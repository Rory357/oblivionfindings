<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Exceptions\UnsupportedRuntimeContractVersion;
use LogicException;

final class RuntimeEnvelopeHandlerRegistry
{
    /** @param array<string, RuntimeEnvelopeHandler|array<int, RuntimeEnvelopeHandler>> $handlers */
    public function __construct(private readonly array $handlers) {}

    public function for(RuntimeMessageType $type, int $payloadVersion = 1): RuntimeEnvelopeHandler
    {
        $registered = $this->handlers[$type->value] ?? null;
        $handler = is_array($registered) ? ($registered[$payloadVersion] ?? null) : $registered;

        if (! $handler instanceof RuntimeEnvelopeHandler) {
            if (is_array($registered)) {
                throw new UnsupportedRuntimeContractVersion(
                    "Runtime payload version {$payloadVersion} is unsupported for {$type->value}.",
                );
            }

            throw new LogicException("No runtime envelope handler is registered for {$type->value}.");
        }

        return $handler;
    }
}
