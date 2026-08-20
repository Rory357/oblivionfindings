<?php

namespace App\Services\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\SafeguardingTerminalTransition;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SafeguardingTerminalTransitionService
{
    public const OVERRIDE_PERMISSION = 'safeguarding.terminalOverride';

    public const CLOSE_ANY_PERMISSION = 'safeguarding.closeAny';

    private const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function close(
        SafeguardingConcern $concern,
        User $actor,
        string $summary,
        ?string $lessonsLearned = null,
        ?string $overrideReason = null,
    ): SafeguardingTerminalTransition {
        $summary = trim($summary);
        if ($summary === '') {
            throw ValidationException::withMessages(['closure_summary' => 'A closure summary is required.']);
        }

        $lessonsLearned = $this->optionalText($lessonsLearned);
        $overrideReason = $this->optionalText($overrideReason);

        return $this->submitAndApply(
            $concern,
            $actor,
            'closed',
            $summary,
            $overrideReason,
            [
                'status' => 'closed',
                'closure_summary' => $summary,
                'lessons_learned' => $lessonsLearned,
            ],
        );
    }

    /** @param  array<string, mixed>  $triageAttributes */
    public function noAction(
        SafeguardingConcern $concern,
        User $actor,
        array $triageAttributes,
    ): SafeguardingTerminalTransition {
        $reason = trim((string) ($triageAttributes['triage_notes'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['notes' => 'Record why no further action is required.']);
        }

        unset($triageAttributes['triaged_at'], $triageAttributes['updated_by']);

        return $this->submitAndApply(
            $concern,
            $actor,
            'no_action_required',
            $reason,
            null,
            [
                ...$triageAttributes,
                'status' => 'no_action_required',
                'closed_by_user_id' => $actor->id,
                'closure_summary' => $reason,
            ],
        );
    }

    /** @param  array<string, mixed>  $terminalAttributes */
    private function submitAndApply(
        SafeguardingConcern $concern,
        User $actor,
        string $targetStatus,
        string $reason,
        ?string $overrideReason,
        array $terminalAttributes,
    ): SafeguardingTerminalTransition {
        $requestPayload = $this->canonicalize([
            'concern_id' => (int) $concern->id,
            'target_status' => $targetStatus,
            'reason' => $reason,
            'override_reason' => $overrideReason,
            'terminal_attributes' => $this->serializeAttributes($terminalAttributes),
        ]);
        $requestHash = $this->hash($requestPayload);
        $idempotencyKey = hash('sha256', "safeguarding-terminal:{$concern->id}");

        $transition = DB::transaction(function () use (
            $concern,
            $actor,
            $targetStatus,
            $reason,
            $overrideReason,
            $requestHash,
            $idempotencyKey,
        ): SafeguardingTerminalTransition {
            $aggregate = $this->lockAggregate((int) $concern->id, (int) $actor->id);
            $authority = $this->assertAuthority($aggregate);

            $existing = SafeguardingTerminalTransition::query()
                ->where(function ($query) use ($idempotencyKey, $concern): void {
                    $query->where('idempotency_key', $idempotencyKey)
                        ->orWhere('safeguarding_concern_id', $concern->id);
                })
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $targetStatus, $requestHash);

                return $existing;
            }

            $this->assertStartingStatus($aggregate['concern'], $targetStatus);
            $safeguardingBlockers = $this->safeguardingBlockers($aggregate);
            $overrideApplied = $this->assertSafeguardingBlockers(
                $aggregate['actor'],
                $safeguardingBlockers,
                $overrideReason,
            );
            $this->assertOwningDomainsReady($aggregate);

            $evidence = $this->evidenceSnapshot($aggregate, $safeguardingBlockers, $overrideApplied);
            $evidenceHash = $this->hash($evidence);
            $requestedAt = now();

            return SafeguardingTerminalTransition::query()->create([
                'idempotency_key' => $idempotencyKey,
                'safeguarding_concern_id' => $aggregate['concern']->id,
                'hs_event_id' => $aggregate['event']->id,
                'control_room_alert_id' => $aggregate['alert']->id,
                'site_id' => $aggregate['concern']->site_id,
                'requested_by_user_id' => $aggregate['actor']->id,
                'target_status' => $targetStatus,
                'status' => SafeguardingTerminalTransition::STATUS_PENDING,
                'authority' => $overrideApplied ? self::OVERRIDE_PERMISSION : $authority['authority'],
                'reason' => $reason,
                'override_reason' => $overrideReason,
                'evidence_reference' => 'safeguarding:'.$aggregate['concern']->id.':'.substr($evidenceHash, 0, 24),
                'authority_snapshot' => $authority['snapshot'],
                'evidence_snapshot' => $evidence,
                'request_hash' => $requestHash,
                'evidence_hash' => $evidenceHash,
                'attempt_count' => 0,
                'requested_at' => $requestedAt,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        if ($transition->status === SafeguardingTerminalTransition::STATUS_APPLIED) {
            return $transition;
        }

        $attempt = (int) $transition->attempt_count + 1;
        try {
            return DB::transaction(function () use (
                $transition,
                $actor,
                $targetStatus,
                $requestHash,
                $terminalAttributes,
                $attempt,
            ): SafeguardingTerminalTransition {
                $aggregate = $this->lockAggregate(
                    (int) $transition->safeguarding_concern_id,
                    (int) $actor->id,
                );
                $authority = $this->assertAuthority($aggregate);
                $lockedTransition = SafeguardingTerminalTransition::query()
                    ->lockForUpdate()
                    ->findOrFail($transition->id);
                $this->assertReplayMatches($lockedTransition, $targetStatus, $requestHash);
                if ($lockedTransition->status === SafeguardingTerminalTransition::STATUS_APPLIED) {
                    return $lockedTransition;
                }
                $this->assertStartingStatus($aggregate['concern'], $targetStatus);
                $safeguardingBlockers = $this->safeguardingBlockers($aggregate);
                $overrideApplied = $this->assertSafeguardingBlockers(
                    $aggregate['actor'],
                    $safeguardingBlockers,
                    $lockedTransition->override_reason,
                );
                $this->assertOwningDomainsReady($aggregate);

                $evidence = $this->evidenceSnapshot($aggregate, $safeguardingBlockers, $overrideApplied);
                $evidenceHash = $this->hash($evidence);
                $appliedAt = now();
                $attributes = $terminalAttributes;
                $attributes['updated_by'] = $aggregate['actor']->id;
                if (in_array($targetStatus, SafeguardingConcern::TERMINAL_STATUSES, true)) {
                    $attributes['closed_by_user_id'] = $aggregate['actor']->id;
                    $attributes['closed_at'] = $appliedAt;
                }
                if ($targetStatus === 'no_action_required') {
                    $attributes['triaged_by_user_id'] = $aggregate['actor']->id;
                    $attributes['triaged_at'] = $appliedAt;
                }

                $aggregate['concern']->forceFill($attributes)->save();

                $context = $aggregate['alert']->context ?? [];
                unset($context['journey_attention']);
                $context['journey_terminal'] = [
                    'type' => 'safeguarding_terminal',
                    'transition_id' => $lockedTransition->id,
                    'safeguarding_concern_id' => $aggregate['concern']->id,
                    'hs_event_id' => $aggregate['event']->id,
                    'target_status' => $targetStatus,
                    'actor_id' => $aggregate['actor']->id,
                    'applied_at' => $appliedAt->toIso8601String(),
                    'evidence_hash' => $evidenceHash,
                ];
                $aggregate['alert']->forceFill(['context' => $context])->save();

                $provenanceHash = $this->hash($this->canonicalize([
                    'transition_id' => (int) $lockedTransition->id,
                    'request_hash' => $requestHash,
                    'evidence_hash' => $evidenceHash,
                    'actor_id' => (int) $aggregate['actor']->id,
                    'target_status' => $targetStatus,
                    'applied_at' => $appliedAt->toIso8601String(),
                ]));
                $lockedTransition->forceFill([
                    'hs_event_id' => $aggregate['event']->id,
                    'control_room_alert_id' => $aggregate['alert']->id,
                    'site_id' => $aggregate['concern']->site_id,
                    'applied_by_user_id' => $aggregate['actor']->id,
                    'status' => SafeguardingTerminalTransition::STATUS_APPLIED,
                    'authority' => $overrideApplied ? self::OVERRIDE_PERMISSION : $authority['authority'],
                    'authority_snapshot' => $authority['snapshot'],
                    'evidence_snapshot' => $evidence,
                    'evidence_hash' => $evidenceHash,
                    'provenance_hash' => $provenanceHash,
                    'attempt_count' => $attempt,
                    'last_error_code' => null,
                    'last_attempted_at' => $appliedAt,
                    'failed_at' => null,
                    'applied_at' => $appliedAt,
                ])->save();

                AuditLogger::logOrFail('safeguarding.concern.terminalTransitionApplied', $aggregate['concern'], [
                    'actor_id' => $aggregate['actor']->id,
                    'transition_id' => $lockedTransition->id,
                    'hs_event_id' => $aggregate['event']->id,
                    'control_room_alert_id' => $aggregate['alert']->id,
                    'site_id' => $aggregate['concern']->site_id,
                    'target_status' => $targetStatus,
                    'authority' => $lockedTransition->authority,
                    'request_hash' => $requestHash,
                    'evidence_hash' => $evidenceHash,
                    'provenance_hash' => $provenanceHash,
                    'override_applied' => $overrideApplied,
                    'applied_at' => $appliedAt->toIso8601String(),
                ]);

                return $lockedTransition->fresh();
            }, self::TRANSACTION_ATTEMPTS);
        } catch (Throwable $exception) {
            $this->markFailed($transition->id, $attempt, $exception);

            throw $exception;
        }
    }

    /**
     * @return array{
     *   concern: SafeguardingConcern,
     *   actor: User,
     *   incident: ClientIncident|null,
     *   event: HsEvent,
     *   alert: ControlRoomAlert,
     *   safeguarding_investigations: Collection,
     *   safeguarding_actions: Collection,
     *   safeguarding_reports: Collection,
     *   alert_tasks: Collection,
     *   hs_investigations: Collection,
     *   hs_actions: Collection
     * }
     */
    private function lockAggregate(int $concernId, int $actorId): array
    {
        $concern = SafeguardingConcern::query()->lockForUpdate()->findOrFail($concernId);
        $actor = $this->lockActorAuthority($actorId);
        if ($concern->site_id) {
            Site::query()->whereKey($concern->site_id)->lockForUpdate()->get();
        }

        $incident = null;
        if ($concern->concern_type === 'incident_escalation' && $concern->related_incident_id) {
            $incident = ClientIncident::query()->lockForUpdate()->find($concern->related_incident_id);
            if (! $incident) {
                throw new DomainException('The canonical incident journey is unavailable.');
            }
        }

        $event = $this->lockCanonicalEvent($concern, $incident);
        $alert = $this->lockCanonicalAlert($concern, $incident, $event);

        return [
            'concern' => $concern,
            'actor' => $actor,
            'incident' => $incident,
            'event' => $event,
            'alert' => $alert,
            'safeguarding_investigations' => SafeguardingInvestigation::query()
                ->where('safeguarding_concern_id', $concern->id)->orderBy('id')->lockForUpdate()->get(),
            'safeguarding_actions' => SafeguardingActionPlan::query()
                ->where('safeguarding_concern_id', $concern->id)->orderBy('id')->lockForUpdate()->get(),
            'safeguarding_reports' => SafeguardingExternalReport::query()
                ->where('safeguarding_concern_id', $concern->id)->orderBy('id')->lockForUpdate()->get(),
            'alert_tasks' => AlertTask::query()
                ->where('alert_id', $alert->id)->orderBy('id')->lockForUpdate()->get(),
            'hs_investigations' => HsInvestigation::query()
                ->where('hs_event_id', $event->id)->orderBy('id')->lockForUpdate()->get(),
            'hs_actions' => HsCorrectiveAction::query()
                ->where('hs_event_id', $event->id)->orderBy('id')->lockForUpdate()->get(),
        ];
    }

    private function lockCanonicalEvent(SafeguardingConcern $concern, ?ClientIncident $incident): HsEvent
    {
        if ($incident) {
            $events = HsEvent::query()
                ->where(function ($query) use ($incident): void {
                    if ($incident->hs_event_id) {
                        $query->whereKey($incident->hs_event_id)->orWhere(function ($source) use ($incident): void {
                            $source->whereIn('source_type', [ClientIncident::class, '\\'.ClientIncident::class])
                                ->where('source_id', $incident->id);
                        });
                    } else {
                        $query->whereIn('source_type', [ClientIncident::class, '\\'.ClientIncident::class])
                            ->where('source_id', $incident->id);
                    }
                })
                ->orderBy('id')
                ->limit(2)
                ->lockForUpdate()
                ->get();
        } else {
            $events = HsEvent::query()
                ->whereIn('source_type', [SafeguardingConcern::class, '\\'.SafeguardingConcern::class])
                ->where('source_id', $concern->id)
                ->where('event_category', HsEvent::CATEGORY_SAFEGUARDING)
                ->orderBy('id')
                ->limit(2)
                ->lockForUpdate()
                ->get();
        }

        if ($events->count() !== 1) {
            throw new DomainException('One canonical H&S safeguarding event is required.');
        }

        /** @var HsEvent $event */
        $event = $events->first();
        if ($incident && $incident->hs_event_id && (int) $incident->hs_event_id !== (int) $event->id) {
            throw new DomainException('The incident and H&S safeguarding journey do not identify the same event.');
        }
        if (! $incident) {
            $expectedKey = HsEvent::buildIdempotencyKey(
                SafeguardingConcern::class,
                $concern->id,
                HsEvent::CATEGORY_SAFEGUARDING,
            );
            if (! hash_equals($expectedKey, (string) $event->idempotency_key)) {
                throw new DomainException('The H&S safeguarding event provenance is invalid.');
            }
        }

        return $event;
    }

    private function lockCanonicalAlert(
        SafeguardingConcern $concern,
        ?ClientIncident $incident,
        HsEvent $event,
    ): ControlRoomAlert {
        $alertIds = collect([$event->control_room_alert_id, $incident?->control_room_alert_id])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($alertIds->count() !== 1) {
            throw new DomainException('The canonical Control Room safeguarding alert is missing or conflicting.');
        }

        $alert = ControlRoomAlert::query()->lockForUpdate()->find($alertIds->first());
        if (! $alert) {
            throw new DomainException('The canonical Control Room safeguarding alert is unavailable.');
        }
        if (! $incident && (int) data_get($alert->context, 'concern_id') !== (int) $concern->id) {
            throw new DomainException('The Control Room alert does not belong to this safeguarding concern.');
        }

        return $alert;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array{authority: string, snapshot: array<string, mixed>}
     */
    private function assertAuthority(array $aggregate): array
    {
        /** @var SafeguardingConcern $concern */
        $concern = $aggregate['concern'];
        /** @var User $actor */
        $actor = $aggregate['actor'];
        $siteId = $concern->site_id ? (int) $concern->site_id : null;
        $globalSiteAccess = $this->siteAccess->canBypass($actor, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId === null) {
            if (! $globalSiteAccess) {
                $this->conceal($concern);
            }
        } elseif (! $globalSiteAccess
            && ! in_array($siteId, $this->siteAccess->accessibleSiteIds($actor), true)) {
            $this->conceal($concern);
        }

        $canSeeSensitive = ! $concern->is_sensitive
            || $actor->canDo('safeguarding.viewSensitive')
            || (int) $concern->assigned_to_user_id === (int) $actor->id
            || (int) $concern->reported_by_user_id === (int) $actor->id;
        if (! $canSeeSensitive) {
            $this->conceal($concern);
        }

        $hasUpdatePermission = $actor->canDo('safeguarding.update');
        $isAssignedOwner = (int) $concern->assigned_to_user_id === (int) $actor->id;
        if (! $hasUpdatePermission && ! $isAssignedOwner) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        if ($concern->assigned_to_user_id !== null
            && ! $isAssignedOwner
            && ! $actor->canDo(self::CLOSE_ANY_PERMISSION)) {
            throw new AuthorizationException('Only the assigned safeguarding lead or an explicitly authorised safeguarding lead can close this concern.');
        }

        $this->assertCanonicalSiteTuple($aggregate);
        $authority = match (true) {
            $isAssignedOwner => 'assigned_safeguarding_owner',
            $actor->canDo(self::CLOSE_ANY_PERMISSION) => self::CLOSE_ANY_PERMISSION,
            default => 'safeguarding.update',
        };

        return [
            'authority' => $authority,
            'snapshot' => $this->canonicalize([
                'actor_id' => (int) $actor->id,
                'site_id' => $siteId,
                'site_scope' => $globalSiteAccess ? 'explicit_global' : 'assigned_site',
                'assigned_owner' => $isAssignedOwner,
                'permissions' => [
                    'safeguarding.update' => $hasUpdatePermission,
                    self::CLOSE_ANY_PERMISSION => $actor->canDo(self::CLOSE_ANY_PERMISSION),
                    self::OVERRIDE_PERMISSION => $actor->canDo(self::OVERRIDE_PERMISSION),
                    'reports.viewAny' => $actor->canDo('reports.viewAny'),
                    'safeguarding.viewSensitive' => $actor->canDo('safeguarding.viewSensitive'),
                ],
            ]),
        ];
    }

    /** @param  array<string, mixed>  $aggregate */
    private function assertCanonicalSiteTuple(array $aggregate): void
    {
        $siteValues = [
            $aggregate['concern']->site_id,
            $aggregate['event']->site_id,
            $aggregate['alert']->site_id,
        ];
        if ($aggregate['incident']) {
            $siteValues[] = $aggregate['incident']->site_id;
        }
        $siteIds = collect($siteValues)
            ->map(fn ($id): ?int => is_numeric($id) && (int) $id > 0 ? (int) $id : null)
            ->uniqueStrict()
            ->values();

        if ($siteIds->count() !== 1) {
            throw new DomainException('The safeguarding, H&S and Control Room records do not share one canonical Site.');
        }
    }

    private function assertStartingStatus(SafeguardingConcern $concern, string $targetStatus): void
    {
        if ($targetStatus === 'no_action_required' && $concern->status !== 'reported') {
            throw new DomainException('This concern has already been triaged.');
        }
        if ($targetStatus === 'closed' && $concern->status === 'reported') {
            throw new DomainException('Triage the concern before closing.');
        }
        if (in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true)) {
            throw new DomainException('This concern is already closed.');
        }
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return list<array{type: string, id: int|null, status: string}>
     */
    private function safeguardingBlockers(array $aggregate): array
    {
        $blockers = [];
        foreach ($aggregate['safeguarding_investigations'] as $investigation) {
            if (! in_array($investigation->status, ['completed', 'abandoned'], true)) {
                $blockers[] = ['type' => 'investigation', 'id' => (int) $investigation->id, 'status' => (string) $investigation->status];
            }
        }
        foreach ($aggregate['safeguarding_actions'] as $action) {
            if (! in_array($action->status, ['completed', 'cancelled'], true)) {
                $blockers[] = ['type' => 'action_plan', 'id' => (int) $action->id, 'status' => (string) $action->status];
            }
        }
        if ($aggregate['concern']->requires_external_referral && $aggregate['safeguarding_reports']->isEmpty()) {
            $blockers[] = ['type' => 'external_referral', 'id' => null, 'status' => 'unlogged'];
        }

        return $blockers;
    }

    /** @param  list<array{type: string, id: int|null, status: string}>  $blockers */
    private function assertSafeguardingBlockers(User $actor, array $blockers, ?string $overrideReason): bool
    {
        if ($blockers === []) {
            return false;
        }
        if ($overrideReason === null) {
            throw ValidationException::withMessages([
                'override_reason' => 'Open work or an unlogged referral remains — record why you are closing anyway.',
            ]);
        }
        if (! $actor->canDo(self::OVERRIDE_PERMISSION)) {
            throw ValidationException::withMessages([
                'override_reason' => 'An override reason alone cannot authorise closure with open safeguarding work.',
            ]);
        }
        if (mb_strlen($overrideReason) < 20) {
            throw ValidationException::withMessages([
                'override_reason' => 'Record a specific override reason of at least 20 characters.',
            ]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $aggregate */
    private function assertOwningDomainsReady(array $aggregate): void
    {
        /** @var HsEvent $event */
        $event = $aggregate['event'];
        if ($event->status !== HsEvent::STATUS_CLOSED
            || $event->closed_at === null
            || $event->closed_by === null
            || blank($event->closure_summary)) {
            throw new DomainException('Close the canonical H&S safeguarding event with its required evidence first.');
        }
        if ($aggregate['hs_investigations']->contains(
            fn (HsInvestigation $investigation): bool => $investigation->status !== HsInvestigation::STATUS_COMPLETED,
        )) {
            throw new DomainException('Complete the canonical H&S investigation before closing safeguarding.');
        }
        if ($aggregate['hs_actions']->contains(
            fn (HsCorrectiveAction $action): bool => ! in_array($action->status, [
                HsCorrectiveAction::STATUS_VERIFIED,
                HsCorrectiveAction::STATUS_CLOSED,
            ], true),
        )) {
            throw new DomainException('Verify or close the canonical H&S corrective work before closing safeguarding.');
        }

        /** @var ControlRoomAlert $alert */
        $alert = $aggregate['alert'];
        if (! in_array($alert->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)
            || $alert->resolved_at === null
            || $alert->resolved_by_user_id === null
            || blank($alert->resolution_code)) {
            throw new DomainException('Resolve the canonical Control Room safeguarding response with its required evidence first.');
        }
        if ($alert->status === ControlRoomAlert::STATUS_CLOSED
            && ($alert->closed_at === null || $alert->closed_by_user_id === null)) {
            throw new DomainException('The canonical Control Room alert closure provenance is incomplete.');
        }
        if ($aggregate['alert_tasks']->contains(
            fn (AlertTask $task): bool => ! in_array($task->status, AlertTask::TERMINAL_STATUSES, true),
        )) {
            throw new DomainException('Complete, cancel with governance, or transfer every protective Control Room task first.');
        }

        $actionsBySourceTask = $aggregate['hs_actions']->keyBy('source_control_room_task_id');
        foreach ($aggregate['alert_tasks']->where('status', AlertTask::STATUS_TRANSFERRED) as $task) {
            $action = $actionsBySourceTask->get($task->id);
            if (! $action
                || (int) $task->transferred_to_hs_corrective_action_id !== (int) $action->id
                || $task->transferred_at === null
                || $task->transferred_by_user_id === null
                || ! in_array($action->status, [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED], true)) {
                throw new DomainException('Transferred protective work does not have one completed canonical H&S action.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @param  list<array{type: string, id: int|null, status: string}>  $safeguardingBlockers
     * @return array<string, mixed>
     */
    private function evidenceSnapshot(array $aggregate, array $safeguardingBlockers, bool $overrideApplied): array
    {
        return $this->canonicalize([
            'concern' => [
                'id' => (int) $aggregate['concern']->id,
                'site_id' => $aggregate['concern']->site_id ? (int) $aggregate['concern']->site_id : null,
                'status' => (string) $aggregate['concern']->status,
                'assigned_to_user_id' => $aggregate['concern']->assigned_to_user_id ? (int) $aggregate['concern']->assigned_to_user_id : null,
                'open_blockers' => $safeguardingBlockers,
                'override_applied' => $overrideApplied,
            ],
            'health_safety' => [
                'id' => (int) $aggregate['event']->id,
                'site_id' => $aggregate['event']->site_id ? (int) $aggregate['event']->site_id : null,
                'status' => (string) $aggregate['event']->status,
                'closed_at' => $aggregate['event']->closed_at?->toIso8601String(),
                'closed_by' => $aggregate['event']->closed_by ? (int) $aggregate['event']->closed_by : null,
                'investigations' => $aggregate['hs_investigations']->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'status' => (string) $item->status,
                ])->values()->all(),
                'corrective_actions' => $aggregate['hs_actions']->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'status' => (string) $item->status,
                    'source_control_room_task_id' => $item->source_control_room_task_id ? (int) $item->source_control_room_task_id : null,
                ])->values()->all(),
            ],
            'control_room' => [
                'id' => (int) $aggregate['alert']->id,
                'site_id' => $aggregate['alert']->site_id ? (int) $aggregate['alert']->site_id : null,
                'status' => (string) $aggregate['alert']->status,
                'resolution_code' => (string) $aggregate['alert']->resolution_code,
                'resolved_at' => $aggregate['alert']->resolved_at?->toIso8601String(),
                'resolved_by_user_id' => $aggregate['alert']->resolved_by_user_id ? (int) $aggregate['alert']->resolved_by_user_id : null,
                'tasks' => $aggregate['alert_tasks']->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'status' => (string) $item->status,
                    'transferred_to_hs_corrective_action_id' => $item->transferred_to_hs_corrective_action_id
                        ? (int) $item->transferred_to_hs_corrective_action_id
                        : null,
                ])->values()->all(),
            ],
        ]);
    }

    private function lockActorAuthority(int $actorId): User
    {
        $actor = User::query()->lockForUpdate()->findOrFail($actorId);
        HrEmployeeProfile::query()->where('user_id', $actor->id)->lockForUpdate()->get();
        $roleIds = DB::table('role_user')->where('user_id', $actor->id)->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('permission_user')->where('user_id', $actor->id)->orderBy('permission_id')->lockForUpdate()->get();
        if ($roleIds->isNotEmpty()) {
            DB::table('role_permission')
                ->whereIn('role_id', $roleIds)
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->lockForUpdate()
                ->get();
        }
        $actor->unsetRelations();

        return $actor;
    }

    private function assertReplayMatches(
        SafeguardingTerminalTransition $transition,
        string $targetStatus,
        string $requestHash,
    ): void {
        if ($transition->target_status !== $targetStatus || ! hash_equals($transition->request_hash, $requestHash)) {
            throw new DomainException('A different terminal transition request already owns this safeguarding concern.');
        }
    }

    private function markFailed(int $transitionId, int $attempt, Throwable $exception): void
    {
        DB::transaction(function () use ($transitionId, $attempt, $exception): void {
            $transition = SafeguardingTerminalTransition::query()->lockForUpdate()->find($transitionId);
            if (! $transition || $transition->status === SafeguardingTerminalTransition::STATUS_APPLIED) {
                return;
            }
            $transition->forceFill([
                'status' => SafeguardingTerminalTransition::STATUS_FAILED,
                'attempt_count' => max($attempt, (int) $transition->attempt_count + 1),
                'last_error_code' => class_basename($exception),
                'last_attempted_at' => now(),
                'failed_at' => now(),
            ])->save();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function conceal(SafeguardingConcern $concern): never
    {
        throw (new ModelNotFoundException)->setModel(SafeguardingConcern::class, [$concern->id]);
    }

    private function optionalText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function serializeAttributes(array $attributes): array
    {
        return collect($attributes)->map(function ($value) {
            if ($value instanceof Carbon) {
                return $value->toIso8601String();
            }
            if ($value instanceof DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            return $value;
        })->all();
    }

    /** @return array<string, mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn ($nested) => is_array($nested) ? $this->canonicalize($nested) : $nested, $item)
                    : $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param  array<string, mixed>  $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
