<?php

declare(strict_types=1);

namespace App\Tests\Homepage;

use App\Homepage\EntryHint;
use PHPUnit\Framework\TestCase;

final class EntryHintTest extends TestCase
{
    public function testHintsOnAMultiDaySpan(): void
    {
        self::assertSame('', EntryHint::forDay('2026-11-10', '2026-11-10', '2026-11-10'));
        self::assertSame('through Nov 12', EntryHint::forDay('2026-11-10', '2026-11-12', '2026-11-10'));
        self::assertSame('last day', EntryHint::forDay('2026-11-08', '2026-11-10', '2026-11-10'));
        self::assertSame('through Nov 12', EntryHint::forDay('2026-11-08', '2026-11-12', '2026-11-10'));
    }
}
