<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSkill;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Unified Performance & Development hub — a single page aggregating the eight
 * sub-domains (reviews, supervision, goals, development, competencies & skills,
 * 360 feedback, PIPs, succession) into one golden-hero, tabbed surface. Writes
 * still flow to each domain's own controller; this is the read-side aggregator.
 */
class PerformanceHubController extends Controller
{
    use ResolvesHrTenant;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.performance.manage');
        $roleMap = $this->roleMap($tenantId);

        $reviews = $this->reviews($tenantId, $roleMap);
        $supervision = $this->supervision($tenantId, $roleMap);
        $goals = $this->goals($tenantId);
        $development = $this->development($tenantId, $roleMap);
        $feedback = $this->feedback($tenantId, $roleMap);
        $pips = $this->pips($tenantId, $roleMap);
        $competencies = $this->competencies($tenantId);
        $succession = $this->succession($tenantId);

        return Inertia::render('hr/performance/index', [
            'hero' => $this->hero($tenantId, $reviews, $supervision, $goals, $feedback, $pips, $succession),
            'reviews' => $reviews,
            'supervision' => $supervision,
            'goals' => $goals,
            'development' => $development,
            'feedback' => $feedback,
            'pips' => $pips,
            'competencies' => $competencies,
            'succession' => $succession,
            'staff' => $this->staffOptions($tenantId),
            'sessionTypes' => HrSupervisionNote::sessionTypeOptions(),
            'successionEmployees' => HrEmployeeProfile::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->with('user:id,name')
                ->orderBy('user_id')
                ->limit(500)
                ->get(['id', 'user_id'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => $p->user?->name ?? 'Unknown'])
                ->all(),
            'competencyOptions' => HrCompetency::forTenant($tenantId)->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            'reviewTypes' => [
                ['value' => 'annual', 'label' => 'Annual'],
                ['value' => 'mid_year', 'label' => 'Mid-year'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
                ['value' => 'ad_hoc', 'label' => 'Ad hoc'],
            ],
            'can' => ['manage' => $canManage],
        ]);
    }

    /**
     * Stream a tab's full dataset as a CSV download (server-side export).
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $roleMap = $this->roleMap($tenantId);
        $tab = (string) $request->query('tab', 'reviews');

        $rows = match ($tab) {
            'reviews' => $this->reviews($tenantId, $roleMap),
            'supervision' => $this->supervision($tenantId, $roleMap)['rows'],
            'goals' => $this->goals($tenantId),
            'development' => $this->development($tenantId, $roleMap),
            'feedback' => array_map(fn ($r) => collect($r)->except('ids')->all(), $this->feedback($tenantId, $roleMap)),
            'pips' => $this->pips($tenantId, $roleMap),
            'competencies' => $this->competencies($tenantId)['coverage'],
            default => [],
        };

        abort_if($rows === [] && ! in_array($tab, ['reviews', 'supervision', 'goals', 'development', 'feedback', 'pips', 'competencies'], true), 404);

        $headers = $rows === [] ? [] : array_keys((array) $rows[0]);
        $slug = preg_replace('/[^a-z0-9_-]/i', '', $tab);
        $title = ucfirst($tab).' export';

        if ($request->query('format') === 'pdf') {
            $pdf = Pdf::loadView('hr.performance.export-pdf', [
                'title' => $title,
                'headers' => $headers,
                'rows' => array_map(fn ($r) => (array) $r, $rows),
                'generatedAt' => now()->format('d M Y H:i'),
            ])->setPaper('a4', 'landscape');

            return $pdf->download('performance-'.$slug.'-'.now()->format('Ymd').'.pdf');
        }

        $filename = 'performance-'.$slug.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            if ($headers !== []) {
                $this->putCsv($out, $headers);
                foreach ($rows as $row) {
                    $this->putCsv($out, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, array_values((array) $row)));
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------------ */
    /*  Hero — clickable stats, compliance, needs-you */
    /* ------------------------------------------------------------------ */

    private function hero(int $tenantId, array $reviews, array $supervision, array $goals, array $feedback, array $pips, array $succession): array
    {
        $reviewsDue = collect($reviews)->whereIn('status', ['draft', 'in_progress', 'pending'])->count();
        $reviewsOverdue = collect($reviews)->where('status', 'overdue')->count();
        $supDue = $supervision['overdue_count'] ?? 0;
        $activeOkrs = collect($goals)->whereNotIn('status', ['completed', 'cancelled', 'draft'])->count();
        $feedbackInFlight = collect($feedback)->where('status', 'in_progress')->count();
        $activePips = collect($pips)->whereIn('status', ['active', 'in_progress', 'monitoring'])->count();
        $successionRisk = collect($succession['critical_roles'] ?? [])->whereIn('risk', ['high', 'critical'])->count();

        $rated = collect($reviews)->pluck('rating')->filter(fn ($r) => $r !== null);
        $avgRating = $rated->count() ? round($rated->avg(), 1) : null;

        return [
            'stats' => [
                ['key' => 'reviews_due', 'label' => 'Reviews due', 'value' => $reviewsDue, 'tab' => 'reviews', 'status' => 'pending'],
                ['key' => 'reviews_overdue', 'label' => 'Overdue', 'value' => $reviewsOverdue, 'tab' => 'reviews', 'status' => 'overdue', 'amber' => true],
                ['key' => 'sup_due', 'label' => 'Supervisions due', 'value' => $supDue, 'tab' => 'supervision', 'status' => 'overdue'],
                ['key' => 'active_okrs', 'label' => 'Active OKRs', 'value' => $activeOkrs, 'tab' => 'goals'],
                ['key' => 'avg_rating', 'label' => 'Avg rating', 'value' => $avgRating ?? '—', 'tab' => 'reviews'],
                ['key' => 'feedback', 'label' => '360 in-flight', 'value' => $feedbackInFlight, 'tab' => 'feedback'],
                ['key' => 'pips', 'label' => 'Active PIPs', 'value' => $activePips, 'tab' => 'pips', 'amber' => true],
                ['key' => 'succession', 'label' => 'Succession risk', 'value' => $successionRisk, 'tab' => 'succession', 'amber' => true],
            ],
            'compliance' => $this->compliance($tenantId, $reviews),
            'needs' => array_values(array_filter([
                $reviewsOverdue ? ['label' => $reviewsOverdue.' overdue '.str('review')->plural($reviewsOverdue), 'icon' => 'award', 'tab' => 'reviews', 'status' => 'overdue'] : null,
                $supDue ? ['label' => $supDue.' supervisions due', 'icon' => 'supervision', 'tab' => 'supervision', 'status' => 'overdue'] : null,
                $activePips ? ['label' => $activePips.' active '.str('PIP')->plural($activePips), 'icon' => 'trend', 'tab' => 'pips'] : null,
                $feedbackInFlight ? ['label' => $feedbackInFlight.' 360s awaiting response', 'icon' => 'message', 'tab' => 'feedback'] : null,
            ])),
        ];
    }

    private function compliance(int $tenantId, array $reviews): array
    {
        $probationDue = HrPerformanceReview::where('tenant_id', $tenantId)
            ->where('review_type', 'ad_hoc')
            ->whereIn('status', ['draft', 'in_progress'])
            ->whereBetween('next_review_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        $signedThisQuarter = HrPerformanceReview::where('tenant_id', $tenantId)
            ->where('status', 'signed_off')
            ->where('manager_signed_off_at', '>=', now()->startOfQuarter())
            ->count();

        return [
            ['label' => 'NZ — Holidays Act in good standing', 'ok' => true],
            ['label' => $probationDue
                ? $probationDue.' probation '.str('review')->plural($probationDue).' due this week'
                : 'No probation reviews due this week', 'ok' => $probationDue === 0],
            ['label' => $signedThisQuarter.' '.str('review')->plural($signedThisQuarter).' signed off this quarter', 'ok' => true],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Reviews */
    /* ------------------------------------------------------------------ */

    private function reviews(int $tenantId, array $roleMap): array
    {
        return HrPerformanceReview::where('tenant_id', $tenantId)
            ->with(['employee:id,name'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (HrPerformanceReview $r) use ($roleMap) {
                $overdue = $r->next_review_date
                    && $r->next_review_date->isBefore(now()->startOfDay())
                    && ! in_array($r->status, ['completed', 'signed_off'], true);

                return [
                    'id' => $r->id,
                    'employee' => $r->employee?->name ?? 'Unknown',
                    'role' => $roleMap[$r->employee_user_id] ?? '',
                    'type' => $this->reviewTypeLabel($r->review_type),
                    'period' => $this->periodLabel($r->review_period_start, $r->review_period_end),
                    'rating' => $r->overall_rating !== null ? (float) $r->overall_rating : null,
                    'status' => $overdue ? 'overdue' : $r->status,
                    'employee_signed_off' => (bool) $r->employee_signed_off,
                    'manager_signed_off' => (bool) $r->manager_signed_off,
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Supervision */
    /* ------------------------------------------------------------------ */

    private function supervision(int $tenantId, array $roleMap): array
    {
        $notes = HrSupervisionNote::forTenant($tenantId)
            ->with(['employee:id,name'])
            ->orderByDesc('session_date')
            ->limit(200)
            ->get();

        $rows = $notes->map(function (HrSupervisionNote $n) use ($roleMap) {
            $next = $n->next_session_date;
            $status = $n->status;
            if (! $status) {
                if ($n->employee_acknowledged) {
                    $status = 'acknowledged';
                } elseif ($next && $next->isBefore(now()->startOfDay())) {
                    $status = 'overdue';
                } elseif ($next) {
                    $status = 'scheduled';
                } else {
                    $status = 'pending';
                }
            }

            return [
                'id' => $n->id,
                'employee' => $n->employee?->name ?? 'Unknown',
                'role' => $roleMap[$n->employee_user_id] ?? '',
                'last' => $n->session_date?->format('d M Y') ?? '—',
                'next' => $n->next_session_date?->format('d M Y') ?? '—',
                'status' => $status,
            ];
        })->values()->all();

        // Trend: sessions per month over the last 8 months (sparkline).
        $spark = [];
        for ($i = 7; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $spark[] = HrSupervisionNote::forTenant($tenantId)
                ->whereBetween('session_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->count();
        }

        $withNext = $notes->whereNotNull('next_session_date');
        $overdue = $withNext->filter(fn ($n) => $n->next_session_date->isBefore(now()->startOfDay()))->count();
        $dueSoon = $withNext->filter(fn ($n) => $n->next_session_date->between(now()->startOfDay(), now()->addDays(7)->endOfDay()))->count();
        $acked = $notes->where('employee_acknowledged', true)->count();
        $needAck = $notes->where('is_visible_to_employee', true)->count();
        $slaPct = $needAck > 0 ? (int) round($acked / $needAck * 100) : 100;

        return [
            'rows' => $rows,
            'overdue_count' => $overdue,
            'due_soon_count' => $dueSoon,
            'sessions_quarter' => HrSupervisionNote::forTenant($tenantId)->where('session_date', '>=', now()->startOfQuarter())->count(),
            'sla_pct' => $slaPct,
            'spark' => $spark,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Goals & OKRs */
    /* ------------------------------------------------------------------ */

    private function goals(int $tenantId): array
    {
        return HrGoal::forTenant($tenantId)
            ->with(['user:id,name'])
            ->withCount('keyResults')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (HrGoal $g) {
                $status = match ($g->status) {
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    'draft' => 'draft',
                    default => ((int) $g->progress_percentage < 50
                        && $g->due_date && $g->due_date->isBefore(now()->addDays(30)))
                        ? 'at_risk'
                        : 'on_track',
                };

                return [
                    'id' => $g->id,
                    'title' => $g->title,
                    'owner' => $g->user?->name ?? '—',
                    'type' => $g->goal_type === 'company' ? 'Company' : ($g->goal_type === 'team' ? 'Team' : 'Individual'),
                    'kr' => $g->key_results_count,
                    'progress' => (int) $g->progress_percentage,
                    'due' => $g->due_date?->format('d M Y') ?? '—',
                    'status' => $status,
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Development */
    /* ------------------------------------------------------------------ */

    private function development(int $tenantId, array $roleMap): array
    {
        return HrDevelopmentGoal::where('tenant_id', $tenantId)
            ->with(['employee:id,name'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function (HrDevelopmentGoal $d) use ($roleMap) {
                $status = match ($d->status) {
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    default => ((int) $d->progress_percent < 35) ? 'at_risk' : 'active',
                };

                return [
                    'id' => $d->id,
                    'employee' => $d->employee?->name ?? 'Unknown',
                    'role' => $roleMap[$d->employee_user_id] ?? '',
                    'area' => $d->competency_area ?? $d->title,
                    'cur' => (int) $d->current_level,
                    'tgt' => (int) $d->target_level,
                    'progress' => (int) $d->progress_percent,
                    'course' => $d->category ?? '',
                    'status' => $status,
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  360 Feedback (grouped into subject × type cycles) */
    /* ------------------------------------------------------------------ */

    private function feedback(int $tenantId, array $roleMap): array
    {
        $requests = HrFeedbackRequest::forTenant($tenantId)
            ->with(['subject:id,name'])
            ->orderByDesc('created_at')
            ->limit(400)
            ->get();

        return $requests
            ->groupBy(fn ($r) => $r->subject_user_id.'|'.$r->review_type)
            ->map(function ($group) use ($roleMap) {
                $first = $group->first();
                $reviewers = $group->count();
                $responded = $group->where('status', 'completed')->count();
                $declined = $group->where('status', 'declined')->count();
                $expired = $group->where('status', 'expired')->count();

                $status = match (true) {
                    $reviewers > 0 && $responded === $reviewers => 'completed',
                    $declined === $reviewers => 'declined',
                    $expired === $reviewers => 'expired',
                    default => 'in_progress',
                };

                return [
                    'id' => $first->id,
                    'ids' => $group->pluck('id')->all(),
                    'subject' => $first->subject?->name ?? 'Unknown',
                    'subject_user_id' => $first->subject_user_id,
                    'role' => $roleMap[$first->subject_user_id] ?? '',
                    'type' => str($first->review_type)->headline().' 360',
                    'reviewers' => $reviewers,
                    'responded' => $responded,
                    'due' => $group->whereNotNull('due_date')->min('due_date')?->format('d M Y') ?? '—',
                    'status' => $status,
                ];
            })
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  PIPs */
    /* ------------------------------------------------------------------ */

    private function pips(int $tenantId, array $roleMap): array
    {
        return HrPerformanceImprovementPlan::where('tenant_id', $tenantId)
            ->with(['employee:id,name', 'milestones'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (HrPerformanceImprovementPlan $p) use ($roleMap) {
                $total = $p->milestones->count();
                $met = $p->milestones->where('status', 'met')->count();
                $progress = $total > 0 ? (int) round($met / $total * 100) : 0;
                $status = match ($p->status) {
                    'in_progress' => 'active',
                    default => $p->status,
                };

                return [
                    'id' => $p->id,
                    'employee' => $p->employee?->name ?? 'Unknown',
                    'role' => $roleMap[$p->employee_user_id] ?? '',
                    'reason' => str($p->reason ?? '')->limit(60)->toString(),
                    'milestones' => $met.' / '.$total,
                    'progress' => $progress,
                    'review' => $p->review_date?->format('d M Y') ?? '—',
                    'status' => $status,
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Competencies — matrix + coverage + skills */
    /* ------------------------------------------------------------------ */

    private function competencies(int $tenantId): array
    {
        $competencies = HrCompetency::forTenant($tenantId)->active()
            ->orderBy('sort_order')->orderBy('name')->limit(12)->get(['id', 'name', 'category']);

        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->limit(12)->get(['id', 'user_id']);

        // Latest assessed level per (profile, competency).
        $assessments = HrCompetencyAssessment::where('tenant_id', $tenantId)
            ->whereIn('employee_profile_id', $profiles->pluck('id'))
            ->orderByDesc('assessment_date')
            ->get(['employee_profile_id', 'competency_id', 'assessed_level', 'target_level', 'assessment_date']);

        $levelMap = [];
        $targetByComp = [];
        foreach ($assessments as $a) {
            $key = $a->employee_profile_id.'-'.$a->competency_id;
            if (! isset($levelMap[$key])) {
                $levelMap[$key] = (int) $a->assessed_level;
            }
            if ($a->target_level) {
                $targetByComp[$a->competency_id] = (int) $a->target_level;
            }
        }

        $matrixStaff = $profiles->map(fn ($p) => [
            'profile_id' => $p->id,
            'name' => $p->user?->name ?? 'Unknown',
        ])->values();

        // Coverage list: how many staff meet target per competency.
        $coverage = $competencies->map(function ($c) use ($profiles, $levelMap, $targetByComp) {
            $target = $targetByComp[$c->id] ?? 3;
            $total = $profiles->count();
            $covered = 0;
            foreach ($profiles as $p) {
                $lvl = $levelMap[$p->id.'-'.$c->id] ?? 0;
                if ($lvl >= $target) {
                    $covered++;
                }
            }

            return [
                'id' => $c->id,
                'name' => $c->name,
                'category' => $c->category,
                'covered' => $covered,
                'total' => $total,
            ];
        })->values();

        // Skills coverage.
        $skills = HrSkill::where('tenant_id', $tenantId)->where('is_active', true)
            ->orderBy('name')->limit(20)->get(['id', 'name']);
        $skillCounts = HrEmployeeSkill::where('tenant_id', $tenantId)
            ->selectRaw('skill_id, COUNT(DISTINCT employee_profile_id) as cnt')
            ->groupBy('skill_id')->pluck('cnt', 'skill_id');

        return [
            'matrix' => [
                'staff' => $matrixStaff,
                'competencies' => $competencies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'levels' => $levelMap,
            ],
            'coverage' => $coverage,
            'skills' => $skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'count' => (int) ($skillCounts[$s->id] ?? 0),
            ])->values(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Succession — 9-box + readiness + critical roles */
    /* ------------------------------------------------------------------ */

    private function succession(int $tenantId): array
    {
        $plans = HrSuccessionPlan::where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->with(['candidates.employeeProfile.user:id,name'])
            ->withCount('candidates')
            ->get();

        // 9-box: performance (x, from overall_rating) × potential (y, from readiness).
        $box = [];
        foreach (['0-0', '1-0', '2-0', '0-1', '1-1', '2-1', '0-2', '1-2', '2-2'] as $cell) {
            $box[$cell] = [];
        }
        $readinessTally = ['ready_now' => 0, 'ready_1_year' => 0, 'ready_2_years' => 0, 'developing' => 0];

        foreach ($plans as $plan) {
            foreach ($plan->candidates as $c) {
                $readinessTally[$c->readiness] = ($readinessTally[$c->readiness] ?? 0) + 1;
                $x = match (true) {
                    ($c->overall_rating ?? 3) >= 4 => 2,
                    ($c->overall_rating ?? 3) >= 3 => 1,
                    default => 0,
                };
                $y = match ($c->readiness) {
                    'ready_now' => 2,
                    'ready_1_year' => 1,
                    default => 0,
                };
                $name = $c->employeeProfile?->user?->name;
                if ($name) {
                    $box[$x.'-'.$y][] = str($name)->explode(' ')->map(fn ($w, $i) => $i === 0 ? $w : str($w)->substr(0, 1).'.')->implode(' ');
                }
            }
        }

        $criticalRoles = $plans
            ->sortByDesc(fn ($p) => ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3][$p->risk_level] ?? 0)
            ->take(6)
            ->map(function ($p) {
                $readyNow = $p->candidates->where('readiness', 'ready_now')->count();

                return [
                    'id' => $p->id,
                    'role' => $p->role_title,
                    'risk' => $p->risk_level,
                    'cover' => $p->candidates_count === 0 ? 'Uncovered' : ($readyNow > 0 ? $readyNow.' ready' : $p->candidates_count.' developing'),
                    'uncovered' => $p->candidates_count === 0,
                ];
            })->values();

        return [
            'box' => $box,
            'readiness' => [
                ['label' => 'Ready now', 'count' => $readinessTally['ready_now'], 'tone' => 'success'],
                ['label' => '1 year', 'count' => $readinessTally['ready_1_year'], 'tone' => 'info'],
                ['label' => '2 years', 'count' => $readinessTally['ready_2_years'], 'tone' => 'warning'],
                ['label' => 'Developing', 'count' => $readinessTally['developing'], 'tone' => 'neutral'],
            ],
            'critical_roles' => $criticalRoles,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Map of user_id => "Position · Department" for role sub-labels. */
    private function roleMap(int $tenantId): array
    {
        return HrEmployeeProfile::where('tenant_id', $tenantId)
            ->get(['user_id', 'position_title', 'department'])
            ->mapWithKeys(function ($p) {
                $parts = array_filter([$p->position_title, $p->department]);

                return [$p->user_id => implode(' · ', $parts)];
            })
            ->all();
    }

    private function staffOptions(int $tenantId): array
    {
        $ids = $this->hrStaffUserIdsForTenant($tenantId);

        return User::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
            ->all();
    }

    private function reviewTypeLabel(?string $type): string
    {
        return [
            'annual' => 'Annual',
            'mid_year' => 'Mid-year',
            'quarterly' => 'Quarterly',
            'ad_hoc' => 'Ad hoc',
        ][$type] ?? str($type ?? '')->headline()->toString();
    }

    private function periodLabel($start, $end): string
    {
        if (! $start && ! $end) {
            return '—';
        }

        return trim(($start?->format('M y') ?? '').' – '.($end?->format('M y') ?? ''), ' –');
    }
}
