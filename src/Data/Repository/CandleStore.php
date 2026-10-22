<?php
declare(strict_types=1);

namespace App\Data\Repository;

use App\Core\Database;
use App\Data\Sources\Candle;

/** Bulk upserts of candles. Prices are stored in paise. */
final class CandleStore
{
    private const BATCH = 500;

    public static function paise(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    /** Brokers occasionally send negative volume/OI on correction prints; store them as zero. */
    private static function count(?int $v): ?int
    {
        return $v === null ? null : max(0, $v);
    }

    /** @param Candle[] $candles */
    public static function saveInstrument1m(int $instrumentId, array $candles, bool $overwrite = true): int
    {
        return self::bulk(
            ($overwrite ? 'INSERT INTO' : 'INSERT IGNORE INTO') . ' candles_1m (instrument_id, ts, o, h, l, c, volume, oi) VALUES ',
            '(?,?,?,?,?,?,?,?)',
            $overwrite ? ' ON DUPLICATE KEY UPDATE o=VALUES(o), h=VALUES(h), l=VALUES(l), c=VALUES(c), volume=VALUES(volume), oi=COALESCE(VALUES(oi), oi)' : '',
            $candles,
            fn (Candle $c) => [$instrumentId, $c->ts->format('Y-m-d H:i:s'), self::paise($c->open), self::paise($c->high), self::paise($c->low), self::paise($c->close), self::count($c->volume), self::count($c->oi)],
        );
    }

    /** @param Candle[] $candles */
    public static function saveInstrument1d(int $instrumentId, array $candles): int
    {
        return self::bulk(
            'INSERT INTO candles_1d (instrument_id, day, o, h, l, c, volume, oi) VALUES ',
            '(?,?,?,?,?,?,?,?)',
            ' ON DUPLICATE KEY UPDATE o=VALUES(o), h=VALUES(h), l=VALUES(l), c=VALUES(c), volume=VALUES(volume), oi=COALESCE(VALUES(oi), oi)',
            $candles,
            fn (Candle $c) => [$instrumentId, $c->ts->format('Y-m-d'), self::paise($c->open), self::paise($c->high), self::paise($c->low), self::paise($c->close), self::count($c->volume), self::count($c->oi)],
        );
    }

    /** @param Candle[] $candles */
    public static function saveSpot1m(int $underlyingId, array $candles): int
    {
        return self::bulk(
            'INSERT INTO spot_candles_1m (underlying_id, ts, o, h, l, c) VALUES ',
            '(?,?,?,?,?,?)',
            ' ON DUPLICATE KEY UPDATE o=VALUES(o), h=VALUES(h), l=VALUES(l), c=VALUES(c)',
            $candles,
            fn (Candle $c) => [$underlyingId, $c->ts->format('Y-m-d H:i:s'), self::paise($c->open), self::paise($c->high), self::paise($c->low), self::paise($c->close)],
        );
    }

    /** @param Candle[] $candles */
    public static function saveSpot1d(int $underlyingId, array $candles): int
    {
        return self::bulk(
            'INSERT INTO spot_candles_1d (underlying_id, day, o, h, l, c, volume) VALUES ',
            '(?,?,?,?,?,?,?)',
            ' ON DUPLICATE KEY UPDATE o=VALUES(o), h=VALUES(h), l=VALUES(l), c=VALUES(c), volume=VALUES(volume)',
            $candles,
            fn (Candle $c) => [$underlyingId, $c->ts->format('Y-m-d'), self::paise($c->open), self::paise($c->high), self::paise($c->low), self::paise($c->close), self::count($c->volume)],
        );
    }

    /** @param Candle[] $candles @param callable(Candle):array<int,mixed> $mapRow */
    private static function bulk(string $prefix, string $placeholder, string $suffix, array $candles, callable $mapRow): int
    {
        $written = 0;
        foreach (array_chunk($candles, self::BATCH) as $chunk) {
            $params = [];
            foreach ($chunk as $c) {
                array_push($params, ...$mapRow($c));
            }
            $sql = $prefix . implode(',', array_fill(0, count($chunk), $placeholder)) . $suffix;
            Database::run($sql, $params);
            $written += count($chunk);
        }
        return $written;
    }
}
