<?php

namespace App\Services\Medication\Support;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportAgreement;
use App\Models\MedicationSupportChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** P03 support authority. Call writes inside the canonical client/actor lock. */
final class MedicationSupport
{
    public const SCORES = ['cognitive_capacity', 'physical_dexterity', 'vision_ability', 'swallowing_ability', 'understanding_score'];

    public const TRIGGERS = ['hospital', 'error', 'order', 'refusals', 'asked', 'decline', 'review_date'];

    public function current(int $clientId, bool $lock = false): ?MedicationSelfAdminAssessment
    {
        $query = MedicationSelfAdminAssessment::query()->where('client_id', $clientId)
            ->whereNotIn('id', MedicationSelfAdminAssessment::withTrashed()->where('client_id', $clientId)->whereNotNull('supersedes_id')->select('supersedes_id'));
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        abort_if($rows->count() > 1, 409, 'This support record needs reconciliation before it can be used.');

        return $rows->first();
    }

    public function agreement(?MedicationSelfAdminAssessment $assessment): ?MedicationSupportAgreement
    {
        return $assessment?->support_agreement_id ? MedicationSupportAgreement::query()
            ->where('client_id', $assessment->client_id)->find($assessment->support_agreement_id) : null;
    }

    /** Unknown support is explicitly Administer; expired review dates do not remove support. */
    public function mode(ClientMedication $order, ?CarbonImmutable $at = null, bool $lock = false): string
    {
        $at ??= CarbonImmutable::now('UTC');
        $query = MedicationSupportChange::query()->where('client_medication_id', $order->id)
            ->where('client_id', $order->client_id)->where('effective_at', '<=', $at->utc())
            ->orderByDesc('effective_at')->orderByDesc('id');
        $change = ($lock ? $query->sharedLock() : $query)->first();

        return $change?->mode ?? 'staff_given';
    }

    /** Public component/payload contract for P02 and the read-only care-plan summary. */
    public function summary(Client $client, User $viewer): array
    {
        return $this->summaries(collect([$client]), $viewer)->first();
    }

    /** One bounded query set for the register; no query per person or medicine. */
    public function summaries(Collection $clients, User $viewer): Collection
    {
        if ($clients->isEmpty()) {
            return collect();
        }
        (new \Illuminate\Database\Eloquent\Collection($clients->all()))->loadMissing('site:id,name');
        $ids = $clients->pluck('id')->all();
        $assessments = MedicationSelfAdminAssessment::query()->whereIn('client_id', $ids)
            ->whereNotIn('id', MedicationSelfAdminAssessment::withTrashed()->whereIn('client_id', $ids)->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->get()->groupBy('client_id');
        $all = ClientMedication::query()->active()->whereIn('client_id', $ids)->orderBy('name')->get();
        $medicines = $all->groupBy('client_id');
        $changes = MedicationSupportChange::query()->whereIn('client_id', $ids)->whereIn('client_medication_id', $all->pluck('id'))
            ->where('effective_at', '<=', now('UTC'))
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('medication_support_changes as newer')
                ->whereColumn('newer.client_id', 'medication_support_changes.client_id')
                ->whereColumn('newer.client_medication_id', 'medication_support_changes.client_medication_id')
                ->where('newer.effective_at', '<=', now('UTC'))->where(fn ($later) => $later
                ->whereColumn('newer.effective_at', '>', 'medication_support_changes.effective_at')
                ->orWhere(fn ($equal) => $equal->whereColumn('newer.effective_at', 'medication_support_changes.effective_at')
                    ->whereColumn('newer.id', '>', 'medication_support_changes.id'))))
            ->get()->groupBy('client_id');
        $agreements = MedicationSupportAgreement::query()->whereIn('id', $assessments->flatten(1)->pluck('support_agreement_id')->filter())->get()->keyBy('id');
        $reviews = app(SupportFollowupAdapter::class)->openForClients($ids, $viewer)->groupBy('client_id');

        return $clients->map(function ($client) use ($viewer, $assessments, $medicines, $changes, $agreements, $reviews) {
            $current = $assessments->get($client->id, collect());
            abort_if($current->count() > 1, 409, 'This support record needs reconciliation before it can be used.');
            $a = $current->first();
            $agreement = $agreements->get($a?->support_agreement_id);
            if ($agreement && (int) $agreement->client_id !== (int) $client->id) {
                $agreement = null;
            }

            return $this->summaryFrom($client, $viewer, $a, $medicines->get($client->id, collect()),
                $changes->get($client->id, collect())->keyBy('client_medication_id'), $agreement, $reviews->get($client->id, collect()));
        });
    }

    private function summaryFrom(Client $client, User $viewer, ?MedicationSelfAdminAssessment $a, Collection $all, Collection $changes, ?MedicationSupportAgreement $agreement, Collection $reviews): array
    {
        $visible = $all->filter(fn ($m) => ! $m->controlled_drug || $viewer->canDo('medications.controlled.view'));
        $scope = collect($a?->med_scope ?? [])->keyBy('med_id');
        $today = now('Pacific/Auckland')->toDateString();
        $state = ! $a ? 'none' : ($reviews->isNotEmpty() ? 'reassess' : (! $a->reassessment_date ? 'unknown' : ($a->reassessment_date->toDateString() < $today ? 'overdue' : ($a->reassessment_date->toDateString() <= now('Pacific/Auckland')->addDays(30)->toDateString() ? 'soon' : 'current'))));

        return [
            'client_id' => $client->id, 'client_name' => trim($client->first_name.' '.$client->last_name),
            'site_id' => $client->site_id, 'site_name' => $client->site?->name,
            'state' => $state, 'cap' => SupportMode::cap($a?->outcome), 'assessment' => $a ? [...$a->only([
                'id', 'supersedes_id', 'outcome', 'wishes_to_self_administer', 'people_involved', ...self::SCORES,
                'can_identify_medications', 'can_read_labels', 'can_open_packaging', 'can_manage_timing', 'can_store_safely',
                'willing_to_self_admin', 'risk_factors', 'support_needed', 'support_adjustments', 'storage_location', 'safe_storage_notes',
                'assessor_notes', 'assessment_date', 'reassessment_date', 'reassessment_interval_months', 'reassessment_trigger',
            ]), 'people_involved' => $a->people_involved ?? [], 'support_adjustments' => $a->support_adjustments ?? []] : null,
            'medicines' => $visible->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'dosage' => $m->dosage, 'controlled' => (bool) $m->controlled_drug,
                'mode' => $changes->get($m->id)?->mode ?? 'staff_given', 'requested_mode' => $scope->get($m->id)['scope'] ?? null,
                'agreement_needed' => SupportMode::needsAgreement($scope->get($m->id)['scope'] ?? 'staff_given') && ! $agreement,
                'is_prn' => (bool) $m->is_prn,
            ])->values()->all(),
            'concealed_count' => $all->count() - $visible->count(),
            'agreement' => $agreement ? [...$agreement->only(['id', 'agreed_by_role', 'agreed_by_name', 'method', 'witness_id', 'ordering_responsibility', 'person_responsibilities', 'staff_responsibilities', 'storage_notes', 'created_at']), 'attachment_url' => $agreement->attachment_path ? route('emar.support.agreement.file', $agreement) : null] : null,
            'agreement_needed' => ! $agreement && $visible->contains(fn ($m) => SupportMode::needsAgreement($scope->get($m->id)['scope'] ?? 'staff_given')),
            'reviews' => $reviews->all(),
            'can_assess' => $viewer->canDo('medications.orders.manage'),
            'can_set_controlled' => $this->canSetControlled($viewer),
            'can_record_consent' => $viewer->canDo('medications.orders.manage') || $viewer->canDo('medications.administer.record'),
            'url' => route('emar.support.show', $client),
        ];
    }

    public function assess(Client $client, User $actor, array $data): MedicationSelfAdminAssessment
    {
        $prior = $this->current((int) $client->id, true);
        if (($prior?->id ?? null) !== (isset($data['supersedes_id']) ? (int) $data['supersedes_id'] : null)) {
            throw ValidationException::withMessages(['supersedes_id' => 'The support plan has changed. Reload the current assessment before saving.']);
        }
        $outcome = MedicationSelfAdminAssessment::computeOutcome((bool) $data['wishes_to_self_administer'], (bool) $data['willing_to_self_admin'], collect(self::SCORES)->sum(fn ($k) => $data[$k]));
        $assessment = new MedicationSelfAdminAssessment;
        $assessment->fill(collect($data)->except(['med_scope', 'confirm_loosening', 'confirmed_with_person', 'agreement_terms_changed'])->all());
        $termsChanged = ($data['agreement_terms_changed'] ?? false) || ($prior && (($data['storage_location'] ?? null) !== $prior->storage_location || ($data['safe_storage_notes'] ?? null) !== $prior->safe_storage_notes));
        $assessment->forceFill([
            'client_id' => $client->id, 'assessed_by' => $actor->id, 'assessment_date' => now('Pacific/Auckland')->toDateString(),
            'status' => 'completed', 'outcome' => $outcome, 'support_agreement_id' => $termsChanged ? null : $prior?->support_agreement_id,
            'reassessment_date' => now('Pacific/Auckland')->addMonthsNoOverflow($data['reassessment_interval_months'] ?? 12)->toDateString(),
        ]);
        $oldScope = collect($prior?->med_scope ?? [])->keyBy('med_id');
        $orders = ClientMedication::query()->active()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $submitted = collect($data['med_scope'] ?? []);
        $this->assertSubmitted($submitted, $orders, $actor);
        $provided = $submitted->keyBy('med_id');
        $scope = $orders->map(function ($order) use ($actor, $assessment, $oldScope, $provided) {
            $old = $oldScope->get($order->id)['scope'] ?? 'staff_given';
            if ($order->controlled_drug && ! $this->canSetControlled($actor)) {
                return ['med_id' => $order->id, 'scope' => $old];
            }
            $mode = $provided->get($order->id)['scope'] ?? (SupportMode::rank($old) >= SupportMode::rank(SupportMode::cap($assessment->outcome)) ? $old : SupportMode::cap($assessment->outcome));
            SupportMode::validate($mode, SupportMode::cap($assessment->outcome), (bool) $order->controlled_drug);

            return ['med_id' => $order->id, 'scope' => $mode];
        })->values()->all();
        foreach ($scope as $entry) {
            $order = $orders->get($entry['med_id']);
            if ($order->controlled_drug && ! $this->canSetControlled($actor)) {
                continue;
            }
            if (SupportMode::loosens($entry['scope'], $this->mode($order)) && ! ($data['confirm_loosening'] ?? false)) {
                throw ValidationException::withMessages(['confirm_loosening' => 'Confirm that this reassessment loosens staff support.']);
            }
        }
        $assessment->med_scope = $scope;
        $assessment->save();
        $agreement = $this->agreement($assessment);
        foreach ($scope as $entry) {
            $order = $orders->get($entry['med_id']);
            if ($order->controlled_drug && ! $this->canSetControlled($actor)) {
                continue;
            }
            $effective = SupportMode::needsAgreement($entry['scope']) && ! $agreement ? 'staff_given' : $entry['scope'];
            $this->change($order, $assessment, $actor, $effective, 'assessment');
        }

        return $assessment;
    }

    /** Preserve archive compatibility and immediately remove any current independence. */
    public function archive(Client $client, User $actor, MedicationSelfAdminAssessment $assessment): void
    {
        $this->assertCurrent($client, $assessment);
        $orders = ClientMedication::query()->active()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get();
        abort_if($orders->contains(fn ($m) => $m->controlled_drug) && ! $this->canSetControlled($actor), 404);
        foreach ($orders as $order) {
            $this->change($order, $assessment, $actor, 'staff_given', 'assessment_archived');
        }
        $assessment->delete();
    }

    public function setSupport(Client $client, User $actor, MedicationSelfAdminAssessment $assessment, array $entries, bool $confirm): void
    {
        $this->assertCurrent($client, $assessment);
        $orders = ClientMedication::query()->active()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $submitted = collect($entries);
        $this->assertSubmitted($submitted, $orders, $actor);
        $scope = collect($assessment->med_scope ?? [])->keyBy('med_id');
        foreach ($submitted as $entry) {
            $order = $orders->get($entry['med_id']);
            SupportMode::validate($entry['scope'], SupportMode::cap($assessment->outcome), (bool) $order->controlled_drug);
            $previous = $this->mode($order);
            // Only an unset, new medicine can loosen outside reassessment.
            if (SupportMode::loosens($entry['scope'], $previous) && $scope->has($order->id)) {
                throw ValidationException::withMessages(['med_scope' => 'More independence needs a reassessment.']);
            }
            if (SupportMode::loosens($entry['scope'], $previous) && ! $confirm) {
                throw ValidationException::withMessages(['confirm_loosening' => 'Confirm that setting this new medicine loosens staff support.']);
            }
            if (SupportMode::needsAgreement($entry['scope']) && ! $this->agreement($assessment)) {
                throw ValidationException::withMessages(['med_scope' => 'Record the agreement before choosing Self-managed or Prompt.']);
            }
            $scope->put($order->id, ['med_id' => $order->id, 'scope' => $entry['scope']]);
            $this->change($order, $assessment, $actor, $entry['scope'], 'support_changed');
        }
        $assessment->update(['med_scope' => $scope->values()->all()]);
    }

    public function consent(Client $client, User $actor, MedicationSelfAdminAssessment $assessment, array $data): void
    {
        $this->assertCurrent($client, $assessment);
        $orders = ClientMedication::query()->active()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get();
        $chosen = isset($data['client_medication_id']) ? $orders->where('id', $data['client_medication_id']) : $orders;
        abort_if($chosen->isEmpty(), 404);
        abort_if(isset($data['client_medication_id']) && $chosen->contains(fn ($m) => $m->controlled_drug) && ! $actor->canDo('medications.controlled.view'), 404);
        $scope = collect($assessment->med_scope ?? [])->keyBy('med_id');
        foreach ($chosen as $order) {
            $mode = $data['direction'] === 'less' ? 'staff_given' : $this->mode($order);
            $this->change($order, $assessment, $actor, $mode, $data['direction'] === 'less' ? 'consent_withdrawn' : 'independence_requested', $data['said'], CarbonImmutable::parse($data['occurred_at']));
            if ($data['direction'] === 'less') {
                $scope->put($order->id, ['med_id' => $order->id, 'scope' => 'staff_given']);
            }
        }
        $assessment->update(['med_scope' => $scope->values()->all()]);
        $this->trigger($assessment, 'asked', 'consent:'.MedicationSupportChange::query()->where('client_id', $client->id)->max('id'), $data['direction'] === 'less' ? 'Asked staff to do more — support lowered at once.' : 'Asked to do more themselves — support stays as it is until reassessment.');
    }

    public function recordAgreement(Client $client, User $actor, MedicationSelfAdminAssessment $assessment, array $data): MedicationSupportAgreement
    {
        $this->assertCurrent($client, $assessment);
        $agreement = MedicationSupportAgreement::create([
            ...$data, 'client_id' => $client->id, 'assessment_id' => $assessment->id, 'recorded_by' => $actor->id,
            'agreed_by_name' => $data['agreed_by_role'] === 'person' ? trim($client->first_name.' '.$client->last_name) : $data['agreed_by_name'],
            'supersedes_id' => $assessment->support_agreement_id,
        ]);
        $assessment->forceFill(['support_agreement_id' => $agreement->id])->save();
        $orders = ClientMedication::query()->active()->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($assessment->med_scope ?? [] as $entry) {
            $order = $orders->get($entry['med_id']);
            if (! $order || ($order->controlled_drug && ! $this->canSetControlled($actor))) {
                continue;
            }
            SupportMode::validate($entry['scope'], SupportMode::cap($assessment->outcome), (bool) $order->controlled_drug);
            $this->change($order, $assessment, $actor, $entry['scope'], 'agreement_recorded');
        }

        return $agreement;
    }

    /** Integration seam: approved event kinds, idempotent source identity, no inferred clinical policy. */
    public function trigger(MedicationSelfAdminAssessment $assessment, string $kind, string $sourceKey, string $reason): void
    {
        if (! in_array($kind, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException('Unknown support review trigger.');
        }
        app(SupportFollowupAdapter::class)->request($assessment, $kind, $sourceKey, $reason);
    }

    private function change(ClientMedication $order, MedicationSelfAdminAssessment $assessment, User $actor, string $mode, string $reason, ?string $notes = null, ?CarbonImmutable $occurred = null): void
    {
        MedicationSupportChange::create([
            'client_id' => $order->client_id, 'client_medication_id' => $order->id, 'assessment_id' => $assessment->id,
            'previous_mode' => $this->mode($order), 'mode' => $mode, 'reason' => $reason, 'notes' => $notes,
            'recorded_by' => $actor->id, 'occurred_at' => ($occurred ?? CarbonImmutable::now('UTC'))->utc(), 'effective_at' => now('UTC'),
        ]);
    }

    private function assertCurrent(Client $client, MedicationSelfAdminAssessment $assessment): void
    {
        abort_unless((int) $assessment->client_id === (int) $client->id, 404);
        abort_unless($this->current((int) $client->id, true)?->id === $assessment->id, 409, 'This support plan is out of date. Reload before saving.');
    }

    private function canSetControlled(User $actor): bool
    {
        return $actor->canDo('medications.controlled.view') && $actor->canDo('medications.controlled.record');
    }

    private function assertSubmitted(Collection $entries, Collection $orders, User $actor): void
    {
        if ($entries->pluck('med_id')->unique()->count() !== $entries->count()) {
            throw ValidationException::withMessages(['med_scope' => 'Each medicine may appear only once.']);
        }
        foreach ($entries as $entry) {
            $order = $orders->get($entry['med_id']);
            abort_unless($order, 404);
            abort_if($order->controlled_drug && ! $this->canSetControlled($actor), 404);
        }
    }
}
