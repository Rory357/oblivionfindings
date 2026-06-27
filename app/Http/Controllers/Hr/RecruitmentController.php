<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewKit;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Services\RecruitmentAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecruitmentController extends Controller
{
    use ResolvesHrTenant;

    /** Active stages a candidate flows through, in order. */
    private const FLOW = [
        'new', 'screening', 'interview_scheduled', 'interview_completed',
        'reference_check', 'offer_pending', 'offer_sent', 'offer_accepted',
        'onboarding', 'hired',
    ];

    /** Stages rendered as funnel rows / pipeline columns (excludes terminal). */
    private const FUNNEL_STAGES = [
        'new' => 'New',
        'screening' => 'Screening',
        'interview_scheduled' => 'Interview',
        'interview_completed' => 'Interviewed',
        'reference_check' => 'References',
        'offer_sent' => 'Offer sent',
        'offer_accepted' => 'Accepted',
    ];

    private const SOURCES = [
        'referral', 'seek', 'indeed', 'trade_me', 'agency', 'direct',
        'linkedin', 'facebook', 'website', 'other',
    ];

    private const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'];

    public function index(Request $request, RecruitmentAnalyticsService $analytics)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staleDays = (int) config('hr.recruitment.stale_stage_days', 14);
        $from = $request->filled('from') ? (string) $request->query('from') : null;
        $to = $request->filled('to') ? (string) $request->query('to') : null;

        return Inertia::render('hr/recruitment/index', [
            'hero' => $this->buildHero($tenantId, $analytics),
            'needs' => $this->buildNeeds($tenantId),
            'candidates' => $this->buildCandidates($tenantId, $staleDays),
            'requisitions' => $this->buildRequisitions($tenantId),
            'interviews' => $this->buildInterviews($tenantId),
            'offers' => $this->buildOffers($tenantId),
            'analytics' => $this->buildAnalytics($tenantId, $analytics, $from, $to),
            'kits' => $this->buildKits($tenantId),
            'pool' => $this->buildPool($tenantId),
            'support' => $this->buildSupport($tenantId),
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
                'manage_employees' => $user->canDo('hr.employees.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Hero                                                              */
    /* ------------------------------------------------------------------ */

    private function buildHero(?int $tenantId, RecruitmentAnalyticsService $analytics): array
    {
        $openRequisitions = HrJobRequisition::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['draft', 'published', 'paused'])
            ->count();

        $activeCandidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->count();

        $interviewsThisWeek = HrInterview::query()
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $offersOut = HrOffer::query()
            ->whereNotNull('sent_at')
            ->whereNull('response')
            ->count();

        // Funnel by active stage.
        $byStatus = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $funnel = [];
        foreach (self::FUNNEL_STAGES as $key => $label) {
            $funnel[] = ['key' => $key, 'label' => $label, 'count' => (int) ($byStatus[$key] ?? 0)];
        }

        // Speed metrics.
        $tth = collect($analytics->getTimeToHire($tenantId, 6));
        $timeToHire = (int) round((float) ($tth->avg('avg_days') ?? 0));

        $respondedOffers = HrOffer::query()->whereNotNull('response')->count();
        $acceptedOffers = HrOffer::query()->where('response', 'accepted')->count();
        $offerAccept = $respondedOffers > 0 ? (int) round(($acceptedOffers / $respondedOffers) * 100) : 0;

        return [
            'subtitle' => 'Fill roles fast and bring people on safely',
            'open_requisitions' => $openRequisitions,
            'active_candidates' => $activeCandidates,
            'interviews_this_week' => $interviewsThisWeek,
            'offers_out' => $offersOut,
            'time_to_hire_days' => $timeToHire,
            'offer_accept_rate' => $offerAccept,
            'funnel' => $funnel,
        ];
    }

    private function buildNeeds(?int $tenantId): array
    {
        $needs = [];

        $offersAwaitingApproval = HrOffer::query()
            ->where('approval_status', 'draft')
            ->whereNull('sent_at')
            ->whereNull('response')
            ->count();
        if ($offersAwaitingApproval > 0) {
            $needs[] = ['key' => 'offers_approval', 'label' => "{$offersAwaitingApproval} ".str('offer')->plural($offersAwaitingApproval).' to send', 'tab' => 'offers'];
        }

        $interviewsToScore = HrInterview::query()
            ->where('status', 'completed')
            ->whereDoesntHave('scores')
            ->count();
        if ($interviewsToScore > 0) {
            $needs[] = ['key' => 'score', 'label' => "{$interviewsToScore} ".str('interview')->plural($interviewsToScore).' to score', 'tab' => 'interviews'];
        }

        $stuck = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->whereNotNull('current_stage_entered_at')
            ->whereRaw('DATEDIFF(NOW(), current_stage_entered_at) > 7')
            ->count();
        if ($stuck > 0) {
            $needs[] = ['key' => 'stuck', 'label' => "{$stuck} ".str('candidate')->plural($stuck).' stuck >7d', 'tab' => 'pipeline'];
        }

        return $needs;
    }

    /* ------------------------------------------------------------------ */
    /*  Pipeline + board candidates                                       */
    /* ------------------------------------------------------------------ */

    private function buildCandidates(?int $tenantId, int $staleDays): array
    {
        $candidates = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->with(['applications' => fn ($q) => $q->select('id', 'candidate_id', 'requisition_id', 'position_title', 'status')
                ->latest('id'), 'applications.requisition:id,title'])
            ->orderByDesc('current_stage_entered_at')
            ->limit(300)
            ->get();

        return $candidates->map(function (HrCandidate $c) use ($staleDays) {
            $app = $c->applications->first();
            $days = $c->current_stage_entered_at ? (int) $c->current_stage_entered_at->diffInDays(now()) : 0;

            return [
                'id' => $c->id,
                'application_id' => $app?->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'full_name' => $c->full_name,
                'email' => $c->personal_email,
                'source' => $c->source,
                'stage' => $c->status,
                'days' => $days,
                'stale' => $days > $staleDays,
                'requisition' => $app?->requisition ? ['id' => $app->requisition->id, 'title' => $app->requisition->title] : null,
            ];
        })->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Requisitions                                                      */
    /* ------------------------------------------------------------------ */

    private function buildRequisitions(?int $tenantId): array
    {
        $reqs = HrJobRequisition::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['site:id,name', 'position:id,title', 'hiringManager:id,name'])
            ->withCount('applications')
            ->orderByRaw("FIELD(status, 'published', 'draft', 'paused', 'closed')")
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return $reqs->map(fn (HrJobRequisition $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'site' => $r->site?->name ?? 'Multiple sites',
            'status' => $r->status,
            'openings' => (int) $r->openings,
            'applicants' => (int) $r->applications_count,
            'hiring_manager' => $r->hiringManager?->name,
            'employment_type' => $r->employment_type,
            'position' => $r->position?->title ?? $r->title,
            'position_id' => $r->position_id,
        ])->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Interviews                                                        */
    /* ------------------------------------------------------------------ */

    private function buildInterviews(?int $tenantId): array
    {
        $week = HrInterview::query()
            ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()->addDays(2)])
            ->with(['application.candidate:id,first_name,last_name,tenant_id'])
            ->when($tenantId !== null, fn ($q) => $q->whereHas('application.candidate', fn ($c) => $c->where('tenant_id', $tenantId)))
            ->orderBy('scheduled_at')
            ->limit(40)
            ->get()
            ->map(function (HrInterview $i) {
                $cand = $i->application?->candidate;

                return [
                    'id' => $i->id,
                    'application_id' => $i->application_id,
                    'candidate' => $cand?->full_name ?? 'Candidate',
                    'type' => $i->interview_type,
                    'status' => $i->status,
                    'scheduled_at' => optional($i->scheduled_at)->toIso8601String(),
                ];
            })->values()->all();

        return [
            'week' => $week,
            'consensus' => $this->buildConsensus($tenantId),
        ];
    }

    private function buildConsensus(?int $tenantId): ?array
    {
        // Most-recently-scored application's panel roll-up.
        $latestScore = \App\Domain\Hr\Models\HrInterviewScore::query()
            ->with(['interview.application.candidate:id,first_name,last_name,tenant_id', 'interview.application:id,candidate_id,position_title'])
            ->latest('submitted_at')
            ->first();

        $application = $latestScore?->interview?->application;
        if (! $application) {
            return null;
        }

        $scores = \App\Domain\Hr\Models\HrInterviewScore::query()
            ->whereHas('interview', fn ($q) => $q->where('application_id', $application->id))
            ->get();

        $byCriteria = [];
        foreach ($scores as $score) {
            foreach (($score->criteria_scores ?? []) as $crit) {
                $label = $crit['label'] ?? null;
                $val = isset($crit['score']) ? (float) $crit['score'] : null;
                if ($label === null || $val === null) {
                    continue;
                }
                $byCriteria[$label][] = $val;
            }
        }

        $criteria = [];
        foreach ($byCriteria as $label => $vals) {
            $avg = count($vals) ? array_sum($vals) / count($vals) : 0;
            $criteria[] = [
                'label' => $label,
                'avg' => round($avg, 1),
                'dots' => array_map(fn ($v) => round($v, 1), $vals),
            ];
        }

        // Majority recommendation.
        $recCounts = $scores->groupBy('recommendation')->map->count();
        $topRec = $recCounts->sortDesc()->keys()->first();
        $recLabel = [
            'strong_yes' => 'Strong hire', 'yes' => 'Recommend hire', 'maybe' => 'Mixed signal',
            'no' => 'Do not hire', 'strong_no' => 'Strong no',
        ][$topRec] ?? 'Awaiting scores';

        $cand = $application->candidate;

        return [
            'name' => $cand?->full_name ?? 'Candidate',
            'role' => $application->position_title ?? 'Role',
            'count' => $scores->count(),
            'criteria' => $criteria,
            'rec' => $recLabel,
            'rec_sub' => $scores->count().' '.str('scorecard')->plural($scores->count()).' in',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Offers                                                            */
    /* ------------------------------------------------------------------ */

    private function buildOffers(?int $tenantId): array
    {
        $offers = HrOffer::query()
            ->with(['application.candidate:id,first_name,last_name,tenant_id'])
            ->when($tenantId !== null, fn ($q) => $q->whereHas('application.candidate', fn ($c) => $c->where('tenant_id', $tenantId)))
            ->latest('created_at')
            ->limit(60)
            ->get();

        $list = $offers->map(function (HrOffer $o) {
            $status = $this->offerStatus($o);
            $cand = $o->application?->candidate;
            $pay = $o->hourly_rate
                ? '$'.number_format((float) $o->hourly_rate, 2).' / hr'
                : ($o->annual_salary ? '$'.number_format((float) $o->annual_salary, 0).' / yr' : '—');

            $meta = match ($status) {
                'sent' => $o->portal_expires_at ? 'Expires '.$o->portal_expires_at->diffForHumans() : 'Sent',
                'accepted' => 'Ready to convert',
                'declined' => 'Declined'.($o->response_notes ? ' — '.str($o->response_notes)->limit(20) : ''),
                'approved' => 'Ready to send',
                default => 'Not yet sent',
            };

            return [
                'id' => $o->id,
                'application_id' => $o->application_id,
                'candidate' => $cand?->full_name ?? 'Candidate',
                'role' => $o->position_title ?? 'Role',
                'status' => $status,
                'pay' => $pay,
                'meta' => $meta,
                'response' => $o->response,
                'approval_status' => $o->approval_status,
                'sent' => $o->sent_at !== null,
            ];
        })->values();

        $count = fn (string $s) => $list->where('status', $s)->count();

        return [
            'summary' => [
                ['key' => 'draft', 'label' => 'Draft', 'count' => $count('draft') + $count('approved'), 'color' => 'var(--muted-foreground)'],
                ['key' => 'sent', 'label' => 'Sent', 'count' => $count('sent'), 'color' => 'var(--status-info)'],
                ['key' => 'accepted', 'label' => 'Accepted', 'count' => $count('accepted'), 'color' => 'var(--status-success)'],
                ['key' => 'declined', 'label' => 'Declined', 'count' => $count('declined'), 'color' => 'var(--status-critical)'],
            ],
            'list' => $list->all(),
        ];
    }

    private function offerStatus(HrOffer $o): string
    {
        if ($o->response === 'accepted') {
            return 'accepted';
        }
        if ($o->response === 'declined') {
            return 'declined';
        }
        if ($o->response === 'withdrawn') {
            return 'withdrawn';
        }
        if ($o->sent_at !== null) {
            return 'sent';
        }
        if ($o->approval_status === 'approved') {
            return 'approved';
        }

        return 'draft';
    }

    /* ------------------------------------------------------------------ */
    /*  Analytics                                                         */
    /* ------------------------------------------------------------------ */

    private function buildAnalytics(?int $tenantId, RecruitmentAnalyticsService $analytics, ?string $from = null, ?string $to = null): array
    {
        $conversion = collect($analytics->getPipelineConversion($tenantId));
        $sources = collect($analytics->getSourceEffectiveness($tenantId));
        $tth = collect($analytics->getTimeToHire($tenantId, 6));
        $velocity = collect($analytics->getHiringVelocity($tenantId));
        $openPositions = collect($analytics->getOpenPositionsSummary($tenantId, $from, $to));

        $hiresThisMonth = (int) ($velocity->last()['count'] ?? 0);
        $avgTth = (int) round((float) ($tth->avg('avg_days') ?? 0));
        $totalActive = HrCandidate::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['withdrawn', 'rejected', 'hired'])
            ->count();
        $totalSourced = (int) $sources->sum('total');
        $totalHired = (int) $sources->sum('hired');
        $hireRate = $totalSourced > 0 ? round(($totalHired / $totalSourced) * 100, 1) : 0;

        $maxConv = max(1, (int) $conversion->max('count'));
        $maxSrc = max(1, (int) $sources->max('total'));

        return [
            'kpis' => [
                ['key' => 'tth', 'label' => 'Avg time to hire', 'value' => $avgTth.'d', 'trend' => ''],
                ['key' => 'active', 'label' => 'Active candidates', 'value' => (string) $totalActive, 'trend' => ''],
                ['key' => 'hires', 'label' => 'Hires this month', 'value' => (string) $hiresThisMonth, 'trend' => ''],
                ['key' => 'rate', 'label' => 'Sourced → hired', 'value' => $hireRate.'%', 'trend' => ''],
            ],
            'funnel' => $conversion->map(fn ($row) => [
                'label' => str($row['stage'])->headline()->toString(),
                'count' => (int) $row['count'],
                'rate' => ($row['percentage'] ?? 0).'%',
                'width' => round(((int) $row['count'] / $maxConv) * 100),
            ])->values()->all(),
            'sources' => $sources->map(fn ($row) => [
                'name' => $row['source'] ?: 'Unknown',
                'total' => (int) $row['total'],
                'hired' => (int) $row['hired'],
                'detail' => $row['hired'].' hired · '.$row['conversion_rate'].'%',
                'width' => round(((int) $row['total'] / $maxSrc) * 100),
            ])->values()->all(),
            'open_positions' => $openPositions->map(fn ($row) => [
                'requisition_id' => $row['requisition_id'],
                'title' => $row['position_title'] ?: 'Untitled',
                'applications' => (int) $row['applications'],
                'days_open' => (int) $row['days_open'],
            ])->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Kits + pool                                                       */
    /* ------------------------------------------------------------------ */

    private function buildKits(?int $tenantId): array
    {
        return HrInterviewKit::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(fn (HrInterviewKit $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'role' => $k->role,
                'is_active' => (bool) $k->is_active,
                'criteria' => collect($k->criteria ?? [])->map(fn ($c) => [
                    'label' => $c['label'] ?? 'Criterion',
                    'weight' => (int) ($c['weight'] ?? 0),
                ])->values()->all(),
            ])->values()->all();
    }

    private function buildPool(?int $tenantId): array
    {
        // Durable talent pool — explicit hr_talent_pool membership (D5 / item 22),
        // not any non-empty tags. Membership survives candidate anonymisation.
        return \App\Domain\Hr\Models\HrTalentPool::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with([
                'candidate' => fn ($q) => $q->withTrashed()->select('id', 'first_name', 'last_name', 'deleted_at'),
                'candidate.applications' => fn ($q) => $q->select('id', 'candidate_id', 'position_title')->latest('id'),
            ])
            ->latest('updated_at')
            ->limit(80)
            ->get()
            ->filter(fn ($membership) => $membership->candidate !== null)
            ->map(fn ($membership) => [
                'id' => $membership->candidate_id,
                'name' => $membership->candidate->full_name,
                'last_role' => $membership->candidate->applications->first()?->position_title ?? '—',
                'tags' => array_values((array) ($membership->tags ?? [])),
                'reason' => $membership->reason ?: 'Kept warm',
            ])->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Wizard support lists                                              */
    /* ------------------------------------------------------------------ */

    private function buildSupport(?int $tenantId): array
    {
        $sites = Site::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $kits = HrInterviewKit::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $managers = User::query()
            ->when($this->hrStaffUserIdsForTenant($tenantId) !== [], fn ($q) => $q->whereIn('id', $this->hrStaffUserIdsForTenant($tenantId)))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email']);

        $positions = HrPosition::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->with(['requisitions:id,position_id,status,openings'])
            ->orderBy('title')
            ->limit(200)
            ->get()
            ->map(fn (HrPosition $p) => [
                'id' => $p->id,
                'label' => $p->title,
                'role' => $p->code,
                'employment_type' => $p->employment_type,
                'vacancies' => $p->actionable_vacancies,
            ])->values()->all();

        return [
            'sites' => $sites,
            'roles' => collect(self::EMPLOYMENT_TYPES)->map(fn ($v) => [
                'value' => $v, 'label' => str($v)->headline()->toString(),
            ])->all(),
            'hiring_managers' => $managers,
            'interview_kits' => $kits,
            'positions' => $positions,
            'sources' => self::SOURCES,
            'employment_types' => self::EMPLOYMENT_TYPES,
            'document_categories' => \App\Domain\Hr\Models\HrCandidateDocument::CATEGORIES ?? [],
            'stages' => self::FLOW,
        ];
    }
}
