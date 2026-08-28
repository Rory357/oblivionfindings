<?php

namespace App\Http\Controllers\Emar;

use App\Enums\Medication\NotGivenReason;
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
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\MarOmissionService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(
        private MedicationGovernanceScopeService $governanceScope,
    ) {}

    public function index(Request $request, MarOmissionService $omissions)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $canViewControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);

        $clientId = $request->integer('client_id') ?: null;
        $siteFilter = $request->integer('site_id') ?: null;
        $accessibleSiteIds = $this->governanceScope->readerSiteIds(
            $user,
            'medications.audit.view',
            $siteFilter,
            $clientId,
        );
        $readerSiteIds = $siteFilter !== null ? [$siteFilter] : $accessibleSiteIds;
        $allowedClientIds = Client::query()
            ->whereIn('site_id', $readerSiteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $eventTypes = $request->query('event_types', []);
        if (is_string($eventTypes)) {
            $eventTypes = array_filter(explode(',', $eventTypes));
        }

        $events = collect();

        // 1. ClientMedication — started / ceased
        if (empty($eventTypes) || array_intersect(['medication_started', 'medication_ceased'], $eventTypes)) {
            $medQuery = ClientMedication::query()
                ->whereIn('client_id', $allowedClientIds)
                ->with(['client:id,first_name,last_name', 'createdByUser:id,name', 'ceasedByUser:id,name'])
                ->select('id', 'client_id', 'created_by', 'name', 'dosage', 'created_at', 'ceased_at', 'ceased_reason', 'ceased_by');

            if (! $canViewControlled) {
                $medQuery->where(function (Builder $classification): void {
                    $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                });
            }

            if ($clientId) {
                $medQuery->where('client_id', $clientId);
            }

            $meds = $medQuery->get();

            foreach ($meds as $med) {
                $clientName = $med->client ? trim($med->client->first_name.' '.$med->client->last_name) : 'Unknown';

                if (empty($eventTypes) || in_array('medication_started', $eventTypes)) {
                    $ts = $med->created_at;
                    if ($this->withinDateRange($ts, $dateFrom, $dateTo)) {
                        $events->push([
                            'id' => 'med_start_'.$med->id,
                            'event_type' => 'medication_started',
                            'timestamp' => $ts->toIso8601String(),
                            'description' => "{$med->name} {$med->dosage} started for {$clientName}",
                            'performed_by' => $med->createdByUser->name ?? null,
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'details' => [
                                'medication' => $med->name,
                                'dosage' => $med->dosage,
                            ],
                        ]);
                    }
                }

                if ($med->ceased_at && (empty($eventTypes) || in_array('medication_ceased', $eventTypes))) {
                    $ts = $med->ceased_at instanceof Carbon ? $med->ceased_at : Carbon::parse($med->ceased_at);
                    if ($this->withinDateRange($ts, $dateFrom, $dateTo)) {
                        $events->push([
                            'id' => 'med_cease_'.$med->id,
                            'event_type' => 'medication_ceased',
                            'timestamp' => $ts->toIso8601String(),
                            'description' => "{$med->name} ceased for {$clientName}",
                            'performed_by' => $med->ceasedByUser->name ?? null,
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'details' => [
                                'medication' => $med->name,
                                'reason' => $med->ceased_reason,
                            ],
                        ]);
                    }
                }
            }
        }

        // 2. ClientMedicationAdministration — immutable raw clinical and
        // correction evidence. Corrections are deliberately not collapsed into
        // effectiveClinicalEvidence(): pending/rejected rows and the original
        // record remain part of the audit history, but must never be described
        // as a dose administration merely because their proposed status is given.
        $adminTypes = [
            'dose_administered',
            'dose_refused',
            'dose_missed',
            'dose_withheld',
            'dose_pending',
            'dose_recorded',
            'correction_submitted',
            'correction_approved',
            'correction_rejected',
            'correction_recorded',
        ];
        if (empty($eventTypes) || array_intersect($adminTypes, $eventTypes)) {
            $adminQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientMedicationAdministration::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
                false,
            )
                ->with(['client:id,first_name,last_name', 'medication:id,name,dosage,deleted_at', 'administeredBy:id,name', 'correctionRequestedBy:id,name', 'witnessedBy:id,name'])
                ->select(
                    'id',
                    'client_id',
                    'client_medication_id',
                    'administered_by',
                    'correction_requested_by',
                    'witnessed_by',
                    'scheduled_for',
                    'administered_at',
                    'status',
                    'reason',
                    'reason_code',
                    'dose_given',
                    'notes',
                    'corrected_of_id',
                    'is_correction',
                    'correction_reason',
                    'correction_status',
                    'correction_approved_by',
                    'correction_approved_at',
                    'correction_rejection_reason',
                    'created_at',
                    'updated_at',
                );
            if (! $canViewControlled) {
                $this->governanceScope->scopeWithoutControlledMedicationRows($adminQuery);
            }

            if ($clientId) {
                $adminQuery->where('client_id', $clientId);
            }
            $adminTable = $adminQuery->getModel()->getTable();
            $adminEventTimestampSql = "CASE WHEN {$adminTable}.is_correction = 1 "
                ."THEN COALESCE({$adminTable}.correction_approved_at, {$adminTable}.created_at) "
                ."ELSE COALESCE({$adminTable}.administered_at, {$adminTable}.scheduled_for, {$adminTable}.created_at) END";
            if ($dateFrom) {
                $adminQuery->whereRaw(
                    "({$adminEventTimestampSql}) >= ?",
                    [Carbon::parse($dateFrom)->startOfDay()],
                );
            }
            if ($dateTo) {
                $adminQuery->whereRaw(
                    "({$adminEventTimestampSql}) <= ?",
                    [Carbon::parse($dateTo)->endOfDay()],
                );
            }

            $admins = $adminQuery->get();
            $correctionDecisionMakerIds = $admins
                ->pluck('correction_approved_by')
                ->filter()
                ->unique()
                ->values();
            $correctionDecisionMakers = $correctionDecisionMakerIds->isEmpty()
                ? collect()
                : User::query()->whereIn('id', $correctionDecisionMakerIds)->pluck('name', 'id');

            foreach ($admins as $admin) {
                $isCorrection = (bool) $admin->is_correction;
                $eventType = $isCorrection
                    ? match ($admin->correction_status) {
                        'pending' => 'correction_submitted',
                        'approved' => 'correction_approved',
                        'rejected' => 'correction_rejected',
                        default => 'correction_recorded',
                    }
                : match ($admin->status) {
                    'given' => 'dose_administered',
                    'refused' => 'dose_refused',
                    'missed' => 'dose_missed',
                    'withheld' => 'dose_withheld',
                    'pending' => 'dose_pending',
                    default => 'dose_recorded',
                };

                if (! empty($eventTypes) && ! in_array($eventType, $eventTypes)) {
                    continue;
                }

                $clientName = $admin->client ? trim($admin->client->first_name.' '.$admin->client->last_name) : 'Unknown';
                $medName = $admin->medication?->historicalDisplayName() ?? 'Unknown medication';
                $statusLabel = str_replace('_', ' ', (string) ($admin->status ?: 'unknown'));
                $submittedBy = $admin->correctionRequestedBy?->name
                    ?? $admin->administeredBy?->name;
                $decisionMaker = $admin->correction_approved_by
                    ? $correctionDecisionMakers->get($admin->correction_approved_by)
                    : null;
                $performedBy = $isCorrection && in_array($admin->correction_status, ['approved', 'rejected'], true)
                    ? ($decisionMaker ?? $submittedBy)
                    : $submittedBy;

                $descMap = [
                    'dose_administered' => "{$medName} {$admin->dose_given} administered to {$clientName}",
                    'dose_refused' => "{$medName} refused by {$clientName}",
                    'dose_missed' => "{$medName} dose missed for {$clientName}",
                    'dose_withheld' => "{$medName} dose withheld for {$clientName}",
                    'dose_pending' => "{$medName} dose remains pending for {$clientName}",
                    'dose_recorded' => "{$medName} dose outcome recorded as {$statusLabel} for {$clientName}",
                    'correction_submitted' => "Correction for {$medName} submitted for approval for {$clientName} (proposed outcome: {$statusLabel})",
                    'correction_approved' => "Correction for {$medName} approved for {$clientName} (corrected outcome: {$statusLabel})",
                    'correction_rejected' => "Correction for {$medName} rejected for {$clientName} (proposed outcome: {$statusLabel})",
                    'correction_recorded' => "Correction for {$medName} recorded for {$clientName} (proposed outcome: {$statusLabel})",
                ];

                $reasonLabel = $admin->reason_code
                    ? (NotGivenReason::tryFrom($admin->reason_code)?->label() ?? $admin->reason_code)
                    : null;
                $timestamp = $isCorrection
                    ? ($admin->correction_approved_at ?? $admin->created_at ?? $admin->updated_at ?? now())
                    : ($admin->administered_at ?? $admin->scheduled_for ?? $admin->created_at ?? now());

                $events->push([
                    'id' => 'admin_'.$admin->id,
                    'event_type' => $eventType,
                    'timestamp' => $timestamp->toIso8601String(),
                    'description' => $descMap[$eventType],
                    'performed_by' => $performedBy,
                    'witness' => $admin->witnessedBy->name ?? null,
                    'client_id' => $admin->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'dose' => $admin->dose_given,
                        'reason_code' => $reasonLabel,
                        'reason' => $admin->reason,
                        'notes' => $admin->notes,
                        'status' => $admin->status,
                        'is_correction' => $isCorrection,
                        'correction_status' => $admin->correction_status,
                        'correction_reason' => $admin->correction_reason,
                        'submitted_by' => $isCorrection ? $submittedBy : null,
                        'corrected_record_id' => $admin->corrected_of_id,
                        'correction_rejection_reason' => $admin->correction_rejection_reason,
                    ],
                ]);
            }
        }

        // 3. MedicationPrescriberOrder — prescriber_order
        if (empty($eventTypes) || in_array('prescriber_order', $eventTypes)) {
            $orderQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationPrescriberOrder::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
            )
                ->with(['client:id,first_name,last_name'])
                ->select('id', 'client_id', 'medication_name', 'dose', 'prescriber_name', 'order_type', 'status', 'created_at');
            if (! $canViewControlled) {
                $orderQuery->visibleToOrdinaryReader();
            }

            if ($clientId) {
                $orderQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $orderQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $orderQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($orderQuery->get() as $order) {
                $clientName = $order->client ? trim($order->client->first_name.' '.$order->client->last_name) : 'Unknown';

                $events->push([
                    'id' => 'order_'.$order->id,
                    'event_type' => 'prescriber_order',
                    'timestamp' => $order->created_at->toIso8601String(),
                    'description' => "Prescriber order ({$order->order_type}) for {$order->medication_name} — {$clientName}",
                    'performed_by' => $order->prescriber_name,
                    'client_id' => $order->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $order->medication_name,
                        'dose' => $order->dose,
                        'order_type' => $order->order_type,
                        'status' => $order->status,
                    ],
                ]);
            }
        }

        // 4. MedicationReview — review_completed
        if (empty($eventTypes) || in_array('review_completed', $eventTypes)) {
            $reviewQuery = MedicationReview::query()
                ->whereIn('client_id', $allowedClientIds)
                ->with(['client:id,first_name,last_name', 'reviewer:id,name'])
                ->whereNotNull('completed_date')
                ->select('id', 'client_id', 'review_type', 'completed_date', 'reviewer_name', 'reviewer_user_id', 'clinical_summary');

            if ($clientId) {
                $reviewQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $reviewQuery->where('completed_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $reviewQuery->where('completed_date', '<=', $dateTo);
            }

            foreach ($reviewQuery->get() as $review) {
                $clientName = $review->client ? trim($review->client->first_name.' '.$review->client->last_name) : 'Unknown';
                $reviewerName = $review->reviewer->name ?? $review->reviewer_name ?? null;

                $events->push([
                    'id' => 'review_'.$review->id,
                    'event_type' => 'review_completed',
                    'timestamp' => Carbon::parse($review->completed_date)->toIso8601String(),
                    'description' => "Medication review ({$review->review_type}) completed for {$clientName}",
                    'performed_by' => $reviewerName,
                    'client_id' => $review->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'review_type' => $review->review_type,
                        'summary' => $review->clinical_summary,
                    ],
                ]);
            }
        }

        // 5. MedicationDestruction — destruction
        if (empty($eventTypes) || in_array('destruction', $eventTypes)) {
            $destructionQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationDestruction::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
            )
                ->with(['client:id,first_name,last_name', 'destroyedByUser:id,name'])
                ->select('id', 'client_id', 'medication_name', 'quantity', 'unit', 'reason', 'disposal_method', 'destroyed_by', 'destroyed_at');
            $destructionTable = $destructionQuery->getModel()->getTable();
            $destructionQuery->where(function (Builder $row) use ($destructionTable): void {
                $row->whereNull($destructionTable.'.site_id')
                    ->orWhereHas('client', fn (Builder $client) => $client->whereColumn(
                        'clients.site_id',
                        $destructionTable.'.site_id',
                    ));
            });
            if (! $canViewControlled) {
                $destructionQuery->where(function (Builder $classification): void {
                    $classification->where('is_controlled_drug', false)->orWhereNull('is_controlled_drug');
                });
                $this->governanceScope->scopeWithoutControlledMedicationRows($destructionQuery);
            }

            if ($clientId) {
                $destructionQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $destructionQuery->where('destroyed_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $destructionQuery->where('destroyed_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($destructionQuery->get() as $dest) {
                $clientName = $dest->client ? trim($dest->client->first_name.' '.$dest->client->last_name) : 'Unknown';
                $performedBy = $dest->destroyedByUser->name ?? null;

                $events->push([
                    'id' => 'dest_'.$dest->id,
                    'event_type' => 'destruction',
                    'timestamp' => $dest->destroyed_at?->toIso8601String() ?? $dest->created_at->toIso8601String(),
                    'description' => "{$dest->medication_name} ({$dest->quantity} {$dest->unit}) destroyed for {$clientName}",
                    'performed_by' => $performedBy,
                    'client_id' => $dest->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $dest->medication_name,
                        'quantity' => $dest->quantity,
                        'unit' => $dest->unit,
                        'reason' => $dest->reason,
                        'method' => $dest->disposal_method,
                    ],
                ]);
            }
        }

        // 6. MedicationOrderVersion — medication_changed
        if (empty($eventTypes) || in_array('medication_changed', $eventTypes)) {
            $versionQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationOrderVersion::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
                false,
            )
                ->with(['client:id,first_name,last_name', 'changedBy:id,name'])
                ->where('version_number', '>', 1)
                ->select('id', 'client_id', 'client_medication_id', 'version_number', 'name', 'dosage', 'frequency', 'route', 'instructions', 'is_prn', 'dose_times', 'change_reason', 'changed_by', 'created_at');
            if (! $canViewControlled) {
                $versionQuery->where(function (Builder $classification): void {
                    $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                });
                $this->governanceScope->scopeWithoutControlledMedicationRows($versionQuery);
            }

            if ($clientId) {
                $versionQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $versionQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $versionQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            $versions = $versionQuery->get();

            // Load every version of the affected medications so each change can be
            // diffed against its immediate predecessor (vN vs vN-1) — the data for
            // a real before→after already lives in the full per-version snapshots.
            $medIds = $versions->pluck('client_medication_id')->filter()->unique()->values();
            $byMedVersion = $medIds->isEmpty()
                ? collect()
                : $this->governanceScope->scopeCanonicalClientMedicationRows(
                    MedicationOrderVersion::query()
                        ->whereIn('client_medication_id', $medIds)
                        ->whereIn('client_id', $allowedClientIds),
                    $readerSiteIds,
                    false,
                )
                    ->when(! $canViewControlled, function (Builder $query): void {
                        $query->where(function (Builder $classification): void {
                            $classification->where('controlled_drug', false)->orWhereNull('controlled_drug');
                        });
                        $this->governanceScope->scopeWithoutControlledMedicationRows($query);
                    })
                    ->get(['id', 'client_medication_id', 'version_number', 'name', 'dosage', 'frequency', 'route', 'instructions', 'is_prn', 'dose_times'])
                    ->groupBy('client_medication_id')
                    ->map(fn ($group) => $group->keyBy('version_number'));

            foreach ($versions as $ver) {
                $clientName = $ver->client ? trim($ver->client->first_name.' '.$ver->client->last_name) : 'Unknown';
                $performedBy = $ver->changedBy->name ?? null;

                $previous = $byMedVersion[$ver->client_medication_id][$ver->version_number - 1] ?? null;
                $changes = $previous ? $this->diffVersions($previous, $ver) : [];

                $events->push([
                    'id' => 'ver_'.$ver->id,
                    'event_type' => 'medication_changed',
                    'timestamp' => $ver->created_at->toIso8601String(),
                    'description' => "{$ver->name} {$ver->dosage} changed (v{$ver->version_number}) for {$clientName}",
                    'performed_by' => $performedBy,
                    'client_id' => $ver->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $ver->name,
                        'dosage' => $ver->dosage,
                        'version' => $ver->version_number,
                        'reason' => $ver->change_reason,
                        'changes' => $changes,
                    ],
                ]);
            }
        }

        // 7. ClientControlledDrugEntry — controlled-drug movements (with witness)
        if ($canViewControlled
            && (empty($eventTypes) || array_intersect(['cd_given', 'cd_received', 'cd_wasted', 'cd_balance_check', 'cd_adjustment'], $eventTypes))) {
            $cdQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientControlledDrugEntry::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
                false,
            )
                ->with(['client:id,first_name,last_name', 'medication:id,name', 'recordedBy:id,name', 'witnessedBy:id,name']);

            if ($clientId) {
                $cdQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $cdQuery->where('recorded_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $cdQuery->where('recorded_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            // The CD register uses two naming conventions across its write paths
            // (storeCDEntry vs the MAR administration flow). Map every real
            // entry_type to a distinct audit event so receipts, disposals, counts
            // and adjustments are no longer mislabelled "given".
            $cdEventMap = [
                'administered' => 'cd_given',
                'administration' => 'cd_given',
                'receipt' => 'cd_received',
                'received' => 'cd_received',
                'transfer_in' => 'cd_received',
                'disposal' => 'cd_wasted',
                'wasted' => 'cd_wasted',
                'transfer_out' => 'cd_wasted',
                'balance_check' => 'cd_balance_check',
                'stock_count' => 'cd_balance_check',
                'adjustment' => 'cd_adjustment',
            ];

            foreach ($cdQuery->get() as $entry) {
                $eventType = $cdEventMap[$entry->entry_type] ?? 'cd_given';
                if (! empty($eventTypes) && ! in_array($eventType, $eventTypes)) {
                    continue;
                }

                $clientName = $entry->client ? trim($entry->client->first_name.' '.$entry->client->last_name) : 'Unknown';
                $medName = $entry->medication->name ?? 'Controlled drug';
                $qty = trim(($entry->quantity ?? '').' '.($entry->unit ?? ''));

                // Two-person sign-off is the NICE SC1 / CD-policy requirement for
                // administration and disposal of a controlled drug. Receipts,
                // counts and adjustments are not flagged for a missing witness.
                $witnessRequired = in_array($eventType, ['cd_given', 'cd_wasted'], true);

                $descMap = [
                    'cd_given' => "Controlled drug {$medName} ({$qty}) administered — {$clientName}",
                    'cd_received' => "Controlled drug {$medName} ({$qty}) received into stock — {$clientName}",
                    'cd_wasted' => "Controlled drug {$medName} ({$qty}) disposed / wasted — {$clientName}",
                    'cd_balance_check' => "CD balance check — {$medName} for {$clientName}",
                    'cd_adjustment' => "CD stock adjustment — {$medName} ({$qty}) for {$clientName}",
                ];

                $events->push([
                    'id' => 'cd_'.$entry->id,
                    'event_type' => $eventType,
                    'timestamp' => ($entry->recorded_at ?? $entry->created_at ?? now())->toIso8601String(),
                    'description' => $descMap[$eventType] ?? "Controlled drug {$medName} ({$qty}) — {$clientName}",
                    'performed_by' => $entry->recordedBy->name ?? null,
                    'witness' => $entry->witnessedBy->name ?? null,
                    'witness_required' => $witnessRequired,
                    'client_id' => $entry->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'entry_type' => str_replace('_', ' ', (string) $entry->entry_type),
                        'quantity' => $entry->quantity,
                        'unit' => $entry->unit,
                        'balance_before' => $entry->on_hand_before,
                        'balance_after' => $entry->on_hand_after,
                        'reason' => $entry->reason,
                    ],
                ]);
            }
        }

        // 8. MedicationError — medication_error
        if (empty($eventTypes) || in_array('medication_error', $eventTypes)) {
            $errorQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationError::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
            )
                ->with(['client:id,first_name,last_name', 'reportedBy:id,name']);
            if (! $canViewControlled) {
                $this->governanceScope->scopeWithoutControlledMedicationRows($errorQuery);
            }

            if ($clientId) {
                $errorQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $errorQuery->where('reported_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $errorQuery->where('reported_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($errorQuery->get() as $error) {
                $clientName = $error->client ? trim($error->client->first_name.' '.$error->client->last_name) : 'Unknown';

                $events->push([
                    'id' => 'err_'.$error->id,
                    'event_type' => 'medication_error',
                    'timestamp' => ($error->reported_at ?? $error->created_at ?? now())->toIso8601String(),
                    'description' => 'Medication error ('.str_replace('_', ' ', (string) $error->error_type).") reported — {$clientName}",
                    'performed_by' => $error->reportedBy->name ?? null,
                    'client_id' => $error->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'error_type' => $error->error_type,
                        'severity' => $error->severity,
                        'status' => $error->status,
                    ],
                    'outcome' => $error->severity,
                ]);
            }
        }

        // 9. MedicationPharmacyOrder — stock received (real delivery receipts only;
        //    non-CD ad-hoc stock has no movement log, so nothing is invented here).
        if (empty($eventTypes) || in_array('stock_received', $eventTypes)) {
            $stockQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationPharmacyOrder::query()->whereIn('client_id', $allowedClientIds),
                $readerSiteIds,
                false,
            )
                ->with(['client:id,first_name,last_name', 'medication:id,name', 'receivedByUser:id,name'])
                ->whereNotNull('delivered_at');
            if (! $canViewControlled) {
                $this->governanceScope->scopeWithoutControlledMedicationRows($stockQuery);
            }

            if ($clientId) {
                $stockQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $stockQuery->where('delivered_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $stockQuery->where('delivered_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($stockQuery->get() as $order) {
                $clientName = $order->client ? trim($order->client->first_name.' '.$order->client->last_name) : 'Unknown';
                $medName = $order->medication->name ?? 'Medication';

                $events->push([
                    'id' => 'stock_recv_'.$order->id,
                    'event_type' => 'stock_received',
                    'timestamp' => $order->delivered_at->toIso8601String(),
                    'description' => "{$medName} delivery received ({$order->quantity_received} units) — {$clientName}",
                    'performed_by' => $order->receivedByUser->name ?? null,
                    'client_id' => $order->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'pharmacy' => $order->pharmacy_name,
                        'quantity_received' => $order->quantity_received,
                        'batch_number' => $order->batch_number,
                    ],
                ]);
            }
        }

        // 10. Omissions — scheduled doses never recorded (real "blank MAR slot"
        //     detection over a bounded recent window; reuses MarScheduleService).
        if (empty($eventTypes) || in_array('omission', $eventTypes)) {
            $omissionClientIds = $clientId !== null ? [$clientId] : $allowedClientIds;
            foreach ($omissionClientIds as $omissionClientId) {
                foreach ($omissions->omissionsForRange(
                    $dateFrom ? Carbon::parse($dateFrom) : null,
                    $dateTo ? Carbon::parse($dateTo) : null,
                    $omissionClientId,
                    $canViewControlled,
                ) as $omission) {
                    $events->push($omission);
                }
            }
        }

        // ── Enrich every event with category / source / site / flags so the
        //    redesigned page can facet, surface compliance gaps and render the
        //    read-only detail drawer. (See docs/emar-redesign/audit-plan.md.)
        $clientSite = Client::query()->whereIn('id', $allowedClientIds)->pluck('site_id', 'id');
        $siteNames = Site::query()->whereIn('id', $readerSiteIds)->pluck('name', 'id');
        $categoryOf = [
            'dose_administered' => 'doses', 'dose_refused' => 'doses', 'dose_missed' => 'doses',
            'dose_withheld' => 'doses', 'dose_pending' => 'doses', 'dose_recorded' => 'doses',
            'correction_submitted' => 'doses', 'correction_approved' => 'doses',
            'correction_rejected' => 'doses', 'correction_recorded' => 'doses', 'omission' => 'doses',
            'cd_given' => 'controlled', 'cd_received' => 'controlled', 'cd_wasted' => 'controlled',
            'cd_balance_check' => 'controlled', 'cd_adjustment' => 'controlled',
            'medication_started' => 'clinical', 'medication_ceased' => 'clinical', 'medication_changed' => 'clinical',
            'prescriber_order' => 'clinical', 'review_completed' => 'clinical',
            'destruction' => 'stock', 'stock_received' => 'stock', 'medication_error' => 'errors',
        ];
        $sourceOf = [
            'dose_administered' => 'MAR', 'dose_refused' => 'MAR', 'dose_missed' => 'MAR',
            'dose_withheld' => 'MAR', 'dose_pending' => 'MAR', 'dose_recorded' => 'MAR',
            'correction_submitted' => 'MAR', 'correction_approved' => 'MAR',
            'correction_rejected' => 'MAR', 'correction_recorded' => 'MAR', 'omission' => 'MAR',
            'cd_given' => 'CD', 'cd_received' => 'CD', 'cd_wasted' => 'CD', 'cd_balance_check' => 'CD', 'cd_adjustment' => 'CD',
            'medication_started' => 'MAR', 'medication_ceased' => 'MAR', 'medication_changed' => 'MAR',
            'prescriber_order' => 'Orders', 'review_completed' => 'Clinical',
            'destruction' => 'Stock', 'stock_received' => 'Stock', 'medication_error' => 'Errors',
        ];

        $events = $events->map(function (array $e) use ($categoryOf, $sourceOf, $clientSite, $siteNames) {
            $type = $e['event_type'];
            $siteId = $e['client_id'] ? ($clientSite[$e['client_id']] ?? null) : null;

            $flags = [];
            if (($e['witness_required'] ?? false) && empty($e['witness'])) {
                $flags[] = 'missing_witness';
            }
            if (in_array($type, ['dose_missed', 'omission'], true)) {
                $flags[] = 'omission';
            }
            if (in_array($type, ['medication_started', 'medication_ceased'], true) && empty($e['performed_by'])) {
                $flags[] = 'no_actor';
            }
            // A refusal/omission must carry a coded reason (NotGivenReason) — only
            // when neither the code nor a free-text reason is present is it a gap.
            if (in_array($type, ['dose_refused', 'dose_missed'], true)
                && empty($e['details']['reason_code'] ?? null)
                && empty($e['details']['reason'] ?? null)) {
                $flags[] = 'no_reason';
            }

            return array_merge($e, [
                'category' => $categoryOf[$type] ?? 'clinical',
                'source' => $sourceOf[$type] ?? 'MAR',
                'site_id' => $siteId,
                'site_name' => $siteId ? ($siteNames[$siteId] ?? null) : null,
                'witness' => $e['witness'] ?? null,
                'witness_required' => $e['witness_required'] ?? false,
                'outcome' => $e['outcome'] ?? null,
                'flags' => $flags,
            ]);
        });

        // Staff + site filters (post-enrichment — site is derived per event).
        $staffFilter = $request->query('staff');
        if ($staffFilter) {
            $events = $events->filter(fn ($e) => $e['performed_by'] === $staffFilter)->values();
        }
        if ($siteFilter) {
            $events = $events->filter(fn ($e) => (int) ($e['site_id'] ?? 0) === $siteFilter)->values();
        }

        // Sort by timestamp descending
        $sorted = $events->sortByDesc('timestamp')->values();

        // Stats — anchor the week/month boundaries to the worker timezone so they
        // match how the timeline groups days: a dose at 09:00 NZT is "today" even
        // though it is stored as the previous day in UTC. Carbon compares absolute
        // instants, so only the boundary's timezone needs to be right.
        $now = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'));
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $isGap = fn ($e) => ! empty(array_intersect($e['flags'] ?? [], ['missing_witness', 'omission']));

        $stats = [
            'total' => $sorted->count(),
            'this_week' => $sorted->filter(fn ($e) => Carbon::parse($e['timestamp'])->gte($weekStart))->count(),
            'this_month' => $sorted->filter(fn ($e) => Carbon::parse($e['timestamp'])->gte($monthStart))->count(),
            'open_gaps' => $sorted->filter($isGap)->count(),
        ];

        // Flat, client-side-filterable feed — the redesigned page facets by
        // view/category/search/staff with live counts. Capped for payload size.
        $events = $sorted->take(800)->values();

        $clients = Client::query()
            ->whereIn('site_id', $readerSiteIds)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]);

        $staff = $sorted->pluck('performed_by')->filter()->unique()->sort()->values()
            ->map(fn ($name, $i) => ['id' => $i, 'name' => $name]);

        $activeSite = $siteFilter
            ? Site::query()->whereKey($siteFilter)->whereIn('id', $readerSiteIds)->first()
            : null;

        return inertia('emar/AuditLog', [
            'events' => $events,
            'stats' => $stats,
            'clients' => $clients,
            'staff' => $staff,
            'sites' => Site::query()
                ->whereIn('id', $readerSiteIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'user_first_name' => $user ? (explode(' ', trim((string) $user->name))[0] ?: null) : null,
            'filters' => [
                'client_id' => $clientId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'event_types' => $eventTypes,
            ],
        ]);
    }

    private function withinDateRange($timestamp, ?string $dateFrom, ?string $dateTo): bool
    {
        if (! $timestamp) {
            return false;
        }

        $ts = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

        if ($dateFrom && $ts->lt(Carbon::parse($dateFrom)->startOfDay())) {
            return false;
        }
        if ($dateTo && $ts->gt(Carbon::parse($dateTo)->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Field-by-field diff between two medication order versions, for the audit
     * drawer's before→after section. Only changed fields are returned.
     *
     * @return array<int, array{field: string, from: string, to: string}>
     */
    private function diffVersions(MedicationOrderVersion $from, MedicationOrderVersion $to): array
    {
        $labels = [
            'name' => 'Name',
            'dosage' => 'Dose',
            'frequency' => 'Frequency',
            'route' => 'Route',
            'instructions' => 'Instructions',
            'is_prn' => 'PRN (as-needed)',
            'dose_times' => 'Dose times',
        ];

        $changes = [];
        foreach ($labels as $field => $label) {
            $a = $this->formatVersionField($from->$field);
            $b = $this->formatVersionField($to->$field);
            if ($a !== $b) {
                $changes[] = ['field' => $label, 'from' => $a, 'to' => $b];
            }
        }

        return $changes;
    }

    private function formatVersionField($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }

        return trim((string) ($value ?? ''));
    }
}
