<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Http\Controllers\Controller;
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
use App\Services\Medication\MedicationGovernanceScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-event actions for the audit-trail drawer. The audit feed is synthesised
 * across several tables and each event carries a prefixed synthetic id
 * (admin_…, cd_…, omission_…); {@see resolveModelOrFail()} maps a backed id to
 * its canonical Site-accessible Eloquent record. Derived, missing, malformed,
 * and foreign ids are all concealed before integrity, export, or flagging.
 */
class MedicationAuditEventController extends Controller
{
    use SanitizesCsvOutput;

    public function __construct(
        private readonly MedicationAuditIntegrityService $integrity,
        private readonly MedicationGovernanceScopeService $governanceScope,
    ) {}

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

    /** Models whose optional medication link must converge with client_id. */
    private const LINKED_MEDICATION_MODELS = [
        ClientMedicationAdministration::class,
        MedicationPrescriberOrder::class,
        MedicationDestruction::class,
        ClientControlledDrugEntry::class,
        MedicationOrderVersion::class,
        MedicationError::class,
        MedicationPharmacyOrder::class,
    ];

    private const SAFE_EXPORT_FIELDS = [
        'id',
        'client_id',
        'client_medication_id',
        'name',
        'medication_name',
        'dosage',
        'frequency',
        'route',
        'is_prn',
        'active',
        'state',
        'approval_status',
        'status',
        'entry_type',
        'quantity',
        'unit',
        'on_hand_before',
        'on_hand_after',
        'dose_given',
        'reason_code',
        'review_type',
        'scheduled_date',
        'completed_date',
        'scheduled_for',
        'administered_at',
        'recorded_at',
        'destroyed_at',
        'delivered_at',
        'error_type',
        'severity',
        'version_number',
        'created_at',
        'ceased_at',
    ];

    public function integrity(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $siteIds = $this->governanceScope->readerSiteIds($user, 'medications.audit.view');
        $model = $this->resolveModelOrFail($id, $siteIds);
        $integrity = $this->integrity->forModel($model);

        return response()->json([
            'backed' => (bool) ($integrity['backed'] ?? false),
        ]);
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $siteIds = $this->governanceScope->readerSiteIds($user, 'medications.audit.view');
        $this->governanceScope->readerSiteIds($user, 'medications.reports.export');
        $model = $this->resolveModelOrFail($id, $siteIds);

        $filename = 'audit_record_'.$id.'_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($model) {
            $out = fopen('php://output', 'w');

            $this->putCsv($out, ['Record', class_basename($model).' #'.$model->getKey()]);
            $this->putCsv($out, []);
            $this->putCsv($out, ['Field', 'Value']);
            $attrs = array_intersect_key(
                $model->getAttributes(),
                array_flip(self::SAFE_EXPORT_FIELDS),
            );
            ksort($attrs);
            foreach ($attrs as $field => $value) {
                $this->putCsv($out, [$field, is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function flag(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $siteIds = $this->governanceScope->readerSiteIds($user, 'medications.audit.view');
        $this->governanceScope->readerSiteIds($user, 'medications.administer.record');
        $model = $this->resolveModelOrFail($id, $siteIds);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', 'in:near_miss,minor,moderate,major,critical'],
            'flag' => ['nullable', 'string', 'max:60'],
        ]);

        $clientId = $model instanceof Client ? $model->getKey() : ($model->client_id ?? null);
        abort_unless($clientId, 422, 'This event is not linked to a client.');

        // Flagging raises a medication-error record against the client, so the
        // flagger must be allowed to act on that client's medication record —
        // the broad route permission alone let staff flag other clients' events.
        $client = $model instanceof Client
            ? $model
            : Client::query()->whereKey($clientId)->whereIn('site_id', $siteIds)->firstOrFail();

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

        return back()->with('success', 'Flagged for investigation — error '.($error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT)).' opened.');
    }

    /** @param array<int, int> $siteIds */
    private function resolveModelOrFail(string $id, array $siteIds): Model
    {
        foreach (self::PREFIXES as $prefix => $class) {
            if (str_starts_with($id, $prefix)) {
                $suffix = substr($id, strlen($prefix));
                abort_unless($suffix !== '' && ctype_digit($suffix), 404);
                $modelId = (int) $suffix;
                abort_unless($modelId > 0, 404);

                /** @var Builder<Model> $query */
                $query = $class::query()
                    ->whereKey($modelId)
                    ->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));

                if ($class !== ClientMedication::class && in_array($class, self::LINKED_MEDICATION_MODELS, true)) {
                    $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds);
                }

                return $query->firstOrFail();
            }
        }

        abort(404);
    }
}
