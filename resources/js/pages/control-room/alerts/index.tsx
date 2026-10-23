import { useAlertActions } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import {
    BulkAlertActionDialog,
    type BulkAlertMode,
} from '@/components/control-room/bulk-alert-action-dialog';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import { AlertPreview } from '@/components/control-room/live-response/alert-preview';
import {
    LiveResponseFilters,
    LiveResponseSearch,
    applyLiveResponseFilters,
} from '@/components/control-room/live-response/live-response-filters';
import { ResponseWorklist } from '@/components/control-room/live-response/response-worklist';
import { useAlertSelection } from '@/components/control-room/live-response/use-alert-selection';
import { NewAlertWizard } from '@/components/control-room/new-alert-wizard';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import { PopupSelectionBar } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { SelectInput } from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { CalendarRange, Plus, UserCheck, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

/** The older row shape still sent by /control-room/integration-alerts. */
interface LegacyAlertItem {
    id: number;
    source: string;
    alert_type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    status: string;
    escalation_level: number | null;
    triggered_at: string | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    assigned_to: { id: number; name: string } | null;
    client_name: string | null;
    sla_status: 'green' | 'yellow' | 'red' | null;
    snoozed_until: string | null;
    notes: string | null;
}

type CanonicalAlertItem = AlertWorklistRow & {
    alert_type: string;
    escalation_level: number;
    assigned_to: { id: number; name: string } | null;
    client_name: string | null;
    snoozed_until: string | null;
};

interface Props {
    alerts: {
        data: Array<LegacyAlertItem | CanonicalAlertItem>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Record<string, string | undefined>;
    stats: {
        total: number;
        open: number;
        critical: number;
        assigned_to_me: number;
        unassigned: number;
        snoozed: number;
        history?: number;
    };
    staff: Array<{ id: number; name: string; email: string }>;
    clients?: Array<{ id: number; name: string }>;
    sites?: Array<{ id: number; name: string }>;
    can: { manage: boolean; assign: boolean; create?: boolean };
    detail?: AlertWorkspaceDetail | null;
    basePath?: string;
    pageTitle?: string;
    pageDescription?: string;
}

function plain(value: string): string {
    const words = value.replace(/[._-]+/g, ' ').trim().toLowerCase();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Read the older integration rows as worklist rows so both lists look and act alike. */
function fromLegacy(item: LegacyAlertItem): AlertWorklistRow {
    const sla =
        item.sla_status === 'red'
            ? 'breached'
            : item.sla_status === 'yellow'
              ? 'at_risk'
              : item.sla_status === 'green'
                ? 'on_track'
                : null;
    return {
        id: item.id,
        reference_number: null,
        summary: item.notes ?? plain(item.alert_type),
        source: { key: item.source, label: plain(item.source) },
        status: item.status as AlertWorklistRow['status'],
        severity: item.severity,
        priority: { level: item.severity, rank: 0, reason: '' },
        triggered_at: item.triggered_at,
        next_deadline_at: null,
        sla: { status: sla, next_deadline_at: null },
        site: null,
        person: item.client_name ? { id: 0, name: item.client_name } : null,
        assignee: item.assigned_to,
        queue: null,
        journey: {
            incident_reference: null,
            health_safety_reference: null,
            handover_status: null,
        },
        next_action: { label: 'View alert', href: `/control-room/alerts/${item.id}` },
        actions: {
            can_claim: false,
            can_acknowledge: false,
            can_move_queue: false,
            can_escalate: false,
            can_create_incident: false,
            can_snooze: false,
            can_unsnooze: false,
            can_copy_reference: false,
            incident_href: null,
            health_safety_href: null,
        },
        href: `/control-room/alerts/${item.id}`,
    };
}

function isCanonical(item: LegacyAlertItem | CanonicalAlertItem): item is CanonicalAlertItem {
    return 'summary' in item && 'actions' in item;
}

const PERIODS = [
    { value: 'all', label: 'Any time' },
    { value: '1', label: 'Today' },
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
];

function periodFrom(days: string): string | undefined {
    if (days === 'all') return undefined;
    const date = new Date();
    date.setDate(date.getDate() - (Number(days) - 1));
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function periodValue(dateFrom: string | undefined): string {
    if (!dateFrom) return 'all';
    return PERIODS.find((p) => p.value !== 'all' && periodFrom(p.value) === dateFrom)?.value ?? 'all';
}

const SORTS = [
    { value: 'priority', label: 'Most urgent first' },
    { value: 'triggered_at:desc', label: 'Newest first' },
    { value: 'triggered_at:asc', label: 'Oldest first' },
    { value: 'severity:desc', label: 'Severity' },
];

/**
 * Live response views served by the alerts list: Waiting (snoozed), History,
 * and Active for people who can work alerts but not open the desk. One list
 * and a preview beside it, the same as every other Live response view.
 */
export default function AlertsIndex({
    alerts,
    filters,
    stats,
    staff,
    clients = [],
    sites = [],
    can,
    detail = null,
    basePath = '/control-room/alerts',
    pageTitle,
    pageDescription,
}: Props) {
    const canonical = basePath === '/control-room/alerts';
    const lens = filters.lens ?? (filters.snoozed === '1' ? 'snoozed' : 'active');
    const view = lens === 'snoozed' ? 'waiting' : lens === 'history' ? 'history' : 'active';

    const rows = useMemo<AlertWorklistRow[]>(
        () => alerts.data.map((item) => (isCanonical(item) ? item : fromLegacy(item))),
        [alerts.data],
    );
    const [ticked, setTicked] = useState<Set<number>>(new Set());
    const [bulkMode, setBulkMode] = useState<BulkAlertMode | null>(null);
    const [newOpen, setNewOpen] = useState<boolean>(
        () =>
            canonical &&
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );
    const actions = useAlertActions(detail);
    const selection = useAlertSelection({ rows, detail, runAction: actions.run });

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (!document.hidden)
                router.reload({ async: true, only: ['alerts', 'stats', 'detail'], preserveScroll: true });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);
    useEffect(() => setTicked(new Set()), [alerts.current_page, lens]);

    const apply = (next: Record<string, string | undefined | null>) =>
        applyLiveResponseFilters(basePath, { ...filters, ...next, page: undefined, alert: undefined });

    const rowActions = (row: AlertWorklistRow) =>
        buildControlRoomAlertRowActions(row, {
            select: (id) => selection.select(id),
            openAction: (id, key) => selection.select(id, key),
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const bulkAllowed = canonical && view !== 'history' && (can.manage || can.assign);
    const tickedRows = rows.filter((row) => ticked.has(row.id));
    const sortValue = filters.sort && filters.sort !== 'priority'
        ? `${filters.sort}:${filters.dir ?? 'desc'}`
        : 'priority';

    const subline = !canonical
        ? (pageDescription ?? 'Alerts raised by connected systems')
        : view === 'waiting'
          ? 'Snoozed alerts. Each comes back to the worklist on its own when its time is up.'
          : view === 'history'
            ? 'Finished response work, with who did what and when'
            : 'Every open alert, most urgent first';

    const meters = canonical ? (
        <>
            <PageHeaderMeterBlock label="Open" href="/control-room/alerts" ariaLabel="Show open alerts">
                <PageHeaderMeterBig>{stats.open}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>actionable now</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Critical"
                tone={stats.critical > 0 ? 'critical' : 'brand'}
                href="/control-room/alerts?severity=critical"
                ariaLabel="Show critical alerts"
            >
                <PageHeaderMeterBig>{stats.critical}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>act first</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Unassigned"
                tone={stats.unassigned > 0 ? 'warning' : 'brand'}
                href="/control-room/alerts?assigned_to=unassigned"
                ariaLabel="Show alerts with no owner"
            >
                <PageHeaderMeterBig>{stats.unassigned}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>claim or assign</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Waiting"
                href="/control-room/alerts?lens=snoozed"
                ariaLabel="Show snoozed alerts"
            >
                <PageHeaderMeterBig>{stats.snoozed}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>snoozed for now</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    ) : undefined;

    return (
        <ControlRoomPage
            headTitle={!canonical ? pageTitle : view === 'waiting' ? 'Waiting' : view === 'history' ? 'History' : 'Active'}
            rail={canonical ? undefined : null}
            header={{
                title: canonical ? undefined : (pageTitle ?? 'Integration alerts'),
                subline,
                actions: (
                    <>
                        <LiveResponseSearch
                            value={filters.search ?? ''}
                            onApply={(search) => apply({ search: search || undefined })}
                        />
                        {canonical && can.create ? (
                            <PageHeaderPrimaryButton icon={Plus} onClick={() => setNewOpen(true)}>
                                Add alert
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters,
                filters: (
                    <>
                        <LiveResponseFilters
                            values={{
                                severity: filters.severity,
                                source: filters.source,
                                assigned_to: filters.assigned_to,
                            }}
                            rows={rows}
                            staff={staff}
                            onChange={(next) =>
                                apply({
                                    severity: next.severity ? String(next.severity) : undefined,
                                    source: next.source ? String(next.source) : undefined,
                                    assigned_to: next.assigned_to ? String(next.assigned_to) : undefined,
                                })
                            }
                            show={{ severity: true, source: true, owner: true }}
                        />
                        {view === 'history' ? (
                            <PageHeaderFilterSelect
                                icon={CalendarRange}
                                label="Any time"
                                value={periodValue(filters.date_from)}
                                onChange={(days) =>
                                    apply({ date_from: periodFrom(days), date_to: undefined })
                                }
                                options={PERIODS}
                            />
                        ) : null}
                    </>
                ),
            }}
        >
            {bulkAllowed ? (
                <PopupSelectionBar count={ticked.size} onClear={() => setTicked(new Set())}>
                    {can.manage ? (
                        <Button type="button" variant="outline" onClick={() => setBulkMode('acknowledge')}>
                            <UserCheck aria-hidden="true" />
                            Acknowledge
                        </Button>
                    ) : null}
                    {can.assign ? (
                        <Button type="button" onClick={() => setBulkMode('assign')}>
                            <UserPlus aria-hidden="true" />
                            Assign
                        </Button>
                    ) : null}
                </PopupSelectionBar>
            ) : null}

            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]">
                <ResponseWorklist
                    title={view === 'waiting' ? 'Waiting' : view === 'history' ? 'Finished alerts' : 'Open alerts'}
                    description={
                        view === 'history'
                            ? 'Resolved, closed and dismissed alerts. Your access is checked again when you open one.'
                            : view === 'waiting'
                              ? 'Snoozed alerts keep their response targets running.'
                              : 'Missed response targets first, then severity and the next deadline.'
                    }
                    worklist={{
                        data: rows,
                        links: alerts.links,
                        meta: {
                            current_page: alerts.current_page,
                            last_page: alerts.last_page,
                            total: alerts.total,
                        },
                    }}
                    selectedId={selection.selectedId}
                    onSelect={(id) => selection.select(id)}
                    getActions={rowActions}
                    selection={bulkAllowed ? { selected: ticked, onChange: setTicked } : undefined}
                    toolbar={
                        canonical ? (
                            <SelectInput
                                value={sortValue}
                                onChange={(value) => {
                                    const [sort, dir] = value.split(':');
                                    apply({
                                        sort: sort === 'priority' ? undefined : sort,
                                        dir: sort === 'priority' ? undefined : dir,
                                    });
                                }}
                                placeholder="Sort"
                                ariaLabel="Sort the list"
                                options={SORTS}
                                className="h-8 w-44"
                            />
                        ) : null
                    }
                    emptyTitle={
                        view === 'waiting'
                            ? 'Nothing is snoozed'
                            : view === 'history'
                              ? 'No finished alerts here'
                              : 'Nothing needs a response'
                    }
                    emptyDescription={
                        view === 'history'
                            ? 'Try a longer period or clear a filter.'
                            : 'No alerts match these filters.'
                    }
                />
                <div className="min-w-0 xl:sticky xl:top-5">
                    <AlertPreview row={selection.row} detail={detail} onRun={actions.run} />
                </div>
            </div>

            {actions.host}
            {bulkMode ? (
                <BulkAlertActionDialog
                    mode={bulkMode}
                    open
                    onClose={() => setBulkMode(null)}
                    alerts={tickedRows.map((row) => ({
                        id: row.id,
                        reference_number: row.reference_number,
                        summary: row.summary,
                        alert_type: row.source.label,
                        severity: row.severity,
                        status: row.status,
                        client_name: row.person?.name,
                    }))}
                    staff={staff}
                    onDone={() => setTicked(new Set())}
                />
            ) : null}
            {newOpen ? (
                <NewAlertWizard
                    open
                    onClose={() => setNewOpen(false)}
                    clients={clients}
                    sites={sites}
                    onOpenAlert={(id) => router.visit(`/control-room/alerts/${id}`)}
                />
            ) : null}
        </ControlRoomPage>
    );
}
