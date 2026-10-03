<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAdminRule;
use App\Models\MedicationDowntime;
use App\Models\MedicationDowntimeDose;
use App\Models\MedicationDowntimeResolution;
use App\Models\MedicationPaperConfirmation;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationPaperPosting;
use App\Models\User;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaperEntryService
{
    public function __construct(
        private readonly DowntimeAccess $access,
        private readonly MedicationRuleService $rules,
        private readonly UserSiteAccessService $sites,
        private readonly WitnessPinService $pins,
        private readonly ControlledMedicationTransportWitnessService $witnesses,
        private readonly PaperAdministrationWriter $writer,
        private readonly DowntimeEvents $events,
    ) {}

    public static function orderFingerprint(ClientMedication $order): string
    {
        return PaperReconciliationRules::fingerprint($order->only([
            'id', 'client_id', 'name', 'dosage', 'dose_amount', 'dose_unit', 'route', 'version',
            'controlled_drug', 'is_prn', 'approval_status', 'verified_at', 'superseded_by',
            'dose_times', 'frequency', 'frequency_code', 'start_date', 'end_date',
            'max_per_day', 'min_hours_between_doses', 'prn_reason', 'instructions',
            'witness_required', 'high_risk', 'nzulm_code', 'active', 'state',
            'paused_at', 'ceased_at', 'superseded_at', 'self_managed',
        ]));
    }

    /** Bind matching rule revisions as well as their effective requirements. */
    public function requirementsFingerprint(ClientMedication $order): string
    {
        $locking = DB::transactionLevel() > 0;
        $requirements = $this->rules->requirementsFor($order, lockForUpdate: $locking);
        $revisions = MedicationAdminRule::query()->whereIn('id', array_column($requirements['matched_rules'], 'id'))->orderBy('id')
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'site_id', 'match_type', 'match_value', 'requires_countersign', 'required_observations', 'active', 'created_at', 'updated_at'])->toArray();

        return PaperReconciliationRules::fingerprint([$requirements, $revisions]);
    }

    public function snapshot(ClientMedication $order, string $outcome = 'given'): array
    {
        $rule = $this->rules->requirementsFor($order, lockForUpdate: DB::transactionLevel() > 0);

        return [
            'person' => $order->client->full_name, 'medicine' => $order->name,
            'dosage' => $order->dosage, 'route' => $order->route,
            'controlled' => (bool) $order->controlled_drug,
            'second_person_required' => $outcome === 'given' && ($order->requiresWitness() || $rule['requires_countersign']),
            'observation_keys' => $outcome === 'given' ? $rule['required_observations'] : [],
            'order_fingerprint' => self::orderFingerprint($order),
            'requirements_fingerprint' => $this->requirementsFingerprint($order),
            'reason_code' => PaperAdministrationWriter::reasonCode($outcome),
            'order_version' => $order->version,
        ];
    }

    /** Read-only preview. The server computes it again under locks on submission. */
    public function preview(User $actor, MedicationDowntime $downtime, array $data): array
    {
        $order = $this->access->order($actor, $downtime, (int) $data['client_medication_id']);
        $at = $this->instant((string) $data['given_at']);
        $errors = [];
        if (! PaperReconciliationRules::inside($at, $downtime->started_at->utc(), $downtime->ended_at->utc())) {
            $errors[] = 'The time on paper must be inside this downtime.';
        }
        if ($data['outcome'] !== 'given' && blank($data['notes'] ?? null)) {
            $errors[] = 'Enter the reason or explanation written on the paper for this outcome.';
        }
        abort_unless($this->access->manages($actor) || (int) $data['given_by'] === (int) $actor->id, 403);
        $giver = User::query()->findOrFail((int) $data['given_by']);
        abort_unless(in_array((int) $downtime->site_id, $this->sites->accessibleSiteIds($giver), true), 404);
        $target = isset($data['downtime_dose_id']) ? $downtime->doses()->findOrFail((int) $data['downtime_dose_id']) : null;
        if ($target !== null) {
            abort_unless((int) $target->client_medication_id === (int) $order->id && (int) $target->client_id === (int) $order->client_id, 404);
            abort_if(($target->snapshot['controlled'] ?? false) && ! $actor->canDo('medications.controlled.view'), 404);
            if (MedicationDowntimeResolution::query()->where('downtime_dose_id', $target->id)->exists()) {
                $errors[] = 'This listed dose was already resolved by a reviewed link to existing evidence.';
            }
        } else {
            abort_unless($order->is_prn, 422, 'Choose a scheduled dose listed for this downtime.');
        }
        $snapshot = $this->snapshot($order, $data['outcome']);
        if ($target !== null && ($target->snapshot['order_fingerprint'] ?? '') !== $snapshot['order_fingerprint']) {
            $errors[] = 'The order changed after this downtime was listed. The clinical lead must resolve the historical order before entry.';
        }
        $witnessId = filled($data['witness_id'] ?? null) ? (int) $data['witness_id'] : null;
        // Naming a second person always creates accountable confirmation work.
        // A voluntary attestation is not silently discarded when no rule demands it.
        $snapshot['second_person_required'] = $snapshot['second_person_required'] || $witnessId !== null;
        if ($snapshot['second_person_required'] && $witnessId === null) {
            $errors[] = 'Name the second person on the paper. They must confirm with their own witness PIN.';
        }
        if ($witnessId !== null) {
            $witness = User::query()->findOrFail($witnessId);
            abort_unless(in_array((int) $downtime->site_id, $this->sites->accessibleSiteIds($witness), true), 404);
            if ($witnessId === (int) $giver->id) {
                $errors[] = 'The giver and second person must be different people.';
            }
        }
        foreach ($snapshot['observation_keys'] as $key) {
            foreach (DoseRecordingRequirements::OBSERVATIONS[$key]['fields'] ?? [] as $field) {
                if (! filled($data['observations'][$field] ?? null)) {
                    $errors[] = 'Enter the '.$key.' reading written on the paper.';
                }
            }
        }
        $identity = PaperReconciliationRules::identity((int) $order->id, $target?->dose_slot_id, $at);
        $conflicts = $this->conflicts($order, $target?->scheduled_for, $at, $identity);
        $facts = [
            'downtime_id' => (int) $downtime->id, 'client_medication_id' => (int) $order->id,
            'downtime_dose_id' => $target?->id, 'given_at' => $at->toIso8601String(),
            'given_by' => (int) $giver->id, 'witness_id' => $witnessId,
            'outcome' => $data['outcome'], 'dose_on_paper' => $data['dose_on_paper'] ?? null,
            'notes' => $data['notes'] ?? null, 'observations' => $data['observations'] ?? [],
            'snapshot' => $snapshot, 'dose_identity' => $identity,
        ];
        $hash = hash_hmac('sha256', PaperReconciliationRules::fingerprint([$facts, $conflicts, $errors]), (string) config('app.key'));

        return [
            'preview_token' => $hash, 'facts' => $facts, 'conflicts' => $conflicts, 'errors' => $errors,
            'can_submit' => $conflicts === [] && $errors === [] && $downtime->finished_at === null,
            'giver_confirmation_needed' => (int) $giver->id !== (int) $actor->id,
            'second_person_required' => $snapshot['second_person_required'],
            'notice' => 'This collects paper facts. It does not post a dose or change stock. Confirmations and an explicit reconciliation are required.',
        ];
    }

    public function capture(User $actor, MedicationDowntime $downtime, array $data): MedicationPaperEntry
    {
        if (! in_array($data['accountable_confirmation'] ?? null, [true, 1, '1', 'yes', 'on'], true)) {
            throw ValidationException::withMessages(['accountable_confirmation' => 'Confirm that these are the actual facts on the signed paper.']);
        }

        // All mutations follow client -> order -> downtime -> paper. Global order lock serialises overlapping windows.
        return DB::transaction(function () use ($actor, $downtime, $data): MedicationPaperEntry {
            [$order, $actor] = $this->lockOrder($actor, $downtime, (int) $data['client_medication_id']);
            $downtime = MedicationDowntime::query()->whereKey($downtime->id)->lockForUpdate()->firstOrFail();
            $fingerprint = PaperReconciliationRules::fingerprint(array_diff_key($data, array_flip(['preview_token', 'accountable_confirmation'])));
            $replay = MedicationPaperEntry::query()->where('request_uuid', $data['request_uuid'])->first();
            if ($replay) {
                abort_unless((int) $replay->entered_by === (int) $actor->id && (int) $replay->downtime_id === (int) $downtime->id, 404);
                if (! hash_equals($replay->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_uuid' => 'This request was already used for different paper facts.']);
                }

                return $replay;
            }
            abort_if($downtime->finished_at !== null, 422, 'This downtime has finished; its paper facts cannot be changed.');
            $preview = $this->preview($actor, $downtime, $data);
            if (! hash_equals($preview['preview_token'], $data['preview_token'])) {
                throw ValidationException::withMessages(['preview_token' => 'The dose or paper entry changed. Preview it again before saving.']);
            }
            if (! $preview['can_submit']) {
                throw ValidationException::withMessages(['paper' => implode(' ', array_merge($preview['errors'], array_column($preview['conflicts'], 'message')))]);
            }
            $facts = $preview['facts'];
            $target = isset($data['downtime_dose_id']) ? $downtime->doses()->findOrFail((int) $data['downtime_dose_id']) : null;
            $entry = MedicationPaperEntry::query()->create([
                ...array_diff_key($facts, ['downtime_dose_id' => 1]),
                'downtime_dose_id' => $target?->id, 'client_id' => (int) $order->client_id,
                'entered_by' => (int) $actor->id, 'scheduled_for' => $target?->scheduled_for,
                'given_at' => CarbonImmutable::parse($facts['given_at'])->utc(),
                'request_uuid' => $data['request_uuid'], 'request_fingerprint' => $fingerprint,
            ]);
            if ((int) $entry->given_by === (int) $actor->id) {
                MedicationPaperConfirmation::query()->create([
                    'paper_entry_id' => $entry->id, 'kind' => 'giver', 'confirmed_by' => $actor->id,
                    'method' => 'authenticated_accountable', 'confirmed_at' => CarbonImmutable::now('UTC'),
                ]);
            }
            $this->events->record($downtime, 'paper_collected', $actor, $entry, ['giver_id' => (int) $entry->given_by, 'entered_by' => (int) $actor->id, 'paper_given_at' => $entry->given_at->toIso8601String()]);

            return $entry;
        }, 5);
    }

    public function confirm(User $actor, MedicationDowntime $downtime, MedicationPaperEntry $entry, string $kind, ?string $pin): void
    {
        DB::transaction(function () use ($actor, $downtime, $entry, $kind, $pin): void {
            [$order, $actor] = $this->lockOrder($actor, $downtime, (int) $entry->client_medication_id);
            $entry = MedicationPaperEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->access->entry($actor, $downtime, (int) $entry->id);
            abort_unless((int) $actor->id === (int) ($kind === 'giver' ? $entry->given_by : $entry->witness_id), 404);
            abort_unless($kind === 'giver' || ($entry->snapshot['second_person_required'] ?? false), 422);
            if ($entry->confirmations()->where('kind', $kind)->exists()) {
                return;
            }
            if ($kind === 'giver') {
                abort_unless($actor->canDo('medications.administer.record'), 403);
            } else {
                // Existing qualification policy at the dose's actual time; the PIN proves today's accountable attestation.
                abort_unless($this->witnesses->eligibleWitnessesForSite((int) $downtime->site_id, $entry->given_at, (int) $entry->given_by)->contains('id', $actor->id), 422,
                    'Historical witness eligibility could not be established. Ask the clinical lead to review; this dose is not posted.');
                $this->pins->verify($actor, $pin, 'witness_pin', ['site_id' => (int) $downtime->site_id, 'surface' => 'paper_confirmation'], ownPin: true);
            }
            MedicationPaperConfirmation::query()->create([
                'paper_entry_id' => $entry->id, 'kind' => $kind, 'confirmed_by' => $actor->id,
                'method' => $kind === 'giver' ? 'authenticated_accountable' : WitnessPinService::METHOD,
                'confirmed_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->events->record($downtime, $kind.'_confirmed', $actor, $entry);
        }, 5);
    }

    public function reconcile(User $actor, MedicationDowntime $downtime, MedicationPaperEntry $entry, string $previewToken): array
    {
        return DB::transaction(function () use ($actor, $downtime, $entry, $previewToken): array {
            [$order, $actor] = $this->lockOrder($actor, $downtime, (int) $entry->client_medication_id);
            $entry = MedicationPaperEntry::query()->whereKey($entry->id)->firstOrFail();
            $this->access->entry($actor, $downtime, (int) $entry->id);
            if ($posted = $entry->posting()->first()) {
                return ['success' => true, 'administration_id' => (int) $posted->administration_id, 'duplicate' => true];
            }
            $preview = $this->reconciliationPreview($actor, $downtime, $entry, $order);
            if (! hash_equals($preview['preview_token'], $previewToken)) {
                throw ValidationException::withMessages(['preview_token' => 'The record changed. Check the reconciliation preview again.']);
            }
            if (! $preview['can_reconcile']) {
                return ['success' => false, 'error' => $preview['unavailable'] ?? 'Confirmations or conflict resolution are still required.'];
            }

            return $this->writer->withinAuthority($entry, $order, $actor, function (MedicationScopeDecision $decision) use ($actor, $downtime, $entry, $previewToken): array {
                $entry = MedicationPaperEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($posted = $entry->posting()->first()) {
                    return ['success' => true, 'administration_id' => (int) $posted->administration_id, 'duplicate' => true];
                }
                $lockedPreview = $this->reconciliationPreview($decision->performer, $downtime, $entry, $decision->medication);
                if (! hash_equals($lockedPreview['preview_token'], $previewToken)) {
                    throw ValidationException::withMessages(['preview_token' => 'The record changed. Check the reconciliation preview again.']);
                }
                if (! $lockedPreview['can_reconcile']) {
                    return ['success' => false, 'error' => $lockedPreview['unavailable'] ?? 'Confirmations or conflict resolution are still required.'];
                }
                $result = $this->writer->postAuthorized($entry, $decision);
                if (! ($result['success'] ?? false)) {
                    return ['success' => false, 'error' => $result['error'] ?? 'The normal recording checks did not permit this entry. Signed paper evidence is kept.'];
                }
                MedicationPaperPosting::query()->create([
                    'paper_entry_id' => $entry->id, 'administration_id' => $result['administration']->id, 'posted_by' => $actor->id,
                ]);
                $this->events->record($downtime, 'paper_reconciled', $decision->performer, $entry, ['administration_id' => (int) $result['administration']->id]);

                return ['success' => true, 'administration_id' => (int) $result['administration']->id];
            });
        }, 5);
    }

    public function reconciliationPreview(User $actor, MedicationDowntime $downtime, MedicationPaperEntry $entry, ?ClientMedication $order = null): array
    {
        $order ??= $this->access->order($actor, $downtime, (int) $entry->client_medication_id);
        $confirmations = $entry->confirmations()->get()->keyBy('kind');
        $state = PaperReconciliationRules::state($entry->posting()->exists(), $confirmations->has('giver'), (bool) ($entry->snapshot['second_person_required'] ?? false), $confirmations->has('witness'));
        $conflicts = $this->conflicts($order, $entry->scheduled_for, $entry->given_at, $entry->dose_identity, (int) $entry->id);
        $unavailable = $this->writer->availability($entry, $order, $actor);
        $facts = [$entry->request_fingerprint, $state, $conflicts, $unavailable, self::orderFingerprint($order), $this->requirementsFingerprint($order)];

        return [
            'state' => $state, 'conflicts' => $conflicts, 'unavailable' => $unavailable,
            'can_reconcile' => $state === 'ready_to_reconcile' && $conflicts === [] && $unavailable === null,
            'preview_token' => hash_hmac('sha256', PaperReconciliationRules::fingerprint($facts), (string) config('app.key')),
        ];
    }

    /** Existing evidence is offered for explicit review, never an inferred paper outcome. */
    public function resolutionChoices(User $actor, MedicationDowntime $downtime, MedicationDowntimeDose $dose): array
    {
        $order = $this->access->order($actor, $downtime, (int) $dose->client_medication_id);
        abort_unless((int) $dose->downtime_id === (int) $downtime->id && (int) $dose->client_id === (int) $order->client_id, 404);
        abort_if(($dose->snapshot['controlled'] ?? false) && ! $actor->canDo('medications.controlled.view'), 404);
        $at = $dose->scheduled_for->utc();
        $clinical = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where('client_id', $order->client_id)
            ->where('client_medication_id', $order->id)->whereBetween('scheduled_for', [$at->startOfMinute(), $at->endOfMinute()])->get()
            ->map(fn ($record) => ['kind' => 'clinical', 'id' => (int) $record->id, 'label' => 'Existing eMAR record #'.$record->id.' — '.$record->status,
                'href' => app(MarLinkService::class)->urlFor($actor, (int) $order->client_id), 'actual_at' => $record->administered_at?->toIso8601String()])->all();
        $paper = MedicationPaperEntry::query()->where('dose_identity', 'slot:'.$dose->dose_slot_id)
            ->where('client_id', $order->client_id)->where('client_medication_id', $order->id)->get()
            ->filter(fn ($record) => $actor->canDo('medications.controlled.view') || ! ($record->snapshot['controlled'] ?? false))
            ->map(fn ($record) => ['kind' => 'paper', 'id' => (int) $record->id, 'label' => 'Existing paper entry #'.$record->id.' in DT-'.$record->downtime_id,
                'href' => '/emar/downtime/'.$record->downtime_id, 'actual_at' => $record->given_at->toIso8601String()])->all();

        return [...$clinical, ...$paper];
    }

    public function resolveDuplicate(User $actor, MedicationDowntime $downtime, MedicationDowntimeDose $dose, string $kind, int $recordId, string $reason): void
    {
        abort_unless($this->access->manages($actor), 403);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say how the signed paper was checked against this existing evidence.']);
        }
        DB::transaction(function () use ($actor, $downtime, $dose, $kind, $recordId, $reason): void {
            [$order, $actor] = $this->lockOrder($actor, $downtime, (int) $dose->client_medication_id);
            abort_unless($this->access->manages($actor), 403);
            $downtime = MedicationDowntime::query()->whereKey($downtime->id)->lockForUpdate()->firstOrFail();
            $dose = $downtime->doses()->whereKey($dose->id)->lockForUpdate()->firstOrFail();
            $choices = $this->resolutionChoices($actor, $downtime, $dose);
            abort_unless(collect($choices)->contains(fn ($choice) => $choice['kind'] === $kind && $choice['id'] === $recordId), 404);
            if ($existing = MedicationDowntimeResolution::query()->where('downtime_dose_id', $dose->id)->first()) {
                abort_unless((int) ($kind === 'clinical' ? $existing->administration_id : $existing->paper_entry_id) === $recordId, 409);

                return;
            }
            abort_if($downtime->finished_at || $downtime->entries()->where('downtime_dose_id', $dose->id)->exists(), 409, 'This paper collection item is already complete.');
            MedicationDowntimeResolution::query()->create([
                'downtime_dose_id' => $dose->id, 'administration_id' => $kind === 'clinical' ? $recordId : null,
                'paper_entry_id' => $kind === 'paper' ? $recordId : null, 'resolved_by' => $actor->id, 'reason' => $reason,
            ]);
            $this->events->record($downtime, 'duplicate_reviewed', $actor, facts: ['listed_dose_id' => (int) $dose->id, 'evidence_kind' => $kind, 'evidence_id' => $recordId], clientId: (int) $dose->client_id,
                controlled: (bool) ($dose->snapshot['controlled'] ?? false) || (bool) $order->controlled_drug);
        }, 5);
    }

    private function conflicts(ClientMedication $order, ?CarbonImmutable $scheduledFor, CarbonImmutable $at, string $identity, ?int $exceptEntry = null): array
    {
        $conflicts = [];
        $paper = MedicationPaperEntry::query()->where('dose_identity', $identity)->when($exceptEntry, fn ($q) => $q->where('id', '!=', $exceptEntry))->exists();
        if ($paper) {
            $conflicts[] = ['kind' => 'paper_duplicate', 'message' => 'This dose already has a paper entry, including entries in another downtime. Open that entry.'];
        }
        $column = $scheduledFor ? 'scheduled_for' : 'administered_at';
        $when = ($scheduledFor ?? $at)->utc();
        $clinical = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where('client_id', $order->client_id)
            ->where('client_medication_id', $order->id)->whereBetween($column, [$when->startOfMinute(), $when->endOfMinute()])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($clinical !== []) {
            $conflicts[] = ['kind' => 'clinical_record_exists', 'administration_ids' => $clinical, 'message' => 'An eMAR record already exists for this dose. Review the record; paper entry cannot replace or duplicate it.'];
        }

        return $conflicts;
    }

    private function lockOrder(User $actor, MedicationDowntime $downtime, int $id): array
    {
        $visible = $this->access->order($actor, $downtime, $id);
        $client = Client::query()->whereKey($visible->client_id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $client->site_id === (int) $downtime->site_id, 404);

        $order = ClientMedication::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail()->setRelation('client', $client);
        $current = $this->access->lockActor($actor, (int) $downtime->site_id);
        $this->access->assertOrderReadable($current, $order);

        return [$order, $current];
    }

    private function instant(string $value): CarbonImmutable
    {
        try {
            return PaperReconciliationRules::instant($value);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['given_at' => $e->getMessage()]);
        }
    }
}
