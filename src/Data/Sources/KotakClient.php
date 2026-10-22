<?php
declare(strict_types=1);

namespace App\Data\Sources;

use App\Core\Logger;

/**
 * Kotak NEO market-data REST client. These endpoints authenticate with the
 * consumer key alone; no TOTP session is needed.
 * Reference: https://github.com/Kotak-Neo/kotak-neo-python (docs/functions/market_data).
 */
final class KotakClient implements MarketDataSource
{
    private const BASE = 'https://mis.kotaksecurities.com/market-data/1.0';

    private const MAX_DAYS = [
        '1min' => 30, '3min' => 30, '5min' => 30,
        '10min' => 60, '15min' => 60,
        '30min' => 90, '60min' => 90,
        'D' => 180, 'W' => 180,
    ];

    public int $requestCount = 0;

    public function __construct(private readonly string $consumerKey, private readonly int $timeoutSec = 30)
    {
    }

    public function name(): string
    {
        return 'kotak';
    }

    public function maxDays(string $interval): int
    {
        return self::MAX_DAYS[$interval] ?? 30;
    }

    public function expiries(string $foExchange, string $underlying): array
    {
        $data = $this->get('/watchlist/expiries', ['exchange' => $foExchange, 'underlying' => $underlying, 'instrument_type' => 'option']);
        $list = $data['expiries'] ?? [];
        sort($list);
        return array_values(array_filter($list, fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
    }

    public function optionChain(string $foExchange, string $underlying, string $expiry, int $count): array
    {
        $count = max(10, (int) (ceil($count / 10) * 10));
        $data = $this->get('/watchlist/option-chain', [
            'exchange' => $foExchange, 'underlying' => $underlying, 'expiry' => $expiry,
            'instrument_type' => 'option', 'count' => $count,
        ]);
        $lot = (int) ($data['common_data']['mktLot'] ?? 0);
        $out = [];
        foreach (['call', 'put'] as $side) {
            foreach ($data[$side] ?? [] as $row) {
                $inst = $row['inst'] ?? $row['instrument'] ?? [];
                $neo = (string) ($inst['neoSymbol'] ?? '');
                if (!str_contains($neo, '|')) {
                    continue;
                }
                [$seg, $token] = explode('|', $neo, 2);
                $quote = $row['quote'] ?? [];
                $oi = $row['oi'] ?? $row['openInterest'] ?? [];
                $out[] = new ContractInfo(
                    kind: strtoupper((string) ($inst['optType'] ?? $inst['optionType'] ?? ($side === 'call' ? 'CE' : 'PE'))),
                    strike: (float) ($inst['strkPrc'] ?? $inst['strikePrice'] ?? 0),
                    expiry: (string) ($inst['exp'] ?? $data['common_data']['expiryDt'] ?? $expiry),
                    exchangeSeg: $seg,
                    token: $token,
                    tradingSymbol: (string) ($inst['symbol'] ?? ''),
                    lotSize: $lot,
                    ltp: isset($quote['ltp']) ? (float) $quote['ltp'] : null,
                    oi: isset($oi['cur']) ? (int) $oi['cur'] : (isset($oi['current']) ? (int) $oi['current'] : null),
                );
            }
        }
        return $out;
    }

    public function futuresChain(string $foExchange, string $underlying): array
    {
        $data = $this->get('/watchlist/option-chain', ['exchange' => $foExchange, 'underlying' => $underlying, 'instrument_type' => 'fut']);
        $lot = (int) ($data['common_data']['mktLot'] ?? 0);
        $out = [];
        foreach ($data['fut'] ?? [] as $row) {
            $inst = $row['inst'] ?? [];
            $neo = (string) ($inst['neoSymbol'] ?? '');
            if (!str_contains($neo, '|')) {
                continue;
            }
            [$seg, $token] = explode('|', $neo, 2);
            $expiry = self::normaliseDate((string) ($inst['expiryDt'] ?? ''));
            if ($expiry === null) {
                continue;
            }
            $quote = $row['quote'] ?? [];
            $out[] = new ContractInfo('FUT', 0.0, $expiry, $seg, $token, (string) ($inst['symbol'] ?? ''), $lot,
                isset($quote['ltp']) ? (float) $quote['ltp'] : null, isset($row['oi']['cur']) ? (int) $row['oi']['cur'] : null);
        }
        return $out;
    }

    public function candles(string $neosymbol, string $interval, string $fromDate, string $toDate): array
    {
        $data = $this->get('/historical/details', [
            'neosymbol' => $neosymbol, 'interval' => $interval, 'fromdate' => $fromDate, 'todate' => $toDate,
        ]);
        $rows = $data['data']['candles'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || count($r) < 5) {
                continue;
            }
            $ts = new \DateTimeImmutable((string) $r[0]);
            $ts = $ts->setTimezone(new \DateTimeZone('Asia/Kolkata'));
            $out[] = new Candle($ts, (float) $r[1], (float) $r[2], (float) $r[3], (float) $r[4], (int) ($r[5] ?? 0), isset($r[6]) && $r[6] !== null ? (int) $r[6] : null);
        }
        usort($out, fn (Candle $a, Candle $b) => $a->ts <=> $b->ts);
        return $out;
    }

    /** "31-JUL-2026" or "2026-07-31" -> "2026-07-31" */
    private static function normaliseDate(string $raw): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $dt = \DateTimeImmutable::createFromFormat('d-M-Y', ucfirst(strtolower($raw)));
        return $dt ? $dt->format('Y-m-d') : null;
    }

    /** @param array<string,scalar> $query @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        $url = self::BASE . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $this->consumerKey, 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $this->requestCount++;
        if ($raw === false) {
            $err = curl_error($ch);
            throw new \RuntimeException("Kotak request failed: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);

        if ($status === 429) {
            $retry = preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) ? (int) $m[1] : 60;
            throw new RateLimitedException($retry);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Kotak HTTP {$status}: non-JSON response: " . substr($body, 0, 200));
        }
        if ($status >= 400 || (isset($json['status']) && strtoupper((string) $json['status']) === 'ERROR')) {
            $msg = $json['fault']['message'] ?? $json['message'] ?? $json['error'][0]['message'] ?? 'unknown error';
            $code = (int) ($json['fault']['code'] ?? $status);
            Logger::error('fetch', "Kotak error {$code} on {$path}", ['query' => $query, 'msg' => $msg]);
            throw new \RuntimeException("Kotak error {$code}: {$msg}", $code);
        }
        return $json;
    }
}
