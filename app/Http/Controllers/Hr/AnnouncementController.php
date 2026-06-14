<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    use ResolvesHrTenant;

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

    /**
     * List announcements (feed view).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.announcements.view') || $user->canDo('hr.announcements.manage'));
        abort_unless($canView, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $announcements = HrAnnouncement::forTenant($tenantId)
            ->published()
            ->with(['creator:id,name'])
            ->withCount('acknowledgements')
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(20)
            ->withQueryString();

        // Check which announcements the current user has acknowledged
        $acknowledgedIds = HrAnnouncementAcknowledgement::where('user_id', $user->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('announcement_id')
            ->toArray();

        return Inertia::render('hr/announcements/index', [
            'announcements' => $announcements,
            'acknowledgedIds' => $acknowledgedIds,
            'priorities' => self::PRIORITIES,
            'audiences' => self::AUDIENCES,
            'filters' => [
                'priority' => $request->query('priority'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.announcements.manage'),
            ],
        ]);
    }

    /**
     * The create page is retired — announcements are created from the index
     * modal. Route preserved as a redirect for any bookmarks.
     */
    public function create(Request $request)
    {
        return redirect()->route('hr.announcements.index');
    }

    /**
     * Store a new announcement.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.announcements.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
            'target_audience' => ['required', 'string', 'in:all,department,site,role'],
            'target_value' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_pinned' => ['sometimes', 'boolean'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
        ]);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        DB::transaction(function () use ($user, $data, $tenantId) {
            $announcement = HrAnnouncement::create([
                'tenant_id' => $tenantId,
                'created_by' => $user->id,
                'published_at' => $data['published_at'] ?? now(),
                ...$data,
            ]);

            if ($announcement->published_at && $announcement->published_at->lte(now())) {
                $notification = new AnnouncementPublishedNotification($announcement->fresh());
                $this->announcementRecipients($announcement, $tenantId, $user->id)
                    ->each(fn ($recipient) => $recipient->notify($notification));
            }
        });

        return redirect()->route('hr.announcements.index')->with('success', 'Announcement published.');
    }

    /**
     * Show a single announcement with acknowledgement details.
     */
    public function show(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.announcements.view') || $user->canDo('hr.announcements.manage'));
        abort_unless($canView, 403);

        $announcement->load([
            'creator:id,name',
            'acknowledgements.user:id,name',
        ]);

        $userAcknowledged = $announcement->acknowledgements
            ->contains('user_id', $user->id);

        return Inertia::render('hr/announcements/show', [
            'announcement' => $announcement,
            'userAcknowledged' => $userAcknowledged,
            'can' => [
                'manage' => $user->canDo('hr.announcements.manage'),
            ],
        ]);
    }

    /**
     * Acknowledge an announcement.
     */
    public function acknowledge(Request $request, HrAnnouncement $announcement)
    {
        $user = $request->user();
        abort_unless($user, 403);

        HrAnnouncementAcknowledgement::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
            ],
            [
                'acknowledged_at' => now(),
            ],
        );

        return redirect()->back()->with('success', 'Announcement acknowledged.');
    }

    private function announcementRecipients(HrAnnouncement $announcement, int $tenantId, int $creatorId): Collection
    {
        $targetValue = trim((string) $announcement->target_value);

        $profiles = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $creatorId)
            ->with('user.roles:id,name')
            ->when($announcement->target_audience === 'department' && $targetValue !== '', function ($query) use ($targetValue) {
                $query->where('department', $targetValue);
            })
            ->when($announcement->target_audience === 'site' && $targetValue !== '', function ($query) use ($targetValue) {
                $query->where(function ($siteQuery) use ($targetValue) {
                    if (is_numeric($targetValue)) {
                        $siteQuery->where('primary_site_id', (int) $targetValue)
                            ->orWhereJsonContains('secondary_site_ids', (int) $targetValue);
                    } else {
                        $siteQuery->whereRaw('1 = 0');
                    }
                });
            })
            ->when($announcement->target_audience === 'role' && $targetValue !== '', function ($query) use ($targetValue) {
                $query->where(function ($roleQuery) use ($targetValue) {
                    $roleQuery->where('position_role', $targetValue)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('role', $targetValue))
                        ->orWhereHas('user.roles', fn ($rolePivotQuery) => $rolePivotQuery->where('name', $targetValue));
                });
            })
            ->get();

        return $profiles
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();
    }
}
