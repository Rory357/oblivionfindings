<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\Client;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralLifecycle
{
    public const WORK_STAGES = ['triage', 'assessment', 'funding_assistance'];

    /** Reached only when Gate A readiness passes; a later prerequisite change moves it back. */
    public const READY = 'ready_for_handover';

    public const HOLD_REASONS = ['awaiting_person', 'awaiting_evidence', 'awaiting_assessment', 'awaiting_funding', 'capacity_waitlist', 'person_requested_pause'];

    public const CLOSE_REASONS = ['information_only', 'person_withdrew', 'another_provider', 'outside_scope', 'unable_to_contact', 'duplicate', 'test', 'spam', 'other'];

    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access) {}

    public function move(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'stage' => ['required', Rule::in([...self::WORK_STAGES, self::READY])], 'reason' => 'required|string|min:5|max:2000',
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'transition', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            $this->working($referral);
            $this->owner($referral, $reads);
            abort_if($referral->general_enquiry && $data['stage'] !== 'triage', 422, 'Review collection authority and complete the person’s intake before progressing a process enquiry.');
            abort_if($referral->stage === $data['stage'], 422, 'The referral is already at this stage.');
            $ready = $data['stage'] === self::READY;
            $configuration = app(ReferralWorkflow::class)->configuration($referral);
            $configured = collect(($configuration['workflow'] ?? ReferralWorkflow::defaults())['transitions'])
                ->contains(fn ($rule) => $rule['from'] === $referral->stage && $rule['to'] === $data['stage']);
            // Readiness is the gate for ready_for_handover; a configured rule may add role or evidence requirements.
            $workflowEvidence = ! $ready || $configured ? app(ReferralWorkflow::class)->enforce($actor, $referral, $data['stage'], $reads) : [];
            $previous = $referral->stage;
            $labels = array_column($configuration['stages'], 'label', 'id');
            $this->next($referral, $data);
            if ($ready) {
                abort_unless(in_array($referral->stage, self::WORK_STAGES, true), 422, 'Move the referral to a working stage before marking it ready for handover.');
                $unready = $this->unreadyChecks($actor, $referral, $reads);
                abort_if($unready !== [], 422, 'Complete the handover readiness checks first: '.implode('; ', $unready).'.');
            }
            $referral->fill(['stage' => $data['stage'], 'stage_changed_at' => now()]);
            foreach ($workflowEvidence as $audience => $documents) {
                $this->command->record($referral, $actor, 'workflow.evidence_checked', $audience, ['settings_version_id' => $referral->settings_version_id, 'stage' => $data['stage'], 'documents' => $documents]);
            }
            $this->command->record($referral, $actor, 'stage.changed', 'intake', [
                'previous_stage' => $previous, 'stage' => $data['stage'], 'reason' => $data['reason'], 'settings_version_id' => $referral->settings_version_id,
                'previous_stage_label' => $labels[$previous] ?? $previous, 'stage_label' => $labels[$data['stage']] ?? $data['stage'],
                'next_action' => $data['next_action'], 'next_due_at' => $referral->next_due_at->toISOString(),
                ...($ready ? ['readiness_checked' => true] : []),
            ]);
        });
    }

    /**
     * Gate A readiness from the existing handover preview. The actor's own person-creation
     * permission is checked again at handover, so it does not decide whether the case is ready.
     *
     * @return list<string>
     */
    private function unreadyChecks(User $actor, CareReferral $referral, CurrentAuthorizationReads $reads): array
    {
        $target = self::internal($referral) ? (int) $referral->client_id : null;
        $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral, $target, $reads);

        return collect($preview['checks'])->reject(fn (array $check) => $check['passed'] || $check['key'] === 'canonical_permission')
            ->pluck('label')->values()->all();
    }

    /** Hold and close reasons staff may choose under the published settings (built-ins when none). */
    private function choiceKeys(string $list): array
    {
        return ReferralSettings::enabledChoiceKeys(app(ReferralSettings::class)->effective()?->configuration, $list);
    }

    public function hold(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'hold_reason' => ['present', 'nullable', Rule::in($this->choiceKeys('hold'))], 'reason' => 'required|string|min:5|max:2000',
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'transition', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            $this->working($referral);
            $this->owner($referral, $reads);
            abort_if($referral->hold_reason === $data['hold_reason'], 422, 'The hold reason has not changed. Update its next review instead.');
            $previous = $referral->hold_reason;
            $this->next($referral, $data);
            // Waiting is independent of progress. Do not reset stage time or historical contact measures.
            $referral->hold_reason = $data['hold_reason'];
            $this->command->record($referral, $actor, $data['hold_reason'] ? 'hold.recorded' : 'hold.released', 'intake', [
                ...$data, 'previous_hold_reason' => $previous, 'next_due_at' => $referral->next_due_at->toISOString(),
            ]);
        });
    }

    /**
     * Triage priority: one of the priorities enabled in the published settings, with a reason. It orders
     * work only, so stage, waiting period, next action and every contact or stage clock stay as they are.
     * Anyone who works the referral may set it (Stephan, 21 Oct 2026): those who can edit it (intake and
     * coordinators) or move it between stages, always within their site and record scope.
     */
    public function priority(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'priority' => ['required', 'string', Rule::in($this->choiceKeys('priority'))],
            'reason' => 'required|string|min:5|max:2000',
        ])->validate();
        // The command re-checks the chosen action under the record and permission locks.
        $action = ! $this->access->can($actor, 'edit') && $this->access->can($actor, 'transition') ? 'transition' : 'edit';
        $this->command->run($actor, $id, $version, $action, function (CareReferral $referral, User $actor) use ($data): void {
            $this->working($referral);
            abort_if($referral->priority === $data['priority'], 422, 'The referral already has this priority.');
            $configuration = app(ReferralSettings::class)->effective()?->configuration;
            $previous = $referral->priority;
            $referral->priority = $data['priority'];
            $this->command->record($referral, $actor, 'priority.changed', 'intake', [
                'previous_priority' => $previous, 'priority' => $data['priority'], 'reason' => $data['reason'],
                'previous_priority_label' => ReferralSettings::choiceLabel($configuration, 'priority', $previous),
                'priority_label' => ReferralSettings::choiceLabel($configuration, 'priority', $data['priority']),
            ]);
        });
    }

    public function close(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'closure_reason' => ['required', Rule::in($this->choiceKeys('close'))], 'reason' => 'required|string|min:5|max:2000',
            'person_informed' => 'required|boolean', 'communication_note' => 'required|string|min:5|max:2000',
            'cancel_open_work' => 'required|boolean',
            'duplicate_reference' => 'exclude_unless:closure_reason,duplicate|required|ulid',
            'duplicate_version' => 'exclude_unless:closure_reason,duplicate|required|integer|min:1',
            'duplicate_basis' => 'exclude_unless:closure_reason,duplicate|required|string|min:20|max:2000',
            'same_enquiry_confirmed' => 'exclude_unless:closure_reason,duplicate|required|accepted',
            'test_entry_confirmed' => 'exclude_unless:closure_reason,test|required|accepted',
        ])->validate();
        $this->command->run($actor, $id, $version, 'close', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            $this->working($referral);
            if ($data['closure_reason'] === 'duplicate') {
                app(ReferralDuplicateClosure::class)->record($actor, $referral, $data, $reads);
            }
            abort_if(ReferralFundingCase::query()->where('referral_id', $referral->id)->whereNotIn('status', ['withdrawn', 'closed'])->exists(), 422, 'Resolve or withdraw the open funding cases before closing the referral. Funding history will be retained.');
            $work = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('status', 'open')->lockForUpdate()->get();
            if ($work->isNotEmpty()) {
                abort_unless($data['cancel_open_work'] && $this->access->can($actor, 'tasks.manage'), 422, 'Review and explicitly cancel the remaining follow-ups before closing.');
                foreach ($work as $item) {
                    abort_unless($this->access->audience($actor, $item->audience, true), 422, 'A responsible colleague must resolve restricted follow-ups before this referral can close.');
                    $item->update(['status' => 'cancelled', 'lock_version' => $item->lock_version + 1,
                        'details' => [...$item->details, 'outcome_note' => 'Referral closed: '.$data['reason'], 'finished_at' => now()->toISOString(), 'finished_by' => $actor->id]]);
                    $this->command->record($referral, $actor, 'work.cancelled', $item->audience, ['work_id' => $item->id, 'note' => 'Cancelled with referral closure.']);
                }
            }
            $previous = $referral->stage;
            $referral->fill(['stage' => 'closed', 'closure_reason' => $data['closure_reason'], 'closed_at' => now(), 'stage_changed_at' => now(),
                'hold_reason' => null, 'next_action' => '', 'next_due_at' => null, 'primary_work_item_id' => null]);
            $audit = array_diff_key($data, array_flip(['duplicate_reference', 'duplicate_version', 'duplicate_basis', 'same_enquiry_confirmed']));
            $this->command->record($referral, $actor, 'referral.closed', 'intake', [...$audit, 'duplicate_reviewed' => $data['closure_reason'] === 'duplicate', 'previous_stage' => $previous, 'cancelled_work_count' => $work->count()]);
        });
    }

    public function reopen(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, ['reason' => 'required|string|min:5|max:2000', 'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date'])->validate();
        $this->command->run($actor, $id, $version, 'reopen', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            abort_unless($referral->stage === 'closed' && ! $referral->converted_at && (! $referral->client_id || self::internal($referral)), 422, 'Only an unconverted closed referral can be reopened.');
            abort_if(ReferralRetention::anonymised($referral), 422, 'This referral’s details were removed under the retention policy, so it cannot be reopened. Record a new referral instead.');
            if (self::internal($referral)) {
                // One open preparation per person, site and service, as when it was opened.
                abort_unless(Client::query()->whereKey($referral->client_id)->lockForUpdate()->exists(), 422, 'The person record is no longer available. Prepare support from their current record instead.');
                abort_if(CareReferral::query()->whereKeyNot($referral->id)->where('client_id', $referral->client_id)->where('entry_kind', InternalSupportPreparation::KIND)
                    ->where('site_id', $referral->site_id)->where('requested_service', $referral->requested_service)->whereNotIn('stage', ['closed', 'converted'])
                    ->sharedLock()->exists(), 409, 'Support preparation is already open for this person, site and service. Continue the existing case instead.');
            }
            // Team intake can recover an unassigned case; an obsolete owner must be replaced after reopening.
            if ($referral->owner_id) {
                $owner = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($referral->owner_id, array_column(ReferralPermissions::definitions(), 'key'));
                if (! $this->access->eligibleForSite($owner, $referral->site_id, $reads) || ($referral->restricted && ! $this->access->can($owner, 'restricted'))) {
                    $referral->owner_id = null;
                }
            }
            $previous = ['closure_reason' => $referral->closure_reason, 'closed_at' => $referral->closed_at?->toISOString()];
            app(ReferralDuplicateClosure::class)->reopened($referral);
            $this->next($referral, $data);
            $referral->fill(['stage' => $referral->owner_id ? 'triage' : 'new', 'closed_at' => null, 'closure_reason' => null, 'stage_changed_at' => now()]);
            $this->command->record($referral, $actor, 'referral.reopened', 'intake', [...$data, 'previous' => $previous, 'stage' => $referral->stage, 'next_due_at' => $referral->next_due_at->toISOString()]);
        });
    }

    private function working(CareReferral $referral): void
    {
        // An internal preparation is linked to its existing person from the start; only a
        // completed handover protects it. A genuine referral with a linked person is a conversion.
        abort_if(in_array($referral->stage, ['closed', 'converted'], true) || $referral->converted_at
            || ($referral->client_id && ! self::internal($referral)), 422, 'Closed or converted referrals cannot be progressed as a new referral.');
    }

    private static function internal(CareReferral $referral): bool
    {
        return $referral->entry_kind === InternalSupportPreparation::KIND;
    }

    private function owner(CareReferral $referral, CurrentAuthorizationReads $reads): void
    {
        abort_unless($referral->owner_id, 422, 'Assign a named owner before progressing or placing this referral on hold.');
        $owner = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($referral->owner_id, array_column(ReferralPermissions::definitions(), 'key'));
        abort_unless($this->access->eligibleForSite($owner, $referral->site_id, $reads)
            && (! $referral->restricted || $this->access->can($owner, 'restricted')), 422, 'Assign a current eligible owner before progressing this referral.');
    }

    private function next(CareReferral $referral, array $data): void
    {
        $due = ReferralDates::parse($data['next_due_at'], 'next_due_at');
        abort_if($due->isPast(), 422, 'Choose a future next action or hold review date.');
        $referral->fill(['next_action' => $data['next_action'], 'next_due_at' => $due, 'primary_work_item_id' => null]);
    }
}
