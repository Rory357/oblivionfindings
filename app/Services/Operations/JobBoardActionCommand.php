<?php

namespace App\Services\Operations;

use App\Models\Client;
use App\Models\CoverageReservation;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CoverageReservationService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\AssignmentEligibilityStatus;
use App\Services\Eligibility\EligibilityResult;
use App\Services\Eligibility\ProposedShiftWorkloadService;
use App\Services\ShiftReplacementService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Existing Job Board policies, checked writes and an opt-in physical-root result. */
final class JobBoardActionCommand
{
    private const PERMISSIONS = ['job_board.viewAny', 'job_board.claim', 'job_board.approve', 'shifts.viewAny', 'shifts.viewAssigned', 'shifts.manageAny', 'reports.viewAny'];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public static function modern(Request $request): bool
    {
        return $request->exists('request_id') || $request->exists('source_revision') || $request->exists('enabled');
    }

    public function source(Request $request, ?int $positionId = null): array
    {
        $action = $positionId === null ? 'set_alert_preference' : (string) $request->query('action');
        abort_unless(in_array($action, ['claim', 'approve', 'set_alert_preference'], true), 422, 'Choose a supported action.');
        $this->rootBoundary();

        return DB::transaction(function () use ($request, $positionId, $action): array {
            app(WorkforceMutationGuard::class)->lock();
            $actor = $this->actor($request, $action);
            if ($positionId === null) {
                return ['actor_id' => (int) $actor->id, 'source_revision' => JobBoardActionSource::revision($action, $actor, []),
                    'enabled' => (bool) $actor->job_board_alerts_enabled, 'can_update' => true];
            }

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $positionId, $action): array {
                $source = $this->currentSource($actor, $positionId, $reads);
                $decision = null;
                if ($this->actionable($source, $actor, $action)) {
                    $staff = $action === 'claim' ? $actor : $source['claimant'];
                    if ($staff) {
                        $decision = $this->decision($source, $actor, $staff, $reads, $action);
                    }
                }
                $allowed = $decision && in_array($decision->status, [AssignmentEligibilityStatus::Pass, AssignmentEligibilityStatus::Warning], true);

                return ['actor_id' => (int) $actor->id, 'action' => $action,
                    'source_revision' => JobBoardActionSource::revision($action, $actor, $source),
                    'display' => JobBoardActionSource::display($source, $actor),
                    // Only the requested action was assessed; another action must fetch its own source.
                    'can' => ['claim' => $action === 'claim' && $allowed, 'approve' => $action === 'approve' && $allowed],
                    'urls' => ['claim' => route('operations.job_board.claim', $positionId, false),
                        'approve' => route('operations.job_board.approve', $positionId, false)],
                    'eligibility' => JobBoardActionSource::eligibility($decision)];
            });
        });
    }

    public function execute(Request $request, string $action, ?int $positionId = null): array
    {
        $modern = self::modern($request);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $entry = $this->rootBoundary();
        $proof = new JobBoardActionEvidence($connection, $pdo);
        $legacyWarnings = [];
        $saved = DB::transaction(function () use ($request, $action, $positionId, $modern, $proof, &$legacyWarnings): array {
            app(WorkforceMutationGuard::class)->lock();
            $actor = $this->actor($request, $action); // Current authority before numeric lookup/intent comparison.
            $intent = $this->intent($request, $action, $modern);
            if ($action === 'set_alert_preference') {
                $before = JobBoardActionSource::revision($action, $actor, []);
                $this->expectedSource($intent, $before);
                $enabled = $modern ? $intent['enabled'] : ! (bool) $actor->job_board_alerts_enabled;
                $changed = (bool) $actor->job_board_alerts_enabled !== $enabled;
                if ($changed) {
                    $proof->update($actor, ['job_board_alerts_enabled' => $enabled], ['id', ...$actor->getFillable()]);
                } else {
                    $proof->remember($actor, ['id', ...$actor->getFillable()]);
                }
                $this->actor($request, $action);
                $proof->finish();

                return $this->result($actor, $action, $intent, $before, JobBoardActionSource::revision($action, $actor, []),
                    $changed ? 'changed' : 'unchanged', $changed, null, null, ['enabled' => $enabled], null);
            }

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request, $action, $positionId, $actor, $intent, $modern, $proof, &$legacyWarnings): array {
                $source = $this->currentSource($actor, $positionId, $reads);
                if (! $modern && $action === 'approve') {
                    abort_unless($source['position']->status === 'claimed', 404);
                }
                $before = JobBoardActionSource::revision($action, $actor, $source);
                $this->expectedSource($intent, $before);
                $this->assertActionable($source, $actor, $action);
                $staff = $action === 'claim' ? $actor : $source['claimant'];
                $decision = $this->decision($source, $actor, $staff, $reads, $action);
                $this->assertDecision($decision, $action, $modern);
                if (! $modern) {
                    $legacyWarnings = $decision->result?->warnings ?? [];
                }
                // Observer side effects may not silently retime/relink an otherwise unchanged duty on claim.
                $proof->remember($source['shift']);
                $proof->remember($source['shift']->client);
                $proof->remember($source['site']);
                $proof->remember($actor);
                if ($source['claimant']) {
                    $proof->remember($source['claimant']);
                }
                foreach ($source['siblings'] as $sibling) {
                    $proof->remember($sibling);
                }
                $shift = $source['shift'];
                $position = $source['position'];
                $reservationService = app(CoverageReservationService::class);
                if ($action === 'claim') {
                    // Same shortage/TTL/window/token policy; creation and compensation now share the claim transaction.
                    $hold = $reservationService->reserveForAssignment($shift, $actor, 'job_board_claim', $proof->hold(...));
                    $proof->update($position, ['claimed_by' => $actor->id, 'claimed_at' => now(), 'status' => 'claimed']);
                    if ($hold) {
                        $proof->update($hold, ['shift_open_position_id' => $position->id]);
                    }
                    $position->setRelation('claimer', $actor);
                    app(ShiftReplacementService::class)->syncClaimFromOpenPosition($position,
                        $this->replacementEvidence($source, $actor, $staff, $decision), $proof->update(...), $proof->timeline(...));
                } else {
                    $hold = $source['holds']->last(); // Preserve installed latest active linked hold, including its existing expiry semantics.
                    if (! $hold) {
                        $hold = $reservationService->reserveForAssignment($shift, $actor, 'job_board_approve', $proof->hold(...));
                    }
                    $proof->update($position, ['approved_by' => $actor->id, 'approved_at' => now(), 'status' => 'filled']);
                    $proof->update($shift, ['user_id' => $position->claimed_by,
                        'status' => $shift->status === 'draft' ? 'scheduled' : $shift->status]);
                    foreach ($source['siblings'] as $sibling) {
                        $proof->update($sibling, ['status' => 'cancelled']);
                    }
                    $reservationService->fulfill($hold, $shift, $position, $proof->update(...));
                    app(ShiftReplacementService::class)->approveFromOpenPosition($position, $actor, $decision,
                        $this->replacementEvidence($source, $actor, $staff, $decision), $proof->update(...), $proof->timeline(...));
                }
                $this->actor($request, $action);
                $proof->finish();
                // Rebuild the current source after all writes, not from returned stale models.
                $after = $this->currentSource($actor, $positionId, $reads);
                $expectedSiblingIds = $action === 'approve' ? [] : $source['siblings']->pluck('id')->all();
                abort_unless($after['siblings']->pluck('id')->all() === $expectedSiblingIds, 409, 'The active position cohort changed.');

                return $this->result($actor, $action, $intent, $before, JobBoardActionSource::revision($action, $actor, $after),
                    $action === 'claim' ? 'claimed' : 'approved', true, $after['position'], $after['shift'], null, $decision);
            });
        });
        $root = $entry === 0 && DB::connection() === $connection && $connection->getPdo() === $pdo
            && $connection->transactionLevel() === 0 && ! $pdo->inTransaction();
        if (! $root) {
            return ['confirmed' => false, 'saved' => $saved, 'legacy_warnings' => []];
        }
        // An after-commit callback can have changed a row or thrown after durable save.
        // Do not promote the pre-commit projection to success without this bounded current readback.
        $confirmed = DB::transaction(function () use ($request, $action, $positionId, $proof, $saved): bool {
            app(WorkforceMutationGuard::class)->lock();
            $actor = $this->actor($request, $action);
            if (! $proof->matches()) {
                return false;
            }

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $action, $positionId, $saved): bool {
                $source = $positionId === null ? [] : $this->currentSource($actor, $positionId, $reads);

                return hash_equals($saved['result_revision'], JobBoardActionSource::revision($action, $actor, $source));
            });
        });
        $confirmed = $confirmed && $connection->transactionLevel() === 0 && ! $pdo->inTransaction();
        $saved['committed_at'] = $confirmed ? now('UTC')->toISOString() : null;

        return ['confirmed' => $confirmed, 'saved' => $saved, 'legacy_warnings' => $confirmed ? $legacyWarnings : []];
    }

    private function rootBoundary(): int
    {
        $level = DB::transactionLevel();
        abort_if($level === 0 && DB::connection()->getPdo()->inTransaction(), 409, 'An untracked transaction cannot confirm this action.');

        return $level;
    }

    private function actor(Request $request, string $action): User
    {
        abort_unless($request->user()?->id, 403);
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user()->id, self::PERMISSIONS);
        $permissions = match ($action) {
            'claim' => ['job_board.claim', 'shifts.viewAssigned', 'shifts.manageAny'],
            'approve' => ['job_board.approve', 'shifts.manageAny'],
            'set_alert_preference' => ['job_board.viewAny', 'job_board.claim', 'shifts.viewAny', 'shifts.viewAssigned'],
            default => [],
        };
        abort_unless($actor->isApproved() && collect($permissions)->contains(fn ($key) => $actor->canDo($key)), 403);

        return $actor;
    }

    private function intent(Request $request, string $action, bool $modern): array
    {
        if (! $modern) {
            return ['request_id' => null, 'source_revision' => null];
        }
        abort_unless($request->wantsJson(), 422, 'Correlated actions require a JSON result.');
        $rules = ['request_id' => ['required', 'uuid'], 'source_revision' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/']];
        if ($action === 'set_alert_preference') {
            $rules['enabled'] = ['required', 'boolean'];
        }
        $intent = $request->validate($rules);
        if ($action === 'set_alert_preference') {
            $intent['enabled'] = $request->boolean('enabled');
        }

        return $intent;
    }

    private function expectedSource(array $intent, string $current): void
    {
        abort_if($intent['source_revision'] !== null && ! hash_equals($current, $intent['source_revision']), 409,
            'The displayed source changed. Refresh it before confirming.');
    }

    private function currentSource(User $actor, int $positionId, CurrentAuthorizationReads $reads): array
    {
        $siteIds = $this->sites->accessibleSiteIds($actor, ['reports.viewAny'], $reads);
        abort_if($siteIds === [], 404);
        // Hints contain no labels. Revalidate every governing identity after the canonical Client/Shift locks.
        $hint = $reads->query(ShiftOpenPosition::query())->whereKey($positionId)->first(['id', 'shift_id']);
        abort_unless($hint, 404);
        $shiftHint = $reads->query(Shift::query())->whereKey($hint->shift_id)->first(['id', 'client_id', 'site_id']);
        abort_unless($shiftHint, 404);
        $client = $reads->query(Client::query())->whereKey($shiftHint->client_id)->first();
        abort_unless($client && $client->site_id && ($shiftHint->site_id === null || (int) $shiftHint->site_id === (int) $client->site_id), 404);
        $siteId = (int) ($shiftHint->site_id ?? $client->site_id);
        abort_unless(in_array($siteId, $siteIds, true), 404);
        $site = $reads->query(Site::query()->active()->notArchived()->whereNull('archived_at'))->whereKey($siteId)->first();
        abort_unless($site, 404);
        $shift = Shift::query()->whereKey($hint->shift_id)->where('client_id', $client->id)
            ->where('site_id', $shiftHint->site_id)->lockForUpdate()->first();
        abort_unless($shift, 404);
        $shift->setRelation('client', $client)->setRelation('site', $site);
        if ($shift->user_id) {
            abort_unless($reads->query($this->sites->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), $siteId))->exists(), 404);
        }
        $position = ShiftOpenPosition::query()->whereKey($positionId)->where('shift_id', $shift->id)->lockForUpdate()->first();
        abort_unless($position, 404);
        $replacement = $position->replacement_request_id ? $reads->query(ShiftReplacementRequest::query()
            ->tap(fn ($q) => $this->sites->applyShiftReplacementIntegrityScope($q)))->whereKey($position->replacement_request_id)->where('shift_id', $shift->id)->first() : null;
        abort_if($position->replacement_request_id && ! $replacement, 404);
        $claimant = $position->claimed_by ? $reads->query(User::query())->whereKey($position->claimed_by)->first() : null;
        abort_if($position->claimed_by && ! $claimant, 404);
        if ($position->approved_by) {
            abort_unless($reads->query(User::query())->whereKey($position->approved_by)->exists(), 404);
        }
        $position->setRelation('shift', $shift)->setRelation('replacementRequest', $replacement)->setRelation('claimer', $claimant);
        $siblings = $reads->query(ShiftOpenPosition::query()->tap(fn ($q) => $this->sites->applyShiftOpenPositionIntegrityScope($q)))
            ->where('shift_id', $shift->id)->whereKeyNot($positionId)->whereIn('status', ['open', 'claimed'])->orderBy('id')->get();
        $holds = CoverageReservation::query()->where('shift_open_position_id', $positionId)
            ->where('status', CoverageReservationService::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        foreach ($holds as $hold) {
            abort_unless((int) $hold->site_id === $siteId && ($hold->shift_id === null || (int) $hold->shift_id === (int) $shift->id)
                && $hold->window_starts_at && $hold->window_ends_at, 404);
            if ($hold->coverage_requirement_id) {
                abort_unless($reads->query(SiteCoverageRequirement::query())->whereKey($hold->coverage_requirement_id)->where('site_id', $siteId)->exists(), 404);
            }
            abort_unless($reads->query(User::query())->whereKey($hold->reserved_by_user_id)->exists(), 404);
        }
        if ($replacement) {
            $replacement->setRelation('shift', $shift);
            foreach (['requester' => 'requested_by', 'currentStaff' => 'current_staff_id', 'replacementStaff' => 'replacement_user_id'] as $relation => $key) {
                $replacement->setRelation($relation, $replacement->{$key} ? $reads->query(User::query())->whereKey($replacement->{$key})->first() : null);
            }
        }

        return compact('position', 'shift', 'replacement', 'claimant', 'site', 'siblings', 'holds');
    }

    private function decision(array $source, User $actor, User $staff, CurrentAuthorizationReads $reads, string $action): AssignmentEligibilityDecision
    {
        $shift = $source['shift'];
        // Existing approval/replacement recipient gate; no additional past/in-progress policy.
        if ($action === 'approve' || $source['replacement']) {
            if (! $reads->query($this->sites->applyFleetRecipientEligibility(User::query()->whereKey($staff->id), $source['site']->id))->exists()) {
                return AssignmentEligibilityDecision::fromResult($shift, $staff, EligibilityResult::fromChecks([
                    ['rule' => 'site_assignment', 'passed' => false, 'severity' => 'block', 'overrideable' => false,
                        'message' => 'The selected worker is no longer eligible to work at this Shift Site.'],
                ]));
            }
        }
        // Fresh relation tree used by the installed evaluator, without altering global readers.
        $staff = clone $staff;
        foreach (['staffAvailability', 'staffTimeOff', 'hrLeaveRequests', 'hrEmployeeProfile', 'hrComplianceStatuses', 'hrDriverEligibility',
            'medicationCompetencyAssessments', 'medicationCompetencyExemptions'] as $relation) {
            $staff->unsetRelation($relation);
            $staff->load([$relation => function ($r) use ($reads, $relation): void {
                $q = $r->getQuery();
                if ($relation === 'hrEmployeeProfile') {
                    $q->withTrashed();
                } elseif ($relation === 'hrLeaveRequests') {
                    $q->where('status', 'approved');
                } elseif ($relation === 'hrComplianceStatuses') {
                    $q->with(['requirement' => fn ($r) => $reads->query($r->getQuery())]);
                }
                $reads->query($q);
            }]);
        }
        $candidate = clone $shift;
        $candidate->user_id = $staff->id;
        $workloads = app(ProposedShiftWorkloadService::class)->prepareCurrent(collect([$candidate]), [$shift->id], $actor);
        $workload = $workloads->get($staff->id);
        if (! $workload) {
            throw new \LogicException('The Job Board candidate workload is unavailable.');
        }
        app(ProposedShiftWorkloadService::class)->assertCurrentFor($workload, $candidate, $staff, $actor);

        // Same evaluator/gateway semantics; proposed object is retained for exact workload identity.
        return app(AssignmentEligibilityGateway::class)->decide($candidate, $staff, $workload);
    }

    private function actionable(array $source, User $actor, string $action): bool
    {
        $p = $source['position'];
        $s = $source['shift'];
        if (in_array($s->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        return $action === 'claim'
            ? $p->status === 'open' && (! $p->expires_at || $p->expires_at->gt(now())) && (int) $s->user_id !== (int) $actor->id
            : $p->status === 'claimed' && $source['claimant'] !== null;
    }

    private function assertActionable(array $source, User $actor, string $action): void
    {
        if ($this->actionable($source, $actor, $action)) {
            return;
        }
        $p = $source['position'];
        $message = $action === 'approve' ? 'This claim is no longer active.' : match (true) {
            $p->status === 'claimed' => 'This position was just claimed by another worker.',
            $p->status !== 'open' => 'This position is no longer open for claims.',
            $p->expires_at && $p->expires_at->lte(now()) => 'This position has expired and can no longer be claimed.',
            (int) $source['shift']->user_id === (int) $actor->id => 'You are already assigned to this shift.',
            default => 'This shift can no longer be claimed from the job board.',
        };
        throw ValidationException::withMessages([$action === 'claim' ? 'claim' : 'position' => $message]);
    }

    private function assertDecision(AssignmentEligibilityDecision $decision, string $action, bool $modern): void
    {
        $field = $action === 'claim' ? 'claim' : 'position';
        if ($modern && $decision->status === AssignmentEligibilityStatus::HardBlock) {
            throw ValidationException::withMessages([$field => JobBoardActionSource::eligibility($decision)['blocked_reasons'][0]])->status(422);
        }
        if (! $modern && $action === 'approve' && $decision->result?->hasBlocks()) {
            session()->flash('compliance_warnings', $decision->result->toArray()['compliance_warnings'] ?? []);
        }
        $decision->assertMayAssign($field, 'The worker cannot be assigned to this Shift.');
    }

    private function replacementEvidence(array $source, User $actor, User $staff, AssignmentEligibilityDecision $decision): array
    {
        return ['position' => $source['position'], 'shift' => $source['shift'], 'replacement' => $source['replacement'],
            'actor' => $actor, 'staff' => $staff, 'decision' => $decision,
            'connection' => DB::connection(), 'pdo' => DB::connection()->getPdo()];
    }

    private function result(User $actor, string $action, array $intent, string $before, string $after, string $outcome, bool $changed,
        ?ShiftOpenPosition $position, ?Shift $shift, ?array $preference, ?AssignmentEligibilityDecision $decision): array
    {
        return ['version' => 1, 'scope' => 'job_board', 'action' => $action, 'outcome' => $outcome, 'changed' => $changed,
            'actor_id' => (int) $actor->id, 'request_id' => $intent['request_id'], 'source_revision' => $before, 'result_revision' => $after,
            'position' => $position ? JobBoardActionSource::position($position) : null,
            'shift' => $shift ? JobBoardActionSource::shift($shift) : null, 'preference' => $preference,
            'eligibility' => JobBoardActionSource::eligibility($decision), 'committed_at' => null];
    }
}
