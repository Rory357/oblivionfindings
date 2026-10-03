<?php

namespace App\Services\Medication\Followups;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationFollowup;
use App\Models\MedicationFollowupEvent;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationRefusalFollowup;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventFingerprint;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\RefusalEscalationPolicy;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MedicationFollowupService
{
    private array $viewShifts = [];

    public const MANAGE = 'medications.followups.manage';

    public const TYPES = [
        'effect' => 'As-needed effect check', 'reoffer' => 'Refusal follow-up',
        'confirm' => 'Were you there?', 'unconfirmed' => 'Unconfirmed dose',
        'partial' => 'Partial dose sign-off', 'disputed' => 'Disputed confirmation',
        'override' => 'Witness override follow-up', 'countersign' => 'Phone instruction countersign',
        'handover' => 'Handover not acknowledged', 'reconciliation' => 'Medication reconciliation',
        'watch' => 'Watch for',
        'reassess_support' => 'Reassess medication support', 'order_check' => 'Check medication order',
        'written_confirmation' => 'Prescriber written confirmation', 'second_check' => 'Independent second check',
        'reconciliation_query' => 'Reconciliation query', 'review_watch' => 'Medication review watch item',
        'stock_discrepancy' => 'Stock discrepancy follow-up',
        'covert_review' => 'Review covert authorisation',
    ];

    public const LEAD_TYPES = ['unconfirmed', 'partial', 'disputed', 'override', 'countersign', 'handover', 'reconciliation',
        'reassess_support', 'order_check', 'written_confirmation', 'second_check', 'reconciliation_query', 'stock_discrepancy', 'covert_review'];

    public const SOURCE_OWNED_TYPES = ['confirm', 'override', 'countersign', 'reconciliation',
        'reassess_support', 'order_check', 'written_confirmation', 'second_check', 'reconciliation_query', 'stock_discrepancy', 'covert_review'];

    public const SOURCE_TYPES = [
        'support-reassessment' => 'reassess_support', 'order-check' => 'order_check',
        'phone-written-confirmation' => 'written_confirmation', 'second-check' => 'second_check',
        'reconciliation-query' => 'reconciliation_query', 'review-watch' => 'review_watch',
        'stock-discrepancy' => 'stock_discrepancy', 'confirm' => 'confirm', 'witness-override' => 'override',
        'covert-review' => 'covert_review',
    ];

    public function __construct(
        private readonly MedicationGovernanceScopeService $governance,
        private readonly MedicationRecordAccess $records,
        private readonly UserSiteAccessService $sites,
        private readonly AuthorizationEvidenceLockService $evidence,
        private readonly HrCurrentStaffService $staff,
        private readonly RefusalEscalationPolicy $refusals,
    ) {}

    /** Source adapters call this inside their canonical aggregate transaction. */
    public function ensure(
        string $sourceKey, string $type, Client $client, ?ClientMedication $medication,
        ?ClientMedicationAdministration $administration, ?int $ownerId,
        ?CarbonInterface $dueAt, array $context = [],
    ): MedicationFollowup {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Create follow-up work inside the source transaction.');
        }
        if (! isset(self::TYPES[$type]) || strlen($sourceKey) > 190) {
            throw new \InvalidArgumentException('Unknown medication follow-up type or source.');
        }
        abort_unless(
            (! $medication || (int) $medication->client_id === (int) $client->id)
            && (! $administration || ((int) $administration->client_id === (int) $client->id
                && (int) $administration->client_medication_id === (int) $medication?->id)), 404,
        );
        $row = MedicationFollowup::query()->firstOrCreate(['source_key' => $sourceKey], [
            'type' => $type, 'client_id' => $client->id, 'client_medication_id' => $medication?->id,
            'administration_id' => $administration?->id,
            'owner_id' => $ownerId, 'original_owner_id' => $ownerId,
            'due_at' => $dueAt ? CarbonImmutable::instance($dueAt)->utc() : null,
            'state' => 'open', 'revision' => 1,
            'context' => $context,
        ]);
        abort_unless((int) $row->client_id === (int) $client->id
            && $row->type === $type && (int) $row->administration_id === (int) $administration?->id
            && (int) $row->client_medication_id === (int) $medication?->id, 404);
        if ($row->wasRecentlyCreated) {
            $this->event($row, $ownerId, 'created', ['due_at' => $row->due_at?->toIso8601String()]);
        }

        return $row;
    }

    public function ensureForSource(string $source, int|string $sourceId, Client $client,
        ?ClientMedication $medication, ?ClientMedicationAdministration $administration,
        ?int $ownerId, ?CarbonInterface $dueAt, array $context = []): MedicationFollowup
    {
        if (! isset(self::SOURCE_TYPES[$source]) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', (string) $sourceId)) {
            throw new \InvalidArgumentException('Use a known source and stable record identity.');
        }

        return $this->ensure($source.':'.$sourceId, self::SOURCE_TYPES[$source], $client,
            $medication, $administration, $ownerId, $dueAt, $context);
    }

    /** No guessed effect-check time; historical rows without a chosen time stay unscheduled. */
    public function syncAdministration(ClientMedicationAdministration $administration): void
    {
        $administration->loadMissing(['client', 'medication']);
        $client = $administration->client;
        $medication = $administration->medication;
        if (! $client || ! $medication || (int) $medication->client_id !== (int) $client->id) {
            return;
        }
        $owner = $administration->administered_by ? (int) $administration->administered_by : null;
        if (! ClientMedicationAdministration::query()->effectiveClinicalEvidence()->whereKey($administration->id)->exists()) {
            // Historical evidence remains intact; only outstanding work is retired.
            MedicationFollowup::query()->where('administration_id', $administration->id)
                ->whereIn('type', ['effect', 'reoffer', 'partial', 'unconfirmed'])
                ->whereNull('completed_at')->orderBy('id')->lockForUpdate()->get()
                ->each(fn ($row) => $this->close($row, 0, 'source_retired', [
                    'reason' => 'This administration no longer represents the effective clinical record.',
                    'correction_status' => $administration->correction_status,
                ]));

            return;
        }
        if ($medication->is_prn && $administration->status === 'given') {
            $row = $this->ensure('effect:'.$administration->id, 'effect', $client, $medication,
                $administration, $owner, $this->instant($administration, 'effect_check_due_at'));
            $effect = MedicationPrnEffectiveness::query()
                ->where('client_medication_administration_id', $administration->id)
                ->where('client_id', $client->id)->where('client_medication_id', $medication->id)->first();
            if ($effect && ! $row->completed_at) {
                $this->close($row, (int) $effect->reviewed_by, 'legacy_effect', [
                    'outcome' => $effect->effectiveness, 'observations' => $effect->observations,
                ], $this->instant($effect, 'reviewed_at'));
            }
        }
        $refusalSources = [$administration->id];
        if ($administration->is_correction && in_array($administration->status, ['refused', 'withheld'], true)) {
            $refusalSources[] = $administration->corrected_of_id;
        }
        foreach (MedicationRefusalFollowup::query()
            ->whereIn('client_medication_administration_id', $refusalSources)
            ->where('client_id', $client->id)->orderBy('id')->get() as $refusal) {
            $row = $this->ensure('refusal:'.$administration->id, 'reoffer', $client, $medication, $administration,
                $refusal->owner_id ? (int) $refusal->owner_id : ((int) $refusal->created_by ?: null),
                $this->instant($refusal, 'follow_up_due_at'), ['refusal_id' => $refusal->id]);
            if ($refusal->follow_up_completed_at && ! $row->completed_at) {
                $this->close($row, (int) $refusal->follow_up_completed_by, 'source_completed',
                    ['outcome' => $refusal->follow_up_outcome], $this->instant($refusal, 'follow_up_completed_at'));
            }
        }
        // PIN-2 no/expiry already owns one canonical disputed follow-up.
        if ($administration->review_required && filled($administration->review_reason_key)
            && ! in_array($administration->review_reason_key, [
                'second_person_disputed', 'second_person_confirmation_expired',
            ], true)) {
            $type = str_contains($administration->review_reason_key, 'partial') ? 'partial' : 'unconfirmed';
            $this->ensure('dose-review:'.$administration->id, $type, $client, $medication,
                $administration, null, $this->nextShiftEnd($client, $administration),
                ['reason' => $administration->review_reason]);
        }
        if ($administration->reoffer_of_id && (int) $administration->reoffer_of_id !== (int) $administration->id) {
            $root = ClientMedicationAdministration::query()->whereKey($administration->reoffer_of_id)
                ->where('client_id', $client->id)->where('client_medication_id', $medication->id)->first();
            if ($root) {
                $this->syncAdministration($root);
                ClientMedicationAdministration::query()->effectiveClinicalEvidence()
                    ->where('corrected_of_id', $root->id)->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)->orderBy('id')->get()
                    ->each(fn ($accepted) => $this->syncAdministration($accepted));
            }
        }
    }

    /** Prepare an existing source's canonical work, without recording an outcome. */
    public function prepareAdministration(User $actor, int $administrationId, string $type = 'effect'): MedicationFollowup
    {
        abort_unless(in_array($type, ['effect', 'reoffer'], true) && $actor->canDo('medications.administer.record') && $this->staff->isCurrent($actor), 403);
        $snapshot = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->findOrFail($administrationId);
        $this->records->client($actor, (int) $snapshot->client_id);

        return DB::transaction(function () use ($actor, $snapshot, $type) {
            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->firstOrFail();
            $medication = ClientMedication::withTrashed()->whereKey($snapshot->client_medication_id)
                ->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            $administration = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->whereKey($snapshot->id)
                ->where('client_id', $client->id)->where('client_medication_id', $medication->id)->lockForUpdate()->firstOrFail();
            $this->records->assertReadable($actor, $client);
            abort_if($medication->controlled_drug && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
            abort_unless($type === 'effect' ? $medication->is_prn && $administration->status === 'given'
                : in_array($administration->status, ['refused', 'withheld'], true), 404);
            $administration->setRelation('client', $client)->setRelation('medication', $medication);
            $this->syncAdministration($administration);

            return MedicationFollowup::query()->where('source_key', ($type === 'effect' ? 'effect:' : 'refusal:').$administration->id)->firstOrFail();
        }, 5);
    }

    /** Apply current person ownership before loading details, counts or tasks. */
    public function visibleQuery(User $actor, bool $includeControlled = false): Builder
    {
        $siteIds = $this->governance->readerSiteIds($actor, 'medications.view');
        $clientIds = Client::query()->whereIn('site_id', $siteIds)->pluck('id');
        $readable = $this->records->readableClientIds($actor, $clientIds);
        $query = MedicationFollowup::query()->whereIn('client_id', $readable)
            ->where(fn (Builder $q) => $q->whereNull('client_medication_id')
                ->orWhereHas('medication', fn (Builder $m) => $m->whereColumn('client_medications.client_id', 'medication_followups.client_id')))
            ->where(fn (Builder $q) => $q->whereNull('administration_id')
                ->orWhereHas('administration', fn (Builder $a) => $a
                    ->whereColumn('client_medication_administrations.client_id', 'medication_followups.client_id')
                    ->whereColumn('client_medication_administrations.client_medication_id', 'medication_followups.client_medication_id')))
            ->where(fn (Builder $q) => $q->whereNotNull('completed_at')
                ->orWhereNotIn('type', ['effect', 'reoffer', 'partial', 'unconfirmed'])
                ->orWhereHas('administration', fn (Builder $a) => $a->effectiveClinicalEvidence()));
        if (! $includeControlled && ! $actor->canDo('medications.controlled.view')) {
            $query->where(fn (Builder $q) => $q->whereNull('client_medication_id')
                ->orWhereHas('medication', fn (Builder $m) => $m->where('controlled_drug', false)->orWhereNull('controlled_drug')));
        }

        return $query;
    }

    public function forClient(User $actor, int $clientId): array
    {
        $this->records->client($actor, $clientId);

        return $this->visibleQuery($actor)->where('client_id', $clientId)->whereNull('completed_at')
            ->with(['client.site', 'medication', 'owner', 'originalOwner'])
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->get()
            ->map(fn ($row) => $this->present($row, $actor))->all();
    }

    public function present(MedicationFollowup $row, User $actor): array
    {
        $row->loadMissing(['client.site', 'medication', 'owner', 'originalOwner']);
        $lead = in_array($row->type, self::LEAD_TYPES, true);
        $shiftKey = $actor->id.':'.$row->client->site_id;
        if (! array_key_exists($shiftKey, $this->viewShifts)) {
            $this->viewShifts[$shiftKey] = $this->staff->isCurrent($actor)
                ? $this->coveringShifts((int) $row->client->site_id, [(int) $actor->id])->first() : null;
        }
        $shift = $this->viewShifts[$shiftKey];
        $manage = $actor->canDo(self::MANAGE);
        $canComplete = $row->type === 'confirm'
            ? (int) $row->owner_id === (int) $actor->id && $actor->canDo('medications.administer.record')
            : ($lead ? $manage : ($shift !== null || (int) $row->owner_id === (int) $actor->id) && $actor->canDo('medications.administer.record'));
        if ($row->medication?->controlled_drug && ! $actor->canDo('medications.controlled.record')) {
            $canComplete = false;
        }
        if ($row->completed_at && ($row->type !== 'effect'
            || ! ClientMedicationAdministration::query()->effectiveClinicalEvidence()->whereKey($row->administration_id)->where('status', 'given')->exists())) {
            $canComplete = false;
        }
        $state = $row->completed_at ? ($row->state === 'retired' ? 'retired' : 'done') : ($row->due_at?->isPast() ? 'overdue' : $row->state);
        $reofferTarget = null;
        if ($row->type === 'reoffer' && ! $row->completed_at) {
            $row->loadMissing('administration');
            $scheduled = $row->administration ? $this->instant($row->administration, 'scheduled_for') : null;
            if ($scheduled && $row->administration->status === 'refused') {
                $reofferTarget = ['kind' => 'scheduled', 'orderId' => $row->client_medication_id,
                    'scheduledFor' => $scheduled->toIso8601String(),
                    'label' => ['person' => $row->client->full_name, 'medicine' => $row->medication->name]];
            }
        }

        return [
            'id' => $row->id, 'type' => $row->type, 'label' => self::TYPES[$row->type],
            'client' => ['id' => $row->client->id, 'name' => $row->client->full_name],
            'site' => ['id' => $row->client->site_id, 'name' => $row->client->site?->name],
            'medication' => $row->medication ? ['id' => $row->medication->id, 'name' => $row->medication->name] : null,
            'administration_id' => $row->administration_id, 'owner' => $row->owner ? ['id' => $row->owner->id, 'name' => $row->owner->name] : null,
            'reoffer_target' => $reofferTarget,
            'original_owner' => $row->originalOwner ? ['id' => $row->originalOwner->id, 'name' => $row->originalOwner->name] : null,
            'due_at' => $row->due_at?->toIso8601String(), 'completed_at' => $row->completed_at?->toIso8601String(),
            'state' => $state, 'revision' => $row->revision, 'context' => $row->context,
            'lead' => $lead, 'can_complete' => $canComplete,
            'source_owned' => in_array($row->type, self::SOURCE_OWNED_TYPES, true),
            'source_url' => $row->context['source_url'] ?? null,
            'can_reassign' => ! $row->completed_at && $row->type !== 'confirm'
                && ($manage || (int) $row->owner_id === (int) $actor->id),
            'shift_end' => $shift ? $this->instant($shift, 'ends_at')?->toIso8601String() : null,
            'why' => $canComplete ? null : ($row->type === 'confirm' ? 'Only the named colleague can answer.'
                : ($lead ? 'House and clinical leads sign this off.' : 'A worker rostered at this house completes this follow-up.')),
            'record_url' => app(MarLinkService::class)->urlFor($actor, $row->client_id),
            'url' => '/medication-followups?open='.$row->id,
        ];
    }

    public function details(User $actor, int $id): array
    {
        $row = $this->visibleQuery($actor)->findOrFail($id);
        $payload = $this->present($row, $actor);
        $payload['history'] = $row->events()->with('actor:id,name')->get()->map(fn ($event) => [
            'id' => $event->id, 'action' => $event->action, 'at' => $event->created_at->toIso8601String(),
            'by' => $event->actor?->name, 'data' => $event->data,
        ])->all();
        $payload['candidates'] = $payload['can_reassign'] ? $this->candidates($row) : [];
        if ($row->type === 'reoffer') {
            $count = $this->refusals->countFor((int) $row->client_id, (int) $row->client_medication_id);
            $payload['refusal_assessment_required'] = $this->refusals->escalates($count);
            $payload['refusal_count'] = $count;
            $payload['refusal_threshold'] = $this->refusals->threshold();
            $payload['refusal_days'] = $this->refusals->days();
        }

        return $payload;
    }

    public function transition(User $actor, int $id, array $data): array
    {
        $snapshot = $this->visibleQuery($actor)->findOrFail($id);
        $targetId = ($data['action'] ?? '') === 'reassign' ? (int) ($data['owner_id'] ?? 0) : null;

        return DB::transaction(function () use ($actor, $snapshot, $data, $targetId) {
            // Clinical aggregate -> sorted Shift union -> sorted User/RBAC/profile -> Site -> workflow.
            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->firstOrFail();
            $medication = $snapshot->client_medication_id ? ClientMedication::withTrashed()
                ->whereKey($snapshot->client_medication_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail() : null;
            $administration = $snapshot->administration_id ? ClientMedicationAdministration::query()
                ->whereKey($snapshot->administration_id)->where('client_id', $client->id)
                ->where('client_medication_id', $medication?->id)->lockForUpdate()->firstOrFail() : null;
            $userIds = array_values(array_unique(array_filter([(int) $actor->id, $targetId])));
            $shifts = $this->coveringShifts((int) $client->site_id, $userIds, lock: true);
            $users = $this->evidence->lockForUsers($userIds, [
                'medications.view', self::MANAGE, 'medications.administer.record',
                'medications.controlled.view', 'medications.controlled.record',
                'medications.stock.update', 'medications.audit.view', 'medications.reports.export', 'reports.viewAny',
                'clients.viewAny', 'clients.viewAssigned', 'medications.breakglass', 'clinical.accessAllSites', 'sites.viewAll',
            ]);
            $profiles = $this->governance->lockCurrentStaffProfiles($users, $userIds);
            $users->each(fn (User $u) => $u->setRelation('hrEmployeeProfile', $profiles->get((int) $u->id)));
            $lockedActor = $users->get((int) $actor->id);
            $this->governance->lockCurrentMedicationSite((int) $client->site_id);
            abort_unless($lockedActor->canDo('medications.view'), 403);
            abort_unless(in_array((int) $client->site_id,
                $this->sites->accessibleSiteIds($lockedActor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS), true), 404);
            $this->records->assertReadable($lockedActor, $client);
            if ($medication?->controlled_drug) {
                abort_unless($lockedActor->canDo('medications.controlled.view') && $lockedActor->canDo('medications.controlled.record'), 404);
            }
            $row = MedicationFollowup::query()->whereKey($snapshot->id)->where('client_id', $client->id)
                ->where('client_medication_id', $snapshot->client_medication_id)->where('administration_id', $snapshot->administration_id)
                ->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            if (in_array($row->type, ['effect', 'reoffer', 'partial', 'unconfirmed'], true)) {
                abort_unless($administration && ClientMedicationAdministration::query()
                    ->effectiveClinicalEvidence()->whereKey($administration->id)->exists(), 404);
            }
            $manage = $lockedActor->canDo(self::MANAGE);
            $actorShift = $shifts->first(fn (Shift $s) => (int) $s->user_id === (int) $lockedActor->id);
            if ($action === 'reassign') {
                abort_unless($row->type !== 'confirm' && ($manage || (int) $row->owner_id === (int) $lockedActor->id), 403);
                $this->requireFields($data, ['reason']);
            } elseif ($row->type === 'confirm') {
                abort_unless((int) $row->owner_id === (int) $lockedActor->id && $lockedActor->canDo('medications.administer.record'), 403);
            } else {
                abort_unless(in_array($row->type, self::LEAD_TYPES, true)
                    ? $manage : $lockedActor->canDo('medications.administer.record')
                        && ($actorShift !== null || (int) $row->owner_id === (int) $lockedActor->id), 403);
            }
            $fingerprintData = $data;
            unset($fingerprintData['request_uuid']);
            ksort($fingerprintData);
            $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
            $previous = $row->events()->where('request_uuid', $data['request_uuid'])->first();
            if ($previous) {
                if ($previous->request_fingerprint !== $fingerprint || (int) $previous->actor_id !== (int) $lockedActor->id) {
                    throw ValidationException::withMessages(['request_uuid' => 'This request was already used for different follow-up details.']);
                }

                return $this->present($row, $lockedActor) + ['duplicate' => true]
                    + ($action === 'refusal' && ($data['outcome'] ?? null) === 'taken'
                        ? ['next_action' => 'record_reoffer', 'reoffer_of_id' => $row->administration_id] : []);
            }
            abort_if((int) $data['revision'] !== $row->revision, 409, 'This follow-up changed. Refresh it and review your entries.');
            abort_if($row->completed_at && $action !== 'amend_effect', 409, 'This follow-up is already complete.');
            $result = $this->apply($row, $lockedActor, $action, $data, $actorShift, $shifts, $users, $administration);
            $row->increment('revision');
            $this->event($row, (int) $lockedActor->id, $action, $data, $data['request_uuid'], $fingerprint);
            $payload = $this->present($row->refresh(), $lockedActor) + $result;
            // P09 is the final lock/write in the transaction. Its failure rolls
            // back the source, workflow, history and idempotency receipt.
            app(MedicationEventRecorder::class)->append(new MedicationEventData(
                siteId: (int) $client->site_id, kind: 'followup.'.$action,
                subjectType: 'medication_followup', subjectId: (string) $row->id,
                actorId: (int) $lockedActor->id, occurredAt: CarbonImmutable::now('UTC'),
                summary: 'Medication follow-up updated.', facts: ['action' => $action, 'revision' => $row->revision],
                clientId: (int) $client->id, controlled: (bool) $medication?->controlled_drug,
            ));

            return $payload;
        }, 5);
    }

    private function apply(MedicationFollowup $row, User $actor, string $action, array $data,
        ?Shift $actorShift, Collection $shifts, Collection $users, ?ClientMedicationAdministration $administration): array
    {
        $row->loadMissing(['client', 'medication']);
        if ($action === 'reassign') {
            $target = $users->get((int) $data['owner_id']);
            $targetShift = $shifts->first(fn ($s) => (int) $s->user_id === (int) $target?->id);
            if (! $target || ! $targetShift || ! $target->canDo('medications.administer.record')
                || ! in_array((int) $row->client->site_id, $this->sites->accessibleSiteIds($target, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS), true)
                || ($row->medication?->controlled_drug && (! $target->canDo('medications.controlled.view') || ! $target->canDo('medications.controlled.record')))) {
                throw ValidationException::withMessages(['owner_id' => 'Choose a current worker rostered at this house with medication access.']);
            }
            $this->records->assertReadable($target, $row->client);
            if (in_array($row->type, self::LEAD_TYPES, true) && ! $target->canDo(self::MANAGE)) {
                throw ValidationException::withMessages(['owner_id' => 'Choose a lead who can manage medication follow-ups.']);
            }
            $row->update(['owner_id' => $target->id]);
            $this->syncRefusalOwnership($row);

            return [];
        }
        if ($action === 'couldnt_check') {
            if (! in_array($row->type, ['effect', 'reoffer', 'watch', 'review_watch'], true) || ! $actorShift) {
                throw ValidationException::withMessages(['action' => 'This follow-up requires its sign-off action.']);
            }
            $this->requireFields($data, ['reason', 'again_at']);
            $again = FollowupTime::parse((string) $data['again_at']);
            if ($again->lessThanOrEqualTo(now('UTC')) || $again->greaterThan($this->instant($actorShift, 'ends_at'))) {
                throw ValidationException::withMessages(['again_at' => 'Choose a future time no later than the end of your shift.']);
            }
            $row->update(['due_at' => $again, 'state' => 'couldnt_check']);
            $this->syncRefusalOwnership($row);

            return [];
        }
        if ($row->type === 'effect' && in_array($action, ['effect', 'amend_effect'], true)) {
            if (! $administration || $administration->status !== 'given' || ! $row->medication?->is_prn
                || ! ClientMedicationAdministration::query()->effectiveClinicalEvidence()->whereKey($administration->id)->exists()) {
                abort(404);
            }
            $this->requireFields($data, ['outcome']);
            if (! in_array($data['outcome'], ['effective', 'partially_effective', 'not_effective'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Choose whether the medicine helped.']);
            }
            if ($data['outcome'] === 'not_effective' || ($data['escalation_needed'] ?? false)) {
                $this->requireFields($data, ['told', 'escalation_action']);
            }
            MedicationPrnEffectiveness::query()->updateOrCreate(
                ['client_medication_administration_id' => $administration->id],
                ['client_id' => $row->client_id, 'client_medication_id' => $row->client_medication_id,
                    'effectiveness' => $data['outcome'], 'observations' => $data['observations'] ?? null,
                    'review_minutes_after' => $data['review_minutes_after'] ?? max(0, (int) $this->instant($administration, 'administered_at')?->diffInMinutes(now('UTC'))),
                    'escalation_needed' => $data['outcome'] === 'not_effective' || ($data['escalation_needed'] ?? false),
                    'escalation_action' => $data['escalation_action'] ?? null, 'reviewed_by' => $actor->id, 'reviewed_at' => now('UTC')]);
            if (! $row->completed_at) {
                $row->update(['completed_at' => now('UTC'), 'completed_by' => $actor->id, 'state' => 'done']);
            }

            return [];
        }
        if ($row->type === 'reoffer' && $action === 'refusal') {
            $this->requireFields($data, ['outcome']);
            $outcome = $data['outcome'];
            if ($outcome === 'taken') {
                // Cancelling P01's recorder must leave this work open.
                return ['next_action' => 'record_reoffer', 'reoffer_of_id' => $row->administration_id];
            }
            if (! in_array($outcome, ['refused_again', 'not_needed'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Choose what happened.']);
            }
            $this->requireFields($data, ['reason']);
            $count = $this->refusals->countFor((int) $row->client_id, (int) $row->client_medication_id);
            $full = $outcome === 'refused_again' || $this->refusals->escalates($count);
            if ($full) {
                $this->validateRefusalAssessment($data);
            }
            $refusal = MedicationRefusalFollowup::query()->whereKey($row->context['refusal_id'] ?? 0)
                ->where('client_id', $row->client_id)->whereIn('client_medication_administration_id', array_filter([$row->administration_id, $administration?->corrected_of_id]))
                ->lockForUpdate()->firstOrFail();
            if ($full) {
                $refusal->fill([
                    'reason_category' => $data['reason_category'], 'client_capacity_at_time' => $data['capacity'],
                    'detailed_reason' => $data['reason'], 'offered_alternative' => (bool) ($data['offered_alternative'] ?? false),
                    'alternative_details' => $data['alternative_details'] ?? null,
                    'gp_notified_at' => ($data['gp_told'] ?? false) ? now('UTC') : $refusal->gp_notified_at,
                    'gp_notified_by' => ($data['gp_told'] ?? false) ? $actor->id : $refusal->gp_notified_by,
                    'gp_response' => $data['gp_response'] ?? $refusal->gp_response,
                    'family_notified' => ($data['family_told'] ?? false) || $refusal->family_notified,
                    'family_notified_at' => ($data['family_told'] ?? false) ? now('UTC') : $refusal->family_notified_at,
                    'follow_up_action' => $data['next_action'],
                ]);
            }
            $refusal->fill(['follow_up_completed_at' => now('UTC'), 'follow_up_completed_by' => $actor->id,
                'follow_up_outcome' => $outcome.': '.$data['reason']])->save();
            app(MedicationIncidentIntegrationService::class)->resolveRefusalEscalation($refusal, 'Medication refusal follow-up completed.', (int) $actor->id);
            $row->update(['completed_at' => now('UTC'), 'completed_by' => $actor->id, 'state' => 'done']);

            return [];
        }
        if ($row->type === 'confirm' && $action === 'confirmation') {
            throw ValidationException::withMessages(['action' => 'Answer through the named colleague confirmation request so its expiry and evidence are checked.']);
        }
        if ($action === 'signoff' && in_array($row->type, self::LEAD_TYPES, true)) {
            if (in_array($row->type, self::SOURCE_OWNED_TYPES, true)) {
                throw ValidationException::withMessages(['action' => 'Finish this work in its source record so its required evidence and checks are preserved.']);
            }
            $this->requireFields($data, ['outcome']);
            if ($row->type === 'countersign') {
                $this->requireFields($data, ['written_confirmation_reference']);
                if (! ($row->context['written_confirmed_at'] ?? null)) {
                    throw ValidationException::withMessages(['written_confirmation_reference' => 'Record the prescriber’s written confirmation on the instruction first.']);
                }
            }
            if ($administration && in_array($row->type, ['partial', 'unconfirmed'], true)) {
                $administration->update(['review_required' => false]);
            }
            $row->update(['completed_at' => now('UTC'), 'completed_by' => $actor->id, 'state' => 'done']);

            return [];
        }
        if ($action === 'complete' && in_array($row->type, ['watch', 'review_watch'], true)) {
            $this->requireFields($data, ['outcome']);
            $row->update(['completed_at' => now('UTC'), 'completed_by' => $actor->id, 'state' => 'done']);

            return [];
        }
        throw ValidationException::withMessages(['action' => 'Choose the action for this follow-up.']);
    }

    private function validateRefusalAssessment(array $data): void
    {
        $this->requireFields($data, ['reason_category', 'capacity', 'next_action']);
        if (! in_array($data['reason_category'], ['personal_choice', 'side_effects', 'difficulty_swallowing', 'nausea', 'pain', 'cognitive', 'behavioural', 'sleeping', 'other'], true)
            || ! in_array($data['capacity'], ['has_capacity', 'lacks_capacity', 'fluctuating', 'not_assessed'], true)) {
            throw ValidationException::withMessages(['reason_category' => 'Choose the reason and whether they understood the choice.']);
        }
        foreach (['offered_alternative' => 'alternative_details', 'gp_told' => 'gp_response', 'family_told' => 'family_details'] as $toggle => $field) {
            if ($data[$toggle] ?? false) {
                $this->requireFields($data, [$field]);
            }
        }
    }

    /** Domain-only: call LAST after source authorization/evidence, no further domain locks. */
    public function completeFromSource(string $sourceKey, ?User $actor, string $outcome, array $facts = [], ?array &$auditEvents = null): MedicationFollowup
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Resolve source follow-ups in the source transaction.');
        }
        if (array_key_exists('outcome', $facts)) {
            throw ValidationException::withMessages(['outcome' => 'Outcome is reserved; supply evidence facts separately.']);
        }
        $fingerprint = MedicationEventFingerprint::of([
            'source_key' => $sourceKey, 'actor_id' => $actor ? (int) $actor->id : null,
            'outcome' => $outcome, 'facts' => $facts,
        ]);
        $row = MedicationFollowup::query()->where('source_key', $sourceKey)->lockForUpdate()->firstOrFail();
        if ($row->type === 'confirm') {
            if (! in_array($outcome, ['yes', 'no', 'expired'], true)) {
                throw new \InvalidArgumentException('Unknown confirmation outcome.');
            }
            if ($outcome !== 'expired') {
                abort_unless($actor && (int) $row->owner_id === (int) $actor->id, 403);
            }
        }
        if ($row->completed_at) {
            $event = $row->events()->where('action', 'source_completed')->latest('id')->first();
            $priorFacts = $event?->data ?? [];
            unset($priorFacts['outcome']);
            $priorFingerprint = $event?->request_fingerprint ?? ($event ? MedicationEventFingerprint::of([
                'source_key' => $sourceKey, 'actor_id' => $event->actor_id ? (int) $event->actor_id : null,
                'outcome' => $event->data['outcome'] ?? null, 'facts' => $priorFacts,
            ]) : null);
            if ($priorFingerprint !== $fingerprint) {
                throw ValidationException::withMessages(['outcome' => 'This source follow-up was already completed with different evidence, actor or outcome.']);
            }

            return $row;
        }
        // Source expiry still closes durable work after a parent is deleted.
        // Reader projections retain their normal soft-delete/privacy scopes.
        $row->setRelation('client', Client::withTrashed()->findOrFail($row->client_id));
        $row->setRelation('medication', $row->client_medication_id
            ? ClientMedication::withTrashed()->findOrFail($row->client_medication_id) : null);
        $row->setRelation('administration', $row->administration_id
            ? ClientMedicationAdministration::withTrashed()->findOrFail($row->administration_id) : null);
        if ($row->type === 'confirm' && in_array($outcome, ['no', 'expired'], true)) {
            $this->ensure('disputed:'.$row->id, 'disputed', $row->client, $row->medication,
                $row->administration, null, $row->administration ? $this->nextShiftEnd($row->client, $row->administration) : null,
                ['confirmation_id' => $row->id, 'outcome' => $outcome]);
        }
        $this->close($row, (int) $actor?->id, 'source_completed', ['outcome' => $outcome, ...$facts], fingerprint: $fingerprint);
        $audit = new MedicationEventData(
            siteId: (int) $row->client->site_id, kind: 'followup.source_completed',
            subjectType: 'medication_followup', subjectId: (string) $row->id,
            actorId: $actor ? (int) $actor->id : null, occurredAt: CarbonImmutable::now('UTC'),
            summary: 'Medication follow-up completed in its source workflow.',
            facts: ['outcome' => $outcome, 'revision' => $row->revision],
            clientId: $row->client->trashed() ? null : (int) $row->client_id, controlled: (bool) $row->medication?->controlled_drug,
        );
        if ($auditEvents !== null) {
            $auditEvents[] = $audit;
        } else {
            app(MedicationEventRecorder::class)->append($audit);
        }

        return $row;
    }

    /**
     * Domain-only batch completion. Caller owns source/clinical locks and receipts.
     *
     * @param  array<int, array{source_key:string,actor:?User,outcome:string,facts?:array}>  $completions
     * @return array<int, MedicationFollowup>
     */
    public function completeSources(array $completions): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Complete source work inside its authorized transaction.');
        }
        $keys = array_column($completions, 'source_key');
        if (count($keys) !== count(array_unique($keys))) {
            throw new \InvalidArgumentException('A batch must name each source once.');
        }
        $locked = MedicationFollowup::query()->whereIn('source_key', $keys)->orderBy('id')->lockForUpdate()->get()->keyBy('source_key');
        abort_unless($locked->count() === count($keys), 404);
        $auditEvents = [];
        $rows = [];
        // Process the prelocked workflow set in deterministic order too.
        $commands = collect($completions)->keyBy('source_key');
        foreach ($locked as $key => $row) {
            $command = $commands->get($key);
            $rows[] = $this->completeFromSource($key, $command['actor'], $command['outcome'], $command['facts'] ?? [], $auditEvents);
        }
        if ($auditEvents !== []) {
            app(MedicationEventRecorder::class)->appendMany($auditEvents);
        }

        return $rows;
    }

    /** Source-owned evidence updates; the public controller never accepts context. */
    public function updateSourceContext(string $sourceKey, array $context): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Update source context inside the source transaction.');
        }
        $row = MedicationFollowup::query()->where('source_key', $sourceKey)->lockForUpdate()->firstOrFail();
        $row->update(['context' => [...($row->context ?? []), ...$context], 'revision' => $row->revision + 1]);
        $this->event($row, null, 'source_updated', $context);
    }

    private function requireFields(array $data, array $fields): void
    {
        $errors = [];
        foreach ($fields as $field) {
            if (blank($data[$field] ?? null)) {
                $errors[$field] = 'Record this detail before saving.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function coveringShifts(int $siteId, array $userIds, bool $lock = false): Collection
    {
        $query = Shift::query()->whereIn('user_id', $userIds)
            ->where('starts_at', '<=', now('UTC'))->where('ends_at', '>=', now('UTC'))
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $s->where('site_id', $siteId)
                    ->where(fn (Builder $c) => $c->whereNull('client_id')
                        ->orWhereHas('client', fn (Builder $c) => $c->where('site_id', $siteId))))
                ->orWhere(fn (Builder $s) => $s->whereNull('site_id')
                    ->whereHas('client', fn (Builder $c) => $c->where('site_id', $siteId))))
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function candidates(MedicationFollowup $row): array
    {
        $ids = $this->coveringShifts((int) $row->client->site_id, $this->staff->currentUserIds())->pluck('user_id')->unique();

        return $this->staff->currentUsersQuery()->whereIn('id', $ids)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canDo('medications.view') && $u->canDo('medications.administer.record')
                && (! in_array($row->type, self::LEAD_TYPES, true) || $u->canDo(self::MANAGE))
                && (! $row->medication?->controlled_drug || ($u->canDo('medications.controlled.view') && $u->canDo('medications.controlled.record')))
                && in_array((int) $row->client_id, $this->records->readableClientIds($u, [$row->client_id]), true))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
    }

    private function nextShiftEnd(Client $client, ClientMedicationAdministration $administration): ?CarbonImmutable
    {
        $origin = $administration->shift_id ? Shift::query()->find($administration->shift_id) : null;
        $boundary = $origin ? $this->instant($origin, 'ends_at') : now('UTC');
        $next = Shift::query()->whereNotIn('status', ['cancelled'])->where('starts_at', '>=', $boundary)
            ->where(fn (Builder $q) => $q->where('site_id', $client->site_id)
                ->orWhere(fn (Builder $q) => $q->whereNull('site_id')->where('client_id', $client->id)))
            ->orderBy('starts_at')->orderBy('id')->first();

        return $next ? $this->instant($next, 'ends_at') : null;
    }

    private function syncRefusalOwnership(MedicationFollowup $row): void
    {
        if ($row->type === 'reoffer') {
            $row->loadMissing('administration');
            MedicationRefusalFollowup::query()->whereKey($row->context['refusal_id'] ?? 0)
                ->where('client_id', $row->client_id)->whereIn('client_medication_administration_id', array_filter([$row->administration_id, $row->administration?->corrected_of_id]))
                ->update(['owner_id' => $row->owner_id, 'follow_up_due_at' => $row->due_at]);
        }
    }

    /** Called under ShiftHandoverService's validated Client/Shift/actor locks. */
    public function acknowledged(ShiftHandover $handover, Shift $incoming, User $actor): void
    {
        if (DB::transactionLevel() < 1 || (int) $incoming->user_id !== (int) $actor->id
            || (int) $handover->incoming_shift_id !== (int) $incoming->id || (int) $incoming->client_id !== (int) $handover->client_id
            || $handover->status !== 'acknowledged' || (int) $handover->acknowledged_by !== (int) $actor->id) {
            throw new \LogicException('Carry-over requires the canonical acknowledged incoming shift.');
        }
        if (! $actor->canDo('medications.view') || ! $actor->canDo('medications.administer.record')) {
            return;
        }
        $rows = $this->visibleQuery($actor)->where('client_id', $handover->client_id)->whereNull('completed_at')
            ->whereIn('type', ['effect', 'reoffer', 'watch', 'review_watch'])
            ->where('created_at', '<=', $this->instant($incoming, 'starts_at'))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($row->medication?->controlled_drug && ! $actor->canDo('medications.controlled.record')) {
                continue;
            }
            if ((int) $row->owner_id === (int) $actor->id) {
                continue;
            }
            if ($row->owner_id && $this->coveringShifts((int) $row->client->site_id, [(int) $row->owner_id])->isNotEmpty()) {
                continue;
            }
            if ($row->events()->where('action', 'carried')->where('data->handover_id', $handover->id)->exists()) {
                continue;
            }
            $from = $row->owner_id;
            $row->update(['owner_id' => $actor->id, 'revision' => $row->revision + 1]);
            $this->syncRefusalOwnership($row);
            $this->event($row, (int) $actor->id, 'carried', ['handover_id' => $handover->id, 'from_owner_id' => $from]);
        }
    }

    /** A lead heads-up exists even with Delivery off; this sends no messages. */
    public function headsUp(ShiftHandover $snapshot): void
    {
        DB::transaction(function () use ($snapshot) {
            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            if (! $client) {
                return;
            }
            $incoming = Shift::query()->whereKey($snapshot->incoming_shift_id)->lockForUpdate()->first();
            $handover = ShiftHandover::query()->whereKey($snapshot->id)->where('client_id', $client->id)->lockForUpdate()->first();
            if (! $incoming || ! $handover || $handover->status !== 'submitted'
                || (int) $handover->incoming_shift_id !== (int) $incoming->id || (int) $incoming->client_id !== (int) $client->id
                || $handover->acknowledged_at || $incoming->status === 'cancelled'
                || (int) ($incoming->site_id ?? $client->site_id) !== (int) $client->site_id) {
                return;
            }
            $due = $this->instant($incoming, 'starts_at')?->addHour();
            if (! $due || $due->isFuture()) {
                return;
            }
            $this->ensure('handover:'.$handover->id, 'handover', $client, null, null, null, $due,
                ['handover_id' => $handover->id, 'incoming_shift_id' => $incoming->id]);
        }, 3);
    }

    private function close(MedicationFollowup $row, int $by, string $action, array $data, ?CarbonImmutable $at = null, ?string $fingerprint = null): void
    {
        $row->update(['completed_at' => $at ?? now('UTC'), 'completed_by' => $by > 0 ? $by : null,
            'state' => $action === 'source_retired' ? 'retired' : 'done', 'revision' => $row->revision + 1]);
        $this->event($row, $by > 0 ? $by : null, $action, $data, fingerprint: $fingerprint);
    }

    private function event(MedicationFollowup $row, ?int $actorId, string $action, array $data,
        ?string $requestUuid = null, ?string $fingerprint = null): void
    {
        unset($data['request_uuid']);
        MedicationFollowupEvent::query()->create([
            'medication_followup_id' => $row->id, 'actor_id' => $actorId, 'action' => $action,
            'request_uuid' => $requestUuid, 'request_fingerprint' => $fingerprint,
            'data' => $data, 'created_at' => now('UTC'),
        ]);
    }

    private function instant($model, string $field): ?CarbonImmutable
    {
        $raw = $model->getRawOriginal($field);

        return filled($raw) ? CarbonImmutable::parse($raw, 'UTC') : null;
    }
}
