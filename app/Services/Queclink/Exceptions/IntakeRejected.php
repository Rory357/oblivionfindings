<?php

namespace App\Services\Queclink\Exceptions;

use RuntimeException;

/**
 * A bounded, value-free intake rejection safe to classify operationally.
 */
final class IntakeRejected extends RuntimeException
{
    private const ALLOWED_REASONS = [
        'buffer_limit',
        'frame_limit',
        'invalid_frame',
        'invalid_direction',
    ];

    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = in_array($reason, self::ALLOWED_REASONS, true)
            ? $reason
            : 'invalid_frame';

        parent::__construct('Queclink intake rejected.');
    }
}
