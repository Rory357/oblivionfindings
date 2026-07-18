import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertWorklist } from '@/components/control-room/alert-worklist/alert-worklist';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    AlertWorkspaceDialog,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import {
    EscalationQueueFilters,
    type EscalationQueueSummary,
} from '@/components/control-room/escalation-queue-filters';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ArrowUpCircle, Gauge, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';

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

export default function Escalations({
    queues,
    worklist,
    summary,
    filters,
    serverTime,
    detail = null,
}: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [search, setSearch] = useState(filters.search ?? '');
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkReason, setBulkReason] = useState('');

    useEffect(() => setSearch(filters.search ?? ''), [filters.search]);

    useEffect(() => {
        const visible = new Set(worklist.data.map((row) => row.id));
        setSelected(
            (current) => new Set([...current].filter((id) => visible.has(id))),
        );
    }, [worklist.current_page, worklist.data]);

    useEffect(() => {
        const interval = window.setInterval(() => {
            router.reload({
                only: ['queues', 'worklist', 'summary', 'serverTime'],
                preserveScroll: true,
            });
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const visit = (next: Partial<Props['filters']>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                (entry): entry is [string, string] =>
                    typeof entry[1] === 'string' && entry[1] !== '',
            ),
        );
        router.get(basePath, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const openWorkspace = (id: number) =>
        router.get(
            basePath,
            { ...filters, alert: String(id) } as Record<string, string>,
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const closeWorkspace = () =>
        router.get(basePath, filters as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
            only: ['detail'],
        });

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        visit({ search: search.trim() || null });
    };

    const rowActions = (row: EscalationRow) =>
        buildControlRoomAlertRowActions(row, {
            openWorkspace,
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const selectedRows = useMemo(
        () => worklist.data.filter((row) => selected.has(row.id)),
        [selected, worklist.data],
    );
    const escalatableIds = selectedRows
        .filter((row) => row.actions.can_escalate)
        .map((row) => row.id);

    const submitBulkEscalation = () => {
        if (!bulkReason.trim() || !escalatableIds.length) return;
        router.post(
            `${basePath}/bulk-escalate`,
            { alert_ids: escalatableIds, reason: bulkReason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected(new Set());
                    setBulkReason('');
                    setBulkOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Escalations', href: basePath },
            ]}
        >
            <Head title="Escalations - Control Room" />
            <PageShell>
                <CommandCentrePage
                    current={basePath}
                    icon={AlertTriangle}
                    title="Escalations"
                    description="Escalation queues — SLA-tracked tiers with guided moves and escalations."
                    status="Live escalation workspace"
                    freshness={`Updated ${formatRelative(serverTime)}`}
                    badges={{
                        '/control-room/escalations': summary.total_alerts,
                    }}
                    metricGroups={[
                        {
                            title: 'Queue pressure',
                            icon: Gauge,
                            metrics: [
                                {
                                    label: 'Active queues',
                                    value: String(summary.active_queues),
                                    caption: 'configured tiers',
                                    tone: 'neutral',
                                },
                                {
                                    label: 'Alerts',
                                    value: String(summary.total_alerts),
                                    caption: 'full bounded worklist',
                                    tone: 'neutral',
                                },
                                {
                                    label: 'Breached',
                                    value: String(summary.breaches),
                                    caption: 'past SLA',
                                    tone:
                                        summary.breaches > 0
                                            ? 'critical'
                                            : 'success',
                                },
                                {
                                    label: 'Urgent / unassigned',
                                    value: `${summary.urgent} / ${summary.unassigned}`,
                                    caption: 'priority / ownership',
                                    tone:
                                        summary.urgent + summary.unassigned > 0
                                            ? 'warning'
                                            : 'success',
                                },
                            ],
                        },
                    ]}
                >
                    <EscalationQueueFilters
                        queues={queues}
                        activeQueueId={filters.queue_id}
                        totalAlerts={summary.total_alerts}
                        hasFilters={Boolean(
                            filters.queue_id ||
                            filters.tier ||
                            filters.severity ||
                            filters.search,
                        )}
                        onSelect={(queueId) => visit({ queue_id: queueId })}
                        onClear={() => router.get(basePath)}
                    />

                    <Card className="gap-3 rounded-xl p-3">
                        <form
                            onSubmit={submitSearch}
                            className="flex flex-col gap-2 sm:flex-row"
                        >
                            <div className="relative min-w-0 flex-1">
                                <Search
                                    className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search reference, type, or summary"
                                    aria-label="Search escalations"
                                    className="pl-9"
                                />
                            </div>
                            <Select
                                value={filters.severity ?? 'all'}
                                onValueChange={(value) =>
                                    visit({
                                        severity:
                                            value === 'all' ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Filter by severity"
                                >
                                    <SelectValue placeholder="All severities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All severities
                                    </SelectItem>
                                    <SelectItem value="critical">
                                        Critical
                                    </SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="medium">
                                        Medium
                                    </SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button type="submit">Search</Button>
                        </form>
                    </Card>

                    {selected.size > 0 ? (
                        // eslint-disable-next-line no-restricted-syntax -- This transient action bar is a sticky selection surface, not a content card.
                        <div className="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-primary/25 bg-background/95 p-3 shadow-lg backdrop-blur-sm">
                            <Badge variant="secondary">
                                {selected.size} selected
                            </Badge>
                            <span className="text-sm text-muted-foreground">
                                {escalatableIds.length} can be escalated
                            </span>
                            <div className="ml-auto flex gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSelected(new Set())}
                                >
                                    Clear selection
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={!escalatableIds.length}
                                    onClick={() => setBulkOpen(true)}
                                >
                                    <ArrowUpCircle
                                        className="h-4 w-4"
                                        aria-hidden
                                    />
                                    Escalate selected
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    <AlertWorklist
                        rows={worklist.data}
                        selected={selected}
                        onSelectionChange={setSelected}
                        onSort={() => undefined}
                        onOpen={openWorkspace}
                        getActions={rowActions}
                        heading="Escalation priority worklist"
                        description="Ordered by breached deadline, queue tier, severity, next deadline, time in queue, then oldest alert."
                        allowSorting={false}
                    />

                    {worklist.last_page > 1 ? (
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-muted-foreground">
                                Showing {worklist.from ?? 0}–{worklist.to ?? 0}{' '}
                                of {worklist.total} alerts
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {worklist.links.map((link, index) => (
                                    <Button
                                        key={`${link.label}-${index}`}
                                        type="button"
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        <span
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </CommandCentrePage>
            </PageShell>

            <Dialog open={bulkOpen} onOpenChange={setBulkOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Escalate selected alerts</DialogTitle>
                        <DialogDescription>
                            Record why these alerts need the next queue. The
                            server rechecks every alert, site scope, state, and
                            destination before writing.
                        </DialogDescription>
                    </DialogHeader>
                    <Textarea
                        value={bulkReason}
                        onChange={(event) => setBulkReason(event.target.value)}
                        placeholder="Reason for escalation"
                        aria-label="Escalation reason"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setBulkOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={!bulkReason.trim()}
                            onClick={submitBulkEscalation}
                        >
                            Escalate {escalatableIds.length} alert
                            {escalatableIds.length === 1 ? '' : 's'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {detail ? (
                <AlertWorkspaceDialog
                    detail={detail}
                    open
                    onClose={closeWorkspace}
                />
            ) : null}
        </AppLayout>
    );
}
