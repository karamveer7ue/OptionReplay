<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Key/value settings stored in the database, editable from the admin panel.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public const DEFAULTS = [
        'lookback_days' => '365',
        'strike_band' => '25',
        'expiry_window_days' => '60',
        'fetch_delay_ms' => '1000',
        'fetch_time_budget_sec' => '50',
        'risk_free_rate' => '0.065',
        'brokerage_per_order' => '20',
        'stt_sell_pct' => '0.1',
        'exchange_txn_pct' => '0.03503',
        'sebi_fee_pct' => '0.0001',
        'stamp_duty_buy_pct' => '0.003',
        'gst_pct' => '18',
        'site_name' => 'OptionReplay',
        'default_underlying' => 'NIFTY',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::all('SELECT `key`, `value` FROM settings');
            $stored = array_column($rows, 'value', 'key');
            self::$cache = array_merge(self::DEFAULTS, $stored);
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key, '0');
    }

    public static function float(string $key): float
    {
        return (float) self::get($key, '0');
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value],
        );
        self::$cache = null;
    }
}
