<?php
declare(strict_types=1);

namespace App\Data\Sources;

/**
 * Dhan "expired options" backfill source. Dhan serves expired index option
 * candles on a rolling basis (ATM +/- n strikes), which Kotak cannot.
 * Reference: https://dhanhq.co/docs/v2/expired-options-data/
 *
 * Enabled when DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN are present in .env.
 * The adapter is wired but the backfill job type will be activated once credentials exist.
 */
final class DhanClient
{
    private const BASE = 'https://api.dhan.co/v2';

    public function __construct(private readonly string $clientId, private readonly string $accessToken)
    {
    }

    public static function configured(): bool
    {
        return (\App\Core\Env::get('DHAN_CLIENT_ID') !== null) && (\App\Core\Env::get('DHAN_ACCESS_TOKEN') !== null);
    }

    /**
     * Rolling expired option candles relative to ATM.
     * @param 'WEEK'|'MONTH' $expiryFlag
     * @param 'CALL'|'PUT' $side
     * @return array<string,mixed> raw Dhan payload (data.ce / data.pe arrays)
     */
    public function rollingOption(string $exchangeSegment, int $securityId, string $expiryFlag, int $expiryCode, string $strike, string $side, string $fromDate, string $toDate, string $interval = '1'): array
    {
        $payload = [
            'exchangeSegment' => $exchangeSegment, 'interval' => $interval, 'securityId' => $securityId,
            'instrument' => 'OPTIDX', 'expiryFlag' => $expiryFlag, 'expiryCode' => $expiryCode,
            'strike' => $strike, 'drvOptionType' => $side,
            'requiredData' => ['open', 'high', 'low', 'close', 'volume', 'oi', 'iv', 'strike', 'spot'],
            'fromDate' => $fromDate, 'toDate' => $toDate,
        ];
        $ch = curl_init(self::BASE . '/charts/rollingoption');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'access-token: ' . $this->accessToken, 'client-id: ' . $this->clientId],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new \RuntimeException('Dhan request failed');
        }
        if ($status === 429) {
            throw new RateLimitedException(60);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || $status >= 400) {
            throw new \RuntimeException("Dhan HTTP {$status}: " . substr((string) $raw, 0, 200));
        }
        return $json;
    }
}
