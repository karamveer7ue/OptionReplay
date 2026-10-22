<?php
declare(strict_types=1);

namespace App\Data\Sources;

interface MarketDataSource
{
    public function name(): string;

    /** @return string[] ISO dates, ascending */
    public function expiries(string $foExchange, string $underlying): array;

    /**
     * Option contracts for one expiry. $count strikes around ATM (broker-defined), must be a multiple of 10.
     * @return ContractInfo[]
     */
    public function optionChain(string $foExchange, string $underlying, string $expiry, int $count): array;

    /** @return ContractInfo[] all futures contracts for the underlying */
    public function futuresChain(string $foExchange, string $underlying): array;

    /**
     * Candles for a neosymbol ("seg|token") at interval 1min|3min|5min|10min|15min|30min|60min|D.
     * @return Candle[] ascending by ts
     */
    public function candles(string $neosymbol, string $interval, string $fromDate, string $toDate): array;

    /** Max calendar days per request for the given interval. */
    public function maxDays(string $interval): int;
}
