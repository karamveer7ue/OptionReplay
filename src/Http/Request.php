<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : $default;
    }

    public static function requireQuery(string $key): string
    {
        $v = self::query($key);
        if ($v === null) {
            throw new HttpException("Missing parameter: {$key}", 400);
        }
        return $v;
    }

    public static function post(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) ? trim($v) : $default;
    }

    /** Parse an IST timestamp like 2026-10-21T09:15 or "2026-10-21 09:15:00". */
    public static function time(string $key): \DateTimeImmutable
    {
        $raw = self::requireQuery($key);
        try {
            return new \DateTimeImmutable($raw, new \DateTimeZone('Asia/Kolkata'));
        } catch (\Exception) {
            throw new HttpException("Invalid time: {$raw}", 400);
        }
    }

    /** @return int[] */
    public static function intList(string $key): array
    {
        $raw = self::query($key, '');
        return array_values(array_filter(array_map('intval', explode(',', (string) $raw)), fn ($n) => $n > 0));
    }
}
