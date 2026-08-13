<?php

namespace App\Domain\SecurityDevices\Services;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integration\IntegrationEventHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalTrackingLocationExportService
{
    use SanitizesCsvOutput;

    public function __construct(
        private readonly PersonalTrackingPrivacyService $privacy,
        private readonly IntegrationEventHistoryService $history,
    ) {}

    /**
     * @param  array{reason: string, date_from: string, date_to: string, event_types?: array<int, string>}  $data
     */
    public function export(Client $client, User $user, array $data): StreamedResponse
    {
        abort_unless($user->canDo('assets.telemetry.export'), 403);

        $assignment = $this->privacy->authorisedClientAssignment($client);
        abort_unless($assignment, 403);

        $retentionDays = max(
            1,
            (int) ($assignment->retention_days
                ?? config('fleet.retention.personal_location_days', 90)),
        );
        $dateFrom = Carbon::parse($data['date_from'])->startOfDay();
        $dateTo = Carbon::parse($data['date_to'])->endOfDay();
        $maximumScopeDays = min(31, $retentionDays);
        $collectionStart = $assignment->collection_started_at ?? $assignment->assigned_at;

        if (! $collectionStart) {
            throw ValidationException::withMessages([
                'date_from' => 'This resident assignment has no authoritative collection start.',
            ]);
        }
        if ($dateFrom->lt($collectionStart)) {
            $dateFrom = $collectionStart->copy();
        }

        if ($dateFrom->isBefore(now()->subDays($retentionDays)->startOfDay())) {
            throw ValidationException::withMessages([
                'date_from' => "Exports cannot start before the {$retentionDays}-day retention boundary.",
            ]);
        }

        if ($dateFrom->diffInDays($dateTo) > $maximumScopeDays) {
            throw ValidationException::withMessages([
                'date_to' => "Choose a range of {$maximumScopeDays} days or less.",
            ]);
        }

        $filters = [
            'date_from' => $dateFrom->toDateTimeString(),
            'date_to' => $dateTo->toDateTimeString(),
            'event_types' => $data['event_types'] ?? [],
        ];
        $locations = $this->history->forDevice(
            $assignment->device,
            $filters,
            true,
            $retentionDays,
        );

        // Re-check after the read so a consent withdrawn during export
        // preparation cannot produce a response from stale in-memory data.
        $currentAssignment = $this->privacy->authorisedClientAssignment($client);
        abort_unless(
            $currentAssignment
                && (int) $currentAssignment->id === (int) $assignment->id
                && (int) $currentAssignment->device_id === (int) $assignment->device_id
                && (int) $currentAssignment->consent_id === (int) $assignment->consent_id,
            403,
        );

        AuditLogger::logOrFail('tracking.location_export.authorised', $client, [
            'actor_id' => $user->id,
            'assignment_id' => $assignment->id,
            'device_id' => $assignment->device_id,
            'consent_id' => $assignment->consent_id,
            'reason' => trim($data['reason']),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'event_types' => array_values($data['event_types'] ?? []),
            'row_count' => $locations->count(),
            'retention_days' => $retentionDays,
        ]);

        return response()->streamDownload(function () use ($locations): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            $this->putCsv($handle, [
                'Timestamp',
                'Latitude',
                'Longitude',
                'Display location',
                'Speed km/h',
                'Battery %',
                'Event type',
            ]);

            foreach ($locations as $location) {
                $this->putCsv($handle, [
                    $location['timestamp'] ?? '',
                    $location['lat'] ?? '',
                    $location['lng'] ?? '',
                    $location['display_location'] ?? '',
                    $location['speed'] ?? '',
                    $location['battery'] ?? '',
                    $location['event_type'] ?? '',
                ]);
            }

            fclose($handle);
        }, "client-location-{$client->id}-{$dateFrom->toDateString()}-{$dateTo->toDateString()}.csv", [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
