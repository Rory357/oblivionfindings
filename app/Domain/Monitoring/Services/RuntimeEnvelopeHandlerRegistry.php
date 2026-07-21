<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use LogicException;

final class RuntimeEnvelopeHandlerRegistry
{
    /** @param array<string, RuntimeEnvelopeHandler> $handlers */
    public function __construct(private readonly array $handlers) {}

    public function for(RuntimeMessageType $type): RuntimeEnvelopeHandler
    {
        $handler = $this->handlers[$type->value] ?? null;

        if (! $handler instanceof RuntimeEnvelopeHandler) {
            throw new LogicException("No runtime envelope handler is registered for {$type->value}.");
        }

        return $handler;
    }
}
