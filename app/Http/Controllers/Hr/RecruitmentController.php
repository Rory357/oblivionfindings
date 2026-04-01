<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\RecruitmentAnalyticsService;
use App\Domain\Hr\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RecruitmentController extends Controller
{
    use ResolvesHrTenant;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $search = trim((string) $request->query('search', ''));
        $source = trim((string) $request->query('source', ''));
        $status = trim((string) $request->query('status', ''));

        $candidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($source !== '', fn ($query) => $query->where('source', $source))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('personal_email', 'like', "%{$search}%");
                });
            })
            ->with(['applications.jobPosting:id,title,slug'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $pipeline = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $sourceBreakdown = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('source, count(*) as count')
            ->groupBy('source')
            ->orderByDesc('count')
            ->pluck('count', 'source');

        $todayStats = $this->getTodayStats($tenantId);
        $recentActivity = $this->getRecentActivity($tenantId);
        $urgentItems = $this->getUrgentItems($tenantId);

        return Inertia::render('hr/recruitment/index', [
            'candidates' => $candidates,
            'pipeline' => $pipeline,
            'sourceBreakdown' => $sourceBreakdown,
            'stages' => RecruitmentService::STAGES,
            'todayStats' => $todayStats,
            'recentActivity' => $recentActivity,
            'urgentItems' => $urgentItems,
            'filters' => [
                'search' => $search,
                'source' => $source,
                'status' => $status,
            ],
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    public function kanban(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $kanbanStages = ['new', 'screening', 'interview_scheduled', 'interview_completed', 'reference_check', 'offer_pending', 'offer_sent', 'offer_accepted', 'hired', 'withdrawn', 'rejected'];

        $candidates = HrCandidate::with(['applications' => function ($q) {
            $q->select('id', 'candidate_id', 'position_title', 'job_posting_id', 'status');
        }, 'applications.jobPosting:id,title'])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', $kanbanStages)
            ->orderByDesc('current_stage_entered_at')
            ->get();

        $columns = [];
        foreach ($kanbanStages as $stage) {
            $columns[$stage] = $candidates
                ->where('status', $stage)
                ->map(function ($candidate) {
                    $daysInStage = $candidate->current_stage_entered_at
                        ? (int) $candidate->current_stage_entered_at->diffInDays(now())
                        : 0;

                    $firstApp = $candidate->applications->first();

                    return [
                        'id' => $candidate->id,
                        'name' => $candidate->full_name,
                        'email' => $candidate->personal_email,
                        'position' => $firstApp?->position_title ?? 'No position',
                        'job_posting_title' => $firstApp?->jobPosting?->title,
                        'days_in_stage' => $daysInStage,
                        'source' => $candidate->source,
                        'created_at' => $candidate->created_at->toISOString(),
                    ];
                })
                ->values()
                ->toArray();
        }

        return Inertia::render('hr/recruitment/kanban', [
            'columns' => $columns,
            'stages' => $kanbanStages,
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    public function analytics(Request $request, RecruitmentAnalyticsService $analyticsService)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        return Inertia::render('hr/recruitment/analytics', [
            'timeToHire' => $analyticsService->getTimeToHire($tenantId),
            'sourceEffectiveness' => $analyticsService->getSourceEffectiveness($tenantId),
            'pipelineConversion' => $analyticsService->getPipelineConversion($tenantId),
            'openPositions' => $analyticsService->getOpenPositionsSummary($tenantId),
            'hiringVelocity' => $analyticsService->getHiringVelocity($tenantId),
            'stageBottlenecks' => $analyticsService->getStageBottlenecks($tenantId),
            'monthlyTrend' => $analyticsService->getMonthlyApplicationTrend($tenantId),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    private function getTodayStats(?int $tenantId): array
    {
        $tenantScope = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;

        $newThisWeek = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        $interviewsToday = HrInterview::query()
            ->whereDate('scheduled_at', today())
            ->where('status', 'scheduled')
            ->count();

        $offersPending = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['offer_pending', 'offer_sent'])
            ->count();

        $totalActive = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->count();

        $avgDays = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->whereNotNull('current_stage_entered_at')
            ->selectRaw('AVG(DATEDIFF(NOW(), current_stage_entered_at)) as avg_days')
            ->value('avg_days');

        return [
            'total_active' => $totalActive,
            'new_this_week' => $newThisWeek,
            'interviews_today' => $interviewsToday,
            'offers_pending' => $offersPending,
            'avg_days_in_stage' => round((float) ($avgDays ?? 0), 1),
        ];
    }

    private function getRecentActivity(?int $tenantId, int $limit = 10): array
    {
        $candidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['applications' => fn ($q) => $q->select('id', 'candidate_id', 'position_title')])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $candidates->map(function ($candidate) {
            $position = $candidate->applications->first()?->position_title ?? 'Unknown position';
            return [
                'type' => 'status_change',
                'description' => "{$candidate->full_name} is at {$candidate->status} stage for {$position}",
                'timestamp' => $candidate->updated_at?->diffForHumans() ?? '',
                'candidate_id' => $candidate->id,
            ];
        })->toArray();
    }

    private function getUrgentItems(?int $tenantId): array
    {
        $items = [];

        // Interviews today/tomorrow
        $upcomingInterviews = HrInterview::query()
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDay()->endOfDay()])
            ->with('application.candidate')
            ->limit(5)
            ->get();

        foreach ($upcomingInterviews as $interview) {
            $candidate = $interview->application?->candidate;
            $items[] = [
                'type' => 'interview',
                'severity' => 'warning',
                'description' => ($candidate ? $candidate->full_name : 'Unknown') . ' interview ' .
                    ($interview->scheduled_at->isToday() ? 'today at ' : 'tomorrow at ') .
                    $interview->scheduled_at->format('g:i A'),
            ];
        }

        // Stale candidates (>14 days in stage)
        $staleCandidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->whereNotNull('current_stage_entered_at')
            ->whereRaw('DATEDIFF(NOW(), current_stage_entered_at) > 14')
            ->limit(5)
            ->get();

        foreach ($staleCandidates as $candidate) {
            $days = (int) $candidate->current_stage_entered_at->diffInDays(now());
            $items[] = [
                'type' => 'stale',
                'severity' => 'danger',
                'description' => "{$candidate->full_name} has been in {$candidate->status} for {$days} days",
            ];
        }

        // Expiring offers
        $expiringOffers = HrOffer::query()
            ->whereNotNull('portal_expires_at')
            ->whereNull('response')
            ->where('portal_expires_at', '<=', now()->addDays(3))
            ->with('application.candidate')
            ->limit(3)
            ->get();

        foreach ($expiringOffers as $offer) {
            $candidate = $offer->application?->candidate;
            $items[] = [
                'type' => 'offer',
                'severity' => 'warning',
                'description' => ($candidate ? $candidate->full_name : 'Unknown') . ' offer expires ' .
                    $offer->portal_expires_at->diffForHumans(),
            ];
        }

        return $items;
    }
}
