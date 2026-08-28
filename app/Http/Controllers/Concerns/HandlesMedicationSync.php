<?php

namespace App\Http\Controllers\Concerns;

use App\Services\MarScheduleService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

trait HandlesMedicationSync
{
    /**
     * Fail-closed provenance contract for medication submissions that may be
     * queued on a device and replayed later.
     *
     * Online submissions may still carry a UUID for idempotency, but capture
     * provenance is meaningful only when the client explicitly marks the
     * submission as queued offline.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function medicationOfflineSubmissionRules(Request $request): array
    {
        $queuedOffline = $request->boolean('queued_offline');

        return [
            'client_request_uuid' => [
                Rule::requiredIf($queuedOffline),
                'nullable',
                'uuid:4',
            ],
            'captured_offline_at' => [
                Rule::requiredIf($queuedOffline),
                Rule::prohibitedIf(! $queuedOffline),
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isRfc3339Timestamp($value)) {
                        $fail('The '.$attribute.' field must be a valid RFC 3339 timestamp.');
                    }
                },
            ],
            'origin_device_id' => [
                Rule::requiredIf($queuedOffline),
                Rule::prohibitedIf(! $queuedOffline),
                'nullable',
                'string',
                'max:128',
                'regex:/\S/u',
            ],
            'queued_offline' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function medicationOnlineOnlySubmissionRules(): array
    {
        return [
            'client_request_uuid' => ['required', 'uuid:4'],
            'captured_offline_at' => ['prohibited'],
            'origin_device_id' => ['prohibited'],
            'queued_offline' => ['sometimes', 'boolean', 'declined'],
        ];
    }

    /**
     * Return the client-supplied clinical timestamp, if one is trustworthy.
     * A device capture time is never promoted to the administration time for
     * an online submission.
     */
    protected function medicationSubmittedAdministrationAt(array $data): ?string
    {
        if (filled($data['administered_at'] ?? null)) {
            return (string) $data['administered_at'];
        }

        if (($data['queued_offline'] ?? false) && filled($data['captured_offline_at'] ?? null)) {
            return (string) $data['captured_offline_at'];
        }

        return null;
    }

    /**
     * Resolve only the timestamp needed for a non-locking concealment probe.
     * Malformed input is deliberately left for the later scoped validator, so
     * it cannot disclose a medication target before current work scope is
     * checked. This hint never grants authority; the locked decision rechecks
     * the validated timestamp.
     */
    protected function medicationConcealmentActionAt(
        Request $request,
        MarScheduleService $schedule,
        Carbon $fallback,
        bool $acceptAdministeredAt = true,
    ): Carbon {
        $rawActionAt = $acceptAdministeredAt
            ? $request->input('administered_at')
            : null;
        $usesOfflineCapture = false;
        if (! filled($rawActionAt) && $request->boolean('queued_offline')) {
            $rawActionAt = $request->input('captured_offline_at');
            $usesOfflineCapture = true;
        }

        if (! (is_string($rawActionAt) || is_numeric($rawActionAt)) || ! filled($rawActionAt)) {
            return $fallback;
        }
        if ($usesOfflineCapture && ! $this->isRfc3339Timestamp($rawActionAt)) {
            return $fallback;
        }

        try {
            return $schedule->parseWorkerDateTime((string) $rawActionAt);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function isRfc3339Timestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d+)?(?<timezone>Z|[+-](?<offset_hour>\d{2}):(?<offset_minute>\d{2}))$/D',
            $value,
            $parts,
        );

        if ($matched !== 1
            || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
            || (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) $parts['second'] > 59
        ) {
            return false;
        }

        if ($parts['timezone'] === 'Z') {
            return true;
        }

        return (int) $parts['offset_hour'] <= 23
            && (int) $parts['offset_minute'] <= 59;
    }

    protected function medicationSyncRequested(array $data): bool
    {
        return filled($data['client_request_uuid'] ?? null);
    }

    protected function medicationIdempotencyKey(string $scope, string $requestUuid): string
    {
        return "emar:idempotency:{$scope}:{$requestUuid}";
    }

    protected function medicationSyncPayload(
        array $data,
        string $status,
        bool $duplicate = false,
        ?string $message = null
    ): array {
        return array_filter([
            'status' => $status,
            'duplicate' => $duplicate,
            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
            'client_request_uuid' => $data['client_request_uuid'] ?? null,
            'captured_offline_at' => $data['captured_offline_at'] ?? null,
            'origin_device_id' => $data['origin_device_id'] ?? null,
            'message' => $message,
        ], fn ($value) => $value !== null);
    }

    protected function withMedicationSync(
        array $payload,
        array $data,
        string $status,
        bool $duplicate = false,
        ?string $message = null
    ): array {
        $payload['sync'] = $this->medicationSyncPayload(
            $data,
            $status,
            $duplicate,
            $message,
        );

        return $payload;
    }

    protected function getCachedMedicationSyncResponse(string $scope, array $data): ?array
    {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if (! $requestUuid) {
            return null;
        }

        $payload = Cache::get($this->medicationIdempotencyKey($scope, $requestUuid));
        if (! $payload) {
            return null;
        }

        return $this->withMedicationSync(
            $payload,
            $data,
            'duplicate',
            true,
            'This medication request was already processed.',
        );
    }

    protected function rememberMedicationSyncResponse(string $scope, array $data, array $payload): array
    {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if ($requestUuid) {
            Cache::put(
                $this->medicationIdempotencyKey($scope, $requestUuid),
                $payload,
                now()->addDays(7),
            );
        }

        return $payload;
    }

    protected function buildMedicationConflictPayload(array $data, string $message): array
    {
        return $this->withMedicationSync([
            'success' => false,
            'error' => $message,
        ], $data, 'conflict', false, $message);
    }

    protected function medicationProcessedStatus(array $data): string
    {
        return ($data['queued_offline'] ?? false) ? 'synced' : 'processed';
    }
}
