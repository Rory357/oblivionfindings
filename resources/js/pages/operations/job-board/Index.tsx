import { Head, router } from '@inertiajs/react';
import { Briefcase } from 'lucide-react';
import { useMemo } from 'react';
import { toast } from 'sonner';

import { JobBoardHero } from '@/components/job-board/job-board-hero';
import { JobCard } from '@/components/job-board/job-card';
import { ScopeTabs } from '@/components/job-board/scope-tabs';
import { SmartMatchesStrip } from '@/components/job-board/smart-matches-strip';
import type {
    JobBoardScope,
    JobBoardStats,
    JobBoardViewer,
    JobBoardWeek,
    JobPost,
} from '@/components/job-board/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

type PaginatedJobs = {
    data: JobPost[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

interface Props {
    jobs?: PaginatedJobs;
    filters?: {
        q?: string;
        status?: string;
        scope?: string;
        date_range?: string;
        skill?: string;
        fit?: string;
        week?: string;
    };
    available_skills?: string[];
    stats?: JobBoardStats;
    week?: JobBoardWeek;
    viewer?: JobBoardViewer;
}

const SCOPE_VALUES: JobBoardScope[] = [
    'for-you',
    'all',
    'mine',
    'replacements',
    'approvals',
];

function resolveScope(raw: string | undefined): JobBoardScope {
    if (raw && (SCOPE_VALUES as string[]).includes(raw)) {
        return raw as JobBoardScope;
    }
    return 'for-you';
}

function strongMatchPredicate(job: JobPost): boolean {
    if (job.status !== 'open') return false;
    if (!job.viewer_eligibility?.is_eligible) return false;
    const skillsTotal = job.required_skills.length;
    const skillsMatch = job.your_skills?.length ?? 0;
    if (skillsTotal > 0 && skillsMatch < skillsTotal) return false;
    if (!job.your_schedule?.free) return false;
    if (
        job.your_schedule.conflict ||
        job.your_schedule.fatigue ||
        job.your_schedule.time_off
    ) {
        return false;
    }
    return true;
}

export default function JobBoardIndex({
    jobs = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    available_skills = [],
    stats,
    week,
    viewer,
}: Props) {
    const scope = resolveScope(filters.scope);
    const firstName = viewer?.first_name ?? 'there';
    const effectiveStats: JobBoardStats = stats ?? {
        open: 0,
        claimed: 0,
        filled_today: 0,
        eligible_for_you: 0,
        expiring_soon: 0,
        mine: 0,
        replacements: 0,
        pending_approval: 0,
        sites: 0,
        sites_worked_this_week: 0,
    };

    const fallbackWeek: JobBoardWeek = useMemo(() => {
        if (week) return week;
        const today = new Date();
        const monday = new Date(today);
        const day = (monday.getDay() + 6) % 7;
        monday.setDate(monday.getDate() - day);
        monday.setHours(0, 0, 0, 0);
        const sunday = new Date(monday);
        sunday.setDate(sunday.getDate() + 6);
        const toISO = (d: Date) => d.toISOString().slice(0, 10);
        const toLabel = (d: Date) =>
            d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
        const prev = new Date(monday);
        prev.setDate(prev.getDate() - 7);
        const next = new Date(monday);
        next.setDate(next.getDate() + 7);
        return {
            start: toISO(monday),
            end: toISO(sunday),
            start_label: toLabel(monday),
            end_label: toLabel(sunday),
            prev: toISO(prev),
            next: toISO(next),
            is_current: true,
        };
    }, [week]);

    const navigate = (next: Record<string, string | null | undefined>) => {
        const baseFilters: Record<string, string> = {};
        const merged: Record<string, string | null | undefined> = {
            ...filters,
            ...next,
        };

        for (const [key, value] of Object.entries(merged)) {
            if (value !== null && value !== undefined && value !== '') {
                baseFilters[key] = String(value);
            }
        }

        if (next.scope === 'mine') {
            delete baseFilters.status;
        }

        router.get('/operations/job-board', baseFilters, {
            preserveState: true,
            replace: true,
        });
    };

    const handleScopeChange = (nextScope: JobBoardScope) => {
        navigate({ scope: nextScope });
    };

    const handleWeekChange = (anchor: string) => {
        navigate({ week: anchor });
    };

    const handleFilterChange = (key: string, value: string | null) => {
        navigate({ [key]: value });
    };

    const handleClaim = (job: JobPost) => {
        router.post(
            `/operations/job-board/${job.id}/claim`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Claim submitted', {
                        description: `${job.title} — awaiting coordinator approval`,
                    });
                },
                onError: () => {
                    toast.error('Could not claim this shift', {
                        description: 'It may have been claimed by another worker.',
                    });
                },
            },
        );
    };

    const handleApprove = (job: JobPost) => {
        router.post(
            `/operations/job-board/${job.id}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Approved', {
                        description: `${job.claimed_by?.name ?? 'Worker'} confirmed for ${job.title}`,
                    });
                },
            },
        );
    };

    const counts: Record<JobBoardScope, number> = {
        'for-you': effectiveStats.eligible_for_you ?? 0,
        all: effectiveStats.open ?? 0,
        mine: effectiveStats.mine ?? 0,
        replacements: effectiveStats.replacements ?? 0,
        approvals: effectiveStats.pending_approval ?? 0,
    };

    const visibleJobs = jobs.data;

    const recommended = useMemo(
        () => visibleJobs.filter(strongMatchPredicate).slice(0, 3),
        [visibleJobs],
    );

    const sectionTitle =
        scope === 'for-you'
            ? 'All matches'
            : scope === 'mine'
              ? 'My claims'
              : scope === 'replacements'
                ? 'Replacement requests'
                : scope === 'approvals'
                  ? 'Claims awaiting your approval'
                  : 'All open positions';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Operations', href: '/operations' },
                { title: 'Job Board', href: '/operations/job-board' },
            ]}
        >
            <Head title="Job Board" />
            <div className="space-y-4 p-4">
                <JobBoardHero
                    firstName={firstName}
                    week={fallbackWeek}
                    stats={effectiveStats}
                    availableSkills={available_skills}
                    filters={{
                        q: filters.q,
                        date_range: filters.date_range,
                        skill: filters.skill,
                        fit: filters.fit,
                    }}
                    onFilterChange={handleFilterChange}
                    onWeekChange={handleWeekChange}
                    sitesCount={effectiveStats.sites}
                    sitesWorkedThisWeek={effectiveStats.sites_worked_this_week}
                />

                <ScopeTabs
                    scope={scope}
                    counts={counts}
                    onScopeChange={handleScopeChange}
                    showApprovals={!!viewer?.can_approve}
                />

                {scope === 'for-you' ? (
                    <SmartMatchesStrip
                        jobs={recommended}
                        totalMatches={effectiveStats.eligible_for_you}
                        onQuickClaim={handleClaim}
                    />
                ) : null}

                <section>
                    <header className="mb-3 flex items-center justify-between">
                        <h2 className="inline-flex items-center gap-2 text-[15px] font-bold tracking-tight">
                            {sectionTitle}
                            <span className="rounded-full bg-accent px-2 py-0.5 text-[11px] font-bold text-[var(--brand-deep,var(--primary))]">
                                {jobs.total ?? visibleJobs.length}
                            </span>
                        </h2>
                    </header>

                    <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(340px,1fr))]">
                        {visibleJobs.length === 0 ? (
                            <div className="col-span-full rounded-xl border border-dashed border-border bg-card p-14 text-center text-muted-foreground">
                                <Briefcase className="mx-auto mb-3 h-8 w-8 opacity-40" />
                                <h3 className="m-0 mb-1 text-base font-semibold text-foreground">
                                    No shifts match those filters
                                </h3>
                                <p className="m-0 text-sm">
                                    Try widening your date range, clearing a skill
                                    filter, or switching to "All open".
                                </p>
                            </div>
                        ) : (
                            visibleJobs.map((job) => (
                                <JobCard
                                    key={job.id}
                                    job={job}
                                    onClaim={handleClaim}
                                    onApprove={handleApprove}
                                />
                            ))
                        )}
                    </div>

                    {(jobs.last_page ?? 1) > 1 ? (
                        <div className="mt-4 flex items-center justify-center gap-1">
                            {(jobs.links ?? []).map((link, idx) => (
                                <Button
                                    key={idx}
                                    size="sm"
                                    variant={link.active ? 'default' : 'outline'}
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    ) : null}
                </section>
            </div>
        </AppLayout>
    );
}
