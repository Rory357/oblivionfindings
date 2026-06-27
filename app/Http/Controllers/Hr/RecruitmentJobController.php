<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrInterviewKit;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RecruitmentJobController extends Controller
{
    use ResolvesHrTenant;

    private const POSTING_CHANNELS = ['career_page', 'linkedin', 'seek', 'indeed', 'facebook'];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $hiringManagerUserId = $request->query('hiring_manager_user_id');
        $staleStageDays = (int) config('hr.recruitment.stale_stage_days', 14);
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);

        $jobsQuery = HrJobRequisition::query()
            ->with([
                'site:id,name',
                'defaultInterviewKit:id,name',
                'hiringManager:id,name',
                'applications:id,requisition_id,candidate_id,status',
                'applications.candidate:id,status,current_stage_entered_at',
            ])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->when($hiringManagerUserId !== null && $hiringManagerUserId !== '', function ($query) use ($hiringManagerUserId) {
                if ($hiringManagerUserId === 'unassigned') {
                    $query->whereNull('hiring_manager_user_id');

                    return;
                }

                $query->where('hiring_manager_user_id', (int) $hiringManagerUserId);
            });

        $jobsForSummary = (clone $jobsQuery)->get();
        $summary = $this->buildSummary($jobsForSummary, $staleStageDays);
        $managerSummary = $this->buildManagerSummary($jobsForSummary, $staleStageDays);

        $jobs = (clone $jobsQuery)
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $jobs->setCollection(
            $jobs->getCollection()
                ->map(fn (HrJobRequisition $job) => $this->transformJob($job, $staleStageDays))
                ->values()
        );

        $sites = Site::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $interviewKits = HrInterviewKit::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $hiringManagers = User::query()
            ->when($tenantStaffIds !== [], fn ($query) => $query->whereIn('id', $tenantStaffIds))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/recruitment/jobs', [
            'jobs' => $jobs,
            'summary' => $summary,
            'managerSummary' => $managerSummary,
            'sites' => $sites,
            'interviewKits' => $interviewKits,
            'hiringManagers' => $hiringManagers,
            'statuses' => ['draft', 'published', 'paused', 'closed'],
            'employmentTypes' => ['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'],
            'postingChannels' => self::POSTING_CHANNELS,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'hiring_manager_user_id' => $hiringManagerUserId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $siteRule = Rule::exists('sites', 'id');
        $kitRule = Rule::exists('hr_interview_kits', 'id');
        $siteRule = $siteRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $kitRule = $kitRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $managerRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');

        $positionRule = Rule::exists('hr_positions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'position_role' => ['nullable', 'string', 'max:100'],
            'position_id' => ['nullable', 'integer', $positionRule],
            'site_id' => ['nullable', 'integer', $siteRule],
            'employment_type' => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'openings' => ['required', 'integer', 'min:1', 'max:100'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'description' => ['required', 'string', 'max:20000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'responsibilities' => ['nullable', 'string', 'max:20000'],
            'default_interview_kit_id' => ['nullable', 'integer', $kitRule],
            'hiring_manager_user_id' => ['nullable', 'integer', $managerRule],
            'posting_channels' => ['nullable', 'array'],
            'posting_channels.*' => ['string', Rule::in(self::POSTING_CHANNELS)],
            'closing_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        HrJobRequisition::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'slug' => $this->generateUniqueSlug((string) $validated['title'], $tenantId),
            'status' => 'draft',
            'external_posting_status' => 'not_posted',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Job requisition created.');
    }

    public function update(Request $request, HrJobRequisition $job)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $job->tenant_id);

        $siteRule = Rule::exists('sites', 'id');
        $kitRule = Rule::exists('hr_interview_kits', 'id');
        $siteRule = $siteRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $kitRule = $kitRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $managerRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');

        $positionRule = Rule::exists('hr_positions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position_role' => ['nullable', 'string', 'max:100'],
            'position_id' => ['nullable', 'integer', $positionRule],
            'site_id' => ['nullable', 'integer', $siteRule],
            'employment_type' => ['sometimes', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'openings' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'responsibilities' => ['nullable', 'string', 'max:20000'],
            'default_interview_kit_id' => ['nullable', 'integer', $kitRule],
            'hiring_manager_user_id' => ['nullable', 'integer', $managerRule],
            'posting_channels' => ['nullable', 'array'],
            'posting_channels.*' => ['string', Rule::in(self::POSTING_CHANNELS)],
            'closing_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'paused', 'closed'])],
        ]);

        if (array_key_exists('title', $validated) && $validated['title'] !== $job->title) {
            $validated['slug'] = $this->generateUniqueSlug((string) $validated['title'], $job->tenant_id, $job->id);
        }

        $validated['updated_by'] = $user->id;
        $job->update($validated);

        return redirect()->back()->with('success', 'Job requisition updated.');
    }

    public function publish(Request $request, HrJobRequisition $job)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $job->tenant_id);

        $job->update([
            'status' => 'published',
            'published_at' => $job->published_at ?? now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Job published to careers page.');
    }

    public function close(Request $request, HrJobRequisition $job)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $job->tenant_id);

        $job->update([
            'status' => 'closed',
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Job closed.');
    }

    public function syncPosting(Request $request, HrJobRequisition $job)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $job->tenant_id);

        if ($job->status !== 'published') {
            return redirect()->back()->withErrors([
                'job' => 'Only published jobs can be synced to external channels.',
            ]);
        }

        $channels = collect($job->posting_channels ?? [])
            ->filter(fn ($channel) => is_string($channel))
            ->values();

        if ($channels->isEmpty()) {
            return redirect()->back()->withErrors([
                'job' => 'Select at least one posting channel before syncing.',
            ]);
        }

        $job->update([
            'external_posting_status' => 'posted',
            'external_posted_at' => $job->external_posted_at ?? now(),
            'external_sync_at' => now(),
            'external_sync_error' => null,
            'external_reference' => $channels->mapWithKeys(
                fn (string $channel) => [$channel => [
                    'external_job_id' => 'JOB-' . strtoupper($channel) . '-' . $job->id,
                    'last_synced_at' => now()->toIso8601String(),
                ]]
            )->toArray(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'External posting channels synced.');
    }

    private function generateUniqueSlug(string $title, ?int $tenantId, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $slug = $base !== '' ? $base : 'job';
        $counter = 1;

        while (HrJobRequisition::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId), fn ($query) => $query->whereNull('tenant_id'))
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $counter++;
            $slug = $base . '-' . $counter;
        }

        return $slug;
    }

    private function transformJob(HrJobRequisition $job, int $staleStageDays): array
    {
        $metrics = $this->calculateMetrics($job->applications, $staleStageDays);

        return [
            'id' => $job->id,
            'title' => $job->title,
            'slug' => $job->slug,
            'position_role' => $job->position_role,
            'employment_type' => $job->employment_type,
            'openings' => (int) $job->openings,
            'status' => $job->status,
            'summary' => $job->summary,
            'description' => $job->description,
            'requirements' => $job->requirements,
            'responsibilities' => $job->responsibilities,
            'published_at' => optional($job->published_at)->toDateString(),
            'closing_at' => optional($job->closing_at)->toDateString(),
            'site' => $job->site ? ['id' => $job->site->id, 'name' => $job->site->name] : null,
            'default_interview_kit' => $job->defaultInterviewKit ? [
                'id' => $job->defaultInterviewKit->id,
                'name' => $job->defaultInterviewKit->name,
            ] : null,
            'hiring_manager' => $job->hiringManager ? [
                'id' => $job->hiringManager->id,
                'name' => $job->hiringManager->name,
            ] : null,
            'posting_channels' => $job->posting_channels ?? [],
            'external_posting_status' => $job->external_posting_status ?? 'not_posted',
            'external_posted_at' => optional($job->external_posted_at)->toDateTimeString(),
            'external_sync_at' => optional($job->external_sync_at)->toDateTimeString(),
            'external_sync_error' => $job->external_sync_error,
            'metrics' => $metrics,
        ];
    }

    private function buildSummary(Collection $jobs, int $staleStageDays): array
    {
        $metrics = $jobs->map(fn (HrJobRequisition $job) => $this->calculateMetrics($job->applications, $staleStageDays));

        return [
            'total_jobs' => $jobs->count(),
            'open_requisitions' => $jobs->whereIn('status', ['draft', 'published', 'paused'])->count(),
            'published_jobs' => $jobs->where('status', 'published')->count(),
            'closing_soon' => $jobs->filter(fn (HrJobRequisition $job) => $job->status === 'published'
                && $job->closing_at
                && $job->closing_at->isBetween(now()->startOfDay(), now()->addDays(14)->endOfDay()))->count(),
            'externally_posted_jobs' => $jobs->where('external_posting_status', 'posted')->count(),
            'external_sync_failed_jobs' => $jobs->where('external_posting_status', 'sync_failed')->count(),
            'active_candidates' => $metrics->sum('active_candidates'),
            'stale_candidates' => $metrics->sum('stale_candidates'),
            'offers_in_flight' => $metrics->sum('offers_in_flight'),
            'hired_candidates' => $metrics->sum('hired_candidates'),
        ];
    }

    private function buildManagerSummary(Collection $jobs, int $staleStageDays): array
    {
        return $jobs
            ->groupBy(fn (HrJobRequisition $job) => $job->hiring_manager_user_id ?? 'unassigned')
            ->map(function (Collection $group) use ($staleStageDays) {
                /** @var HrJobRequisition $first */
                $first = $group->first();
                $metrics = $group->map(fn (HrJobRequisition $job) => $this->calculateMetrics($job->applications, $staleStageDays));

                return [
                    'manager' => $first->hiringManager ? [
                        'id' => $first->hiringManager->id,
                        'name' => $first->hiringManager->name,
                    ] : null,
                    'open_jobs' => $group->whereIn('status', ['draft', 'published', 'paused'])->count(),
                    'active_candidates' => $metrics->sum('active_candidates'),
                    'stale_candidates' => $metrics->sum('stale_candidates'),
                    'offers_in_flight' => $metrics->sum('offers_in_flight'),
                    'hired_candidates' => $metrics->sum('hired_candidates'),
                ];
            })
            ->sortByDesc('active_candidates')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, \App\Domain\Hr\Models\HrApplication> $applications
     * @return array<string, int|float>
     */
    private function calculateMetrics(Collection $applications, int $staleStageDays): array
    {
        $activeCandidates = $applications->filter(fn ($application) => $this->isActiveCandidate($application->candidate))->count();
        $staleCandidates = $applications->filter(fn ($application) => $this->isStaleCandidate($application->candidate, $staleStageDays))->count();
        $offersInFlight = $applications->filter(
            fn ($application) => in_array((string) ($application->candidate?->status ?? ''), ['offer_pending', 'offer_sent', 'offer_accepted'], true)
        )->count();
        $hiredCandidates = $applications->filter(
            fn ($application) => $application->status === 'hired' || $application->candidate?->status === 'hired'
        )->count();

        $averageStageAge = $applications
            ->filter(fn ($application) => $this->isActiveCandidate($application->candidate) && $application->candidate?->current_stage_entered_at !== null)
            ->avg(fn ($application) => $application->candidate->current_stage_entered_at->diffInDays(now()));

        return [
            'total_applications' => $applications->count(),
            'active_candidates' => $activeCandidates,
            'stale_candidates' => $staleCandidates,
            'offers_in_flight' => $offersInFlight,
            'hired_candidates' => $hiredCandidates,
            'conversion_rate' => $applications->count() > 0 ? round(($hiredCandidates / $applications->count()) * 100, 1) : 0.0,
            'average_stage_age_days' => round((float) ($averageStageAge ?? 0), 1),
        ];
    }

    private function isActiveCandidate(?HrCandidate $candidate): bool
    {
        if (! $candidate) {
            return false;
        }

        return ! in_array($candidate->status, ['withdrawn', 'rejected', 'hired'], true);
    }

    private function isStaleCandidate(?HrCandidate $candidate, int $staleStageDays): bool
    {
        if (! $this->isActiveCandidate($candidate)) {
            return false;
        }

        if (! $candidate?->current_stage_entered_at) {
            return false;
        }

        return $candidate->current_stage_entered_at->lte(now()->subDays($staleStageDays));
    }
}

