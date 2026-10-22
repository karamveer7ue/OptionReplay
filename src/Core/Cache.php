<?php
declare(strict_types=1);

namespace App\Core;

/** Tiny file cache for computed values (works on any host, no extensions needed). */
final class Cache
{
    private static function path(string $key): string
    {
        return APP_ROOT . '/storage/cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    }

    /** @template T @param callable():T $compute @return T */
    public static function remember(string $key, int $ttlSeconds, callable $compute): mixed
    {
        $file = self::path($key);
        if (is_file($file) && filemtime($file) > time() - $ttlSeconds) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && array_key_exists('v', $decoded)) {
                    return $decoded['v'];
                }
            }
        }
        $value = $compute();
        @file_put_contents($file, json_encode(['v' => $value]), LOCK_EX);
        return $value;
    }

    public static function forget(string $prefix): void
    {
        foreach (glob(APP_ROOT . '/storage/cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $prefix) . '*.json') ?: [] as $f) {
            @unlink($f);
        }
    }
}
