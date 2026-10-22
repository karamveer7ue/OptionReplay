<?php
declare(strict_types=1);

namespace App\Data\Repository;

use App\Core\Database;
use App\Data\Sources\ContractInfo;

final class InstrumentRepository
{
    /** Insert or refresh a contract; returns its id. */
    public static function upsert(int $underlyingId, ContractInfo $c, string $source = 'kotak'): int
    {
        Database::run(
            'INSERT INTO instruments (underlying_id, kind, strike, expiry, exchange_seg, token, trading_symbol, lot_size, source)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE exchange_seg=VALUES(exchange_seg), token=VALUES(token), trading_symbol=VALUES(trading_symbol),
                lot_size=IF(VALUES(lot_size)>0, VALUES(lot_size), lot_size)',
            [$underlyingId, $c->kind, $c->strike, $c->expiry, $c->exchangeSeg, $c->token, $c->tradingSymbol, $c->lotSize, $source],
        );
        return (int) Database::scalar(
            'SELECT id FROM instruments WHERE underlying_id=? AND kind=? AND strike=? AND expiry=?',
            [$underlyingId, $c->kind, $c->strike, $c->expiry],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function liveForUnderlying(int $underlyingId, string $asOfDate): array
    {
        return Database::all(
            'SELECT * FROM instruments WHERE underlying_id = ? AND expiry >= ? ORDER BY expiry, kind, strike',
            [$underlyingId, $asOfDate],
        );
    }
}
