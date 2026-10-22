<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Pricing\BlackScholes;
use PHPUnit\Framework\TestCase;

final class BlackScholesTest extends TestCase
{
    public function testCallPriceMatchesReference(): void
    {
        // S=100, K=100, T=1y, r=5%, sigma=20% -> call 10.4506, put 5.5735
        $this->assertEqualsWithDelta(10.4506, BlackScholes::price('CE', 100, 100, 1.0, 0.05, 0.20), 0.001);
        $this->assertEqualsWithDelta(5.5735, BlackScholes::price('PE', 100, 100, 1.0, 0.05, 0.20), 0.001);
    }

    public function testPutCallParity(): void
    {
        $c = BlackScholes::price('CE', 23000, 22800, 0.02, 0.065, 0.14);
        $p = BlackScholes::price('PE', 23000, 22800, 0.02, 0.065, 0.14);
        $this->assertEqualsWithDelta(23000 - 22800 * exp(-0.065 * 0.02), $c - $p, 0.01);
    }

    public function testImpliedVolRoundTrip(): void
    {
        $price = BlackScholes::price('PE', 23033, 23000, 6 / 365, 0.065, 0.1488);
        $iv = BlackScholes::impliedVol('PE', $price, 23033, 23000, 6 / 365, 0.065);
        $this->assertNotNull($iv);
        $this->assertEqualsWithDelta(0.1488, $iv, 1e-4);
    }

    public function testImpliedVolIsNullBelowIntrinsic(): void
    {
        $this->assertNull(BlackScholes::impliedVol('CE', 100.0, 23500, 23000, 0.02, 0.065));
    }

    public function testGreeksSigns(): void
    {
        $g = BlackScholes::greeks('CE', 23000, 23000, 0.02, 0.065, 0.14);
        $this->assertGreaterThan(0.5, $g['delta']);
        $this->assertLessThan(0.6, $g['delta']);
        $this->assertGreaterThan(0, $g['gamma']);
        $this->assertLessThan(0, $g['theta']);
        $this->assertGreaterThan(0, $g['vega']);
        $p = BlackScholes::greeks('PE', 23000, 23000, 0.02, 0.065, 0.14);
        $this->assertEqualsWithDelta($g['delta'] - 1, $p['delta'], 1e-9);
        $this->assertEqualsWithDelta($g['gamma'], $p['gamma'], 1e-9);
    }

    public function testExpiredOptionIsIntrinsic(): void
    {
        $this->assertSame(200.0, BlackScholes::price('CE', 23200, 23000, 0.0, 0.065, 0.2));
        $this->assertSame(0.0, BlackScholes::price('PE', 23200, 23000, 0.0, 0.065, 0.2));
    }
}
