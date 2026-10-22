<?php
declare(strict_types=1);

namespace App\Data\Sources;

final class RateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, string $message = 'Rate limited')
    {
        parent::__construct($message, 429);
    }
}
