<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    /**
     * List announcements (feed view).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.announcements.view') || $user->canDo('hr.announcements.manage'));
        abort_unless($canView, 403);

        $announcements = HrAnnouncement::forTenant($user->tenant_id)
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
            'filters' => [
                'priority' => $request->query('priority'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.announcements.manage'),
            ],
        ]);
    }

    /**
     * Show form to create an announcement.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.announcements.manage'), 403);

        return Inertia::render('hr/announcements/create', [
            'priorities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'high', 'label' => 'High'],
                ['value' => 'urgent', 'label' => 'Urgent'],
            ],
            'audiences' => [
                ['value' => 'all', 'label' => 'All Staff'],
                ['value' => 'department', 'label' => 'Department'],
                ['value' => 'site', 'label' => 'Site'],
                ['value' => 'role', 'label' => 'Role'],
            ],
        ]);
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

        DB::transaction(function () use ($user, $data) {
            HrAnnouncement::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'published_at' => $data['published_at'] ?? now(),
                ...$data,
            ]);
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
}
