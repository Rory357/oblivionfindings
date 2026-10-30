<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Models\HsTrainingReviewSource;
use App\Models\HsTrainingReviewCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Prospective review only; cumulative day counts and HR qualifications are never inferred. */
final class WorkerParticipationTrainingReviewService
{
    public function __construct(private readonly WorkerParticipationTrainingReviewAccessService $access,
        private readonly WorkerParticipationAccessService $participation,
        private readonly WorkerParticipationTrainingReviewerResolver $reviewers) {}

    /** Unmounted scheduled planning only: no owner impersonation or Governance completion. */
    public function planSystemCases(): array
    {
        if (! app()->runningInConsole() || \Illuminate\Support\Facades\Auth::id() !== null) {
            throw new \LogicException('System planning requires the unauthenticated application command runtime.');
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('governance_audit_log')) {
            throw new \LogicException('System review planning requires its canonical Governance audit ledger.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function (): array {
            $today = ComplianceObligation::nzToday();
            $staff = app(\App\Domain\Hr\Services\HrCurrentStaffService::class)->currentUsersQuery()
                ->whereColumn('users.id', 'hs_representatives.user_id');
            $source = \App\Models\HsRepresentative::query()->where('status', 'active')->whereDate('elected_at', '<=', $today)
                ->where(fn ($term) => $term->whereNull('term_expires_at')->orWhereDate('term_expires_at', '>=', $today))
                ->whereExists($staff->selectRaw('1')->toBase())
                ->where(fn ($site) => $site->whereNull('site_id')->orWhereHas('site', fn ($approved) => $approved->active()->notArchived()->whereNull('archived_at')));
            // Only scalar source facts, never representative names/notes/training or HR bodies.
            $facts = (clone $source)->orderBy('id')->lockForUpdate()->get(['id', 'user_id', 'site_id', 'status', 'elected_at', 'term_expires_at']);
            $created = 0; $existing = 0; $needsRepair = 0;
            foreach ($facts as $representative) {
                $case = HsTrainingReviewCase::query()->where('hs_representative_id', $representative->id)->lockForUpdate()->first(['id', 'site_id']);
                if ($case !== null) {
                    if (($case->site_id === null ? null : (int) $case->site_id) !== ($representative->site_id === null ? null : (int) $representative->site_id)) { $needsRepair++; }
                    else { $existing++; }
                    continue;
                }
                if (ComplianceObligation::nzToday() !== $today) { throw new \LogicException('Planning date changed; restart the scheduled source read.'); }
                $actual = (clone $source)->whereKey($representative->id)->where('user_id', $representative->user_id)
                    ->where('site_id', $representative->site_id)->where('status', $representative->status)
                    ->whereDate('elected_at', $representative->elected_at->toDateString());
                if ($representative->term_expires_at === null) { $actual->whereNull('term_expires_at'); }
                else { $actual->whereDate('term_expires_at', $representative->term_expires_at->toDateString()); }
                $actual->whereNotExists(\Illuminate\Support\Facades\DB::table('hs_training_review_cases')
                    ->whereColumn('hs_training_review_cases.hs_representative_id', 'hs_representatives.id')->selectRaw('1'));
                $stamp = now()->toDateTimeString();
                // Conditional physical INSERT reuses current staff/term/Site admission
                // plus exact captured source facts, without touching an HR credential.
                $inserted = \Illuminate\Support\Facades\DB::table('hs_training_review_cases')->insertUsing(
                    ['hs_representative_id', 'site_id', 'initial_due_date', 'anniversary_date', 'creation_origin', 'created_by', 'root_obligation_id', 'created_at', 'updated_at'],
                    $actual->selectRaw("hs_representatives.id, hs_representatives.site_id, ?, ?, 'system', NULL, NULL, ?, ?", [$today, $today, $stamp, $stamp])->toBase());
                if ($inserted !== 1) { throw new \LogicException('The original planning source changed; restart the scheduled read.'); }
                $id = HsTrainingReviewCase::query()->where('hs_representative_id', $representative->id)->value('id');
                \App\Domain\Governance\Services\GovernanceAuditService::log('hsr_training_review.case_planned', 'HsTrainingReviewCase', $id,
                    ['creation_origin' => 'system', 'representative_id' => (int) $representative->id, 'site_id' => $representative->site_id]);
                $created++;
            }

            return ['created' => $created, 'existing' => $existing, 'source_link_needs_repair' => $needsRepair, 'current_nz_date' => $today];
        });
    }

    public function reviewsForViewer(User $actor, int $representativeId): array
    {
        return $this->access->materialize($actor, 'governance.compliance.view', function ($scope, $context, $current) use ($representativeId): array {
            $representative = $scope->representatives($context)->whereKey($representativeId)->firstOrFail(['id', 'site_id']);
            $rows = $scope->obligations($context)->whereExists(\Illuminate\Support\Facades\DB::table('hs_training_review_sources')
                ->whereColumn('compliance_obligation_id', 'compliance_obligations.id')->where('hs_representative_id', $representative->id)->selectRaw('1'))
                ->orderBy('due_date')->get();

            $case = $scope->cases($context)->where('hs_representative_id', $representative->id)->first();

            return ['current_nz_date' => ComplianceObligation::nzToday(), 'case' => $case === null ? null : $this->caseFacts($case),
                'reviews' => $rows->map(fn ($row) => $this->facts($row, $current))->all()];
        });
    }

    public function syncForViewer(User $actor, int $representativeId): array
    {
        return $this->access->materialize($actor, 'governance.compliance.manage', function ($scope, $context, $current) use ($representativeId): array {
            $representative = $scope->representatives($context)->whereKey($representativeId)
                ->lockForUpdate()->firstOrFail(['id', 'site_id', 'status']);
            if ($representative->status !== 'active') { return ['status' => 'unavailable', 'review' => null]; }
            $case = $scope->cases($context)->where('hs_representative_id', $representative->id)->lockForUpdate()->first();
            if ($case === null && HsTrainingReviewCase::query()->where('hs_representative_id', $representative->id)->exists()) {
                return ['status' => 'source_link_needs_repair', 'case_id' => null, 'review' => null];
            }
            if ($case === null) {
                $day = ComplianceObligation::nzToday();
                $case = HsTrainingReviewCase::create(['hs_representative_id' => $representative->id, 'site_id' => $representative->site_id,
                    'initial_due_date' => $day, 'anniversary_date' => $day, 'creation_origin' => 'user', 'created_by' => $current->id]);
            }
            $owner = $this->reviewers->user($representative->site_id === null ? null : (int) $representative->site_id);
            if ($owner === null) { return ['status' => 'needs_owner', 'case_id' => (int) $case->id, 'case' => $this->caseFacts($case), 'review' => null]; }
            $existing = $scope->obligations($context)->whereExists(\Illuminate\Support\Facades\DB::table('hs_training_review_sources')
                ->whereColumn('compliance_obligation_id', 'compliance_obligations.id')->where('hs_representative_id', $representative->id)->selectRaw('1'))
                ->orderByDesc('due_date')->lockForUpdate()->first();
            if ($existing) { return ['status' => 'exists', 'case_id' => (int) $case->id, 'case' => $this->caseFacts($case), 'review' => $this->facts($existing, $current)]; }
            if ($case->root_obligation_id !== null) {
                return ['status' => 'source_link_needs_repair', 'case_id' => (int) $case->id, 'review' => null];
            }
            // An inaccessible old prospective series is not silently replaced.
            if (HsTrainingReviewSource::query()->where('hs_representative_id', $representative->id)->exists()) {
                return ['status' => 'unavailable', 'review' => null];
            }
            $period = WorkerParticipationTrainingReviewPeriod::initial($case->initial_due_date->toDateString());
            $engine = app(ComplianceEngineService::class);
            $obligation = $engine->createObligation(framework: 'hswa', title: 'HSR training-position review',
                description: 'Review the current recorded training position. This internal review does not establish qualification or make an annual course mandatory.',
                frequency: 'annual', owner: $owner, dueDate: Carbon::parse($period['due']),
                obligationCode: 'HSR-TRAINING-REVIEW-'.$representative->id, reminderDays: [60, 14, 0],
                requirements: 'Record the actual review date and a factual review attestation. HR evidence remains subject to independent HR permissions; no course may legitimately be recorded.', evidenceRequired: true);
            HsTrainingReviewSource::create(['case_id' => $case->id, 'compliance_obligation_id' => $obligation->id, 'hs_representative_id' => $representative->id,
                'site_id' => $representative->site_id, 'root_obligation_id' => $obligation->id, 'anniversary_date' => $period['due'],
                'period_start' => $period['start'], 'period_end' => $period['end']]);
            $case->update(['root_obligation_id' => $obligation->id]);
            $engine->scheduleReminders($obligation);

            return ['status' => 'created', 'case_id' => (int) $case->id, 'case' => $this->caseFacts($case), 'review' => $this->facts($obligation, $current)];
        });
    }

    public function completeForViewer(User $actor, int $obligationId, string $reviewedOn, string $attestation, int $expectedVersion): array
    {
        return $this->access->materialize($actor, 'governance.compliance.manage', function ($scope, $context, $current) use ($obligationId, $reviewedOn, $attestation, $expectedVersion): array {
            $obligation = $scope->obligations($context)->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $source = HsTrainingReviewSource::query()->where('compliance_obligation_id', $obligation->id)->lockForUpdate()->firstOrFail();
            if ($obligation->status === 'complete') { return ['review' => $this->facts($obligation, $current), 'replayed' => true]; }
            if (in_array($obligation->status, ComplianceObligation::CLOSED_STATUSES, true)) {
                throw ValidationException::withMessages(['review' => 'This review is closed and cannot receive a new attestation.']);
            }
            abort_unless((int) $obligation->version_number === $expectedVersion, 409, 'The review changed. Refresh before recording it.');
            WorkerParticipationTrainingReviewPeriod::initial($reviewedOn);
            if ($reviewedOn < $source->period_start->toDateString() || $reviewedOn > ComplianceObligation::nzToday()
                || trim($attestation) === '' || mb_strlen($attestation) > 2000) {
                throw ValidationException::withMessages(['attestation' => 'Record a factual review and its actual date within this review period, without future facts.']);
            }
            $owner = $this->reviewers->user($source->site_id === null ? null : (int) $source->site_id);
            if ($owner === null) { throw ValidationException::withMessages(['owner' => 'A current eligible named reviewer is needed before completing this review.']); }
            if ((int) $owner->id !== (int) $current->id) {
                abort(403, 'Only the currently assigned training reviewer can record this review.');
            }
            $evidence = ComplianceEvidence::create(['compliance_obligation_id' => $obligation->id, 'evidence_type' => 'attestation',
                'title' => 'Training-position review on '.$reviewedOn, 'description' => trim($attestation),
                'valid_from' => $reviewedOn, 'uploaded_by' => $current->id, 'uploaded_at' => now(), 'verified' => false]);
            $source->update(['reviewed_on' => $reviewedOn, 'reviewed_by' => $current->id, 'attestation_evidence_id' => $evidence->id]);
            app(ComplianceEngineService::class)->completeObligation($obligation, $current, [$evidence->id], trim($attestation), $expectedVersion);

            return ['review' => $this->facts($obligation->fresh(), $current), 'replayed' => false];
        });
    }

    /** Called only for an admitted body in the original source callback. */
    private function facts(ComplianceObligation $obligation, User $viewer): array
    {
        $source = HsTrainingReviewSource::query()->where('compliance_obligation_id', $obligation->id)->firstOrFail();
        $owner = $this->reviewers->user($source->site_id === null ? null : (int) $source->site_id);
        $attestation = $source->attestation_evidence_id === null ? null : ComplianceEvidence::query()
            ->whereKey($source->attestation_evidence_id)->where('compliance_obligation_id', $obligation->id)->first(['id', 'description']);
        $timezone = config('app.worker_timezone');
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : \App\Domain\Governance\Support\GovernanceLabels::TIMEZONE;
        $recordedDate = $obligation->completed_at?->copy()->timezone($timezone)->toDateString();

        return ['id' => (int) $obligation->id, 'case_id' => (int) $source->case_id, 'representative_id' => (int) $source->hs_representative_id,
            'site_id' => $source->site_id === null ? null : (int) $source->site_id,
            'root_id' => (int) $source->root_obligation_id, 'current_nz_date' => ComplianceObligation::nzToday(),
            'due_date' => $obligation->due_date->toDateString(), 'period_start' => $source->period_start->toDateString(),
            'period_end' => $source->period_end->toDateString(), 'version' => (int) $obligation->version_number,
            'status' => $obligation->currentStatus(), 'next_action' => $owner === null ? 'needs_owner' : ($obligation->status === 'complete' ? 'review_recorded' : 'record_review'),
            'can_complete' => $owner !== null && (int) $owner->id === (int) $viewer->id && $viewer->canDo('governance.compliance.manage')
                && ! in_array($obligation->status, ComplianceObligation::CLOSED_STATUSES, true),
            'owner' => $owner === null ? null : ['id' => (int) $owner->id, 'name' => $owner->name],
            'reviewed_on' => $source->reviewed_on?->toDateString(),
            'performed_late' => $source->reviewed_on !== null && $source->reviewed_on->toDateString() > $obligation->due_date->toDateString(),
            'recorded_late' => $recordedDate !== null && $recordedDate > $obligation->due_date->toDateString(),
            'recorded_at' => $obligation->completed_at?->toIso8601String(),
            'attestation' => $attestation === null ? null : ['id' => (int) $attestation->id, 'text' => $attestation->description]];
    }

    private function caseFacts(HsTrainingReviewCase $case): array
    {
        return ['id' => (int) $case->id, 'initial_due_date' => $case->initial_due_date->toDateString(),
            'anniversary_date' => $case->anniversary_date->toDateString(), 'creation_origin' => $case->creation_origin,
            'created_by' => $case->created_by === null ? null : (int) $case->created_by,
            'root_obligation_id' => $case->root_obligation_id === null ? null : (int) $case->root_obligation_id,
            'site_confirmed' => $case->site_id !== null, 'next_action' => $case->root_obligation_id === null ? 'needs_owner' : 'review_available'];
    }
}
