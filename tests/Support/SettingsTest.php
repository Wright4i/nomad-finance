<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FINANCE_DB');
        putenv('FINANCE_NEW_USER');
        putenv('FINANCE_HOMEPAGE_TOKEN');
        putenv('FINANCE_HOMEPAGE_TZ');
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

    public function testNewUserStaysOffUnlessTheFlagIsOn(): void
    {
        putenv('FINANCE_NEW_USER');
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertFalse($settings['allowNewUser']);

        putenv('FINANCE_NEW_USER=0');
        $closed = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertFalse($closed['allowNewUser']);

        putenv('FINANCE_NEW_USER=1');
        $open = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertTrue($open['allowNewUser']);
    }

    public function testHomepageTokenAndTimezone(): void
    {
        putenv('FINANCE_HOMEPAGE_TOKEN');
        putenv('FINANCE_HOMEPAGE_TZ');
        $defaults = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertSame('', $defaults['homepageToken']);
        self::assertSame('America/Los_Angeles', $defaults['homepageTimezone']);

        putenv('FINANCE_HOMEPAGE_TOKEN=secret');
        putenv('FINANCE_HOMEPAGE_TZ=UTC');
        $set = require dirname(__DIR__, 2) . '/config/settings.php';
        self::assertSame('secret', $set['homepageToken']);
        self::assertSame('UTC', $set['homepageTimezone']);
    }
}
