<?php

namespace App\Services\Integration;

use App\Support\SafeOperationalData;
use RuntimeException;
use Throwable;

final class IntegrationDiscoveryException extends RuntimeException
{
    private function __construct(private readonly string $category)
    {
        parent::__construct(SafeOperationalData::failureSummary());
    }

    public static function forHttpStatus(int $status): self
    {
        return new self(in_array($status, [401, 403], true)
            ? 'authentication_failure'
            : 'provider_failure');
    }

    public static function invalidResponse(): self
    {
        return new self('invalid_response');
    }

    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return new self(SafeOperationalData::failureCategory($exception));
    }

    public function failureCategory(): string
    {
        return $this->category;
    }
}
