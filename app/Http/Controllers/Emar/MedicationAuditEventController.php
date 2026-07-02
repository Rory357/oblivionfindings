<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReview;
use App\Services\Emar\MedicationAuditIntegrityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-event actions for the audit-trail drawer. The audit feed is synthesised
 * across several tables and each event carries a prefixed synthetic id
 * (admin_…, cd_…, omission_…); {@see resolveModel()} maps that id back to its
 * backing Eloquent record so a single event can be inspected, exported, or
 * flagged. Omission events are derived (no backing row) and resolve to null.
 */
class MedicationAuditEventController extends Controller
{
    use SanitizesCsvOutput;

    public function __construct(private readonly MedicationAuditIntegrityService $integrity) {}

    /** Synthetic id prefix → backing model. Longest/most-specific prefixes first. */
    private const PREFIXES = [
        'med_start_' => ClientMedication::class,
        'med_cease_' => ClientMedication::class,
        'stock_recv_' => MedicationPharmacyOrder::class,
        'admin_' => ClientMedicationAdministration::class,
        'review_' => MedicationReview::class,
        'order_' => MedicationPrescriberOrder::class,
        'dest_' => MedicationDestruction::class,
        'cd_' => ClientControlledDrugEntry::class,
        'ver_' => MedicationOrderVersion::class,
        'err_' => MedicationError::class,
    ];

    public function integrity(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);

        return response()->json($this->integrity->forModel($this->resolveModel($id)));
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.reports.export'), 403);

        $model = $this->resolveModel($id);
        abort_unless($model, 404, 'This event is derived from the live schedule and has no exportable record.');

        $logs = AuditLog::query()
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey())
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'action', 'user_id', 'ip_address', 'created_at', 'meta']);

        $filename = 'audit_record_'.$id.'_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($model, $logs) {
            $out = fopen('php://output', 'w');

            $this->putCsv($out, ['Record', class_basename($model).' #'.$model->getKey()]);
            $this->putCsv($out, []);
            $this->putCsv($out, ['Field', 'Value']);
            $attrs = $model->getAttributes();
            ksort($attrs);
            foreach ($attrs as $field => $value) {
                $this->putCsv($out, [$field, is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)]);
            }

            $this->putCsv($out, []);
            $this->putCsv($out, ['Change history (append-only audit log)']);
            $this->putCsv($out, ['Time', 'Action', 'By', 'IP', 'Changed fields']);
            foreach ($logs as $log) {
                $this->putCsv($out, [
                    optional($log->created_at)->toDateTimeString(),
                    $log->action,
                    $log->user?->name ?? '',
                    $log->ip_address ?? '',
                    implode(', ', (array) ($log->meta['fields'] ?? [])),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function flag(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->canDo('medications.administer.record') || $user->canDo('clients.update')),
            403,
        );

        $model = $this->resolveModel($id);
        abort_unless($model, 422, 'This event cannot be flagged — it has no backing record.');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', 'in:near_miss,minor,moderate,major,critical'],
            'flag' => ['nullable', 'string', 'max:60'],
        ]);

        $clientId = $model instanceof Client ? $model->getKey() : ($model->client_id ?? null);
        abort_unless($clientId, 422, 'This event is not linked to a client.');

        // Map the audit gap to a medication-error type where we can be specific.
        $errorType = match ($validated['flag'] ?? null) {
            'omission' => 'omission',
            'missing_witness', 'no_reason', 'no_actor' => 'documentation',
            default => 'documentation',
        };

        $description = trim(
            'Flagged for investigation from the medication audit trail (event '.$id.').'
            .($validated['note'] ?? '' ? "\n\n".$validated['note'] : '')
        );

        $error = MedicationError::create([
            'client_id' => $clientId,
            'client_medication_id' => $model instanceof ClientMedication
                ? $model->getKey()
                : ($model->client_medication_id ?? null),
            'error_type' => $errorType,
            'severity' => $validated['severity'] ?? 'minor',
            'description' => $description,
            'reported_by' => $user->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);

        return back()->with('success', 'Flagged for investigation — error ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT).' opened.');
    }

    private function resolveModel(string $id): ?Model
    {
        foreach (self::PREFIXES as $prefix => $class) {
            if (str_starts_with($id, $prefix)) {
                $modelId = (int) substr($id, strlen($prefix));

                return $modelId > 0 ? $class::find($modelId) : null;
            }
        }

        // omission_… and any unknown id are synthetic — no backing record.
        return null;
    }
}
