<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Data\BulkCommandRequestInput;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandBatchRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchPresenter;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceCommandBatchController extends Controller
{
    public function confirmIdentity(Request $request): RedirectResponse
    {
        $workspace = $request->string('workspace')->toString();
        abort_unless(in_array($workspace, ['network-it', 'security', 'healthcare', 'facilities-iot'], true), 404);
        $intended = "/security-devices/{$workspace}?tab=management";
        // S&D rebuild C6: the Devices bulk bar asks here first, then comes back to the same ticked
        // devices and action. Only device numbers and an action key travel; the page checks the rest.
        if ($request->query('return') === 'devices') {
            $rawIds = $request->query('ids');
            $rawAction = $request->query('action');
            $ids = collect(explode(',', is_string($rawIds) ? $rawIds : ''))
                ->map(fn (string $id): string => trim($id))
                ->filter(fn (string $id): bool => ctype_digit($id) && (int) $id > 0)
                ->map(fn (string $id): int => (int) $id)
                ->unique()
                ->take(100)
                ->values();
            $action = is_string($rawAction) ? $rawAction : '';
            $query = array_filter([
                'bulk' => $ids->join(','),
                'bulk_action' => preg_match('/\A[a-z0-9_.]{1,120}\z/', $action) === 1 ? $action : null,
            ], fn (?string $value): bool => $value !== null && $value !== '');
            $intended = '/security-devices/devices'.($query === [] ? '' : '?'.http_build_query($query));
        }
        $request->session()->put('url.intended', $intended);

        return redirect()->route('password.confirm');
    }

    public function store(
        StoreDeviceCommandBatchRequest $request,
        DeviceCommandBatchService $batches,
        DeviceCommandBatchPresenter $presenter,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $batch = $batches->create($request->user(), new BulkCommandRequestInput(
            workspace: $validated['workspace'],
            deviceIds: array_map('intval', $validated['device_ids']),
            capability: $validated['capability'],
            parameters: $validated['parameters'],
            reason: $validated['reason'],
            idempotencyKey: $validated['idempotency_key'],
            stepUpConfirmedAt: is_numeric($confirmedAt)
                ? CarbonImmutable::createFromTimestampUTC((int) $confirmedAt)
                : null,
            itChangeIds: collect($validated['it_change_ids'] ?? [])
                ->mapWithKeys(fn (mixed $changeId, mixed $deviceId): array => [(int) $deviceId => (int) $changeId])
                ->all(),
            impactAcknowledged: (bool) ($validated['impact_acknowledged'] ?? false),
            confirmationText: $validated['confirmation_text'] ?? null,
        ));

        // S&D rebuild C6: the Devices bulk bar stays on its page, so it gets each device's result.
        if ($request->expectsJson()) {
            $presented = $presenter->present($request->user(), $batch);

            return response()->json([
                'id' => (int) $batch->id,
                'created' => $batch->wasRecentlyCreated,
                'summary' => $presented['summary'],
                'targets' => array_map(fn (array $target): array => [
                    'device' => ['id' => $target['device']['id'], 'name' => $target['device']['name']],
                    'included' => $target['inclusionStatus'] === 'included',
                    'status' => $target['command']['status'] ?? null,
                    'reason' => $target['safeExclusionReason'],
                ], $presented['targets']),
            ], $batch->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'no-store');
        }

        return redirect()
            ->route('security-devices.command-batches.show', $batch)
            ->with('success', $batch->wasRecentlyCreated
                ? 'Bulk command review created with one governed child request per included Device.'
                : 'The existing bulk command review was returned safely.');
    }

    public function export(
        Request $request,
        DeviceCommandBatch $batch,
        DeviceCommandBatchPresenter $presenter,
    ): StreamedResponse {
        $payload = $presenter->present($request->user(), $batch);
        $filename = 'device-command-batch-'.$batch->batch_uuid.'.csv';

        return response()->streamDownload(function () use ($payload): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Device',
                'Device UID',
                'Site',
                'Included',
                'Command status',
                'Expected state',
                'Reconciliation',
                'Safe result',
                'Safe failure or exclusion',
            ]);
            foreach ($payload['targets'] as $target) {
                fputcsv($stream, array_map($this->safeCsvCell(...), [
                    $target['device']['name'],
                    $target['device']['uid'],
                    $target['site']['name'] ?? 'Site unavailable',
                    $target['inclusionStatus'] === 'included' ? 'Yes' : 'No',
                    $target['command']['status'] ?? 'excluded',
                    $this->safeJson($target['command']['expectedState'] ?? []),
                    $target['command']['latestReconciliation']['outcome'] ?? '',
                    $this->safeJson($target['command']['latestAttempt']['safeResult'] ?? []),
                    $target['safeExclusionReason']
                        ?? $target['command']['safeFailureReason']
                        ?? $target['command']['latestAttempt']['safeFailureReason']
                        ?? '',
                ]));
            }
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeJson(array $value): string
    {
        return $value === [] ? '' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function safeCsvCell(mixed $value): string
    {
        $cell = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'{$cell}" : $cell;
    }
}
