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
use App\Models\User;
use App\Services\Emar\MedicationAuditIntegrityService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\Reporting\MedicationExportAudit;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        private readonly MedicationScopeDecisionService $medicationScope,
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
        $model = $this->resolveModelOrFail(
            $id,
            $siteIds,
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
        $integrity = $this->integrity->forModel($model);

        return response()->json([
            'backed' => (bool) ($integrity['backed'] ?? false),
        ]);
    }

    public function export(Request $request, string $id): Response
    {
        $actor = $request->user();
        abort_unless($actor && app(MedicationReportAccess::class)->canExport($actor, 'audit'), 403);
        $request->validate(['client_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1']]);
        $audit = app(MedicationExportAudit::class);
        $purpose = $audit->purpose($request);
        if ($request->filled('date')) {
            $request->merge(['period' => 'custom', 'date_from' => $request->input('date'), 'date_to' => $request->input('date')]);
        } elseif ($request->filled('from') || $request->filled('to')) {
            $request->merge(['period' => 'custom', 'date_from' => $request->input('from'), 'date_to' => $request->input('to')]);
        }
        $period = MedicationReportPeriod::fromRequest($request);
        $evidence = $this->exportEvidence($actor, $id, $request->integer('site_id') ?: null, $request->integer('client_id') ?: null);
        $siteId = $evidence['site_id'];
        $clientId = $evidence['client_id'];
        $digest = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
        $out = fopen('php://temp', 'w+b');
        $this->putCsv($out, ['Record', $evidence['record']]);
        $this->putCsv($out, []);
        $this->putCsv($out, ['Field', 'Value']);
        foreach ($evidence['attributes'] as $field => $value) {
            $this->putCsv($out, [$field, is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)]);
        }
        rewind($out);
        $bytes = stream_get_contents($out);
        fclose($out);

        // The selected record supplies release scope. Query filters cannot
        // substitute another person's evidence for the buffered source.
        $audit->record($actor, 'audit', [$siteId], $period, $purpose, $clientId,
            ['legacy_route' => $request->route()->getName()],
            function (User $current) use ($id, $siteId, $clientId, $digest): void {
                $fresh = $this->exportEvidence($current, $id, $siteId, $clientId);
                abort_unless(hash_equals($digest, hash('sha256', json_encode($fresh, JSON_THROW_ON_ERROR))), 409,
                    'The record or your access changed while the file was being prepared. Refresh and try again.');
            });

        return response($bytes, 200, [
            'Content-Type' => 'text/csv', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="audit_record_'.$id.'_'.now()->format('Y-m-d_His').'.csv"',
        ]);
    }

    private function exportEvidence(User $actor, string $id, ?int $siteId, ?int $clientId): array
    {
        $this->governanceScope->readerSiteIds($actor, 'medications.audit.view');
        $this->governanceScope->readerSiteIds($actor, 'medications.audit.export');
        $sites = app(MedicationReportAccess::class)->siteIds($actor, $siteId, $clientId);
        $model = $this->resolveModelOrFail($id, $sites, $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY));
        $actualClientId = $this->clientIdForModel($model);
        abort_unless($clientId === null || $clientId === $actualClientId, 404);
        $person = Client::query()->whereIn('site_id', $sites)->find($actualClientId);
        app(MedicationRecordAccess::class)->assertReportable($actor, $person);
        $attributes = array_intersect_key($model->getAttributes(), array_flip(self::SAFE_EXPORT_FIELDS));
        ksort($attributes);

        return ['site_id' => (int) $person->site_id, 'client_id' => $actualClientId,
            'record' => class_basename($model).' #'.$model->getKey(), 'attributes' => $attributes];
    }

    public function flag(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $siteIds = $this->governanceScope->readerSiteIds($user, 'medications.audit.view');
        $this->governanceScope->readerSiteIds($user, 'medications.administer.record');
        $snapshot = $this->resolveModelOrFail(
            $id,
            $siteIds,
            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
        $clientId = $this->clientIdForModel($snapshot);
        $snapshotMedicationId = $this->medicationIdForModel($snapshot);
        $canViewControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);

        return $this->withAssignedClient(
            $user,
            $clientId,
            function (MedicationScopeDecision $scope) use (
                $request,
                $id,
                $user,
                $snapshotMedicationId,
                $canViewControlled,
            ) {
                $medication = null;
                if ($snapshotMedicationId !== null) {
                    $medication = ClientMedication::withTrashed()
                        ->whereKey($snapshotMedicationId)
                        ->where('client_id', $scope->client->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $model = $this->resolveModelOrFail(
                    $id,
                    [$scope->siteId],
                    $canViewControlled,
                    true,
                );
                abort_unless($this->clientIdForModel($model) === (int) $scope->client->id, 404);
                abort_unless($this->medicationIdForModel($model) === $snapshotMedicationId, 404);

                $validated = $request->validate([
                    'note' => ['nullable', 'string', 'max:2000'],
                    'severity' => ['nullable', 'in:near_miss,minor,moderate,major,critical'],
                    'flag' => ['nullable', 'string', 'max:60'],
                ]);

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
                    'client_id' => $scope->client->id,
                    'client_medication_id' => $medication?->id,
                    'error_type' => $errorType,
                    'severity' => $validated['severity'] ?? 'minor',
                    'description' => $description,
                    'reported_by' => $user->id,
                    'reported_at' => now(),
                    'status' => 'reported',
                ]);
                app(MedicationAlertSources::class)->error($error);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'flagged_medication_audit_event',
                    'Event '.$id,
                );

                return back()->with('success', 'Flagged for investigation — error '.($error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT)).' opened.');
            },
        );
    }

    /** @param array<int, int> $siteIds */
    private function resolveModelOrFail(
        string $id,
        array $siteIds,
        bool $canViewControlled,
        bool $lockForUpdate = false,
    ): Model {
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

                if (! $canViewControlled) {
                    abort_if($class === ClientControlledDrugEntry::class, 404);

                    if ($class === ClientMedication::class) {
                        $query->where(function (Builder $classification): void {
                            $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                        });
                    }

                    if ($class === MedicationDestruction::class) {
                        $query->where(function (Builder $classification): void {
                            $classification->where('is_controlled_drug', false)->orWhereNull('is_controlled_drug');
                        });
                    }

                    if ($class === MedicationOrderVersion::class) {
                        $query->where(function (Builder $classification): void {
                            $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                        });
                    }

                    if ($class === MedicationPrescriberOrder::class) {
                        $query->visibleToOrdinaryReader();
                    } elseif (in_array($class, self::LINKED_MEDICATION_MODELS, true)) {
                        $this->governanceScope->scopeWithoutControlledMedicationRows($query);
                    }
                }

                if ($class !== ClientMedication::class && in_array($class, self::LINKED_MEDICATION_MODELS, true)) {
                    $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds);
                }

                if ($class === MedicationDestruction::class) {
                    $table = $query->getModel()->getTable();
                    $query->where(function (Builder $row) use ($table): void {
                        $row->whereNull($table.'.site_id')
                            ->orWhereHas('client', fn (Builder $client) => $client->whereColumn(
                                'clients.site_id',
                                $table.'.site_id',
                            ));
                    });
                }

                if ($lockForUpdate) {
                    $query->lockForUpdate();
                }

                return $query->firstOrFail();
            }
        }

        abort(404);
    }

    private function clientIdForModel(Model $model): int
    {
        $clientId = $model instanceof Client ? $model->getKey() : $model->getAttribute('client_id');
        abort_unless(is_numeric($clientId) && (int) $clientId > 0, 404);

        return (int) $clientId;
    }

    private function medicationIdForModel(Model $model): ?int
    {
        $medicationId = $model instanceof ClientMedication
            ? $model->getKey()
            : $model->getAttribute('client_medication_id');
        if ($medicationId === null) {
            return null;
        }
        abort_unless(is_numeric($medicationId) && (int) $medicationId > 0, 404);

        return (int) $medicationId;
    }

    private function withAssignedClient(User $user, int $clientId, \Closure $callback): mixed
    {
        $scopeEntered = false;

        try {
            return $this->medicationScope->forClient(
                $user,
                $clientId,
                now(),
                function (MedicationScopeDecision $scope) use ($callback, &$scopeEntered) {
                    $scopeEntered = true;

                    return $callback($scope);
                },
            );
        } catch (HttpExceptionInterface $exception) {
            if (! $scopeEntered && $exception->getStatusCode() === 403) {
                abort(404, 'The requested medication action is not available.');
            }

            throw $exception;
        }
    }
}
