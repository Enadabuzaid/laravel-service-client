<?php

namespace Enadstack\ServiceClient;

use RuntimeException;
use Throwable;

class ServiceException extends RuntimeException
{
    public const UNREACHABLE = 'SERVICE_UNREACHABLE';

    public const SERVICE_ERROR = 'SERVICE_ERROR';

    public const UNAUTHORIZED = 'UNAUTHORIZED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const CONFLICT = 'CONFLICT';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const IDEMPOTENCY_KEY_REQUIRED = 'IDEMPOTENCY_KEY_REQUIRED';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        protected string $errorCode,
        string $message,
        protected int $status = 0,
        protected array $details = [],
        ?Throwable $previous = null,
        protected ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** Seconds from the Retry-After header, when the service sent one. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Transient failure worth retrying: unreachable, 429, 502, 503, 504. */
    public function isRetryable(): bool
    {
        return $this->errorCode === self::UNREACHABLE || in_array($this->status, [429, 502, 503, 504], true);
    }

    public static function unreachable(string $service, ?Throwable $previous = null): self
    {
        return new self(self::UNREACHABLE, "Service [{$service}] is unreachable.", 0, [], $previous);
    }
}
