<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synchronously projects a workplace injury into its mandatory H&S journey.
 *
 * Interactive callers execute this inside the same transaction as the source
 * write, so a required HsEvent, Control Room alert or WorkSafe register failure
 * rolls the injury back. The model observer invokes the same idempotent method
 * only as a compatibility repair path for imports and legacy writers.
 */
class WorkplaceInjuryJourneyService
{
    private const ALERT_SOURCE = 'operations';

    private const ALERT_TYPE = 'operations.workplace_injury';

    private const RETRACTION_OUTCOME = 'Reclassified as not WorkSafe-notifiable before notification.';

    public function __construct(
        private readonly HsEventService $hsEvents,
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly ControlRoomAlertLifecycleService $alertLifecycle,
    ) {}

    public function synchronize(WorkplaceInjury $injury): HsEvent
    {
        return DB::transaction(function () use ($injury): HsEvent {
            $locked = WorkplaceInjury::query()
                ->lockForUpdate()
                ->findOrFail($injury->getKey());
            $actor = $this->decisionActor($locked);
            $canSignWorksafeDecision = $actor->canDo('hazards.manage');

            $event = $this->hsEvents->recordEvent([
                'source' => $locked,
                'event_category' => HsEvent::CATEGORY_INJURY,
                'severity' => $locked->severity ?? 'moderate',
                'occurred_at' => $locked->injury_date,
                'reported_at' => $locked->created_at,
                'site_id' => $locked->site_id,
                'staff_id' => $locked->user_id,
                ...($canSignWorksafeDecision ? [
                    'worksafe_notifiable' => (bool) $locked->worksafe_notifiable,
                    'worksafe_decided_by_user_id' => $actor->id,
                    'worksafe_decision_reason' => $this->worksafeDecisionReason($locked),
                    'worksafe_decision_source' => 'incident_report',
                ] : []),
                'created_by' => $locked->created_by ?? $actor->id,
            ]);

            if (! $event) {
                throw new RuntimeException('The required H&S event projection could not be created.');
            }

            $this->synchronizeEvent($event, $locked, $actor);

            $this->synchronizeNotifiableIncident($locked, $actor);

            $alert = $this->synchronizeControlRoomAlert($locked, $event, $actor);
            if ($alert) {
                $this->hsEvents->linkControlRoomAlert($event, $alert->id);
            }

            return $event->fresh();
        }, 3);
    }

    private function synchronizeEvent(HsEvent $event, WorkplaceInjury $injury, User $actor): void
    {
        $normalisedSeverity = HsEventService::normaliseSeverity((string) $injury->severity);
        if ($event->severity !== $normalisedSeverity) {
            $this->hsEvents->syncSeverity($event, (string) $injury->severity);
            $event->refresh();
        }

        $sourceChanges = [
            'occurred_at' => $injury->injury_date,
            'site_id' => $injury->site_id,
            'staff_id' => $injury->user_id,
        ];
        if ((int) $event->site_id !== (int) $injury->site_id
            || (int) $event->staff_id !== (int) $injury->user_id
            || ! $event->occurred_at?->equalTo($injury->injury_date)
        ) {
            $event->forceFill($sourceChanges)->save();
            $event->refresh();
        }

        if ($actor->canDo('hazards.manage')
            && ($event->worksafe_notifiable !== (bool) $injury->worksafe_notifiable
                || ! $event->hasSignedWorksafeDecision())
        ) {
            $event = $this->hsEvents->recordWorksafeDecision(
                $event,
                (bool) $injury->worksafe_notifiable,
                $this->worksafeDecisionReason($injury),
                $actor,
                'incident_report',
            );
        }

        if ($injury->worksafe_notifiable && ! $event->investigation_required) {
            $event->forceFill(['investigation_required' => true])->save();
        }
    }

    private function ensureNotifiableIncident(WorkplaceInjury $injury, User $actor): NotifiableIncident
    {
        $occurred = $injury->injury_date ?? $injury->created_at ?? now();
        $reference = $injury->reference_number
            ?? 'WI-'.str_pad((string) $injury->id, 4, '0', STR_PAD_LEFT);

        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->lockForUpdate()
            ->first();
        if ($notifiable) {
            if ($notifiable->notification_authority !== 'worksafe'
                || (int) $notifiable->workplace_injury_id !== (int) $injury->id) {
                throw new RuntimeException('The existing WorkSafe projection does not match this workplace injury.');
            }
            if ($notifiable->status === 'closed') {
                if ($notifiable->outcome !== self::RETRACTION_OUTCOME) {
                    throw new RuntimeException('The closed WorkSafe projection cannot be reused automatically.');
                }
                $notifiable->forceFill([
                    'status' => 'pending',
                    'outcome' => null,
                    'closed_at' => null,
                    'closed_by' => null,
                ])->save();
                GovernanceAuditService::log(
                    'notifiable_incident.reopened_after_reclassification',
                    'NotifiableIncident',
                    $notifiable->id,
                    [
                        'source' => 'WorkplaceInjury',
                        'workplace_injury_id' => $injury->id,
                        'authority' => 'worksafe',
                        'actor_id' => $actor->id,
                    ],
                );
            }
        } else {
            $notifiable = NotifiableIncident::query()->create([
                'workplace_injury_id' => $injury->id,
                'incident_type' => 'serious_harm',
                'notification_authority' => 'worksafe',
                'title' => 'Workplace injury — '.$reference,
                'description' => $injury->description
                    ?: 'Notifiable workplace injury under the Health and Safety at Work Act 2015.',
                'severity' => $injury->severity === 'critical' ? 'critical' : 'high',
                'status' => 'pending',
                'occurred_at' => $occurred,
                'discovered_at' => $injury->created_at ?? now(),
                'notification_deadline' => Carbon::parse($occurred)->addDay(),
                'submitted_by' => $injury->created_by ?? $injury->updated_by ?? $actor->id,
            ]);
            GovernanceAuditService::log(
                'notifiable_incident.auto_created',
                'NotifiableIncident',
                $notifiable->id,
                [
                    'source' => 'WorkplaceInjury',
                    'workplace_injury_id' => $injury->id,
                    'authority' => 'worksafe',
                ],
            );
        }

        if (! $notifiable->wasRecentlyCreated) {
            $notifiable->forceFill([
                'title' => 'Workplace injury — '.$reference,
                'description' => $injury->description
                    ?: 'Notifiable workplace injury under the Health and Safety at Work Act 2015.',
                'severity' => $injury->severity === 'critical' ? 'critical' : 'high',
                'occurred_at' => $occurred,
                'notification_deadline' => Carbon::parse($occurred)->addDay(),
            ])->save();
        }

        return $notifiable;
    }

    private function synchronizeNotifiableIncident(WorkplaceInjury $injury, User $actor): void
    {
        if ($injury->worksafe_notifiable) {
            $this->ensureNotifiableIncident($injury, $actor);

            return;
        }

        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->lockForUpdate()
            ->first();
        if (! $notifiable) {
            return;
        }
        if ($notifiable->notification_authority !== 'worksafe'
            || (int) $notifiable->workplace_injury_id !== (int) $injury->id) {
            throw new RuntimeException('The existing WorkSafe projection does not match this workplace injury.');
        }
        if ($notifiable->status === 'notified'
            || $notifiable->notified_at !== null
            || filled($notifiable->notification_reference)) {
            throw new RuntimeException('A notified WorkSafe projection cannot be retracted automatically.');
        }
        if ($notifiable->status === 'closed' && $notifiable->outcome === self::RETRACTION_OUTCOME) {
            return;
        }

        $notifiable->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'outcome' => self::RETRACTION_OUTCOME,
        ])->save();
        GovernanceAuditService::log(
            'notifiable_incident.retracted_after_reclassification',
            'NotifiableIncident',
            $notifiable->id,
            [
                'source' => 'WorkplaceInjury',
                'workplace_injury_id' => $injury->id,
                'authority' => 'worksafe',
                'actor_id' => $actor->id,
            ],
        );
    }

    private function synchronizeControlRoomAlert(
        WorkplaceInjury $injury,
        HsEvent $event,
        User $actor,
    ): ?ControlRoomAlert {
        $activeAlert = $this->activeAlert($injury, $event);
        $context = $this->alertContext($injury);

        if (! $this->requiresControlRoomAlert($injury)) {
            if (! $activeAlert) {
                return null;
            }

            $activeAlert->forceFill([
                'severity' => $this->operationalSeverity($injury),
                'context' => array_merge($activeAlert->context ?? [], $context),
            ])->save();

            return $this->alertLifecycle->resolveAutomatically(
                $activeAlert,
                'The workplace injury was reclassified and no longer requires an active operational alert.',
                'workplace_injury_reclassified',
                'workplace_injury_journey',
                [
                    'resolved_by_user_id' => $actor->id,
                    'workplace_injury_id' => $injury->id,
                    'site_id' => $injury->site_id,
                ],
            );
        }

        return $this->ensureControlRoomAlert($injury, $event, $activeAlert);
    }

    private function ensureControlRoomAlert(
        WorkplaceInjury $injury,
        HsEvent $event,
        ?ControlRoomAlert $alert = null,
    ): ControlRoomAlert {
        $severity = $this->operationalSeverity($injury);
        $context = $this->alertContext($injury);

        if ($alert) {
            $alert->forceFill([
                'severity' => $severity,
                'context' => array_merge($alert->context ?? [], $context),
            ])->save();

            return $alert->fresh();
        }

        $bridged = $this->bridge->bridgeOperationalAlert('workplace_injury', $severity, $context);
        $alert = $this->activeAlert($injury, $event);

        if (! $bridged || ! $alert || (int) $bridged->id !== (int) $alert->id) {
            throw new RuntimeException('The required Control Room injury alert could not be created.');
        }

        return $alert;
    }

    private function activeAlert(WorkplaceInjury $injury, HsEvent $event): ?ControlRoomAlert
    {
        if ($event->control_room_alert_id) {
            $linked = ControlRoomAlert::query()
                ->whereKey($event->control_room_alert_id)
                ->lockForUpdate()
                ->first();
            if (! $linked) {
                throw new RuntimeException('The linked Control Room injury alert is unavailable.');
            }
            $this->assertAlertTuple($linked, $injury);
        }

        $alerts = ControlRoomAlert::query()
            ->where('source', self::ALERT_SOURCE)
            ->where('alert_type', self::ALERT_TYPE)
            ->where('context->workplace_injury_id', $injury->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $alerts->each(fn (ControlRoomAlert $alert) => $this->assertAlertTuple($alert, $injury));
        $active = $alerts->filter(fn (ControlRoomAlert $alert): bool => $alert->isActionable())->values();
        if ($active->count() > 1) {
            throw new RuntimeException('The workplace injury has multiple active Control Room alerts.');
        }

        return $active->first();
    }

    private function assertAlertTuple(ControlRoomAlert $alert, WorkplaceInjury $injury): void
    {
        if ($alert->source !== self::ALERT_SOURCE
            || $alert->alert_type !== self::ALERT_TYPE
            || $this->positiveId($alert->site_id) !== (int) $injury->site_id
            || $this->positiveId(data_get($alert->context, 'site_id')) !== (int) $injury->site_id
            || $this->positiveId(data_get($alert->context, 'workplace_injury_id')) !== (int) $injury->id) {
            throw new RuntimeException('The Control Room alert does not match the workplace injury source, type, and Site tuple.');
        }
    }

    /** @return array<string, mixed> */
    private function alertContext(WorkplaceInjury $injury): array
    {
        return [
            'workplace_injury_id' => $injury->id,
            'user_id' => $injury->user_id,
            'site_id' => $injury->site_id,
            'injury_type' => $injury->injury_type,
            'injury_severity' => $injury->severity,
            'body_part_affected' => $injury->body_part_affected,
            'worksafe_notifiable' => (bool) $injury->worksafe_notifiable,
            'injury_date' => $injury->injury_date?->toIso8601String(),
            'description' => $injury->description,
        ];
    }

    private function requiresControlRoomAlert(WorkplaceInjury $injury): bool
    {
        return (bool) $injury->worksafe_notifiable
            || in_array($injury->severity, ['serious', 'critical'], true);
    }

    private function operationalSeverity(WorkplaceInjury $injury): string
    {
        if ($injury->worksafe_notifiable) {
            return 'critical';
        }

        return match ($injury->severity) {
            'critical', 'serious' => 'high',
            'moderate' => 'medium',
            default => 'low',
        };
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (string) (int) $value !== $value) {
            return null;
        }

        return (int) $value;
    }

    private function decisionActor(WorkplaceInjury $injury): User
    {
        $actorId = $injury->updated_by
            ?? $injury->created_by
            ?? auth()->id()
            ?? $injury->user_id;

        $actor = User::query()->find($actorId);
        if (! $actor) {
            throw new RuntimeException('The workplace injury journey requires an attributable actor.');
        }

        return $actor;
    }

    private function worksafeDecisionReason(WorkplaceInjury $injury): string
    {
        return $injury->worksafe_notifiable
            ? 'The workplace injury intake classified this event as WorkSafe-notifiable.'
            : 'The workplace injury intake classified this event as not WorkSafe-notifiable.';
    }
}
