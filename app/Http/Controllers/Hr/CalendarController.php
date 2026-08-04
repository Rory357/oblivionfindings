<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Models\HrCalendarEventCategory;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrICalToken;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\CalendarEventInviteNotification;
use App\Domain\Hr\Notifications\CalendarEventRsvpNotification;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CalendarController extends Controller
{
    use ServesPrivateAttachments;

    /** Upload mime allowlist (stored-XSS defence — see ServesPrivateAttachments). */
    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt';

    public function __construct(
        private readonly HrCalendarAggregator $aggregator,
        private readonly HrCalendarAccessService $access,
    ) {}

    /**
     * The unified, layered organisation calendar. Events themselves are
     * range-fetched client-side from feed(); index() bootstraps the page chrome:
     * filter options, permissions, the hero's headline stats, and the "Up next"
     * rail. (The Renewals tab consumes the compliance layer of the same feed.)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $sites = $this->access->visibleSitesQuery($user)
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = $this->access->visibleDepartmentsQuery($user)
            ->orderBy('name')
            ->get(['id', 'name']);

        $teams = $this->access->visibleCurrentProfilesQuery($user)
            ->whereNotNull('team')
            ->distinct()
            ->orderBy('team')
            ->pluck('team')
            ->map(fn (string $team) => HrEmployeeProfile::normalizeTeam($team))
            ->filter()
            ->unique(fn (string $team) => mb_strtolower($team))
            ->sort(fn (string $left, string $right) => strnatcasecmp($left, $right))
            ->values();

        $icalToken = HrICalToken::query()
            ->where('user_id', $user->id)
            ->value('token');

        $categories = HrCalendarEventCategory::query()
            ->orderBy('sort')
            ->get(['id', 'key', 'label', 'icon', 'color_token']);

        $canManage = $this->canManage($user);
        $archivedEvents = collect();
        if ($canManage) {
            $archivedQuery = HrCalendarEvent::query()
                ->archived()
                ->with(['archiver:id,name', 'attendees']);
            $this->access->applySiteScope($archivedQuery, $user);
            $archivedEvents = $this->access->visibleEvents(
                $archivedQuery->orderByDesc('archived_at')->get(),
                $user,
            )
                ->take(50)
                ->map(fn (HrCalendarEvent $event) => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'archived_at' => $event->archived_at?->toIso8601String(),
                    'archived_by' => $event->archiver?->name,
                    'archive_reason' => $event->archive_reason,
                ])
                ->values();
        }

        // Staff for the wizard's "invite people" picker (active employees).
        $staff = $this->access->visibleCurrentStaffQuery($user)
            ->with('hrEmployeeProfile:user_id,position_title')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $staffUser) => [
                'value' => (string) $staffUser->id,
                'label' => $staffUser->name,
                'sub' => $staffUser->hrEmployeeProfile?->position_title,
            ])
            ->filter(fn ($p) => $p['label'] !== null)
            ->values();

        return Inertia::render('hr/calendar/index', [
            'sites' => $sites,
            'departments' => $departments,
            'teams' => $teams,
            'categories' => $categories,
            'staff' => $staff,
            'archivedEvents' => $archivedEvents,
            'stats' => $this->heroStats($user),
            'upNext' => $this->upNext($user),
            'ical' => [
                'url' => $icalToken ? url('/hr/ical/'.$icalToken) : null,
            ],
            'can' => [
                'manage' => $canManage,
                'manageRecurring' => (bool) $user->canDo('calendar.manage_recurring'),
                'seeSensitive' => (bool) $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /** Headline stats for the hero band (each click-filters / deep-links). */
    private function heroStats(User $user): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $today = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $eventQuery = HrCalendarEvent::query()
            ->active()
            ->inRange($weekStart->toDateString(), $weekEnd->toDateString())
            ->with('attendees');
        $this->access->applySiteScope($eventQuery, $user);
        $eventsThisWeek = $this->access->visibleEvents($eventQuery->get(), $user)->count();

        $visibleStaffIds = $this->access->visibleCurrentStaffQuery($user)->select('users.id');

        $onLeaveToday = HrLeaveRequest::query()
            ->whereIn('user_id', $visibleStaffIds)
            ->where('status', 'approved')
            ->where('starts_at', '<=', $todayEnd)
            ->where('ends_at', '>=', $today)
            ->distinct('user_id')
            ->count('user_id');

        $coverageGapsToday = 0;
        if ($user->canDo('rostering.viewAny')) {
            $coverageGapsToday = collect($this->access->accessibleSiteIds($user))
                ->flatMap(fn (int $siteId) => app(ShiftCoverageService::class)
                    ->buildRangeCoverage($today, $todayEnd, $siteId))
                ->filter(fn (array $w) => ! empty($w['has_actionable_gap']))
                ->count();
        }

        $renewalSoon = HrStaffComplianceStatus::query()
            ->whereIn('user_id', $this->access->visibleCurrentStaffQuery($user)->select('users.id'))
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, now()->copy()->addDays(30)])
            ->count();

        return [
            'eventsThisWeek' => $eventsThisWeek,
            'onLeaveToday' => $onLeaveToday,
            'coverageGapsToday' => $coverageGapsToday,
            'renewalsSoon' => $renewalSoon,
        ];
    }

    /** Next ~5 upcoming entries across the default layers, for the hero rail. */
    private function upNext(User $user): array
    {
        $from = now()->toDateString();
        $to = now()->copy()->addDays(30)->toDateString();

        $feed = $this->aggregator->feed(
            $from,
            $to,
            ['event', 'leave', 'shift', 'holiday'],
            [],
            $user,
        );

        return collect($feed)
            ->filter(fn ($e) => ! empty($e['start']) && empty($e['extendedProps']['gap']))
            ->sortBy('start')
            ->take(5)
            ->map(fn ($e) => [
                'id' => $e['id'],
                'layer' => $e['layer'],
                'title' => $e['title'],
                'start' => $e['start'],
                'allDay' => $e['allDay'] ?? false,
                'deepLink' => $e['deepLink'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Unified layered feed for the rebuilt /hr/calendar page. Returns one flat
     * list of CalendarLayerFeed rows (see resources/js/lib/calendar/layer-feed.ts)
     * across every requested layer, range-fetched on FullCalendar's datesSet.
     */
    public function feed(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'layers' => ['nullable', 'string'],
            'site' => ['nullable', 'integer'],
            'team' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'integer'],
        ]);

        $allLayers = ['event', 'leave', 'shift', 'holiday', 'compliance', 'milestone'];
        $layers = array_values(array_intersect(
            $allLayers,
            array_filter(explode(',', (string) ($data['layers'] ?? ''))),
        ));
        if ($layers === []) {
            $layers = ['event', 'leave', 'shift', 'holiday'];
        }

        if (isset($data['site'])) {
            $this->access->assertCanUseSite($user, (int) $data['site']);
        }
        if (isset($data['department'])) {
            $this->access->assertCanUseDepartment($user, (int) $data['department']);
        }

        $events = $this->aggregator->feed(
            $data['from'],
            $data['to'],
            $layers,
            [
                'site_id' => $data['site'] ?? null,
                'team' => $data['team'] ?? null,
                'department_id' => $data['department'] ?? null,
            ],
            $user,
        );

        return response()->json(['events' => $events]);
    }

    /**
     * Store a new calendar event.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'rrule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'audience_type' => ['nullable', 'in:org,site,department,team,people'],
            'audience_team' => [
                'exclude_unless:audience_type,team',
                'required_if:audience_type,team',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if ($this->access->canonicalVisibleTeam($user, (string) $value) === null) {
                        $fail('The selected team is not available.');
                    }
                },
            ],
            'audience_user_ids' => ['nullable', 'required_if:audience_type,people', 'array', 'min:1'],
            'audience_user_ids.*' => ['integer', 'exists:users,id'],
            'reminders' => ['nullable', 'array'],
            'reminders.*.offset_minutes' => ['required_with:reminders', 'integer', 'min:0', 'max:43200'],
            'reminders.*.channel' => ['required_with:reminders', 'in:notification,email'],
        ]);

        $audienceType = $data['audience_type'] ?? null;
        $audienceTeam = $audienceType === 'team'
            ? $this->access->canonicalVisibleTeam($user, $data['audience_team'] ?? null)
            : null;
        $audienceUserIds = $data['audience_user_ids'] ?? [];
        $reminders = $data['reminders'] ?? [];
        unset($data['audience_type'], $data['audience_team'], $data['audience_user_ids'], $data['reminders']);

        $this->access->assertCanUseSite($user, isset($data['site_id']) ? (int) $data['site_id'] : null);
        $this->access->assertCanUseDepartment($user, isset($data['department_id']) ? (int) $data['department_id'] : null);
        $this->access->assertCanInviteUsers($user, $audienceUserIds);
        $this->assertAudienceReferencesEvent($audienceType, $data);

        $data['category_id'] = $this->resolveCategoryId($data['event_type'] ?? null);

        $event = HrCalendarEvent::create([
            'created_by' => $user->id,
            ...$data,
        ]);

        $newInvitees = $this->syncAttendees($event, $audienceType, $audienceUserIds, $audienceTeam);
        $this->syncReminders($event, $reminders);
        $this->notifyNewInvitees($event, $newInvitees);

        // Flash the new id so the wizard can upload any staged attachments to it.
        return redirect()->back()
            ->with('success', 'Calendar event created.')
            ->with('createdEventId', $event->id);
    }

    /**
     * Upload one attachment to an event (multipart, single file). The wizard
     * uploads staged files here after the event is saved.
     */
    public function storeAttachment(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:'.self::ATTACHMENT_MIMES],
        ]);

        $file = $request->file('file');
        $path = $file->store('hr/calendar/events/'.$event->id, 'private');

        $attachment = $event->attachments()->create([
            'uploaded_by' => $user->id,
            'disk' => 'private',
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json(['attachment' => $this->attachmentPayload($attachment)]);
    }

    /** Remove an attachment (file + row). */
    public function destroyAttachment(Request $request, HrCalendarEventAttachment $attachment)
    {
        $user = $request->user();
        $event = $attachment->event()->with('attendees')->firstOrFail();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        Storage::disk($attachment->disk ?: 'private')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    /** Stream an attachment from the private disk (hardened headers). */
    public function downloadAttachment(Request $request, HrCalendarEventAttachment $attachment)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $event = $attachment->event()->with('attendees')->firstOrFail();
        $this->access->assertCanViewEvent($user, $event);

        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    /** @return array<string, mixed> */
    private function attachmentPayload(HrCalendarEventAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'url' => url('/hr/calendar/attachments/'.$attachment->id.'/download'),
        ];
    }

    /**
     * Update an existing calendar event.
     */
    public function update(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['sometimes', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'rrule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'audience_type' => ['nullable', 'in:org,site,department,team,people'],
            'audience_team' => [
                'exclude_unless:audience_type,team',
                'required_if:audience_type,team',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if ($this->access->canonicalVisibleTeam($user, (string) $value) === null) {
                        $fail('The selected team is not available.');
                    }
                },
            ],
            'audience_user_ids' => ['nullable', 'required_if:audience_type,people', 'array', 'min:1'],
            'audience_user_ids.*' => ['integer', 'exists:users,id'],
            'reminders' => ['nullable', 'array'],
            'reminders.*.offset_minutes' => ['required_with:reminders', 'integer', 'min:0', 'max:43200'],
            'reminders.*.channel' => ['required_with:reminders', 'in:notification,email'],
            // Recurring-edit scope (gated on calendar.manage_recurring below).
            'scope' => ['nullable', 'in:all,this,following'],
            'occurrence_date' => ['nullable', 'date'],
        ]);

        $scope = $data['scope'] ?? 'all';
        $occurrenceDate = $data['occurrence_date'] ?? null;
        $audienceType = $data['audience_type'] ?? null;
        $audienceTeam = $audienceType === 'team'
            ? $this->access->canonicalVisibleTeam($user, $data['audience_team'] ?? null)
            : null;
        $audienceUserIds = $data['audience_user_ids'] ?? [];
        $audienceProvided = $request->has('audience_type');
        $reminders = $data['reminders'] ?? [];
        $remindersProvided = $request->has('reminders');
        unset(
            $data['scope'], $data['occurrence_date'], $data['audience_type'], $data['audience_team'],
            $data['audience_user_ids'], $data['reminders'],
        );

        $siteId = array_key_exists('site_id', $data) ? ($data['site_id'] === null ? null : (int) $data['site_id']) : $event->site_id;
        $departmentId = array_key_exists('department_id', $data)
            ? ($data['department_id'] === null ? null : (int) $data['department_id'])
            : $event->department_id;
        $this->access->assertCanUseSite($user, $siteId);
        $this->access->assertCanUseDepartment($user, $departmentId);
        if ($audienceProvided) {
            $this->access->assertCanInviteUsers($user, $audienceUserIds);
            $this->assertAudienceReferencesEvent($audienceType, [
                'site_id' => $siteId,
                'department_id' => $departmentId,
            ]);
        }

        if (array_key_exists('event_type', $data)) {
            $data['category_id'] = $this->resolveCategoryId($data['event_type']);
        }

        // Single-occurrence + "this & following" edits only apply to a recurring
        // base and require the recurring-management permission.
        if (in_array($scope, ['this', 'following'], true) && $event->rrule) {
            abort_unless($user->canDo('calendar.manage_recurring'), 403);

            if ($scope === 'this' && $occurrenceDate) {
                $this->upsertOccurrenceOverride($event, $occurrenceDate, $data);

                return redirect()->back()->with('success', 'This occurrence was updated.');
            }

            if ($scope === 'following' && $occurrenceDate) {
                $this->splitSeriesFrom($event, $occurrenceDate, $data);

                return redirect()->back()->with('success', 'This and following events were updated.');
            }
        }

        $event->update($data);

        if ($audienceProvided) {
            $newInvitees = $this->syncAttendees($event, $audienceType, $audienceUserIds, $audienceTeam);
            $this->notifyNewInvitees($event, $newInvitees);
        }
        if ($remindersProvided) {
            $this->syncReminders($event, $reminders);
        }

        return redirect()->back()->with('success', 'Calendar event updated.');
    }

    /**
     * Replace an event's reminders, preserving last_sent_at for any reminder that
     * is unchanged (same offset + channel) so editing won't re-fire past sends.
     *
     * @param  list<array{offset_minutes:int, channel:string}>  $reminders
     */
    private function syncReminders(HrCalendarEvent $event, array $reminders): void
    {
        $keep = [];
        foreach ($reminders as $r) {
            $offset = (int) $r['offset_minutes'];
            $channel = $r['channel'];
            $event->reminders()->updateOrCreate(
                ['offset_minutes' => $offset, 'channel' => $channel],
                [],
            );
            $keep[] = $offset.':'.$channel;
        }

        foreach ($event->reminders()->get() as $existing) {
            if (! in_array($existing->offset_minutes.':'.$existing->channel, $keep, true)) {
                $existing->delete();
            }
        }
    }

    /**
     * Replace an event's audience rows, preserving existing RSVPs for invitees
     * who remain. `org`/`site`/`department` set the reach; `people` invites
     * named users who can RSVP.
     *
     * @param  list<int>  $userIds
     */
    /**
     * @return array<int, int> User ids newly added to the invite list (so the
     *                         caller can notify them — re-syncs don't re-invite).
     */
    private function syncAttendees(
        HrCalendarEvent $event,
        ?string $audienceType,
        array $userIds,
        ?string $audienceTeam = null,
    ): array {
        if ($audienceType === null) {
            return [];
        }

        if ($audienceType !== 'people') {
            $event->attendees()->delete();
            $ref = match ($audienceType) {
                'site' => $event->site_id,
                'department' => $event->department_id,
                'team' => $audienceTeam,
                default => null,
            };
            $event->attendees()->create([
                'audience_type' => $audienceType,
                'audience_ref' => $ref,
                'rsvp_status' => 'none',
            ]);

            return [];
        }

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        // Drop non-person rows + people no longer invited; keep RSVPs for the rest.
        $event->attendees()->where('audience_type', '!=', 'person')->delete();
        $event->attendees()
            ->where('audience_type', 'person')
            ->whereNotIn('user_id', $userIds ?: [0])
            ->delete();

        $existing = $event->attendees()->where('audience_type', 'person')->pluck('user_id')->all();
        $added = [];
        foreach (array_diff($userIds, $existing) as $userId) {
            $event->attendees()->create([
                'user_id' => $userId,
                'audience_type' => 'person',
                'rsvp_status' => 'none',
            ]);
            $added[] = $userId;
        }

        return $added;
    }

    /**
     * Tell newly-invited people about the event — without this the RSVP
     * feature is unreachable (you can't respond to an invite you never saw).
     */
    private function notifyNewInvitees(HrCalendarEvent $event, array $userIds): void
    {
        foreach ($userIds as $userId) {
            if ($userId === $event->created_by) {
                continue;
            }
            $invitee = User::find($userId);
            if (! $invitee) {
                continue;
            }
            try {
                $invitee->notify(new CalendarEventInviteNotification($event));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send calendar invite notification', [
                    'event_id' => $event->id,
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * RSVP to an event as the current user (must be an invited person attendee).
     */
    public function rsvp(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $this->access->assertCanViewEvent($user, $event);
        $this->assertEventIsActive($event);

        $data = $request->validate([
            'status' => ['required', 'in:yes,no,maybe'],
        ]);

        $attendee = $event->attendees()
            ->where('audience_type', 'person')
            ->where('user_id', $user->id)
            ->first();

        abort_unless($attendee !== null, 403, 'You are not on the invite list for this event.');

        $attendee->update([
            'rsvp_status' => $data['status'],
            'responded_at' => now(),
        ]);

        // The organiser is waiting on responses — a quiet in-app note, no mail.
        if ($event->created_by && $event->created_by !== $user->id) {
            $organiser = User::find($event->created_by);
            if ($organiser) {
                try {
                    $organiser->notify(new CalendarEventRsvpNotification($event, $user, $data['status']));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send RSVP notification', [
                        'event_id' => $event->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return redirect()->back()->with('success', 'Your response was saved.');
    }

    /**
     * "This event": store/refresh an exception child that overrides the parent
     * series on a single occurrence date. The aggregator skips the parent's
     * occurrence on that date and renders the child instead.
     */
    private function upsertOccurrenceOverride(HrCalendarEvent $parent, string $occurrenceDate, array $data): void
    {
        $child = HrCalendarEvent::query()
            ->where('recurrence_parent_id', $parent->id)
            ->where('is_exception', true)
            ->whereDate('exception_date', $occurrenceDate)
            ->first();

        $payload = [
            ...$parent->only([
                'title', 'description', 'event_type', 'category_id',
                'is_all_day', 'location', 'department', 'department_id', 'site_id', 'created_by',
            ]),
            ...$data,
            'rrule' => null,
            'recurrence_until' => null,
            'recurrence_parent_id' => $parent->id,
            'is_exception' => true,
            'exception_date' => $occurrenceDate,
        ];

        if ($child) {
            $child->update($payload);
        } else {
            HrCalendarEvent::create($payload);
        }
    }

    /**
     * "This & following": cap the original series the day before the occurrence
     * and start a fresh series (with the edits) from the occurrence onward.
     */
    private function splitSeriesFrom(HrCalendarEvent $parent, string $occurrenceDate, array $data): void
    {
        $splitDay = Carbon::parse($occurrenceDate)->startOfDay();

        $newStart = $splitDay->copy()->setTimeFromTimeString($parent->starts_at->format('H:i:s'));
        $durationSec = $parent->ends_at ? $parent->ends_at->getTimestamp() - $parent->starts_at->getTimestamp() : 0;

        DB::transaction(function () use ($parent, $data, $newStart, $durationSec, $splitDay): void {
            $newSeries = HrCalendarEvent::create([
                ...$parent->only([
                    'title', 'description', 'event_type', 'category_id',
                    'is_all_day', 'location', 'department', 'department_id', 'site_id', 'created_by',
                    'rrule', 'recurrence_until',
                ]),
                ...$data,
                'starts_at' => $data['starts_at'] ?? $newStart,
                'ends_at' => $data['ends_at'] ?? $newStart->copy()->addSeconds($durationSec),
            ]);

            foreach ($parent->attendees()->get() as $attendee) {
                $newSeries->attendees()->create($attendee->only([
                    'user_id', 'audience_type', 'audience_ref', 'rsvp_status', 'responded_at',
                ]));
            }
            foreach ($parent->reminders()->get() as $reminder) {
                $newSeries->reminders()->create($reminder->only([
                    'offset_minutes', 'channel',
                ]));
            }

            // The original series now ends the day before the split point.
            $parent->update(['recurrence_until' => $splitDay->copy()->subDay()->endOfDay()]);
        });
    }

    /** Archive a calendar event while retaining its evidence graph and files. */
    public function destroy(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        $data = $request->validate([
            'archive_reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(fn () => $event->update([
            'archived_at' => now(),
            'archived_by' => $user->id,
            'archive_reason' => $data['archive_reason'] ?? null,
        ]));

        return redirect()->back()->with('success', 'Calendar event archived.');
    }

    /** Restore an archived calendar event to active feeds. */
    public function restore(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);

        DB::transaction(fn () => $event->update([
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
        ]));

        return redirect()->back()->with('success', 'Calendar event restored.');
    }

    private function assertEventIsActive(HrCalendarEvent $event): void
    {
        abort_if(
            $event->archived_at !== null,
            409,
            'Restore the archived calendar event before changing it.',
        );
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.view')
            || $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.view')
            || $user->canDo('calendar.viewAny')
            || $user->canDo('calendar.manage_recurring')
            // Leave viewers land here too (the retired Time-Off page folded into
            // the Leave layer); they see leave/holiday layers, not event editing.
            || $user->canDo('hr.leave.viewAny')
            || $user->canDo('hr.leave.viewOwn')
            || $user->canDo('hr.leave.manage')
        );
    }

    /** Map an event_type key to its application-wide category id. */
    private function resolveCategoryId(?string $key): ?int
    {
        if (! $key) {
            return null;
        }

        return HrCalendarEventCategory::query()
            ->where('key', $key)
            ->value('id');
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.create')
            || $user->canDo('calendar.manage')
            || $user->canDo('calendar.manage_recurring')
        );
    }

    /** @param array<string, mixed> $eventData */
    private function assertAudienceReferencesEvent(?string $audienceType, array $eventData): void
    {
        if ($audienceType === 'site') {
            abort_unless(! empty($eventData['site_id']), 422, 'Choose a Site for a Site audience.');
        }

        if ($audienceType === 'department') {
            abort_unless(! empty($eventData['department_id']), 422, 'Choose a department for a department audience.');
        }
    }
}
