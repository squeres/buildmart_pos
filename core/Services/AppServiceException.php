<?php
declare(strict_types=1);

/**
 * Domain-safe exception for service-level business errors.
 */
final class AppServiceException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $payload;
    private string $appCode;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $message,
        string $appCode = 'service_error',
        array $payload = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->appCode = $appCode;
        $this->payload = $payload;
    }

    public function appCode(): string
    {
        return $this->appCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
