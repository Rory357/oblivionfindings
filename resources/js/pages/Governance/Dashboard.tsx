import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CalendarPlus,
    Landmark,
    ListFilter,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
    assuranceAttention,
    assuranceExtras,
    assuranceVisibility,
} from '@/components/governance/AssuranceSummaryPanel';
import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { CockpitSkeleton } from '@/components/governance/CockpitSkeleton';
import { GovernanceHomeRail } from '@/components/governance/GovernanceHomeRail';
import type {
    MyWorkPreview,
    MyWorkTotals,
} from '@/components/governance/MyNextActionsRail';
import type {
    PriorityPagination,
    TabKey,
} from '@/components/governance/PriorityOverviewPanel';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/datetime';
import { canDoGovernance } from '@/lib/governance-permissions';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { PageProps } from '@/types';

import {
    CockpitLayout,
    type CockpitPayload,
    type HomeView,
} from './Cockpit/CockpitLayout';

interface DashboardPayload {
    snapshot_id: number | null;
    workflow: {
        summary: {
            total: number;
            critical: number;
            overdue: number;
            by_tab?: Record<TabKey, number>;
        };
        actions: WorkflowAction[];
        pagination?: PriorityPagination | null;
    };
    work_totals?: MyWorkTotals | null;
    my_work?: MyWorkPreview | null;
    cockpit: CockpitPayload;
    captured_at?: string;
}

type Props = PageProps & {
    isBoardMember: boolean;
    boardRole?: string;
    workTotals?: MyWorkTotals | null;
};

const VIEW_OPTIONS: { value: HomeView; label: string }[] = [
    { value: 'all', label: 'Show everything' },
    { value: 'mine', label: 'Needs my action' },
    { value: 'board', label: 'Board-wide' },
];

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

/**
 * Governance Home — one page for board members and the people who run the
 * board. The header carries My work, the next meeting and one "Needs the
 * board's attention" meter (plus board priorities for meeting managers),
 * each opening the place its number lives. The body (`CockpitLayout`) leads
 * with Next meeting and My work, then Board assurance and board priorities.
 */
export default function GovernanceDashboard({ auth, workTotals }: Props) {
    const [view, setView] = useState<HomeView>('all');
    const [searchQuery, setSearchQuery] = useState('');
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const activeRequestRef = useRef(0);
    const payloadRef = useRef<DashboardPayload | null>(null);

    // `fresh` bypasses the server-side cache so Refresh always returns
    // just-computed figures. A failure after a successful load keeps the
    // earlier figures on screen and says so.
    const load = useCallback(async (fresh: boolean) => {
        const reqId = ++activeRequestRef.current;
        setLoading(true);
        setRefreshError(null);
        try {
            const response = await axios.get<DashboardPayload>(
                dashboardData.url(),
                { params: fresh ? { period: 'month', fresh: 1 } : { period: 'month' } },
            );
            if (reqId !== activeRequestRef.current) return;
            payloadRef.current = response.data;
            setPayload(response.data);
            setError(null);
        } catch {
            if (reqId !== activeRequestRef.current) return;
            const previous = payloadRef.current;
            if (!previous) {
                setError("Board information couldn't be loaded.");
            } else {
                setRefreshError(
                    `Refresh didn't work — showing figures from ${formatTime(previous.captured_at ?? null, 'earlier')}.`,
                );
            }
        } finally {
            if (reqId === activeRequestRef.current) {
                setLoading(false);
            }
        }
    }, []);

    useEffect(() => {
        void load(false);
    }, [load]);

    const refresh = () => void load(true);

    const workflow = payload?.workflow;
    const cockpit = payload?.cockpit;
    const permissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;

    const isManager = canDoGovernance(permissions, 'meetings', 'manage');
    const canViewMeetings = canDoGovernance(permissions, 'meetings', 'view');
    const canViewRecords = canDoGovernance(permissions, 'view');

    // My work: the same authorised totals as /governance/my-work (upcoming
    // meetings are never counted as work to do).
    const myWork = payload?.my_work;
    const myWorkTotals: MyWorkTotals | null = payload
        ? (myWork?.totals ?? payload.work_totals ?? null)
        : (workTotals ?? null);
    const pending = myWorkTotals?.pending ?? null;
    const overdue = myWorkTotals?.overdue ?? 0;

    const nextMeeting = cockpit?.next_meeting ?? null;
    const meetingHref =
        nextMeeting?.member_readiness?.workspace_href ??
        nextMeeting?.meeting.href ??
        null;

    // One source for the meter and the Board assurance headline.
    const assurance = cockpit?.assurance ?? null;
    const visibility = assurance ? assuranceVisibility(assurance, permissions) : null;
    const extras = isManager ? assuranceExtras(cockpit?.cards_by_key) : [];
    const attention =
        assurance && visibility
            ? assuranceAttention(assurance, visibility, extras)
            : null;
    const showAttentionMeter =
        !payload ||
        (visibility !== null &&
            (Object.values(visibility).some(Boolean) || extras.length > 0));
    const seriousAttention =
        (visibility?.risks && (assurance?.risks_above_appetite.count ?? 0) > 0) ||
        (visibility?.compliance && (assurance?.obligations_overdue.count ?? 0) > 0);

    const scrollToSection = (id: string) => {
        setView('all');
        window.setTimeout(() => {
            document.getElementById(id)?.scrollIntoView({ block: 'start' });
        }, 0);
    };

    const primaryAction = meetingHref
        ? { label: 'Prepare for meeting', href: meetingHref, icon: undefined }
        : isManager
          ? { label: 'Schedule meeting', href: '/governance/meetings/create', icon: CalendarPlus }
          : null;

    const subline = [
        'What you need to do, your next meeting, and anything the board must know',
        payload?.captured_at ? `Updated ${formatTime(payload.captured_at)}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const priorityTotal = workflow?.summary.total ?? null;
    const priorityOverdue = workflow?.summary.overdue ?? 0;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
            ]}
        >
            <Head title="Governance" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="index"
                        icon={Landmark}
                        title="Governance"
                        titleDusk="governance-cockpit-heading"
                        subline={subline}
                        actions={
                            <>
                                {canViewRecords ? (
                                    <PageHeaderSearch
                                        value={searchQuery}
                                        onChange={setSearchQuery}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && searchQuery.trim()) {
                                                router.visit(
                                                    `/governance/records?search=${encodeURIComponent(searchQuery.trim())}`,
                                                );
                                            }
                                        }}
                                        placeholder="Search governance records…"
                                    />
                                ) : null}
                                <PageHeaderGlassButton
                                    icon={RefreshCw}
                                    onClick={refresh}
                                    disabled={loading}
                                    aria-label="Refresh the figures on this page"
                                >
                                    {loading ? 'Refreshing' : 'Refresh'}
                                </PageHeaderGlassButton>
                                {primaryAction ? (
                                    <PageHeaderPrimaryButton
                                        icon={primaryAction.icon}
                                        onClick={() => router.visit(primaryAction.href)}
                                        data-dusk="governance-home-primary"
                                    >
                                        {primaryAction.label}
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="My work"
                                    href="/governance/my-work"
                                    tone={overdue > 0 ? 'critical' : pending ? 'warning' : 'brand'}
                                    ariaLabel="Open My work"
                                >
                                    <PageHeaderMeterBig>{pending ?? '—'}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {pending === null
                                            ? payload
                                                ? 'Not available'
                                                : 'Loading'
                                            : overdue > 0
                                              ? `${overdue} overdue`
                                              : pending > 0
                                                ? `${plural(pending, 'thing', 'things')} for you to do`
                                                : 'Nothing to do'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Next meeting"
                                    href={
                                        meetingHref ??
                                        (canViewMeetings ? '/governance/meetings' : '/governance/calendar')
                                    }
                                    ariaLabel={
                                        nextMeeting
                                            ? `Open ${nextMeeting.meeting.title}`
                                            : 'View meetings'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {nextMeeting
                                            ? formatDate(nextMeeting.meeting.scheduled_at, 'To be confirmed')
                                            : '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {!payload
                                            ? 'Loading'
                                            : nextMeeting
                                              ? [
                                                    formatTime(nextMeeting.meeting.scheduled_at, ''),
                                                    nextMeeting.meeting.days_until === null
                                                        ? null
                                                        : nextMeeting.meeting.days_until <= 0
                                                          ? 'today'
                                                          : nextMeeting.meeting.days_until === 1
                                                            ? 'tomorrow'
                                                            : `in ${nextMeeting.meeting.days_until} days`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')
                                              : 'None scheduled'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {showAttentionMeter ? (
                                    <PageHeaderMeterBlock
                                        label="Needs the board's attention"
                                        tone={
                                            attention && attention.total > 0
                                                ? seriousAttention
                                                    ? 'critical'
                                                    : 'warning'
                                                : 'brand'
                                        }
                                        onClick={() => scrollToSection('board-assurance')}
                                        ariaLabel="View board assurance"
                                    >
                                        <PageHeaderMeterBig>
                                            {!attention || attention.checked === 0
                                                ? '—'
                                                : attention.total}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {!attention
                                                ? payload
                                                    ? 'Not available'
                                                    : 'Loading'
                                                : attention.parts.length > 0
                                                  ? attention.parts.length === 1
                                                      ? attention.parts[0]
                                                      : `${attention.parts[0]} + ${attention.parts.length - 1} more`
                                                  : attention.unavailable > 0
                                                    ? 'Some figures not available'
                                                    : 'Nothing flagged'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {isManager ? (
                                    <PageHeaderMeterBlock
                                        label="Board priorities"
                                        tone={priorityOverdue > 0 ? 'critical' : 'brand'}
                                        onClick={() => scrollToSection('board-priorities')}
                                        ariaLabel="View board priorities"
                                    >
                                        <PageHeaderMeterBig>{priorityTotal ?? '—'}</PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {priorityTotal === null
                                                ? payload
                                                    ? 'Not available'
                                                    : 'Loading'
                                                : priorityOverdue > 0
                                                  ? `${priorityOverdue} overdue`
                                                  : 'None overdue'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                icon={ListFilter}
                                label="Show everything"
                                value={view}
                                allValue="all"
                                options={VIEW_OPTIONS}
                                onChange={(value) => setView(value as HomeView)}
                            />
                        }
                        rail={
                            <GovernanceHomeRail
                                value="overview"
                                myWorkCount={pending}
                            />
                        }
                    />
                }
            >
                {error && !payload ? (
                    <Card data-dusk="dashboard-error">
                        <CardContent>
                            <ErrorState
                                title="Board information couldn't be loaded"
                                message="Nothing has been marked as done. Try again in a few minutes."
                                onRetry={loading ? undefined : refresh}
                            />
                        </CardContent>
                    </Card>
                ) : !payload || !cockpit || !workflow ? (
                    <CockpitSkeleton />
                ) : (
                    <div className="flex flex-col gap-5">
                        {refreshError ? (
                            <div
                                role="alert"
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-2.5 text-sm text-status-warning"
                                data-dusk="refresh-failed-banner"
                            >
                                <span className="inline-flex items-center gap-2">
                                    <AlertTriangle
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {refreshError}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={refresh}
                                    disabled={loading}
                                >
                                    {loading ? 'Trying again…' : 'Try again'}
                                </Button>
                            </div>
                        ) : null}
                        <CockpitLayout
                            cockpit={cockpit}
                            workflow={workflow}
                            myWork={myWork}
                            permissions={permissions}
                            view={view}
                            onRefresh={refresh}
                        />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
