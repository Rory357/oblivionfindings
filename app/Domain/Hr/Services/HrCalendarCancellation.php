<?php

namespace App\Domain\Hr\Services;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Notifications\CalendarEventCancelledNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Cancellation retains the canonical event graph, occurrence keys and evidence. */
class HrCalendarCancellation
{
    public function ready(): bool
    {
        return Schema::hasColumns('hr_calendar_events', ['cancelled_at', 'cancelled_by', 'cancellation_reason']);
    }

    private function actor(User $bound): User
    {
        $actor = $bound->fresh();
        abort_unless($actor && app(HrCurrentStaffService::class)->isCurrent($actor), 404);

        return $actor;
    }

    private function access(): HrCalendarAccessService
    {
        return new HrCalendarAccessService(new UserSiteAccessService, app(HrCurrentStaffService::class));
    }

    /** A series marker cancels future dates, including moved exceptions, not completed history. */
    public function marker(HrCalendarEvent $event, Carbon $start): ?HrCalendarEvent
    {
        $start = $this->instant($event, $start);
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        foreach ([$event, $root] as $source) {
            if ($source?->cancelled_at && $start->gte($source->cancelled_at)) {
                return $source;
            }
        }

        return null;
    }

    public function instant(HrCalendarEvent $event, Carbon $start): Carbon
    {
        return $event->is_all_day ? Carbon::parse($start->toDateString(), 'Pacific/Auckland')->utc() : $start;
    }

    public function state(HrCalendarEvent $event, Carbon $start, ?User $viewer = null): array
    {
        $marker = $this->marker($event, $start);
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $canCancel = false;
        if ($viewer && array_key_exists('cancelled_at', $event->getAttributes()) && $this->ready() && ! $marker && $this->instant($event, $start)->isFuture() && $root && ! $event->archived_at && ! $root->archived_at) {
            $actor = $viewer->fresh();
            $canCancel = $actor && app(HrCurrentStaffService::class)->isCurrent($actor)
                && $actor->canDo('company_hub.view') && $actor->canDo('company_hub.write') && $actor->canDo('hr.calendar.view')
                && (! $root->hub_managed || $actor->canDo('company_hub.publish')) && $this->access()->canManageEvent($actor, $event)
                && (! $root->rrule || $actor->canDo('calendar.manage_recurring'));
        }

        return ['cancelled' => $marker !== null, 'cancelled_at' => $marker?->cancelled_at?->toIso8601String(),
            'reason' => $marker?->cancellation_reason, 'actor_id' => $viewer?->id, 'can_cancel' => (bool) $canCancel,
            'scopes' => $canCancel ? ($root->rrule ? ['this', 'all', 'following'] : ['this']) : []];
    }

    /** Resolve the actual start without a feed/registration-summary recursion. */
    public function start(HrCalendarEvent $event, string $date): ?Carbon
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        if (! $root || $root->archived_at) {
            return null;
        }
        $child = $event->recurrence_parent_id ? $event : HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $date)->first();
        if ($child) {
            if ($child->archived_at || $child->exception_date?->toDateString() !== $date) {
                return null;
            }

            return $child->starts_at;
        }

        return collect(app(HrCalendarRecurrence::class)->between($root, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()))->firstWhere('date', $date)['start'] ?? null;
    }

    public function cancelled(HrCalendarEvent $event, string $date): bool
    {
        $start = $this->start($event, $date);
        if (! $start) {
            return false;
        }
        $effective = $event->recurrence_parent_id ? $event : HrCalendarEvent::where('recurrence_parent_id', $event->id)->whereDate('exception_date', $date)->first() ?? $event;

        return $this->marker($effective, $start) !== null;
    }

    public function assertOpen(HrCalendarEvent $event, string $date): void
    {
        abort_if($this->cancelled($event, $date), 409, 'This event was cancelled. Your original response remains in its history.');
    }

    /** Never reopen a cancelled date by replacing its source or changing its series. */
    public function assertEditable(HrCalendarEvent $event, string $scope = 'all', ?string $date = null): void
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        if (! $root) {
            return;
        }
        if ($event->recurrence_parent_id || $scope === 'this') {
            $this->assertOpen($event, $date ?? $event->exception_date?->toDateString() ?? $event->starts_at->toDateString());

            return;
        }
        abort_if($root->cancelled_at, 409, 'This series was cancelled. Prepare a new event instead.');
        if ($scope === 'all' && $this->ready()) {
            abort_if(HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereNotNull('cancelled_at')->exists(), 409, 'This series has a cancelled date. Edit an unaffected date or following dates instead.');
        }
        if ($date) {
            $this->assertOpen($root, $date);
        }
    }

    public function cancel(User $bound, HrCalendarEvent $boundEvent, array $input): array
    {
        $data = Validator::make($input, ['actor_id' => 'required|integer|min:1', 'edit_version' => 'required|integer|min:1',
            'date' => 'required|date_format:Y-m-d', 'scope' => 'required|in:this,all,following', 'reason' => 'nullable|string|max:500'])->validate();
        $actor = $this->actor($bound);
        abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your account changed. Reopen the event before cancelling.');
        (new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService))->assertEnter($actor);
        abort_unless($actor->canDo('company_hub.write') && $actor->canDo('hr.calendar.view'), 403);
        $this->access()->assertCanManageEvent($actor, $boundEvent->fresh() ?? abort(404));
        if (! $this->ready()) {
            throw ValidationException::withMessages(['scope' => 'Event cancellation is not available yet. Refresh after the calendar update is installed.']);
        }
        $copies = [];
        try {
            return DB::transaction(function () use ($actor, $boundEvent, $data, &$copies) {
                $root = HrCalendarEvent::whereKey($boundEvent->recurrence_parent_id ?? $boundEvent->id)->lockForUpdate()->firstOrFail();
                $event = $boundEvent->id === $root->id ? $root : HrCalendarEvent::whereKey($boundEvent->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This date moved to a changed series. Reopen it.');
                $actor = $this->actor($actor);
                $access = $this->access();
                $access->assertCanManageEvent($actor, $root);
                $access->assertCanManageEvent($actor, $event);
                abort_unless($actor->canDo('company_hub.write') && $actor->canDo('hr.calendar.view') && $actor->canDo('company_hub.view'), 403);
                abort_if($root->hub_managed && ! $actor->canDo('company_hub.publish'), 403, 'Publishing permission is required to cancel a Hub event.');
                abort_if($root->archived_at || $event->archived_at, 404);
                abort_unless((int) $root->edit_version === (int) $data['edit_version'], 409, 'This event changed. Reopen it before cancelling.');
                $row = app(HrEventRegistrationService::class)->occurrence($actor, $event, $data['date']);
                abort_unless($this->instant($event, Carbon::parse($row['start']))->isFuture(), 422, 'Only an event that has not started can be cancelled.');
                $this->assertOpen($event, $data['date']);
                $scope = $data['scope'];
                if ($root->rrule) {
                    abort_unless($actor->canDo('calendar.manage_recurring'), 403);
                } else {
                    abort_unless($scope === 'this', 422, 'This event has one date. Choose this event only.');
                }
                if ($root->rrule && $scope === 'following') {
                    abort_if($root->cancelled_at, 409, 'This series was already cancelled.');
                    $map = [];
                    $event = app(HrCalendarSeriesService::class)->change($actor, $root, $data['date'], [], true, [], $map, true);
                    $copies = $event->attachments()->get()->map(fn ($file) => [$file->disk ?: 'private', $file->path])->all();
                    $event->forceFill(['hub_managed' => (bool) $root->hub_managed])->save();
                    $root->increment('edit_version');
                    $root = $event;
                } elseif ($root->rrule && $scope === 'this') {
                    $event = HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $data['date'])->lockForUpdate()->first();
                    if (! $event) {
                        $event = HrCalendarEvent::create([...$root->only(['title', 'description', 'event_type', 'category_id', 'is_all_day', 'location', 'department', 'department_id', 'site_id', 'created_by', 'recurrence_timezone']),
                            'starts_at' => $row['start'], 'ends_at' => $row['end'], 'recurrence_parent_id' => $root->id, 'is_exception' => true, 'exception_date' => $data['date'], 'hub_content' => $root->hub_content]);
                    }
                } else {
                    $event = $root;
                }
                $event->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null])->save();
                $root->forceFill(['edit_version' => $root->edit_version + 1, 'registration_version' => $root->registration_version + 1])->save();
                $this->skipReminders($root);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'calendar.event_cancelled', 'auditable_type' => HrCalendarEvent::class, 'auditable_id' => $event->id,
                    'meta' => ['root_id' => $root->id, 'scope' => $scope, 'occurrence_date' => $data['date'], 'edit_version' => $root->edit_version]]);
                $this->notifyRegistrants($root, $event, $data['date']);
                $event = $event->fresh();
                $start = $this->start($event, $data['date']);

                return ['actor_id' => $actor->id, 'event_id' => $event->id, 'edit_version' => (int) $root->edit_version,
                    'date' => $data['date'], 'scope' => $scope, 'cancellation' => $this->state($event, $start, $actor),
                    'registration' => app(HrEventRegistrationService::class)->summary($actor, $event, $data['date'])];
            });
        } catch (\Throwable $error) {
            foreach ($copies as [$disk, $path]) {
                if (! HrCalendarEventAttachment::where('path', $path)->exists()) {
                    Storage::disk($disk)->delete($path);
                }
            }
            throw $error;
        }
    }

    private function skipReminders(HrCalendarEvent $root): void
    {
        foreach (DB::table(HrCalendarReminderDelivery::TABLE)->where('event_id', $root->id)->where('state', 'pending')->get() as $delivery) {
            if ($this->cancelled($root, $delivery->occurrence_date)) {
                DB::table(HrCalendarReminderDelivery::TABLE)->where('id', $delivery->id)->update(['state' => 'skipped', 'outcome' => 'event_cancelled', 'retry_at' => null, 'updated_at' => now()]);
            }
        }
    }

    private function notifyRegistrants(HrCalendarEvent $root, HrCalendarEvent $cancelledEvent, string $selectedDate): void
    {
        $dates = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->whereIn('status', ['going', 'waiting', 'maybe'])->get()->groupBy('occurrence_date');
        $notified = [];
        foreach ($dates as $date => $rows) {
            $effective = HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $date)->first() ?? $root;
            $start = $this->start($effective, $date);
            if (! $start || (int) $this->marker($effective, $start)?->id !== (int) $cancelledEvent->id) {
                continue;
            }
            foreach ($rows as $row) {
                $this->notify($row->user_id, $effective, $date, $notified);
            }
        }
        if (! $root->registration_enabled) {
            foreach ($root->attendees()->where('audience_type', 'person')->whereIn('rsvp_status', ['yes', 'maybe'])->get() as $attendee) {
                $this->notify($attendee->user_id, $cancelledEvent, $selectedDate, $notified);
            }
        }
    }

    private function notify(int $id, HrCalendarEvent $effective, string $date, array &$notified): void
    {
        if (isset($notified[$id])) {
            return;
        }
        $person = User::find($id);
        if (! $person || ! app(HrCurrentStaffService::class)->isCurrent($person) || (! $person->canDo('company_hub.view') && ! $person->canDo('hr.calendar.view')) || ! $this->access()->canViewEvent($person, $effective)) {
            return;
        }
        try {
            app(HrEventRegistrationService::class)->occurrence($person, $effective, $date);
        } catch (HttpExceptionInterface) {
            return;
        }
        $notice = new CalendarEventCancelledNotification($effective->id, $date);
        if (! $notice->shouldSend($person, 'database')) {
            return;
        }
        $person->notify($notice);
        $notified[$id] = true;
    }
}
