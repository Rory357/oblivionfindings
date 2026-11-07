<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Jobs\Notifications\DeliverControlRoomAlertNotificationJob;
use App\Services\NotificationService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central notification service for Control Room alerts.
 *
 * All operational notifications related to CR alerts must flow through this service.
 * This is the ONLY place where escalation-driven notifications originate.
 *
 * Notifications are a CONSEQUENCE of alert state changes (creation, escalation,
 * SLA breach, queue movement) — they do NOT drive escalation.
 */
class ControlRoomNotificationService
{
    private const OUTBOX_TEMPLATE_PREFIX = 'control-room-alert-notification-v2:';

    private const MAX_OUTBOX_ATTEMPTS = 3;

    /** O2: an SLA-breach escalation notice staged in the escalation's transaction and sent after it commits. */
    private const ESCALATION_TEMPLATE = 'control-room-sla-escalation-v1';

    /**
     * O2: for SLA-breach escalations only, role holders are loaded once per service instance (one SLA job run: the job
     * gets a fresh instance each run) with the relations their access check reads, keyed by role names. Every other
     * caller resolves fresh, as before. Who receives a notice is still decided per alert, exactly as before.
     *
     * @var array<string, Collection<int, User>>
     */
    private array $slaRoleHolderMemo = [];

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    /**
     * Notify on initial alert creation.
     */
    public function notifyAlert(ControlRoomAlert $alert, ?SignalRule $rule, ?TriageQueue $queue): void
    {
        $users = $this->authorizedRecipients(
            $alert,
            $this->resolveUsers($alert, $rule, $queue),
        );
        if ($users->isEmpty()) {
            return;
        }

        $this->sendToUsers($alert, $users, 'notification');
    }

    /**
     * Stage initial alert notifications as a transactional outbox.
     *
     * @return Collection<int, Communication>
     */
    public function stageAlertNotifications(
        ControlRoomAlert $alert,
        ?SignalRule $rule,
        ?TriageQueue $queue,
    ): Collection {
        $users = $this->authorizedRecipients(
            $alert,
            $this->resolveUsers($alert, $rule, $queue),
        );
        $generation = $this->routingGeneration($alert, $rule, $queue, $users);
        $template = self::OUTBOX_TEMPLATE_PREFIX.$generation;
        $snapshot = $this->notificationSnapshot($alert, $rule, $queue, $generation);
        $content = $this->buildContent($alert, 'notification', []);

        $communications = $users
            ->map(function (User $user) use ($alert, $content, $generation, $snapshot, $template): Communication {
                $deliveryKey = hash('sha256', implode('|', [
                    'control-room-alert-notification-v2',
                    $alert->id,
                    $generation,
                    $user->id,
                ]));

                $communication = Communication::query()->firstOrCreate(
                    ['delivery_key' => $deliveryKey],
                    [
                        'alert_id' => $alert->id,
                        'channel' => 'in_app',
                        'direction' => 'outbound',
                        'purpose' => 'notification',
                        'target_user_id' => $user->id,
                        'content' => $content,
                        'notification_payload' => $snapshot,
                        'template_used' => $template,
                        'status' => 'pending',
                        'initiated_by_user_id' => null,
                    ],
                );

                if (! is_array($communication->notification_payload)) {
                    $communication->forceFill([
                        'content' => $content,
                        'notification_payload' => $snapshot,
                    ])->save();
                }

                if ($communication->superseded_at !== null
                    && in_array($communication->status, ['pending', 'failed'], true)
                ) {
                    $communication->forceFill([
                        'superseded_at' => null,
                        'status_detail' => null,
                    ])->save();
                }

                return $communication;
            })
            ->values();

        Communication::query()
            ->where('alert_id', $alert->id)
            ->whereNotNull('delivery_key')
            ->where('purpose', 'notification')
            ->where('channel', 'in_app')
            ->whereIn('status', ['pending', 'failed'])
            ->whereNull('superseded_at')
            ->where(function ($query) use ($template): void {
                $query->whereNull('template_used')
                    ->orWhere('template_used', '!=', $template);
            })
            ->update([
                'superseded_at' => now(),
                'status_detail' => "Superseded by routing generation {$generation}",
            ]);

        return $communications;
    }

    /**
     * Deliver one staged row. The caller owns the database transaction so the
     * database notification and terminal outbox state commit atomically.
     *
     * @return list<int> Current-generation outbox rows that should be dispatched
     */
    public function deliverStagedNotification(Communication $communication): array
    {
        if ($communication->superseded_at !== null
            || (int) $communication->retry_count >= self::MAX_OUTBOX_ATTEMPTS
            || ! in_array($communication->status, ['pending', 'failed'], true)
        ) {
            return [];
        }

        // O2: an SLA-breach escalation notice staged by notifySlaBreachEscalation(). Its recipients were decided when the
        // breach was recorded; the medication content rule is checked again now, as sendToUsers() did.
        if ($communication->purpose === 'escalation'
            && $communication->template_used === self::ESCALATION_TEMPLATE
            && $communication->channel === 'in_app'
            && $communication->target_user_id !== null
        ) {
            $alert = ControlRoomAlert::query()->findOrFail($communication->alert_id);
            $user = User::query()->findOrFail($communication->target_user_id);
            if (! $this->authorizedRecipients($alert, collect([$user]))->contains('id', $user->id)) {
                $communication->forceFill([
                    'superseded_at' => now(),
                    'status_detail' => 'Superseded because the recipient no longer has access to this alert.',
                ])->save();

                return [];
            }
            $this->dispatcher->send($user, new ControlRoomAlertNotification($alert, (array) $communication->notification_payload));
            $communication->forceFill(['status' => 'sent', 'status_detail' => null, 'sent_at' => now()])->save();

            return [];
        }

        if ($communication->purpose !== 'notification'
            || $communication->channel !== 'in_app'
            || $communication->target_user_id === null
        ) {
            throw new \DomainException('Communication is not a staged Control Room alert notification.');
        }

        $alert = ControlRoomAlert::query()->findOrFail($communication->alert_id);
        $snapshot = [];
        if (str_starts_with((string) $communication->template_used, self::OUTBOX_TEMPLATE_PREFIX)) {
            $snapshot = $communication->notification_payload;
            if (! is_array($snapshot)) {
                $snapshot = json_decode((string) $communication->content, true);
            }
            if (! is_array($snapshot)) {
                throw new \DomainException('Staged Control Room alert notification snapshot is invalid.');
            }

            $rule = isset($snapshot['signal_rule_id'])
                ? SignalRule::query()->find($snapshot['signal_rule_id'])
                : null;
            $queue = $alert->queue_id === null
                ? null
                : TriageQueue::query()->find($alert->queue_id);
            $currentUsers = $this->authorizedRecipients(
                $alert,
                $this->resolveUsers($alert, $rule, $queue),
            );
            $currentGeneration = $this->routingGeneration($alert, $rule, $queue, $currentUsers);
            $currentTemplate = self::OUTBOX_TEMPLATE_PREFIX.$currentGeneration;

            if ($communication->template_used !== $currentTemplate
                || ! $currentUsers->contains(
                    fn (User $candidate): bool => (int) $candidate->id === (int) $communication->target_user_id,
                )
            ) {
                return $this->stageAlertNotifications($alert, $rule, $queue)
                    ->filter(fn (Communication $current): bool => $current->superseded_at === null
                        && (int) $current->retry_count < self::MAX_OUTBOX_ATTEMPTS
                        && in_array($current->status, ['pending', 'failed'], true)
                        && (int) $current->id !== (int) $communication->id)
                    ->pluck('id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        $user = User::query()->findOrFail($communication->target_user_id);
        if (! $this->authorizedRecipients($alert, collect([$user]))->contains('id', $user->id)) {
            $communication->forceFill([
                'superseded_at' => now(),
                'status_detail' => 'Superseded because the recipient no longer has access to this alert.',
            ])->save();

            return [];
        }

        $this->dispatcher->send($user, new ControlRoomAlertNotification($alert, $snapshot));

        $communication->forceFill([
            'status' => 'sent',
            'status_detail' => null,
            'sent_at' => now(),
        ])->save();

        return [];
    }

    private function routingGeneration(
        ControlRoomAlert $alert,
        ?SignalRule $rule,
        ?TriageQueue $queue,
        Collection $users,
    ): string {
        return hash('sha256', json_encode([
            'version' => 2,
            'alert_id' => (int) $alert->id,
            'severity' => $alert->severity,
            'alert_type' => $alert->alert_type,
            'source' => $alert->source,
            'queue_id' => $queue?->id,
            'signal_rule_id' => $rule?->id,
            'recipient_ids' => $users
                ->pluck('id')
                ->map(fn (int|string $id): int => (int) $id)
                ->sort()
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function notificationSnapshot(
        ControlRoomAlert $alert,
        ?SignalRule $rule,
        ?TriageQueue $queue,
        string $generation,
    ): array {
        return [
            'type' => 'control_room_alert',
            'alert_id' => (int) $alert->id,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'alert_type' => $alert->alert_type,
            'source' => $alert->source,
            'triggered_at' => $alert->triggered_at?->toISOString(),
            'escalation_level' => $alert->escalation_level,
            'routing_generation' => $generation,
            'queue_id' => $queue?->id,
            'signal_rule_id' => $rule?->id,
        ];
    }

    /**
     * Notify on SLA breach escalation.
     *
     * Sends notifications to the roles defined in the SlaDefinition's
     * breach_notify_roles, plus the alert's current queue assigned roles.
     */
    public function notifySlaBreachEscalation(
        ControlRoomAlert $alert,
        SlaDefinition $slaDefinition,
        array $breachTypes,
    ): void {
        $roles = collect($slaDefinition->breach_notify_roles ?? []);

        // Also notify the current queue's assigned roles
        if ($alert->queue_id) {
            $queue = TriageQueue::find($alert->queue_id);
            if ($queue?->assigned_roles) {
                $roles = $roles->merge($queue->assigned_roles);
            }
        }

        $users = $this->authorizedRecipients(
            $alert,
            $this->resolveUsersByRoles($alert, $roles->unique()->values()->toArray(), preload: true),
        );
        if ($users->isEmpty()) {
            return;
        }

        $breachLabel = implode(', ', $breachTypes);
        $extraContext = [
            'escalation_reason' => "SLA breach: {$breachLabel}",
            'breach_types' => $breachTypes,
            'escalation_level' => $alert->escalation_level,
        ];

        // O2 (Stephan, 6 Nov 2026: "Yes, H&S builds it"; Gemini's proposal): the same recipients and the same notice as
        // before, but the notice rows are written here, in the caller's transaction, and sent after it commits, so the
        // alert row lock isn't held while notices go out. A rollback discards the rows and nothing is sent; a send that
        // fails after commit stays pending or failed for the recovery sweep (RecoverControlRoomAlertNotificationsJob).
        $content = $this->buildContent($alert, 'escalation', $extraContext);
        $ids = $users->map(fn (User $user): int => (int) Communication::query()->firstOrCreate(
            ['delivery_key' => hash('sha256', implode('|', [
                self::ESCALATION_TEMPLATE, $alert->id, (int) $alert->escalation_level, implode(',', $breachTypes), $user->id,
            ]))],
            [
                'alert_id' => $alert->id,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'purpose' => 'escalation',
                'target_user_id' => $user->id,
                'content' => $content,
                'notification_payload' => $extraContext,
                'template_used' => self::ESCALATION_TEMPLATE,
                'status' => 'pending',
                'initiated_by_user_id' => null,
            ],
        )->id)->unique()->values()->all();

        DB::afterCommit(function () use ($ids): void {
            foreach ($ids as $id) {
                try {
                    DeliverControlRoomAlertNotificationJob::dispatch($id);
                } catch (\Throwable $exception) {
                    // The row is durable and stays pending or failed for the recovery sweep.
                    Log::error('Control Room SLA escalation notice could not be sent yet', [
                        'communication_id' => $id,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }


    /**
     * Notify on queue auto-escalation.
     *
     * Sends notifications to the target queue's assigned roles.
     */
    public function notifyQueueEscalation(
        ControlRoomAlert $alert,
        TriageQueue $fromQueue,
        TriageQueue $toQueue,
    ): void {
        $roles = collect($toQueue->assigned_roles ?? []);
        $users = $this->authorizedRecipients(
            $alert,
            $this->resolveUsersByRoles($alert, $roles->unique()->values()->toArray()),
        );
        if ($users->isEmpty()) {
            return;
        }

        $this->sendToUsers($alert, $users, 'escalation', [
            'escalation_reason' => "Auto-escalated from {$fromQueue->name} to {$toQueue->name}",
            'from_queue' => $fromQueue->name,
            'to_queue' => $toQueue->name,
            'escalation_level' => $alert->escalation_level,
        ]);
    }

    /**
     * Send notifications to a set of users and log as Communication records.
     */
    protected function sendToUsers(
        ControlRoomAlert $alert,
        Collection $users,
        string $purpose,
        array $extraContext = [],
    ): void {
        foreach ($this->authorizedRecipients($alert, $users) as $user) {
            $user->notify(new ControlRoomAlertNotification($alert, $extraContext));

            Communication::create([
                'alert_id' => $alert->id,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'purpose' => $purpose,
                'target_user_id' => $user->id,
                'content' => $this->buildContent($alert, $purpose, $extraContext),
                'status' => 'sent',
                'sent_at' => now(),
                'initiated_by_user_id' => null,
            ]);
        }
    }

    /**
     * Build human-readable content for Communication log.
     */
    protected function buildContent(ControlRoomAlert $alert, string $purpose, array $context): string
    {
        $base = "Alert {$alert->alert_type} ({$alert->severity})";

        if ($purpose === 'escalation' && isset($context['escalation_reason'])) {
            return "{$base} — {$context['escalation_reason']}";
        }

        return $base;
    }

    /**
     * Resolve users from a SignalRule and/or TriageQueue: explicitly named
     * users, plus the role holders who may open this alert.
     */
    protected function resolveUsers(ControlRoomAlert $alert, ?SignalRule $rule, ?TriageQueue $queue): Collection
    {
        $userIds = collect($rule?->notify_users ?? [])
            ->merge($queue?->assigned_users ?? [])
            ->unique()
            ->values();
        $roles = collect($rule?->notify_roles ?? [])
            ->merge($queue?->assigned_roles ?? []);

        $named = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email']);

        return $named
            ->merge($this->resolveUsersByRoles($alert, $roles->all()))
            ->unique('id')
            ->values();
    }

    /**
     * Resolve role routing to the role holders who may open this alert.
     *
     * Routing fields hold role-group names (the seeded signal rules use
     * 'managers_core' and 'coordinators', which are not role names) or
     * literal role names (Settings and the SLA page), so both resolve.
     */
    protected function resolveUsersByRoles(ControlRoomAlert $alert, array $roleNames, bool $preload = false): Collection
    {
        $names = collect($roleNames)
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->unique()
            ->values();
        if ($names->isEmpty()) {
            return collect();
        }

        $memoKey = $names->sort()->implode('|');
        if ($preload && array_key_exists($memoKey, $this->slaRoleHolderMemo)) {
            $holders = $this->slaRoleHolderMemo[$memoKey];
        } else {
            $groups = app(NotificationService::class);
            $userIds = User::query()
                ->whereHas('roles', fn ($rq) => $rq->whereIn('name', $names))
                ->pluck('id');
            foreach ($names as $name) {
                $userIds = $userIds->merge($groups->resolveRoleGroupUserIds($name));
            }

            $holders = $userIds->isEmpty() ? collect() : User::query()
                ->whereIn('id', $userIds->unique()->values())
                // O2: the relations the access check reads, loaded in one go rather than per person.
                ->when($preload, fn ($query) => $query->with([
                    'roles.permissions', 'permissionOverrides', 'hrEmployeeProfile', 'staffProfile',
                ]))
                ->get(['id', 'name', 'email']);
            if ($preload) {
                $this->slaRoleHolderMemo[$memoKey] = $holders;
            }
        }

        return $holders
            ->filter(fn (User $user): bool => $this->alertAccess->canReceiveRoleRoutedNotification($alert, $user))
            ->values();
    }

    /**
     * Medication alerts carry clinical context, and controlled-medication
     * alerts governed content, in their summaries, notes and history. Never
     * persist or deliver a medication notification to a recipient who cannot
     * open the canonical Site-scoped alert (controlled ones with the exact
     * controlled-content permission). Other alerts keep their explicitly
     * named recipients; their role routing is Site-scoped when resolved.
     */
    private function authorizedRecipients(ControlRoomAlert $alert, Collection $users): Collection
    {
        if (! $this->alertAccess->isMedicationAlert($alert)) {
            return $users->unique('id')->values();
        }

        return $users
            ->unique('id')
            ->filter(fn (User $user): bool => $this->alertAccess->canView($alert, $user))
            ->values();
    }
}
