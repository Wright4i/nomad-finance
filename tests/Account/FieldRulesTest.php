<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Database\Database;
use App\Http\ApiException;
use App\Money\CurrencyCatalog;
use App\Tests\Support\DemoSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class FieldRulesTest extends TestCase
{
    private PDO $pdo;

    private AccountService $accounts;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        DemoSession::start($this->pdo);
        $this->accounts = new AccountService($this->pdo, new CurrencyCatalog(), new Actor($this->pdo));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCheckingDropsLiabilityFields(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'minimumDueMinor' => 50,
            'dueDay' => 3,
            'creditLimitMinor' => 500,
        ]);

        self::assertSame('asset', $account['side']);
        self::assertNull($account['minimumDueMinor']);
        self::assertNull($account['dueDay']);
        self::assertNull($account['creditLimitMinor']);
    }

    public function testCreditKeepsLiabilityFields(): void
    {
        $account = $this->accounts->create([
            'name' => 'Card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4000,
            'interestBps' => 1999,
            'minimumDueMinor' => 2500,
            'dueDay' => 15,
            'creditLimitMinor' => 200000,
        ]);

        self::assertSame('liability', $account['side']);
        self::assertSame(1999, $account['interestBps']);
        self::assertSame(2500, $account['minimumDueMinor']);
        self::assertSame(15, $account['dueDay']);
        self::assertSame(200000, $account['creditLimitMinor']);
    }

    public function testTheAssetFavoriteClearsTheOtherAndSortsFirst(): void
    {
        $first = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'assetFavorite' => true,
        ]);
        $second = $this->accounts->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 50,
            'assetFavorite' => true,
        ]);

        self::assertTrue($second['assetFavorite']);
        self::assertFalse($this->accounts->get($first['id'])['assetFavorite']);
        self::assertSame($second['id'], $this->accounts->list(false)[0]['id']);
    }

    public function testTheListPutsFavoritesFirstThenTypeThenName(): void
    {
        $this->accounts->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 50,
        ]);
        $this->accounts->create([
            'name' => 'Camp jar',
            'type' => 'cash',
            'currency' => 'USD',
            'balanceMinor' => 20,
        ]);
        $this->accounts->create([
            'name' => 'Travel card',
            'type' => 'credit',
            'currency' => 'USD',
            'balanceMinor' => 4000,
        ]);
        $everyday = $this->accounts->create([
            'name' => 'Everyday',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'assetFavorite' => true,
        ]);
        $this->accounts->create([
            'name' => 'Emergency',
            'type' => 'savings',
            'currency' => 'USD',
            'balanceMinor' => 80,
        ]);

        self::assertSame(
            ['Everyday', 'Camp jar', 'Wallet', 'Travel card', 'Emergency'],
            array_column($this->accounts->list(false), 'name'),
        );

        $this->accounts->update($everyday['id'], [
            'name' => 'Everyday',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'assetFavorite' => false,
        ]);

        self::assertSame(
            ['Camp jar', 'Wallet', 'Everyday', 'Travel card', 'Emergency'],
            array_column($this->accounts->list(false), 'name'),
        );
    }

    public function testAnArchivedAccountCannotBeTheAssetFavorite(): void
    {
        $account = $this->accounts->create([
            'name' => 'Old checking',
            'type' => 'checking',
            'currency' => 'USD',
            'balanceMinor' => 100,
            'assetFavorite' => true,
        ]);
        $archived = $this->accounts->archive($account['id']);
        self::assertFalse($archived['assetFavorite']);

        try {
            $this->accounts->update($account['id'], [
                'name' => 'Old checking',
                'type' => 'checking',
                'currency' => 'USD',
                'balanceMinor' => 100,
                'assetFavorite' => true,
            ]);
            self::fail('An archived account became the asset favorite.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('assetFavorite', $e->fields);
        }
    }

    public function testIncludeInBudgetIsOnlyForAssets(): void
    {
        $checking = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'balanceMinor' => 100,
        ]);
        self::assertTrue($checking['inBudget']);

        $savings = $this->accounts->create([
            'name' => 'Savings',
            'type' => 'savings',
            'balanceMinor' => 100,
        ]);
        self::assertFalse($savings['inBudget']);
        $included = $this->accounts->update($savings['id'], [
            'name' => 'Savings',
            'type' => 'savings',
            'balanceMinor' => 100,
            'inBudget' => true,
        ]);
        self::assertTrue($included['inBudget']);

        $card = $this->accounts->create([
            'name' => 'Card',
            'type' => 'credit',
            'balanceMinor' => 100,
            'inBudget' => true,
        ]);
        self::assertFalse($card['inBudget']);

        $tab = $this->accounts->create([
            'name' => 'Tab',
            'type' => 'other',
            'side' => 'liability',
            'balanceMinor' => 100,
            'inBudget' => true,
        ]);
        self::assertFalse($tab['inBudget']);

        $kept = $this->accounts->update($checking['id'], [
            'name' => 'Checking',
            'type' => 'checking',
            'balanceMinor' => 100,
            'inBudget' => false,
        ]);
        self::assertFalse($kept['inBudget']);
        $forced = $this->accounts->update($checking['id'], [
            'name' => 'Checking',
            'type' => 'credit',
            'balanceMinor' => 100,
            'inBudget' => true,
        ]);
        self::assertFalse($forced['inBudget']);
    }

    public function testHolderRoundTripsAndABlankStoresNull(): void
    {
        $account = $this->accounts->create([
            'name' => 'Checking',
            'type' => 'checking',
            'balanceMinor' => 100,
            'holder' => '  Ester  ',
        ]);
        self::assertSame('Ester', $account['holder']);
        self::assertSame('Ester', $this->accounts->get($account['id'])['holder']);

        $renamed = $this->accounts->update($account['id'], [
            'name' => 'Checking',
            'type' => 'checking',
            'balanceMinor' => 100,
            'holder' => 'W4i',
        ]);
        self::assertSame('W4i', $renamed['holder']);

        $cleared = $this->accounts->update($account['id'], [
            'name' => 'Checking',
            'type' => 'checking',
            'balanceMinor' => 100,
            'holder' => '',
        ]);
        self::assertNull($cleared['holder']);

        $blank = $this->accounts->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'balanceMinor' => 50,
            'holder' => '   ',
        ]);
        self::assertNull($blank['holder']);

        $card = $this->accounts->create([
            'name' => 'Card',
            'type' => 'credit',
            'balanceMinor' => 100,
            'holder' => 'Joseph',
            'inBudget' => true,
        ]);
        self::assertSame('Joseph', $card['holder']);
        self::assertSame('liability', $card['side']);
        self::assertFalse($card['inBudget']);
    }

    public function testOtherRequiresASide(): void
    {
        try {
            $this->accounts->create([
                'name' => 'Brokerage',
                'type' => 'other',
                'currency' => 'USD',
                'balanceMinor' => 100,
            ]);
            self::fail('Other accounts need a side.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('side', $e->fields);
        }
    }

    public function testEachFavoriteIsOneAccountAndAnAccountHoldsOnlyOne(): void
    {
        $checking = $this->accounts->create([
            'name' => 'Everyday',
            'type' => 'checking',
            'balanceMinor' => 100,
        ]);
        $savings = $this->accounts->create([
            'name' => 'Emergency',
            'type' => 'savings',
            'balanceMinor' => 80,
            'assetFavorite' => true,
        ]);
        $card = $this->accounts->create([
            'name' => 'Travel card',
            'type' => 'credit',
            'balanceMinor' => 40,
            'shoppingFavorite' => true,
        ]);
        $fuel = $this->accounts->create([
            'name' => 'Fuel card',
            'type' => 'fuel',
            'balanceMinor' => 20,
            'gasFavorite' => true,
        ]);
        $wallet = $this->accounts->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'balanceMinor' => 10,
        ]);

        self::assertSame(
            ['Fuel card', 'Travel card', 'Emergency', 'Wallet', 'Everyday'],
            array_column($this->accounts->list(false), 'name'),
        );
        self::assertFalse($checking['assetFavorite']);
        self::assertTrue($fuel['gasFavorite']);
        self::assertTrue($card['shoppingFavorite']);
        self::assertTrue($savings['assetFavorite']);

        $moved = $this->accounts->update($wallet['id'], [
            'name' => 'Wallet',
            'type' => 'cash',
            'balanceMinor' => 10,
            'assetFavorite' => true,
        ]);
        self::assertTrue($moved['assetFavorite']);
        self::assertFalse($this->accounts->get($savings['id'])['assetFavorite']);
        self::assertFalse($this->accounts->get($checking['id'])['assetFavorite']);

        try {
            $this->accounts->update($fuel['id'], [
                'name' => 'Fuel card',
                'type' => 'fuel',
                'balanceMinor' => 20,
                'gasFavorite' => true,
                'shoppingFavorite' => true,
            ]);
            self::fail('One account held two favorites.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('shoppingFavorite', $e->fields);
        }

        try {
            $this->accounts->create([
                'name' => 'Checking gas',
                'type' => 'checking',
                'balanceMinor' => 1,
                'gasFavorite' => true,
            ]);
            self::fail('A checking account became the gas favorite.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('gasFavorite', $e->fields);
        }

        $archived = $this->accounts->archive($fuel['id']);
        self::assertFalse($archived['gasFavorite']);
        self::assertFalse($archived['assetFavorite']);
    }
}
