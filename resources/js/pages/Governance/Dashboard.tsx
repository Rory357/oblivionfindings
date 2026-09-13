import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CalendarPlus, Landmark, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { GovernanceHomeRail } from '@/components/governance/GovernanceHomeRail';
import type { MyWorkPreview, MyWorkTotals } from '@/components/governance/MyNextActionsRail';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTimeLong, formatTime } from '@/lib/datetime';
import { canDoGovernance } from '@/lib/governance-permissions';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { PageProps } from '@/types';

import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { CockpitSkeleton } from '@/components/governance/CockpitSkeleton';
import type { PriorityPagination } from '@/components/governance/PriorityOverviewPanel';
import { CockpitLayout, type CockpitPayload } from './Cockpit/CockpitLayout';

interface DashboardPayload {
    snapshot_id: number | null;
    workflow: {
        summary: { total: number; critical: number; overdue: number };
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

type Period = 'today' | 'week' | 'month' | 'year';

const PERIOD_OPTIONS: { value: Period; label: string }[] = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
    { value: 'year', label: 'This year' },
];

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

/**
 * Governance Home — the board member's primary page. The header carries the
 * member's key facts (My work, next meeting, board assurance) as meter
 * blocks that link to the views listing exactly those records; the body
 * (`CockpitLayout`) leads with Next meeting and My work, then board-wide
 * priorities and a concise assurance summary.
 */
export default function GovernanceDashboard({
    auth,
    boardRole,
    workTotals,
}: Props) {
    const [period, setPeriod] = useState<Period>('month');
    const [searchQuery, setSearchQuery] = useState('');
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const activeRequestRef = useRef(0);
    const payloadRef = useRef<DashboardPayload | null>(null);

    // `fresh` bypasses the server-side dashboard cache so Refresh always
    // returns just-computed numbers. A failure after a successful load keeps
    // the earlier data on screen and says so.
    const load = useCallback(async (selectedPeriod: Period, fresh: boolean) => {
        const reqId = ++activeRequestRef.current;
        setLoading(true);
        setRefreshError(null);
        try {
            const response = await axios.get<DashboardPayload>(
                dashboardData.url(),
                { params: fresh ? { period: selectedPeriod, fresh: 1 } : { period: selectedPeriod } },
            );
            if (reqId !== activeRequestRef.current) return;
            payloadRef.current = response.data;
            setPayload(response.data);
            setError(null);
        } catch {
            if (reqId !== activeRequestRef.current) return;
            const previous = payloadRef.current;
            if (!previous) {
                setError('Board information could not be loaded.');
            } else {
                setRefreshError(
                    `Refresh failed — showing information captured at ${formatTime(previous.captured_at ?? null, 'an earlier time')}.`,
                );
            }
        } finally {
            if (reqId === activeRequestRef.current) {
                setLoading(false);
            }
        }
    }, []);

    useEffect(() => {
        void load(period, false);
    }, [load, period]);

    const refresh = () => void load(period, true);

    const workflow = payload?.workflow;
    const cockpit = payload?.cockpit;
    const permissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;

    const canManageMeetings = canDoGovernance(permissions, 'meetings', 'manage');
    const canViewMeetings = canDoGovernance(permissions, 'meetings', 'view');
    const canViewRecords = canDoGovernance(permissions, 'view');
    const canViewRisks = canDoGovernance(permissions, 'risks', 'view');
    const canViewCompliance = canDoGovernance(permissions, 'compliance', 'view');
    const canViewActions = canDoGovernance(permissions, 'actions', 'view');

    // My work: the same authorised totals as /governance/my-work.
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
    const assurance = cockpit?.assurance ?? null;

    const primaryAction = (() => {
        if (canManageMeetings) {
            return meetingHref
                ? { label: 'Prepare meeting', href: meetingHref, icon: undefined }
                : { label: 'Schedule meeting', href: '/governance/meetings/create', icon: CalendarPlus };
        }
        return meetingHref
            ? { label: 'Prepare for meeting', href: meetingHref, icon: undefined }
            : { label: 'Open My work', href: '/governance/my-work', icon: undefined };
    })();

    const subline = !payload
        ? 'Board meetings, your work and board assurance'
        : [
              nextMeeting
                  ? `Next meeting ${formatDateTimeLong(nextMeeting.meeting.scheduled_at, 'date to be confirmed')}`
                  : 'No upcoming meeting',
              payload.captured_at
                  ? `Updated ${formatTime(payload.captured_at)}`
                  : null,
          ]
              .filter(Boolean)
              .join(' · ');

    const titleChip =
        pending === null ? null : overdue > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {overdue} overdue for you
            </PageHeaderStatusChip>
        ) : pending > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {pending} pending for you
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Nothing pending for you
            </PageHeaderStatusChip>
        );

    const assuranceMeter = (
        key: 'risks_above_appetite' | 'obligations_overdue' | 'actions_overdue',
        label: string,
        caption: { some: (n: number) => string; none: string },
        alertTone: 'critical' | 'warning',
        fallbackHref: string,
    ) => {
        const signal = assurance?.[key];
        const available = Boolean(signal?.available) && signal?.count != null;
        const count = available ? (signal?.count as number) : null;
        return (
            <PageHeaderMeterBlock
                label={label}
                href={signal?.href ?? fallbackHref}
                tone={count !== null && count > 0 ? alertTone : 'brand'}
            >
                <PageHeaderMeterBig>{count ?? '—'}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {!payload
                        ? 'Loading'
                        : count === null
                          ? 'Unavailable'
                          : count > 0
                            ? caption.some(count)
                            : caption.none}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        );
    };

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
                        titleChip={titleChip}
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
                                    aria-label="Refresh governance home"
                                >
                                    {loading ? 'Refreshing' : 'Refresh'}
                                </PageHeaderGlassButton>
                                <PageHeaderPrimaryButton
                                    icon={primaryAction.icon}
                                    onClick={() => router.visit(primaryAction.href)}
                                    data-dusk="governance-home-primary"
                                >
                                    {primaryAction.label}
                                </PageHeaderPrimaryButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="My work"
                                    href="/governance/my-work"
                                    tone={overdue > 0 ? 'critical' : pending ? 'warning' : 'brand'}
                                    ariaLabel="View my work"
                                >
                                    <PageHeaderMeterBig>{pending ?? '—'}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {pending === null
                                            ? payload
                                                ? 'Unavailable'
                                                : 'Loading'
                                            : overdue > 0
                                              ? `${pending} pending · ${overdue} overdue`
                                              : pending > 0
                                                ? `${pending} ${plural(pending, 'item needs', 'items need')} you`
                                                : 'Nothing pending'}
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
                                            ? formatDate(nextMeeting.meeting.scheduled_at, 'TBC')
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
                                {canViewRisks
                                    ? assuranceMeter(
                                          'risks_above_appetite',
                                          'Risks above appetite',
                                          {
                                              some: (n) => `${n} outside tolerance`,
                                              none: 'None above appetite',
                                          },
                                          'critical',
                                          '/governance/risks?above_appetite=1',
                                      )
                                    : null}
                                {canViewCompliance
                                    ? assuranceMeter(
                                          'obligations_overdue',
                                          'Obligations overdue',
                                          {
                                              some: (n) => `${n} ${plural(n, 'obligation', 'obligations')} past due`,
                                              none: 'None overdue',
                                          },
                                          'critical',
                                          '/governance/compliance?status=overdue',
                                      )
                                    : null}
                                {canViewActions
                                    ? assuranceMeter(
                                          'actions_overdue',
                                          'Overdue board actions',
                                          {
                                              some: (n) => `${n} ${plural(n, 'action', 'actions')} past due`,
                                              none: 'None overdue',
                                          },
                                          'warning',
                                          '/governance/actions?status=overdue',
                                      )
                                    : null}
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                label="This month"
                                value={period}
                                allValue="month"
                                options={PERIOD_OPTIONS}
                                onChange={(value) => setPeriod(value as Period)}
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
                                title="Board information could not be loaded"
                                message="Governance reporting services did not respond. Nothing has been marked complete — try again."
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
                                    {loading ? 'Retrying…' : 'Retry'}
                                </Button>
                            </div>
                        ) : null}
                        <CockpitLayout
                            cockpit={cockpit}
                            workflow={workflow}
                            myWork={myWork}
                            permissions={permissions}
                            boardRole={boardRole ?? null}
                            userRole={
                                (auth.user as { role?: string } | undefined)
                                    ?.role ?? null
                            }
                            onRefresh={refresh}
                        />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
