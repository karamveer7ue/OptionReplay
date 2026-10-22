<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Data\DhanBackfill;
use App\Market\ExpiryRule;
use App\Market\TradingCalendar;
use PHPUnit\Framework\TestCase;

final class DhanBackfillTest extends TestCase
{
    public function testGroupsRowsIntoContractsByExpiryAndStrike(): void
    {
        TradingCalendar::setHolidays([]);
        ExpiryRule::setKnown(1, []);
        $u = ['id' => 1, 'weekly_expiry_dow' => 2, 'monthly_expiry_dow' => 2];
        $ist = new \DateTimeZone('Asia/Kolkata');
        $t1 = (new \DateTimeImmutable('2026-10-08 09:15', $ist))->getTimestamp(); // -> weekly 2026-10-13
        $t2 = (new \DateTimeImmutable('2026-10-14 09:15', $ist))->getTimestamp(); // -> weekly 2026-10-20 (no holidays set)
        $payload = ['data' => ['ce' => [
            'timestamp' => [$t1, $t1 + 60, $t2],
            'strike' => [23000, 23000, 23100],
            'open' => [100, 101, 50], 'high' => [102, 103, 51], 'low' => [99, 100, 49], 'close' => [101, 102, 50.5],
            'volume' => [10, 20, 30], 'oi' => [1000, 1000, 2000],
        ], 'pe' => null]];
        $g = DhanBackfill::group($u, $payload, 'CE', 'weekly');
        $this->assertSame(['2026-10-13|23000', '2026-10-20|23100'], array_keys($g));
        $this->assertCount(2, $g['2026-10-13|23000']);
        $this->assertSame('2026-10-08 09:16:00', $g['2026-10-13|23000'][1]->ts->format('Y-m-d H:i:s'));
        $this->assertSame(2000, $g['2026-10-20|23100'][0]->oi);
        $this->assertSame([], DhanBackfill::group($u, $payload, 'PE', 'weekly'));
    }
}
