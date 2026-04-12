<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Cache;

trait HandlesMedicationSync
{
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
