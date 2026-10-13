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
use App\Domain\Hr\Services\HrCalendarHubContent;
use App\Domain\Hr\Services\HrCalendarRecurrence;
use App\Domain\Hr\Services\HrCalendarReminderPreferences;
use App\Domain\Hr\Services\HrCalendarSeriesService;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CalendarController extends Controller
{
    use ServesPrivateAttachments;

    /** Upload mime allowlist (stored-XSS defence — see ServesPrivateAttachments). */
    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt';

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
            'storyOptions' => app(HrCalendarHubContent::class)->stories($user),
            'emailAvailable' => app(HrCalendarReminderPreferences::class)->emailConfigured(),
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
            ->inRange($weekStart->toDateTimeString(), $weekEnd->toDateTimeString())
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
            ...$this->eventFileRules(),
            ...app(HrCalendarHubContent::class)->rules(),
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'rrule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date'],
            'recurrence_timezone' => ['sometimes', 'in:UTC,Pacific/Auckland'],
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
            'audience_user_ids' => ['exclude_unless:audience_type,people', 'required_if:audience_type,people', 'array', 'min:1'],
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
        $hubContent = $data['hub_content'] ?? null;
        unset($data['audience_type'], $data['audience_team'], $data['audience_user_ids'], $data['reminders'], $data['files'], $data['removed_attachment_ids'], $data['hub_content']);
        abort_if($request->input('removed_attachment_ids', []), 422, 'A new event has no files to remove.');

        $this->access->assertCanUseSite($user, isset($data['site_id']) ? (int) $data['site_id'] : null);
        $this->access->assertCanUseDepartment($user, isset($data['department_id']) ? (int) $data['department_id'] : null);
        $this->access->assertCanInviteUsers($user, $audienceUserIds);
        $this->assertAudienceReferencesEvent($audienceType, $data);

        $data['category_id'] = $this->resolveCategoryId($data['event_type'] ?? null);
        $data['recurrence_timezone'] ??= 'Pacific/Auckland';
        $data = $this->prepareTiming($data);

        $writtenFiles = [];
        try {
            [$event, $newInvitees] = DB::transaction(function () use ($request, $user, $data, $audienceType, $audienceUserIds, $audienceTeam, $reminders, $hubContent, &$writtenFiles) {
                $event = HrCalendarEvent::create(['created_by' => $user->id, ...$data]);
                $newInvitees = $this->syncAttendees($event, $audienceType, $audienceUserIds, $audienceTeam);
                $this->syncReminders($event, $reminders);
                $uploads = $this->saveEventFiles($request, $event, [], $writtenFiles);
                if ($hubContent !== null) {
                    app(HrCalendarHubContent::class)->save($user, $event, $hubContent, $uploads);
                }

                return [$event, $newInvitees];
            });
        } catch (\Throwable $exception) {
            $this->cleanWrittenFiles($writtenFiles);
            throw $exception;
        }
        DB::afterCommit(fn () => $this->notifyNewInvitees($event, $newInvitees));
        $request->attributes->set('hub.saved_event', $event);

        // All staged files are committed with this canonical event.
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
            'edit_version' => ['required', 'integer', 'min:1'],
        ]);

        $writtenFiles = [];
        try {
            return DB::transaction(function () use ($request, $event, &$writtenFiles) {
                [$event, $root] = $this->lockEditableEvent($request, $event);
                $this->saveEventFiles($request, $event, [], $writtenFiles, [$request->file('file')]);
                $root->increment('edit_version');

                return response()->json(['attachment' => $this->attachmentPayload($event->attachments()->latest('id')->firstOrFail()), 'edit_version' => $root->edit_version]);
            });
        } catch (\Throwable $exception) {
            $this->cleanWrittenFiles($writtenFiles);
            throw $exception;
        }
    }

    /** Remove an attachment (file + row). */
    public function destroyAttachment(Request $request, HrCalendarEventAttachment $attachment)
    {
        $user = $request->user();
        $event = $attachment->event()->with('attendees')->firstOrFail();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        $data = $request->validate(['event_id' => ['required', 'integer'], 'edit_version' => ['required', 'integer', 'min:1']]);
        abort_unless((int) $data['event_id'] === (int) $event->id, 404);

        return DB::transaction(function () use ($request, $event, $attachment) {
            [$event, $root] = $this->lockEditableEvent($request, $event);
            $writtenFiles = [];
            $this->saveEventFiles($request, $event, [$attachment->id], $writtenFiles, []);
            $root->increment('edit_version');

            return response()->json(['ok' => true, 'edit_version' => $root->edit_version]);
        });
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
            'eventId' => $attachment->event_id,
            'inherited' => false,
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
            ...$this->eventFileRules(),
            'edit_version' => ['required', 'integer', 'min:1'],
            ...app(HrCalendarHubContent::class)->rules(),
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['sometimes', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'rrule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date'],
            'recurrence_timezone' => ['sometimes', 'in:UTC,Pacific/Auckland'],
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
            'audience_user_ids' => ['exclude_unless:audience_type,people', 'required_if:audience_type,people', 'array', 'min:1'],
            'audience_user_ids.*' => ['integer', 'exists:users,id'],
            'reminders' => ['nullable', 'array'],
            'reminders.*.offset_minutes' => ['required_with:reminders', 'integer', 'min:0', 'max:43200'],
            'reminders.*.channel' => ['required_with:reminders', 'in:notification,email'],
            // Recurring-edit scope (gated on calendar.manage_recurring below).
            'scope' => ['nullable', 'in:all,this,following'],
            'occurrence_date' => ['nullable', 'date'],
        ]);

        $copiedFiles = [];
        try {
            return DB::transaction(function () use ($request, $user, $event, $data, &$copiedFiles) {
                // Registration and series edits share the root lock, including exceptions.
                $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
                $event = $event->id === $root->id ? $root : HrCalendarEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This date moved to a changed series. Reload the event before editing.');
                $this->access->assertCanManageEvent($user, $event);
                $this->assertEventIsActive($event);
                $this->assertEventIsActive($root);
                $this->assertHubWrite($request, $root);
                $this->assertEditVersion($root, (int) $data['edit_version']);
                $scope = $data['scope'] ?? 'all';
                $hubContent = $data['hub_content'] ?? null;
                unset($data['hub_content']);
                if (($scope === 'this' || $event->recurrence_parent_id) && isset($data['recurrence_timezone'])) {
                    abort_unless($data['recurrence_timezone'] === $root->recurrence_timezone, 422, 'A single date keeps the series repeat timing.');
                }
                $data = $this->prepareTiming($data, $root);
                $removedIds = array_map('intval', $data['removed_attachment_ids'] ?? []);
                // A date's inherited files belong to the series and cannot be removed here.
                $this->assertOwnedFiles($event, $removedIds);
                if ($scope === 'this' && ! $event->recurrence_parent_id && $removedIds) {
                    throw ValidationException::withMessages(['files' => 'Series files are shared. Edit the whole series to remove them.']);
                }
                $occurrenceDate = $data['occurrence_date'] ?? null;
                $audienceType = $data['audience_type'] ?? null;
                $audienceTeam = $audienceType === 'team'
                    ? $this->access->canonicalVisibleTeam($user, $data['audience_team'] ?? null)
                    : null;
                $audienceUserIds = $data['audience_user_ids'] ?? [];
                $audienceProvided = $request->has('audience_type');
                $reminders = $data['reminders'] ?? [];
                $remindersProvided = $request->has('reminders') || $request->boolean('replace_reminders');
                unset(
                    $data['scope'], $data['occurrence_date'], $data['audience_type'], $data['audience_team'],
                    $data['audience_user_ids'], $data['reminders'],
                    $data['edit_version'], $data['files'], $data['removed_attachment_ids'],
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

                    abort_unless($occurrenceDate, 422, 'Choose the date to edit.');
                    if ($scope === 'this' && $audienceProvided) {
                        $group = $event->attendees()->where('audience_type', '!=', 'person')->first();
                        $people = $event->attendees()->where('audience_type', 'person')->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                        $currentType = $group?->audience_type ?? ($people ? 'people' : 'org');
                        abort_unless($audienceType === $currentType && ($currentType !== 'team' || $audienceTeam === $group?->audience_ref)
                            && ($currentType !== 'people' || collect($audienceUserIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all() === $people), 422, 'A single date keeps the series audience. Choose this and following dates to change the audience.');
                    }
                    $attachmentMap = [];
                    $updated = app(HrCalendarSeriesService::class)->change($user, $event, Carbon::parse($occurrenceDate)->toDateString(), $data, $scope === 'following', $removedIds, $attachmentMap, $request->attributes->get('hub.approved_publication') === true);
                    if ($scope === 'following') {
                        $copiedFiles = $updated->attachments()->get()->map(fn ($file) => [$file->disk ?: 'private', $file->path])->all();
                    }
                    if ($scope === 'following' && $audienceProvided) {
                        $newInvitees = $this->syncAttendees($updated, $audienceType, $audienceUserIds, $audienceTeam);
                        DB::afterCommit(fn () => $this->notifyNewInvitees($updated, $newInvitees));
                    }
                    if ($remindersProvided) {
                        $this->syncReminders($updated, $reminders);
                        if ($scope === 'this') {
                            $updated->forceFill(['reminders_override' => true])->save();
                        }
                    }

                    $uploads = $this->saveEventFiles($request, $updated, [], $copiedFiles);
                    if ($hubContent !== null) {
                        app(HrCalendarHubContent::class)->save($user, $updated, $hubContent, $uploads, $attachmentMap);
                    }
                    $root->increment('edit_version');
                    $request->attributes->set('hub.saved_event', $updated);

                    return redirect()->back()->with('savedEventId', $updated->id)->with('success', $scope === 'this' ? 'This occurrence was updated.' : 'This and following events were updated.');
                }

                if ($event->recurrence_parent_id) {
                    abort_if(! empty($data['rrule']) || ! empty($data['recurrence_until']), 422, 'This date inherits the series repeat pattern.');
                    abort_if((array_key_exists('site_id', $data) && (int) $data['site_id'] !== (int) $root->site_id)
                        || (array_key_exists('department_id', $data) && (int) $data['department_id'] !== (int) $root->department_id), 422, 'This date keeps the series audience and Site.');
                    if ($audienceProvided) {
                        $group = $root->attendees()->where('audience_type', '!=', 'person')->first();
                        $people = $root->attendees()->where('audience_type', 'person')->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                        $currentType = $group?->audience_type ?? ($people ? 'people' : 'org');
                        abort_unless($audienceType === $currentType && ($currentType !== 'team' || $audienceTeam === $group?->audience_ref)
                            && ($currentType !== 'people' || collect($audienceUserIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all() === $people), 422, 'This date keeps the series audience.');
                        $audienceProvided = false;
                    }
                }
                app(HrCalendarSeriesService::class)->assertRetainsDates($event, $data);
                $event->update($data);
                if (! $event->recurrence_parent_id) {
                    HrCalendarEvent::where('recurrence_parent_id', $event->id)->update(['site_id' => $event->site_id, 'department_id' => $event->department_id]);
                }

                if ($audienceProvided) {
                    $newInvitees = $this->syncAttendees($event, $audienceType, $audienceUserIds, $audienceTeam);
                    DB::afterCommit(fn () => $this->notifyNewInvitees($event, $newInvitees));
                }
                if ($remindersProvided) {
                    $this->syncReminders($event, $reminders);
                    if ($event->recurrence_parent_id) {
                        $event->forceFill(['reminders_override' => true])->save();
                    }
                }

                $uploads = $this->saveEventFiles($request, $event, $removedIds, $copiedFiles);
                if ($hubContent !== null) {
                    app(HrCalendarHubContent::class)->save($user, $event, $hubContent, $uploads);
                }
                $root->increment('edit_version');
                $request->attributes->set('hub.saved_event', $event);

                return redirect()->back()->with('savedEventId', $event->id)->with('success', 'Calendar event updated.');
            });
        } catch (\Throwable $exception) {
            // A later audience/reminder failure also rolls back copied private files.
            $this->cleanWrittenFiles($copiedFiles);
            throw $exception;
        }
    }

    private function eventFileRules(): array
    {
        return [
            'files' => ['sometimes', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:'.self::ATTACHMENT_MIMES],
            'removed_attachment_ids' => ['sometimes', 'array', 'max:100'],
            'removed_attachment_ids.*' => ['integer', 'distinct'],
        ];
    }

    private function prepareTiming(array $data, ?HrCalendarEvent $original = null): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->utc();
            }
        }
        $recurrence = app(HrCalendarRecurrence::class);
        if (array_key_exists('rrule', $data)) {
            $recurrence->validateRule($data['rrule']);
        }
        if (array_key_exists('recurrence_until', $data)) {
            $zone = ($data['is_all_day'] ?? $original?->is_all_day ?? false) ? 'UTC' : ($data['recurrence_timezone'] ?? $original?->recurrence_timezone ?? 'UTC');
            $data['recurrence_until'] = $recurrence->normalizeUntil($data['recurrence_until'], $zone, $original);
        }

        return $data;
    }

    private function assertEditVersion(HrCalendarEvent $root, int $version): void
    {
        if ((int) $root->edit_version !== $version) {
            throw ValidationException::withMessages(['edit_version' => 'This event changed while you were editing. Your unsaved changes are still here. Close and reopen the editor to review the latest version before saving.']);
        }
    }

    private function lockEditableEvent(Request $request, HrCalendarEvent $event, bool $lifecycle = false): array
    {
        $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
        $this->assertHubWrite($request, $root, $lifecycle);
        $event = $event->id === $root->id ? $root : HrCalendarEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This date moved to a changed series. Reopen the event.');
        $this->access->assertCanManageEvent($request->user(), $event);
        $this->assertEventIsActive($root);
        $this->assertEventIsActive($event);
        $this->assertEditVersion($root, (int) $request->input('edit_version'));

        return [$event, $root];
    }

    private function assertHubWrite(Request $request, HrCalendarEvent $root, bool $lifecycle = false): void
    {
        if (! $root->hub_managed || $request->attributes->get('hub.approved_publication') === true) {
            return;
        }
        if ($lifecycle) {
            abort_unless($request->user()->canDo('company_hub.publish'), 403, 'Publishing permission is required to archive or restore a Hub event.');

            return;
        }
        throw ValidationException::withMessages(['edit_version' => 'This event is published through Company Hub. Open Event drafts in the Hub to prepare and publish changes.']);
    }

    private function assertOwnedFiles(HrCalendarEvent $event, array $ids): void
    {
        abort_unless($event->attachments()->whereIn('id', $ids)->count() === count($ids), 404);
    }

    /** Called under the series lock. New files are compensated on rollback; old files are removed only after commit. */
    private function saveEventFiles(Request $request, HrCalendarEvent $event, array $removedIds, array &$writtenFiles, ?array $uploads = null): array
    {
        $this->assertOwnedFiles($event, $removedIds);
        $saved = [];
        foreach ($uploads ?? $request->file('files', []) as $file) {
            $path = $file->store('hr/calendar/events/'.$event->id, 'private');
            if (! $path) {
                throw ValidationException::withMessages(['files' => 'A file could not be saved. No event changes were saved. Please try again.']);
            }
            $writtenFiles[] = ['private', $path];
            $saved[] = $event->attachments()->create([
                'uploaded_by' => $request->user()->id, 'disk' => 'private', 'path' => $path,
                'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            ])->id;
        }
        foreach ($event->attachments()->whereIn('id', $removedIds)->get() as $attachment) {
            $attachment->delete();
            DB::afterCommit(fn () => $this->cleanWrittenFiles([[$attachment->disk ?: 'private', $attachment->path]]));
        }

        return $saved;
    }

    private function cleanWrittenFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            try {
                if (! Storage::disk($disk)->delete($path)) {
                    Log::warning('Calendar file cleanup needs retry', ['disk' => $disk, 'path' => $path]);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
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

        $registrationEvent = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        if ($registrationEvent?->registration_enabled) {
            $data = $request->validate(['status' => 'required|in:yes,no,maybe', 'occurrence_date' => 'required|date_format:Y-m-d']);
            app(HrEventRegistrationService::class)->respond($user, $event, $data['occurrence_date'], match ($data['status']) {
                'yes' => 'going', 'no' => 'declined', default => 'maybe'
            });

            return back()->with('success', 'Your response for this date was saved.');
        }

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

    /** Archive a calendar event while retaining its evidence graph and files. */
    public function destroy(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);
        $this->assertEventIsActive($event);

        $data = $request->validate([
            'archive_reason' => ['nullable', 'string', 'max:500'],
            'edit_version' => ['required', 'integer', 'min:1'],
            'scope' => ['nullable', 'in:all'],
        ]);

        DB::transaction(function () use ($request, $event, $user, $data) {
            [$event, $root] = $this->lockEditableEvent($request, $event, true);
            $event->update(['archived_at' => now(), 'archived_by' => $user->id, 'archive_reason' => $data['archive_reason'] ?? null]);
            $root->increment('edit_version');
        });

        return redirect()->back()->with('success', 'Calendar event archived.');
    }

    /** Restore an archived calendar event to active feeds. */
    public function restore(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        $this->access->assertCanManageEvent($user, $event);

        DB::transaction(function () use ($event, $user, $request) {
            $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
            $this->assertHubWrite($request, $root, true);
            $fresh = HrCalendarEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) ($fresh->recurrence_parent_id ?? $fresh->id) === (int) $root->id, 409);
            $this->access->assertCanManageEvent($user, $fresh);
            $fresh->update(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null]);
            $root->increment('edit_version');
        });

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
