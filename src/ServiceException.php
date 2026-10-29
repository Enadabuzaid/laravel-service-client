<?php

namespace Enadstack\ServiceClient;

use RuntimeException;
use Throwable;

class ServiceException extends RuntimeException
{
    public const UNREACHABLE = 'SERVICE_UNREACHABLE';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        protected string $errorCode,
        string $message,
        protected int $status = 0,
        protected array $details = [],
        ?Throwable $previous = null,
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

    public static function unreachable(string $service, ?Throwable $previous = null): self
    {
        return new self(self::UNREACHABLE, "Service [{$service}] is unreachable.", 0, [], $previous);
    }
}
