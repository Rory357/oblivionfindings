<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsClosureException;
use App\Models\HsClosureExceptionDecision;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\HsClosureReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HsEventClosureService
{
    public function __construct(
        private readonly HsInvestigationService $investigations,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function readiness(HsEvent $event): HsClosureReadiness
    {
        $requirements = [];
        $sourceType = ltrim((string) $event->source_type, '\\');
        $handoverRequiresAcceptance = $sourceType === ClientIncident::class
            || in_array($event->handover_status, [
                HsEvent::HANDOVER_NOT_READY,
                HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            ], true);
        $acceptanceOk = ! $handoverRequiresAcceptance
            || $event->handover_status === HsEvent::HANDOVER_ACCEPTED;

        $requirements[] = $this->requirement(
            'hs_acceptance',
            $acceptanceOk,
            $acceptanceOk
                ? 'H&S handover accepted where required'
                : 'Accept the H&S handover before closing this event.',
            "/health-safety/events/{$event->id}?action=accept-handover",
            HsClosureReadiness::EXCEPTIONAL,
        );

        $decisionOk = $event->worksafe_notifiable !== null
            && $event->worksafe_decided_at !== null
            && $event->worksafe_decided_by_user_id !== null
            && filled($event->worksafe_decision_reason)
            && filled($event->worksafe_decision_source);
        $requirements[] = $this->requirement(
            'worksafe_decision',
            $decisionOk,
            $decisionOk
                ? 'WorkSafe notifiability decision recorded'
                : 'Record the WorkSafe notifiability decision before closing this event.',
            "/health-safety/events/{$event->id}?action=worksafe-decision",
            HsClosureReadiness::HARD,
        );

        $notifiable = $event->worksafe_notifiable === true;
        $notificationOk = ! $notifiable || (
            in_array($event->worksafe_status, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true)
            && $event->worksafe_notified_at !== null
            && filled($event->worksafe_method)
        );
        $requirements[] = $this->requirement(
            'worksafe_notification',
            $notificationOk,
            $notificationOk
                ? ($notifiable ? 'WorkSafe notification recorded' : 'WorkSafe notification not required by the recorded decision')
                : 'Record the WorkSafe notification before closing this event.',
            "/health-safety/events/{$event->id}?action=worksafe-notify",
            HsClosureReadiness::HARD,
        );

        $acknowledgementOk = ! $notifiable || (
            $event->worksafe_status === HsEvent::WORKSAFE_ACKNOWLEDGED
            && $event->worksafe_acknowledged_at !== null
        );
        $requirements[] = $this->requirement(
            'worksafe_acknowledgement',
            $acknowledgementOk,
            $acknowledgementOk
                ? ($notifiable ? 'WorkSafe acknowledgement recorded' : 'WorkSafe acknowledgement not required by the recorded decision')
                : 'Record WorkSafe acknowledgement before closing this event.',
            "/health-safety/events/{$event->id}?action=worksafe-acknowledge",
            HsClosureReadiness::HARD,
        );

        $sitePreservationOk = ! $notifiable || in_array(
            $event->worksafe_site_preservation_status,
            [HsEvent::SITE_PRESERVATION_NOT_REQUIRED, HsEvent::SITE_PRESERVATION_RELEASED],
            true,
        );
        $sitePreservationLabel = match (true) {
            $sitePreservationOk && ! $notifiable => 'Site-preservation work not required by the recorded decision',
            $event->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_NOT_REQUIRED => 'Site-preservation applicability reviewed and recorded as not required',
            $event->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_RELEASED => 'Site-preservation release recorded',
            $event->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_ACTIVE => 'Record the Site-preservation release before closing this event.',
            default => 'Review and record the Site-preservation obligation before closing this event.',
        };
        $sitePreservationAction = $event->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_ACTIVE
            ? 'worksafe-site-release'
            : 'worksafe-site-preservation';
        $requirements[] = $this->requirement(
            'site_preservation',
            $sitePreservationOk,
            $sitePreservationLabel,
            "/health-safety/events/{$event->id}?action={$sitePreservationAction}",
            HsClosureReadiness::HARD,
        );

        $incident = $this->linkedClientIncident($event);
        $eventAlertId = $event->control_room_alert_id ? (int) $event->control_room_alert_id : null;
        $incidentAlertId = $incident?->control_room_alert_id ? (int) $incident->control_room_alert_id : null;
        $alertLinkageOk = $eventAlertId === null
            || $incidentAlertId === null
            || $eventAlertId === $incidentAlertId;
        $alertId = $eventAlertId ?? $incidentAlertId;
        $alert = $alertId ? ControlRoomAlert::query()->find($alertId) : null;
        $requirements[] = $this->requirement(
            'control_room_linkage',
            $alertLinkageOk,
            $alertLinkageOk
                ? 'Linked incident and H&S record identify the same Control Room alert'
                : 'Resolve the conflicting linked Control Room alert records before closing this event.',
            $alert ? "/control-room/alerts/{$alert->id}" : "/health-safety/events/{$event->id}",
            HsClosureReadiness::HARD,
        );

        $alertScopeOk = ! $alert || (
            $event->site_id !== null
            && $alert->site_id !== null
            && (int) $event->site_id === (int) $alert->site_id
        );
        $requirements[] = $this->requirement(
            'control_room_scope',
            $alertScopeOk,
            $alertScopeOk
                ? 'Linked Control Room alert has the same Site scope'
                : 'The linked Control Room alert Site does not match this H&S event.',
            $alert ? "/control-room/alerts/{$alert->id}" : "/health-safety/events/{$event->id}",
            HsClosureReadiness::HARD,
        );

        $alertLifecycleOk = ! $alert || ! in_array($alert->status, ControlRoomAlert::ACTIVE_STATUSES, true);
        $requirements[] = $this->requirement(
            'control_room_alert',
            $alertLifecycleOk,
            $alertLifecycleOk
                ? 'Linked Control Room alert no longer needs an operational response'
                : 'Resolve or dismiss the active linked Control Room alert before closing this event.',
            $alert ? "/control-room/alerts/{$alert->id}" : "/health-safety/events/{$event->id}",
            HsClosureReadiness::HARD,
        );

        $activeProtectiveWork = $alert?->tasks()
            ->whereNotIn('status', AlertTask::TERMINAL_STATUSES)
            ->exists() ?? false;
        $activeTransferredProtectiveWork = HsCorrectiveAction::query()
            ->where('hs_event_id', $event->id)
            ->whereNotNull('source_control_room_task_id')
            ->whereNotIn('status', [
                HsCorrectiveAction::STATUS_VERIFIED,
                HsCorrectiveAction::STATUS_CLOSED,
            ])
            ->exists();
        $requirements[] = $this->requirement(
            'protective_work',
            ! $activeProtectiveWork && ! $activeTransferredProtectiveWork,
            match (true) {
                $activeProtectiveWork => 'Complete or formally transfer active protective Control Room work before closing this event.',
                $activeTransferredProtectiveWork => 'Verify or close the transferred H&S protective action before closing this event.',
                default => 'Linked protective Control Room work is complete or formally transferred',
            },
            $alert ? "/control-room/alerts/{$alert->id}" : "/health-safety/events/{$event->id}",
            HsClosureReadiness::HARD,
        );

        $hasActiveInvestigation = $event->investigations()
            ->where('status', '!=', HsInvestigation::STATUS_COMPLETED)
            ->exists();
        $investigationOk = ! $hasActiveInvestigation
            && (! $event->investigation_required || $event->hasCompletedInvestigation());
        $requirements[] = $this->requirement(
            'hs_investigation',
            $investigationOk,
            $investigationOk
                ? 'Required H&S investigation complete'
                : ($hasActiveInvestigation
                    ? 'Complete the active H&S investigation before closing this event.'
                    : 'Complete the required H&S investigation before closing this event.'),
            $hasActiveInvestigation
                ? "/health-safety/events/{$event->id}?section=investigation"
                : "/health-safety/events/{$event->id}?action=investigation",
            HsClosureReadiness::EXCEPTIONAL,
        );

        $recommendationsOk = true;
        $recommendationBlockers = [];
        foreach ($event->investigations()->where('status', HsInvestigation::STATUS_COMPLETED)->get() as $investigation) {
            $missing = $this->investigations->undispositionedRecommendationIndexes($investigation);
            if ($missing === []) {
                continue;
            }
            $recommendationsOk = false;
            $numbers = collect($missing)->map(static fn (int $index): string => (string) ($index + 1))->implode(', ');
            $recommendationBlockers[] = "Decide the outcome of recommendation {$numbers} on investigation {$investigation->reference_number}.";
        }
        $requirements[] = $this->requirement(
            'recommendation_dispositions',
            $recommendationsOk,
            $recommendationsOk
                ? 'Every investigation recommendation has a recorded outcome'
                : implode(' ', $recommendationBlockers),
            "/health-safety/events/{$event->id}?section=investigation",
            HsClosureReadiness::EXCEPTIONAL,
        );

        $unresolvedActionDisposition = HsRecommendationDisposition::query()
            ->whereHas('investigation', fn ($query) => $query->where('hs_event_id', $event->id))
            ->where('disposition', HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION)
            ->where(function ($query): void {
                $query->whereNull('hs_corrective_action_id')
                    ->orWhereHas('correctiveAction', fn ($actionQuery) => $actionQuery
                        ->whereNotIn('status', [
                            HsCorrectiveAction::STATUS_VERIFIED,
                            HsCorrectiveAction::STATUS_CLOSED,
                        ]));
            })
            ->exists();
        $actionsOk = ! $event->hasOpenCorrectiveActions() && ! $unresolvedActionDisposition;
        $requirements[] = $this->requirement(
            'corrective_actions',
            $actionsOk,
            $actionsOk
                ? 'All corrective actions verified or closed'
                : 'All corrective actions must be verified or closed before this event can be closed.',
            "/health-safety/corrective-actions?event={$event->id}",
            HsClosureReadiness::EXCEPTIONAL,
        );

        return new HsClosureReadiness((int) $event->id, $event->site_id ? (int) $event->site_id : null, $requirements);
    }

    /** @param  array{category: string, reason: string, evidence_reference: string, scope: list<string>, expires_at: string, review_at: string}  $data */
    public function requestException(HsEvent $event, User $actor, array $data): HsClosureException
    {
        return DB::transaction(function () use ($event, $actor, $data): HsClosureException {
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            $lockedActor = $this->lockActorAuthority($actor);
            $this->lockClosureDependencies($locked);
            $this->assertPermission($lockedActor, 'healthSafety.closureExceptions.request');
            $this->assertSiteAccessAndOwnership($lockedActor, $locked);
            $this->assertOpen($locked);

            if (! $locked->site_id) {
                throw new \DomainException('A Site-scoped H&S event is required before requesting a closure exception.');
            }

            $category = trim($data['category']);
            $reason = trim($data['reason']);
            $evidenceReference = trim($data['evidence_reference']);
            $scope = array_values(array_unique(array_map('strval', $data['scope'])));
            sort($scope);
            $allowedCategoryScope = HsClosureException::CATEGORY_SCOPES[$category] ?? null;
            if ($allowedCategoryScope === null || $scope === [] || array_diff($scope, $allowedCategoryScope) !== []) {
                throw new \DomainException('The requested closure exception category and scope do not match.');
            }
            if (mb_strlen($reason) < 20 || mb_strlen($evidenceReference) < 5) {
                throw new \DomainException('A specific reason and evidence reference are required for an H&S closure exception.');
            }

            $now = CarbonImmutable::now();
            $expiresAt = CarbonImmutable::parse($data['expires_at']);
            $reviewAt = CarbonImmutable::parse($data['review_at']);
            if (! $expiresAt->isAfter($now) || $expiresAt->isAfter($now->addDays(30))) {
                throw new \DomainException('The exception expiry must be within the next 30 days.');
            }
            if (! $reviewAt->isAfter($now) || $reviewAt->isAfter($expiresAt)) {
                throw new \DomainException('The exception review must be due before it expires.');
            }

            $readiness = $this->readiness($locked);
            $currentExceptionalKeys = $readiness->exceptionalBlockerKeys();
            if (array_diff($scope, $currentExceptionalKeys) !== []) {
                throw new \DomainException('An exception can only cover an exceptional blocker that is currently open.');
            }

            $existing = HsClosureException::query()
                ->where('hs_event_id', $locked->id)
                ->where('category', $category)
                ->lockForUpdate()
                ->with('latestDecision')
                ->get()
                ->first(fn (HsClosureException $exception): bool => $exception->scope === $scope
                    && in_array($exception->status(), [HsClosureException::STATUS_PENDING, HsClosureException::STATUS_APPROVED], true)
                    && $exception->expires_at->isFuture());
            if ($existing) {
                throw new \DomainException('A current closure exception request already covers this scope.');
            }

            $requestedAt = $now;
            $provenance = [
                'event_id' => (int) $locked->id,
                'site_id' => (int) $locked->site_id,
                'owner_user_id' => $locked->owner_user_id ? (int) $locked->owner_user_id : null,
                'requester_user_id' => (int) $lockedActor->id,
                'event_status' => $locked->status,
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'category' => $category,
                'scope' => $scope,
                'blockers' => $readiness->blockers(),
                'requested_at' => $requestedAt->toIso8601String(),
            ];
            $hash = $this->provenanceHash([
                $provenance,
                $reason,
                $evidenceReference,
                $expiresAt->toIso8601String(),
                $reviewAt->toIso8601String(),
            ]);

            $exception = HsClosureException::query()->create([
                'hs_event_id' => $locked->id,
                'site_id' => $locked->site_id,
                'requested_by_user_id' => $lockedActor->id,
                'category' => $category,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'scope' => $scope,
                'request_provenance' => $provenance,
                'provenance_hash' => $hash,
                'requested_at' => $requestedAt,
                'expires_at' => $expiresAt,
                'review_at' => $reviewAt,
            ]);

            AuditLogger::logOrFail('healthSafety.event.closureExceptionRequested', $exception, [
                'actor_id' => $lockedActor->id,
                'hs_event_id' => $locked->id,
                'site_id' => $locked->site_id,
                'scope' => $scope,
                'provenance_hash' => $hash,
            ]);

            return $exception->fresh(['requester:id,name', 'latestDecision.decidedBy:id,name']);
        }, 3);
    }

    public function decideException(
        HsEvent $event,
        HsClosureException $exception,
        User $actor,
        string $decision,
        string $reason,
    ): HsClosureExceptionDecision {
        if (! in_array($decision, [
            HsClosureExceptionDecision::DECISION_APPROVED,
            HsClosureExceptionDecision::DECISION_REJECTED,
        ], true)) {
            throw new \DomainException('The closure exception decision is not supported.');
        }

        return DB::transaction(function () use ($event, $exception, $actor, $decision, $reason): HsClosureExceptionDecision {
            $lockedEvent = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            $lockedActor = $this->lockActorAuthority($actor);
            $this->lockClosureDependencies($lockedEvent);
            $lockedException = HsClosureException::query()
                ->whereKey($exception->id)
                ->where('hs_event_id', $lockedEvent->id)
                ->where('site_id', $lockedEvent->site_id)
                ->lockForUpdate()
                ->first();
            if (! $lockedException) {
                throw new \DomainException('The closure exception is not valid for this event.');
            }

            $decisions = $lockedException->decisions()->lockForUpdate()->orderBy('id')->get();
            $this->assertPermission($lockedActor, 'healthSafety.closureExceptions.approve');
            $this->assertSiteAccess($lockedActor, $lockedEvent);
            $this->assertOpen($lockedEvent);

            if ((int) $lockedException->requested_by_user_id === (int) $lockedActor->id) {
                throw new \DomainException('You cannot approve or reject your own H&S closure exception request.');
            }
            if ($decisions->isNotEmpty()) {
                throw new \DomainException('This closure exception request has already been decided.');
            }
            if (! $lockedException->expires_at->isFuture() || ! $lockedException->review_at->isFuture()) {
                throw new \DomainException('This closure exception request is no longer current.');
            }

            $reason = trim($reason);
            if (mb_strlen($reason) < 10) {
                throw new \DomainException('An independent decision reason of at least 10 characters is required.');
            }

            $readiness = $this->readiness($lockedEvent);
            if (array_diff($lockedException->scope ?? [], $readiness->exceptionalBlockerKeys()) !== []) {
                throw new \DomainException('The requested exceptional blocker is no longer open.');
            }

            $decidedAt = CarbonImmutable::now();
            $provenance = [
                'exception_id' => (int) $lockedException->id,
                'request_provenance_hash' => $lockedException->provenance_hash,
                'event_id' => (int) $lockedEvent->id,
                'site_id' => (int) $lockedEvent->site_id,
                'decision' => $decision,
                'approver_user_id' => (int) $lockedActor->id,
                'requester_user_id' => (int) $lockedException->requested_by_user_id,
                'scope' => $lockedException->scope,
                'blockers_at_decision' => $readiness->blockers(),
                'decided_at' => $decidedAt->toIso8601String(),
            ];
            $hash = $this->provenanceHash([$provenance, $reason]);

            $record = HsClosureExceptionDecision::query()->create([
                'hs_closure_exception_id' => $lockedException->id,
                'decision' => $decision,
                'decision_phase' => HsClosureExceptionDecision::PHASE_INITIAL,
                'decided_by_user_id' => $lockedActor->id,
                'reason' => $reason,
                'previous_decision_id' => null,
                'decision_provenance' => $provenance,
                'provenance_hash' => $hash,
                'decided_at' => $decidedAt,
            ]);

            AuditLogger::logOrFail(
                $decision === HsClosureExceptionDecision::DECISION_APPROVED
                    ? 'healthSafety.event.closureExceptionApproved'
                    : 'healthSafety.event.closureExceptionRejected',
                $lockedException,
                [
                    'actor_id' => $lockedActor->id,
                    'hs_event_id' => $lockedEvent->id,
                    'site_id' => $lockedEvent->site_id,
                    'decision_id' => $record->id,
                    'scope' => $lockedException->scope,
                    'provenance_hash' => $hash,
                ],
            );

            return $record->fresh(['decidedBy:id,name']);
        }, 3);
    }

    public function revokeException(
        HsEvent $event,
        HsClosureException $exception,
        User $actor,
        string $reason,
    ): HsClosureExceptionDecision {
        return DB::transaction(function () use ($event, $exception, $actor, $reason): HsClosureExceptionDecision {
            $lockedEvent = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            $lockedActor = $this->lockActorAuthority($actor);
            $this->lockClosureDependencies($lockedEvent);
            $lockedException = HsClosureException::query()
                ->whereKey($exception->id)
                ->where('hs_event_id', $lockedEvent->id)
                ->where('site_id', $lockedEvent->site_id)
                ->lockForUpdate()
                ->first();
            if (! $lockedException) {
                throw new \DomainException('The closure exception is not valid for this event.');
            }

            $decisions = $lockedException->decisions()->lockForUpdate()->orderBy('id')->get();
            $this->assertPermission($lockedActor, 'healthSafety.closureExceptions.approve');
            $this->assertSiteAccess($lockedActor, $lockedEvent);

            $approval = $decisions->firstWhere('decision', HsClosureExceptionDecision::DECISION_APPROVED);
            if (! $approval || $decisions->contains('decision', HsClosureExceptionDecision::DECISION_REVOKED)) {
                throw new \DomainException('Only a current approved closure exception can be revoked.');
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 10) {
                throw new \DomainException('A revocation reason of at least 10 characters is required.');
            }

            $decidedAt = CarbonImmutable::now();
            $provenance = [
                'exception_id' => (int) $lockedException->id,
                'request_provenance_hash' => $lockedException->provenance_hash,
                'previous_decision_id' => (int) $approval->id,
                'previous_decision_hash' => $approval->provenance_hash,
                'event_id' => (int) $lockedEvent->id,
                'site_id' => (int) $lockedEvent->site_id,
                'decision' => HsClosureExceptionDecision::DECISION_REVOKED,
                'approver_user_id' => (int) $lockedActor->id,
                'decided_at' => $decidedAt->toIso8601String(),
            ];
            $hash = $this->provenanceHash([$provenance, $reason]);

            $record = HsClosureExceptionDecision::query()->create([
                'hs_closure_exception_id' => $lockedException->id,
                'decision' => HsClosureExceptionDecision::DECISION_REVOKED,
                'decision_phase' => HsClosureExceptionDecision::PHASE_REVOCATION,
                'decided_by_user_id' => $lockedActor->id,
                'reason' => $reason,
                'previous_decision_id' => $approval->id,
                'decision_provenance' => $provenance,
                'provenance_hash' => $hash,
                'decided_at' => $decidedAt,
            ]);

            AuditLogger::logOrFail('healthSafety.event.closureExceptionRevoked', $lockedException, [
                'actor_id' => $lockedActor->id,
                'hs_event_id' => $lockedEvent->id,
                'site_id' => $lockedEvent->site_id,
                'decision_id' => $record->id,
                'provenance_hash' => $hash,
            ]);

            return $record->fresh(['decidedBy:id,name']);
        }, 3);
    }

    public function closeEvent(
        HsEvent $event,
        string $summary,
        User $actor,
        ?int $exceptionId = null,
    ): HsEvent {
        $summary = trim($summary);
        if ($summary === '') {
            throw new \DomainException('A closure summary is required.');
        }

        return DB::transaction(function () use ($event, $summary, $actor, $exceptionId): HsEvent {
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            $lockedActor = $this->lockActorAuthority($actor);
            $this->lockClosureDependencies($locked);
            $this->assertPermission($lockedActor, 'healthSafety.events.close');
            $this->assertSiteAccessAndOwnership($lockedActor, $locked);
            $this->assertOpen($locked);

            $exception = null;
            if ($exceptionId !== null) {
                $exception = HsClosureException::query()
                    ->whereKey($exceptionId)
                    ->where('hs_event_id', $locked->id)
                    ->where('site_id', $locked->site_id)
                    ->lockForUpdate()
                    ->first();
                if (! $exception) {
                    throw new \DomainException('The selected closure exception is not valid for this event.');
                }
                $exception->decisions()->lockForUpdate()->get();
                $exception->load('latestDecision');
            }

            $readiness = $this->readiness($locked);
            if ($readiness->hardBlockers() !== []) {
                throw new \DomainException(implode(' ', $readiness->hardBlockerLabels()));
            }
            if (! $readiness->canCloseWith($exception)) {
                if ($readiness->ordinaryAllowed() && $exception !== null) {
                    throw new \DomainException('This event is ready to close without an exception.');
                }
                throw new \DomainException(
                    'A current independently approved exception covering every exceptional blocker is required. '
                    .implode(' ', array_column($readiness->exceptionalBlockers(), 'label'))
                );
            }

            $this->withCanonicalCloseGuard($locked, function () use ($locked, $lockedActor, $summary, $exception, $readiness): void {
                $closedAt = now();
                $locked->forceFill([
                    'status' => HsEvent::STATUS_CLOSED,
                    'closed_at' => $closedAt,
                    'closed_by' => $lockedActor->id,
                    'closure_summary' => $summary,
                ])->save();

                AuditLogger::logOrFail(
                    $exception
                        ? 'healthSafety.event.closedWithException'
                        : 'healthSafety.event.closed',
                    $locked,
                    [
                        'actor_id' => $lockedActor->id,
                        'closure_summary' => $summary,
                        'exception_id' => $exception?->id,
                        'exception_provenance_hash' => $exception?->provenance_hash,
                        'blockers_at_close' => $readiness->blockers(),
                        'closed_at' => $closedAt->toIso8601String(),
                    ],
                );
            });

            Log::info('HsEventClosureService: event closed', [
                'hs_event_id' => $locked->id,
                'reference' => $locked->reference_number,
                'actor' => $lockedActor->id,
                'exception_id' => $exception?->id,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @return array{key: string, complete: bool, label: string, href: string, classification: string} */
    private function requirement(string $key, bool $complete, string $label, string $href, string $classification): array
    {
        return compact('key', 'complete', 'label', 'href', 'classification');
    }

    private function lockActorAuthority(User $actor): User
    {
        $locked = User::query()->lockForUpdate()->findOrFail($actor->id);
        HrEmployeeProfile::query()->where('user_id', $locked->id)->lockForUpdate()->get();
        $roleIds = DB::table('role_user')
            ->where('user_id', $locked->id)
            ->orderBy('role_id')
            ->lockForUpdate()
            ->pluck('role_id');
        DB::table('permission_user')
            ->where('user_id', $locked->id)
            ->orderBy('permission_id')
            ->lockForUpdate()
            ->get();
        if ($roleIds->isNotEmpty()) {
            DB::table('role_permission')
                ->whereIn('role_id', $roleIds)
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->lockForUpdate()
                ->get();
        }

        return $locked;
    }

    private function lockClosureDependencies(HsEvent $event): void
    {
        if ($event->site_id) {
            Site::query()->whereKey($event->site_id)->lockForUpdate()->get();
        }
        $incident = $this->linkedClientIncident($event, true);
        $alertIds = collect([
            $event->control_room_alert_id,
            $incident?->control_room_alert_id,
        ])->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($alertIds->isNotEmpty()) {
            ControlRoomAlert::query()->whereIn('id', $alertIds)->orderBy('id')->lockForUpdate()->get();
            AlertTask::query()->whereIn('alert_id', $alertIds)->orderBy('id')->lockForUpdate()->get();
        }
        $investigationIds = HsInvestigation::query()
            ->where('hs_event_id', $event->id)
            ->lockForUpdate()
            ->pluck('id');
        if ($investigationIds->isNotEmpty()) {
            HsRecommendationDisposition::query()
                ->whereIn('hs_investigation_id', $investigationIds)
                ->lockForUpdate()
                ->get();
        }
        HsCorrectiveAction::query()->where('hs_event_id', $event->id)->lockForUpdate()->get();
    }

    private function linkedClientIncident(HsEvent $event, bool $lock = false): ?ClientIncident
    {
        $query = ClientIncident::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        if (ltrim((string) $event->source_type, '\\') === ClientIncident::class && $event->source_id) {
            return $query->find($event->source_id);
        }

        return $query->where('hs_event_id', $event->id)->orderBy('id')->first();
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->canDo($permission)) {
            throw new \DomainException('You do not have the required H&S closure authority.');
        }
    }

    private function assertSiteAccessAndOwnership(User $actor, HsEvent $event): void
    {
        $this->assertSiteAccess($actor, $event);
        if ($event->owner_user_id !== null
            && (int) $event->owner_user_id !== (int) $actor->id
            && ! $actor->canDo('healthSafety.events.closeAny')
        ) {
            throw new \DomainException('Only the recorded H&S owner or an explicitly authorised H&S lead can close this event.');
        }
    }

    private function assertSiteAccess(User $actor, HsEvent $event): void
    {
        $siteIds = $this->siteAccess->accessibleHealthSafetySiteIds($actor);
        $applicationWide = $this->siteAccess->canAccessAllHealthSafetySites($actor);
        if ($event->site_id === null) {
            if (! $applicationWide) {
                throw new \DomainException(UserSiteAccessService::DEFAULT_MESSAGE);
            }

            return;
        }
        if (! in_array((int) $event->site_id, $siteIds, true)) {
            throw new \DomainException(UserSiteAccessService::DEFAULT_MESSAGE);
        }
    }

    private function assertOpen(HsEvent $event): void
    {
        if ($event->status === HsEvent::STATUS_CLOSED) {
            throw new \DomainException('This event is already closed.');
        }
    }

    /** @param  list<mixed>  $parts */
    private function provenanceHash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function withCanonicalCloseGuard(HsEvent $event, callable $callback): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET @hs_canonical_close_event_id = '.(int) $event->id);
        }

        try {
            $callback();
        } finally {
            if ($mysql) {
                DB::statement('SET @hs_canonical_close_event_id = NULL');
            }
        }
    }
}
