<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpUnitIsOperational(): void
    {
        self::assertSame(4, 2 + 2);
    }
}
