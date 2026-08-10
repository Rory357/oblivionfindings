<?php

namespace App\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;
use RuntimeException;

final class ConfigureSensitiveDataRedaction
{
    public function __invoke(IlluminateLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;
        if (! $monolog instanceof MonologLogger) {
            throw new RuntimeException('Sensitive log redaction requires a Monolog logger.');
        }

        $monolog->pushProcessor(new SensitiveDataProcessor);
    }
}
