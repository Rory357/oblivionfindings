<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubEventMedia;
use App\Domain\CompanyHub\Services\HubEventPublishing;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Models\HrCalendarEventCategory;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrCalendarCancellation;
use App\Domain\Hr\Services\HrCalendarExport;
use App\Domain\Hr\Services\HrCalendarHubContent;
use App\Domain\Hr\Services\HrCalendarPersonalReminders;
use App\Domain\Hr\Services\HrCalendarReminderPreferences;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HubEventController extends Controller
{
    use ServesPrivateAttachments;

    public function show(Request $request, HrCalendarEvent $event, HubAccess $access, HubFeed $feed, HrEventRegistrationService $registration)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 404);
        $access->assertEnter($user);
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $current = $event->fresh();
        abort_unless($current, 404);
        $row = $registration->occurrence($user, $current, $data['date']);
        $row['extendedProps']['attachments'] = array_map(fn ($file) => HubEventMedia::attachmentUrls($file, '/company-hub/event-files/'.$file['id']), $row['extendedProps']['attachments'] ?? []);

        return Inertia::render('company-hub/index', [
            ...$feed->forUser($user), 'view' => 'events', 'selectedPage' => null, 'selectedEvent' => $row,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, HrCalendarEventAttachment $attachment, HubAccess $access, HrCalendarAccessService $calendar)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 403);
        $access->assertEnter($user);
        $attachment = $attachment->fresh();
        abort_unless($attachment, 404);
        $event = $attachment->event;
        abort_unless($event && ! $event->archived_at && (! $event->recurrence_parent_id || ! $event->recurrenceParent?->archived_at), 404);
        $calendar->assertCanViewEvent($user, $event);
        abort_if($request->boolean('preview') && ! HubMediaLibrary::previewable($attachment->mime), 404);
        $response = $this->streamPrivateAttachment($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime, $request->boolean('preview') ? 'inline' : 'attachment');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function rsvp(Request $request, HrCalendarEvent $event, HubAccess $access, HrCalendarAccessService $calendar)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['status' => 'required|in:yes,no,maybe']);
        DB::transaction(function () use ($request, $event, $calendar, $data) {
            $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
            $current = HrCalendarEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) ($current->recurrence_parent_id ?? $current->id) === (int) $root->id, 409);
            $calendar->assertCanViewEvent($request->user(), $current);
            abort_if($root->archived_at || $current->archived_at, 404);
            abort_if($root->registration_enabled, 422, 'Choose a date to register for this event.');
            abort_if($root->cancelled_at || $current->cancelled_at, 409, 'This event was cancelled. Its original responses remain in history.');
            $attendee = $root->attendees()->where('audience_type', 'person')->where('user_id', $request->user()->id)->first();
            abort_unless($attendee, 403);
            $attendee->update(['rsvp_status' => $data['status'], 'responded_at' => now()]);
        });

        return back()->with('success', 'Your response was saved.');
    }

    public function respond(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'status' => 'required|in:going,maybe,declined']);

        return response()->json(['registration' => $registration->respond($request->user(), $event, $data['date'], $data['status'])]);
    }

    public function cancel(Request $request, HrCalendarEvent $event, HrCalendarCancellation $cancellation)
    {
        return response()->json($cancellation->cancel($request->user(), $event, $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['enabled' => 'required|boolean', 'capacity' => 'nullable|integer|min:1|max:100000', 'version' => 'required|integer|min:1', 'date' => 'nullable|date_format:Y-m-d',
            'closes_before_minutes' => 'sometimes|nullable|integer|min:0|max:43200', 'actor_id' => 'sometimes|integer|min:1', 'edit_version' => 'sometimes|integer|min:1']);
        $result = DB::transaction(function () use ($request, $event, $registration, $data) {
            if (! empty($data['date'])) {
                $registration->occurrence($request->user(), $event, $data['date']);
            }
            $registration->configure($request->user(), $event, $data);

            return ['saved' => true, 'registration' => ! empty($data['date']) ? $registration->summary($request->user(), $event->fresh(), $data['date']) : null];
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function attendance(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['date' => 'required|date_format:Y-m-d',
            'registration_id' => 'nullable|integer|min:1', 'attendance' => 'nullable|in:attended,no_show', 'version' => 'nullable|integer|min:1']);
        if ($request->isMethod('PUT')) {
            $request->validate(['registration_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1']);
            $result = $registration->attendance($request->user(), $event, $data['date'], (int) $data['registration_id'], $data['attendance'] ?? null, (int) $data['version']);
        } else {
            $result = $registration->roster($request->user(), $event, $data['date']);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function options(Request $request, HubAccess $access, HrCalendarAccessService $calendar)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 404);
        $access->assertEnter($user);
        abort_unless(app(HubEventPublishing::class)->canCreate($user), 403);

        return response()->json([
            'actor_id' => $user->id,
            'can_schedule' => app(HubEventPublishing::class)->schedulingReady() && $user->canDo('company_hub.publish'),
            'cutoff_supported' => app(HrEventRegistrationService::class)->cutoffReady(),
            'personal_reminders_supported' => app(HrCalendarPersonalReminders::class)->ready(),
            'can_manage_recurring' => $user->canDo('calendar.manage_recurring'),
            'can_publish' => $user->canDo('company_hub.publish'),
            'sites' => $calendar->visibleSitesQuery($user)->orderBy('name')->get(['id', 'name']),
            'departments' => $calendar->visibleDepartmentsQuery($user)->orderBy('name')->get(['id', 'name']),
            'teams' => $calendar->visibleCurrentProfilesQuery($user)->whereNotNull('team')->distinct()->orderBy('team')->pluck('team'),
            'categories' => HrCalendarEventCategory::orderBy('sort')->get(['id', 'key', 'label', 'icon', 'color_token']),
            'staff' => $calendar->visibleCurrentStaffQuery($user)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($person) => ['value' => (string) $person->id, 'label' => $person->name]),
            'storyOptions' => app(HrCalendarHubContent::class)->stories($user),
            'emailAvailable' => app(HrCalendarReminderPreferences::class)->emailConfigured(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function personalReminder(Request $request, HrCalendarEvent $event, HubAccess $access, HrCalendarPersonalReminders $reminders)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 404);
        $access->assertEnter($user);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'edit_version' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d',
            'reminder_version' => 'required|integer|min:0', 'enabled' => 'required|boolean']);

        return response()->json($reminders->save($user, $event, $data))->header('Cache-Control', 'private, no-store');
    }

    public function offerPlace(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 404);
        $access->assertEnter($user);
        abort_unless($user->canDo('company_hub.write') && $user->canDo('hr.calendar.view'), 403);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'edit_version' => 'required|integer|min:1', 'registration_version' => 'required|integer|min:1',
            'date' => 'required|date_format:Y-m-d', 'registration_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1']);

        return response()->json($registration->offerPlace($user, $event, $data))->header('Cache-Control', 'private, no-store');
    }

    public function attendanceExport(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration)
    {
        $user = $request->user()->fresh();
        abort_unless($user, 404);
        $access->assertEnter($user);
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $roster = $registration->roster($user, $event, $data['date']);

        return response()->streamDownload(function () use ($roster): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Occurrence date', 'Name', 'Registration', 'Attendance', 'Recorded at (UTC)'], ',', '"', '');
            foreach ($roster['registrations'] as $person) {
                $cells = [$roster['date'], $person['name'], $person['status'], $person['attendance'] ?? '', $person['recorded_at'] ?? ''];
                // Spreadsheet formulas must never execute a staff-supplied name.
                $cells = array_map(fn ($value) => preg_match('/^[\s\x{FEFF}]*[=+@\-]|^[\t\r\n]/u', (string) $value) ? "'".$value : $value, $cells);
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        }, 'event-attendance-'.$data['date'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function history(Request $request, HubAccess $access, HrCalendarAggregator $calendar)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['year' => 'nullable|integer|min:1900|max:'.now('Pacific/Auckland')->year]);
        $year = (int) ($data['year'] ?? now('Pacific/Auckland')->year);
        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Pacific/Auckland')->utc();
        $to = Carbon::create($year, 12, 31, 23, 59, 59, 'Pacific/Auckland')->utc()->min(now());
        $events = collect($calendar->feed($from->toDateString(), $to->copy()->setTimezone('Pacific/Auckland')->toDateString(), ['event'], [], $request->user()))
            ->filter(fn ($row) => app(HrCalendarHubContent::class)->finishedAt(Carbon::parse($row['end']), $row['allDay'])->betweenIncluded($from, $to))
            ->map(function ($row) {
                $row['extendedProps']['attachments'] = array_map(fn ($file) => HubEventMedia::attachmentUrls($file, '/company-hub/event-files/'.$file['id']), $row['extendedProps']['attachments'] ?? []);

                return $row;
            })->sortByDesc('end')->values()->all();

        return response()->json(['year' => $year, 'events' => $events])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, HrCalendarEvent $event, HubAccess $access, HrEventRegistrationService $registration, HrCalendarAccessService $calendar, HrCalendarExport $export)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'reminder' => 'nullable|in:15,60,1440', 'scope' => 'sometimes|in:date,series']);
        if (($data['scope'] ?? 'date') === 'series') {
            $content = DB::transaction(function () use ($request, $event, $data, $calendar, $registration, $export) {
                $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
                $calendar->assertCanViewEvent($request->user(), $root);
                abort_if($root->archived_at || ! $root->rrule, 404);
                $current = $event->fresh();
                abort_unless($current, 404);
                abort_if((int) ($current->recurrence_parent_id ?? $current->id) !== (int) $root->id, 409, 'This date moved to another series. Reopen it before downloading.');
                $registration->occurrence($request->user(), $current, $data['date']);
                $children = HrCalendarEvent::where('recurrence_parent_id', $root->id)->get();

                return $export->series($root, $children, fn ($child) => $calendar->canViewEvent($request->user(), $child), isset($data['reminder']) ? (int) $data['reminder'] : null);
            });

            return response($content, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="company-event-series.ics"', 'Cache-Control' => 'private, no-store']);
        }
        $row = $registration->occurrence($request->user(), $event, $data['date']);
        $rootId = $row['extendedProps']['recurrenceParentId'] ?? $row['extendedProps']['eventId'];
        $escape = fn ($value) => str_replace(['\\', "\r", "\n", ';', ','], ['\\\\', '', '\\n', '\\;', '\\,'], (string) $value);
        $dateTime = fn ($value) => Carbon::parse($value)->utc()->format('Ymd\\THis\\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Oblivion Findings//Company Hub//EN', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
            'UID:calendar-'.$rootId.'-'.$data['date'].'@oblivion-findings', 'DTSTAMP:'.now()->utc()->format('Ymd\\THis\\Z'),
            'SEQUENCE:'.max(1, (int) $row['extendedProps']['editVersion']),
            'STATUS:'.(($row['extendedProps']['cancellation']['cancelled'] ?? false) ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:'.$escape($row['title']), 'DESCRIPTION:'.$escape($row['extendedProps']['description']), 'LOCATION:'.$escape($row['extendedProps']['location'])];
        if ($row['allDay']) {
            $lines[] = 'DTSTART;VALUE=DATE:'.Carbon::parse($row['start'])->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.Carbon::parse($row['end'])->startOfDay()->addDay()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$dateTime($row['start']);
            $lines[] = 'DTEND:'.$dateTime($row['end']);
        }
        if (! empty($data['reminder']) && ! ($row['extendedProps']['cancellation']['cancelled'] ?? false)) {
            array_push($lines, 'BEGIN:VALARM', 'TRIGGER:-PT'.$data['reminder'].'M', 'ACTION:DISPLAY', 'DESCRIPTION:Event reminder', 'END:VALARM');
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        // Fold UTF-8 lines by bytes without splitting a multibyte character.
        $folded = array_map(function ($line) {
            $out = [];
            while (strlen($line) > 73) {
                $chunk = mb_strcut($line, 0, 73, 'UTF-8');
                $out[] = $chunk;
                $line = substr($line, strlen($chunk));
            }
            $out[] = $line;

            return implode("\r\n ", $out);
        }, $lines);

        return response(implode("\r\n", $folded)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="company-event.ics"', 'Cache-Control' => 'private, no-store']);
    }
}
