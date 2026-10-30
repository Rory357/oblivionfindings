<?php

namespace App\Domain\Hr\Services;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Own choices share canonical occurrence keys and the existing durable reminder receipts. */
class HrCalendarPersonalReminders
{
    public const TABLE = 'hr_calendar_personal_reminders';

    public function ready(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    public function summary(User $viewer, HrCalendarEvent $event, string $date): array
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $supported = $this->ready();
        $choice = $supported && $root ? DB::table(self::TABLE)->where('event_id', $root->id)->where('occurrence_date', $date)->where('user_id', $viewer->id)->first() : null;
        $event = $event->recurrence_parent_id ? $event : HrCalendarEvent::where('recurrence_parent_id', $event->id)->whereDate('exception_date', $date)->first() ?? $event;
        $start = app(HrCalendarCancellation::class)->start($event, $date);
        $instant = $start ? app(HrCalendarCancellation::class)->instant($event, $start) : null;
        $due = $instant?->copy()->subMinutes(1440);
        $eligible = $root && $supported && $instant?->isFuture() && ! app(HrCalendarCancellation::class)->cancelled($event, $date)
            && $this->eligible($viewer, $root, $event, $date);

        return ['supported' => $supported, 'enabled' => (bool) $choice?->enabled, 'version' => (int) ($choice?->version ?? 0),
            'can_enable' => (bool) ($eligible && $due?->isFuture()), 'can_disable' => (bool) ($eligible && $choice?->enabled),
            'due_at' => $due?->toIso8601String(), 'inapp_allowed' => app(HubNotificationPreferences::class)->allowsEvent($viewer, $event)
                && app(HrCalendarReminderPreferences::class)->allows($viewer, 'notification')];
    }

    private function eligible(User $viewer, HrCalendarEvent $root, HrCalendarEvent $event, string $date): bool
    {
        $viewer = $viewer->fresh();

        return $viewer && app(HubAccess::class)->canEnter($viewer) && $root->registration_enabled
            && app(HrCalendarReminderRecipients::class)->eligible($viewer, $root, $event, $date, true);
    }

    public function save(User $viewer, HrCalendarEvent $bound, array $data): array
    {
        return DB::transaction(function () use ($viewer, $bound, $data): array {
            $viewer = $viewer->fresh();
            abort_unless($viewer, 404);
            app(HubAccess::class)->assertEnter($viewer);
            $root = HrCalendarEvent::whereKey($bound->recurrence_parent_id ?? $bound->id)->lockForUpdate()->firstOrFail();
            $event = $bound->id === $root->id ? $root : HrCalendarEvent::whereKey($bound->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This date moved to another series. Reopen the event.');
            app(HrEventRegistrationService::class)->occurrence($viewer, $event, $data['date']);
            abort_unless((int) $data['actor_id'] === (int) $viewer->id, 409, 'Your account changed. Reopen the event before continuing.');
            abort_unless((int) $data['edit_version'] === (int) $root->edit_version, 409, 'The event changed. Reopen it before changing your reminder.');
            if (! $this->ready()) {
                throw ValidationException::withMessages(['enabled' => 'Personal event reminders are not available until the calendar update is installed.']);
            }
            $identity = ['event_id' => $root->id, 'occurrence_date' => $data['date'], 'user_id' => $viewer->id];
            $choice = DB::table(self::TABLE)->where($identity)->lockForUpdate()->first();
            abort_unless((int) ($choice?->version ?? 0) === (int) $data['reminder_version'], 409, 'Your reminder choice changed. Refresh the event.');
            $context = $this->summary($viewer, $event, $data['date']);
            if ((bool) $data['enabled'] !== (bool) $choice?->enabled) {
                abort_unless($data['enabled'] ? $context['can_enable'] : $context['can_disable'], 422, 'This reminder choice is no longer available for the current registration and date.');
                DB::table(self::TABLE)->updateOrInsert($identity, ['enabled' => $data['enabled'], 'version' => ($choice?->version ?? 0) + 1,
                    'created_at' => $choice?->created_at ?? now(), 'updated_at' => now()]);
            }

            return ['actor_id' => $viewer->id, 'event_id' => $event->id, 'edit_version' => (int) $root->edit_version, 'date' => $data['date'],
                'registration' => app(HrEventRegistrationService::class)->summary($viewer, $event->fresh(), $data['date'])];
        }, 3);
    }

    /** Stage selected day-before choices into the same once-per-person/channel receipts. */
    public function stage(Carbon $now): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        DB::table(self::TABLE)->where('enabled', true)->orderBy('id')->chunkById(100, function ($choices) use ($now, &$count): void {
            foreach ($choices as $bound) {
                $count += DB::transaction(function () use ($bound, $now): int {
                    $root = HrCalendarEvent::whereKey($bound->event_id)->lockForUpdate()->first();
                    $choice = DB::table(self::TABLE)->where('id', $bound->id)->lockForUpdate()->first();
                    $viewer = User::find($bound->user_id);
                    if (! $root || ! $choice?->enabled || (int) $choice->event_id !== (int) $root->id || ! $viewer) {
                        return 0;
                    }
                    $row = $this->currentChoice($viewer, $root, $choice->occurrence_date, $now);
                    if (! $row) {
                        return 0;
                    }
                    $identity = ['event_id' => $root->id, 'user_id' => $viewer->id, 'occurrence_date' => $choice->occurrence_date,
                        'starts_at' => $row['start'], 'offset_minutes' => 1440, 'channel' => 'notification'];

                    return DB::table(HrCalendarReminderDelivery::TABLE)->insertOrIgnore([...$identity, 'due_at' => $row['due'], 'expires_at' => $row['end'],
                        'state' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
                }, 3);
            }
        });

        return $count;
    }

    /** A saved choice never grants access or overrides a current decline/cancellation. */
    public function current(User $viewer, HrCalendarEvent $root, object $delivery, Carbon $now): ?array
    {
        if (! $this->ready() || $delivery->channel !== 'notification' || (int) $delivery->offset_minutes !== 1440
            || ! DB::table(self::TABLE)->where('event_id', $root->id)->where('occurrence_date', $delivery->occurrence_date)->where('user_id', $viewer->id)->where('enabled', true)->exists()) {
            return null;
        }
        $row = $this->currentChoice($viewer, $root, $delivery->occurrence_date, $now);

        return $row && $row['start']->eq(Carbon::parse($delivery->starts_at)) ? $row : null;
    }

    private function currentChoice(User $viewer, HrCalendarEvent $root, string $date, Carbon $now): ?array
    {
        $event = HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $date)->first() ?? $root;
        if ($root->archived_at || $root->cancelled_at || ! $this->eligible($viewer, $root, $event, $date) || app(HrCalendarCancellation::class)->cancelled($event, $date)) {
            return null;
        }
        try {
            $row = app(HrEventRegistrationService::class)->occurrence($viewer, $event, $date);
        } catch (HttpExceptionInterface $error) {
            return null;
        }
        $raw = app(HrCalendarCancellation::class)->start($event, $date);
        $start = $raw ? app(HrCalendarCancellation::class)->instant($event, $raw) : null;
        $due = $start?->copy()->subMinutes(1440);
        if (! $start || $start->lte($now) || $due->gt($now)) {
            return null;
        }

        return ['event' => $event, 'date' => $date, 'start' => $start, 'due' => $due,
            'end' => app(HrCalendarHubContent::class)->finishedAt(Carbon::parse($row['end']), $row['allDay'])];
    }
}
