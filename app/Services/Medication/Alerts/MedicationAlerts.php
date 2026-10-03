<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\MedicationAlertRecipient;
use App\Models\User;
use App\Notifications\MedicationAlertNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way a medication alert is raised (eMAR P11 B2).
 *
 * raise() writes the alert's record in the alert log — the record every
 * recipient shares — then tells the people Medication Settings chooses, on
 * the alert's channels. While an alert about the same subject is open,
 * raising it again tells nobody: the open record is the de-duplication, in
 * the database, so a deploy's cache clear can't repeat it and two schedulers
 * racing tell people once.
 *
 * An alert stays open until it is dealt with: resolve() for one subject when
 * its source is put right, reconcile() for a scheduled check that lists every
 * subject still true. Then the same subject can alert again.
 *
 * Quiet hours (B2 chunk 5): during the house's window, email and push for an
 * alert without Follow up wait (held_until) and releaseHeld() sends them
 * when it ends. The bell is never held.
 */
class MedicationAlerts
{
    public function __construct(
        private readonly MedicationAlertRecipients $recipients,
        private readonly MedicationAlertSettings $settings,
        private readonly MedicationQuietHours $quietHours,
    ) {}

    /**
     * Raise an alert. Null when it isn't offered or one is already open for
     * this subject.
     *
     * One transaction (B2 C1 review): the open record, its recipients and the
     * in-app notifications commit together. If anything fails part-way, none
     * of it is kept, so a failure can't leave a silent open record that stops
     * the next check raising the alert again.
     */
    public function raise(string $type, MedicationAlertSubject $subject): ?MedicationAlert
    {
        if (! MedicationAlertCatalogue::isBuilt($type)) {
            return null;
        }
        $setting = $this->settings->forAlert($type);
        $channels = $this->settings->channels($type);
        $key = $this->openKey($type, $subject->key);

        try {
            return DB::transaction(fn (): MedicationAlert => $this->record($type, $subject, $key, $setting, $channels));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param  array{inapp: bool, email: bool, push: bool, follow_up: bool, groups: list<string>, people: list<int>}|null  $setting
     * @param  list<string>  $channels
     */
    private function record(string $type, MedicationAlertSubject $subject, string $key, ?array $setting, array $channels): MedicationAlert
    {
        $now = now();
        // After hours on the alert log (Main's answer): the house's own quiet
        // hours, else the organisation default — and which one applied.
        $afterHours = $this->quietHours->afterHoursWindow($subject->siteId);
        $isAfterHours = $afterHours !== null && MedicationQuietHours::within($afterHours, $now);
        $alert = MedicationAlert::query()->create([
            'type' => $type,
            'dedupe_key' => $key,
            'open_key' => $key,
            'site_id' => $subject->siteId,
            'client_id' => $subject->clientId,
            'staff_user_id' => $subject->staffUserId,
            'controlled' => $subject->controlled || (MedicationAlertCatalogue::get($type)['controlled'] ?? false),
            'title' => Str::limit($subject->title, 188),
            'message' => $subject->message,
            'short_message' => Str::limit($subject->shortMessage, 188),
            'action_url' => $subject->actionUrl,
            'severity' => $subject->severity,
            'subject' => $subject->context,
            'follow_up' => (bool) ($setting['follow_up'] ?? false),
            'reached_nobody' => false,
            'after_hours' => $isAfterHours,
            'after_hours_source' => $isAfterHours ? $afterHours['source'] : null,
            'status' => MedicationAlert::STATUS_OPEN,
            'raised_at' => $now,
        ]);

        $resolved = $this->recipients->resolve($type, new MedicationAlertSubject(
            key: $subject->key,
            siteId: $subject->siteId,
            title: $subject->title,
            message: $subject->message,
            shortMessage: $subject->shortMessage,
            actionUrl: $subject->actionUrl,
            severity: $subject->severity,
            clientId: $subject->clientId,
            controlled: $alert->controlled,
            staffUserId: $subject->staffUserId,
            context: $subject->context,
        ), $now);

        // No channel switched on means nobody is told, whoever the groups are
        // (B2 C1 review): no recipient is recorded as told.
        $followUp = $this->settings->followUp();
        ['told' => $told, 'unreachable' => $unreachable, 'held' => $held] = $this->deliver(
            $alert,
            $resolved['told'],
            $channels,
            0,
            MedicationAlertNotification::FIRST,
            $now,
            $followUp,
        );
        // Follow up (B2 chunk 3): when the first re-alert or escalation is due.
        if ($alert->follow_up && $told !== []) {
            $alert->forceFill(['next_follow_up_at' => MedicationAlertFollowUps::nextDue($alert, $followUp)])->save();
        }

        $this->event($alert, MedicationAlertEvent::SENT, ['told' => $told, 'channels' => $channels]);
        if ($held !== null) {
            $this->event($alert, MedicationAlertEvent::HELD, [
                'user_ids' => $held['user_ids'],
                'until' => $held['until']->toIso8601String(),
                'source' => $held['source'],
                'note' => 'Email and push wait until quiet hours end. The bell showed it straight away.',
            ]);
        }
        if ($unreachable !== []) {
            $this->event($alert, MedicationAlertEvent::NOT_REACHABLE, [
                'user_ids' => $unreachable,
                'reason' => 'In-app is off for this alert, and they have no work email or push set up for its other channels.',
            ]);
        }
        if ($resolved['not_told_controlled'] !== []) {
            $this->event($alert, MedicationAlertEvent::NOT_TOLD_CONTROLLED, ['user_ids' => $resolved['not_told_controlled']]);
        }
        if ($resolved['fallback'] && $channels !== []) {
            $this->event($alert, MedicationAlertEvent::FALLBACK, [
                'groups' => $setting['groups'] ?? [],
                'note' => 'Nobody in its groups at this house — sent to medication settings managers.',
            ]);
        }
        if ($told === []) {
            $reason = match (true) {
                $channels === [] => 'No way to tell people is switched on for this alert.',
                $unreachable !== [] => 'Nobody it would go to can be reached: in-app is off, and they have no work email or push set up.',
                default => $resolved['nobody_reason'],
            };
            // Never a silent log row: Settings counts open alerts that reached nobody.
            $alert->forceFill(['reached_nobody' => true])->save();
            $this->event($alert, MedicationAlertEvent::NOBODY_TOLD, [
                'groups' => $setting['groups'] ?? [],
                'reason' => $reason,
                'would_have_told' => $channels === []
                    ? array_map(fn (array $candidate): int => (int) $candidate['user']->id, $resolved['told'])
                    : [],
            ]);
            Log::warning('Medication alert: nobody could be told', [
                'medication_alert_id' => $alert->id,
                'type' => $type,
                'site_id' => $subject->siteId,
                'reason' => $reason,
            ]);
        }

        return $alert;
    }

    /**
     * Tell these people about the alert, as one step of it (0: when raised;
     * then each re-alert or escalation). Each person is told on the channels
     * they can be reached on (B2 chunk 2): the bell here, in the caller's
     * transaction; email and push once it commits, so a slow mail server
     * can't hold the record or undo it. A Follow up alert waiting for an
     * acknowledgement asks for one in the bell (B2 chunk 3).
     *
     * Quiet hours (B2 chunk 5): when an alert without Follow up is raised
     * inside the house's window, email and push wait until it ends — the
     * bell doesn't. Re-alerts and escalations are only for Follow up alerts,
     * so they're never held. Someone reached only by a held channel is told
     * when it's released.
     *
     * @param  iterable<array{user: User, reason: string}>  $people
     * @param  list<string>  $channels
     * @param  array{realert_every: int|null, realert_max: int|null, attended: string, escalate_after: int|null, escalate_to: list<string>}  $followUp
     * @return array{told: list<array{user_id: int, reason: string, channels: list<string>}>, unreachable: list<int>, held: array{user_ids: list<int>, until: Carbon, source: string}|null}
     */
    public function deliver(MedicationAlert $alert, iterable $people, array $channels, int $step, string $kind, CarbonInterface $now, array $followUp): array
    {
        $private = $this->settings->privateDelivery();
        $ack = $alert->follow_up && ($followUp['attended'] ?? 'ack') === 'ack';
        $told = [];
        $unreachable = [];
        $afterCommit = [];
        $heldIds = [];
        if ($channels === []) {
            return ['told' => [], 'unreachable' => [], 'held' => null];
        }
        $window = $kind === MedicationAlertNotification::FIRST && ! $alert->follow_up
            ? $this->quietHours->window($alert->site_id !== null ? (int) $alert->site_id : null)
            : null;
        $holdUntil = $window !== null ? MedicationQuietHours::endsAt($window, $now) : null;
        foreach ($people as ['user' => $user, 'reason' => $reason]) {
            $reach = $this->reachable($user, $channels);
            if ($reach === []) {
                $unreachable[] = (int) $user->id;

                continue;
            }
            $notificationId = null;
            if (in_array('inapp', $reach, true)) {
                $notification = new MedicationAlertNotification($alert, ['inapp'], $private, $kind, $ack);
                $notification->id = (string) Str::uuid();
                $user->notify($notification);
                $notificationId = $notification->id;
            }
            $outside = array_values(array_diff($reach, ['inapp']));
            $held = $holdUntil !== null && $outside !== [];
            if ($held) {
                $heldIds[] = (int) $user->id;
            } elseif ($outside !== []) {
                $afterCommit[] = [$user, $outside];
            }
            MedicationAlertRecipient::query()->create([
                'medication_alert_id' => $alert->id,
                'user_id' => $user->id,
                'reason' => $reason,
                'step' => $step,
                'channels' => $reach,
                // Told when something reached them: the bell now, or a held
                // email or push when quiet hours end.
                'told_at' => $notificationId !== null || ! $held ? $now : null,
                'notification_id' => $notificationId,
                'held_until' => $held ? $holdUntil : null,
            ]);
            $told[] = ['user_id' => (int) $user->id, 'reason' => $reason, 'channels' => $reach];
        }
        if ($afterCommit !== []) {
            DB::afterCommit(fn () => $this->sendOutside($alert, $afterCommit, $private, $kind));
        }

        return [
            'told' => $told,
            'unreachable' => $unreachable,
            'held' => $heldIds === [] ? null : ['user_ids' => $heldIds, 'until' => $holdUntil, 'source' => $window['source']],
        ];
    }

    /**
     * Quiet hours have ended (B2 chunk 5): send the email and push held for
     * them, from the 15-minute tick. Each alert is claimed under its row
     * lock, so two runs never send twice. Only people still allowed to get
     * it, on channels they can still be reached on; nothing is sent for an
     * alert dealt with meanwhile. Returns how many people were sent to.
     */
    public function releaseHeld(CarbonInterface $now): int
    {
        $due = MedicationAlertRecipient::query()
            ->whereNotNull('held_until')
            ->where('held_until', '<=', $now)
            ->distinct()
            ->pluck('medication_alert_id');
        $sent = 0;
        foreach ($due as $alertId) {
            try {
                $sent += $this->release((int) $alertId, $now);
            } catch (Throwable $exception) {
                Log::error('Held medication alert could not be released', ['medication_alert_id' => $alertId, 'exception' => $exception->getMessage()]);
                report($exception);
            }
        }

        return $sent;
    }

    private function release(int $alertId, CarbonInterface $now): int
    {
        return DB::transaction(function () use ($alertId, $now): int {
            $alert = MedicationAlert::query()->whereKey($alertId)->lockForUpdate()->first();
            if (! $alert instanceof MedicationAlert) {
                return 0;
            }
            $rows = $alert->recipients()
                ->whereNotNull('held_until')
                ->where('held_until', '<=', $now)
                ->get();
            if ($rows->isEmpty()) {
                return 0;
            }
            MedicationAlertRecipient::query()->whereIn('id', $rows->pluck('id'))->update(['held_until' => null]);
            $userIds = $rows->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
            if ($alert->open_key === null) {
                $this->event($alert, MedicationAlertEvent::HELD_NOT_SENT, [
                    'user_ids' => $userIds,
                    'reason' => 'It was dealt with before quiet hours ended, so the email and push weren’t sent.',
                ]);

                return 0;
            }
            $allowed = $this->recipients->stillAllowed(
                $rows->mapWithKeys(fn (MedicationAlertRecipient $row): array => [(int) $row->user_id => (string) $row->reason])->all(),
                MedicationAlertSubject::of($alert),
            );
            $send = [];
            $told = [];
            foreach ($allowed as ['user' => $user, 'reason' => $reason]) {
                $row = $rows->firstWhere('user_id', $user->id);
                $channels = $this->reachable($user, array_values(array_diff($row->channels ?? [], ['inapp'])));
                if ($channels === []) {
                    continue;
                }
                $send[] = [$user, $channels];
                $told[] = ['user_id' => (int) $user->id, 'reason' => $reason, 'channels' => $channels];
                if ($row->told_at === null) {
                    $row->forceFill(['told_at' => $now])->save();
                }
            }
            $this->event($alert, MedicationAlertEvent::RELEASED, [
                'told' => $told,
                'not_sent' => array_values(array_diff($userIds, array_column($told, 'user_id'))),
            ]);
            if ($send !== []) {
                $private = $this->settings->privateDelivery();
                DB::afterCommit(fn () => $this->sendOutside($alert, $send, $private, MedicationAlertNotification::FIRST));
            }

            return count($send);
        });
    }

    /**
     * The alert's channels this person can be reached on: the bell always;
     * email only to a work email (never the sign-in address); push only to a
     * phone or browser they've allowed.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function reachable(User $user, array $channels): array
    {
        return array_values(array_filter($channels, fn (string $channel): bool => match ($channel) {
            'inapp' => true,
            'email' => $user->medicationAlertWorkEmail() !== null,
            'push' => $user->pushSubscriptions()->where('enabled', true)->exists(),
            default => false,
        }));
    }

    /**
     * Email and push, after the record commits. A failure is logged and
     * reported; it never undoes the record or the bell.
     *
     * @param  list<array{0: User, 1: list<string>}>  $people
     */
    private function sendOutside(MedicationAlert $alert, array $people, bool $private, string $kind): void
    {
        foreach ($people as [$user, $channels]) {
            try {
                $user->notify(new MedicationAlertNotification($alert, $channels, $private, $kind));
            } catch (Throwable $exception) {
                Log::error('Medication alert email or push could not be sent', [
                    'medication_alert_id' => $alert->id,
                    'user_id' => $user->id,
                    'channels' => $channels,
                    'exception' => $exception->getMessage(),
                ]);
                report($exception);
            }
        }
    }

    /** The open alert for this subject is dealt with: its source was put right. */
    public function resolve(string $type, string $subjectKey, string $outcome): int
    {
        return $this->close(
            MedicationAlert::query()->where('open_key', $this->openKey($type, $subjectKey)),
            $outcome,
        );
    }

    /**
     * After a scheduled check: open alerts of this type whose subject is no
     * longer true are dealt with. `$scope` limits it to what the check looked
     * at (e.g. one person's orders).
     *
     * @param  list<string>  $stillTrue  Subject keys the check found.
     * @param  array{client_id?: int, site_id?: int, key_prefix?: string}  $scope  key_prefix: only subjects whose key starts so.
     */
    public function reconcile(string $type, array $stillTrue, string $outcome, array $scope = []): int
    {
        $keep = array_map(fn (string $key): string => $this->openKey($type, $key), $stillTrue);

        return $this->close(
            MedicationAlert::query()
                ->where('type', $type)
                ->whereNotNull('open_key')
                ->when(isset($scope['client_id']), fn ($query) => $query->where('client_id', $scope['client_id']))
                ->when(isset($scope['site_id']), fn ($query) => $query->where('site_id', $scope['site_id']))
                ->when(isset($scope['key_prefix']), fn ($query) => $query->where('open_key', 'like', addcslashes($this->openKey($type, $scope['key_prefix']), '%_\\').'%'))
                ->when($keep !== [], fn ($query) => $query->whereNotIn('open_key', $keep)),
            $outcome,
        );
    }

    /** Is an alert open for this subject? */
    public function isOpen(string $type, string $subjectKey): bool
    {
        return MedicationAlert::query()->where('open_key', $this->openKey($type, $subjectKey))->exists();
    }

    private function close($query, string $outcome): int
    {
        $closed = 0;
        foreach ($query->get() as $alert) {
            $closed += DB::transaction(function () use ($alert, $outcome): int {
                $locked = MedicationAlert::query()->whereKey($alert->id)->whereNotNull('open_key')->lockForUpdate()->first();
                if ($locked === null) {
                    return 0;
                }
                // Dealt with is the strongest way to attend (B2 chunk 3): it
                // stops any follow-up for everyone.
                $locked->forceFill([
                    'open_key' => null,
                    'status' => MedicationAlert::STATUS_DEALT_WITH,
                    'dealt_with_at' => now(),
                    'outcome' => Str::limit($outcome, 188),
                    'next_follow_up_at' => null,
                    'attended_at' => $locked->attended_at ?? now(),
                    'attended_how' => $locked->attended_how ?? MedicationAlertAttendance::DEALT_WITH,
                ])->save();
                $this->event($locked, MedicationAlertEvent::DEALT_WITH, ['outcome' => $outcome]);

                return 1;
            });
        }

        return $closed;
    }

    /** @param array<string, mixed> $detail */
    public function event(MedicationAlert $alert, string $event, array $detail = [], ?int $userId = null): void
    {
        MedicationAlertEvent::query()->create([
            'medication_alert_id' => $alert->id,
            'event' => $event,
            'user_id' => $userId,
            'detail' => $detail,
            'occurred_at' => now(),
        ]);
    }

    private function openKey(string $type, string $subjectKey): string
    {
        return Str::limit($type.':'.$subjectKey, 188, '');
    }
}
