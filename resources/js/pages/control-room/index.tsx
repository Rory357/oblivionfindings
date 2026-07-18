import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    AlertWorkspaceDialog,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import { CommandCentreTabs } from '@/components/control-room/command-centre-tabs';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import {
    AnalyticsPanel,
    type DeskAnalytics,
} from '@/components/control-room/dashboard/analytics-panel';
import { ContinuityPanel } from '@/components/control-room/dashboard/continuity-panel';
import {
    ControlRoomHero,
    type DeskHandover,
    type DeskHero,
} from '@/components/control-room/dashboard/control-room-hero';
import {
    LiveDeskPanel,
    type DeskFilters,
    type DeskWorklist,
} from '@/components/control-room/dashboard/live-desk-panel';
import {
    QueuePressurePanel,
    type DeskQueue,
} from '@/components/control-room/dashboard/queue-pressure-panel';
import {
    ServiceHealthPanel,
    type DeskActivity,
    type FreshnessState,
} from '@/components/control-room/dashboard/service-health-panel';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { BellRing, ChevronUp, LoaderCircle } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type Option = { id: number; name: string };

export type ControlRoomDashboardProps = {
    hero: DeskHero;
    worklist: DeskWorklist;
    queues: DeskQueue[];
    handover: DeskHandover;
    activity: DeskActivity[];
    filters: DeskFilters;
    freshness: { updated_at: string; stale_after_seconds: number };
    sites: Option[];
    staff: Option[];
    can: {
        manage: boolean;
        assign: boolean;
        escalate?: boolean;
        create: boolean;
        viewReports: boolean;
    };
    detail: AlertWorkspaceDetail | null;
    analytics?: DeskAnalytics;
};

const LIVE_PROPS = [
    'hero',
    'worklist',
    'queues',
    'handover',
    'activity',
    'freshness',
] as const;

export function ControlRoomDashboardView({
    hero,
    worklist,
    queues,
    handover,
    activity,
    filters,
    freshness,
    sites,
    staff,
    can,
    detail,
    analytics,
}: ControlRoomDashboardProps) {
    const [refreshing, setRefreshing] = useState(false);
    const [clock, setClock] = useState(() => Date.now());
    const [analyticsOpen, setAnalyticsOpen] = useState(() =>
        Boolean(analytics),
    );
    const [newCriticalCount, setNewCriticalCount] = useState(0);
    const criticalIds = useMemo(
        () =>
            worklist.data
                .filter((row) => row.severity === 'critical')
                .map((row) => row.id),
        [worklist.data],
    );
    const seenCriticalIds = useRef(new Set(criticalIds));

    useEffect(() => {
        const next = new Set(criticalIds);
        const newlyVisible = criticalIds.filter(
            (id) => !seenCriticalIds.current.has(id),
        );
        if (newlyVisible.length > 0) setNewCriticalCount(newlyVisible.length);
        seenCriticalIds.current = next;
    }, [criticalIds]);

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (document.hidden) return;
            setRefreshing(true);
            router.reload({
                only: [...LIVE_PROPS],
                preserveScroll: true,
                onFinish: () => {
                    setRefreshing(false);
                    setClock(Date.now());
                },
            });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);

    useEffect(() => {
        const timer = window.setInterval(() => setClock(Date.now()), 15_000);
        return () => window.clearInterval(timer);
    }, []);

    const ageSeconds = Math.max(
        0,
        (clock - new Date(freshness.updated_at).getTime()) / 1000,
    );
    const freshnessState: FreshnessState = refreshing
        ? 'refreshing'
        : ageSeconds > freshness.stale_after_seconds
          ? 'stale'
          : 'updated';

    const applyFilters = (next: DeskFilters) => {
        const query = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) =>
                    value !== '' && value !== null && value !== undefined,
            ),
        );
        router.get('/control-room', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const openWorkspace = (id: number) => {
        const params = new URLSearchParams(window.location.search);
        params.set('alert', String(id));
        router.get(
            `/control-room?${params.toString()}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['detail'],
            },
        );
    };

    const closeWorkspace = () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('alert');
        router.get(
            `/control-room${params.size ? `?${params.toString()}` : ''}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['detail'],
            },
        );
    };

    const rowActions = (row: AlertWorklistRow) =>
        buildControlRoomAlertRowActions(row, {
            openWorkspace,
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const openAnalytics = () => {
        setAnalyticsOpen(true);
        if (!analytics) {
            router.reload({
                only: ['analytics'],
                preserveScroll: true,
            });
        }
    };

    return (
        <div className="space-y-5 p-4 md:p-6">
            <ControlRoomHero
                hero={hero}
                handover={handover}
                canCreate={can.create}
                canViewAnalytics={can.viewReports}
                onOpenAnalytics={openAnalytics}
            />

            <CommandCentreTabs
                current="/control-room"
                badges={{
                    '/control-room/alerts': hero.active,
                    '/control-room/escalations': hero.sla_breached,
                    '/control-room/incidents': handover.needs_incident,
                }}
            />

            {newCriticalCount > 0 ? (
                <div
                    role="alert"
                    className="flex items-center justify-between gap-4 rounded-xl border border-status-critical/30 bg-status-critical/10 px-4 py-3 text-sm text-status-critical-foreground"
                >
                    <span className="flex items-center gap-2 font-semibold">
                        <BellRing className="h-4 w-4" aria-hidden />
                        {newCriticalCount} new critical{' '}
                        {newCriticalCount === 1 ? 'alert is' : 'alerts are'} in
                        the priority worklist
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setNewCriticalCount(0)}
                    >
                        Dismiss notice
                    </Button>
                </div>
            ) : null}

            <div className="grid items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_360px]">
                <LiveDeskPanel
                    worklist={worklist}
                    filters={filters}
                    sites={sites}
                    staff={staff}
                    queues={queues}
                    onFilter={applyFilters}
                    onOpen={openWorkspace}
                    getActions={rowActions}
                />
                <aside className="space-y-5">
                    <ContinuityPanel handover={handover} />
                    <QueuePressurePanel queues={queues} />
                </aside>
            </div>

            <ServiceHealthPanel
                activity={activity}
                freshness={freshness}
                state={freshnessState}
            />

            {analyticsOpen ? (
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setAnalyticsOpen(false)}
                    >
                        <ChevronUp className="h-4 w-4" aria-hidden />
                        Hide analytics
                    </Button>
                </div>
            ) : null}

            {analyticsOpen && analytics ? (
                <AnalyticsPanel analytics={analytics} />
            ) : analyticsOpen ? (
                <Card className="min-h-32 flex-row items-center justify-center gap-2 py-0 text-sm text-muted-foreground">
                    <LoaderCircle
                        className="h-4 w-4 animate-spin motion-reduce:animate-none"
                        aria-hidden
                    />
                    Loading historical performance…
                </Card>
            ) : null}

            {detail ? (
                <AlertWorkspaceDialog
                    detail={detail}
                    open
                    onClose={closeWorkspace}
                />
            ) : null}
        </div>
    );
}

export default function ControlRoomDashboard(props: ControlRoomDashboardProps) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Desk', href: '/control-room' },
            ]}
            contentClassName="overflow-x-hidden"
        >
            <Head title="Desk - Control Room" />
            <ControlRoomDashboardView {...props} />
        </AppLayout>
    );
}
