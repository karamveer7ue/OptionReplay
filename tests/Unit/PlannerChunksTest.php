<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Planner;
use App\Data\Sources\Candle;
use App\Data\Sources\MarketDataSource;
use PHPUnit\Framework\TestCase;

final class PlannerChunksTest extends TestCase
{
    private function planner(): Planner
    {
        $source = new class implements MarketDataSource {
            public function name(): string { return 'fake'; }
            public function expiries(string $foExchange, string $underlying): array { return []; }
            public function optionChain(string $foExchange, string $underlying, string $expiry, int $count): array { return []; }
            public function futuresChain(string $foExchange, string $underlying): array { return []; }
            public function candles(string $neosymbol, string $interval, string $fromDate, string $toDate): array { return []; }
            public function maxDays(string $interval): int { return 30; }
        };
        return new Planner($source);
    }

    public function testChunksCoverRangeWithoutGapsOrOverlap(): void
    {
        $chunks = $this->planner()->chunks('2025-10-21', '2026-10-21', 30);
        $this->assertSame('2025-10-21', $chunks[0][0]);
        $this->assertSame('2026-10-21', end($chunks)[1]);
        for ($i = 1; $i < count($chunks); $i++) {
            $prevEnd = new \DateTimeImmutable($chunks[$i - 1][1]);
            $this->assertSame($prevEnd->modify('+1 day')->format('Y-m-d'), $chunks[$i][0]);
            $days = (new \DateTimeImmutable($chunks[$i - 1][0]))->diff($prevEnd)->days + 1;
            $this->assertLessThanOrEqual(30, $days);
        }
    }

    public function testSingleDayRange(): void
    {
        $this->assertSame([['2026-10-21', '2026-10-21']], $this->planner()->chunks('2026-10-21', '2026-10-21', 30));
    }

    public function testInvertedRangeIsEmpty(): void
    {
        $this->assertSame([], $this->planner()->chunks('2026-10-22', '2026-10-21', 30));
    }
}
