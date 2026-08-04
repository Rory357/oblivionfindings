<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Domain\Hr\Models\HrAnnouncementAttachment;
use App\Domain\Hr\Models\HrAnnouncementReminder;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedReaction;
use App\Domain\Hr\Models\HrFeedReply;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Notifications\AnnouncementReminderNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    use ServesPrivateAttachments;

    private const PRIORITIES = [
        ['value' => 'low', 'label' => 'Low'],
        ['value' => 'normal', 'label' => 'Normal'],
        ['value' => 'high', 'label' => 'High'],
        ['value' => 'urgent', 'label' => 'Urgent'],
    ];

    private const AUDIENCES = [
        ['value' => 'all', 'label' => 'All Staff'],
        ['value' => 'department', 'label' => 'Department'],
        ['value' => 'site', 'label' => 'Site'],
        ['value' => 'role', 'label' => 'Role'],
    ];

    private const STATUSES = [
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'scheduled', 'label' => 'Scheduled'],
        ['value' => 'published', 'label' => 'Published'],
        ['value' => 'archived', 'label' => 'Archived'],
    ];

    private const REMINDER_COOLDOWN_HOURS = 12;

    public function __construct(
        private readonly AnnouncementAudienceResolver $resolver,
        private readonly AnnouncementInboxBridge $bridge,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /* ================================================================== */
    /*  Command-center hub */
    /* ================================================================== */

    public function index(Request $request)
    {
        $user = $request->user();
        $canView = $this->canViewAnnouncements($user);
        abort_unless($canView, 403);

        $canManage = $this->canManageAnnouncements($user);
        $tab = (string) $request->query('tab', 'all');
        $managerTabs = ['tracking', 'scheduled', 'insights'];

        if (! $canManage && in_array($tab, $managerTabs, true)) {
            abort(403);
        }

        $viewerAnnouncementIds = $canManage
            ? null
            : $this->viewerAnnouncementIds($user);

        $filters = [
            'search' => $request->query('search'),
            'priority' => $request->query('priority'),
            'status' => $request->query('status'),
            'audience' => $request->query('audience'),
            'sort' => $request->query('sort', 'newest'),
        ];

        $payload = [
            'tab' => $tab,
            'filters' => $filters,
            'priorities' => self::PRIORITIES,
            'audiences' => self::AUDIENCES,
            'statuses' => self::STATUSES,
            'segments' => $canManage ? $this->segmentOptions() : $this->emptySegments(),
            'summary' => $canManage
                ? $this->heroSummary()
                : $this->viewerSummary($viewerAnnouncementIds ?? [], $user),
            'tabCounts' => $canManage
                ? $this->tabCounts()
                : $this->viewerTabCounts($viewerAnnouncementIds ?? []),
            'can' => ['manage' => $canManage],
        ];

        if ($tab === 'scheduled') {
            $payload['scheduled'] = $this->scheduledList();
        } elseif ($tab === 'tracking') {
            $payload['trackingList'] = $this->trackingList();
            $selectedId = (int) $request->query('announcement', 0);
            if (! $selectedId && ! empty($payload['trackingList'])) {
                $selectedId = (int) $payload['trackingList'][0]['id'];
            }
            if ($selectedId) {
                $selected = HrAnnouncement::query()->with('targets')->find($selectedId);
                if ($selected) {
                    $payload['tracking'] = $this->trackingData($selected);
                }
            }
        } elseif ($tab === 'insights') {
            $payload['insights'] = $this->insights();
        } else {
            $payload['announcements'] = $this->listAnnouncements(
                $request,
                $tab,
                $filters,
                $viewerAnnouncementIds,
            );
        }

        return Inertia::render('hr/announcements/index', $payload);
    }

    /**
     * The create page is retired — announcements are composed from the wizard.
     */
    public function create(Request $request)
    {
        abort_unless($this->canManageAnnouncements($request->user()), 403);

        return redirect()->route('hr.announcements.index');
    }

    /* ================================================================== */
    /*  Create / update / lifecycle */
    /* ================================================================== */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);

        $data = $this->validatePayload($request);
        $announcement = DB::transaction(function () use ($user, $data, $request) {
            $announcement = HrAnnouncement::create([
                'created_by' => $user->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'priority' => $data['priority'],
                'status' => $data['status'],
                'target_audience' => $data['legacy_audience'],
                'target_value' => $data['legacy_value'],
                'published_at' => $data['published_at'],
                'expires_at' => $data['expires_at'],
                'ack_deadline' => $data['ack_deadline'],
                'recurrence' => $data['recurrence'],
                'recurrence_ends_at' => $data['recurrence_ends_at'],
                'is_pinned' => $data['is_pinned'],
                'requires_acknowledgement' => $data['requires_acknowledgement'],
            ]);

            $this->syncTargets($announcement, $data['targets']);
            $this->storeAttachments($announcement, $request, $user->id);

            $this->afterSave($announcement, $user->id, $data['push_to_bell'], wasPublished: false);

            return $announcement;
        });

        return redirect()->back(fallback: route('hr.announcements.index'))
            ->with('success', $this->savedMessage($announcement));
    }

    public function update(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $data = $this->validatePayload($request);
        $wasPublished = $announcement->status === 'published';

        DB::transaction(function () use ($announcement, $data, $request, $user, $wasPublished) {
            $announcement->update([
                'title' => $data['title'],
                'content' => $data['content'],
                'priority' => $data['priority'],
                'status' => $data['status'],
                'target_audience' => $data['legacy_audience'],
                'target_value' => $data['legacy_value'],
                'published_at' => $data['published_at'],
                'expires_at' => $data['expires_at'],
                'ack_deadline' => $data['ack_deadline'],
                'recurrence' => $data['recurrence'],
                'recurrence_ends_at' => $data['recurrence_ends_at'],
                'is_pinned' => $data['is_pinned'],
                'requires_acknowledgement' => $data['requires_acknowledgement'],
            ]);

            $this->syncTargets($announcement, $data['targets']);
            $this->storeAttachments($announcement, $request, $user->id);

            $this->afterSave($announcement, $user->id, $data['push_to_bell'], wasPublished: $wasPublished);
        });

        return redirect()->back(fallback: route('hr.announcements.index'))
            ->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $announcement->update(['status' => 'archived']);
        $this->bridge->withdraw($announcement);

        return redirect()->back()->with('success', 'Announcement archived.');
    }

    public function restore(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $announcement = HrAnnouncement::withTrashed()->findOrFail($id);

        if ($announcement->trashed()) {
            $announcement->restore();
        }

        $announcement->update(['status' => $this->statusFromDates($announcement)]);
        $this->afterSave($announcement->fresh('targets'), $user->id, true, wasPublished: false);

        return redirect()->back()->with('success', 'Announcement restored.');
    }

    public function publishNow(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $wasPublished = $announcement->status === 'published';
        $announcement->update(['status' => 'published', 'published_at' => now()]);
        $this->afterSave($announcement->fresh('targets'), $user->id, true, wasPublished: $wasPublished);

        return redirect()->back()->with('success', 'Announcement published.');
    }

    /* ================================================================== */
    /*  Detail */
    /* ================================================================== */

    public function show(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        $canView = $this->canViewAnnouncements($user);
        abort_unless($canView, 403);

        $this->assertAnnouncementVisibleTo($announcement, $user);
        $canManage = $this->canManageAnnouncements($user);

        $announcement->load([
            'creator:id,name',
            'targets',
            'attachments',
        ]);
        $announcement->load([
            'acknowledgements' => fn ($query) => $query
                ->when(! $canManage, fn ($acks) => $acks->where('user_id', $user->id))
                ->when($canManage, fn ($acks) => $acks->with('user:id,name')),
        ]);

        $userAcknowledged = $announcement->acknowledgements->contains('user_id', $user->id);

        // Reuse the existing polymorphic feed reaction/reply rows so the thread
        // shown here is the SAME data as the Community feed (subject_type=announcement).
        $feed = app(FeedService::class);
        $reactions = $feed->feedReactionSummaries('announcement', [$announcement->id], $user->id)[$announcement->id]
            ?? ['counts' => [], 'mine' => []];
        $replies = $feed->feedReplyThreads('announcement', [$announcement->id])[$announcement->id] ?? [];

        return Inertia::render('hr/announcements/show', [
            'announcement' => $this->detailPayload($announcement, $canManage),
            'tracking' => $canManage ? $this->trackingData($announcement) : null,
            'userAcknowledged' => $userAcknowledged,
            'segments' => $canManage ? $this->segmentOptions() : null,
            'reactions' => $reactions,
            'replies' => $replies,
            'reactionEmojis' => FeedService::REACTION_EMOJIS,
            'can' => [
                'manage' => $canManage,
                'react' => (bool) $user->canDo('hr.recognition.give'),
            ],
        ]);
    }

    /* ================================================================== */
    /*  Acknowledgement & reminders */
    /* ================================================================== */

    public function acknowledge(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canViewAnnouncements($user), 403);
        $this->assertAnnouncementVisibleTo($announcement, $user);

        HrAnnouncementAcknowledgement::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['acknowledged_at' => now()],
        );

        return redirect()->back()->with('success', 'Announcement acknowledged.');
    }

    public function acknowledgeFor(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $announcement = HrAnnouncement::query()->findOrFail($announcement->getKey());
        $recipient = User::query()->find($data['user_id']);
        abort_unless($recipient && $this->resolver->includesCurrentUser($announcement, $recipient), 404);

        // Record the manager override (actor + subject) on the ack row so the
        // roster can show "marked by a manager" and the action is auditable.
        $ack = HrAnnouncementAcknowledgement::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $data['user_id']],
            ['acknowledged_at' => now()],
        );

        if ($ack->wasRecentlyCreated && $ack->isFillable('acknowledged_by')) {
            $ack->update(['acknowledged_by' => $user->id]);
        }

        return redirect()->back()->with('success', 'Marked acknowledged.');
    }

    public function remind(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $data = $request->validate(['user_ids' => ['sometimes', 'array'], 'user_ids.*' => ['integer']]);
        $sent = $this->sendReminders($announcement, $user->id, $data['user_ids'] ?? null);

        return redirect()->back()->with('success', $sent === 0
            ? 'Everyone has already acknowledged — no reminders sent.'
            : "Reminder sent to {$sent} ".($sent === 1 ? 'person' : 'people').'.');
    }

    public function remindBulk(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $data = $request->validate([
            'announcement_ids' => ['required', 'array', 'min:1'],
            'announcement_ids.*' => ['integer'],
        ]);

        $announcements = HrAnnouncement::query()
            ->whereIn('id', $data['announcement_ids'])
            ->with('targets')
            ->get();

        $total = 0;
        foreach ($announcements as $announcement) {
            $total += $this->sendReminders($announcement, $user->id, null);
        }

        return redirect()->back()->with('success', "Reminders sent to {$total} ".($total === 1 ? 'person' : 'people').'.');
    }

    /* ================================================================== */
    /*  Tracking */
    /* ================================================================== */

    public function tracking(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $announcement->load('targets');

        return response()->json($this->trackingData($announcement));
    }

    /* ================================================================== */
    /*  Live recipient preview (wizard) */
    /* ================================================================== */

    public function preview(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        // The debounced GET sends targets either as a nested array or a JSON
        // string (easier to serialise from the client) — accept both.
        $raw = $request->input('targets', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        try {
            $targets = $this->normaliseTargets($raw, strict: true);
        } catch (ValidationException) {
            return response()->json(['count' => 0]);
        }
        $count = $this->resolver->count($targets);

        return response()->json(['count' => $count]);
    }

    /* ================================================================== */
    /*  Bulk */
    /* ================================================================== */

    public function bulk(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:pin,unpin,archive,publish,delete,remind'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $announcements = HrAnnouncement::query()
            ->whereIn('id', $data['ids'])
            ->with('targets')
            ->get();

        $count = 0;
        foreach ($announcements as $announcement) {
            switch ($data['action']) {
                case 'pin':
                    $announcement->update(['is_pinned' => true]);
                    break;
                case 'unpin':
                    $announcement->update(['is_pinned' => false]);
                    break;
                case 'archive':
                    $announcement->update(['status' => 'archived']);
                    $this->bridge->withdraw($announcement);
                    break;
                case 'publish':
                    $wasPublished = $announcement->status === 'published';
                    $announcement->update(['status' => 'published', 'published_at' => $announcement->published_at ?? now()]);
                    $this->afterSave($announcement, $user->id, true, wasPublished: $wasPublished);
                    break;
                case 'delete':
                    $this->bridge->withdraw($announcement);
                    $announcement->delete();
                    break;
                case 'remind':
                    $this->sendReminders($announcement, $user->id, null);
                    break;
            }
            $count++;
        }

        return redirect()->back()->with('success', $this->bulkMessage($data['action'], $count));
    }

    /* ================================================================== */
    /*  Export */
    /* ================================================================== */

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $filters = [
            'search' => $request->query('search'),
            'priority' => $request->query('priority'),
            'status' => $request->query('status'),
            'audience' => $request->query('audience'),
            'sort' => $request->query('sort', 'newest'),
        ];

        $rows = $this->baseListQuery('all', $filters)
            ->withCount('acknowledgements')
            ->with(['creator:id,name', 'targets'])
            ->get();

        $headers = ['Title', 'Priority', 'Status', 'Audience', 'Recipients', 'Acknowledged', 'Published', 'Expires', 'Created by'];
        $records = $rows->map(function (HrAnnouncement $a) {
            $size = $this->resolver->resolveForAnnouncement($a)->count();

            return [
                $a->title,
                ucfirst((string) $a->priority),
                ucfirst((string) $a->status),
                $this->audienceSummary($a),
                (string) $size,
                (string) $a->acknowledgements_count,
                optional($a->published_at)->format('Y-m-d H:i') ?? '',
                optional($a->expires_at)->format('Y-m-d') ?? '',
                $a->creator?->name ?? '',
            ];
        })->all();

        return $this->streamCsv('announcements-'.now()->format('Y-m-d'), $headers, $records);
    }

    public function trackingExport(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($this->canManageAnnouncements($user), 403);
        $announcement->load('targets');
        $roster = $this->rosterRows($announcement);

        $headers = ['Name', 'Role', 'Site', 'Status', 'Acknowledged at'];
        $records = array_map(fn ($r) => [
            $r['name'], $r['role'], $r['site'], ucfirst($r['status']),
            $r['acknowledged_at'] ? Carbon::parse($r['acknowledged_at'])->format('Y-m-d H:i') : '',
        ], $roster);

        $slug = Str::slug($announcement->title) ?: 'announcement';

        return $this->streamCsv("{$slug}-acknowledgements-".now()->format('Y-m-d'), $headers, $records);
    }

    /* ================================================================== */
    /*  Attachments */
    /* ================================================================== */

    public function downloadAttachment(Request $request, HrAnnouncementAttachment $attachment)
    {
        $user = $request->user();
        $canView = $this->canViewAnnouncements($user);
        abort_unless($canView, 403);

        $announcement = $attachment->announcement()->first();
        abort_if(! $announcement, 404);
        $this->assertAnnouncementVisibleTo($announcement, $user);

        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
            $attachment->isImage() ? 'inline' : 'attachment',
        );
    }

    /* ================================================================== */
    /*  Helpers — validation & persistence */
    /* ================================================================== */

    private function canViewAnnouncements(?User $user): bool
    {
        return $user !== null
            && $this->currentStaff->isCurrent($user)
            && ($user->canDo('hr.announcements.view') || $user->canDo('hr.announcements.manage'));
    }

    private function canManageAnnouncements(?User $user): bool
    {
        return $user !== null
            && $this->currentStaff->isCurrent($user)
            && $user->canDo('hr.announcements.manage');
    }

    private function assertAnnouncementVisibleTo(HrAnnouncement $announcement, User $user): void
    {
        if ($this->canManageAnnouncements($user)) {
            return;
        }

        $isLive = $announcement->status === 'published'
            && $announcement->published_at?->lte(now())
            && (! $announcement->expires_at || $announcement->expires_at->isFuture());

        abort_unless($isLive && $this->resolver->includesCurrentUser($announcement, $user), 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
            'intent' => ['sometimes', 'string', 'in:publish,schedule,draft'],
            'targets' => ['sometimes', 'array'],
            'targets.*.type' => ['required_with:targets', 'string', 'in:all,site,department,role,user'],
            'targets.*.value' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'ack_deadline' => ['nullable', 'date'],
            'recurrence' => ['nullable', 'string', 'in:weekly,monthly'],
            'recurrence_ends_at' => ['nullable', 'date'],
            'is_pinned' => ['sometimes', 'boolean'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'push_to_bell' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx'],
        ]);

        $targetsProvided = $request->exists('targets');
        $targets = $this->normaliseTargets($validated['targets'] ?? [], strict: $targetsProvided);
        if (! $targetsProvided && empty($targets)) {
            // Legacy single-segment payload (target_audience + target_value)
            // — keeps the original API shape working alongside multi-segment.
            $legacyType = (string) $request->input('target_audience', '');
            if ($legacyType !== '' && $legacyType !== 'all') {
                $targets = $this->normaliseTargets([
                    ['type' => $legacyType, 'value' => $request->input('target_value')],
                ], strict: true);
            }
        }
        if (empty($targets)) {
            $targets = [['type' => 'all', 'value' => null]];
        }

        $intent = $validated['intent'] ?? 'publish';
        $publishedAt = ! empty($validated['published_at']) ? Carbon::parse($validated['published_at']) : null;

        // Derive lifecycle status from the intent + schedule.
        if ($intent === 'draft') {
            $status = 'draft';
            $publishedAt = $publishedAt; // may be null
        } elseif ($intent === 'schedule' && $publishedAt && $publishedAt->isFuture()) {
            $status = 'scheduled';
        } else {
            $status = 'published';
            $publishedAt = $publishedAt && $publishedAt->isPast() ? $publishedAt : ($publishedAt ?? now());
            if (! $publishedAt) {
                $publishedAt = now();
            }
        }

        // Legacy single-segment columns (first target) kept in sync for fallback.
        $first = $targets[0];

        return [
            'title' => $validated['title'],
            'content' => $this->sanitiseContent($validated['content']),
            'priority' => $validated['priority'],
            'status' => $status,
            'targets' => $targets,
            'legacy_audience' => $first['type'] === 'user' ? 'all' : $first['type'],
            'legacy_value' => $first['type'] === 'all' ? null : $first['value'],
            'published_at' => $publishedAt,
            'expires_at' => ! empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            'ack_deadline' => ! empty($validated['ack_deadline']) ? Carbon::parse($validated['ack_deadline']) : null,
            'recurrence' => $validated['recurrence'] ?? null,
            'recurrence_ends_at' => ! empty($validated['recurrence_ends_at']) ? Carbon::parse($validated['recurrence_ends_at']) : null,
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'requires_acknowledgement' => (bool) ($validated['requires_acknowledgement'] ?? false),
            'push_to_bell' => (bool) ($validated['push_to_bell'] ?? in_array($validated['priority'], ['high', 'urgent'], true)),
        ];
    }

    /**
     * Plain-text content (no HTML) — strips tags to neutralise stored XSS while
     * preserving the author's line breaks. Rendered with whitespace preserved.
     */
    private function sanitiseContent(string $content): string
    {
        return trim(strip_tags($content));
    }

    /**
     * @param  mixed  $raw
     * @return array<int,array{type:string,value:?string}>
     */
    private function normaliseTargets($raw, bool $strict = false): array
    {
        if (! is_array($raw)) {
            if ($strict) {
                throw ValidationException::withMessages(['targets' => 'Choose a valid announcement audience.']);
            }

            return [];
        }

        $out = [];
        foreach ($raw as $target) {
            if (! is_array($target) || empty($target['type'])) {
                if ($strict) {
                    throw ValidationException::withMessages(['targets' => 'Every audience target needs a valid type.']);
                }

                continue;
            }
            $type = trim((string) $target['type']);
            $value = isset($target['value']) && trim((string) $target['value']) !== ''
                ? trim((string) $target['value'])
                : null;

            if (! in_array($type, ['all', 'site', 'department', 'role', 'user'], true)) {
                if ($strict) {
                    throw ValidationException::withMessages(['targets' => 'Choose a supported announcement audience.']);
                }

                continue;
            }

            if ($type === 'all') {
                if (count($raw) !== 1) {
                    throw ValidationException::withMessages(['targets' => 'All staff cannot be combined with another audience.']);
                }

                return [['type' => 'all', 'value' => null]];
            }
            if ($value === null) {
                if ($strict) {
                    throw ValidationException::withMessages(['targets' => 'Every selected audience needs a value.']);
                }

                continue;
            }

            if ($type === 'site' && (! ctype_digit($value) || ! Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->whereKey((int) $value)
                ->exists())) {
                throw ValidationException::withMessages(['targets' => 'The selected Site must be active and available.']);
            }

            if ($type === 'user' && (! ctype_digit($value) || ! $this->currentStaff->isCurrent((int) $value))) {
                throw ValidationException::withMessages(['targets' => 'The selected person must be current approved staff.']);
            }

            $key = $type.'|'.$value;
            $out[$key] = ['type' => $type, 'value' => $value];
        }

        if ($strict && $out === []) {
            throw ValidationException::withMessages(['targets' => 'Choose at least one valid announcement audience.']);
        }

        return array_values($out);
    }

    /**
     * @param  array<int,array{type:string,value:?string}>  $targets
     */
    private function syncTargets(HrAnnouncement $announcement, array $targets): void
    {
        $announcement->targets()->delete();
        foreach ($targets as $target) {
            $announcement->targets()->create([
                'type' => $target['type'],
                'value' => $target['value'],
            ]);
        }
        $announcement->load('targets');
    }

    private function storeAttachments(HrAnnouncement $announcement, Request $request, int $userId): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            if (! $file->isValid()) {
                continue;
            }
            $path = $file->store("hr/announcements/{$announcement->id}", 'private');
            $announcement->attachments()->create([
                'disk' => 'private',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'uploaded_by' => $userId,
            ]);
        }
    }

    /**
     * Fire notifications + the header-bell bridge when an announcement is live.
     * Only notifies on the published transition — never on a plain edit.
     */
    private function afterSave(HrAnnouncement $announcement, int $creatorId, bool $pushToBell, bool $wasPublished): void
    {
        $isPublished = $announcement->status === 'published'
            && $announcement->published_at
            && $announcement->published_at->lte(now());

        if ($isPublished) {
            if ($pushToBell) {
                $this->bridge->publish($announcement->fresh('targets'));
            } else {
                $this->bridge->withdraw($announcement);
            }

            if (! $wasPublished) {
                $notification = new AnnouncementPublishedNotification($announcement->fresh());
                $this->resolver->resolveForAnnouncement($announcement, $creatorId)
                    ->each(fn ($recipient) => $recipient->notify($notification));
            }
        }
    }

    private function statusFromDates(HrAnnouncement $announcement): string
    {
        if (! $announcement->published_at) {
            return 'draft';
        }

        return $announcement->published_at->isFuture() ? 'scheduled' : 'published';
    }

    private function sendReminders(HrAnnouncement $announcement, int $actorId, ?array $userIds): int
    {
        $recipients = $this->resolver->resolveForAnnouncement($announcement);

        $acknowledgedIds = $announcement->acknowledgements()->pluck('user_id')->all();
        $outstanding = $recipients->reject(fn ($u) => in_array($u->id, $acknowledgedIds, true));

        if ($userIds !== null) {
            $outstanding = $outstanding->filter(fn ($u) => in_array($u->id, $userIds, true));
        }

        // Cooldown — skip anyone reminded within the cooldown window.
        $cutoff = now()->subHours(self::REMINDER_COOLDOWN_HOURS);
        $recentlyReminded = HrAnnouncementReminder::where('announcement_id', $announcement->id)
            ->where('reminded_at', '>=', $cutoff)
            ->pluck('user_id')
            ->all();
        $outstanding = $outstanding->reject(fn ($u) => in_array($u->id, $recentlyReminded, true));

        $notification = new AnnouncementReminderNotification($announcement->fresh());
        $sent = 0;
        foreach ($outstanding as $recipient) {
            $recipient->notify($notification);
            HrAnnouncementReminder::create([
                'announcement_id' => $announcement->id,
                'user_id' => $recipient->id,
                'reminded_by' => $actorId,
                'reminded_at' => now(),
            ]);
            $sent++;
        }

        return $sent;
    }

    /* ================================================================== */
    /*  Helpers — lists & aggregates */
    /* ================================================================== */

    private function baseListQuery(string $tab, array $filters, ?array $viewerAnnouncementIds = null)
    {
        $query = HrAnnouncement::query();

        if ($viewerAnnouncementIds !== null) {
            $query->active()->whereKey($viewerAnnouncementIds);
        }

        if ($tab === 'pinned') {
            $query->where('is_pinned', true)->whereIn('status', ['published', 'scheduled']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('content', 'like', $term));
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['audience'])) {
            $query->whereHas('targets', fn ($q) => $q->where('type', $filters['audience']));
        }

        switch ($filters['sort'] ?? 'newest') {
            case 'oldest':
                $query->orderBy('published_at');
                break;
            case 'priority':
                $query->orderByRaw("FIELD(priority,'urgent','high','normal','low')");
                break;
            case 'title':
                $query->orderBy('title');
                break;
            default:
                $query->orderByDesc('is_pinned')->orderByDesc('published_at');
        }

        return $query;
    }

    private function listAnnouncements(
        Request $request,
        string $tab,
        array $filters,
        ?array $viewerAnnouncementIds = null,
    ) {
        $paginator = $this->baseListQuery($tab, $filters, $viewerAnnouncementIds)
            ->withCount(['acknowledgements', 'attachments'])
            ->with(['creator:id,name', 'targets'])
            ->paginate(15)
            ->withQueryString();

        $paginator->through(fn (HrAnnouncement $a) => $this->cardPayload($a));

        return $paginator;
    }

    private function cardPayload(HrAnnouncement $a): array
    {
        $audienceSize = $this->resolver->resolveForAnnouncement($a)->count();
        $reactions = $a->id ? HrFeedReaction::where('subject_type', 'announcement')->where('subject_id', $a->id)->count() : 0;
        $replies = $a->id ? HrFeedReply::where('subject_type', 'announcement')->where('subject_id', $a->id)->count() : 0;

        return [
            'id' => $a->id,
            'title' => $a->title,
            'excerpt' => Str::limit($a->content, 220),
            'priority' => $a->priority,
            'status' => $a->status,
            'is_pinned' => (bool) $a->is_pinned,
            'requires_acknowledgement' => (bool) $a->requires_acknowledgement,
            'audience' => $this->audienceSummary($a),
            'audience_size' => $audienceSize,
            'acknowledged_count' => $a->acknowledgements_count,
            'ack_pct' => $audienceSize > 0 ? (int) round($a->acknowledgements_count / $audienceSize * 100) : 0,
            'attachments_count' => $a->attachments_count,
            'reactions_count' => $reactions,
            'replies_count' => $replies,
            'creator' => $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name] : null,
            'published_at' => optional($a->published_at)->toIso8601String(),
            'expires_at' => optional($a->expires_at)->toIso8601String(),
        ];
    }

    private function scheduledList(): array
    {
        return HrAnnouncement::query()
            ->scheduled()
            ->with('targets')
            ->orderBy('published_at')
            ->get()
            ->map(fn (HrAnnouncement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'status' => $a->status,
                'sends_at' => optional($a->published_at)->toIso8601String(),
                'audience' => $this->audienceSummary($a),
                'recurrence' => $a->recurrence,
                'can_publish' => $a->status === 'scheduled' || ($a->status === 'draft' && $a->published_at !== null),
            ])
            ->all();
    }

    private function trackingList(): array
    {
        return HrAnnouncement::query()
            ->where('requires_acknowledgement', true)
            ->where('status', 'published')
            ->withCount('acknowledgements')
            ->with('targets')
            ->orderByDesc('published_at')
            ->get()
            ->map(function (HrAnnouncement $a) {
                $size = $this->resolver->resolveForAnnouncement($a)->count();

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'priority' => $a->priority,
                    'audience' => $this->audienceSummary($a),
                    'acknowledged_count' => $a->acknowledgements_count,
                    'audience_size' => $size,
                    'ack_pct' => $size > 0 ? (int) round($a->acknowledgements_count / $size * 100) : 0,
                    'ack_deadline' => optional($a->ack_deadline)->toIso8601String(),
                    'published_at' => optional($a->published_at)->toIso8601String(),
                ];
            })
            ->all();
    }

    private function trackingData(HrAnnouncement $announcement): array
    {
        $roster = $this->rosterRows($announcement);
        $total = count($roster);
        $acknowledged = count(array_filter($roster, fn ($r) => $r['status'] === 'acknowledged'));

        $bySite = $this->breakdown($roster, 'site');
        $byRole = $this->breakdown($roster, 'role');

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'priority' => $announcement->priority,
            'audience' => $this->audienceSummary($announcement),
            'ack_deadline' => optional($announcement->ack_deadline)->toIso8601String(),
            'published_at' => optional($announcement->published_at)->toIso8601String(),
            'total' => $total,
            'acknowledged' => $acknowledged,
            'outstanding' => $total - $acknowledged,
            'ack_pct' => $total > 0 ? (int) round($acknowledged / $total * 100) : 0,
            'by_site' => $bySite,
            'by_role' => $byRole,
            'roster' => $roster,
        ];
    }

    /**
     * The roster = expected audience ∪ acknowledgers, each tagged
     * acknowledged | reminded | outstanding.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rosterRows(HrAnnouncement $announcement): array
    {
        $recipients = $this->resolver->resolveForAnnouncement($announcement);

        $profiles = HrEmployeeProfile::query()
            ->whereIn('user_id', $recipients->pluck('id'))
            ->with('primarySite:id,name')
            ->get()
            ->keyBy('user_id');

        $acks = $announcement->acknowledgements()->get()->keyBy('user_id');
        $remindedIds = $announcement->reminders()->pluck('user_id')->unique()->all();

        return $recipients->map(function ($user) use ($profiles, $acks, $remindedIds) {
            $profile = $profiles->get($user->id);
            $ack = $acks->get($user->id);
            $status = $ack ? 'acknowledged' : (in_array($user->id, $remindedIds, true) ? 'reminded' : 'outstanding');

            return [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $profile?->position_title ?: ($profile?->position_role ?: ucfirst((string) ($user->role ?? 'Staff'))),
                'site' => $profile?->primarySite?->name ?? '—',
                'status' => $status,
                'acknowledged_at' => optional($ack?->acknowledged_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $roster
     * @return array<int,array{name:string,pct:int}>
     */
    private function breakdown(array $roster, string $key): array
    {
        $groups = [];
        foreach ($roster as $row) {
            $name = $row[$key] ?: '—';
            $groups[$name] ??= ['total' => 0, 'ack' => 0];
            $groups[$name]['total']++;
            if ($row['status'] === 'acknowledged') {
                $groups[$name]['ack']++;
            }
        }

        $out = [];
        foreach ($groups as $name => $g) {
            $out[] = [
                'name' => $name,
                'pct' => $g['total'] > 0 ? (int) round($g['ack'] / $g['total'] * 100) : 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return $out;
    }

    private function heroSummary(): array
    {
        $live = HrAnnouncement::query()->published()->count();
        $pinned = HrAnnouncement::query()->where('is_pinned', true)->whereIn('status', ['published', 'scheduled'])->count();
        $scheduled = HrAnnouncement::query()->scheduled()->count();

        $requiresAck = HrAnnouncement::query()
            ->where('requires_acknowledgement', true)
            ->where('status', 'published')
            ->withCount('acknowledgements')
            ->with('targets')
            ->get();

        $totalRecipients = 0;
        $totalAck = 0;
        $belowTarget = 0;
        $needsYou = [];

        foreach ($requiresAck as $a) {
            $size = $this->resolver->resolveForAnnouncement($a)->count();
            $totalRecipients += $size;
            $totalAck += $a->acknowledgements_count;
            $pct = $size > 0 ? (int) round($a->acknowledgements_count / $size * 100) : 0;
            if ($pct < 70) {
                $belowTarget++;
                if (count($needsYou) < 3) {
                    $needsYou[] = [
                        'type' => 'below_target',
                        'announcement_id' => $a->id,
                        'label' => Str::limit($a->title, 32)." at {$pct}%",
                    ];
                }
            }
        }

        $outstanding = max(0, $totalRecipients - $totalAck);
        $ackPct = $totalRecipients > 0 ? (int) round($totalAck / $totalRecipients * 100) : 0;

        if ($outstanding > 0) {
            array_unshift($needsYou, [
                'type' => 'remind',
                'announcement_id' => null,
                'label' => "{$outstanding} staff to remind",
            ]);
        }

        $soon = HrAnnouncement::query()
            ->where('status', 'scheduled')
            ->whereBetween('published_at', [now(), now()->addDays(7)])
            ->count();
        if ($soon > 0) {
            $needsYou[] = [
                'type' => 'scheduled_soon',
                'announcement_id' => null,
                'label' => "{$soon} scheduled in next 7 days",
            ];
        }

        return [
            'live' => $live,
            'pinned' => $pinned,
            'scheduled' => $scheduled,
            'requires_ack' => $requiresAck->count(),
            'requires_ack_pct' => $ackPct,
            'outstanding_reminders' => $outstanding,
            'ack_health' => [
                'pct' => $ackPct,
                'acknowledged' => $totalAck,
                'outstanding' => $outstanding,
                'required_notices' => $requiresAck->count(),
                'below_target' => $belowTarget,
                'scheduled_soon' => $soon,
            ],
            'needs_you' => array_slice($needsYou, 0, 4),
        ];
    }

    /** @return array<int> */
    private function viewerAnnouncementIds(User $user): array
    {
        return HrAnnouncement::query()
            ->active()
            ->with('targets')
            ->get()
            ->filter(fn (HrAnnouncement $announcement) => $this->resolver->includesCurrentUser($announcement, $user))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function viewerSummary(array $announcementIds, User $user): array
    {
        $announcements = HrAnnouncement::query()
            ->whereKey($announcementIds)
            ->get(['id', 'is_pinned', 'requires_acknowledgement']);

        $requiredIds = $announcements
            ->where('requires_acknowledgement', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $acknowledged = HrAnnouncementAcknowledgement::query()
            ->where('user_id', $user->id)
            ->whereIn('announcement_id', $requiredIds)
            ->count();
        $required = $requiredIds->count();
        $outstanding = max(0, $required - $acknowledged);
        $ackPct = $required > 0 ? (int) round($acknowledged / $required * 100) : 100;

        return [
            'live' => $announcements->count(),
            'pinned' => $announcements->where('is_pinned', true)->count(),
            'scheduled' => 0,
            'requires_ack' => $required,
            'requires_ack_pct' => $ackPct,
            'outstanding_reminders' => $outstanding,
            'ack_health' => [
                'pct' => $ackPct,
                'acknowledged' => $acknowledged,
                'outstanding' => $outstanding,
                'required_notices' => $required,
                'below_target' => 0,
                'scheduled_soon' => 0,
            ],
            'needs_you' => [],
        ];
    }

    private function viewerTabCounts(array $announcementIds): array
    {
        $announcements = HrAnnouncement::query()
            ->whereKey($announcementIds)
            ->get(['id', 'is_pinned']);

        return [
            'all' => $announcements->count(),
            'pinned' => $announcements->where('is_pinned', true)->count(),
            'tracking' => 0,
            'scheduled' => 0,
        ];
    }

    private function emptySegments(): array
    {
        return [
            'all_count' => 0,
            'sites' => [],
            'departments' => [],
            'roles' => [],
        ];
    }

    private function tabCounts(): array
    {
        return [
            'all' => HrAnnouncement::query()->count(),
            'pinned' => HrAnnouncement::query()->where('is_pinned', true)->whereIn('status', ['published', 'scheduled'])->count(),
            'tracking' => HrAnnouncement::query()->where('requires_acknowledgement', true)->where('status', 'published')->count(),
            'scheduled' => HrAnnouncement::query()->scheduled()->count(),
        ];
    }

    private function insights(): array
    {
        $notices = HrAnnouncement::query()
            ->where('requires_acknowledgement', true)
            ->where('status', 'published')
            ->withCount('acknowledgements')
            ->with(['targets', 'acknowledgements'])
            ->orderByDesc('published_at')
            ->limit(8)
            ->get();

        $trend = [];
        $topUnack = [];
        $totalAckMinutes = 0;
        $ackSamples = 0;

        foreach ($notices as $a) {
            $size = $this->resolver->resolveForAnnouncement($a)->count();
            $pct = $size > 0 ? (int) round($a->acknowledgements_count / $size * 100) : 0;
            $trend[] = [
                'label' => optional($a->published_at)->format('j M') ?? '—',
                'pct' => $pct,
            ];

            $outstanding = max(0, $size - $a->acknowledgements_count);
            if ($outstanding > 0) {
                $topUnack[] = [
                    'id' => $a->id,
                    'title' => $a->title,
                    'outstanding' => $outstanding,
                ];
            }

            foreach ($a->acknowledgements as $ack) {
                if ($a->published_at && $ack->acknowledged_at) {
                    $totalAckMinutes += $a->published_at->diffInMinutes($ack->acknowledged_at);
                    $ackSamples++;
                }
            }
        }

        usort($topUnack, fn ($x, $y) => $y['outstanding'] <=> $x['outstanding']);

        $avgAckRate = count($trend) > 0 ? (int) round(collect($trend)->avg('pct')) : 0;
        $avgTimeHours = $ackSamples > 0 ? round($totalAckMinutes / $ackSamples / 60, 1) : 0;
        $remindersSent = HrAnnouncementReminder::query()
            ->where('reminded_at', '>=', now()->subDays(30))
            ->count();

        return [
            'kpis' => [
                'avg_ack_rate' => $avgAckRate,
                'avg_time_to_ack_hours' => $avgTimeHours,
                'reminders_30d' => $remindersSent,
                'outstanding' => array_sum(array_column($topUnack, 'outstanding')),
            ],
            'trend' => array_reverse($trend),
            'top_unacknowledged' => array_slice($topUnack, 0, 5),
        ];
    }

    private function detailPayload(HrAnnouncement $a, bool $includeAcknowledgements): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'content' => $a->content,
            'priority' => $a->priority,
            'status' => $a->status,
            'is_pinned' => (bool) $a->is_pinned,
            'requires_acknowledgement' => (bool) $a->requires_acknowledgement,
            'audience' => $this->audienceSummary($a),
            'audience_size' => $this->resolver->resolveForAnnouncement($a)->count(),
            'targets' => ($a->relationLoaded('targets') ? $a->targets : $a->targets()->get())
                ->map(fn ($t) => ['type' => $t->type, 'value' => $t->value])->all(),
            'recurrence' => $a->recurrence,
            'recurrence_ends_at' => optional($a->recurrence_ends_at)->toIso8601String(),
            'published_at' => optional($a->published_at)->toIso8601String(),
            'expires_at' => optional($a->expires_at)->toIso8601String(),
            'ack_deadline' => optional($a->ack_deadline)->toIso8601String(),
            'creator' => $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name] : null,
            'acknowledgements' => $includeAcknowledgements
                ? $a->acknowledgements->map(fn ($ack) => [
                    'user' => $ack->user ? ['id' => $ack->user->id, 'name' => $ack->user->name] : null,
                    'acknowledged_at' => optional($ack->acknowledged_at)->toIso8601String(),
                ])->all()
                : [],
            'attachments' => $a->attachments->map(fn ($att) => [
                'id' => $att->id,
                'name' => $att->original_name,
                'size' => $att->size,
                'is_image' => $att->isImage(),
                'url' => route('hr.announcements.attachments.show', $att->id),
            ])->all(),
        ];
    }

    private function audienceSummary(HrAnnouncement $a): string
    {
        $targets = $a->relationLoaded('targets') ? $a->targets : $a->targets()->get();

        if ($targets->isEmpty()) {
            $type = $a->target_audience ?: 'all';
            if ($type === 'all') {
                return 'All staff';
            }

            return ucfirst($type).($a->target_value ? ": {$a->target_value}" : '');
        }

        if ($targets->contains(fn ($t) => $t->type === 'all')) {
            return 'All staff';
        }

        $byType = $targets->groupBy('type');
        $parts = [];
        foreach (['role' => 'role', 'department' => 'dept', 'site' => 'site', 'user' => 'person'] as $type => $noun) {
            $group = $byType->get($type);
            if ($group && $group->count() > 0) {
                $n = $group->count();
                $labels = $group->take(2)->map(fn ($t) => $this->labelForTarget($t->type, $t->value))->filter()->all();
                $label = implode(', ', $labels);
                if ($n > 2) {
                    $label .= ' +'.($n - 2);
                }
                $parts[] = $label;
            }
        }

        return $parts ? implode(' · ', $parts) : 'Targeted';
    }

    private function labelForTarget(string $type, ?string $value): string
    {
        if ($value === null || $value === '') {
            return ucfirst($type);
        }
        if ($type === 'site' && is_numeric($value)) {
            return Site::find((int) $value)?->name ?? "Site #{$value}";
        }

        return $value;
    }

    /**
     * Sites / departments / roles with active-headcount, for the wizard targeting.
     */
    private function segmentOptions(): array
    {
        $profiles = HrEmployeeProfile::query()
            ->whereIn('user_id', $this->currentStaff->currentUsersQuery()->select('users.id'))
            ->get();

        $sites = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($site) use ($profiles) {
                $count = $profiles->filter(function ($p) use ($site) {
                    $secondary = is_array($p->secondary_site_ids) ? $p->secondary_site_ids : [];

                    return (int) $p->primary_site_id === (int) $site->id || in_array((int) $site->id, array_map('intval', $secondary), true);
                })->count();

                return ['key' => (string) $site->id, 'label' => $site->name, 'count' => $count];
            })->all();

        $departments = $profiles->whereNotNull('department')->groupBy('department')
            ->map(fn ($g, $dept) => ['key' => (string) $dept, 'label' => (string) $dept, 'count' => $g->count()])
            ->values()->all();

        $roles = $profiles->map(fn ($p) => $p->position_role ?: $p->position_title)->filter()->countBy()
            ->map(fn ($count, $role) => ['key' => (string) $role, 'label' => (string) $role, 'count' => $count])
            ->values()->all();

        return [
            'all_count' => $profiles->count(),
            'sites' => $sites,
            'departments' => $departments,
            'roles' => $roles,
        ];
    }

    /* ================================================================== */
    /*  Helpers — messaging & CSV */
    /* ================================================================== */

    private function savedMessage(HrAnnouncement $a): string
    {
        return match ($a->status) {
            'draft' => 'Draft saved.',
            'scheduled' => 'Announcement scheduled.',
            default => 'Announcement published.',
        };
    }

    private function bulkMessage(string $action, int $count): string
    {
        $noun = $count === 1 ? 'announcement' : 'announcements';

        return match ($action) {
            'pin' => "Pinned {$count} {$noun}.",
            'unpin' => "Unpinned {$count} {$noun}.",
            'archive' => "Archived {$count} {$noun}.",
            'publish' => "Published {$count} {$noun}.",
            'delete' => "Deleted {$count} {$noun}.",
            'remind' => "Reminders sent for {$count} {$noun}.",
            default => "Updated {$count} {$noun}.",
        };
    }

    /**
     * @param  array<int,string>  $headers
     * @param  array<int,array<int,string>>  $records
     */
    private function streamCsv(string $filename, array $headers, array $records)
    {
        return response()->streamDownload(function () use ($headers, $records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($records as $rec) {
                fputcsv($out, array_map(fn ($c) => $this->csvCell((string) $c), $rec));
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function csvCell(?string $value): string
    {
        $v = (string) $value;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
