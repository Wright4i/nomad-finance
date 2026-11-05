<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FINANCE_DB');
    }

    public function testFinanceDbOverridesTheDefaultPath(): void
    {
        putenv('FINANCE_DB');
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertSame(dirname(__DIR__, 2) . '/data/finance.sqlite', $settings['db']);

        putenv('FINANCE_DB=/tmp/nomad-override.sqlite');
        $overridden = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertSame('/tmp/nomad-override.sqlite', $overridden['db']);
    }
}
