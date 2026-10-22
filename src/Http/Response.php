<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(private readonly int $status, private readonly string $body, private readonly array $headers)
    {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: 'null', ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function error(string $message, int $status): self
    {
        return self::json(['error' => $message], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self(302, '', ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $this->body;
    }
}
