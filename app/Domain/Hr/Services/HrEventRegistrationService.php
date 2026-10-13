<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarPlaceAvailableNotification;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Registrations belong to canonical calendar occurrences, never to a hub copy. */
class HrEventRegistrationService
{
    public function __construct(private readonly HrCalendarAccessService $access) {}

    private function lockOccurrence(HrCalendarEvent $event): array
    {
        $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
        $event = $event->id === $root->id ? $root : HrCalendarEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This date moved to a changed series. Reload the event before continuing.');

        return [$root, $event];
    }

    public function summary(User $viewer, HrCalendarEvent $event, string $date): array
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $rows = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date)->get();
        $mine = $rows->firstWhere('user_id', $viewer->id);
        $queue = $rows->where('status', 'waiting')->sortBy([['queued_at', 'asc'], ['id', 'asc']])->values();

        return [
            'can_configure' => $this->access->canManageEvent($viewer, $event) && (! $root->hub_managed || $viewer->canDo('company_hub.publish')),
            'enabled' => (bool) $root->registration_enabled,
            'capacity' => $root->registration_capacity === null ? null : (int) $root->registration_capacity,
            'version' => (int) $root->registration_version,
            'going' => $rows->where('status', 'going')->count(),
            'attended' => $rows->where('attendance', 'attended')->count(),
            'waiting' => $rows->where('status', 'waiting')->count(),
            'mine' => $mine?->status,
            'queue_position' => $mine?->status === 'waiting' ? $queue->search(fn ($r) => $r->id === $mine->id) + 1 : null,
            'attendance' => $mine?->attendance,
            'date' => $date,
        ];
    }

    public function occurrence(User $viewer, HrCalendarEvent $event, string $date): array
    {
        abort_unless($viewer->canDo('company_hub.view') || $viewer->canDo('hr.calendar.view'), 403);
        $this->access->assertCanViewEvent($viewer, $event);
        abort_if($event->archived_at, 404);
        $range = $event->recurrence_parent_id ? $event->starts_at->toDateString() : $date;
        $rows = app(HrCalendarAggregator::class)->feed($range, $range, ['event'], [], $viewer);
        $row = collect($rows)->first(fn ($row) => (int) $row['extendedProps']['eventId'] === (int) $event->id
            && ($row['extendedProps']['registration']['date'] ?? null) === $date);
        abort_unless($row, 404, 'This event occurrence is no longer available.');

        return $row;
    }

    public function respond(User $viewer, HrCalendarEvent $event, string $date, string $status): array
    {
        return DB::transaction(function () use ($viewer, $event, $date, $status) {
            // All seat allocation/settings updates lock the same series row, including exceptions.
            [$root, $event] = $this->lockOccurrence($event);
            $row = $this->occurrence($viewer, $event, $date);
            abort_unless(app(HrCurrentStaffService::class)->isCurrent($viewer), 403);
            abort_unless($root->registration_enabled || $status === 'declined', 422, 'Registration is not open for this event.');
            abort_if(Carbon::parse($row['start'])->isPast() && $status !== 'declined', 422, 'Registration has closed for this date.');
            $query = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date);
            $mine = (clone $query)->where('user_id', $viewer->id)->first();
            $desired = $status;
            if ($status === 'going') {
                $count = (clone $query)->where('status', 'going')->where('user_id', '!=', $viewer->id)->count();
                $desired = $root->registration_capacity !== null && $count >= $root->registration_capacity ? 'waiting' : 'going';
            }
            // Repeated requests retain queue position and never allocate a second seat.
            if ($mine?->status !== $desired) {
                DB::table('hr_calendar_registrations')->updateOrInsert(
                    ['event_id' => $root->id, 'occurrence_date' => $date, 'user_id' => $viewer->id],
                    ['status' => $desired, 'queued_at' => $desired === 'waiting' ? now() : null,
                        'created_at' => $mine?->created_at ?? now(), 'updated_at' => now(), 'version' => ($mine?->version ?? 0) + 1],
                );
            }
            if ($root->registration_enabled && Carbon::parse($row['start'])->isFuture()) {
                $this->promote($root, $event, $date);
            }

            return $this->summary($viewer, $event->fresh(), $date);
        }, 3);
    }

    public function configure(User $viewer, HrCalendarEvent $event, array $data): void
    {
        DB::transaction(function () use ($viewer, $event, $data) {
            [$root, $event] = $this->lockOccurrence($event);
            $this->access->assertCanManageEvent($viewer, $event);
            abort_if($event->archived_at, 404);
            $this->access->assertCanManageEvent($viewer, $root);
            abort_if($root->archived_at, 404);
            abort_if($root->hub_managed && ! $viewer->canDo('company_hub.publish'), 403, 'Publishing permission is required to change registration settings for a Hub event.');
            abort_unless((int) $root->registration_version === (int) $data['version'], 409, 'Registration settings changed. Reload before saving.');
            $capacity = $data['capacity'] ?? null;
            $max = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('status', 'going')
                ->where('occurrence_date', '>=', now()->toDateString())->selectRaw('COUNT(*) as places')->groupBy('occurrence_date')->get()->max('places');
            if ($capacity !== null && $capacity < ($max ?? 0)) {
                throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than the number already registered.']);
            }
            $root->forceFill(['registration_enabled' => $data['enabled'], 'registration_capacity' => $capacity,
                'registration_version' => $root->registration_version + 1])->save();
            // Promotion occurs when capacity is raised, in original queue order.
            if ($data['enabled']) {
                $dates = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('status', 'waiting')
                    ->where('occurrence_date', '>=', now()->toDateString())->distinct()->pluck('occurrence_date');
                foreach ($dates as $date) {
                    $effective = HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $date)->active()->first() ?? $root;
                    $this->promote($root, $effective, $date);
                }
            }
        }, 3);
    }

    private function promote(HrCalendarEvent $root, HrCalendarEvent $event, string $date): void
    {
        $query = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date);
        $free = $root->registration_capacity === null ? PHP_INT_MAX : max(0, $root->registration_capacity - (clone $query)->where('status', 'going')->count());
        foreach ((clone $query)->where('status', 'waiting')->orderBy('queued_at')->orderBy('id')->get() as $waiting) {
            if ($free < 1) {
                break;
            }
            $person = User::find($waiting->user_id);
            if (! $person || (! $person->canDo('company_hub.view') && ! $person->canDo('hr.calendar.view')) || ! app(HrCurrentStaffService::class)->isCurrent($person) || ! $this->access->canViewEvent($person, $event)) {
                continue;
            }
            try {
                $row = $this->occurrence($person, $event, $date);
            } catch (HttpExceptionInterface $exception) {
                continue;
            }
            if (Carbon::parse($row['start'])->isPast()) {
                continue;
            }
            DB::table('hr_calendar_registrations')->where('id', $waiting->id)->update(['status' => 'going', 'queued_at' => null, 'updated_at' => now(), 'version' => $waiting->version + 1]);
            // Database delivery is inside the same transaction as the promotion:
            // retry/rollback cannot leave a notice without its seat or send it twice.
            $person->notify(new CalendarPlaceAvailableNotification($event->id, $date));
            $free--;
        }
    }

    public function roster(User $viewer, HrCalendarEvent $event, string $date): array
    {
        $this->access->assertCanManageEvent($viewer, $event);
        $row = $this->occurrence($viewer, $event, $date);
        $rootId = $event->recurrence_parent_id ?? $event->id;
        $people = $this->access->visibleCurrentStaffQuery($viewer)->get(['id', 'name'])->keyBy('id');
        $registrations = DB::table('hr_calendar_registrations')->where('event_id', $rootId)->where('occurrence_date', $date)->orderBy('id')->get()
            ->filter(fn ($r) => $people->has($r->user_id))
            ->map(fn ($r) => ['id' => $r->id, 'name' => $people[$r->user_id]->name, 'status' => $r->status,
                'attendance' => $r->attendance, 'version' => (int) $r->version, 'recorded_at' => $r->attendance_recorded_at])->values()->all();

        return ['date' => $date, 'can_record' => Carbon::parse($row['start'])->lte(now()), 'registrations' => $registrations];
    }

    public function attendance(User $viewer, HrCalendarEvent $event, string $date, int $registrationId, ?string $attendance, int $version): array
    {
        return DB::transaction(function () use ($viewer, $event, $date, $registrationId, $attendance, $version) {
            [$root, $event] = $this->lockOccurrence($event);
            $this->access->assertCanManageEvent($viewer, $event);
            $row = $this->occurrence($viewer, $event, $date);
            abort_if(Carbon::parse($row['start'])->isFuture(), 422, 'Attendance opens when this event starts.');
            abort_unless(in_array($attendance, [null, 'attended', 'no_show'], true), 422);
            $registration = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date)->where('id', $registrationId)->lockForUpdate()->first();
            abort_unless($registration && $this->access->visibleCurrentStaffQuery($viewer)->whereKey($registration->user_id)->exists(), 404);
            abort_unless((int) $registration->version === $version, 409, 'This registration changed. Refresh attendance before updating it.');
            abort_unless($registration->status === 'going' || $registration->attendance !== null, 422, 'Only a confirmed place can have attendance recorded.');
            DB::table('hr_calendar_registrations')->where('id', $registrationId)->update([
                'attendance' => $attendance, 'attendance_recorded_at' => $attendance ? now() : null,
                'attendance_recorded_by' => $attendance ? $viewer->id : null, 'version' => $version + 1, 'updated_at' => now(),
            ]);
            AuditLog::create(['user_id' => $viewer->id, 'action' => 'calendar.attendance_recorded', 'auditable_type' => HrCalendarEvent::class,
                'auditable_id' => $event->id, 'meta' => ['registration_id' => $registrationId, 'occurrence_date' => $date, 'attendance' => $attendance]]);

            return $this->roster($viewer, $event, $date);
        }, 3);
    }
}
