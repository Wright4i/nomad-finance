<?php

declare(strict_types=1);

namespace App\ExchangeRate;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Money\Converter;
use App\Money\CurrencyCatalog;
use App\Money\UnknownCurrency;
use PDO;
use Throwable;

final class RateService
{
    private const DAY = 86400;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly Actor $actor,
        private readonly RateFeed $feed,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $base = $this->actor->baseCurrency();
        $this->refresh($base);

        return $this->rows($base);
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): array
    {
        $base = $this->actor->baseCurrency();
        $errors = [];
        try {
            $currency = $this->currencies->assert(is_string($input['currency'] ?? null) ? $input['currency'] : '');
        } catch (UnknownCurrency) {
            $currency = '';
            $errors['currency'] = 'Use a three-letter ISO currency code.';
        }
        if ($currency !== '' && $currency === $base) {
            $errors['currency'] = 'That is already the base currency. No rate is needed.';
        }

        try {
            $rate = Converter::assertRate(is_string($input['rate'] ?? null) ? $input['rate'] : '');
        } catch (\InvalidArgumentException $e) {
            $rate = '';
            $errors['rate'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO exchange_rates (user_id, base_currency, currency, rate, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, base_currency, currency)
             DO UPDATE SET rate = excluded.rate, updated_at = excluded.updated_at'
        )->execute([$this->actor->id(), $base, $currency, $rate, $now, $now]);

        $this->refresh($base);
        foreach ($this->rows($base) as $row) {
            if ($row['currency'] === $currency && $row['baseCurrency'] === $base) {
                return $row;
            }
        }

        throw new ApiException(500, 'Something went wrong.');
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM exchange_rates WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        if ($stmt->rowCount() === 0) {
            throw new ApiException(404, 'Rate not found.');
        }
    }

    /** @return array<string, string> */
    public function into(string $base): array
    {
        $this->refresh($base);
        $used = array_flip($this->usedCurrencies($base));
        $rates = [];
        foreach ($this->fallbacks($base) as $currency => $rate) {
            $rates[$currency] = $rate;
        }
        foreach ($this->freshQuotes($base) as $currency => $quote) {
            if (isset($used[$currency])) {
                $rates[$currency] = $quote['rate'];
            }
        }

        return $rates;
    }

    private function refresh(string $base): void
    {
        $used = $this->usedCurrencies($base);
        if ($used === []) {
            return;
        }

        $poll = $this->poll($base);
        if ($poll !== null && self::withinDay((string) $poll['attempted_at'])) {
            return;
        }

        $now = gmdate('c');
        try {
            $quotes = $this->feed->quotes($base);
            $this->pdo->beginTransaction();
            $this->pdo->prepare('DELETE FROM rate_quotes WHERE user_id = ? AND base_currency = ?')
                ->execute([$this->actor->id(), $base]);
            $insert = $this->pdo->prepare(
                'INSERT INTO rate_quotes (user_id, base_currency, currency, rate, fetched_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($used as $currency) {
                if (!isset($quotes[$currency])) {
                    continue;
                }
                $rate = Converter::assertRate($quotes[$currency]);
                $insert->execute([$this->actor->id(), $base, $currency, $rate, $now]);
            }
            $this->remember($base, $now, true);
            $this->pdo->commit();
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->remember($base, $now, false);
        }
    }

    /** @return list<string> */
    private function usedCurrencies(string $base): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT currency FROM accounts
             WHERE user_id = ? AND archived_at IS NULL AND currency <> ?
             ORDER BY currency'
        );
        $stmt->execute([$this->actor->id(), $base]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return is_array($codes) ? array_map(strval(...), $codes) : [];
    }

    /** @return array<string, string> */
    private function fallbacks(string $base): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT currency, rate FROM exchange_rates WHERE user_id = ? AND base_currency = ?'
        );
        $stmt->execute([$this->actor->id(), $base]);
        $rates = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $rates[(string) $row['currency']] = (string) $row['rate'];
        }

        return $rates;
    }

    /** @return array<string, array{rate:string,fetched_at:string}> */
    private function freshQuotes(string $base): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT currency, rate, fetched_at FROM rate_quotes WHERE user_id = ? AND base_currency = ?'
        );
        $stmt->execute([$this->actor->id(), $base]);
        $quotes = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            if (!self::withinDay((string) $row['fetched_at'])) {
                continue;
            }
            $quotes[(string) $row['currency']] = [
                'rate' => (string) $row['rate'],
                'fetched_at' => (string) $row['fetched_at'],
            ];
        }

        return $quotes;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $base): array
    {
        $used = array_flip($this->usedCurrencies($base));
        $quotes = $this->freshQuotes($base);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM exchange_rates WHERE user_id = ? ORDER BY base_currency, currency'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        $seen = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $currency = (string) $row['currency'];
            $rowBase = (string) $row['base_currency'];
            $key = $rowBase . ':' . $currency;
            $seen[$key] = true;
            $quote = ($rowBase === $base && isset($used[$currency])) ? ($quotes[$currency] ?? null) : null;
            $rows[] = [
                'id' => (int) $row['id'],
                'baseCurrency' => $rowBase,
                'currency' => $currency,
                'fallbackRate' => (string) $row['rate'],
                'liveRate' => $quote['rate'] ?? null,
                'live' => $quote !== null && isset($used[$currency]),
                'used' => $rowBase === $base && isset($used[$currency]),
            ];
        }

        foreach ($quotes as $currency => $quote) {
            if (!isset($used[$currency]) || isset($seen[$base . ':' . $currency])) {
                continue;
            }
            $rows[] = [
                'id' => null,
                'baseCurrency' => $base,
                'currency' => $currency,
                'fallbackRate' => null,
                'liveRate' => $quote['rate'],
                'live' => true,
                'used' => true,
            ];
        }

        usort($rows, static function (array $a, array $b) use ($base): int {
            $aCurrent = $a['baseCurrency'] === $base ? 0 : 1;
            $bCurrent = $b['baseCurrency'] === $base ? 0 : 1;
            if ($aCurrent !== $bCurrent) {
                return $aCurrent <=> $bCurrent;
            }
            $used = ((int) $b['used']) <=> ((int) $a['used']);
            if ($used !== 0) {
                return $used;
            }

            return strcmp((string) $a['currency'], (string) $b['currency']);
        });

        return $rows;
    }

    /** @return ?array{attempted_at:string,ok:int} */
    private function poll(string $base): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at, ok FROM rate_polls WHERE user_id = ? AND base_currency = ?'
        );
        $stmt->execute([$this->actor->id(), $base]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'attempted_at' => (string) $row['attempted_at'],
            'ok' => (int) $row['ok'],
        ];
    }

    private function remember(string $base, string $now, bool $ok): void
    {
        $this->pdo->prepare(
            'INSERT INTO rate_polls (user_id, base_currency, attempted_at, ok)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, base_currency)
             DO UPDATE SET attempted_at = excluded.attempted_at, ok = excluded.ok'
        )->execute([$this->actor->id(), $base, $now, $ok ? 1 : 0]);
    }

    private static function withinDay(string $instant): bool
    {
        $then = new \DateTimeImmutable($instant);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $now->getTimestamp() - $then->getTimestamp() < self::DAY;
    }
}
