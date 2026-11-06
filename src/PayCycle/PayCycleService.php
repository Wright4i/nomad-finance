<?php

declare(strict_types=1);

namespace App\PayCycle;

use App\Auth\Actor;
use App\Envelope\BillFill;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Support\Dates;
use PDO;

final class PayCycleService
{
    private const LENGTHS = ['weekly', 'biweekly', 'semimonthly', 'monthly', 'irregular'];

    private const KINDS = ['salary', 'fixed', 'hourly'];

    /** @var array<int, array<string, array{amount:int,hours:?int}>> */
    private array $entryCache = [];

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
            $dates = MonthPaydays::dates((string) $cycle['payday'], (string) $cycle['length'], $second, $next, $month);
            foreach ($this->keyedDates((int) $cycle['id']) as $keyed) {
                if (str_starts_with($keyed, $month)) {
                    $dates[] = $keyed;
                }
            }
            $dates = array_values(array_unique($dates));
            sort($dates);
            foreach ($dates as $date) {
                $amount = $this->projected($cycle, $date);
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
                pay_kind, rate_minor, hours_hundredths, hours_mode, average_on, created_at, updated_at
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
            $data['averageOn'],
            $now,
            $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $open = $this->pdo->prepare('SELECT COUNT(*) FROM pay_cycles WHERE user_id = ? AND archived_at IS NULL');
        $open->execute([$this->actor->id()]);
        if ((int) $open->fetchColumn() === 1) {
            (new BillFill($this->pdo, new CurrencyCatalog(), $this->actor))->apply($today);
        }

        return $this->get($id, $today);
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
                 pay_kind = ?, rate_minor = ?, hours_hundredths = ?, hours_mode = ?, average_on = ?,
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
            $data['averageOn'],
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
        (new BillFill($this->pdo, new CurrencyCatalog(), $this->actor))->apply($today);

        return $this->get($id, $today);
    }

    /** @return array<string, mixed> */
    public function unarchive(int $id, string $today): array
    {
        $this->must($id);
        $this->pdo->prepare(
            'UPDATE pay_cycles SET archived_at = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([gmdate('c'), $id, $this->actor->id()]);
        (new BillFill($this->pdo, new CurrencyCatalog(), $this->actor))->apply($today);

        return $this->get($id, $today);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payor:string,payday:string,length:string,secondDay:?int,nextPayday:?string,payKind:string,rateMinor:int,hoursHundredths:?int,hoursMode:string,averageOn:int}
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

        $averageOn = !empty($input['averageOn']) ? 1 : 0;

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
            'averageOn' => $averageOn,
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

        $presented = [
            'id' => (int) $row['id'],
            'payor' => $row['payor'],
            'payday' => $row['payday'],
            'length' => $row['length'],
            'secondDay' => $secondDay,
            'nextPayday' => $nextPayday,
            'payKind' => $kind,
            'rateMinor' => $rate,
            'hoursMode' => $mode,
            'averageOn' => (int) ($row['average_on'] ?? 0) === 1,
            'hours' => self::hoursText($hours),
            'paycheckMinor' => $paycheck,
            'weekdayPay' => $weekdayPay,
            'monthlyWeekday' => $monthlyWeekday,
            'periodStart' => $open['start'],
            'periodEnd' => $periodEnd,
            'rollsOn' => $open['next'],
            'archivedAt' => $row['archived_at'],
        ];
        $presented['paycheckMinor'] = $this->projected($presented, (string) $open['start']);

        return $presented;
    }

    /**
     * Keyed amount wins. Average of earlier keys when average is on. Otherwise the base paycheck.
     *
     * @param array<string, mixed> $cycle
     */
    public function projected(array $cycle, string $date): ?int
    {
        $keyed = $this->entries((int) $cycle['id']);
        $earlier = [];
        foreach ($keyed as $day => $entry) {
            if ($day < $date) {
                $earlier[] = $entry['amount'];
            }
        }

        return Payroll::amount(
            $keyed[$date]['amount'] ?? null,
            ($cycle['averageOn'] ?? false) === true,
            $earlier,
            $this->baseOnDate($cycle, $date),
        );
    }

    /**
     * Income for one payor across a span, in cents.
     * The opening payday uses the paycheck preview. A keyed amount inside the span is included once.
     * An irregular salary that is not a paycheck adds nothing.
     */
    public function incomeBetween(int $id, string $start, ?string $end, string $today): int
    {
        $cycle = $this->get($id, $today);
        $dates = [$start => true];
        if (is_string($end) && $end >= $start) {
            foreach ($this->keyedDates($id) as $keyed) {
                if ($keyed >= $start && $keyed <= $end) {
                    $dates[$keyed] = true;
                }
            }
        }
        $total = 0;
        foreach (array_keys($dates) as $date) {
            $amount = $this->projected($cycle, $date);
            if (is_int($amount) && $amount > 0) {
                $total += $amount;
            }
        }

        return $total;
    }

    /** @return list<string> */
    public function keyedDates(int $id): array
    {
        return array_keys($this->entries($id));
    }

    /**
     * @return array{averageOn:bool,rows:list<array<string, mixed>>}
     */
    public function payroll(int $id, string $today): array
    {
        $cycle = $this->get($id, $today);
        $dates = $this->sheetDates($cycle, $today);
        $keyed = $this->entries($id);
        $rows = [];
        foreach ($dates as $date) {
            $entry = $keyed[$date] ?? null;
            $rows[] = [
                'payday' => $date,
                'amountMinor' => $entry['amount'] ?? null,
                'hours' => self::hoursText($entry['hours'] ?? null),
                'keyed' => $entry !== null,
                'projectedMinor' => $this->projected($cycle, $date),
            ];
        }

        return [
            'averageOn' => $cycle['averageOn'] === true,
            'rows' => $rows,
        ];
    }

    /**
     * One amount per date. An empty amount removes the key. Next payday is left as stored.
     *
     * @param array<string, mixed> $input
     * @return array{averageOn:bool,rows:list<array<string, mixed>>}
     */
    public function savePayroll(int $id, array $input, string $today): array
    {
        $this->must($id);
        $entries = is_array($input['entries'] ?? null) ? $input['entries'] : null;
        if ($entries === null) {
            throw new ApiException(422, 'Check the highlighted fields.', ['entries' => 'Send the pay dates.']);
        }
        $upsert = $this->pdo->prepare(
            'INSERT INTO payroll_entries (pay_cycle_id, payday, amount_minor, hours_hundredths)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(pay_cycle_id, payday) DO UPDATE SET
                amount_minor = excluded.amount_minor,
                hours_hundredths = excluded.hours_hundredths'
        );
        $delete = $this->pdo->prepare('DELETE FROM payroll_entries WHERE pay_cycle_id = ? AND payday = ?');
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new ApiException(422, 'Check the highlighted fields.', ['entries' => 'Send one amount per date.']);
            }
            $payday = $this->date($entry['payday'] ?? null);
            if ($payday === null) {
                throw new ApiException(422, 'Check the highlighted fields.', ['payday' => 'Use a YYYY-MM-DD date.']);
            }
            $amount = $entry['amountMinor'] ?? null;
            if ($amount === null || $amount === '' || $amount === 0 || $amount === '0') {
                $delete->execute([$id, $payday]);
                continue;
            }
            $minor = $this->asInt($amount);
            if ($minor === null || $minor < 1) {
                throw new ApiException(422, 'Check the highlighted fields.', ['amountMinor' => 'Enter an amount greater than zero, or leave it blank.']);
            }
            $hours = null;
            if (array_key_exists('hours', $entry) && $entry['hours'] !== null && $entry['hours'] !== '') {
                $hours = $this->hours($entry['hours']);
                if ($hours === null) {
                    throw new ApiException(422, 'Check the highlighted fields.', ['hours' => 'Enter the hours.']);
                }
            }
            $upsert->execute([$id, $payday, $minor, $hours]);
        }
        $this->entryCache = [];

        return $this->payroll($id, $today);
    }

    /**
     * @param array<string, mixed> $cycle
     * @return list<string>
     */
    private function sheetDates(array $cycle, string $today): array
    {
        $second = is_int($cycle['secondDay']) ? $cycle['secondDay'] : null;
        if ($cycle['length'] === 'irregular') {
            $dates = [];
            if (is_string($cycle['nextPayday'])) {
                $dates[] = $cycle['nextPayday'];
            }
        } else {
            $dates = Payroll::window((string) $cycle['payday'], (string) $cycle['length'], $second, $today);
        }
        foreach ($this->keyedDates((int) $cycle['id']) as $keyed) {
            $dates[] = $keyed;
        }
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * @return array<string, array{amount:int,hours:?int}>
     */
    private function entries(int $id): array
    {
        if (isset($this->entryCache[$id])) {
            return $this->entryCache[$id];
        }
        $stmt = $this->pdo->prepare(
            'SELECT payday, amount_minor, hours_hundredths FROM payroll_entries WHERE pay_cycle_id = ? ORDER BY payday'
        );
        $stmt->execute([$id]);
        $listed = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $listed[(string) $row['payday']] = [
                'amount' => (int) $row['amount_minor'],
                'hours' => $row['hours_hundredths'] === null ? null : (int) $row['hours_hundredths'],
            ];
        }
        $this->entryCache[$id] = $listed;

        return $listed;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function baseOnDate(array $cycle, string $date): ?int
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

        $hours = Paycheck::hoursHundredths(is_string($cycle['hours'] ?? null) ? $cycle['hours'] : '');
        $amount = Paycheck::amount(
            (string) ($cycle['payKind'] ?? 'fixed'),
            (string) ($cycle['length'] ?? 'monthly'),
            (int) ($cycle['rateMinor'] ?? 0),
            $hours,
        );
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
