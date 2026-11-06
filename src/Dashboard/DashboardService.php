<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Auth\Actor;
use App\Bill\DuePie;
use App\Calendar\CalendarEntryService;
use App\Bill\Schedule;
use App\Envelope\EnvelopeService;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\PayCycle\PayCycleService;
use App\PayCycle\Paycheck;
use App\PayCycle\Period;
use App\Support\Dates;
use App\Trip\TripService;
use PDO;

final class DashboardService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CurrencyCatalog $currencies,
        private readonly Actor $actor,
        private readonly EnvelopeService $envelopes,
        private readonly PayCycleService $payCycles,
        private readonly TripService $trips,
        private readonly CalendarEntryService $entries,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(string $today, ?int $payCycleId = null, ?int $periodId = null, bool $upcoming = false): array
    {
        try {
            $today = Dates::parse($today)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            throw new ApiException(422, 'Check the highlighted fields.', ['today' => 'Use a YYYY-MM-DD date.']);
        }

        $base = $this->actor->baseCurrency();
        $horizon = $this->horizonDays();
        $windowEnd = Dates::parse($today)->modify('+' . ($horizon - 1) . ' days')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT id, name, type, side, currency, balance_minor AS balanceMinor,
                    interest_bps AS interestBps
             FROM accounts WHERE user_id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        $accounts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $accounts[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'side' => (string) $row['side'],
                'currency' => (string) $row['currency'],
                'balanceMinor' => (int) $row['balanceMinor'],
                'interestBps' => $row['interestBps'] === null ? null : (int) $row['interestBps'],
            ];
        }

        $summary = NetWorth::summarize($accounts);
        $interest = MonthlyInterest::summarize($accounts);
        $cycles = $this->payCycles->list($today, false);
        $income = [];
        $incomeMinor = 0;
        $timeline = [];
        foreach ($cycles as $cycle) {
            $paycheck = $cycle['paycheckMinor'];
            $income[] = [
                'id' => $cycle['id'],
                'payor' => $cycle['payor'],
                'payKind' => $cycle['payKind'],
                'length' => $cycle['length'],
                'rateMinor' => $cycle['rateMinor'],
                'hours' => $cycle['hours'],
                'hoursMode' => $cycle['hoursMode'],
                'paycheckMinor' => $paycheck,
                'weekdayPay' => $cycle['weekdayPay'],
                'average' => null,
                'averageOn' => $cycle['averageOn'] ?? false,
                'periodStart' => $cycle['periodStart'],
                'periodEnd' => $cycle['periodEnd'],
            ];
            if (is_int($paycheck)) {
                $incomeMinor += $paycheck;
            }
            foreach ($this->paydays($cycle, $today, $windowEnd) as $date) {
                $preview = $this->paycheckOn($cycle, $date);
                $timeline[] = [
                    'kind' => 'payday',
                    'on' => $date,
                    'payCycleId' => $cycle['id'],
                    'payor' => $cycle['payor'],
                    'paycheckMinor' => $this->payCycles->projected($cycle, $date),
                    'hoursMode' => $preview['hoursMode'],
                    'weekdayPay' => $preview['weekdayPay'],
                ];
            }
        }

        $listed = $this->trips->list($today);
        $heading = PlannerHeading::label($listed, $today);
        $timeline = array_merge(
            $timeline,
            $this->bills($today, $windowEnd),
            $this->stays($listed, $today, $windowEnd),
            $this->entryMarks($today, $windowEnd),
        );
        usort($timeline, static function (array $left, array $right): int {
            $date = strcmp((string) $left['on'], (string) $right['on']);
            if ($date !== 0) {
                return $date;
            }
            $order = ['payday' => 0, 'bill' => 1, 'stay' => 2, 'balanceDue' => 3, 'entry' => 4];
            $kind = ($order[$left['kind']] ?? 9) <=> ($order[$right['kind']] ?? 9);
            if ($kind !== 0) {
                return $kind;
            }
            if (($left['kind'] ?? '') === 'entry') {
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }

            return 0;
        });

        return [
            'baseCurrency' => $base,
            'exponent' => $this->currencies->exponent($base),
            'today' => $today,
            'horizonDays' => $horizon,
            'windowStart' => $today,
            'windowEnd' => $windowEnd,
            'netWorthMinor' => $summary['netWorthMinor'],
            'assetsMinor' => $summary['assetsMinor'],
            'liabilitiesMinor' => $summary['liabilitiesMinor'],
            'interestLostMinor' => $interest['interestLostMinor'],
            'interestGainedMinor' => $interest['interestGainedMinor'],
            'unconverted' => [],
            'timeline' => $timeline,
            'envelopes' => $this->envelopes->show($today, $payCycleId, $periodId, $upcoming),
            'income' => $income,
            'incomeMinor' => $incomeMinor,
            'duePies' => $this->duePies($today),
            'heading' => $heading['text'],
            'headingItalic' => $heading['italic'],
            'payday' => PlannerWidgets::payday($timeline, $today),
            'incomeComing' => $this->incomeComing($today),
            'payorCount' => count($income),
            'stay' => PlannerWidgets::stay($listed, $today),
            'accountTotals' => PlannerWidgets::accountTotals($accounts),
            'plannerOrder' => $this->plannerOrder(),
            'plannerSpans' => $this->plannerSpans(),
        ];
    }

    /**
     * Still coming in for the month on screen and the next one.
     * The planner switches between those two.
     *
     * @return array<string, int>
     */
    private function incomeComing(string $today): array
    {
        $months = [
            substr($today, 0, 7),
            Dates::parse($today)->modify('first day of next month')->format('Y-m'),
        ];
        $coming = [];
        foreach ($months as $month) {
            $coming[$month] = PlannerWidgets::stillComing(
                $this->payCycles->monthPay($month, $today),
                $month,
                $today,
            );
        }

        return $coming;
    }

    /** @return list<string> */
    private function plannerOrder(): array
    {
        $stmt = $this->pdo->prepare('SELECT planner_order FROM user WHERE id = ?');
        $stmt->execute([$this->actor->id()]);
        $stored = $stmt->fetchColumn();

        return PlannerWidgets::order($stored === false ? null : $stored);
    }

    /** @return array<string, int> */
    private function plannerSpans(): array
    {
        $stmt = $this->pdo->prepare('SELECT planner_spans FROM user WHERE id = ?');
        $stmt->execute([$this->actor->id()]);
        $stored = $stmt->fetchColumn();

        return PlannerWidgets::spans($stored === false ? null : $stored);
    }

    /** @return array<string, list<array{currency:string,exponent:int,slices:list<array{type:string,amountMinor:int}>}>> */
    private function duePies(string $today): array
    {
        $month = substr($today, 0, 7);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        $nextMonth = $start instanceof \DateTimeImmutable ? $start->modify('+1 month')->format('Y-m') : $month;
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.type, b.name, b.other_label, b.status, b.recurrence,
                    b.anchor_date AS anchorDate, b.last_paid_on AS lastPaidOn,
                    b.next_due_date AS nextDueDate, b.amount_minor AS amountMinor, b.currency
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ?'
        );
        $stmt->execute([$this->actor->id()]);
        $bills = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $currency = (string) $row['currency'];
            $name = (string) $row['name'];
            $type = (string) $row['type'];
            if (($type === 'other' || $type === 'misc') && is_string($row['other_label']) && $row['other_label'] !== '') {
                $name = (string) $row['other_label'];
            }
            $bills[] = [
                'id' => (int) $row['id'],
                'type' => $type,
                'name' => $name,
                'status' => (string) $row['status'],
                'recurrence' => (string) $row['recurrence'],
                'anchorDate' => (string) $row['anchorDate'],
                'lastPaidOn' => $row['lastPaidOn'],
                'nextDueDate' => (string) $row['nextDueDate'],
                'amountMinor' => (int) $row['amountMinor'],
                'currency' => $currency,
                'exponent' => $this->currencies->exponent($currency),
            ];
        }
        $base = $this->actor->baseCurrency();
        $exponent = $this->currencies->exponent($base);

        return [
            $month => DuePie::month($bills, $month, $base, $exponent),
            $nextMonth => DuePie::month($bills, $nextMonth, $base, $exponent),
        ];
    }

    private function horizonDays(): int
    {
        $stmt = $this->pdo->prepare('SELECT horizon_days FROM user WHERE id = ?');
        $stmt->execute([$this->actor->id()]);
        $days = (int) $stmt->fetchColumn();

        return $days === 30 ? 30 : 60;
    }

    /**
     * @param array<string, mixed> $cycle
     * @return list<string>
     */
    private function paydays(array $cycle, string $today, string $end): array
    {
        $anchor = (string) $cycle['payday'];
        $length = (string) $cycle['length'];
        $second = is_int($cycle['secondDay']) ? $cycle['secondDay'] : null;
        $nextPayday = is_string($cycle['nextPayday']) ? $cycle['nextPayday'] : null;
        $dates = [];
        $probe = $today;
        for ($i = 0; $i < 400; $i++) {
            try {
                $open = Period::open($anchor, $length, $second, $nextPayday, $probe);
            } catch (\InvalidArgumentException) {
                break;
            }
            $start = $open['start'];
            if ($start > $end) {
                break;
            }
            if ($start >= $today) {
                $dates[$start] = $start;
            }
            $next = $open['next'];
            if ($next === null || $next <= $probe || $next > $end) {
                break;
            }
            $probe = $next;
        }
        foreach ($this->payCycles->keyedDates((int) $cycle['id']) as $keyed) {
            if ($keyed >= $today && $keyed <= $end) {
                $dates[$keyed] = $keyed;
            }
        }
        $listed = array_values($dates);
        sort($listed);

        return $listed;
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array{paycheckMinor:?int,hoursMode:string,weekdayPay:?array<string, int>}
     */
    private function paycheckOn(array $cycle, string $date): array
    {
        $mode = (string) $cycle['hoursMode'];
        $amount = is_int($cycle['paycheckMinor']) ? $cycle['paycheckMinor'] : null;
        $weekdayPay = null;
        if ($mode === 'weekdays' && ($cycle['length'] ?? '') === 'monthly') {
            $hundredths = Paycheck::hoursHundredths(is_string($cycle['hours'] ?? null) ? $cycle['hours'] : '');
            if ($hundredths !== null) {
                $figured = Paycheck::monthlyWeekday($date, (int) $cycle['rateMinor'], $hundredths);
                if ($figured !== null) {
                    $amount = $figured['paycheckMinor'];
                }
            }
        } elseif ($mode === 'weekdays' && is_int($cycle['secondDay'])) {
            $weekdayPay = $this->weekdayOn($cycle, $date);
            if ($weekdayPay !== null) {
                $day = (int) substr($date, 8, 2);
                if ($day === $weekdayPay['earlierDay']) {
                    $amount = $weekdayPay['earlierMinor'];
                } elseif ($day === $weekdayPay['laterDay'] || $day > $weekdayPay['earlierDay']) {
                    $amount = $weekdayPay['laterMinor'];
                } else {
                    $amount = $weekdayPay['earlierMinor'];
                }
            }
        }

        return [
            'paycheckMinor' => $amount,
            'hoursMode' => $mode,
            'weekdayPay' => $weekdayPay,
        ];
    }

    /**
     * Weekday gross for the month of this payday.
     * The split function wants the anchor day in that month, plus the second day.
     *
     * @param array<string, mixed> $cycle
     * @return array{weekdays:int,hours:int,grossMinor:int,earlierDay:int,earlierMinor:int,laterDay:int,laterMinor:int}|null
     */
    private function weekdayOn(array $cycle, string $date): ?array
    {
        $second = $cycle['secondDay'];
        if (!is_int($second)) {
            return null;
        }
        $anchorDay = (int) substr((string) $cycle['payday'], 8, 2);
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $last = (int) Dates::parse(sprintf('%04d-%02d-01', $year, $month))->format('t');
        $probeDay = min($anchorDay, $last);
        if ($probeDay === $second) {
            return null;
        }

        return Paycheck::weekdaySplit(sprintf('%04d-%02d-%02d', $year, $month, $probeDay), $second, (int) $cycle['rateMinor']);
    }

    /** @return list<array<string, mixed>> */
    private function bills(string $today, string $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.amount_minor, b.next_due_date, b.anchor_date, b.autopay, b.recurrence,
                    a.name AS account_name, a.currency
             FROM bills b
             JOIN accounts a ON a.id = b.account_id
             WHERE a.user_id = ? AND b.status = 'active'"
        );
        $stmt->execute([$this->actor->id()]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $currency = (string) $row['currency'];
            foreach ($this->dueDates((string) $row['recurrence'], (string) $row['anchor_date'], (string) $row['next_due_date'], $today, $end) as $date) {
                $rows[] = [
                    'kind' => 'bill',
                    'on' => $date,
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'amountMinor' => (int) $row['amount_minor'],
                    'currency' => $currency,
                    'exponent' => $this->currencies->exponent($currency),
                    'accountName' => $row['account_name'],
                    'autopay' => (int) $row['autopay'] === 1,
                ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function dueDates(string $recurrence, string $anchor, string $next, string $today, string $end): array
    {
        if ($next > $end) {
            return [];
        }
        if ($recurrence === 'once') {
            return $next >= $today ? [$next] : [];
        }
        $cursor = $next;
        for ($i = 0; $i < 400 && $cursor < $today; $i++) {
            try {
                $advanced = Schedule::advance($recurrence, $anchor, $cursor);
            } catch (\InvalidArgumentException) {
                return [];
            }
            if ($advanced <= $cursor) {
                return [];
            }
            $cursor = $advanced;
        }
        $dates = [];
        for ($i = 0; $i < 400 && $cursor <= $end; $i++) {
            if ($cursor >= $today) {
                $dates[] = $cursor;
            }
            try {
                $advanced = Schedule::advance($recurrence, $anchor, $cursor);
            } catch (\InvalidArgumentException) {
                break;
            }
            if ($advanced <= $cursor) {
                break;
            }
            $cursor = $advanced;
        }

        return $dates;
    }

    /** @return list<array<string, mixed>> */
    private function entryMarks(string $today, string $end): array
    {
        $rows = [];
        foreach ($this->entries->between($today, $end) as $entry) {
            foreach ($entry['days'] as $day) {
                $rows[] = [
                    'kind' => 'entry',
                    'on' => $day,
                    'id' => $entry['id'],
                    'text' => $entry['text'],
                    'startOn' => $entry['startOn'],
                    'endOn' => $entry['endOn'],
                ];
            }
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $trips
     *  @return list<array<string, mixed>>
     */
    private function stays(array $trips, string $today, string $end): array
    {
        $rows = [];
        foreach ($trips as $trip) {
            $arrive = (string) $trip['arriveOn'];
            $depart = (string) $trip['departOn'];
            if ($arrive <= $end && $depart >= $today) {
                $rows[] = [
                    'kind' => 'stay',
                    'on' => $arrive < $today ? $today : $arrive,
                    'tripId' => $trip['id'],
                    'placeName' => $trip['placeName'],
                    'arriveOn' => $arrive,
                    'departOn' => $depart,
                    'fuel' => $trip['fuel'],
                ];
            }
            $due = $trip['balanceDueOn'];
            if (is_string($due) && $due >= $today && $due <= $end) {
                $rows[] = [
                    'kind' => 'balanceDue',
                    'on' => $due,
                    'tripId' => $trip['id'],
                    'placeName' => $trip['placeName'],
                    'amountMinor' => $trip['amountDueMinor'],
                    'currency' => $trip['currency'],
                    'exponent' => $trip['exponent'],
                ];
            }
        }

        return $rows;
    }
}
