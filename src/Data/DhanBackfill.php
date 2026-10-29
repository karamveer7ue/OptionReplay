<?php
declare(strict_types=1);

namespace App\Data;

use App\Data\Repository\CandleStore;
use App\Data\Repository\InstrumentRepository;
use App\Data\Sources\Candle;
use App\Data\Sources\ContractInfo;
use App\Data\Sources\DhanClient;
use App\Market\ExpiryRule;

/**
 * Expired-option backfill via Dhan's rolling option endpoint. One job = one (expiry flag, strike offset, side)
 * for a window of up to 30 days. Dhan returns the actual strike per minute, so rows are grouped into
 * concrete contracts (expiry resolved by rule) and stored without overwriting Kotak data.
 */
final class DhanBackfill
{
    public function __construct(private readonly DhanClient $client)
    {
    }

    /** @param array<string,mixed> $u @param array<string,mixed> $job */
    public function run(array $u, array $job): int
    {
        [$flag, $strike, $side] = explode('|', (string) $job['params']);
        $to = (new \DateTimeImmutable($job['to_date']))->modify('+1 day')->format('Y-m-d'); // Dhan toDate is exclusive
        $payload = $this->client->rollingOption((string) $u['dhan_segment'], (int) $u['dhan_security_id'], $flag, 1, $strike, $side, $job['from_date'], $to);
        $grouped = self::group($u, $payload, $side === 'CALL' ? 'CE' : 'PE', $flag === 'WEEK' ? 'weekly' : 'monthly');
        $written = 0;
        foreach ($grouped as $key => $candles) {
            [$expiry, $strikePrice] = explode('|', $key);
            $id = InstrumentRepository::upsert((int) $u['id'], new ContractInfo($side === 'CALL' ? 'CE' : 'PE', (float) $strikePrice, $expiry, strtolower((string) $u['dhan_segment']), 'dhan', '', (int) $u['lot_size']), 'dhan');
            $written += CandleStore::saveInstrument5m($id, $candles, false);
        }
        return $written;
    }

    /**
     * Pure mapping of a Dhan payload into contracts. Exposed for tests.
     * @param array<string,mixed> $u @param array<string,mixed> $payload
     * @return array<string,Candle[]> "expiry|strike" => candles
     */
    public static function group(array $u, array $payload, string $kind, string $expiryKind): array
    {
        $d = $payload['data'][$kind === 'CE' ? 'ce' : 'pe'] ?? null;
        if (!is_array($d) || empty($d['timestamp'])) {
            return [];
        }
        $tz = new \DateTimeZone('Asia/Kolkata');
        $out = [];
        $expiryCache = [];
        foreach ($d['timestamp'] as $i => $epoch) {
            $ts = (new \DateTimeImmutable('@' . (int) $epoch))->setTimezone($tz);
            $strike = (float) ($d['strike'][$i] ?? 0);
            if ($strike <= 0 || !isset($d['close'][$i])) {
                continue;
            }
            $day = $ts->format('Y-m-d');
            $expiryCache[$day] ??= ExpiryRule::next($u, $ts, $expiryKind);
            $key = $expiryCache[$day] . '|' . $strike;
            $out[$key][] = new Candle($ts, (float) $d['open'][$i], (float) $d['high'][$i], (float) $d['low'][$i], (float) $d['close'][$i], (int) ($d['volume'][$i] ?? 0), isset($d['oi'][$i]) ? (int) $d['oi'][$i] : null);
        }
        return $out;
    }
}
