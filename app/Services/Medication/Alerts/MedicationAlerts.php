<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\MedicationAlertRecipient;
use App\Notifications\MedicationAlertNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 */
class MedicationAlerts
{
    public function __construct(
        private readonly MedicationAlertRecipients $recipients,
        private readonly MedicationAlertSettings $settings,
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
        $told = [];
        if ($channels !== []) {
            foreach ($resolved['told'] as ['user' => $user, 'reason' => $reason]) {
                $notification = new MedicationAlertNotification($alert, $channels);
                $notification->id = (string) Str::uuid();
                $user->notify($notification);
                MedicationAlertRecipient::query()->create([
                    'medication_alert_id' => $alert->id,
                    'user_id' => $user->id,
                    'reason' => $reason,
                    'step' => 0,
                    'channels' => $channels,
                    'told_at' => $now,
                    'notification_id' => $notification->id,
                ]);
                $told[] = ['user_id' => (int) $user->id, 'reason' => $reason];
            }
        }

        $this->event($alert, MedicationAlertEvent::SENT, ['told' => $told, 'channels' => $channels]);
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
            $reason = $channels === []
                ? 'No way to tell people is switched on for this alert.'
                : $resolved['nobody_reason'];
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
                $locked->forceFill([
                    'open_key' => null,
                    'status' => MedicationAlert::STATUS_DEALT_WITH,
                    'dealt_with_at' => now(),
                    'outcome' => Str::limit($outcome, 188),
                    'next_follow_up_at' => null,
                ])->save();
                $this->event($locked, MedicationAlertEvent::DEALT_WITH, ['outcome' => $outcome]);

                return 1;
            });
        }

        return $closed;
    }

    /** @param array<string, mixed> $detail */
    private function event(MedicationAlert $alert, string $event, array $detail = [], ?int $userId = null): void
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
