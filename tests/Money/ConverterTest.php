<?php

declare(strict_types=1);

namespace App\Tests\Money;

use App\Money\Converter;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    public function testYenToDollars(): void
    {
        self::assertSame(670, Converter::convert(1000, 0, 2, '0.0067'));
    }

    public function testHalfUp(): void
    {
        self::assertSame(101, Converter::convert(150, 0, 2, '0.0067'));
    }

    public function testThreeDecimalCurrency(): void
    {
        self::assertSame(398, Converter::convert(1500, 3, 2, '2.65'));
    }

    public function testIdentityScale(): void
    {
        self::assertSame(2500, Converter::convert(2500, 2, 2, '1'));
    }

    public function testRejectsZeroRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Converter::assertRate('0');
    }
}
