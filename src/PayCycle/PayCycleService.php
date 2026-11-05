<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Support\Dates;
use PDO;

final class PayCycleService
{
    private const LENGTHS = ['weekly', 'biweekly', 'semimonthly', 'monthly', 'irregular'];

    private const KINDS = ['salary', 'fixed', 'hourly'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $today, bool $includeArchived): array
    {
        $this->day($today, 'today');
        $sql = 'SELECT * FROM pay_cycles WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY payor COLLATE NOCASE, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listed[] = $this->present($this->roll($row, $today), $today);
        }

        return $listed;
    }

    /**
     * Paydays in one month, with the preview paycheck for that date.
     * The amount is a label. It does not assign money or post it.
     *
     * @return list<array{id:int,payor:string,on:string,paycheckMinor:int,length:string}>
     */
    public function monthPay(string $month, string $today): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ApiException(422, 'Use a YYYY-MM month.', ['month' => 'Use a YYYY-MM month.']);
        }
        $this->day($today, 'today');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pay_cycles WHERE user_id = ? AND archived_at IS NULL ORDER BY payor COLLATE NOCASE, id'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }
        $listed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cycle = $this->present($this->roll($row, $today), $today);
            $second = is_int($cycle['secondDay']) ? $cycle['secondDay'] : null;
            $next = is_string($cycle['nextPayday']) ? $cycle['nextPayday'] : null;
            foreach (MonthPaydays::dates((string) $cycle['payday'], (string) $cycle['length'], $second, $next, $month) as $date) {
                $amount = $this->paycheckOnDate($cycle, $date);
                if ($amount === null) {
                    continue;
                }
                $listed[] = [
                    'id' => (int) $cycle['id'],
                    'payor' => (string) $cycle['payor'],
                    'on' => $date,
                    'paycheckMinor' => $amount,
                    'length' => (string) $cycle['length'],
                ];
            }
        }

        return $listed;
    }

    /** @return array<string, mixed> */
    public function get(int $id, string $today): array
    {
        $this->day($today, 'today');

        return $this->present($this->roll($this->must($id), $today), $today);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, string $today): array
    {
        $this->day($today, 'today');
        $data = $this->validate($input);
        $this->assertPayorFree($data['payor'], null);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO pay_cycles (
                user_id, payor, payday, length, second_day, next_payday,
                pay_kind, rate_minor, hours_hundredths, hours_mode, average_months, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->actor->id(),
            $data['payor'],
            $data['payday'],
            $data['length'],
            $data['secondDay'],
            $data['nextPayday'],
            $data['payKind'],
            $data['rateMinor'],
            $data['hoursHundredths'],
            $data['hoursMode'],
            $data['averageMonths'],
            $now,
            $now,
        ]);

        return $this->get((int) $this->pdo->lastInsertId(), $today);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, string $today): array
    {
        $this->day($today, 'today');
        $this->must($id);
        $data = $this->validate($input);
        $this->assertPayorFree($data['payor'], $id);
        $this->pdo->prepare(
            'UPDATE pay_cycles
             SET payor = ?, payday = ?, length = ?, second_day = ?, next_payday = ?,
                 pay_kind = ?, rate_minor = ?, hours_hundredths = ?, hours_mode = ?, average_months = ?,
                 updated_at = ?
             WHERE id = ? AND user_id = ?'
        )->execute([
            $data['payor'],
            $data['payday'],
            $data['length'],
            $data['secondDay'],
            $data['nextPayday'],
            $data['payKind'],
            $data['rateMinor'],
            $data['hoursHundredths'],
            $data['hoursMode'],
            $data['averageMonths'],
            gmdate('c'),
            $id,
            $this->actor->id(),
        ]);

        return $this->get($id, $today);
    }

    /** @return array<string, mixed> */
    public function archive(int $id, string $today): array
    {
        $this->must($id);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE pay_cycles SET archived_at = COALESCE(archived_at, ?), updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $now, $id, $this->actor->id()]);

        return $this->get($id, $today);
    }

    /** @return array<string, mixed> */
    public function unarchive(int $id, string $today): array
    {
        $this->must($id);
        $this->pdo->prepare(
            'UPDATE pay_cycles SET archived_at = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([gmdate('c'), $id, $this->actor->id()]);

        return $this->get($id, $today);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payor:string,payday:string,length:string,secondDay:?int,nextPayday:?string,payKind:string,rateMinor:int,hoursHundredths:?int,hoursMode:string,averageMonths:?int}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $payor = trim(is_string($input['payor'] ?? null) ? $input['payor'] : '');
        if ($payor === '' || mb_strlen($payor) > 80) {
            $errors['payor'] = 'Use a name of 1 to 80 characters.';
        }

        $payday = $this->date($input['payday'] ?? null);
        if ($payday === null) {
            $errors['payday'] = 'Use a YYYY-MM-DD date.';
            $payday = '';
        }

        $length = is_string($input['length'] ?? null) ? $input['length'] : '';
        if (!in_array($length, self::LENGTHS, true)) {
            $errors['length'] = 'Choose how often you are paid.';
        }

        $secondDay = null;
        if ($length === 'semimonthly') {
            $secondDay = $this->dayNumber($input['secondDay'] ?? null);
            if ($secondDay === null) {
                $errors['secondDay'] = 'Use a day from 1 to 31.';
            } elseif ($payday !== '' && $secondDay === (int) substr($payday, 8, 2)) {
                $errors['secondDay'] = 'Choose a different day from the payday.';
            }
        }

        $nextPayday = null;
        if ($length === 'irregular') {
            $nextPayday = $this->date($input['nextPayday'] ?? null);
            if ($nextPayday === null) {
                $errors['nextPayday'] = 'Use a YYYY-MM-DD date.';
            } elseif ($payday !== '' && $nextPayday <= $payday) {
                $errors['nextPayday'] = 'The next payday has to be after this payday.';
            }
        }

        $kind = is_string($input['payKind'] ?? null) ? $input['payKind'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['payKind'] = 'Choose salary, fixed, or hourly.';
        }

        $rate = $this->asInt($input['rateMinor'] ?? null);
        if ($rate === null || $rate < 1) {
            $errors['rateMinor'] = 'Enter a rate greater than zero.';
            $rate = 0;
        }

        $mode = 'typed';
        if (array_key_exists('hoursMode', $input) && $input['hoursMode'] !== null && $input['hoursMode'] !== '') {
            $mode = is_string($input['hoursMode']) ? $input['hoursMode'] : '';
        }
        if (!in_array($mode, ['typed', 'weekdays'], true)) {
            $errors['hoursMode'] = 'Choose typed hours or weekdays.';
            $mode = 'typed';
        } elseif ($mode === 'weekdays' && ($kind !== 'hourly' || ($length !== 'semimonthly' && $length !== 'monthly'))) {
            $errors['hoursMode'] = 'Weekday hours are for a semimonthly or monthly hourly cycle.';
        }

        $hours = null;
        $monthlyWeekday = $kind === 'hourly' && $mode === 'weekdays' && $length === 'monthly';
        if ($kind === 'hourly' && ($mode === 'typed' || $monthlyWeekday)) {
            $hours = $this->hours($input['hoursHundredths'] ?? $input['hours'] ?? null);
            if ($hours === null) {
                $errors['hours'] = $monthlyWeekday
                    ? 'Enter the hours per weekday.'
                    : 'Enter the hours in this period.';
            }
        }

        $average = $this->averageMonths($input['averageMonths'] ?? null);
        if ($average === false) {
            $errors['averageMonths'] = 'Use 3 to 6 months, or leave the average off.';
            $average = null;
        } elseif ($average !== null && $kind !== 'hourly') {
            $errors['averageMonths'] = 'An average is only for an hourly cycle.';
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'payor' => $payor,
            'payday' => $payday,
            'length' => $length,
            'secondDay' => $secondDay,
            'nextPayday' => $nextPayday,
            'payKind' => $kind,
            'rateMinor' => $rate,
            'hoursHundredths' => $hours,
            'hoursMode' => $mode,
            'averageMonths' => is_int($average) ? $average : null,
        ];
    }

    private function assertPayorFree(string $payor, ?int $ignoreId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pay_cycles WHERE user_id = ? AND payor = ? COLLATE NOCASE AND id != ?'
        );
        $stmt->execute([$this->actor->id(), $payor, $ignoreId ?? 0]);
        if ($stmt->fetch() !== false) {
            throw new ApiException(422, 'Check the highlighted fields.', [
                'payor' => 'That payor already has a pay cycle.',
            ]);
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function roll(array $row, string $today): array
    {
        if ($row['length'] !== 'irregular' || $row['next_payday'] === null) {
            return $row;
        }
        $open = Period::open(
            (string) $row['payday'],
            'irregular',
            null,
            (string) $row['next_payday'],
            $today,
        );
        if (!$open['rolled']) {
            return $row;
        }
        $this->pdo->prepare(
            'UPDATE pay_cycles SET payday = ?, next_payday = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$open['start'], gmdate('c'), $row['id'], $this->actor->id()]);
        $fresh = $this->must((int) $row['id']);

        return $fresh;
    }

    /** @return array<string, mixed> */
    private function must(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pay_cycles WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Pay cycle not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, string $today): array
    {
        $secondDay = $row['second_day'] === null ? null : (int) $row['second_day'];
        $nextPayday = $row['next_payday'] === null ? null : (string) $row['next_payday'];
        $open = Period::open((string) $row['payday'], (string) $row['length'], $secondDay, $nextPayday, $today);
        $periodEnd = null;
        if ($open['next'] !== null) {
            $periodEnd = Dates::parse($open['next'])->modify('-1 day')->format('Y-m-d');
        }
        $kind = (string) ($row['pay_kind'] ?? 'fixed');
        $rate = (int) ($row['rate_minor'] ?? 0);
        $hours = $row['hours_hundredths'] === null ? null : (int) $row['hours_hundredths'];
        $mode = (string) ($row['hours_mode'] ?? 'typed');
        $weekdayPay = null;
        $monthlyWeekday = null;
        $paycheck = Paycheck::amount($kind, (string) $row['length'], $rate, $hours);
        if ($mode === 'weekdays' && (string) $row['length'] === 'monthly' && $hours !== null) {
            $figured = Paycheck::monthlyWeekday((string) $row['payday'], $rate, $hours);
            if ($figured !== null) {
                $paycheck = $figured['paycheckMinor'];
                $monthlyWeekday = [
                    'weekdays' => $figured['weekdays'],
                    'month' => $figured['month'],
                    'hours' => self::hoursText($figured['hoursHundredths']),
                    'paycheckMinor' => $figured['paycheckMinor'],
                ];
            }
        }
        if ($mode === 'weekdays' && $secondDay !== null) {
            $weekdayPay = Paycheck::weekdaySplit((string) $row['payday'], $secondDay, $rate);
            if ($weekdayPay !== null) {
                $day = (int) substr((string) $row['payday'], 8, 2);
                $paycheck = $day === $weekdayPay['earlierDay']
                    ? $weekdayPay['earlierMinor']
                    : $weekdayPay['laterMinor'];
            }
        }

        return [
            'id' => (int) $row['id'],
            'payor' => $row['payor'],
            'payday' => $row['payday'],
            'length' => $row['length'],
            'secondDay' => $secondDay,
            'nextPayday' => $nextPayday,
            'payKind' => $kind,
            'rateMinor' => $rate,
            'hoursMode' => $mode,
            'averageMonths' => $row['average_months'] === null ? null : (int) $row['average_months'],
            'hours' => self::hoursText($hours),
            'paycheckMinor' => $paycheck,
            'weekdayPay' => $weekdayPay,
            'monthlyWeekday' => $monthlyWeekday,
            'periodStart' => $open['start'],
            'periodEnd' => $periodEnd,
            'rollsOn' => $open['next'],
            'archivedAt' => $row['archived_at'],
        ];
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function paycheckOnDate(array $cycle, string $date): ?int
    {
        if (($cycle['hoursMode'] ?? '') === 'weekdays' && ($cycle['length'] ?? '') === 'monthly') {
            $hundredths = Paycheck::hoursHundredths(is_string($cycle['hours'] ?? null) ? $cycle['hours'] : '');
            if ($hundredths !== null) {
                $figured = Paycheck::monthlyWeekday($date, (int) $cycle['rateMinor'], $hundredths);
                if ($figured !== null) {
                    return $figured['paycheckMinor'];
                }
            }
        }

        $amount = is_int($cycle['paycheckMinor']) ? $cycle['paycheckMinor'] : null;
        $second = $cycle['secondDay'];
        if (($cycle['hoursMode'] ?? '') === 'weekdays' && is_int($second)) {
            $anchorDay = (int) substr((string) $cycle['payday'], 8, 2);
            $year = (int) substr($date, 0, 4);
            $mon = (int) substr($date, 5, 2);
            $last = (int) Dates::parse(sprintf('%04d-%02d-01', $year, $mon))->format('t');
            $probeDay = min($anchorDay, $last);
            if ($probeDay !== $second) {
                $split = Paycheck::weekdaySplit(
                    sprintf('%04d-%02d-%02d', $year, $mon, $probeDay),
                    $second,
                    (int) $cycle['rateMinor'],
                );
                if ($split !== null) {
                    $day = (int) substr($date, 8, 2);

                    return $day === $split['earlierDay'] ? $split['earlierMinor'] : $split['laterMinor'];
                }
            }
        }

        return $amount;
    }

    private function averageMonths(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        $months = $this->asInt($value);
        if ($months === null || !in_array($months, [3, 4, 5, 6], true)) {
            return false;
        }

        return $months;
    }

    private static function hoursText(?int $hundredths): ?string
    {
        if ($hundredths === null) {
            return null;
        }

        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    private function hours(mixed $value): ?int
    {
        if (is_int($value)) {
            $hundredths = $value > 1000 ? $value : $value * 100;
        } elseif (is_string($value) && preg_match('/^\d+(\.\d{1,2})?$/', trim($value)) === 1) {
            [$whole, $frac] = array_pad(explode('.', trim($value), 2), 2, '');
            $hundredths = ((int) $whole) * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
        } elseif (is_float($value) && $value > 0) {
            $hundredths = (int) round($value * 100);
        } else {
            return null;
        }

        if ($hundredths < 1 || $hundredths > 100000) {
            return null;
        }

        return $hundredths;
    }

    private function asInt(mixed $value): ?int
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

    private function day(string $value, string $field): void
    {
        if ($this->date($value) === null) {
            throw new ApiException(422, 'Check the highlighted fields.', [$field => 'Use a YYYY-MM-DD date.']);
        }
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function dayNumber(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1 && $value <= 31) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $day = (int) $value;
            if ($day >= 1 && $day <= 31) {
                return $day;
            }
        }

        return null;
    }
}
