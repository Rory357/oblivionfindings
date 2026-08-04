<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Data\BulkCommandRequestInput;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandBatchRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchPresenter;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceCommandBatchController extends Controller
{
    public function confirmIdentity(Request $request): RedirectResponse
    {
        $workspace = $request->string('workspace')->toString();
        abort_unless(in_array($workspace, ['network-it', 'security', 'healthcare', 'tracking', 'facilities-iot'], true), 404);
        $request->session()->put('url.intended', "/security-devices/{$workspace}?tab=management");

        return redirect()->route('password.confirm');
    }

    public function store(
        StoreDeviceCommandBatchRequest $request,
        DeviceCommandBatchService $batches,
    ): RedirectResponse {
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

        return redirect()
            ->route('security-devices.command-batches.show', $batch)
            ->with('success', $batch->wasRecentlyCreated
                ? 'Bulk command review created with one governed child request per included Device.'
                : 'The existing bulk command review was returned safely.');
    }

    public function show(
        Request $request,
        DeviceCommandBatch $batch,
        DeviceCommandBatchPresenter $presenter,
    ): Response {
        return Inertia::render('security-devices/command-batches/show', [
            'batch' => $presenter->present($request->user(), $batch),
        ]);
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
