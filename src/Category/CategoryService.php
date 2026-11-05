<?php

declare(strict_types=1);

namespace App\Category;

use App\Auth\Actor;
use App\Http\ApiException;
use App\Target\TargetService;
use PDO;
use Throwable;

final class CategoryService
{
    private const GROUPS = ['needs', 'wants', 'savings'];

    private const OVERSPEND = ['cover', 'carry'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Actor $actor,
        private readonly TargetService $targets,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $includeArchived, ?string $today = null): array
    {
        $sql = 'SELECT * FROM categories WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= " ORDER BY CASE category_group WHEN 'needs' THEN 0 WHEN 'wants' THEN 1 ELSE 2 END, name COLLATE NOCASE, id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->actor->id()]);
        $rows = $stmt->fetchAll();
        $listed = array_map($this->present(...), is_array($rows) ? $rows : []);

        return $this->targets->decorate($listed, $today, true);
    }

    /** @return array<string, mixed> */
    public function get(int $id, ?string $today = null): array
    {
        return $this->targets->decorate([$this->present($this->row($id))], $today, true)[0];
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, ?string $today = null): array
    {
        $data = $this->validate($input, null);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO categories (user_id, name, category_group, amount_minor, overspend, fuel, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
            )->execute([$this->actor->id(), $data['name'], $data['group'], $data['overspend'], $data['fuel'], $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            if (array_key_exists('target', $input)) {
                $this->targets->replace($id, $input['target']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id, $today);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input, ?string $today = null): array
    {
        $existing = $this->present($this->row($id));
        $data = $this->validate($input, (string) $existing['overspend'], (bool) $existing['fuel']);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE categories SET name = ?, category_group = ?, overspend = ?, fuel = ?, updated_at = ? WHERE id = ? AND user_id = ?'
            )->execute([$data['name'], $data['group'], $data['overspend'], $data['fuel'], $now, $id, $this->actor->id()]);
            if (array_key_exists('target', $input)) {
                $this->targets->replace($id, $input['target']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id, $today);
    }

    /** @return array<string, mixed> */
    public function archive(int $id): array
    {
        $existing = $this->get($id);
        if ($existing['amountMinor'] !== 0) {
            throw new ApiException(409, 'This bucket still holds an amount. Bring it to zero before archiving it.');
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE categories SET archived_at = COALESCE(archived_at, ?), updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $now, $id, $this->actor->id()]);

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function unarchive(int $id): array
    {
        $this->get($id);
        $now = gmdate('c');
        $this->pdo->prepare(
            'UPDATE categories SET archived_at = NULL, updated_at = ? WHERE id = ? AND user_id = ?'
        )->execute([$now, $id, $this->actor->id()]);

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string,group:string,overspend:string,fuel:int}
     */
    private function validate(array $input, ?string $existingOverspend, bool $existingFuel = false): array
    {
        $errors = [];
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Use a name of 1 to 80 characters.';
        }

        $group = strtolower(trim(is_string($input['group'] ?? null) ? $input['group'] : ''));
        if (!in_array($group, self::GROUPS, true)) {
            $errors['group'] = 'Choose Needs, Wants, or Savings.';
        }

        if (!array_key_exists('overspend', $input)) {
            $overspend = $existingOverspend ?? 'cover';
        } else {
            $overspend = is_string($input['overspend']) ? $input['overspend'] : '';
            if (!in_array($overspend, self::OVERSPEND, true)) {
                $errors['overspend'] = 'Choose cover now or carry.';
                $overspend = 'cover';
            }
        }

        if (!array_key_exists('fuel', $input)) {
            $fuel = $existingFuel ? 1 : 0;
        } else {
            $fuel = ($input['fuel'] === true || $input['fuel'] === 1 || $input['fuel'] === '1') ? 1 : 0;
        }

        if ($errors !== []) {
            throw new ApiException(422, 'Check the highlighted fields.', $errors);
        }

        return ['name' => $name, 'group' => $group, 'overspend' => $overspend, 'fuel' => $fuel];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->actor->id()]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Category not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'group' => $row['category_group'],
            'overspend' => (string) ($row['overspend'] ?? 'cover'),
            'fuel' => (int) ($row['fuel'] ?? 0) === 1,
            'amountMinor' => (int) $row['amount_minor'],
            'archivedAt' => $row['archived_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
