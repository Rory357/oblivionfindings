<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class EndpointRestartContract
{
    public const CAPABILITY = 'endpoint.computer.restart';

    public const REASONS = ['restart_requested', 'restart_refused', 'declined', 'cancelled', 'expired', 'policy_unavailable', 'consent_lost', 'warning_incomplete', 'clock_changed', 'interrupted', 'operation_failed', 'offline', 'boot_unavailable', 'state_unavailable'];

    public static function parameters(array $input): array
    {
        $date = $input['execute_at'] ?? null;
        $warning = $input['warning_seconds'] ?? null;
        if (array_keys($input) !== ['execute_at', 'warning_seconds'] && (count($input) !== 2 || array_diff(array_keys($input), ['execute_at', 'warning_seconds']) !== [])) {
            self::invalid();
        }
        if (! is_string($date) || strlen($date) !== 20 || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $date) !== 1 || ! is_int($warning) || $warning < 60 || $warning > 3600) {
            self::invalid();
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, 'UTC');
        } catch (\Throwable) {
            self::invalid();
        }
        if ($parsed->format('Y-m-d\TH:i:s\Z') !== $date) {
            self::invalid();
        }

        return ['execute_at' => $date, 'warning_seconds' => $warning];
    }

    public static function requestedExpiry(array $input): CarbonImmutable
    {
        $parameters = self::parameters($input);
        $execute = CarbonImmutable::parse($parameters['execute_at']);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($execute->lessThan($now->addSeconds($parameters['warning_seconds'])) || $execute->greaterThan($now->addDay())) {
            self::invalid();
        }

        return $execute->addSeconds(5);
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['parameters' => 'Choose a valid UTC restart time within the next 24 hours with enough time for the visible warning.']);
    }

    public static function validateResult(array $data): void
    {
        $status = $data['status'];
        $reason = $data['reason_code'];
        $started = $data['mutation_started'];
        abort_if($status === 'awaiting_boot' && (! $started || $reason !== 'restart_requested'), 422);
        abort_if(in_array($status, ['declined', 'cancelled', 'expired'], true) && ($started || $reason !== $status), 422);
        abort_if($status === 'failed' && ($started ? $reason !== 'restart_refused' : in_array($reason, ['restart_requested', 'restart_refused', 'declined', 'cancelled', 'expired'], true)), 422);
        abort_if($status === 'uncertain' && in_array($reason, ['restart_requested', 'declined', 'cancelled', 'expired'], true), 422);
    }
}
