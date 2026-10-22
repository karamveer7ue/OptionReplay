<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private static ?string $dir = null;

    public static function setDir(string $dir): void
    {
        self::$dir = $dir;
    }

    /** @param array<string,mixed> $context */
    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $dir = self::$dir ?? dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        );
        file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'error', $message, $context);
    }
}
