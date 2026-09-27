<?php

namespace App\Domain\SecurityDevices\Services;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationReportWindow;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalTrackingLocationExportService
{
    use SanitizesCsvOutput;

    public function __construct(
        private readonly ClientLocationAccessService $access,
        private readonly ClientLocationReportWindow $windows,
        private readonly IntegrationEventHistoryService $history,
    ) {}

    public function export(Client $client, User $user, array $data): StreamedResponse
    {
        $data = Validator::make($data, [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d'],
            'event_types' => ['sometimes', 'array', 'max:20'], 'event_types.*' => ['string', 'max:100'],
        ])->validate();
        abort_unless($user->fresh()?->canDo('assets.telemetry.export'), 403);
        $assignment = $this->access->resolve($user, $client);
        $fingerprint = $this->access->fingerprint($assignment);
        ['from' => $from, 'to' => $to] = $this->windows->resolve($assignment, $data, 31);
        $watermark = $this->history->reportWatermark();
        // Private spool: release no bytes until generation and access checks finish.
        $handle = fopen('php://temp/maxmemory:2097152', 'w+b');
        abort_unless($handle !== false, 500);
        $count = 0;
        try {
            $this->putCsv($handle, ['Timestamp', 'Latitude', 'Longitude', 'Display location', 'Speed km/h', 'Battery %', 'Event type', 'Received at', 'Accuracy m', 'Source', 'Source ID']);
            $locations = $from->greaterThan($to) ? [] : $this->history->reportForDevice($assignment->device, [
                'date_from' => $from->utc()->toDateTimeString(), 'date_to' => $to->utc()->toDateTimeString(),
                'event_types' => $data['event_types'] ?? [],
            ], (int) $assignment->retention_days, $watermark);
            foreach ($locations as $location) {
                abort_if(++$count > 100000, 422, 'This export exceeds 100,000 observations. Choose a shorter period. No partial export was created.');
                if ($count % 500 === 0) {
                    $this->access->recheck($user, $client, $fingerprint);
                }
                $this->putCsv($handle, array_map(fn ($key) => $location[$key] ?? '', [
                    'timestamp', 'lat', 'lng', 'display_location', 'speed', 'battery', 'event_type', 'received_at', 'accuracy', 'source', 'source_id',
                ]));
            }
            $this->access->recheck($user, $client, $fingerprint);
            abort_unless($user->fresh()?->canDo('assets.telemetry.export'), 403);
            AuditLogger::logOrFail('tracking.location_export.authorised', $client, [
                'actor_id' => $user->id, 'assignment_id' => $assignment->id, 'device_id' => $assignment->device_id,
                'consent_id' => $assignment->consent_id, 'reason' => trim($data['reason']),
                'date_from' => $from->toISOString(), 'date_to' => $to->toISOString(), 'timezone' => 'Pacific/Auckland',
                'event_types' => $data['event_types'] ?? [], 'row_count' => $count,
                'retention_days' => $assignment->retention_days, 'watermark' => $watermark,
            ]);
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        return response()->streamDownload(function () use ($handle, $user, $client, $fingerprint): void {
            try {
                $this->access->recheck($user, $client, $fingerprint);
                abort_unless($user->fresh()?->canDo('assets.telemetry.export'), 403);
                rewind($handle);
                fpassthru($handle);
            } finally {
                fclose($handle);
            }
        }, "client-location-{$client->id}-{$data['date_from']}-{$data['date_to']}.csv", [
            ...ClientLocationAccessService::headers(), 'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Report-Row-Count' => (string) $count, 'X-Report-Coverage' => 'complete-authorised-window',
        ]);
    }
}
