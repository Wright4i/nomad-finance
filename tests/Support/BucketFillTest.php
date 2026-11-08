<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

final class BucketFillTest extends TestCase
{
    public function testTheFillFollowsTheGoalTheGroupAndAThousandDollarScale(): void
    {
        $script = dirname(__DIR__, 2) . '/frontend/src/lib/bucketFill.test.ts';
        $command = 'node --experimental-strip-types --test ' . escapeshellarg($script);
        exec($command . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
    }
}
