<?php

declare(strict_types=1);

namespace Arafat\Brain\Exceptions;

/**
 * Thrown when an AI provider call fails.
 *
 * The $code mirrors the HTTP status returned by the upstream API when
 * available, or 0 for connection-level failures.
 */
final class AIException extends BrainException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", $code, $previous);
    }
}
