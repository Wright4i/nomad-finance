<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Support\Dates;
use PDO;

final class CalendarEntryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
    ) {
    }

    /**
     * Entries that overlap one calendar month, with each covered day in that month.
     *
     * @return list<array{id:int,text:string,startOn:string,endOn:string,days:list<string>}>
     */
    public function month(string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ApiException(422, 'Use a YYYY-MM month.', ['month' => 'Use a YYYY-MM month.']);
        }
        $start = $month . '-01';
        $end = Dates::parse($start)->modify('last day of this month')->format('Y-m-d');

        return $this->between($start, $end);
    }

    /**
     * Entries that overlap a date span, with each covered day inside that span.
     *
     * @return list<array{id:int,text:string,startOn:string,endOn:string,days:list<string>}>
     */
    public function between(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, text, start_on, end_on
             FROM calendar_entries
             WHERE user_id = ? AND start_on <= ? AND end_on >= ?
             ORDER BY start_on, end_on, id'
        );
        $stmt->execute([$this->actor->id(), $to, $from]);
        $listed = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                break;
            }
            $entry = $this->present($row);
            $entry['days'] = $this->daysBetween($entry['startOn'], $entry['endOn'], $from, $to);
            $listed[] = $entry;
        }

        return $listed;
    }

    /** @param array<string, mixed> $input
     *  @return array{id:int,text:string,startOn:string,endOn:string}
     */
    public function create(array $input): array
    {
        $data = $this->validate($input);
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO calendar_entries (user_id, text, start_on, end_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->actor->id(),
            $data['text'],
            $data['startOn'],
            $data['endOn'],
            $now,
            $now,
        ]);

        return $this->present($this->row((int) $this->pdo->lastInsertId()));
    }

    /** @param array<string, mixed> $input
     *  @return array{id:int,text:string,startOn:string,endOn:string}
     */
    public function update(int $id, array $input): array
    {
        $this->row($id);
        $data = $this->validate($input);
        $this->pdo->prepare(
            'UPDATE calendar_entries SET text = ?, start_on = ?, end_on = ?, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([
            $data['text'],
            $data['startOn'],
            $data['endOn'],
            gmdate('c'),
            $id,
            $this->actor->id(),
        ]);

        return $this->present($this->row($id));
    }

    public function delete(int $id): void
    {
        $this->row($id);
        $this->pdo->prepare('DELETE FROM calendar_entries WHERE id = ? AND user_id = ?')->execute([$id, $this->actor->id()]);
    }

    /** @param array<string, mixed> $input
     *  @return array{text:string,startOn:string,endOn:string}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $text = trim(is_string($input['text'] ?? null) ? $input['text'] : '');
        if ($text === '' || mb_strlen($text) > 80) {
            $errors['text'] = 'Use text of 1 to 80 characters.';
        }
        $start = $this->day(is_string($input['startOn'] ?? null) ? $input['startOn'] : '');
        $end = $this->day(is_string($input['endOn'] ?? null) ? $input['endOn'] : '');
        if ($start === null) {
            $errors['startOn'] = 'Use a YYYY-MM-DD start.';
        }
        if ($end === null) {
            $errors['endOn'] = 'Use a YYYY-MM-DD end.';
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors['endOn'] = 'End is on or after the start.';
        }
        if ($errors !== [] || $start === null || $end === null) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return [
            'text' => $text,
            'startOn' => $start,
            'endOn' => $end,
        ];
    }

    private function day(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return Dates::parse($value)->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, text, start_on, end_on FROM calendar_entries WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Calendar entry not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     *  @return array{id:int,text:string,startOn:string,endOn:string}
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'text' => (string) $row['text'],
            'startOn' => (string) $row['start_on'],
            'endOn' => (string) $row['end_on'],
        ];
    }

    /** @return list<string> */
    private function daysBetween(string $start, string $end, string $from, string $to): array
    {
        $cursor = $start > $from ? $start : $from;
        $last = $end < $to ? $end : $to;
        $days = [];
        for ($i = 0; $i < 400 && $cursor <= $last; $i++) {
            $days[] = $cursor;
            $next = Dates::parse($cursor)->modify('+1 day')->format('Y-m-d');
            if ($next <= $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $days;
    }
}
