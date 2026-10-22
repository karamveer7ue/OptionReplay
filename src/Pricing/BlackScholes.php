<?php
declare(strict_types=1);

namespace App\Pricing;

/** European option pricing, implied volatility and Greeks (Black-Scholes-Merton, no dividends). */
final class BlackScholes
{
    public static function cdf(float $x): float
    {
        // Abramowitz-Stegun 7.1.26 with erf; accurate to ~1e-7.
        $t = 1.0 / (1.0 + 0.2316419 * abs($x));
        $d = 0.3989422804014327 * exp(-$x * $x / 2.0);
        $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
        return $x >= 0 ? 1.0 - $p : $p;
    }

    public static function pdf(float $x): float
    {
        return 0.3989422804014327 * exp(-$x * $x / 2.0);
    }

    public static function price(string $kind, float $s, float $k, float $t, float $r, float $sigma): float
    {
        if ($t <= 0 || $sigma <= 0) {
            return max(0.0, $kind === 'CE' ? $s - $k : $k - $s);
        }
        $d1 = (log($s / $k) + ($r + 0.5 * $sigma * $sigma) * $t) / ($sigma * sqrt($t));
        $d2 = $d1 - $sigma * sqrt($t);
        if ($kind === 'CE') {
            return $s * self::cdf($d1) - $k * exp(-$r * $t) * self::cdf($d2);
        }
        return $k * exp(-$r * $t) * self::cdf(-$d2) - $s * self::cdf(-$d1);
    }

    /** Implied volatility by bisection with a Newton polish. Returns null when no solution exists. */
    public static function impliedVol(string $kind, float $marketPrice, float $s, float $k, float $t, float $r): ?float
    {
        if ($t <= 0 || $marketPrice <= 0 || $s <= 0 || $k <= 0) {
            return null;
        }
        $intrinsic = max(0.0, $kind === 'CE' ? $s - $k * exp(-$r * $t) : $k * exp(-$r * $t) - $s);
        if ($marketPrice <= $intrinsic + 1e-6) {
            return null;
        }
        $lo = 0.001;
        $hi = 5.0;
        if (self::price($kind, $s, $k, $t, $r, $hi) < $marketPrice) {
            return null;
        }
        for ($i = 0; $i < 60; $i++) {
            $mid = 0.5 * ($lo + $hi);
            if (self::price($kind, $s, $k, $t, $r, $mid) > $marketPrice) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
            if ($hi - $lo < 1e-6) {
                break;
            }
        }
        return 0.5 * ($lo + $hi);
    }

    /** @return array{delta:float,gamma:float,theta:float,vega:float,rho:float} theta per calendar day, vega per 1% vol */
    public static function greeks(string $kind, float $s, float $k, float $t, float $r, float $sigma): array
    {
        if ($t <= 0 || $sigma <= 0) {
            $itm = $kind === 'CE' ? $s > $k : $s < $k;
            return ['delta' => $itm ? ($kind === 'CE' ? 1.0 : -1.0) : 0.0, 'gamma' => 0.0, 'theta' => 0.0, 'vega' => 0.0, 'rho' => 0.0];
        }
        $sqrtT = sqrt($t);
        $d1 = (log($s / $k) + ($r + 0.5 * $sigma * $sigma) * $t) / ($sigma * $sqrtT);
        $d2 = $d1 - $sigma * $sqrtT;
        $nd1 = self::pdf($d1);
        $disc = exp(-$r * $t);
        $gamma = $nd1 / ($s * $sigma * $sqrtT);
        $vega = $s * $nd1 * $sqrtT / 100.0;
        if ($kind === 'CE') {
            $delta = self::cdf($d1);
            $theta = (-$s * $nd1 * $sigma / (2 * $sqrtT) - $r * $k * $disc * self::cdf($d2)) / 365.0;
            $rho = $k * $t * $disc * self::cdf($d2) / 100.0;
        } else {
            $delta = self::cdf($d1) - 1.0;
            $theta = (-$s * $nd1 * $sigma / (2 * $sqrtT) + $r * $k * $disc * self::cdf(-$d2)) / 365.0;
            $rho = -$k * $t * $disc * self::cdf(-$d2) / 100.0;
        }
        return ['delta' => $delta, 'gamma' => $gamma, 'theta' => $theta, 'vega' => $vega, 'rho' => $rho];
    }
}
