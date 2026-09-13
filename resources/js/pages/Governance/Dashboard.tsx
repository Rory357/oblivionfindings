import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, Landmark, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { canDoGovernance } from '@/lib/governance-permissions';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { PageProps } from '@/types';

import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { CockpitSkeleton } from '@/components/governance/CockpitSkeleton';
import { CockpitLayout, type CockpitPayload } from './Cockpit/CockpitLayout';

interface DashboardPayload {
    snapshot_id: number | null;
    workflow: {
        summary: { total: number; critical: number; overdue: number };
        actions: WorkflowAction[];
    };
    work_totals?: {
        all: number;
        pending: number;
        overdue: number;
        completed: number;
        [key: string]: number;
    } | null;
    cockpit: CockpitPayload;
}

type Props = PageProps & {
    isBoardMember: boolean;
    boardRole?: string;
    workTotals?: {
        all: number;
        pending: number;
        overdue: number;
        completed: number;
        [key: string]: number;
    } | null;
};

const formatLabel = (value: string) => value.replace(/_/g, ' ');

/**
 * Governance Dashboard — thin orchestrator. The hero stays purple via the
 * `category="governance"` dynamic accent. The body is composed entirely
 * inside `CockpitLayout` to keep this file focused on page chrome + data
 * fetch.
 */
export default function GovernanceDashboard({
    auth,
    isBoardMember,
    boardRole,
    workTotals,
}: Props) {
    const [period, setPeriod] = useState('month');
    const [searchQuery, setSearchQuery] = useState('');
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const [lastCapturedAt, setLastCapturedAt] = useState<string | null>(null);
    const activeRequestRef = useRef<number>(0);

    // Manual refresh bypasses the server-side dashboard cache (fresh=1)
    // so the button always returns just-computed numbers.
    const fetchData = async () => {
        setLoading(true);
        setRefreshError(null);
        const reqId = ++activeRequestRef.current;
        try {
            const response = await axios.get(dashboardData.url(), {
                params: { period, fresh: 1 },
            });
            if (reqId === activeRequestRef.current) {
                setPayload(response.data);
                setError(null);
                setRefreshError(null);
                if (response.data?.captured_at) {
                    setLastCapturedAt(response.data.captured_at);
                }
            }
        } catch (err) {
            if (reqId === activeRequestRef.current) {
                if (!payload) {
                    setError('Board information could not be loaded.');
                } else {
                    const timeLabel = lastCapturedAt
                        ? new Date(lastCapturedAt).toLocaleTimeString()
                        : 'earlier capture';
                    setRefreshError(
                        `Refresh failed — showing data as of ${timeLabel}.`,
                    );
                }
            }
        } finally {
            if (reqId === activeRequestRef.current) {
                setLoading(false);
            }
        }
    };

    useEffect(() => {
        let cancelled = false;
        const reqId = ++activeRequestRef.current;
        const load = async () => {
            setLoading(true);
            setRefreshError(null);
            try {
                const response = await axios.get(dashboardData.url(), {
                    params: { period },
                });
                if (!cancelled && reqId === activeRequestRef.current) {
                    setPayload(response.data);
                    setError(null);
                    setRefreshError(null);
                    if (response.data?.captured_at) {
                        setLastCapturedAt(response.data.captured_at);
                    }
                }
            } catch (err) {
                if (!cancelled && reqId === activeRequestRef.current) {
                    if (!payload) {
                        setError('Board information could not be loaded.');
                    } else {
                        const timeLabel = lastCapturedAt
                            ? new Date(lastCapturedAt).toLocaleTimeString()
                            : 'earlier capture';
                        setRefreshError(
                            `Refresh failed — showing data as of ${timeLabel}.`,
                        );
                    }
                }
            } finally {
                if (!cancelled && reqId === activeRequestRef.current) {
                    setLoading(false);
                }
            }
        };
        void load();
        return () => {
            cancelled = true;
        };
    }, [period]);

    const workflow = payload?.workflow;
    const cockpit = payload?.cockpit;
    const permissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;

    const myWorkCount =
        payload?.work_totals?.pending ??
        workTotals?.pending ??
        workflow?.actions.filter((a) => {
            if (auth.user?.id && a.assignee_user_id) {
                return a.assignee_user_id === auth.user.id;
            }
            return false;
        }).length ?? 0;

    const overdueActionsCount =
        (workflow?.summary as { action_items_overdue?: number; overdue?: number } | undefined)?.action_items_overdue ??
        Number(cockpit?.cards_by_key?.follow_through?.metrics?.find((m) => m.label === 'Overdue')?.value ?? workflow?.summary?.overdue ?? 0);

    const risksOverAppetiteCount = Number(
        cockpit?.kpi_band?.find((k) => k.key === 'risks_over_appetite')?.value ??
            0,
    );

    const complianceCard = cockpit?.cards_by_key?.['compliance_calendar'];
    const overdueObligationsMetric = complianceCard?.metrics?.find(
        (m) => m.label === 'Overdue',
    );
    const obligationsOverdueCount = Number(
        overdueObligationsMetric?.value ?? 0,
    );

    const isSecretaryOrChair =
        isBoardMember && (boardRole === 'secretary' || boardRole === 'chair');
    const canManageMeetings = canDoGovernance(permissions, 'meetings', 'manage');
    const showPrepareMeeting = isBoardMember || isSecretaryOrChair || canManageMeetings;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
            ]}
        >
            <Head title="Board overview" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="index"
                        icon={Landmark}
                        title="Board overview"
                        titleChip={
                            <span
                                dusk="governance-cockpit-heading"
                                className="hidden"
                            >
                                Board overview
                            </span>
                        }
                        subline={
                            <>
                                Reporting period: {formatLabel(period)} ·{' '}
                                {lastCapturedAt ? (
                                    <>
                                        Last captured{' '}
                                        <time dateTime={lastCapturedAt}>
                                            {new Date(
                                                lastCapturedAt,
                                            ).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </time>
                                    </>
                                ) : (
                                    'Latest capture'
                                )}
                            </>
                        }
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={searchQuery}
                                    onChange={setSearchQuery}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && searchQuery.trim()) {
                                            router.visit(
                                                `/governance/actions?search=${encodeURIComponent(searchQuery.trim())}`,
                                            );
                                        }
                                    }}
                                    placeholder="Search governance..."
                                />
                                {showPrepareMeeting ? (
                                    <PageHeaderPrimaryButton
                                        onClick={() =>
                                            router.visit(
                                                cockpit?.next_meeting
                                                    ? `/governance/meetings/${cockpit.next_meeting.meeting.id}`
                                                    : '/governance/meetings',
                                            )
                                        }
                                    >
                                        Prepare meeting
                                    </PageHeaderPrimaryButton>
                                ) : (
                                    <PageHeaderPrimaryButton
                                        onClick={() =>
                                            router.visit('/governance/my-work')
                                        }
                                    >
                                        My work
                                        {myWorkCount > 0
                                            ? ` (${myWorkCount})`
                                            : ''}
                                    </PageHeaderPrimaryButton>
                                )}
                                <PageHeaderGlassButton
                                    icon={RefreshCw}
                                    onClick={fetchData}
                                    disabled={loading}
                                    aria-label="Refresh dashboard"
                                >
                                    <span className="hidden sm:inline">
                                        {loading ? 'Refreshing' : 'Refresh'}
                                    </span>
                                </PageHeaderGlassButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="My pending work"
                                    href="/governance/my-work"
                                    tone={myWorkCount > 0 ? 'warning' : 'brand'}
                                >
                                    <PageHeaderMeterBig>
                                        {myWorkCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {myWorkCount === 1
                                            ? '1 item requiring you'
                                            : `${myWorkCount} items requiring you`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Overdue board actions"
                                    href="/governance/actions?status=overdue"
                                    tone={
                                        overdueActionsCount > 0
                                            ? 'critical'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {overdueActionsCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {overdueActionsCount === 1
                                            ? '1 action overdue'
                                            : `${overdueActionsCount} actions overdue`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Risks above appetite"
                                    href="/governance/risks"
                                    tone={
                                        risksOverAppetiteCount > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {risksOverAppetiteCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {risksOverAppetiteCount > 0
                                            ? 'Outside tolerance'
                                            : 'Within appetite'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Obligations overdue"
                                    href="/governance/compliance?status=overdue"
                                    tone={
                                        obligationsOverdueCount > 0
                                            ? 'critical'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {obligationsOverdueCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {obligationsOverdueCount > 0
                                            ? 'Statutory & compliance'
                                            : 'All obligations current'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                label="Period"
                                value={period}
                                options={[
                                    { value: 'today', label: 'Today' },
                                    { value: 'week', label: 'This Week' },
                                    { value: 'month', label: 'This Month' },
                                    { value: 'year', label: 'This Year' },
                                ]}
                                onChange={setPeriod}
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={[
                                    { key: 'overview', label: 'Overview' },
                                    {
                                        key: 'my-work',
                                        label: 'My work',
                                        count: myWorkCount,
                                    },
                                    { key: 'calendar', label: 'Calendar' },
                                    { key: 'decisions', label: 'Decisions' },
                                ]}
                                value="overview"
                                onSelect={(key) => {
                                    if (key === 'overview')
                                        router.visit('/governance/dashboard');
                                    else if (key === 'my-work')
                                        router.visit('/governance/my-work');
                                    else if (key === 'calendar')
                                        router.visit(
                                            '/governance/meetings/calendar',
                                        );
                                    else if (key === 'decisions')
                                        router.visit('/governance/resolutions');
                                }}
                            />
                        }
                    />
                }
            >
                {error && !payload ? (
                    <div
                        className="mx-auto max-w-2xl py-12 text-center"
                        data-dusk="dashboard-error"
                    >
                        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-8">
                            <AlertCircle className="mx-auto h-8 w-8 text-destructive" />
                            <h2 className="mt-3 text-lg font-semibold text-foreground">
                                Board information could not be loaded
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Unable to connect to governance reporting services.
                                Please try again.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => void fetchData()}
                                disabled={loading}
                                className="mt-4"
                            >
                                <RefreshCw
                                    className={
                                        loading
                                            ? 'mr-2 h-4 w-4 animate-spin'
                                            : 'mr-2 h-4 w-4'
                                    }
                                />
                                Retry
                            </Button>
                        </div>
                    </div>
                ) : loading && !payload ? (
                    <CockpitSkeleton />
                ) : cockpit && workflow ? (
                    <>
                        {refreshError && (
                            <div
                                className="mb-4 flex items-center justify-between rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2.5 text-xs text-amber-800 dark:text-amber-300"
                                data-dusk="refresh-failed-banner"
                            >
                                <span>{refreshError}</span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => void fetchData()}
                                    disabled={loading}
                                    className="h-6 text-xs text-amber-900 hover:bg-amber-500/20 dark:text-amber-200"
                                >
                                    Retry
                                </Button>
                            </div>
                        )}
                        <CockpitLayout
                            cockpit={cockpit}
                            workflow={workflow}
                            permissions={permissions}
                            currentUserId={auth.user?.id ?? null}
                            currentUserName={auth.user?.name ?? null}
                            boardRole={boardRole ?? null}
                            userRole={
                                (auth.user as { role?: string } | undefined)
                                    ?.role ?? null
                            }
                            onRefresh={fetchData}
                        />
                    </>
                ) : (
                    <CockpitSkeleton />
                )}
            </PageLayout>
        </AppLayout>
    );
}
