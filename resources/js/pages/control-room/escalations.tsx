import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertStatus } from '@/components/control-room/alert-worklist/alert-status';
import {
    AlertWorkspaceDialog,
    PaneNav,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import { BulkAlertActionDialog } from '@/components/control-room/bulk-alert-action-dialog';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    ChevronRight,
    Clock,
    ExternalLink,
    MoveRight,
    ShieldAlert,
    Timer,
    User,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// --- TypeScript Interfaces ---

interface AssignedUser {
    id: number;
    name: string;
}

interface AlertSla {
    acknowledge_deadline: string | null;
    response_deadline: string | null;
    resolution_deadline: string | null;
    acknowledged_at: string | null;
    responded_at: string | null;
    resolved_at: string | null;
    acknowledge_breached: boolean;
    response_breached: boolean;
    resolution_breached: boolean;
    status: string;
}

interface QueueAlert {
    id: number;
    reference_number: string | null;
    severity: string;
    alert_type: string;
    alert_type_raw?: string;
    source: string;
    status: string;
    escalation_level: number | null;
    triggered_at: string | null;
    acknowledged_at: string | null;
    assigned_to: AssignedUser | null;
    client_name: string | null;
    context: Record<string, unknown> | null;
    entered_queue_at: string | null;
    sla: AlertSla | null;
}

interface QueueData {
    id: number;
    name: string;
    code: string;
    tier: number;
    description: string | null;
    auto_escalate_after_minutes: number | null;
    escalate_to_queue_id: number | null;
    alert_count: number;
    alerts: QueueAlert[];
}

interface QueueOption {
    id: number;
    name: string;
    tier: number;
}

interface Props {
    queues: QueueData[];
    allQueues: QueueOption[];
    serverTime: string;
    can: {
        manage: boolean;
        assign: boolean;
    };
    /** Workspace-over-list: present when ?alert= is in the URL. */
    detail?: AlertWorkspaceDetail | null;
}

// --- Severity config ---

const severityBorder: Record<string, string> = {
    critical: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-blue-500',
};

const tierBgColors: Record<number, string> = {
    1: 'bg-status-info',
    2: 'bg-status-warning',
    3: 'bg-status-critical',
};

const tierColors: Record<number, string> = {
    1: 'bg-status-info-bg text-status-info border-status-info/30',
    2: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    3: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

// --- Helpers ---

function formatTimeInQueue(enteredAt: string | null, nowMs: number): string {
    if (!enteredAt) return '-';
    const enteredMs = new Date(enteredAt).getTime();
    const diffMs = nowMs - enteredMs;
    if (diffMs < 0) return '0m';

    const totalMinutes = Math.floor(diffMs / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
}

function computeSlaCountdown(
    deadline: string | null,
    completedAt: string | null,
    breached: boolean,
    nowMs: number,
): { label: string; color: 'green' | 'yellow' | 'red' | 'muted' } {
    if (completedAt) {
        return { label: 'Done', color: 'muted' };
    }
    if (!deadline) {
        return { label: '-', color: 'muted' };
    }
    if (breached) {
        return { label: 'BREACHED', color: 'red' };
    }

    const deadlineMs = new Date(deadline).getTime();
    const remainingMs = deadlineMs - nowMs;

    if (remainingMs <= 0) {
        return { label: 'BREACHED', color: 'red' };
    }

    const totalMinutes = Math.floor(remainingMs / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    const display = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;

    if (totalMinutes <= 5) {
        return { label: display, color: 'red' };
    }
    if (totalMinutes <= 30) {
        return { label: display, color: 'yellow' };
    }
    return { label: display, color: 'green' };
}

function getSlaStatusDotColor(
    deadline: string | null,
    completedAt: string | null,
    breached: boolean,
    nowMs: number,
): string {
    if (completedAt) return 'bg-status-success';
    if (!deadline) return 'bg-muted';
    if (breached) return 'bg-status-critical';
    const deadlineMs = new Date(deadline).getTime();
    const remainingMs = deadlineMs - nowMs;
    if (remainingMs <= 0) return 'bg-status-critical';
    const totalMinutes = Math.floor(remainingMs / 60000);
    if (totalMinutes <= 5) return 'bg-status-critical';
    if (totalMinutes <= 30) return 'bg-status-warning';
    return 'bg-status-success';
}

function getSlaCountdownColor(
    color: 'green' | 'yellow' | 'red' | 'muted',
): string {
    switch (color) {
        case 'green':
            return 'text-status-success';
        case 'yellow':
            return 'text-status-warning';
        case 'red':
            return 'text-status-critical font-semibold';
        case 'muted':
            return 'text-muted-foreground';
    }
}

function isAlertBreached(sla: AlertSla | null): boolean {
    if (!sla) return false;
    return (
        sla.acknowledge_breached ||
        sla.response_breached ||
        sla.resolution_breached
    );
}

function getInitial(name: string): string {
    return name.charAt(0).toUpperCase();
}

// --- Components ---

function SlaTimerDisplay({
    label,
    deadline,
    completedAt,
    breached,
    nowMs,
}: {
    label: string;
    deadline: string | null;
    completedAt: string | null;
    breached: boolean;
    nowMs: number;
}) {
    const countdown = computeSlaCountdown(
        deadline,
        completedAt,
        breached,
        nowMs,
    );
    const colorClass = getSlaCountdownColor(countdown.color);

    return (
        <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">{label}:</span>
            <span className={colorClass}>{countdown.label}</span>
        </div>
    );
}

function SlaDotsCompact({ sla, nowMs }: { sla: AlertSla; nowMs: number }) {
    const ackColor = getSlaStatusDotColor(
        sla.acknowledge_deadline,
        sla.acknowledged_at,
        sla.acknowledge_breached,
        nowMs,
    );
    const respColor = getSlaStatusDotColor(
        sla.response_deadline,
        sla.responded_at,
        sla.response_breached,
        nowMs,
    );
    const resColor = getSlaStatusDotColor(
        sla.resolution_deadline,
        sla.resolved_at,
        sla.resolution_breached,
        nowMs,
    );

    return (
        <div
            className="flex items-center gap-1.5"
            title="SLA: Ack / Respond / Resolve"
        >
            <div
                className={`h-2 w-2 rounded-full ${ackColor}`}
                title="Acknowledge"
            />
            <div
                className={`h-2 w-2 rounded-full ${respColor}`}
                title="Respond"
            />
            <div
                className={`h-2 w-2 rounded-full ${resColor}`}
                title="Resolve"
            />
        </div>
    );
}

function AlertCard({
    alert,
    allQueues,
    currentQueueId,
    canManage,
    nowMs,
    selectedAlertIds,
    onToggleSelect,
    onOpen,
}: {
    alert: QueueAlert;
    allQueues: QueueOption[];
    currentQueueId: number;
    canManage: boolean;
    nowMs: number;
    selectedAlertIds: Set<number>;
    onToggleSelect: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    const [moveOpen, setMoveOpen] = useState(false);
    const breached = isAlertBreached(alert.sla);
    const borderColor = severityBorder[alert.severity] ?? severityBorder.low;
    const isSelected = selectedAlertIds.has(alert.id);

    const otherQueues = allQueues.filter((q) => q.id !== currentQueueId);

    return (
        <div
            className={`rounded-lg border border-l-4 bg-card transition-all ${borderColor} ${
                breached
                    ? 'animate-pulse-subtle border-t-red-300 border-r-red-300 border-b-red-300 shadow-sm shadow-red-100'
                    : 'border-t-border border-r-border border-b-border'
            } ${isSelected ? 'ring-2 ring-primary ring-offset-1' : ''}`}
        >
            <div className="p-3">
                {/* Title row: checkbox, alert type, severity badge */}
                <div className="mb-2 flex items-start justify-between gap-2">
                    <div className="flex items-center gap-2">
                        {canManage && (
                            <input
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => onToggleSelect(alert.id)}
                                className="mt-0.5 h-3.5 w-3.5 rounded border-border"
                            />
                        )}
                        <div className="min-w-0">
                            <p className="truncate text-sm leading-tight font-semibold">
                                {alert.alert_type}
                            </p>
                            <span className="text-[10px] font-medium text-muted-foreground">
                                {alert.reference_number ?? `Alert ${alert.id}`}
                            </span>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-1.5">
                        <AlertStatus
                            status={alert.status}
                            severity={alert.severity}
                            slaStatus={alert.sla?.status}
                        />
                        {breached && (
                            <ShieldAlert className="h-3.5 w-3.5 text-status-critical" />
                        )}
                    </div>
                </div>

                {/* Detail row: source, client, time-in-queue */}
                <div className="mb-2 space-y-0.5 text-xs text-muted-foreground">
                    <div className="flex items-center gap-1.5">
                        <span className="capitalize">{alert.source}</span>
                        {alert.client_name && (
                            <>
                                <span className="text-border">|</span>
                                <span className="truncate">
                                    {alert.client_name}
                                </span>
                            </>
                        )}
                    </div>
                    <div className="flex items-center gap-1">
                        <Clock className="h-3 w-3 shrink-0" />
                        <span>
                            {formatTimeInQueue(alert.entered_queue_at, nowMs)}{' '}
                            in queue
                        </span>
                    </div>
                </div>

                {/* SLA compact dots */}
                {alert.sla && (
                    <div className="mb-2 flex items-center justify-between rounded bg-muted/50 px-2 py-1.5">
                        <SlaDotsCompact sla={alert.sla} nowMs={nowMs} />
                        <div className="flex gap-3">
                            <SlaTimerDisplay
                                label="Ack"
                                deadline={alert.sla.acknowledge_deadline}
                                completedAt={alert.sla.acknowledged_at}
                                breached={alert.sla.acknowledge_breached}
                                nowMs={nowMs}
                            />
                            <SlaTimerDisplay
                                label="Resp"
                                deadline={alert.sla.response_deadline}
                                completedAt={alert.sla.responded_at}
                                breached={alert.sla.response_breached}
                                nowMs={nowMs}
                            />
                            <SlaTimerDisplay
                                label="Res"
                                deadline={alert.sla.resolution_deadline}
                                completedAt={alert.sla.resolved_at}
                                breached={alert.sla.resolution_breached}
                                nowMs={nowMs}
                            />
                        </div>
                    </div>
                )}

                {/* Assigned row */}
                <div className="mb-2 flex items-center gap-1.5 text-xs">
                    {alert.assigned_to ? (
                        <>
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                {getInitial(alert.assigned_to.name)}
                            </div>
                            <span className="text-foreground">
                                {alert.assigned_to.name}
                            </span>
                        </>
                    ) : (
                        <>
                            <User className="h-3.5 w-3.5 text-muted-foreground/50" />
                            <span className="text-muted-foreground/50 italic">
                                Unassigned
                            </span>
                        </>
                    )}
                </div>

                {/* Action buttons row — work happens in the guided workspace */}
                <div className="flex items-center gap-1.5 border-t border-border/50 pt-2">
                    <Button
                        size="sm"
                        variant="default"
                        className="h-7 px-2 text-xs"
                        onClick={() => onOpen(alert.id)}
                    >
                        <ExternalLink className="mr-1 h-3 w-3" />
                        Open alert
                    </Button>

                    {/* Move to queue — stepped dialog */}
                    {canManage && otherQueues.length > 0 && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto h-7 px-2 text-xs"
                            onClick={() => setMoveOpen(true)}
                        >
                            <MoveRight className="mr-1 h-3 w-3" />
                            Move
                        </Button>
                    )}
                </div>
            </div>

            {moveOpen ? (
                <MoveQueueDialog
                    alert={alert}
                    queues={otherQueues}
                    onClose={() => setMoveOpen(false)}
                />
            ) : null}
        </div>
    );
}

/** Stepped queue move: review the alert → pick the target queue → confirm. */
function MoveQueueDialog({
    alert,
    queues,
    onClose,
}: {
    alert: QueueAlert;
    queues: QueueOption[];
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [targetId, setTargetId] = useState('');
    const [busy, setBusy] = useState(false);
    const target = queues.find((q) => String(q.id) === targetId);

    // Queue names are often already tier-labelled ("Tier 1"), so only prepend the
    // tier prefix when the name doesn't already carry it — avoids "Tier 1: Tier 1".
    const queueLabel = (q: QueueOption) =>
        q.name.trim().toLowerCase().startsWith('tier')
            ? q.name
            : `Tier ${q.tier}: ${q.name}`;

    const submit = () => {
        if (!target || busy) return;
        setBusy(true);
        router.post(
            `/control-room/escalations/${alert.id}/move`,
            { target_queue_id: target.id },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle className="sr-only">
                    Move alert to another queue
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Choose the queue this alert should move to.
                </DialogDescription>
                <div className="flex flex-col gap-4">
                    <StepHead
                        icon={MoveRight}
                        title={`Move ${alert.reference_number ?? `Alert ${alert.id}`} to another queue`}
                        blurb="The alert leaves its current queue and joins the target queue's worklist. The move is recorded on the queue history."
                    />
                    {step === 0 ? (
                        <>
                            <Field label="Target queue" required>
                                <SelectInput
                                    value={targetId}
                                    onChange={setTargetId}
                                    placeholder="Select a queue"
                                    options={queues.map((q) => ({
                                        value: String(q.id),
                                        label: queueLabel(q),
                                    }))}
                                />
                            </Field>
                            <PaneNav
                                onCancel={onClose}
                                onNext={() => setStep(1)}
                                nextDisabled={!target}
                                step={0}
                                stepCount={2}
                            />
                        </>
                    ) : (
                        <>
                            <div className="rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm">
                                <span className="font-medium text-foreground">
                                    {alert.reference_number ??
                                        `Alert ${alert.id}`}{' '}
                                    · {alert.alert_type}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    → {target ? queueLabel(target) : ''}
                                </span>
                            </div>
                            <PaneNav
                                onCancel={onClose}
                                onBack={() => setStep(0)}
                                onSubmit={submit}
                                submitLabel="Move alert"
                                processing={busy}
                                step={1}
                                stepCount={2}
                            />
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function QueueColumn({
    queue,
    allQueues,
    canManage,
    nowMs,
    selectedAlertIds,
    onToggleSelect,
    onOpen,
}: {
    queue: QueueData;
    allQueues: QueueOption[];
    canManage: boolean;
    nowMs: number;
    selectedAlertIds: Set<number>;
    onToggleSelect: (id: number) => void;
    onOpen: (id: number) => void;
}) {
    const breachedCount = queue.alerts.filter((a) =>
        isAlertBreached(a.sla),
    ).length;
    const capacityPercent = Math.min(100, (queue.alert_count / 20) * 100);
    const tierBg = tierBgColors[queue.tier] ?? tierBgColors[1];

    return (
        <div className="flex min-w-[340px] flex-col rounded-lg border bg-muted/30">
            {/* Queue header */}
            <div className="border-b p-4">
                <div className="mb-2 flex items-center gap-3">
                    {/* Tier number with colored circle */}
                    <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg font-bold text-white ${tierBg}`}
                    >
                        {queue.tier}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <h3 className="truncate text-sm font-semibold">
                                {queue.name}
                            </h3>
                            <Badge
                                variant="secondary"
                                className="shrink-0 tabular-nums"
                            >
                                {queue.alert_count}
                            </Badge>
                            {breachedCount > 0 && (
                                <Badge
                                    variant="destructive"
                                    className="shrink-0 px-1.5 text-[10px] tabular-nums"
                                >
                                    {breachedCount} breached
                                </Badge>
                            )}
                        </div>
                        {queue.auto_escalate_after_minutes && (
                            <div className="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Timer className="h-3 w-3" />
                                Auto-escalates after{' '}
                                {queue.auto_escalate_after_minutes}m
                            </div>
                        )}
                    </div>
                </div>

                {queue.description && (
                    <p className="mb-2 text-xs text-muted-foreground">
                        {queue.description}
                    </p>
                )}

                {/* Capacity progress bar */}
                <div className="mt-1">
                    <div className="mb-0.5 flex items-center justify-between text-[10px] text-muted-foreground">
                        <span>Capacity</span>
                        <span>{queue.alert_count} / 20</span>
                    </div>
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full transition-all ${
                                queue.alert_count === 0
                                    ? 'bg-status-success'
                                    : queue.alert_count <= 5
                                      ? 'bg-status-success'
                                      : queue.alert_count <= 10
                                        ? 'bg-status-warning'
                                        : 'bg-status-critical'
                            }`}
                            style={{ width: `${capacityPercent}%` }}
                        />
                    </div>
                </div>
            </div>

            {/* Alert cards — the column shows the top of the queue; the badge
                carries the full count */}
            <div
                className="flex-1 space-y-2 overflow-y-auto p-3"
                style={{ maxHeight: 'calc(100vh - 360px)' }}
            >
                {queue.alert_count > queue.alerts.length ? (
                    <p className="rounded-md bg-muted/60 px-2 py-1 text-center text-[11px] text-muted-foreground">
                        Showing the top {queue.alerts.length} of{' '}
                        {queue.alert_count} — work from the top down.
                    </p>
                ) : null}
                {queue.alerts.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <CheckCircle2 className="mb-2 h-8 w-8 text-status-success" />
                        <p className="text-sm text-muted-foreground">
                            Queue clear
                        </p>
                    </div>
                ) : (
                    queue.alerts.map((alert) => (
                        <AlertCard
                            key={alert.id}
                            alert={alert}
                            allQueues={allQueues}
                            currentQueueId={queue.id}
                            canManage={canManage}
                            nowMs={nowMs}
                            selectedAlertIds={selectedAlertIds}
                            onToggleSelect={onToggleSelect}
                            onOpen={onOpen}
                        />
                    ))
                )}
            </div>
        </div>
    );
}

// --- Main Page Component ---

export default function EscalationQueue({
    queues,
    allQueues,
    serverTime,
    can,
    detail = null,
}: Props) {
    const [nowMs, setNowMs] = useState(() => new Date(serverTime).getTime());
    const [selectedAlertIds, setSelectedAlertIds] = useState<Set<number>>(
        new Set(),
    );
    const [bulkOpen, setBulkOpen] = useState(false);

    // Workspace-over-list: fetch only the `detail` prop and open the dialog
    // over the board; closing drops the param again.
    const openWorkspace = (id: number) => {
        const params = new URLSearchParams(window.location.search);
        params.set('alert', String(id));
        router.get(
            `/control-room/escalations?${params.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const closeWorkspace = () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('alert');
        router.get(
            `/control-room/escalations${params.size ? `?${params.toString()}` : ''}`,
            {},
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownTimerRef = useRef<ReturnType<typeof setInterval> | null>(
        null,
    );

    // Client-side countdown ticker. 30s granularity — SLA timers on cards read
    // in minutes/hours, and a 1s tick re-rendered every card on the board each
    // second, which made large boards visibly janky.
    useEffect(() => {
        countdownTimerRef.current = setInterval(() => {
            setNowMs((prev) => prev + 30000);
        }, 30000);

        return () => {
            if (countdownTimerRef.current)
                clearInterval(countdownTimerRef.current);
        };
    }, []);

    // Sync nowMs when serverTime changes (after a reload)
    useEffect(() => {
        setNowMs(new Date(serverTime).getTime());
    }, [serverTime]);

    // 30-second auto-refresh
    useEffect(() => {
        refreshTimerRef.current = setInterval(() => {
            router.reload({ only: ['queues', 'serverTime'] });
        }, 30000);

        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, []);

    const handleToggleSelect = useCallback((alertId: number) => {
        setSelectedAlertIds((prev) => {
            const next = new Set(prev);
            if (next.has(alertId)) {
                next.delete(alertId);
            } else {
                next.add(alertId);
            }
            return next;
        });
    }, []);

    const selectedAlerts = queues
        .flatMap((q) => q.alerts)
        .filter((a) => selectedAlertIds.has(a.id));

    const totalAlerts = queues.reduce((sum, q) => sum + q.alert_count, 0);
    const totalBreached = queues.reduce(
        (sum, q) => sum + q.alerts.filter((a) => isAlertBreached(a.sla)).length,
        0,
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Escalation Queue', href: '#' },
            ]}
        >
            <Head title="Escalation Queue - Control Room" />
            <PageShell>
                <CommandCentrePage
                    current="/control-room/escalations"
                    icon={AlertTriangle}
                    title="Escalations"
                    description="Escalation queues — SLA-tracked tiers with guided moves and escalations."
                    status="Live escalation workspace"
                    freshness="Auto-refreshing every 30 seconds"
                    badges={{ '/control-room/escalations': totalAlerts }}
                >
                    {/* Summary stats */}
                    <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">
                                    Active Queues
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {queues.length}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">
                                    Total Alerts
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {totalAlerts}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">
                                    SLA Breached
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p
                                    className={`text-2xl font-bold ${totalBreached > 0 ? 'text-status-critical' : ''}`}
                                >
                                    {totalBreached}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">
                                    Selected
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {selectedAlertIds.size}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Kanban columns */}
                    {queues.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <CheckCircle2 className="mb-4 h-12 w-12 text-status-success" />
                                <p className="text-lg font-medium text-muted-foreground">
                                    No active triage queues configured
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Set up triage queues to enable the
                                    escalation workflow.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="flex items-start gap-0 overflow-x-auto pb-4">
                            {queues.map((queue, index) => (
                                <div
                                    key={queue.id}
                                    className="flex items-start"
                                >
                                    {index > 0 && (
                                        <div className="flex items-center self-stretch px-1 pt-20">
                                            <ChevronRight className="h-5 w-5 text-muted-foreground/40" />
                                        </div>
                                    )}
                                    <QueueColumn
                                        queue={queue}
                                        allQueues={allQueues}
                                        canManage={can.manage}
                                        nowMs={nowMs}
                                        selectedAlertIds={selectedAlertIds}
                                        onToggleSelect={handleToggleSelect}
                                        onOpen={openWorkspace}
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                </CommandCentrePage>
            </PageShell>

            {/* Floating bulk action bar */}
            {can.manage && selectedAlertIds.size > 0 && (
                <div className="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 transform">
                    <Card className="flex items-center gap-3 px-5 py-3 shadow-lg">
                        <span className="text-sm font-medium">
                            {selectedAlertIds.size} alert
                            {selectedAlertIds.size !== 1 ? 's' : ''} selected
                        </span>
                        <Button
                            size="sm"
                            variant="default"
                            onClick={() => setBulkOpen(true)}
                        >
                            <ArrowUpRight className="mr-1.5 h-4 w-4" />
                            Escalate {selectedAlertIds.size} Selected
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelectedAlertIds(new Set())}
                        >
                            Clear
                        </Button>
                    </Card>
                </div>
            )}

            {/* Stepped bulk escalate — review selection, then a required reason */}
            {bulkOpen ? (
                <BulkAlertActionDialog
                    mode="escalate"
                    open
                    onClose={() => setBulkOpen(false)}
                    alerts={selectedAlerts.map((a) => ({
                        id: a.id,
                        alert_type: a.alert_type,
                        severity: a.severity,
                        status: a.status,
                        client_name: a.client_name,
                    }))}
                    onDone={() => setSelectedAlertIds(new Set())}
                />
            ) : null}

            {/* Workspace-over-list */}
            {detail ? (
                <AlertWorkspaceDialog
                    detail={detail}
                    open
                    onClose={closeWorkspace}
                />
            ) : null}

            {/* Pulse animation style */}
            <style>{`
                @keyframes pulse-subtle {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.85; }
                }
                .animate-pulse-subtle {
                    animation: pulse-subtle 2s ease-in-out infinite;
                }
            `}</style>
        </AppLayout>
    );
}
