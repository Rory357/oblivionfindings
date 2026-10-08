<?php

namespace App\Services\Medication\Controlled;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlledWitnessOverride;
use App\Models\ControlledWitnessRequest;
use App\Models\ControlledWorkflowEvent;
use App\Models\MedicationDestruction;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\Medication\WitnessPinService;
use App\Support\Medication\MedicationStockQuantity as Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ControlledProductPayload
{
    public const HISTORY_LIMIT = 500;

    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly ControlledPolicy $policy,
        private readonly ControlledMedicationTransportWitnessService $witnesses,
        private readonly WitnessPinService $pins,
    ) {}

    public function forActor(User $actor, ?int $siteId = null, ?int $medicationId = null, ?int $clientId = null, ?string $date = null, ?int $entryId = null, ?int $destructionId = null): array
    {
        $siteIds = $this->scope->readerSiteIds($actor, 'medications.controlled.view', $siteId);
        if ($siteId !== null) {
            $siteIds = [$siteId];
        }
        $clientIds = $this->access->readableClientIds($actor, Client::query()->whereIn('site_id', $siteIds)->pluck('id'));
        // Narrow within the existing person boundary; conceal unreadable IDs.
        abort_if($clientId !== null && ! in_array($clientId, $clientIds, true), 404);
        $people = Client::query()->whereIn('id', $clientIds)->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])->map(fn ($person) => ['id' => $person->id, 'name' => $person->full_name])->values();
        if ($clientId !== null) {
            $clientIds = [$clientId];
        }
        $meds = ClientMedication::withTrashed()->where('controlled_drug', true)->whereIn('client_id', $clientIds)
            ->when($medicationId !== null, fn ($q) => $q->whereKey($medicationId))
            ->with(['client.site', 'stock'])->orderBy('name')->get();
        abort_if($medicationId !== null && $meds->isEmpty(), 404);
        $ids = $meds->pluck('id');
        $entriesQuery = $this->scope->scopeCanonicalClientMedicationRows(ClientControlledDrugEntry::query(), $siteIds, false)->whereIn('client_medication_id', $ids)->whereIn('client_id', $clientIds);
        // History dates never change current stock, count evidence or the
        // optimistic entry version used by witnessed commands.
        $historyQuery = clone $entriesQuery;
        if ($date !== null) {
            $start = CarbonImmutable::parse($date, 'Pacific/Auckland')->startOfDay();
            $historyQuery->where('recorded_at', '>=', $start->utc())
                ->where('recorded_at', '<', $start->addDay()->utc());
        }
        $entries = (clone $historyQuery)->with(['recordedBy:id,name', 'witnessedBy:id,name'])->withExists('destructions')->latest('id')->limit(self::HISTORY_LIMIT)->get();
        if ($entryId !== null) {
            $selectedEntry = (clone $historyQuery)->with(['recordedBy:id,name', 'witnessedBy:id,name'])->withExists('destructions')->findOrFail($entryId);
            $entries = $entries->push($selectedEntry)->unique('id')->sortByDesc('id')->values();
        }
        $latest = (clone $entriesQuery)->selectRaw('client_medication_id, MAX(id) AS latest_id')->groupBy('client_medication_id')->pluck('latest_id', 'client_medication_id');
        $counts = app(ControlledCountStatus::class)->latestWitnessedCounts($ids, $siteIds);
        $reversals = $this->scope->scopeCanonicalClientMedicationRows(ClientControlledDrugEntry::query(), $siteIds, false)
            ->whereIn('client_medication_id', $ids)->whereIn('client_id', $clientIds)
            ->whereIn('reverses_entry_id', $entries->pluck('id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('client_controlled_drug_entries as original')
                ->whereColumn('original.id', 'client_controlled_drug_entries.reverses_entry_id')
                ->whereColumn('original.client_id', 'client_controlled_drug_entries.client_id')
                ->whereColumn('original.client_medication_id', 'client_controlled_drug_entries.client_medication_id'))
            ->with(['recordedBy:id,name', 'witnessedBy:id,name'])->get()->keyBy('reverses_entry_id');
        $discrepancyQuery = $this->scope->scopeCanonicalClientMedicationRows(ClientControlledDrugDiscrepancy::query(), $siteIds, false)->whereIn('client_medication_id', $ids)->whereIn('client_id', $clientIds);
        $discrepancies = $this->retainOutstanding($discrepancyQuery, fn ($q) => $q->whereIn('status', ['open', 'under_review']), ['reportedBy:id,name', 'witnessedBy:id,name', 'resolvedBy:id,name']);
        $lossQuery = $this->scope->scopeCanonicalClientMedicationRows(ControlledDrugLossReport::query(), $siteIds, false)->whereIn('client_medication_id', $ids)->whereIn('client_id', $clientIds);
        $losses = $this->retainOutstanding($lossQuery, fn ($q) => $q->whereIn('investigation_status', ['reported', 'investigating']), ['discoveredBy:id,name', 'resolvedBy:id,name']);
        $destructionQuery = $this->scope->scopeCanonicalClientMedicationRows(MedicationDestruction::query(), $siteIds, false)->whereIn('client_medication_id', $ids)->whereIn('client_id', $clientIds)->where('is_controlled_drug', true)
            ->where(fn ($q) => $q->whereNull('medication_destructions.site_id')->orWhereHas('client', fn ($c) => $c->whereColumn('clients.site_id', 'medication_destructions.site_id')));
        $destructions = $this->retainOutstanding($destructionQuery, fn ($q) => $q->whereNull('voided_at')->where('disposal_method', 'pharmacy_return')->whereNull('pharmacy_received_at'), ['destroyedByUser:id,name', 'witness1:id,name', 'witness2:id,name']);
        if ($destructionId !== null) {
            $selectedDestruction = (clone $destructionQuery)->with(['destroyedByUser:id,name', 'witness1:id,name', 'witness2:id,name'])->findOrFail($destructionId);
            abort_if($date !== null && $selectedDestruction->destroyed_at?->timezone('Pacific/Auckland')->toDateString() !== $date, 404);
            $destructions = $destructions->push($selectedDestruction)->unique('id')->sortByDesc('id')->values();
        }
        $overrideQuery = ControlledWitnessOverride::query()->whereIn('site_id', $siteIds)->whereIn('client_medication_id', $ids);
        $overrides = $this->retainOutstanding($overrideQuery, fn ($q) => $q->whereIn('status', ['waiting', 'approved'])->whereNull('signed_off_at'));
        $overrideDoses = $this->scopeOverrideDoses(ClientMedicationAdministration::query(), $siteIds, $ids, $clientIds)
            ->whereIn('witness_override_id', $overrides->pluck('id'))->with('administeredBy:id,name')->get();
        // Fetch the complete evidence for displayed records; an unrelated event cap must never hide sign-off.
        $events = ControlledWorkflowEvent::query()->whereIn('client_medication_id', $ids)
            ->where(fn ($q) => $q->where(fn ($loss) => $loss->where('subject_type', 'loss')->whereIn('subject_id', $losses->pluck('id')))
                ->orWhere(fn ($dose) => $dose->where('subject_type', 'override_dose')->whereIn('subject_id', $overrideDoses->pluck('id'))))
            ->latest('id')->get();
        $names = User::query()->whereIn('id', $events->pluck('actor_id')->merge($entries->pluck('second_witness_id'))->merge($discrepancies->pluck('owner_id')))->pluck('name', 'id');
        $requests = ControlledWitnessRequest::query()->whereIn('client_medication_id', $ids)->whereNull('closed_at')
            ->where(fn ($q) => $q->where('requested_by', $actor->id)->orWhere('addressed_to', $actor->id))->latest('id')->limit(self::HISTORY_LIMIT)->get();
        $allUserIds = $requests->pluck('requested_by')->merge($requests->pluck('addressed_to'))->merge($overrides->pluck('requested_by'))->merge($overrides->pluck('decided_by'));
        $names = $names->union(User::query()->whereIn('id', $allUserIds)->pluck('name', 'id'));
        $manage = $actor->canDo(ControlledRegisterService::MANAGE);
        $record = $actor->canDo('medications.controlled.record');
        $siteRows = Site::query()->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name', 'brand_colour']);
        $activeSite = $siteId !== null ? $siteRows->firstWhere('id', $siteId) : null;
        $presentSites = HrAttendanceSession::query()->where('user_id', $actor->id)->whereIn('site_id', $siteIds)->where('status', 'open')->whereNull('clock_out_at')->where('clock_in_at', '<=', now()->utc())->pluck('site_id')->all();
        // Canonical active Shift evidence remains supported alongside attendance.
        foreach (Shift::query()->where('user_id', $actor->id)->whereIn('status', ['in_progress', 'active', 'clocked_in', 'started'])->where('starts_at', '<=', now()->utc())->where('ends_at', '>=', now()->utc())->with('client:id,site_id')->get() as $shift) {
            $sid = $shift->site_id ?? $shift->client?->site_id;
            if ($sid && in_array((int) $sid, $siteIds, true) && ($shift->client_id === null || (int) $shift->client?->site_id === (int) $sid)) {
                $presentSites[] = (int) $sid;
            }
        }
        $witnessRows = [];
        foreach ($siteIds as $sid) {
            $eligible = $this->witnesses->eligibleWitnessesForSite($sid, now(), $actor->id);
            $statuses = $this->pins->statuses($eligible->pluck('id'));
            $witnessRows[$sid] = $eligible->map(fn (User $user): array => [
                'id' => $user->id, 'name' => $user->name, 'eligible' => ($statuses[$user->id] ?? '') === WitnessPinService::STATUS_SET,
                'reason' => ($statuses[$user->id] ?? '') === WitnessPinService::STATUS_SET ? null : 'A usable witness PIN is needed',
                'pin_status' => $statuses[$user->id] ?? WitnessPinService::STATUS_NOT_SET,
            ])->values()->all();
        }

        return [
            'filters' => ['site_id' => $siteId, 'client_medication_id' => $medicationId, 'client_id' => $clientId, 'date' => $date], 'people' => $people,
            'selected_entry_id' => $entryId, 'selected_destruction_id' => $destructionId,
            'current_user_id' => $actor->id, 'current_user_name' => $actor->name,
            'sites' => $siteRows->map(fn (Site $site): array => $site->only(['id', 'name'])),
            'site_brand_colour' => $activeSite?->brand_colour,
            'as_at' => now()->toIso8601String(), 'witnesses_by_site' => $witnessRows,
            'on_site_destruction_allowed' => $this->policy->onsiteAllowed($siteId),
            'can' => ['view' => true, 'record' => $record, 'manage' => $manage, 'override' => $actor->canDo('medications.controlled.override'), 'close_loss' => $manage && $actor->hasRole('provider_manager')],
            'cadence' => ['configured' => $this->policy->cadence() !== null && ($this->policy->cadence() !== 'week' || $this->policy->weeklyAnchor() !== null), 'label' => match ($this->policy->cadence()) {
                'shift' => 'Every shift change', 'day' => 'Once a day at the morning shift change', 'week' => $this->policy->weeklyAnchor() === null ? 'Once a week — day and time not configured' : 'Once a week — '.(new WeeklyCountAnchorCodec(config('app.worker_timezone', 'Pacific/Auckland')))->format(json_encode($this->policy->weeklyAnchor())), default => 'Not configured'
            }, 'overdue_after_minutes' => $this->policy->overdueMinutes()],
            'history_limit' => self::HISTORY_LIMIT,
            'history_has_more' => ['entries' => (clone $historyQuery)->count() > self::HISTORY_LIMIT, 'discrepancies' => (clone $discrepancyQuery)->count() > self::HISTORY_LIMIT, 'losses' => (clone $lossQuery)->count() > self::HISTORY_LIMIT, 'destructions' => (clone $destructionQuery)->count() > self::HISTORY_LIMIT, 'overrides' => (clone $overrideQuery)->count() > self::HISTORY_LIMIT],
            'totals' => ['open_discrepancies' => (clone $discrepancyQuery)->whereIn('status', ['open', 'under_review'])->count(), 'open_losses' => (clone $lossQuery)->whereIn('investigation_status', ['reported', 'investigating'])->count(), 'awaiting_receipt' => (clone $destructionQuery)->whereNull('voided_at')->where('disposal_method', 'pharmacy_return')->whereNull('pharmacy_received_at')->count()],
            'medicines' => $medicineRows = $meds->map(function (ClientMedication $m) use ($record, $presentSites, $counts, $latest): array {
                $count = $counts->get($m->id);
                $state = $this->policy->countStatus($m, now(), $count?->recorded_at);
                $status = match ($state['status']) {
                    'complete' => 'counted', 'upcoming' => 'next', 'not_configured', 'schedule_unavailable' => 'not_configured', default => $state['status']
                };
                $canRecord = $record && in_array((int) $m->client->site_id, $presentSites, true) && (($m->deleted_at === null && $m->superseded_by === null));

                return ['id' => $m->id, 'client_id' => $m->client_id, 'client_name' => $m->client->full_name, 'site_id' => $m->client->site_id, 'site_name' => $m->client->site?->name ?? '',
                    'pack_stock' => app(MedicationStockService::class)->packOptions($m),
                    'name' => $m->name, 'unit' => $m->stock?->unit ?? '', 'balance' => $m->stock?->on_hand === null ? null : Quantity::toFloat($m->stock->on_hand),
                    'entry_version' => $latest[$m->id] ?? null, 'nz_class' => $m->nz_controlled_class, 'class_review_required' => $m->controlled_class_reviewed_at === null,
                    'can_count' => $record && in_array((int) $m->client->site_id, $presentSites, true) && $this->policy->countRequired($m),
                    'can_destroy' => $canRecord && app(MedicationStockService::class)->canDestroyRetainedSupply($m, $m->stock),
                    'can_record' => $canRecord, 'record_reason' => $canRecord ? null : 'Record access and a clocked-in shift at this house are needed.',
                    'count' => ['state' => $status, 'cadence' => $state['cadence'], 'next_at' => $state['next_change_at'], 'title' => match ($state['status']) {
                        'due' => $state['cadence'] === 'week' ? 'Due now — weekly count' : 'Due now — shift-change count', 'overdue' => $state['cadence'] === 'week' ? 'Weekly count overdue' : 'Shift-change count overdue', 'complete' => 'Counted', 'upcoming' => $state['cadence'] === 'week' ? 'Next weekly count' : 'Next shift-change count', 'schedule_unavailable' => $state['cadence'] === 'week' ? 'Weekly count day and time not configured' : 'Roster timing not configured', 'not_applicable' => 'No stock count required', default => 'Not configured'
                    },
                        'due_at' => $state['due_at'], 'overdue_at' => $state['overdue_at'], 'last_at' => $count?->recorded_at?->toIso8601String(), 'last_entry_id' => $count?->id]];
            })->values(),
            'entries' => $entries->map(fn ($e): array => ['id' => $e->id, 'client_medication_id' => $e->client_medication_id, 'entry_type' => $e->entry_type, 'stock_balance_scope' => $e->stock_balance_scope, 'transit_log_id' => $e->transit_log_id, 'quantity' => $e->quantity === null ? null : Quantity::toFloat($e->quantity),
                'on_hand_before' => $e->on_hand_before === null ? null : Quantity::toFloat($e->on_hand_before), 'on_hand_after' => $e->on_hand_after === null ? null : Quantity::toFloat($e->on_hand_after), 'recorded_at' => $e->recorded_at?->toIso8601String(),
                'recorded_by_name' => $e->recordedBy?->name, 'witnessed_by_name' => $e->witnessedBy?->name, 'second_witness_name' => $names[$e->second_witness_id] ?? null,
                'notes' => $e->notes, 'voided_at' => $reversals->get($e->id)?->recorded_at?->toIso8601String(), 'void_reason' => $reversals->get($e->id)?->reason,
                'voided_by_name' => $reversals->get($e->id)?->recordedBy?->name, 'void_witness_name' => $reversals->get($e->id)?->witnessedBy?->name,
                'can_void' => $manage && $e->entry_type !== 'balance_check' && $e->reverses_entry_id === null && ! $reversals->has($e->id) && ! $e->requiresGovernedReconciliation()]),
            'discrepancies' => $discrepancies->map(fn ($d): array => ['id' => $d->id, 'client_medication_id' => $d->client_medication_id, 'status' => $d->status,
                'expected_balance' => $d->on_hand_before === null ? null : Quantity::toFloat($d->on_hand_before), 'actual_balance' => $d->on_hand_after === null ? null : Quantity::toFloat($d->on_hand_after),
                'reported_at' => $d->reported_at?->toIso8601String(), 'reported_by_name' => $d->reportedBy?->name, 'witnessed_by_name' => $d->witnessedBy?->name,
                'owner_name' => $names[$d->owner_id] ?? null, 'notes' => $d->notes, 'immediate_action_taken' => $d->immediate_action_taken, 'outcome' => $d->resolution_outcome,
                'resolution_notes' => $d->resolution_notes, 'resolved_at' => $d->resolved_at?->toIso8601String(), 'resolved_by_name' => $d->resolvedBy?->name, 'incident_id' => $d->incident_id,
                'can_resolve' => $manage && ! in_array((int) $actor->id, [(int) $d->reported_by, (int) $d->witnessed_by], true) && in_array($d->status, ['open', 'under_review'], true),
                'resolve_reason' => in_array((int) $actor->id, [(int) $d->reported_by, (int) $d->witnessed_by], true) ? 'You counted or witnessed it. Someone independent resolves it.' : null]),
            'losses' => $losses->map(function ($l) use ($events, $names): array {
                $notes = $events->where('subject_type', 'loss')->where('subject_id', $l->id);

                return ['id' => $l->id, 'client_medication_id' => $l->client_medication_id, 'quantity' => Quantity::toFloat($l->quantity_lost), 'status' => $l->investigation_status,
                    'discovered_at' => $l->discovered_at?->toIso8601String(), 'circumstances' => $l->circumstances, 'immediate_action_taken' => $l->immediate_action_taken,
                    'reported_by_name' => $l->discoveredBy?->name, 'incident_id' => $l->incident_id, 'suspected_theft' => $l->suspected_theft,
                    'reported_to_police' => $l->reported_to_police, 'police_reference' => $l->police_reference, 'police_notified_at' => $l->police_reported_at?->toIso8601String(),
                    'reported_to_regulator' => $l->reported_to_regulator, 'regulator_notified_at' => $l->regulator_notified_at?->toIso8601String(),
                    'resolution_outcome' => $l->resolution_outcome, 'closed_at' => $l->resolved_at?->toIso8601String(),
                    'notes' => $notes->map(fn ($n): array => ['id' => $n->id, 'notes' => $n->payload['notes'] ?? '', 'created_at' => $n->created_at?->toIso8601String(), 'created_by_name' => $names[$n->actor_id] ?? null])->values()];
            }),
            'destructions' => $destructions->map(fn ($d): array => ['id' => $d->id, 'client_medication_id' => $d->client_medication_id, 'medication_name' => $d->medication_name, 'form' => $d->form, 'strength' => $d->strength,
                'quantity' => Quantity::toFloat($d->quantity),
                'reason' => $d->reason, 'method' => $d->disposal_method, 'recorded_at' => $d->destroyed_at?->toIso8601String(), 'recorded_by_name' => $d->destroyedByUser?->name,
                'witnessed_by_name' => $d->witness1?->name, 'second_witness_name' => $d->witness2?->name, 'notes' => $d->notes,
                'pharmacist_name' => $d->pharmacist_name, 'pharmacist_registration' => $d->pharmacist_registration, 'received_at' => $d->pharmacy_received_at?->toIso8601String(),
                'voided_at' => $d->voided_at?->toIso8601String(), 'void_reason' => $d->void_reason,
                'void_stock_semantics' => MedicationDestruction::VOID_STOCK_SEMANTICS,
                'requires_governed_stock_reconciliation' => $d->voided_at !== null && (bool) $d->is_controlled_drug,
                'photo' => $d->photo_path ? ['url' => '/emar/controlled/product/destructions/'.$d->id.'/photo', 'download_url' => '/emar/controlled/product/destructions/'.$d->id.'/photo?download=1', 'name' => 'Destruction photo', 'mime_type' => match (strtolower(pathinfo($d->photo_path, PATHINFO_EXTENSION))) {
                    'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg'
                }] : null]),
            'requests' => $requests->map(fn ($r): array => ['id' => $r->id, 'site_id' => $r->site_id, 'client_medication_id' => $r->client_medication_id,
                'requested_by_id' => $r->requested_by, 'requested_by_name' => $names[$r->requested_by] ?? '', 'witness_id' => $r->addressed_to, 'witness_name' => $names[$r->addressed_to] ?? '',
                'status' => $r->response ?? 'waiting', 'reason' => $r->reason, 'response' => $r->response, 'created_at' => $r->created_at?->toIso8601String(),
                'can_answer' => (int) $r->addressed_to === (int) $actor->id, 'can_cancel' => (int) $r->requested_by === (int) $actor->id]),
            'overrides' => $overrides->map(function ($o) use ($actor, $names, $siteRows, $events, $meds, $overrideDoses): array {
                $visibleIds = array_values(array_intersect(array_map('intval', $o->medicine_ids), $meds->pluck('id')->map(fn ($id) => (int) $id)->all()));
                $doses = $overrideDoses->where('witness_override_id', $o->id)->whereIn('client_medication_id', $visibleIds);
                $completeScope = count($visibleIds) === count(array_unique(array_map('intval', $o->medicine_ids)));

                return ['id' => $o->id, 'site_id' => $o->site_id, 'site_name' => $siteRows->firstWhere('id', $o->site_id)?->name ?? '', 'requested_at' => $o->created_at?->toIso8601String(),
                    'requested_by_name' => $names[$o->requested_by] ?? '', 'reason' => $completeScope ? $o->reason : 'Details require access to every person covered by this override.', 'status' => $o->signed_off_at ? 'signed_off' : $o->status, 'medicine_ids' => $visibleIds,
                    'starts_at' => $o->starts_at?->toIso8601String(), 'expires_at' => $o->expires_at?->toIso8601String(), 'decided_by_name' => $names[$o->decided_by] ?? null,
                    'decision_notes' => $completeScope ? $o->decision_reason : null, 'followup_due_at' => $o->followup_due_at?->toIso8601String(), 'followup_overdue' => $o->status === 'approved' && $o->signed_off_at === null && $o->followup_due_at?->isPast(),
                    'can_signoff' => $actor->canDo(ControlledRegisterService::MANAGE) && $actor->hasRole('team_lead', 'provider_manager'),
                    'doses' => $doses->map(function ($dose) use ($events, $names): array {
                        $sign = $events->where('subject_type', 'override_dose')->where('subject_id', $dose->id)->where('action', 'signed_off')->first();

                        return ['administration_id' => $dose->id, 'client_medication_id' => $dose->client_medication_id, 'administered_at' => $dose->administered_at?->toIso8601String(), 'administered_by_name' => $dose->administeredBy?->name,
                            'signed_off_at' => $sign?->created_at?->toIso8601String(), 'signed_off_by_name' => $names[$sign?->actor_id] ?? null, 'counted_entry_id' => $sign?->payload['counted_entry_id'] ?? null];
                    })];
            }),
            'meters' => $this->meters($actor, $medicineRows, $discrepancyQuery, $lossQuery, $destructionQuery, $overrideQuery, $ids, $clientIds),
        ];
    }

    /** A dose belongs to its canonical owner and to the override's listed medicines at that house. */
    private function scopeOverrideDoses($query, ?array $siteIds, Collection $medicationIds, array $clientIds)
    {
        return $this->scope->scopeCanonicalClientMedicationRows($query, $siteIds, false)
            ->whereIn('client_medication_id', $medicationIds)->whereIn('client_id', $clientIds)
            ->whereExists(fn ($parent) => $parent->selectRaw('1')->from('controlled_witness_overrides')
                ->join('clients as dose_owner', 'dose_owner.site_id', '=', 'controlled_witness_overrides.site_id')
                ->whereColumn('dose_owner.id', 'client_medication_administrations.client_id')
                ->whereColumn('controlled_witness_overrides.id', 'client_medication_administrations.witness_override_id')
                ->whereJsonContains('controlled_witness_overrides.medicine_ids', DB::raw('JSON_ARRAY(client_medication_administrations.client_medication_id)')));
    }

    /** Outstanding records remain actionable even when completed history exceeds the display limit. */
    private function retainOutstanding($query, \Closure $outstanding, array $relations = []): Collection
    {
        return (clone $query)->where($outstanding)->with($relations)->latest('id')->get()
            ->merge((clone $query)->whereNot($outstanding)->with($relations)->latest('id')->limit(self::HISTORY_LIMIT)->get())
            ->sortByDesc('id')->values();
    }

    private function meters(User $actor, Collection $medicines, $discrepancies, $losses, $destructions, $overrides, Collection $medicationIds, array $clientIds): array
    {
        $openDiscrepancies = (clone $discrepancies)->whereIn('status', ['open', 'under_review'])->count();
        $openLosses = (clone $losses)->whereIn('investigation_status', ['reported', 'investigating'])->count();
        $receipts = (clone $destructions)->whereNull('voided_at')->where('disposal_method', 'pharmacy_return')->whereNull('pharmacy_received_at')->count();
        $pending = (clone $overrides)->where('status', 'approved')->whereNull('signed_off_at')->whereHas('administrations', fn ($q) => $this->scopeOverrideDoses($q, null, $medicationIds, $clientIds))->count();
        $overdueFollowups = (clone $overrides)->where('status', 'approved')->whereNull('signed_off_at')->where('followup_due_at', '<', now())->whereHas('administrations', fn ($q) => $this->scopeOverrideDoses($q, null, $medicationIds, $clientIds))->count();
        $waiting = (clone $overrides)->where('status', 'waiting')->count();
        $due = $medicines->filter(fn ($m) => in_array($m['count']['state'], ['due', 'overdue'], true))->count();
        $overdue = $medicines->where('count.state', 'overdue')->count();
        $requests = ControlledWitnessRequest::query()->whereIn('client_medication_id', $medicationIds)->whereNull('closed_at')->where('addressed_to', $actor->id)->whereNull('answered_at')->count();
        $attention = $due + $openDiscrepancies + $openLosses + $receipts + $requests + ($actor->canDo('medications.controlled.override') ? $waiting : 0) + ($actor->canDo(ControlledRegisterService::MANAGE) ? $pending : 0);

        return ['total_open_discrepancies' => $openDiscrepancies, 'total_open_losses' => $openLosses, 'total_awaiting_receipts' => $receipts,
            'total_class_reviews' => $medicines->where('class_review_required', true)->count(), 'total_overdue_counts' => $overdue, 'total_medicines' => $medicines->count(),
            'total_waiting_overrides' => $waiting, 'total_pending_followups' => $pending, 'total_overdue_followups' => $overdueFollowups,
            'controlled_attention_count' => $attention, 'controlled_attention_alert' => $overdue > 0 || $openDiscrepancies > 0 || $openLosses > 0 || $overdueFollowups > 0];
    }
}
