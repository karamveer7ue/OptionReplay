<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Sources\Candle;
use PHPUnit\Framework\TestCase;

final class KotakClientParsingTest extends TestCase
{
    public function testCandleValueObjectKeepsIst(): void
    {
        $ts = (new \DateTimeImmutable('2026-08-20T09:15:00+0530'))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $c = new Candle($ts, 1.0, 2.0, 0.5, 1.5, 10, null);
        $this->assertSame('2026-08-20 09:15:00', $c->ts->format('Y-m-d H:i:s'));
        $this->assertNull($c->oi);
    }
}
