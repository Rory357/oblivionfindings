<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Preserve the canonical occurrence graph when an organiser edits a series. */
class HrCalendarSeriesService
{
    public function __construct(private readonly HrCalendarAccessService $access, private readonly HrCalendarAggregator $calendar) {}

    public function dates(HrCalendarEvent $event, Carbon $through, ?int $limit = null): array
    {
        if (! $event->rrule) {
            return [$event->starts_at->toDateString()];
        }

        return array_column(app(HrCalendarRecurrence::class)->between($event, $event->starts_at->copy()->startOfDay(), $through, $limit), 'date');
    }

    public function assertRetainsDates(HrCalendarEvent $event, array $data): void
    {
        if ($event->recurrence_parent_id) {
            return;
        }
        $candidate = clone $event;
        $candidate->fill($data);
        if ($event->rrule && $event->starts_at->isPast() && app(HrCalendarRecurrence::class)->timezone($candidate) !== app(HrCalendarRecurrence::class)->timezone($event)) {
            throw ValidationException::withMessages(['recurrence_timezone' => 'Keep past dates unchanged. Choose “This and following dates” on a future date to change the repeat timing.']);
        }
        $saved = DB::table('hr_calendar_registrations')->where('event_id', $event->id)->distinct()->pluck('occurrence_date')->all();
        $overrides = HrCalendarEvent::where('recurrence_parent_id', $event->id)->pluck('exception_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $missing = collect([...$saved, ...$overrides])->unique()->contains(function ($date) use ($candidate) {
            return ! collect($this->calendar->occurrences($candidate, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()))->contains('date', $date);
        });
        if ($missing) {
            throw ValidationException::withMessages(['starts_at' => 'This edit would remove dates with saved registrations or individual changes. Use “This and following dates” to move them together.']);
        }
    }

    public function change(User $actor, HrCalendarEvent $parent, string $date, array $data, bool $following, array $excludedAttachmentIds = [], array &$attachmentMap = [], bool $approvedPublication = false): HrCalendarEvent
    {
        $copies = [];
        try {
            return DB::transaction(function () use ($actor, $parent, $date, $data, $following, $excludedAttachmentIds, &$copies, &$attachmentMap, $approvedPublication) {
                $parent = HrCalendarEvent::whereKey($parent->id)->lockForUpdate()->firstOrFail();
                abort_if($parent->hub_managed && ! $approvedPublication, 422, 'Publish Hub event changes through its draft.');
                $this->access->assertCanManageEvent($actor, $parent);
                abort_unless($actor->canDo('calendar.manage_recurring'), 403);
                abort_if($parent->archived_at || $parent->recurrence_parent_id || ! $parent->rrule, 404);
                $futureRows = DB::table('hr_calendar_registrations')->where('event_id', $parent->id)->where('occurrence_date', '>=', $date)->get();
                $children = HrCalendarEvent::where('recurrence_parent_id', $parent->id)->whereDate('exception_date', '>=', $date)->lockForUpdate()->get();
                $deliveries = DB::table(HrCalendarReminderDelivery::TABLE)->where('event_id', $parent->id)->where('occurrence_date', '>=', $date)->get();
                $latest = max([$date, ...$futureRows->pluck('occurrence_date')->all(), ...$children->map(fn ($c) => $c->exception_date->toDateString())->all(), ...$deliveries->pluck('occurrence_date')->all()]);
                $oldDates = $this->dates($parent, Carbon::parse($latest)->endOfDay());
                $offset = array_search($date, $oldDates, true);
                abort_if($offset === false, 422, 'Choose an existing date in this series.');
                $occurrence = collect($this->calendar->occurrences($parent, Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()))->firstWhere('date', $date);
                abort_unless($occurrence, 422, 'Choose an existing date in this series.');
                $base = $parent->only(['title', 'description', 'event_type', 'category_id', 'is_all_day', 'location', 'department', 'department_id', 'site_id', 'created_by', 'recurrence_timezone']);
                if (! $following) {
                    abort_if((array_key_exists('site_id', $data) && (int) $data['site_id'] !== (int) $parent->site_id) || (array_key_exists('department_id', $data) && (int) $data['department_id'] !== (int) $parent->department_id), 422, 'A single date keeps the series audience and Site. Edit the series to change its audience.');
                    $child = HrCalendarEvent::where('recurrence_parent_id', $parent->id)->whereDate('exception_date', $date)->first();
                    $start = $occurrence['start'];
                    $values = [...$base, 'starts_at' => $start, 'ends_at' => $start->copy()->addSeconds($parent->ends_at->getTimestamp() - $parent->starts_at->getTimestamp()), ...($child?->only([...array_keys($base), 'starts_at', 'ends_at']) ?? []), ...$data,
                        'site_id' => $parent->site_id, 'department_id' => $parent->department_id, 'rrule' => null, 'recurrence_until' => null, 'recurrence_timezone' => $parent->recurrence_timezone,
                        'recurrence_parent_id' => $parent->id, 'is_exception' => true, 'exception_date' => $date];
                    if ($child) {
                        $child->update($values);
                    } else {
                        $child = HrCalendarEvent::create($values);
                    }

                    return $child;
                }

                $rule = $data['rrule'] ?? $parent->rrule;
                if ($rule === $parent->rrule && preg_match('/(?:^|;)COUNT=(\d+)/i', $rule, $count)) {
                    $rule = preg_replace('/COUNT=\d+/i', 'COUNT='.max(1, (int) $count[1] - $offset), $rule);
                }
                if (array_key_exists('rrule', $data) && $data['rrule'] === null) {
                    $rule = null;
                }
                $start = $occurrence['start'];
                if ($start->isPast() && isset($data['recurrence_timezone']) && $data['recurrence_timezone'] !== $parent->recurrence_timezone) {
                    throw ValidationException::withMessages(['recurrence_timezone' => 'Choose a future date to change the repeat timing.']);
                }
                $next = HrCalendarEvent::create([...$base, 'starts_at' => $start, 'ends_at' => $start->copy()->addSeconds($parent->ends_at->getTimestamp() - $parent->starts_at->getTimestamp()),
                    'recurrence_until' => $parent->recurrence_until, ...$data, 'rrule' => $rule]);
                $next->forceFill(['registration_enabled' => $parent->registration_enabled, 'registration_capacity' => $parent->registration_capacity,
                    'registration_version' => $parent->registration_version + 1, 'edit_version' => $parent->edit_version + 1])->save();
                $newDates = $this->dates($next, Carbon::parse('9999-12-31')->endOfDay(), count($oldDates) - $offset);
                $map = array_combine(array_slice($oldDates, $offset, count($newDates)), array_slice($newDates, 0, count($oldDates) - $offset));
                if (! $next->rrule && $children->isNotEmpty()) {
                    throw ValidationException::withMessages(['rrule' => 'Keep the series recurring while it has individual date changes.']);
                }
                foreach ([...$futureRows->pluck('occurrence_date')->all(), ...$children->map(fn ($c) => $c->exception_date->toDateString())->all()] as $savedDate) {
                    if (! isset($map[$savedDate])) {
                        throw ValidationException::withMessages(['rrule' => 'Keep enough future dates for the saved registrations and individual event changes.']);
                    }
                }
                foreach ($futureRows as $row) {
                    if ($row->attendance !== null && $map[$row->occurrence_date] !== $row->occurrence_date) {
                        throw ValidationException::withMessages(['starts_at' => 'A date with recorded attendance cannot be moved. Start changes after that date.']);
                    }
                    DB::table('hr_calendar_registrations')->where('id', $row->id)->update(['event_id' => $next->id, 'occurrence_date' => $map[$row->occurrence_date], 'version' => $row->version + 1]);
                }
                foreach ($children as $child) {
                    $child->update(['recurrence_parent_id' => $next->id, 'exception_date' => $map[$child->exception_date->toDateString()], 'site_id' => $next->site_id, 'department_id' => $next->department_id]);
                }
                foreach ($deliveries as $delivery) {
                    if (isset($map[$delivery->occurrence_date])) {
                        DB::table(HrCalendarReminderDelivery::TABLE)->where('id', $delivery->id)->update(['event_id' => $next->id, 'occurrence_date' => $map[$delivery->occurrence_date], 'updated_at' => now()]);
                    } elseif ($delivery->state === 'pending') {
                        DB::table(HrCalendarReminderDelivery::TABLE)->where('id', $delivery->id)->update(['state' => 'skipped', 'outcome' => 'date_removed', 'retry_at' => null, 'updated_at' => now()]);
                    }
                }
                foreach ($parent->attendees()->get() as $attendee) {
                    $values = $attendee->only(['user_id', 'audience_type', 'audience_ref', 'rsvp_status', 'responded_at']);
                    if ($attendee->audience_type === 'site') {
                        $values['audience_ref'] = $next->site_id;
                    }
                    if ($attendee->audience_type === 'department') {
                        $values['audience_ref'] = $next->department_id;
                    }
                    $next->attendees()->create($values);
                }
                foreach ($parent->reminders()->get() as $reminder) {
                    $next->reminders()->create($reminder->only(['offset_minutes', 'channel', 'last_sent_at']));
                }
                foreach (DB::table('hub_bookmarks')->where('subject_type', 'event')->where('subject_id', $parent->id)->get() as $bookmark) {
                    DB::table('hub_bookmarks')->insertOrIgnore(['user_id' => $bookmark->user_id, 'subject_type' => 'event', 'subject_id' => $next->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($parent->attachments()->whereNotIn('id', $excludedAttachmentIds)->get() as $attachment) {
                    $disk = $attachment->disk ?: 'private';
                    $path = 'hr/calendar/events/'.$next->id.'/'.Str::uuid().'.'.pathinfo($attachment->path, PATHINFO_EXTENSION);
                    abort_unless(Storage::disk($disk)->copy($attachment->path, $path), 500, 'An event file could not be copied. No series changes were saved.');
                    $copies[] = [$disk, $path];
                    $attachmentMap[$attachment->id] = $next->attachments()->create([...$attachment->only(['uploaded_by', 'disk', 'original_name', 'mime', 'size']), 'path' => $path])->id;
                }
                if ($parent->hub_content !== null) {
                    $next->update(['hub_content' => app(HrCalendarHubContent::class)->copy($parent->hub_content, $attachmentMap)]);
                }
                foreach ($children as $child) {
                    if ($child->hub_content !== null) {
                        $child->update(['hub_content' => app(HrCalendarHubContent::class)->copy($child->hub_content, $attachmentMap, $child->attachments()->pluck('id')->all())]);
                    }
                }
                $parent->forceFill(['recurrence_until' => $occurrence['start']->copy()->subSecond(), 'registration_version' => $parent->registration_version + 1])->save();

                return $next;
            });
        } catch (\Throwable $exception) {
            foreach ($copies as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }
}
