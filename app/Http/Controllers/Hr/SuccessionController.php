<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\HrSuccessionAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SuccessionController extends Controller
{
    public function __construct(
        private readonly HrSuccessionAccessService $access,
        private readonly HrPerformanceAccessService $performanceAccess,
    ) {}

    /**
     * List succession plans with risk level badges, current holder, candidate count.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $filters = $request->validate([
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'department' => ['nullable', 'string', 'max:255'],
            'active_only' => ['nullable', 'boolean'],
            'new' => ['nullable', 'boolean'],
            'source_review_id' => ['nullable', 'integer'],
        ]);

        $plans = $this->access->visiblePlans($user)
            ->with(['position:id,title,department', 'site:id,name'])
            ->withCount('candidates')
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->where('risk_level', $v))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('role_title')
            ->paginate(20)
            ->withQueryString();

        $planIds = $plans->getCollection()->pluck('id');
        $currentHolders = $this->currentHolders(
            $user,
            $plans->getCollection()->pluck('current_holder_user_id')->filter()->all(),
        );
        $readinessSummary = HrSuccessionCandidate::query()
            ->whereIn('succession_plan_id', $planIds)
            ->select('succession_plan_id', 'readiness', DB::raw('COUNT(*) as count'))
            ->groupBy('succession_plan_id', 'readiness')
            ->get()
            ->groupBy('succession_plan_id')
            ->map(fn ($items) => $items->pluck('count', 'readiness')->toArray());

        $plans->through(function (HrSuccessionPlan $plan) use ($currentHolders): array {
            $holder = $this->holderForPlan($plan, $currentHolders);

            return [
                'id' => $plan->id,
                'role_title' => $plan->role_title,
                'department' => $plan->department,
                'risk_level' => $plan->risk_level,
                'site' => $plan->site?->only('id', 'name'),
                'current_holder_name' => $holder?->name,
                'current_holder' => $holder?->only('id', 'name'),
                'position' => $plan->position?->only('id', 'title'),
                'notes' => $plan->notes,
                'candidates_count' => $plan->candidates_count,
                'is_active' => $plan->is_active,
                'created_at' => $plan->created_at?->toDateTimeString(),
            ];
        });

        $departments = $this->access->visiblePlans($user)
            ->distinct()
            ->whereNotNull('department')
            ->orderBy('department')
            ->pluck('department');

        $canManage = $this->canManage($user);
        $activePlans = $this->access->visiblePlans($user)
            ->active()
            ->get(['id', 'site_id', 'risk_level', 'current_holder_user_id']);
        $activeHolders = $this->currentHolders(
            $user,
            $activePlans->pluck('current_holder_user_id')->filter()->all(),
        );
        $accessibleSiteIds = $this->access->accessibleSiteIds($user);

        return Inertia::render('hr/succession/index', [
            'plans' => $plans,
            'readinessSummary' => $readinessSummary,
            'departments' => $departments,
            'filters' => collect($filters)->only(['risk_level', 'department', 'active_only'])->all(),
            'stats' => [
                'total' => $activePlans->count(),
                'high_risk' => $activePlans->whereIn('risk_level', ['high', 'critical'])->count(),
                'vacant' => $activePlans
                    ->filter(fn (HrSuccessionPlan $plan): bool => $this->holderForPlan($plan, $activeHolders) === null)
                    ->count(),
                'ready_now' => HrSuccessionCandidate::query()
                    ->where('readiness', 'ready_now')
                    ->whereIn('succession_plan_id', $activePlans->pluck('id'))
                    ->count(),
            ],
            // Wizard option lists — only fetched for users who can open the wizard.
            'positions' => $canManage
                ? HrPosition::query()->active()->orderBy('title')->limit(500)->get(['id', 'title', 'department'])
                : [],
            'holders' => $canManage
                ? $this->holderOptions($user, $accessibleSiteIds)
                : [],
            'sites' => $canManage
                ? $this->access->visibleSites($user)->orderBy('name')->get(['id', 'name'])
                : [],
            'prefill' => $canManage
                ? $this->sourceReviewPrefill($user, $filters['source_review_id'] ?? null)
                : null,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * The full-page create form was folded into a WizardShell modal on the
     * index — keep the GET route alive for bookmarks and route() helpers.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.succession.index', ['new' => 1]);
    }

    /**
     * Save plan + candidates.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'site_id' => ['required', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'role_title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'current_holder_user_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'candidates' => ['nullable', 'array', 'max:100'],
            'candidates.*.employee_profile_id' => ['required', 'integer', 'distinct'],
            'candidates.*.readiness' => ['required', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'candidates.*.strengths' => ['nullable', 'string', 'max:2000'],
            'candidates.*.development_needs' => ['nullable', 'string', 'max:2000'],
            'candidates.*.overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source_review_id' => ['nullable', 'integer'],
            'stay' => ['nullable', 'boolean'],
        ]);

        $stay = (bool) ($data['stay'] ?? false);

        DB::transaction(function () use ($user, $data): void {
            $actor = $this->lockedManager($user);
            $freshAccess = new HrSuccessionAccessService(new UserSiteAccessService);
            $site = $freshAccess->site($actor, (int) $data['site_id'], lockForUpdate: true);
            $position = $this->activePosition($data['position_id'] ?? null, lockForUpdate: true);
            $holder = isset($data['current_holder_user_id'])
                ? $freshAccess->currentUserAtSite(
                    $actor,
                    (int) $site->id,
                    (int) $data['current_holder_user_id'],
                )
                : null;
            $candidateProfiles = $this->lockCandidateProfiles(
                $freshAccess,
                $actor,
                (int) $site->id,
                $data['candidates'] ?? [],
            );
            $notes = $this->notesWithSourceReview(
                $actor,
                (int) $site->id,
                $candidateProfiles,
                $data['source_review_id'] ?? null,
                $data['notes'] ?? null,
            );
            $this->assertIdentityAvailable(
                (int) $site->id,
                $position?->id,
                $data['role_title'],
                true,
            );

            $plan = HrSuccessionPlan::query()->create([
                'site_id' => $site->id,
                'position_id' => $data['position_id'] ?? null,
                'role_title' => $data['role_title'],
                'department' => $data['department'] ?? null,
                'risk_level' => $data['risk_level'],
                'current_holder_user_id' => $holder?->id,
                'notes' => $notes,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            foreach ($data['candidates'] ?? [] as $candidate) {
                $plan->candidates()->create([
                    'employee_profile_id' => $candidate['employee_profile_id'],
                    'readiness' => $candidate['readiness'],
                    'strengths' => $candidate['strengths'] ?? null,
                    'development_needs' => $candidate['development_needs'] ?? null,
                    'overall_rating' => $candidate['overall_rating'] ?? null,
                    'assessed_by' => $actor->id,
                    'assessed_at' => now()->toDateString(),
                ]);
            }
        }, attempts: 1);

        if ($stay) {
            return redirect()->back()->with('success', 'Succession plan created.');
        }

        return redirect()->route('hr.succession.index')->with('success', 'Succession plan created.');
    }

    /**
     * Plan detail with candidate readiness matrix.
     */
    public function show(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $plan = $this->access->plan($user, $plan);
        $plan->load([
            'site:id,name',
            'position:id,title,department',
            'creator:id,name',
            'candidates.employeeProfile' => fn ($query) => $query->withTrashed(),
            'candidates.employeeProfile.user:id,name,email',
            'candidates.assessor:id,name',
        ]);

        $employees = $this->access->currentProfilesAtSite($user, (int) $plan->site_id)
            ->with('user:id,name,email')
            ->orderBy('user_id')
            ->limit(500)
            ->get(['id', 'user_id', 'position_title', 'department', 'primary_site_id', 'secondary_site_ids']);
        $currentProfileIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $holder = $this->access->currentHolder($user, $plan);

        $canManage = $this->canManage($user);

        return Inertia::render('hr/succession/show', [
            'plan' => [
                'id' => $plan->id,
                'role_title' => $plan->role_title,
                'department' => $plan->department,
                'risk_level' => $plan->risk_level,
                'site' => $plan->site?->only('id', 'name'),
                'notes' => $plan->notes,
                'is_active' => $plan->is_active,
                'current_holder_name' => $holder?->name,
                'current_holder' => $holder?->only('id', 'name', 'email'),
                'position' => $plan->position?->only('id', 'title', 'department'),
                'creator' => $plan->creator?->only('id', 'name'),
                'created_at' => $plan->created_at?->toDateTimeString(),
                'candidates' => $plan->candidates->map(fn ($c) => [
                    'id' => $c->id,
                    'readiness' => $c->readiness,
                    'development_needs' => $c->development_needs,
                    'strengths' => $c->strengths,
                    'overall_rating' => $c->overall_rating,
                    'assessed_at' => $c->assessed_at?->toDateString(),
                    'can_mutate' => in_array((int) $c->employee_profile_id, $currentProfileIds, true),
                    'assessor' => $c->assessor?->only('id', 'name'),
                    'employee' => $c->employeeProfile ? [
                        'id' => $c->employeeProfile->id,
                        'name' => $c->employeeProfile->user?->name,
                        'email' => $c->employeeProfile->user?->email,
                        'position_title' => $c->employeeProfile->position_title,
                        'department' => $c->employeeProfile->department,
                    ] : null,
                ]),
            ],
            'employees' => $employees,
            // Edit-plan wizard option lists.
            'positions' => $canManage
                ? HrPosition::query()->active()->orderBy('title')->limit(500)->get(['id', 'title', 'department'])
                : [],
            'holders' => $canManage
                ? $this->holderOptions(
                    $user,
                    [(int) $plan->site_id],
                    exactSiteId: (int) $plan->site_id,
                )
                : [],
            'sites' => $canManage && $plan->site
                ? [$plan->site->only('id', 'name')]
                : [],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * Update plan.
     */
    public function update(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $plan = $this->access->plan($user, $plan);
        $data = $request->validate([
            'site_id' => ['sometimes', 'integer', Rule::in([(int) $plan->site_id])],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'role_title' => ['sometimes', 'required', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'current_holder_user_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $plan, $user): void {
            $actor = $this->lockedManager($user);
            $freshAccess = new HrSuccessionAccessService(new UserSiteAccessService);
            $site = $freshAccess->site($actor, (int) $plan->site_id, lockForUpdate: true);
            $lockedPlan = $freshAccess->plan($actor, (int) $plan->id, lockForUpdate: true);
            $positionId = array_key_exists('position_id', $data)
                ? $this->activePosition($data['position_id'], lockForUpdate: true)?->id
                : $lockedPlan->position_id;
            $holderId = $lockedPlan->current_holder_user_id;
            if (array_key_exists('current_holder_user_id', $data)) {
                $holderId = $data['current_holder_user_id'] === null
                    ? null
                    : $freshAccess->currentUserAtSite(
                        $actor,
                        (int) $site->id,
                        (int) $data['current_holder_user_id'],
                    )->id;
            }
            $roleTitle = $data['role_title'] ?? $lockedPlan->role_title;
            $isActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $lockedPlan->is_active;
            $this->assertIdentityAvailable(
                (int) $site->id,
                $positionId,
                $roleTitle,
                $isActive,
                (int) $lockedPlan->id,
            );

            $lockedPlan->update([
                ...collect($data)->except(['site_id'])->all(),
                'position_id' => $positionId,
                'current_holder_user_id' => $holderId,
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Succession plan updated.');
    }

    /**
     * Add candidate to plan.
     */
    public function addCandidate(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'readiness' => ['required', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'development_needs' => ['nullable', 'string', 'max:2000'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $plan = $this->access->plan($user, $plan);
        DB::transaction(function () use ($data, $plan, $user): void {
            $actor = $this->lockedManager($user);
            $freshAccess = new HrSuccessionAccessService(new UserSiteAccessService);
            $lockedPlan = $freshAccess->plan($actor, (int) $plan->id, lockForUpdate: true);
            $this->assertPlanIsActive($lockedPlan);
            $profile = $freshAccess->currentProfileAtSite(
                $actor,
                (int) $lockedPlan->site_id,
                (int) $data['employee_profile_id'],
                lockForUpdate: true,
            );
            if ($lockedPlan->candidates()->where('employee_profile_id', $profile->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'employee_profile_id' => 'This person is already a candidate for the plan.',
                ]);
            }

            $lockedPlan->candidates()->create([
                'employee_profile_id' => $profile->id,
                'readiness' => $data['readiness'],
                'strengths' => $data['strengths'] ?? null,
                'development_needs' => $data['development_needs'] ?? null,
                'overall_rating' => $data['overall_rating'] ?? null,
                'assessed_by' => $actor->id,
                'assessed_at' => now()->toDateString(),
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Candidate added to succession plan.');
    }

    /**
     * Update readiness/rating of a candidate.
     */
    public function updateCandidate(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'readiness' => ['sometimes', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'development_needs' => ['nullable', 'string', 'max:2000'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $candidate = $this->access->candidate($user, $candidate);
        DB::transaction(function () use ($candidate, $data, $user): void {
            [$actor, $lockedPlan, $lockedCandidate, $freshAccess] = $this->lockCandidateMutation($user, $candidate);
            $this->assertPlanIsActive($lockedPlan);
            $freshAccess->currentProfileAtSite(
                $actor,
                (int) $lockedPlan->site_id,
                (int) $lockedCandidate->employee_profile_id,
                lockForUpdate: true,
            );
            $lockedCandidate->update([
                ...$data,
                'assessed_by' => $actor->id,
                'assessed_at' => now()->toDateString(),
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Candidate updated.');
    }

    /**
     * Archive a succession plan while retaining its candidate history.
     */
    public function destroy(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $plan = $this->access->plan($user, $plan);
        DB::transaction(function () use ($plan, $user): void {
            $actor = $this->lockedManager($user);
            $freshAccess = new HrSuccessionAccessService(new UserSiteAccessService);
            $lockedPlan = $freshAccess->plan($actor, (int) $plan->id, lockForUpdate: true);
            $lockedPlan->update(['is_active' => false]);
        }, attempts: 1);

        return redirect()->route('hr.succession.index')->with('success', 'Succession plan archived.');
    }

    /**
     * Remove a candidate from a plan.
     */
    public function removeCandidate(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $candidate = $this->access->candidate($user, $candidate);
        DB::transaction(function () use ($candidate, $user): void {
            [, $lockedPlan, $lockedCandidate] = $this->lockCandidateMutation($user, $candidate);
            $this->assertPlanIsActive($lockedPlan);
            $lockedCandidate->delete();
        }, attempts: 1);

        return redirect()->back()->with('success', 'Candidate removed from plan.');
    }

    /**
     * Nominate a candidate to the ready-now talent bench. Promotes their
     * readiness so they surface in the "Ready now" pipeline across plans.
     */
    public function nominateToTalentPool(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $candidate = $this->access->candidate($user, $candidate);
        DB::transaction(function () use ($candidate, $user): void {
            [$actor, $lockedPlan, $lockedCandidate, $freshAccess] = $this->lockCandidateMutation($user, $candidate);
            $this->assertPlanIsActive($lockedPlan);
            $freshAccess->currentProfileAtSite(
                $actor,
                (int) $lockedPlan->site_id,
                (int) $lockedCandidate->employee_profile_id,
                lockForUpdate: true,
            );
            $lockedCandidate->update([
                'readiness' => 'ready_now',
                'assessed_by' => $actor->id,
                'assessed_at' => now()->toDateString(),
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Candidate nominated to the ready-now talent pool.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && $user->canDo('hr.performance.manage');
    }

    /** @param array<int, int|string> $holderIds */
    private function currentHolders(User $viewer, array $holderIds): EloquentCollection
    {
        if ($holderIds === []) {
            return new EloquentCollection;
        }

        return $this->access->currentUsers($viewer)
            ->whereIn('id', $holderIds)
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
    }

    private function holderForPlan(HrSuccessionPlan $plan, EloquentCollection $holders): ?User
    {
        $holder = $holders->get($plan->current_holder_user_id);
        if (! $holder instanceof User || ! $holder->hrEmployeeProfile) {
            return null;
        }

        return $this->access->profileBelongsToSite(
            $holder->hrEmployeeProfile,
            (int) $plan->site_id,
        ) ? $holder : null;
    }

    /** @param list<int> $allowedSiteIds */
    private function holderOptions(
        User $viewer,
        array $allowedSiteIds,
        ?int $exactSiteId = null,
    ): array {
        $query = $exactSiteId === null
            ? $this->access->currentUsers($viewer)
            : $this->access->currentUsersAtSite($viewer, $exactSiteId);

        return $query
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email'])
            ->map(function (User $holder) use ($allowedSiteIds): array {
                $siteIds = $holder->hrEmployeeProfile
                    ? array_values(array_intersect(
                        $this->access->profileSiteIds($holder->hrEmployeeProfile),
                        $allowedSiteIds,
                    ))
                    : [];

                return [
                    'id' => $holder->id,
                    'name' => $holder->name,
                    'email' => $holder->email,
                    'site_ids' => $siteIds,
                ];
            })
            ->values()
            ->all();
    }

    private function sourceReviewPrefill(User $viewer, mixed $sourceReviewId): ?array
    {
        if ($sourceReviewId === null) {
            return null;
        }

        $review = $this->performanceAccess->performanceReview($viewer, (int) $sourceReviewId);
        if ($review->status !== 'signed_off' || (int) $review->overall_rating < 4) {
            throw ValidationException::withMessages([
                'source_review_id' => 'Only a strong, signed-off review can start a succession nomination.',
            ]);
        }

        $profile = $this->access->currentProfiles($viewer)
            ->with('user:id,name')
            ->where('user_id', $review->employee_user_id)
            ->firstOrFail();
        $siteIds = array_values(array_intersect(
            $this->access->profileSiteIds($profile),
            $this->access->accessibleSiteIds($viewer),
        ));
        $siteId = in_array((int) $profile->primary_site_id, $siteIds, true)
            ? (int) $profile->primary_site_id
            : ($siteIds[0] ?? null);
        abort_unless($siteId !== null, 404);

        return [
            'site_id' => $siteId,
            'source_review_id' => $review->id,
            'candidate' => [
                'employee_profile_id' => $profile->id,
                'name' => $profile->user?->name ?? 'Current staff member',
                'readiness' => 'developing',
            ],
        ];
    }

    private function lockedManager(User $viewer): User
    {
        $actor = User::query()
            ->with(['roles.permissions', 'permissionOverrides'])
            ->lockForUpdate()
            ->findOrFail($viewer->id);
        abort_unless($actor->canDo('hr.performance.manage'), 403);

        return $actor;
    }

    private function activePosition(mixed $positionId, bool $lockForUpdate = false): ?HrPosition
    {
        if ($positionId === null) {
            return null;
        }

        $query = HrPosition::query()->active()->whereKey((int) $positionId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return EloquentCollection<int, HrEmployeeProfile>
     */
    private function lockCandidateProfiles(
        HrSuccessionAccessService $access,
        User $actor,
        int $siteId,
        array $candidates,
    ): EloquentCollection {
        $profiles = new EloquentCollection;
        $profileIds = collect($candidates)
            ->pluck('employee_profile_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values();
        foreach ($profileIds as $profileId) {
            $profile = $access->currentProfileAtSite(
                $actor,
                $siteId,
                $profileId,
                lockForUpdate: true,
            );
            $profiles->put($profile->id, $profile);
        }

        return $profiles;
    }

    private function notesWithSourceReview(
        User $actor,
        int $siteId,
        EloquentCollection $candidateProfiles,
        mixed $sourceReviewId,
        ?string $notes,
    ): ?string {
        if ($sourceReviewId === null) {
            return $notes;
        }

        $review = $this->performanceAccess
            ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $actor)
            ->whereKey((int) $sourceReviewId)
            ->lockForUpdate()
            ->firstOrFail();
        if ($review->status !== 'signed_off' || (int) $review->overall_rating < 4) {
            throw ValidationException::withMessages([
                'source_review_id' => 'Only a strong, signed-off review can support a succession nomination.',
            ]);
        }

        $subject = $candidateProfiles
            ->first(fn (HrEmployeeProfile $profile): bool => (int) $profile->user_id === (int) $review->employee_user_id);
        if (! $subject || ! $this->access->profileBelongsToSite($subject, $siteId)) {
            throw ValidationException::withMessages([
                'source_review_id' => 'The reviewed staff member must be included as a current candidate at this Site.',
            ]);
        }

        $provenance = "Created from performance review #{$review->id}.";

        return filled($notes) ? "{$notes}\n\n{$provenance}" : $provenance;
    }

    private function assertIdentityAvailable(
        int $siteId,
        mixed $positionId,
        string $roleTitle,
        bool $isActive,
        ?int $ignorePlanId = null,
    ): void {
        if (! $isActive) {
            return;
        }

        $query = HrSuccessionPlan::query()
            ->active()
            ->where('site_id', $siteId)
            ->when($ignorePlanId !== null, fn ($planQuery) => $planQuery->whereKeyNot($ignorePlanId));
        if ($positionId !== null) {
            $query->where('position_id', (int) $positionId);
        } else {
            $query->whereNull('position_id')
                ->whereRaw('LOWER(TRIM(role_title)) = ?', [mb_strtolower(trim($roleTitle))]);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'role_title' => 'An active succession plan already covers this role at the selected Site.',
            ]);
        }
    }

    private function assertPlanIsActive(HrSuccessionPlan $plan): void
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'Archived succession plans cannot be changed.',
            ]);
        }
    }

    /** @return array{0: User, 1: HrSuccessionPlan, 2: HrSuccessionCandidate, 3: HrSuccessionAccessService} */
    private function lockCandidateMutation(User $viewer, HrSuccessionCandidate $candidate): array
    {
        $actor = $this->lockedManager($viewer);
        $freshAccess = new HrSuccessionAccessService(new UserSiteAccessService);
        $planId = HrSuccessionCandidate::query()
            ->whereKey($candidate->id)
            ->value('succession_plan_id');
        abort_unless($planId !== null, 404);
        $plan = $freshAccess->plan($actor, (int) $planId, lockForUpdate: true);
        $lockedCandidate = HrSuccessionCandidate::query()
            ->where('succession_plan_id', $plan->id)
            ->lockForUpdate()
            ->findOrFail($candidate->id);

        return [$actor, $plan, $lockedCandidate, $freshAccess];
    }
}
