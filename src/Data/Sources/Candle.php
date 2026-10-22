<?php
declare(strict_types=1);

namespace App\Data\Sources;

/** One OHLC bar. Prices are floats in rupees; ts is an IST DateTimeImmutable. */
final class Candle
{
    public function __construct(
        public readonly \DateTimeImmutable $ts,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly int $volume = 0,
        public readonly ?int $oi = null,
    ) {
    }
}
