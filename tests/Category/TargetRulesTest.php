<?php

declare(strict_types=1);

namespace App\Tests\Category;

use App\Auth\Actor;
use App\Category\CategoryService;
use App\Database\Database;
use App\Http\ApiException;
use App\PayCycle\PayCycleService;
use App\Target\TargetService;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class TargetRulesTest extends TestCase
{
    private PDO $pdo;

    private CategoryService $categories;

    private PayCycleService $cycles;

    private string $path;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $actor = new Actor($this->pdo);
        $this->categories = new CategoryService($this->pdo, $actor, new TargetService($this->pdo, $actor));
        $this->cycles = new PayCycleService($this->pdo, $actor);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testNeededByDateDividesTheGapAcrossPeriodsStillLeft(): void
    {
        $cycle = $this->monthly();
        $category = $this->categories->create([
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 120000,
                'targetDate' => '2027-02-01',
                'payCycleId' => $cycle['id'],
            ],
        ], '2026-11-03');

        self::assertSame(4, $category['target']['periodsLeft']);
        self::assertSame(30000, $category['target']['neededMinor']);
        self::assertFalse($category['target']['met']);
        self::assertFalse($category['target']['celebrate']);

        $this->pdo->prepare('UPDATE categories SET amount_minor = 60000 WHERE id = ?')->execute([$category['id']]);
        $halfway = $this->categories->get($category['id'], '2026-11-03');
        self::assertSame(15000, $halfway['target']['neededMinor']);

        $this->pdo->prepare('UPDATE categories SET amount_minor = 0 WHERE id = ?')->execute([$category['id']]);
        $tight = $this->categories->update($category['id'], [
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 100,
                'targetDate' => '2027-01-15',
                'payCycleId' => $cycle['id'],
            ],
        ], '2026-11-03');
        self::assertSame(3, $tight['target']['periodsLeft']);
        self::assertSame(33, $tight['target']['neededMinor']);
        self::assertFalse($tight['target']['met']);
    }

    public function testAPassedDateKeepsTheWholeGapAndDoesNotCelebrate(): void
    {
        $category = $this->categories->create([
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 120000,
                'targetDate' => '2026-10-01',
                'payCycleId' => $this->monthly()['id'],
            ],
        ], '2026-11-03');

        self::assertSame(0, $category['target']['periodsLeft']);
        self::assertSame(120000, $category['target']['neededMinor']);
        self::assertTrue($category['target']['passed']);
        self::assertFalse($category['target']['celebrate']);
    }

    public function testReachingTheAmountByTheDateCelebratesOnce(): void
    {
        $category = $this->categories->create([
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 120000,
                'targetDate' => '2027-02-01',
                'payCycleId' => $this->monthly()['id'],
            ],
        ], '2026-11-03');
        $this->pdo->prepare('UPDATE categories SET amount_minor = 120000 WHERE id = ?')->execute([$category['id']]);

        $met = $this->categories->get($category['id'], '2026-11-03');
        self::assertTrue($met['target']['met']);
        self::assertTrue($met['target']['celebrate']);
        self::assertSame(0, $met['target']['neededMinor']);

        $again = $this->categories->get($category['id'], '2026-11-03');
        self::assertFalse($again['target']['celebrate']);
        self::assertTrue($again['target']['met']);

        $raised = $this->categories->update($category['id'], [
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 200000,
                'targetDate' => '2027-02-01',
                'payCycleId' => $met['target']['payCycleId'],
            ],
        ], '2026-11-03');
        self::assertFalse($raised['target']['met']);
        self::assertFalse($raised['target']['celebrate']);
    }

    public function testATargetBalanceIsTheWholeGap(): void
    {
        $balance = $this->categories->create([
            'name' => 'Target Balance 1',
            'group' => 'savings',
            'target' => ['kind' => 'balance', 'amountMinor' => 50000],
        ], '2026-11-03');
        self::assertSame(50000, $balance['target']['neededMinor']);
        self::assertFalse($balance['target']['met']);
        self::assertNull($balance['target']['periodsLeft']);

        $this->pdo->prepare('UPDATE categories SET amount_minor = 50000 WHERE id = ?')->execute([$balance['id']]);
        $held = $this->categories->get($balance['id'], '2026-11-03');
        self::assertTrue($held['target']['met']);
        self::assertSame(0, $held['target']['neededMinor']);
        self::assertFalse($held['target']['celebrate']);
    }

    public function testAWishlistTargetIsRefused(): void
    {
        try {
            $this->categories->create([
                'name' => 'Savings 1',
                'group' => 'savings',
                'target' => ['kind' => 'wishlist'],
            ], '2026-11-03');
            self::fail('A wishlist is not a target.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('target', $e->fields);
        }
        self::assertSame(
            ['Needs Default', 'Fuel Bucket', 'Wants Default', 'Savings Default'],
            array_column($this->categories->list(true), 'name'),
        );
    }

    public function testAnOmittedTargetStaysAndANullTargetClearsIt(): void
    {
        $category = $this->categories->create([
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 120000,
                'targetDate' => '2027-02-01',
                'payCycleId' => $this->monthly()['id'],
            ],
        ], '2026-11-03');
        $renamed = $this->categories->update($category['id'], [
            'name' => 'Goal',
            'group' => 'savings',
        ], '2026-11-03');
        self::assertSame('Goal', $renamed['name']);
        self::assertSame(30000, $renamed['target']['neededMinor']);

        $cleared = $this->categories->update($category['id'], [
            'name' => 'Goal',
            'group' => 'savings',
            'target' => null,
        ], '2026-11-03');
        self::assertNull($cleared['target']);
    }

    public function testAnIrregularCycleCountsItsOpenPeriodAndNextPayday(): void
    {
        $cycle = $this->cycles->create([
            'payor' => 'Irregular Payor',
            'payday' => '2026-11-01',
            'length' => 'irregular',
            'nextPayday' => '2026-12-15',
            'payKind' => 'fixed',
            'rateMinor' => 100,
        ], '2026-11-03');
        $category = $this->categories->create([
            'name' => 'Needed By Date 1',
            'group' => 'savings',
            'target' => [
                'kind' => 'needed',
                'amountMinor' => 200,
                'targetDate' => '2027-06-01',
                'payCycleId' => $cycle['id'],
            ],
        ], '2026-11-03');

        self::assertSame(2, $category['target']['periodsLeft']);
        self::assertSame(100, $category['target']['neededMinor']);
    }

    public function testATargetNeedsAnAmountADateAndAnOpenPayCycle(): void
    {
        $cycle = $this->monthly();
        try {
            $this->categories->create([
                'name' => 'Needed By Date 1',
                'group' => 'savings',
                'target' => [
                    'kind' => 'needed',
                    'amountMinor' => 0,
                    'targetDate' => '2027-02-01',
                    'payCycleId' => $cycle['id'],
                ],
            ], '2026-11-03');
            self::fail('A zero target was accepted.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('Enter an amount.', $e->fields['targetAmount']);
        }

        $this->pdo->prepare('UPDATE pay_cycles SET archived_at = ? WHERE id = ?')->execute([gmdate('c'), $cycle['id']]);
        try {
            $this->categories->create([
                'name' => 'Needed By Date 1',
                'group' => 'savings',
                'target' => [
                    'kind' => 'needed',
                    'amountMinor' => 100,
                    'targetDate' => '2027-02-01',
                    'payCycleId' => $cycle['id'],
                ],
            ], '2026-11-03');
            self::fail('An archived pay cycle was accepted.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function monthly(): array
    {
        return $this->cycles->create([
            'payor' => 'Monthly Payor',
            'payday' => '2026-11-01',
            'length' => 'monthly',
            'payKind' => 'fixed',
            'rateMinor' => 100,
        ], '2026-11-03');
    }
}
