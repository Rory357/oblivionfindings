import { Head, router, usePage } from '@inertiajs/react';
import { Briefcase } from 'lucide-react';
import { useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { ListCaption } from '@/components/lists';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { BoardActionDialog, PostPositionDialog } from './_dialogs';
import { useAlertPreference, useBoardAction } from './_use-board-action';
import { useLeaveGuard } from './_use-leave-guard';
import { usePosting } from './_use-posting';
import { useBoardFilters, type BoardFilters } from './use-board-filters';

type PaginatedJobs = {
    data: JobPost[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

interface Props {
    jobs?: PaginatedJobs;
    filters?: BoardFilters;
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

/** Re-read the current query's list, counts and viewer together after a
 * confirmed (or unconfirmed-then-closed) result. It keeps the URL, filters,
 * typed search and scroll position. */
const REFRESH_PROPS = ['jobs', 'stats', 'viewer', 'provenance'];
function refreshBoard() {
    router.reload({ only: REFRESH_PROPS });
}

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

const EMPTY_COPY: Record<
    JobBoardScope,
    { title: string; description: string }
> = {
    'for-you': {
        title: 'No shifts to show',
        description:
            'Open shifts and whether you are eligible for them appear here.',
    },
    all: {
        title: 'No open shifts to show',
        description: 'Open shifts at the sites you can see appear here.',
    },
    mine: {
        title: 'No claims to show',
        description:
            'Shifts you have claimed, and ones recently filled for you, appear here.',
    },
    replacements: {
        title: 'No replacement requests to show',
        description: 'Shifts that need replacement cover appear here.',
    },
    approvals: {
        title: 'No claims waiting for approval',
        description: 'Claims on shifts at the sites you can see appear here.',
    },
};

const SECTION_TITLE: Record<JobBoardScope, string> = {
    'for-you': 'All matches',
    all: 'All open shifts',
    mine: 'My claims',
    replacements: 'Replacement requests',
    approvals: 'Claims awaiting your approval',
};

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
    const page = usePage().props as unknown as {
        auth?: { user?: { id?: number } | null };
    };
    const actor = Number(page.auth?.user?.id ?? 0);
    const query = useBoardFilters(
        filters,
        String(page.auth?.user?.id ?? 'signed-out'),
    );
    const scope = resolveScope(filters.scope);
    const firstName = viewer?.first_name ?? 'there';
    // Default-deny: a control appears only when its flag is exactly true.
    const signedIn = Number.isSafeInteger(actor) && actor > 0;
    const canClaim = signedIn && viewer?.can_claim === true;
    const canApprove = signedIn && viewer?.can_approve === true;
    const canPost = signedIn && viewer?.can_post_position === true;
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

    // One page-level owner for every write on this page. The dialogs stay
    // mounted and read these, so closing one never loses an unconfirmed result.
    const [postingOpen, setPostingOpen] = useState(false);
    const posting = usePosting({
        open: postingOpen,
        actor,
        allowed: canPost,
        week: fallbackWeek.start,
        onConfirmed: refreshBoard,
    });
    const action = useBoardAction({
        actor,
        onConfirmed: refreshBoard,
        onSettled: refreshBoard,
    });
    const alerts = useAlertPreference({ actor, onSettled: refreshBoard });
    const postingUnconfirmed = posting.outcome?.status === 'unknown';
    const sending = posting.pending || action.pending || alerts.pending;
    const guard = useLeaveGuard(
        sending ||
            postingUnconfirmed ||
            action.unresolved ||
            (postingOpen && posting.dirty),
    );

    const handleScopeChange = (nextScope: JobBoardScope) => {
        query.change({ scope: nextScope });
    };

    const handleWeekChange = (anchor: string) => {
        query.change({ week: anchor });
    };

    const handleFilterChange = (key: string, value: string | null) => {
        if (key === 'q') query.editSearch(value ?? '');
        else query.change({ [key]: value ?? undefined });
    };

    const startClaim = (job: JobPost) =>
        action.start({ action: 'claim', positionId: job.id });
    const startApprove = (job: JobPost) =>
        action.start({ action: 'approve', positionId: job.id });

    const counts: Record<JobBoardScope, number> = {
        'for-you': effectiveStats.eligible_for_you ?? 0,
        all: effectiveStats.open ?? 0,
        mine: effectiveStats.mine ?? 0,
        replacements: effectiveStats.replacements ?? 0,
        approvals: effectiveStats.pending_approval ?? 0,
    };

    const visibleJobs = jobs.data;
    const total = jobs.total ?? visibleJobs.length;

    const recommended = useMemo(
        () => visibleJobs.filter(strongMatchPredicate).slice(0, 3),
        [visibleJobs],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: '/operations/job-board' },
                { title: 'Available shifts', href: '/operations/job-board' },
            ]}
        >
            <Head title="Available shifts" />
            <div className="flex flex-col gap-5">
                <JobBoardHero
                    rail={
                        <ScopeTabs
                            scope={scope}
                            counts={counts}
                            onScopeChange={handleScopeChange}
                            showApprovals={canApprove}
                        />
                    }
                    onScopeChange={handleScopeChange}
                    canApprove={canApprove}
                    firstName={firstName}
                    week={fallbackWeek}
                    stats={effectiveStats}
                    availableSkills={available_skills}
                    filters={{
                        q: query.draft.q,
                        date_range: query.draft.date_range,
                        skill: query.draft.skill,
                        fit: query.draft.fit,
                    }}
                    onSearchSubmit={() => query.change()}
                    loading={query.loading}
                    readNotice={
                        query.loading
                            ? 'Updating results. The previous results remain visible.'
                            : (query.error ??
                              ((query.draft.q ?? '') !== (filters.q ?? '') &&
                              !query.loading
                                  ? 'Search changed. Select Search to refresh the shifts.'
                                  : null))
                    }
                    onFilterChange={handleFilterChange}
                    onWeekChange={handleWeekChange}
                    onAlertMe={
                        signedIn
                            ? () =>
                                  void alerts.setAlerts(!viewer?.alerts_enabled)
                            : undefined
                    }
                    alertsEnabled={!!viewer?.alerts_enabled}
                    alertsBusy={alerts.pending}
                    canPostPosition={canPost}
                    onPostPosition={() => setPostingOpen(true)}
                    sitesCount={effectiveStats.sites}
                    sitesWorkedThisWeek={effectiveStats.sites_worked_this_week}
                />

                {scope === 'for-you' ? (
                    <SmartMatchesStrip
                        jobs={recommended}
                        totalMatches={effectiveStats.eligible_for_you}
                        onQuickClaim={canClaim ? startClaim : undefined}
                    />
                ) : null}

                <section
                    aria-busy={query.loading}
                    className="flex flex-col gap-5"
                >
                    <ListCaption
                        title={SECTION_TITLE[scope]}
                        caption={`${visibleJobs.length} of ${total} shown`}
                    />

                    {visibleJobs.length === 0 ? (
                        <EmptyState
                            icon={Briefcase}
                            title={EMPTY_COPY[scope].title}
                            description={`${EMPTY_COPY[scope].description} If you expected a shift here, try clearing the search or filters.`}
                            className="bg-card"
                        />
                    ) : (
                        <div className="grid grid-cols-1 gap-5 md:[grid-template-columns:repeat(auto-fill,minmax(340px,1fr))]">
                            {visibleJobs.map((job) => (
                                <JobCard
                                    key={job.id}
                                    job={job}
                                    onClaim={canClaim ? startClaim : undefined}
                                    onApprove={
                                        canApprove ? startApprove : undefined
                                    }
                                />
                            ))}
                        </div>
                    )}

                    <LaravelPagination
                        links={jobs.links ?? []}
                        lastPage={jobs.last_page}
                        disabled={query.loading}
                        onPage={(next) =>
                            query.goPage(`/operations/job-board?page=${next}`)
                        }
                    />
                </section>
            </div>

            <PostPositionDialog
                open={postingOpen && canPost}
                onClose={() => setPostingOpen(false)}
                form={posting}
                week={fallbackWeek.start}
            />
            <BoardActionDialog action={action} />
            <ConfirmDialog
                open={guard.asking}
                variant="default"
                title="Leave this page?"
                description={
                    sending
                        ? "A request is still being sent. Leaving won't stop or undo it, and you won't see whether it saved."
                        : postingUnconfirmed || action.unresolved
                          ? "We couldn't confirm your last request. If you leave, this page forgets it. Leaving doesn't undo anything that saved."
                          : "Your posting entries haven't been posted yet and will be lost."
                }
                cancelText="Stay here"
                confirmText="Leave page"
                onClose={guard.stay}
                onConfirm={guard.leave}
            />
        </AppLayout>
    );
}
