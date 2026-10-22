<?php
declare(strict_types=1);

namespace App\Http;

final class View
{
    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars = []): string
    {
        $file = APP_ROOT . '/src/Http/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Cache-busting asset URL based on file modification time. */
    public static function asset(string $path): string
    {
        $file = APP_ROOT . '/public/' . ltrim($path, '/');
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return '/' . ltrim($path, '/') . '?v=' . $v;
    }
}
