<?php
declare(strict_types=1);

namespace App\Data\Sources;

/** A derivative contract as discovered from a broker option/futures chain. */
final class ContractInfo
{
    public function __construct(
        public readonly string $kind,          // CE | PE | FUT
        public readonly float $strike,         // 0 for FUT
        public readonly string $expiry,        // YYYY-MM-DD
        public readonly string $exchangeSeg,   // nse_fo
        public readonly string $token,         // broker instrument token
        public readonly string $tradingSymbol,
        public readonly int $lotSize,
        public readonly ?float $ltp = null,
        public readonly ?int $oi = null,
    ) {
    }

    public function neosymbol(): string
    {
        return $this->exchangeSeg . '|' . $this->token;
    }
}
