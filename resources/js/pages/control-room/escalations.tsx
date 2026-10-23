import { useAlertActions } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { BulkAlertActionDialog } from '@/components/control-room/bulk-alert-action-dialog';
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
import { LiveMark } from '@/components/control-room/live-response/target-lens';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { PopupSelectionBar } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowUpCircle, Inbox } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

/** One escalation queue and how full it is. */
type EscalationQueueSummary = {
    id: number;
    name: string;
    tier: number;
    alert_count: number;
    breached_count: number;
};

type EscalationRow = AlertWorklistRow & {
    escalation_level: number;
    entered_queue_at: string | null;
};

type Props = {
    queues: EscalationQueueSummary[];
    worklist: {
        data: EscalationRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        active_queues: number;
        total_alerts: number;
        breaches: number;
        urgent: number;
        unassigned: number;
    };
    filters: {
        queue_id: string | null;
        tier: string | null;
        severity: string | null;
        search: string | null;
    };
    serverTime: string;
    can: { manage: boolean; assign: boolean };
    detail?: AlertWorkspaceDetail | null;
};

const basePath = '/control-room/escalations';

/**
 * Live response › Escalated — alerts in the escalation queues, ordered by a
 * missed deadline, then queue tier, severity and time waiting. Tick alerts to
 * escalate several at once.
 */
export default function Escalations({
    queues,
    worklist,
    summary,
    filters,
    serverTime,
    detail = null,
}: Props) {
    const [ticked, setTicked] = useState<Set<number>>(new Set());
    const [bulkOpen, setBulkOpen] = useState(false);
    const actions = useAlertActions(detail);
    const selection = useAlertSelection({
        rows: worklist.data,
        detail,
        runAction: actions.run,
    });

    useEffect(() => {
        const visible = new Set(worklist.data.map((row) => row.id));
        setTicked((current) => new Set([...current].filter((id) => visible.has(id))));
    }, [worklist.current_page, worklist.data]);

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                async: true,
                only: ['queues', 'worklist', 'summary', 'serverTime', 'detail'],
                preserveScroll: true,
            });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);

    const visit = (next: Partial<Props['filters']>) =>
        applyLiveResponseFilters(basePath, { ...filters, ...next });

    const rowActions = (row: AlertWorklistRow) =>
        buildControlRoomAlertRowActions(row, {
            select: (id) => selection.select(id),
            openAction: (id, key) => selection.select(id, key),
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const tickedRows = useMemo(
        () => worklist.data.filter((row) => ticked.has(row.id)),
        [ticked, worklist.data],
    );
    const escalatable = tickedRows.filter((row) => row.actions.can_escalate);

    return (
        <ControlRoomPage
            headTitle="Escalated"
            header={{
                subline: (
                    <>
                        <LiveMark
                            stale={false}
                            refreshing={false}
                            updatedLabel={`updated ${formatTime(serverTime)}`}
                        />
                        <span aria-hidden="true"> · </span>
                        Alerts moved up for a senior response
                    </>
                ),
                actions: (
                    <LiveResponseSearch
                        value={filters.search ?? ''}
                        onApply={(search) => visit({ search: search || null })}
                        placeholder="Search reference, type or summary"
                    />
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Escalated alerts"
                            href={basePath}
                            ariaLabel="Show every escalated alert"
                        >
                            <PageHeaderMeterBig>{summary.total_alerts}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                across {summary.active_queues} queue
                                {summary.active_queues === 1 ? '' : 's'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Missed target"
                            tone={summary.breaches > 0 ? 'critical' : 'brand'}
                            href="/control-room/sla/breaches"
                            ariaLabel="Show missed response targets"
                        >
                            <PageHeaderMeterBig>{summary.breaches}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>past a response target</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Critical or high"
                            tone={summary.urgent > 0 ? 'critical' : 'brand'}
                            href={basePath}
                            ariaLabel="Show escalated alerts, most urgent first"
                        >
                            <PageHeaderMeterBig>{summary.urgent}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>at the top of the list</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="No one on it"
                            tone={summary.unassigned > 0 ? 'warning' : 'brand'}
                            href={basePath}
                            ariaLabel="Show escalated alerts no one has taken"
                        >
                            <PageHeaderMeterBig>{summary.unassigned}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>take one or assign it</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <>
                        <LiveResponseFilters
                            values={{ severity: filters.severity }}
                            rows={worklist.data}
                            onChange={(next) => visit({ severity: next.severity ? String(next.severity) : null })}
                            show={{ severity: true }}
                        />
                        {queues.length > 1 ? (
                            <PageHeaderFilterSelect
                                icon={Inbox}
                                label="All queues"
                                value={filters.queue_id ?? 'all'}
                                onChange={(value) =>
                                    visit({ queue_id: value === 'all' ? null : value })
                                }
                                options={[
                                    { value: 'all', label: 'All queues' },
                                    ...queues.map((queue) => ({
                                        value: String(queue.id),
                                        label: `${queue.name} · ${queue.alert_count} open${queue.breached_count ? `, ${queue.breached_count} missed` : ''}`,
                                    })),
                                ]}
                            />
                        ) : null}
                    </>
                ),
            }}
        >
            <PopupSelectionBar
                count={ticked.size}
                note={`${escalatable.length} can be escalated`}
                onClear={() => setTicked(new Set())}
            >
                <Button
                    type="button"
                    disabled={!escalatable.length}
                    onClick={() => setBulkOpen(true)}
                >
                    <ArrowUpCircle aria-hidden="true" />
                    Escalate selected
                </Button>
            </PopupSelectionBar>

            <div
                className={
                    worklist.data.length === 0
                        ? 'grid min-w-0 gap-5'
                        : 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]'
                }
            >
                <ResponseWorklist
                    title="Escalated"
                    description="Missed targets first, then the highest queue and the most urgent."
                    worklist={{
                        data: worklist.data,
                        links: worklist.links,
                        meta: {
                            current_page: worklist.current_page,
                            last_page: worklist.last_page,
                            total: worklist.total,
                        },
                    }}
                    selectedId={selection.selectedId}
                    onSelect={(id) => selection.select(id)}
                    getActions={rowActions}
                    selection={{ selected: ticked, onChange: setTicked }}
                    emptyTitle="Nothing is escalated"
                    emptyDescription="No escalated alerts match these filters."
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
            {bulkOpen ? (
                <BulkAlertActionDialog
                    mode="escalate"
                    open
                    onClose={() => setBulkOpen(false)}
                    alerts={escalatable.map((row) => ({
                        id: row.id,
                        reference_number: row.reference_number,
                        summary: row.summary,
                        alert_type: row.source.label,
                        severity: row.severity,
                        client_name: row.person?.name,
                    }))}
                    onDone={() => setTicked(new Set())}
                />
            ) : null}
        </ControlRoomPage>
    );
}
