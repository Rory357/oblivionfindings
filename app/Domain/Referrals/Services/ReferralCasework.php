<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralCasework
{
    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access) {}

    public function assign(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'site_id' => 'present|nullable|integer|min:1', 'owner_id' => 'required|integer|min:1',
            'team' => 'required|string|max:120', 'reason' => 'required|string|min:5|max:2000',
            'transfer_open_work' => 'sometimes|boolean',
        ])->validate();
        $this->command->run($actor, $id, $version, 'assign', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before changing its assignment.');
            $siteId = $data['site_id'] === null ? null : (int) $data['site_id'];
            abort_if($referral->client_id && $siteId !== $referral->site_id, 422, 'This referral is linked to a supported person. Review any site move through their canonical placement and service workflow before changing referral scope.');
            abort_unless($this->access->site($actor, $siteId, $reads), 403);
            $owner = $this->candidate((int) $data['owner_id'], $siteId, $referral->restricted, $reads);
            $previous = ['site_id' => $referral->site_id, 'owner_id' => $referral->owner_id, 'team' => $referral->team];
            $transfer = (bool) ($data['transfer_open_work'] ?? false);
            abort_if($transfer && (! $referral->owner_id || (int) $referral->owner_id === (int) $owner->id), 422, 'Choose a different named owner before transferring their open follow-ups.');
            $referral->fill(['site_id' => $siteId, 'owner_id' => $owner->id, 'team' => $data['team']]);
            // Check visibility against the resulting assignment inside the same transaction.
            // Any failed task/navigator check rolls back this save and all transfers.
            $referral->save();
            if ($siteId !== $previous['site_id'] || (int) $owner->id !== (int) $previous['owner_id']) {
                $work = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('status', 'open')->orderBy('id')->lockForUpdate()->get();
                foreach ($work as $item) {
                    if ($transfer && (int) $item->owner_id === (int) $previous['owner_id']) {
                        abort_unless($this->access->can($actor, 'tasks.manage') && $this->access->audience($actor, $item->audience, true), 403, 'A colleague with the appropriate follow-up and information permissions must review this transfer.');
                        abort_unless($this->access->can($owner, 'tasks.manage') && $this->access->audience($owner, $item->audience, true), 422, 'The new owner needs follow-up management and the appropriate information permissions for every transferred item.');
                        $item->update(['owner_id' => $owner->id, 'lock_version' => $item->lock_version + 1]);
                        $this->command->record($referral, $actor, 'work.reassigned', $item->audience, ['work_id' => $item->id, 'previous_owner_id' => $previous['owner_id'], 'owner_id' => $owner->id, 'reason' => $data['reason']]);
                    }
                    abort_unless($item->owner_id, 422, 'An open follow-up needs a named owner before this assignment can change.');
                    $staff = $this->candidate((int) $item->owner_id, $siteId, $referral->restricted, $reads);
                    abort_unless($this->access->query($staff, $reads)->whereKey($referral->id)->exists() && $this->access->audience($staff, $item->audience), 422, 'This assignment would leave an open follow-up without accessible ownership. Transfer the outgoing owner’s work or ask an authorised colleague to review the affected follow-ups first.');
                }
                $navigatorIds = ReferralFundingCase::query()->where('referral_id', $referral->id)->whereNotIn('status', ['withdrawn', 'closed'])->whereNotNull('navigator_id')->lockForUpdate()->pluck('navigator_id');
                foreach ($navigatorIds->unique()->sort() as $staffId) {
                    $staff = $this->candidate((int) $staffId, $siteId, $referral->restricted, $reads);
                    abort_unless($this->access->query($staff, $reads)->whereKey($referral->id)->exists() && $this->access->can($staff, 'funding.manage'), 422, 'Review the current funding navigator’s access before changing this assignment.');
                }
            }
            $this->command->record($referral, $actor, 'assignment.changed', 'intake', [
                'previous' => $previous, 'current' => ['site_id' => $siteId, 'owner_id' => $owner->id, 'team' => $data['team']], 'reason' => $data['reason'],
                'outgoing_work_transfer_requested' => $transfer,
            ]);
        });
    }

    public function contact(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'channel' => ['required', Rule::in(['phone', 'email', 'in_person', 'video'])],
            'outcome' => ['required', Rule::in(['no_answer', 'message_left', 'sent', 'connected', 'wrong_details', 'declined_contact'])],
            'occurred_at' => 'required|date', 'summary' => 'required|string|max:2000',
            'preference_deviation' => 'nullable|string|min:5|max:1000',
            'audience' => ['required', Rule::in(['intake', 'assessment', 'funding'])],
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'contact', function (CareReferral $referral, User $actor) use ($data): void {
            $this->open($referral);
            abort_unless($this->access->audience($actor, $data['audience'], true), 403);
            $at = ReferralDates::parse($data['occurred_at'], 'occurred_at');
            abort_if($at->isFuture() || $at->lt($referral->created_at->startOfMinute()), 422, 'Contact must be dated between receipt of this referral and now.');
            $contact = $referral->intake['submitted_contact'] ?? [];
            if ($data['outcome'] === 'message_left') {
                abort_unless(($contact['leave_message'] ?? false) || ! empty($data['preference_deviation']), 422, 'Messages are not permitted. If a message was actually left, record the deviation and follow-up so the history remains accurate.');
            }
            if (in_array($data['outcome'], ['sent', 'message_left'], true)) {
                abort_unless(ReferralAssessment::scope($referral, 'contact') || ! empty($data['preference_deviation']), 422, 'Contact permission is missing or expired. Record any actual deviation and follow-up explicitly.');
            }
            $this->next($referral, $data['next_action'], $data['next_due_at']);
            if (! $referral->first_contact_attempt_at || $at->lt($referral->first_contact_attempt_at)) {
                $referral->first_contact_attempt_at = $at;
            }
            if ($data['outcome'] === 'connected' && (! $referral->first_human_contact_at || $at->lt($referral->first_human_contact_at))) {
                $referral->first_human_contact_at = $at;
            }
            $this->command->record($referral, $actor, 'contact.recorded', $data['audience'], [
                'channel' => $data['channel'], 'outcome' => $data['outcome'], 'occurred_at' => $at->utc()->toISOString(), 'summary' => $data['summary'],
                'preference_deviation' => $data['preference_deviation'] ?? null,
                'next_action' => $data['next_action'], 'next_due_at' => $referral->next_due_at->toISOString(),
            ]);
        });
    }

    public function nextAction(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, ['next_action' => 'required|string|max:500', 'next_due_at' => 'required|date', 'reason' => 'required|string|max:2000'])->validate();
        $this->command->run($actor, $id, $version, 'tasks.manage', function (CareReferral $referral, User $actor) use ($data): void {
            $this->open($referral);
            $previous = ['next_action' => $referral->next_action, 'next_due_at' => $referral->next_due_at?->toISOString()];
            $this->next($referral, $data['next_action'], $data['next_due_at']);
            $this->command->record($referral, $actor, 'next_action.changed', 'intake', [...$data, 'previous' => $previous]);
        });
    }

    public function saveWork(User $actor, int $id, int $version, array $input, ?int $workId = null): ReferralWorkItem
    {
        $data = Validator::make($input, [
            'owner_id' => 'required|integer|min:1', 'funding_case_id' => 'present|nullable|integer|min:1',
            'kind' => ['required', Rule::in(['follow_up', 'call', 'meeting', 'assessment', 'document_request', 'funding_follow_up', 'internal_review'])],
            'audience' => ['required', Rule::in(['intake', 'assessment', 'funding'])],
            'title' => 'required|string|max:500', 'description' => 'present|nullable|string|max:2000',
            'due_at' => 'required|date', 'ends_at' => 'present|nullable|date|after:due_at',
            'make_next_action' => 'required|boolean', 'work_version' => [Rule::requiredIf($workId !== null), 'integer', 'min:1'],
        ])->validate();

        $data['funding_case_id'] = $data['funding_case_id'] === null ? null : (int) $data['funding_case_id'];

        return $this->command->run($actor, $id, $version, 'tasks.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $workId): ReferralWorkItem {
            $this->open($referral);
            abort_unless($this->access->audience($actor, $data['audience'], true), 403);
            $owner = $this->candidate((int) $data['owner_id'], $referral->site_id, $referral->restricted, $reads);
            abort_unless($this->access->query($owner, $reads)->whereKey($referral->id)->exists()
                && $this->access->audience($owner, $data['audience']), 422, 'The selected staff member cannot access this work. Assignment does not grant record access.');
            $fundingCase = null;
            if ($data['funding_case_id'] !== null) {
                abort_unless($data['audience'] === 'funding', 422, 'Funding case work must use the funding audience.');
                $fundingCase = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($data['funding_case_id']);
                abort_if(in_array($fundingCase->status, ['closed', 'withdrawn'], true), 422, 'Open a linked assistance attempt before planning more work for this pathway.');
            }
            abort_if($data['kind'] === 'funding_follow_up' && $data['funding_case_id'] === null, 422, 'Select the funding case for this follow-up.');
            $due = ReferralDates::parse($data['due_at'], 'due_at');
            $end = $data['ends_at'] ? ReferralDates::parse($data['ends_at'], 'ends_at') : null;
            abort_if($end && $end->lte($due), 422, 'The appointment must end after it starts.');
            abort_if($due->isPast(), 422, 'Choose a future follow-up date.');
            if ($workId !== null) {
                $work = ReferralWorkItem::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($workId);
                abort_unless($this->access->audience($actor, $work->audience, true), 404);
                abort_unless($work->lock_version === (int) $data['work_version'], 409, 'This follow-up has changed. Review it before saving.');
                abort_unless($work->status === 'open', 422, 'Completed or cancelled work cannot be overwritten.');
                abort_unless($work->audience === $data['audience'] && $work->funding_case_id === $data['funding_case_id'], 422, 'Keep the original audience and funding case. Record separate work for a different purpose.');
                if ($fundingCase && ($fundingCase->details['primary_work_item_id'] ?? null) === $work->id) {
                    abort_unless((int) $owner->id === (int) $fundingCase->navigator_id, 422, 'Use Change navigator to transfer the primary funding action and case accountability together.');
                }
            } else {
                $work = new ReferralWorkItem(['referral_id' => $referral->id, 'lock_version' => 0]);
            }
            $previous = $workId ? [
                'details' => $work->details, 'owner_id' => $work->owner_id,
                'due_at' => $work->due_at->toISOString(), 'ends_at' => $work->ends_at?->toISOString(),
            ] : null;
            $work->fill([
                'owner_id' => $owner->id, 'funding_case_id' => $data['funding_case_id'], 'kind' => $data['kind'],
                'audience' => $data['audience'], 'details' => ['title' => $data['title'], 'description' => $data['description']],
                'due_at' => $due, 'ends_at' => $end, 'lock_version' => $work->lock_version + 1,
            ])->save();
            if ($fundingCase && ($fundingCase->details['primary_work_item_id'] ?? null) === $work->id) {
                $fundingCase->update(['lock_version' => $fundingCase->lock_version + 1, 'details' => [...$fundingCase->details, 'next_action' => $data['title'], 'next_due_at' => $due->toISOString()]]);
            }
            if ($data['make_next_action'] || $referral->primary_work_item_id === $work->id) {
                abort_unless($data['audience'] === 'intake', 422, 'Use a general next action; restricted task details cannot appear in the intake queue.');
                $referral->fill(['next_action' => $data['title'], 'next_due_at' => $due, 'primary_work_item_id' => $work->id]);
            }
            $this->command->record($referral, $actor, $workId ? 'work.updated' : 'work.created', $data['audience'], [
                'work_id' => $work->id, 'work_version' => $work->lock_version, 'owner_id' => $owner->id,
                'title' => $data['title'], 'due_at' => $due->utc()->toISOString(),
                'details' => $work->details, 'ends_at' => $work->ends_at?->toISOString(), 'previous' => $previous,
            ]);

            return $work;
        });
    }

    public function finishWork(User $actor, int $id, int $version, int $workId, array $input): void
    {
        $data = Validator::make($input, [
            'work_version' => 'required|integer|min:1', 'outcome' => ['required', Rule::in(['completed', 'cancelled'])],
            'note' => 'required|string|min:3|max:2000', 'next_action' => 'nullable|string|max:500', 'next_due_at' => 'nullable|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'tasks.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($workId, $data): void {
            $work = ReferralWorkItem::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($workId);
            abort_unless($this->access->audience($actor, $work->audience, true), 404);
            abort_unless($work->lock_version === (int) $data['work_version'], 409, 'This follow-up has changed.');
            abort_unless($work->status === 'open', 422, 'This follow-up is already finished.');
            if ($referral->primary_work_item_id === $work->id) {
                abort_unless(! empty($data['next_action']) && ! empty($data['next_due_at']), 422, 'Set the next action and date before finishing the referral’s current follow-up.');
                $this->next($referral, $data['next_action'], $data['next_due_at']);
            }
            $work->update([
                'status' => $data['outcome'], 'lock_version' => $work->lock_version + 1,
                'details' => [...$work->details, 'finished_at' => now()->toISOString(), 'finished_by' => $actor->id, 'outcome_note' => $data['note']],
            ]);
            if ($work->funding_case_id) {
                $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($work->funding_case_id);
                if (($case->details['primary_work_item_id'] ?? null) === $work->id && ! in_array($case->status, ['withdrawn', 'closed'], true)) {
                    abort_unless(! empty($data['next_action']) && ! empty($data['next_due_at']), 422, 'Record a dated next action for the open funding case.');
                    $next = app(ReferralFunding::class)->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
                    $case->update(['lock_version' => $case->lock_version + 1, 'details' => [...$case->details, 'primary_work_item_id' => $next->id]]);
                }
            }
            $this->command->record($referral, $actor, 'work.'.$data['outcome'], $work->audience, ['work_id' => $work->id, 'note' => $data['note']]);
        });
    }

    private function candidate(int $id, ?int $siteId, bool $restricted, CurrentAuthorizationReads $reads): User
    {
        $user = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($id, array_column(ReferralPermissions::definitions(), 'key'));
        abort_unless($this->access->eligibleForSite($user, $siteId, $reads)
            && (! $restricted || $this->access->can($user, 'restricted')), 422, 'Choose current approved staff with access to this site and referral.');

        return $user;
    }

    private function next(CareReferral $referral, string $action, string $due): void
    {
        $date = ReferralDates::parse($due, 'next_due_at');
        abort_if($date->isPast(), 422, 'Choose a future date for the next action.');
        $referral->fill(['next_action' => $action, 'next_due_at' => $date, 'primary_work_item_id' => null]);
    }

    private function open(CareReferral $referral): void
    {
        abort_if($referral->stage === 'closed', 422, 'Reopen this referral before adding new work.');
    }
}
