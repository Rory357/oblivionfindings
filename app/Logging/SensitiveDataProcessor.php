<?php

namespace App\Logging;

use App\Support\Security\SensitiveDataRedactor;
use Monolog\LogRecord;

final readonly class SensitiveDataProcessor
{
    public function __construct(private SensitiveDataRedactor $redactor = new SensitiveDataRedactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->message($record->message),
            context: $this->redactor->context($record->context),
            extra: $this->redactor->context($record->extra),
        );
    }
}
