<?php

declare(strict_types=1);

namespace App\Auth;

use App\Category\SystemBuckets;
use App\Demo\DemoLibrary;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Money\RateBaseline;
use App\Money\UnknownCurrency;
use PDO;

final class AuthService
{
    private const KINDS = ['car', 'truck', 'motorcycle', 'rv', 'semi'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly DemoLibrary $demo,
    ) {
    }

    public function needsSetup(): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM user WHERE role = 'owner'")->fetchColumn() === 0;
    }

    /** @return ?array{name:string,baseCurrency:string,theme:string,role:string,horizonDays:int,fuelPriceMinor:?int} */
    public function current(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT name, base_currency, theme, role, horizon_days, fuel_price_minor FROM user WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            unset($_SESSION['user_id']);

            return null;
        }

        return [
            'name' => (string) $row['name'],
            'baseCurrency' => (string) $row['base_currency'],
            'theme' => (string) $row['theme'],
            'role' => (string) $row['role'],
            'horizonDays' => (int) $row['horizon_days'] === 30 ? 30 : 60,
            'fuelPriceMinor' => $row['fuel_price_minor'] === null ? null : (int) $row['fuel_price_minor'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function setup(array $input): array
    {
        if (!$this->needsSetup()) {
            throw new ApiException(409, 'Setup is already finished.');
        }

        $books = $this->openBooks($input, false);
        $now = gmdate('c');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO user (name, password_hash, base_currency, theme, role, fuel_price_minor, created_at)
                 VALUES (?, ?, ?, \'dark\', \'owner\', ?, ?)'
            )->execute([
                $books['name'],
                password_hash($books['password'], PASSWORD_DEFAULT),
                $books['currency'],
                $books['fuelPrice'],
                $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$id]);
            RateBaseline::seed($this->pdo, $id, $books['currency'], [], $now);
            SystemBuckets::ensure($this->pdo, $id);
            $this->insertStarredVehicle($id, $books['vehicle'], $now);
            $this->demo->ensure();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->signIn($id);

        $user = $this->current();
        if ($user === null) {
            throw new ApiException(500, 'Something went wrong.');
        }

        return $user;
    }

    /** @param array<string, mixed> $input */
    public function createUser(array $input): array
    {
        if ($this->needsSetup()) {
            throw new ApiException(409, 'Set up the app first.');
        }

        $books = $this->openBooks($input, true);
        $now = gmdate('c');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO user (name, password_hash, base_currency, theme, role, fuel_price_minor, created_at)
                 VALUES (?, ?, ?, \'dark\', \'user\', ?, ?)'
            )->execute([
                $books['name'],
                password_hash($books['password'], PASSWORD_DEFAULT),
                $books['currency'],
                $books['fuelPrice'],
                $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO login_guard (user_id, failed_count) VALUES (?, 0)')->execute([$id]);
            SystemBuckets::ensure($this->pdo, $id);
            $this->insertStarredVehicle($id, $books['vehicle'], $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->signIn($id);
        $user = $this->current();
        if ($user === null) {
            throw new ApiException(500, 'Something went wrong.');
        }

        return $user;
    }

    /** @param array<string, mixed> $input */
    public function login(array $input): array
    {
        if ($this->needsSetup()) {
            throw new ApiException(409, 'Set up the app first.');
        }

        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM user WHERE name = ? COLLATE NOCASE');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            password_verify($password, $this->dummyHash());
            throw new ApiException(401, 'That name and password do not match.');
        }

        $id = (int) $row['id'];
        $this->assertUnlocked($id);
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->recordFailure($id);
            throw new ApiException(401, 'That name and password do not match.');
        }

        $this->resetGuard($id);
        $this->signIn($id);
        $user = $this->current();
        if ($user === null) {
            throw new ApiException(500, 'Something went wrong.');
        }

        return $user;
    }

    /** @return array{name:string,baseCurrency:string,theme:string,role:string,horizonDays:int,fuelPriceMinor:?int} */
    public function enterDemo(): array
    {
        if ($this->needsSetup()) {
            throw new ApiException(409, 'Set up the app first.');
        }

        $stmt = $this->pdo->prepare("SELECT id FROM user WHERE role = 'demo'");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new ApiException(409, 'Set up the app first.');
        }

        $this->signIn((int) $id);
        $user = $this->current();
        if ($user === null) {
            throw new ApiException(500, 'Something went wrong.');
        }

        return $user;
    }

    public function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string) $params['path'],
            'domain' => (string) $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
        session_destroy();
    }

    /** @param array<string, mixed> $input */
    public function updateSettings(array $input): array
    {
        $current = $this->current();
        if ($current === null) {
            throw new ApiException(401, 'Sign in first.');
        }

        $name = array_key_exists('name', $input) ? $this->name($input['name'], 'name') : $current['name'];
        $currency = array_key_exists('baseCurrency', $input)
            ? $this->currency($input['baseCurrency'], 'baseCurrency')
            : $current['baseCurrency'];
        if (array_key_exists('theme', $input)) {
            $theme = $input['theme'];
            if (!is_string($theme) || !in_array($theme, ['dark', 'light'], true)) {
                throw new ApiException(422, 'Check the highlighted fields.', ['theme' => 'Choose dark or light.']);
            }
        } else {
            $theme = $current['theme'];
        }
        $horizonDays = array_key_exists('horizonDays', $input)
            ? $this->horizonDays($input['horizonDays'])
            : $current['horizonDays'];
        $fuelPrice = array_key_exists('fuelPriceMinor', $input)
            ? $this->fuelPrice($input['fuelPriceMinor'])
            : $current['fuelPriceMinor'];

        $password = $input['password'] ?? null;
        $changingPassword = is_string($password) && $password !== '';
        if ($current['role'] === 'demo') {
            $sameName = mb_strtolower($name) === mb_strtolower($current['name']);
            $sameCurrency = $currency === $current['baseCurrency'];
            if (!$sameName || !$sameCurrency || $changingPassword) {
                throw new ApiException(403, 'Demo keeps the sample name, password, and base currency.');
            }
        } else {
            $this->reserveDemoName($name);
        }

        $id = $_SESSION['user_id'];
        if ($changingPassword) {
            $password = $this->password($password);
            $this->pdo->prepare(
                'UPDATE user SET name = ?, base_currency = ?, theme = ?, horizon_days = ?, fuel_price_minor = ?, password_hash = ? WHERE id = ?'
            )->execute([$name, $currency, $theme, $horizonDays, $fuelPrice, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $this->pdo->prepare(
                'UPDATE user SET name = ?, base_currency = ?, theme = ?, horizon_days = ?, fuel_price_minor = ? WHERE id = ?'
            )->execute([$name, $currency, $theme, $horizonDays, $fuelPrice, $id]);
        }

        $user = $this->current();
        if ($user === null) {
            throw new ApiException(401, 'Sign in first.');
        }

        return $user;
    }

    private function signIn(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
    }

    private function assertUnlocked(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT locked_until FROM login_guard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $until = $stmt->fetchColumn();
        if (is_string($until) && $until > gmdate('c')) {
            throw new ApiException(423, 'Too many tries. Try again in 15 minutes.');
        }
    }

    private function recordFailure(int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT failed_count FROM login_guard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn() + 1;
        if ($count >= 5) {
            $until = gmdate('c', time() + 15 * 60);
            $this->pdo->prepare(
                'UPDATE login_guard SET failed_count = 0, locked_until = ? WHERE user_id = ?'
            )->execute([$until, $userId]);

            return;
        }
        $this->pdo->prepare('UPDATE login_guard SET failed_count = ? WHERE user_id = ?')->execute([$count, $userId]);
    }

    private function resetGuard(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE login_guard SET failed_count = 0, locked_until = NULL WHERE user_id = ?'
        )->execute([$userId]);
    }

    private function dummyHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('nomad-finance-timing-pad', PASSWORD_DEFAULT);
        }

        return $hash;
    }

    private function reserveDemoName(string $name): void
    {
        if (mb_strtolower($name) === mb_strtolower(DemoLibrary::NAME)) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'name' => 'Demo is the sample login.',
            ]);
        }
    }

    private function reserveTakenName(string $name): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM user WHERE name = ? COLLATE NOCASE');
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() !== false) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'name' => 'That name is already in use.',
            ]);
        }
    }

    private function name(mixed $value, string $field): string
    {
        $name = trim(is_string($value) ? $value : '');
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                $field => 'Use a name of 1 to 80 characters.',
            ]);
        }

        return $name;
    }

    private function password(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 12) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'password' => 'Use at least 12 characters.',
            ]);
        }
        if (strlen($value) > 200) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'password' => 'Use at most 200 characters.',
            ]);
        }

        return $value;
    }

    private function horizonDays(mixed $value): int
    {
        $days = null;
        if (is_int($value)) {
            $days = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $days = (int) $value;
        }
        if ($days !== 30 && $days !== 60) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'horizonDays' => 'Choose 30 or 60 days.',
            ]);
        }

        return $days;
    }

    private function fuelPrice(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $price = null;
        if (is_int($value)) {
            $price = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $price = (int) $value;
        }
        if ($price === null || $price < 1) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'fuelPriceMinor' => 'Enter a price per gallon greater than zero, or leave it empty.',
            ]);
        }

        return $price;
    }

    private function currency(mixed $value, string $field): string
    {
        try {
            return $this->currencies->assert(is_string($value) ? $value : '');
        } catch (UnknownCurrency) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                $field => 'Use a three-letter ISO currency code.',
            ]);
        }
    }

    /**
     * Login, fallback fuel price, and the starred vehicle, checked before any row is written.
     * Callers that omit the vehicle still get the vehicle the setup screen starts with.
     *
     * @param array<string, mixed> $input
     * @return array{name:string,password:string,currency:string,fuelPrice:?int,vehicle:array{name:string,kind:string,mpgMinTenths:int,mpgMaxTenths:int}}
     */
    private function openBooks(array $input, bool $taken): array
    {
        $errors = [];
        $name = $this->readField(fn () => $this->name($input['name'] ?? null, 'name'), $errors);
        if (is_string($name)) {
            $this->readField(function () use ($name): string {
                $this->reserveDemoName($name);

                return $name;
            }, $errors);
            if ($taken) {
                $this->readField(function () use ($name): string {
                    $this->reserveTakenName($name);

                    return $name;
                }, $errors);
            }
        }
        $password = $this->readField(fn () => $this->password($input['password'] ?? null), $errors);
        $currency = $this->readField(fn () => $this->currency($input['baseCurrency'] ?? null, 'baseCurrency'), $errors);
        $fuelPrice = $this->readField(fn () => $this->fuelPrice($input['fuelPriceMinor'] ?? null), $errors);
        $vehicle = $this->setupVehicle($input, $errors);
        if ($errors !== [] || !is_string($name) || !is_string($password) || !is_string($currency) || $vehicle === null) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'name' => $name,
            'password' => $password,
            'currency' => $currency,
            'fuelPrice' => $fuelPrice,
            'vehicle' => $vehicle,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return ?array{name:string,kind:string,mpgMinTenths:int,mpgMaxTenths:int}
     */
    private function setupVehicle(array $input, array &$errors): ?array
    {
        $mentioned = array_key_exists('vehicleName', $input)
            || array_key_exists('kind', $input)
            || array_key_exists('mpgMinTenths', $input)
            || array_key_exists('mpgMaxTenths', $input);
        if (!$mentioned) {
            return [
                'name' => 'Vehicle 1',
                'kind' => 'rv',
                'mpgMinTenths' => 60,
                'mpgMaxTenths' => 120,
            ];
        }

        $problems = [];
        $name = trim(is_string($input['vehicleName'] ?? null) ? $input['vehicleName'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $problems['vehicleName'] = 'Use a name of 1 to 80 characters.';
        }
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            $problems['kind'] = 'Choose a kind.';
        }
        $min = $this->wholeNumber($input['mpgMinTenths'] ?? null);
        $max = $this->wholeNumber($input['mpgMaxTenths'] ?? null);
        if ($min === null || $min <= 0) {
            $problems['mpgMinTenths'] = 'Enter a minimum miles per gallon greater than zero.';
            $min = 0;
        }
        if ($max === null || $max <= 0) {
            $problems['mpgMaxTenths'] = 'Enter a maximum miles per gallon greater than zero.';
            $max = 0;
        }
        if ($min > 0 && $max > 0 && $min >= $max) {
            $problems['mpgMaxTenths'] = 'The maximum is above the minimum.';
        }
        if ($problems !== []) {
            foreach ($problems as $field => $message) {
                $errors[$field] = $message;
            }

            return null;
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'mpgMinTenths' => $min,
            'mpgMaxTenths' => $max,
        ];
    }

    /** @param array{name:string,kind:string,mpgMinTenths:int,mpgMaxTenths:int} $vehicle */
    private function insertStarredVehicle(int $userId, array $vehicle, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO vehicles (
                user_id, name, kind, mpg_min_tenths, mpg_max_tenths, starred, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $userId,
            $vehicle['name'],
            $vehicle['kind'],
            $vehicle['mpgMinTenths'],
            $vehicle['mpgMaxTenths'],
            $now,
            $now,
        ]);
    }

    /** @param callable(): mixed $read
     *  @param array<string, string> $errors
     */
    private function readField(callable $read, array &$errors): mixed
    {
        try {
            return $read();
        } catch (ApiException $e) {
            if ($e->status !== 422) {
                throw $e;
            }
            foreach ($e->fields as $field => $message) {
                $errors[$field] = $message;
            }

            return null;
        }
    }

    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
