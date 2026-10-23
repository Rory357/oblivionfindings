import { useAlertActions } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import type {
    DeskActivity,
    DeskAnalytics,
    DeskFilters,
    DeskHandover,
    DeskHero,
    DeskQueue,
    DeskWorklist,
} from '@/components/control-room/live-response/desk-types';
import { AlertPreview } from '@/components/control-room/live-response/alert-preview';
import {
    LiveResponseFilters,
    LiveResponseSearch,
    applyLiveResponseFilters,
} from '@/components/control-room/live-response/live-response-filters';
import {
    NeedsYouNowCard,
    needsYouNow,
} from '@/components/control-room/live-response/needs-you-now';
import { longDuration } from '@/components/control-room/live-response/response-clock';
import { ResponseWorklist } from '@/components/control-room/live-response/response-worklist';
import { LiveMark } from '@/components/control-room/live-response/target-lens';
import { useAlertSelection } from '@/components/control-room/live-response/use-alert-selection';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { Clock, Plus } from 'lucide-react';
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

/** Props refreshed by the 30-second poll (the selected alert included). */
const LIVE_PROPS = [
    'hero',
    'worklist',
    'queues',
    'handover',
    'activity',
    'freshness',
    'detail',
] as const;

function useLiveRefresh(freshness: ControlRoomDashboardProps['freshness']) {
    const [refreshing, setRefreshing] = useState(false);
    const [failed, setFailed] = useState(false);
    const [clock, setClock] = useState(() => Date.now());

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            // async: a refresh never cancels the preview loading a row.
            async: true,
            only: [...LIVE_PROPS],
            preserveScroll: true,
            onSuccess: () => setFailed(false),
            onError: () => setFailed(true),
            onFinish: () => {
                setRefreshing(false);
                setClock(Date.now());
            },
        });
    };

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (!document.hidden) refresh();
        }, 30_000);
        const tick = window.setInterval(() => setClock(Date.now()), 15_000);
        return () => {
            window.clearInterval(poll);
            window.clearInterval(tick);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- one poll per page
    }, []);

    const age = (clock - new Date(freshness.updated_at).getTime()) / 1000;
    const stale = failed || age > freshness.stale_after_seconds;
    return { refreshing, stale, refresh };
}

/** New critical alerts since the page was opened, until dismissed. */
function useNewCriticalNotice(rows: AlertWorklistRow[]) {
    const ids = useMemo(
        () => rows.filter((row) => row.severity === 'critical').map((row) => row.id),
        [rows],
    );
    const seen = useRef(new Set(ids));
    const [count, setCount] = useState(0);
    useEffect(() => {
        const fresh = ids.filter((id) => !seen.current.has(id));
        if (fresh.length) setCount((current) => current + fresh.length);
        fresh.forEach((id) => seen.current.add(id));
    }, [ids]);
    return { count, dismiss: () => setCount(0) };
}

export function ControlRoomDashboardView({
    hero,
    worklist,
    queues,
    filters,
    freshness,
    sites,
    staff,
    can,
    detail,
}: ControlRoomDashboardProps) {
    const live = useLiveRefresh(freshness);
    const notice = useNewCriticalNotice(worklist.data);
    const actions = useAlertActions(detail);
    const selection = useAlertSelection({
        rows: worklist.data,
        detail,
        runAction: actions.run,
    });

    const apply = (next: Partial<DeskFilters>) =>
        applyLiveResponseFilters('/control-room', { ...filters, ...next, page: undefined });

    const rowActions = (row: AlertWorklistRow) =>
        buildControlRoomAlertRowActions(row, {
            select: (id) => selection.select(id),
            openAction: (id, key) => selection.select(id, key),
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const updated = `updated ${formatTime(freshness.updated_at)}`;
    const top = needsYouNow(worklist.data);
    const rest = top
        ? {
              ...worklist,
              data: worklist.data.filter((row) => row.id !== top.id),
              meta: { ...worklist.meta, total: Math.max(0, worklist.meta.total - 1) },
          }
        : worklist;
    const oldest = hero.oldest_open_at
        ? Date.now() - new Date(hero.oldest_open_at).getTime()
        : null;

    return (
        <ControlRoomPage
            header={{
                subline: (
                    <>
                        <LiveMark
                            stale={live.stale}
                            refreshing={live.refreshing}
                            updatedLabel={updated}
                        />
                        <span aria-hidden="true"> · </span>
                        Alerts that need someone, most urgent first
                    </>
                ),
                actions: (
                    <>
                        <LiveResponseSearch
                            value={filters.q ?? ''}
                            onApply={(q) => apply({ q })}
                        />
                        <PageHeaderGlassButton icon={Clock} asChild>
                            <Link href="/control-room/shifts">Duty handover</Link>
                        </PageHeaderGlassButton>
                        {can.create ? (
                            <PageHeaderPrimaryButton icon={Plus} asChild>
                                <Link href="/control-room/alerts?new=1">
                                    Add alert
                                </Link>
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Need action"
                            href="/control-room"
                            ariaLabel="Show every open alert"
                        >
                            <PageHeaderMeterBig>{hero.active}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {oldest !== null && hero.active > 0
                                    ? `oldest waiting ${longDuration(oldest)}`
                                    : 'open alerts'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Critical"
                            tone={hero.critical > 0 ? 'critical' : 'brand'}
                            href="/control-room?severity=critical"
                            ariaLabel="Show critical alerts"
                        >
                            <PageHeaderMeterBig>{hero.critical}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>answer these first</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="No one on it"
                            tone={hero.unassigned > 0 ? 'warning' : 'brand'}
                            href="/control-room?assigned_to=unassigned"
                            ariaLabel="Show alerts no one has taken"
                        >
                            <PageHeaderMeterBig>{hero.unassigned}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                take one or assign it
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Missed target"
                            tone={hero.sla_breached > 0 ? 'critical' : 'brand'}
                            href="/control-room/sla/breaches"
                            ariaLabel="Show response targets that were missed"
                        >
                            <PageHeaderMeterBig>{hero.sla_breached}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                past a response target
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <LiveResponseFilters
                        values={filters}
                        rows={worklist.data}
                        staff={staff}
                        sites={sites}
                        queues={queues}
                        onChange={(next) => apply(next as Partial<DeskFilters>)}
                    />
                ),
            }}
        >
            {live.stale ? (
                <PopupCallout tone="warning">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span>
                            The list couldn&apos;t refresh — what you see may
                            be out of date ({updated.toLowerCase()}).
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={live.refresh}
                        >
                            Try again
                        </Button>
                    </span>
                </PopupCallout>
            ) : null}
            {notice.count > 0 ? (
                <PopupCallout tone="critical">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span className="font-semibold">
                            {notice.count} new critical{' '}
                            {notice.count === 1 ? 'alert has' : 'alerts have'} come
                            in
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={notice.dismiss}
                        >
                            Dismiss
                        </Button>
                    </span>
                </PopupCallout>
            ) : null}

            {top ? (
                <NeedsYouNowCard
                    row={top}
                    onAcknowledge={() => selection.select(top.id, 'acknowledge')}
                    onTake={() =>
                        router.post(
                            `/control-room/alerts/${top.id}/assign-to-me`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                />
            ) : null}

            <div
                className={
                    worklist.data.length === 0
                        ? 'grid min-w-0 gap-5'
                        : 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]'
                }
            >
                <ResponseWorklist
                    title={top ? 'Up next' : 'Needs action'}
                    description={
                        top
                            ? 'Everything else that is open, most urgent first.'
                            : 'Open alerts, most urgent first. Missed targets go to the top.'
                    }
                    worklist={rest}
                    selectedId={selection.selectedId}
                    onSelect={(id) => selection.select(id)}
                    getActions={rowActions}
                    emptyTitle={top ? 'Nothing else is waiting' : 'All clear'}
                    emptyDescription={
                        top
                            ? 'The alert above is the only one open.'
                            : 'No open alerts match these filters. Snoozed alerts are under Snoozed; finished ones are under Done.'
                    }
                />
                <div className="min-w-0 xl:sticky xl:top-5">
                    <AlertPreview
                        row={selection.row}
                        detail={detail}
                        onRun={actions.run}
                        hideWhenEmpty={worklist.data.length === 0}
                    />
                </div>
            </div>

            {actions.host}
        </ControlRoomPage>
    );
}

export default function ControlRoomDashboard(props: ControlRoomDashboardProps) {
    return <ControlRoomDashboardView {...props} />;
}
