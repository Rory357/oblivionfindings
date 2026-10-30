<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralFunding
{
    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access) {}

    /**
     * Whether the case is a private-payer arrangement rather than external funding. Uses the
     * pathway configuration snapshotted on the case (`payer_kind`), falling back to the default
     * template's `private` pathway until published settings carry an explicit payer kind.
     */
    public static function privatePayer(ReferralFundingCase $case): bool
    {
        $kind = $case->details['guide']['payer_kind'] ?? null;

        return $kind !== null ? $kind === 'private' : $case->pathway === 'private';
    }

    public function open(User $actor, int $id, int $version, array $input): ReferralFundingCase
    {
        $data = Validator::make($input, [
            'pathway' => 'required|string|max:48', 'navigator_id' => 'required|integer|min:1', 'previous_case_id' => 'present|nullable|integer|min:1',
            'service_id' => 'present|nullable|string|max:64', 'starting_point' => 'required|string|min:5|max:4000',
            'assistance_requested' => 'required|string|min:5|max:2000', 'person_review_plan' => 'required|string|min:5|max:2000',
            'provider_role_explained' => 'required|accepted', 'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();

        return $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): ReferralFundingCase {
            $this->canAssist($referral);
            $settings = app(ReferralSettings::class)->effective();
            $pathway = collect($settings?->configuration['pathways'] ?? [])->firstWhere('id', $data['pathway']);
            abort_unless($pathway && $pathway['enabled'], 422, 'Choose an enabled published funding pathway.');
            if ($pathway['personal_income']) {
                abort_unless($data['service_id'] === null, 422, 'Personal income assistance is separate from provider service funding.');
            } else {
                abort_unless(in_array($data['service_id'], $pathway['service_ids'], true), 422, 'This pathway is not configured for the requested service.');
            }
            $navigator = $this->navigator((int) $data['navigator_id'], $referral, $reads);
            if ($data['previous_case_id']) {
                $previous = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($data['previous_case_id']);
                abort_unless($previous->pathway === $data['pathway'], 422, 'A review attempt must retain its original pathway. Open a separate case for an alternative pathway.');
                abort_unless(in_array($previous->status, ['decision_received', 'withdrawn', 'closed'], true), 422, 'Finish the previous attempt with a recorded decision, or withdraw it, before opening a linked review attempt.');
                abort_if(ReferralFundingCase::query()->where('referral_id', $referral->id)->where('previous_case_id', $previous->id)
                    ->whereNotIn('status', ['withdrawn', 'closed'])->lockForUpdate()->exists(), 422, 'A review attempt is already open for that funding case. Continue the existing attempt.');
            }
            $case = ReferralFundingCase::query()->create([
                'referral_id' => $referral->id, 'navigator_id' => $navigator->id, 'settings_version_id' => $settings->id,
                'previous_case_id' => $data['previous_case_id'], 'pathway' => $pathway['id'], 'personal_income' => $pathway['personal_income'],
                'status' => 'exploring', 'lock_version' => 1,
                'details' => ['service_id' => $data['service_id'], 'starting_point' => $data['starting_point'], 'assistance_requested' => $data['assistance_requested'],
                    'person_review_plan' => $data['person_review_plan'], 'provider_role_explained' => true, 'authority_version' => $referral->authority['review']['version'],
                    'pathway_label' => $pathway['label'], 'guide' => $pathway, 'roles' => [], 'preparation' => [], 'submission_count' => 0],
            ]);
            $work = $this->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            $case->update(['details' => [...$case->details, 'primary_work_item_id' => $work->id]]);
            $this->command->record($referral, $actor, 'funding.opened', 'funding', ['case_id' => $case->id, 'pathway' => $pathway['label'], 'navigator_id' => $navigator->id, 'previous_case_id' => $data['previous_case_id'], 'summary' => $data['assistance_requested']]);

            return $case;
        });
    }

    /** Accountability can be restored while assistance authority is suspended. */
    public function reassign(User $actor, int $id, int $version, int $caseId, array $input): void
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'navigator_id' => 'required|integer|min:1',
            'reason' => 'required|string|min:5|max:2000', 'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($caseId, $data): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before reassigning funding assistance.');
            $case = $this->case($referral, $caseId, (int) $data['case_version']);
            $this->openCase($case);
            $navigator = $this->navigator((int) $data['navigator_id'], $referral, $reads);
            $previous = $case->navigator_id;
            $case->navigator_id = $navigator->id;
            $work = $this->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            // Transfer outstanding funding work only when it belonged to the outgoing navigator.
            // Named assignments to other eligible staff are retained and remain separately reviewable.
            $otherWork = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('funding_case_id', $case->id)
                ->where('status', 'open')->where('owner_id', $previous)->where('id', '!=', $work->id)->lockForUpdate()->get();
            abort_if($otherWork->isNotEmpty() && ! $this->access->can($actor, 'tasks.manage'), 403, 'Task-management permission is needed to transfer the outgoing navigator’s other follow-ups.');
            foreach ($otherWork as $item) {
                $item->update(['owner_id' => $navigator->id, 'lock_version' => $item->lock_version + 1]);
                $this->command->record($referral, $actor, 'work.reassigned', 'funding', ['work_id' => $item->id, 'previous_owner_id' => $previous, 'owner_id' => $navigator->id, 'reason' => $data['reason']]);
            }
            $case->fill(['lock_version' => $case->lock_version + 1, 'details' => [...$case->details, 'primary_work_item_id' => $work->id]])->save();
            $this->command->record($referral, $actor, 'funding.navigator_changed', 'funding', ['case_id' => $case->id, 'previous_navigator_id' => $previous,
                'navigator_id' => $navigator->id, 'reason' => $data['reason'], 'authority_changed' => false]);
        });
    }

    public function prepare(User $actor, int $id, int $version, int $caseId, array $input): void
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'navigator_id' => 'required|integer|min:1',
            'roles' => 'required|array:funding_body,assessor,host,contracting_party,invoice_payer',
            'roles.*' => 'present|nullable|string|max:300',
            'external_contact' => 'required|array:name,email,phone', 'external_contact.name' => 'present|nullable|string|max:160',
            'external_contact.email' => 'present|nullable|email:rfc|max:254', 'external_contact.phone' => 'present|nullable|string|max:50',
            'preparation' => 'present|array|max:40', 'preparation.*' => 'required|array:item,status,note,evidence_ids',
            'preparation.*.item' => 'required|string|max:200|distinct', 'preparation.*.status' => ['required', Rule::in(['needed', 'requested', 'received', 'checked', 'not_applicable'])],
            'preparation.*.note' => 'required|string|min:3|max:1000', 'preparation.*.evidence_ids' => 'present|array|max:20', 'preparation.*.evidence_ids.*' => 'integer|min:1|distinct',
            'blocker' => 'present|nullable|string|max:1000', 'reason' => 'required|string|min:5|max:2000',
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $caseId): void {
            $this->canAssist($referral);
            $case = $this->case($referral, $caseId, (int) $data['case_version']);
            $this->openCase($case);
            $navigator = $this->navigator((int) $data['navigator_id'], $referral, $reads);
            abort_unless((int) $case->navigator_id === (int) $navigator->id, 422, 'Use Change navigator to transfer accountability and outstanding follow-ups.');
            foreach ($data['preparation'] as $item) {
                $this->evidence($referral, $item['evidence_ids'], $item['status'] === 'checked');
                abort_if($item['status'] === 'checked' && $item['evidence_ids'] === [], 422, 'Link source evidence for a checked preparation item.');
            }
            $previous = ['navigator_id' => $case->navigator_id, 'roles' => $case->details['roles'] ?? [], 'preparation' => $case->details['preparation'] ?? [], 'blocker' => $case->details['blocker'] ?? null];
            $case->navigator_id = $navigator->id;
            $work = $this->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            $case->fill(['status' => $case->status === 'exploring' ? 'preparing' : $case->status, 'lock_version' => $case->lock_version + 1,
                'details' => [...$case->details, 'roles' => $data['roles'], 'external_contact' => $data['external_contact'], 'preparation' => $data['preparation'],
                    'blocker' => $data['blocker'], 'primary_work_item_id' => $work->id]])->save();
            $this->command->record($referral, $actor, 'funding.preparation_updated', 'funding', ['case_id' => $case->id, 'previous' => $previous, 'current' => $data, 'reason' => $data['reason']]);
        });
    }

    public function submission(User $actor, int $id, int $version, int $caseId, array $input): void
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'destination' => 'required|string|min:3|max:300',
            'channel' => ['required', Rule::in(['agency_portal', 'email', 'post', 'in_person', 'phone'])],
            'submitted_at' => 'required|date', 'external_reference' => 'present|nullable|string|max:160', 'acknowledgement' => 'sometimes|nullable|string|min:5|max:2000',
            'submitted_by' => ['required', Rule::in(['person', 'staff', 'other_professional'])],
            'person_reviewed' => 'required|accepted', 'evidence_ids' => 'required|array|min:1|max:40', 'evidence_ids.*' => 'integer|min:1|distinct',
            'authority_version' => 'required|integer|min:1', 'sharing_index' => 'present|nullable|integer|min:0|max:19',
            'authority_deviation' => 'present|nullable|string|min:5|max:2000',
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $caseId): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen this referral before recording new application work.');
            $case = $this->case($referral, $caseId, (int) $data['case_version']);
            $this->openCase($case);
            abort_unless(in_array($case->status, ['exploring', 'preparing', 'submitted', 'awaiting_assessment', 'awaiting_decision'], true), 422, 'Open a linked review attempt after a decision.');
            $at = ReferralDates::parse($data['submitted_at'], 'submitted_at');
            abort_if($at->isFuture() || $at->lt($referral->created_at->startOfMinute()), 422, 'Use the actual external submission time, between referral receipt and now.');
            $this->evidence($referral, $data['evidence_ids'], true);
            $authority = $referral->authority['review'] ?? [];
            $authorized = ReferralAssessment::scope($referral, 'assist') && ($authority['version'] ?? 0) === (int) $data['authority_version'];
            if ($data['submitted_by'] === 'staff') {
                $permission = $authority['sharing'][$data['sharing_index'] ?? -1] ?? null;
                $authorized = $authorized && $this->access->can($actor, 'disclose') && $permission
                    && in_array($permission, ReferralAssessment::currentSharing($referral), true)
                    && mb_strtolower(trim($permission['recipient'])) === mb_strtolower(trim($data['destination']));
            }
            abort_unless($authorized || ! empty($data['authority_deviation']), 422, 'Current assistance/sharing authority does not cover this submission. Record any actual deviation explicitly; this screen never sends an application.');
            $guide = $case->details['guide'] ?? [];
            $prepared = collect($case->details['preparation'] ?? []);
            foreach ($guide['evidence_checklist'] ?? [] as $item) {
                abort_unless($prepared->contains(fn ($row) => $row['item'] === $item && in_array($row['status'], ['checked', 'not_applicable'], true)), 422, 'Complete or explain each required pathway preparation item before recording submission.');
            }
            $number = (int) ($case->details['submission_count'] ?? 0) + 1;
            // Prepared, submitted and acknowledged are distinct: an agency acknowledgement may arrive later.
            $data['acknowledgement'] ??= null;
            $submission = [...$data, 'acknowledged_at' => $data['acknowledgement'] === null ? null : now()->toISOString(), 'submitted_at' => $at->toISOString(), 'number' => $number, 'recorded_by' => $actor->id, 'recorded_at' => now()->toISOString(), 'authority_current_when_recorded' => (bool) $authorized];
            $work = $this->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            $case->update(['status' => 'submitted', 'lock_version' => $case->lock_version + 1,
                'details' => [...$case->details, 'last_submission' => $submission, 'submission_count' => $number, 'primary_work_item_id' => $work->id]]);
            $this->command->record($referral, $actor, 'funding.submission_recorded', 'funding', ['case_id' => $case->id, 'submission' => $submission, 'summary' => 'External submission recorded; no application was sent by Oblivion Care.']);
        });
    }

    public function progress(User $actor, int $id, int $version, int $caseId, array $input): void
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['preparing', 'awaiting_assessment', 'awaiting_decision', 'withdrawn', 'closed'])],
            'reason' => 'required|string|min:5|max:2000', 'person_outcome' => 'required|string|min:5|max:2000',
            'cancel_open_work' => 'required|boolean', 'next_action' => 'nullable|string|max:500', 'next_due_at' => 'nullable|date',
            'acknowledgement' => 'sometimes|nullable|string|min:5|max:2000',
        ])->validate();
        $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $caseId): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before changing funding work.');
            $case = $this->case($referral, $caseId, (int) $data['case_version']);
            $this->openCase($case);
            $terminal = in_array($data['status'], ['withdrawn', 'closed'], true);
            if (! $terminal) {
                $this->canAssist($referral);
                abort_if($case->status === 'decision_received', 422, 'Create a linked review attempt to revisit an external decision.');
                abort_if($data['status'] !== 'preparing' && empty($case->details['last_submission']), 422, 'Record the external submission before moving to an external waiting stage.');
                abort_unless(! empty($data['next_action']) && ! empty($data['next_due_at']), 422, 'Record the next action and due date.');
                $work = $this->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            } else {
                abort_if($data['status'] === 'closed' && $case->status !== 'decision_received', 422, 'Record the external decision before completing the case, or withdraw assistance with a reason.');
                $items = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('funding_case_id', $case->id)->where('status', 'open')->lockForUpdate()->get();
                abort_if($items->isNotEmpty() && (! $data['cancel_open_work'] || ! $this->access->can($actor, 'tasks.manage')), 422, 'Resolve the funding follow-ups or explicitly cancel them with task-management permission.');
                foreach ($items as $item) {
                    $item->update(['status' => 'cancelled', 'lock_version' => $item->lock_version + 1, 'details' => [...$item->details, 'finished_at' => now()->toISOString(), 'finished_by' => $actor->id, 'outcome_note' => $data['reason']]]);
                    $this->command->record($referral, $actor, 'work.cancelled', 'funding', ['work_id' => $item->id, 'case_id' => $case->id, 'note' => $data['reason']]);
                }
            }
            $previous = $case->status;
            $details = [...$case->details, 'person_outcome' => $data['person_outcome'], 'primary_work_item_id' => $terminal ? null : $work->id];
            if (filled($data['acknowledgement'] ?? null)) {
                abort_if(empty($case->details['last_submission']), 422, 'Record the external submission before its acknowledgement.');
                abort_if(filled($case->details['last_submission']['acknowledgement'] ?? null), 422, 'This submission already has a recorded acknowledgement.');
                $details['last_submission'] = [...$case->details['last_submission'], 'acknowledgement' => $data['acknowledgement'],
                    'acknowledged_at' => now()->toISOString(), 'acknowledgement_recorded_by' => $actor->id];
            }
            $case->update(['status' => $data['status'], 'lock_version' => $case->lock_version + 1, 'details' => $details]);
            $this->command->record($referral, $actor, 'funding.progress_changed', 'funding', ['case_id' => $case->id, 'previous_status' => $previous, ...$data]);
        });
    }

    public function case(CareReferral $referral, int $id, int $version): ReferralFundingCase
    {
        $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($id);
        abort_unless($case->lock_version === $version, 409, 'This funding case changed. Review its current version before saving.');

        return $case;
    }

    public function evidence(CareReferral $referral, array $ids, bool $available): void
    {
        $query = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', 'funding')->whereIn('id', $ids);
        $rows = $query->lockForUpdate()->get();
        abort_unless($rows->count() === count($ids), 404);
        foreach ($rows as $row) {
            abort_unless($available ? $row->state === 'available' : in_array($row->state, ['available', 'quarantined'], true), 422, 'Selected funding evidence is not available for this action.');
            abort_if(! empty($row->metadata['expires_at']) && CarbonImmutable::parse($row->metadata['expires_at'])->isPast(), 422, 'Selected funding evidence has expired.');
            abort_if(! empty($row->metadata['superseded_by_id']), 422, 'Select the latest version of the funding evidence.');
            abort_if(app(ReferralEvidenceFiles::class)->bytes($row) === null, 422, 'Selected funding evidence is missing or its integrity could not be verified.');
        }
    }

    public function next(CareReferral $referral, ReferralFundingCase $case, User $actor, CurrentAuthorizationReads $reads, string $title, string $date): ReferralWorkItem
    {
        $this->navigator((int) $case->navigator_id, $referral, $reads);
        $due = ReferralDates::parse($date, 'next_due_at');
        abort_if($due->isPast(), 422, 'Choose a future funding follow-up date.');
        $work = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('funding_case_id', $case->id)->where('status', 'open')
            ->whereKey($case->details['primary_work_item_id'] ?? 0)->lockForUpdate()->first();
        if (! $work) {
            $work = new ReferralWorkItem(['referral_id' => $referral->id, 'funding_case_id' => $case->id, 'kind' => 'funding_follow_up', 'audience' => 'funding', 'lock_version' => 0]);
        }
        $previous = $work->exists ? ['details' => $work->details, 'due_at' => $work->due_at->toISOString(), 'owner_id' => $work->owner_id] : null;
        $work->fill(['owner_id' => $case->navigator_id, 'due_at' => $due, 'details' => ['title' => $title], 'lock_version' => $work->lock_version + 1])->save();
        $this->command->record($referral, $actor, $previous ? 'work.updated' : 'work.created', 'funding', ['case_id' => $case->id, 'work_id' => $work->id, 'work_version' => $work->lock_version,
            'previous' => $previous, 'title' => $title, 'due_at' => $due->toISOString(), 'owner_id' => $case->navigator_id]);
        $case->details = [...$case->details, 'next_action' => $title, 'next_due_at' => $due->toISOString()];

        return $work;
    }

    private function navigator(int $id, CareReferral $referral, CurrentAuthorizationReads $reads): User
    {
        $navigator = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($id, array_column(ReferralPermissions::definitions(), 'key'));
        abort_unless($this->access->eligibleForSite($navigator, $referral->site_id, $reads) && $this->access->can($navigator, 'funding.manage')
            && (! $referral->restricted || $this->access->can($navigator, 'restricted')), 422, 'Choose current eligible staff with funding-assistance permission.');

        return $navigator;
    }

    private function canAssist(CareReferral $referral): void
    {
        abort_if($referral->stage === 'closed' || $referral->general_enquiry, 422, 'Complete the person’s intake and keep the referral open for funding assistance.');
        abort_unless(ReferralAssessment::scope($referral, 'assist'), 422, 'Review the person’s current permission for funding assistance.');
    }

    private function openCase(ReferralFundingCase $case): void
    {
        abort_if(in_array($case->status, ['withdrawn', 'closed'], true), 422, 'Open a linked new attempt; completed assistance history is preserved.');
    }
}
