import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Clock,
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
    severity: string;
    alert_type: string;
    source: string;
    status: string;
    escalation_level: number | null;
    triggered_at: string | null;
    acknowledged_at: string | null;
    assigned_to: AssignedUser | null;
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
    };
}

// --- Severity config ---

const severityConfig: Record<string, { label: string; className: string; order: number }> = {
    critical: { label: 'Critical', className: 'bg-red-600 text-white', order: 0 },
    high: { label: 'High', className: 'bg-orange-500 text-white', order: 1 },
    medium: { label: 'Medium', className: 'bg-yellow-500 text-white', order: 2 },
    low: { label: 'Low', className: 'bg-blue-500 text-white', order: 3 },
};

const tierColors: Record<number, string> = {
    1: 'bg-blue-100 text-blue-800 border-blue-200',
    2: 'bg-orange-100 text-orange-800 border-orange-200',
    3: 'bg-red-100 text-red-800 border-red-200',
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

    // Calculate percentage of original time remaining
    // Use the triggered_at -> deadline span to compute
    // Simplified: use absolute thresholds
    if (totalMinutes <= 5) {
        return { label: display, color: 'red' };
    }
    if (totalMinutes <= 30) {
        return { label: display, color: 'yellow' };
    }
    return { label: display, color: 'green' };
}

function getSlaCountdownColor(color: 'green' | 'yellow' | 'red' | 'muted'): string {
    switch (color) {
        case 'green':
            return 'text-green-600';
        case 'yellow':
            return 'text-yellow-600';
        case 'red':
            return 'text-red-600 font-semibold';
        case 'muted':
            return 'text-muted-foreground';
    }
}

function isAlertBreached(sla: AlertSla | null): boolean {
    if (!sla) return false;
    return sla.acknowledge_breached || sla.response_breached || sla.resolution_breached;
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
    const countdown = computeSlaCountdown(deadline, completedAt, breached, nowMs);
    const colorClass = getSlaCountdownColor(countdown.color);

    return (
        <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">{label}:</span>
            <span className={colorClass}>{countdown.label}</span>
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
}: {
    alert: QueueAlert;
    allQueues: QueueOption[];
    currentQueueId: number;
    canManage: boolean;
    nowMs: number;
    selectedAlertIds: Set<number>;
    onToggleSelect: (id: number) => void;
}) {
    const [moving, setMoving] = useState(false);
    const breached = isAlertBreached(alert.sla);
    const sev = severityConfig[alert.severity] ?? severityConfig.low;
    const isSelected = selectedAlertIds.has(alert.id);

    const handleMove = (targetQueueId: string) => {
        if (moving) return;
        setMoving(true);
        router.post(
            `/control-room/escalations/${alert.id}/move`,
            { target_queue_id: parseInt(targetQueueId, 10) },
            {
                preserveScroll: true,
                onFinish: () => setMoving(false),
            },
        );
    };

    const otherQueues = allQueues.filter((q) => q.id !== currentQueueId);

    return (
        <div
            className={`rounded-lg border bg-card p-3 transition-all ${
                breached ? 'animate-pulse-subtle border-red-400 shadow-red-100 shadow-sm' : 'border-border'
            } ${isSelected ? 'ring-2 ring-primary ring-offset-1' : ''}`}
        >
            <div className="mb-2 flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                    {canManage && (
                        <input
                            type="checkbox"
                            checked={isSelected}
                            onChange={() => onToggleSelect(alert.id)}
                            className="mt-0.5 h-3.5 w-3.5 rounded border-gray-300"
                        />
                    )}
                    <Badge className={sev.className}>{sev.label}</Badge>
                    <span className="text-xs font-medium text-muted-foreground">#{alert.id}</span>
                </div>
                {breached && (
                    <ShieldAlert className="h-4 w-4 shrink-0 text-red-500" />
                )}
            </div>

            <div className="mb-2">
                <p className="text-sm font-medium leading-tight">{alert.alert_type}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    Source: {alert.source}
                    {alert.status !== 'open' && (
                        <span className="ml-2 capitalize">({alert.status})</span>
                    )}
                </p>
            </div>

            {/* Time in queue */}
            <div className="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
                <Clock className="h-3 w-3" />
                <span>In queue: {formatTimeInQueue(alert.entered_queue_at, nowMs)}</span>
            </div>

            {/* Assigned to */}
            {alert.assigned_to && (
                <div className="mb-2 flex items-center gap-1 text-xs text-muted-foreground">
                    <User className="h-3 w-3" />
                    <span>{alert.assigned_to.name}</span>
                </div>
            )}

            {/* SLA timers */}
            {alert.sla && (
                <div className="mb-2 space-y-0.5 rounded bg-muted/50 p-2">
                    <SlaTimerDisplay
                        label="Ack"
                        deadline={alert.sla.acknowledge_deadline}
                        completedAt={alert.sla.acknowledged_at}
                        breached={alert.sla.acknowledge_breached}
                        nowMs={nowMs}
                    />
                    <SlaTimerDisplay
                        label="Respond"
                        deadline={alert.sla.response_deadline}
                        completedAt={alert.sla.responded_at}
                        breached={alert.sla.response_breached}
                        nowMs={nowMs}
                    />
                    <SlaTimerDisplay
                        label="Resolve"
                        deadline={alert.sla.resolution_deadline}
                        completedAt={alert.sla.resolved_at}
                        breached={alert.sla.resolution_breached}
                        nowMs={nowMs}
                    />
                </div>
            )}

            {/* Move to queue action */}
            {canManage && otherQueues.length > 0 && (
                <div className="mt-2">
                    <Select onValueChange={handleMove} disabled={moving}>
                        <SelectTrigger className="h-7 text-xs">
                            <MoveRight className="mr-1 h-3 w-3" />
                            <SelectValue placeholder="Move to queue..." />
                        </SelectTrigger>
                        <SelectContent>
                            {otherQueues.map((q) => (
                                <SelectItem key={q.id} value={String(q.id)}>
                                    Tier {q.tier}: {q.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
        </div>
    );
}

function QueueColumn({
    queue,
    allQueues,
    canManage,
    nowMs,
    selectedAlertIds,
    onToggleSelect,
}: {
    queue: QueueData;
    allQueues: QueueOption[];
    canManage: boolean;
    nowMs: number;
    selectedAlertIds: Set<number>;
    onToggleSelect: (id: number) => void;
}) {
    const tierColor = tierColors[queue.tier] ?? tierColors[1];
    const breachedCount = queue.alerts.filter((a) => isAlertBreached(a.sla)).length;

    return (
        <div className="flex min-w-[320px] flex-col rounded-lg border bg-muted/30">
            {/* Queue header */}
            <div className="border-b p-4">
                <div className="mb-2 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <h3 className="text-sm font-semibold">{queue.name}</h3>
                        <Badge variant="outline" className={tierColor}>
                            Tier {queue.tier}
                        </Badge>
                    </div>
                    <Badge variant="secondary" className="tabular-nums">
                        {queue.alert_count}
                    </Badge>
                </div>

                {queue.description && (
                    <p className="mb-2 text-xs text-muted-foreground">{queue.description}</p>
                )}

                {/* Capacity / status bar */}
                <div className="flex items-center gap-3 text-xs">
                    {breachedCount > 0 && (
                        <span className="flex items-center gap-1 text-red-600">
                            <AlertTriangle className="h-3 w-3" />
                            {breachedCount} breached
                        </span>
                    )}
                    {queue.auto_escalate_after_minutes && (
                        <span className="flex items-center gap-1 text-muted-foreground">
                            <Timer className="h-3 w-3" />
                            Auto-escalate: {queue.auto_escalate_after_minutes}m
                        </span>
                    )}
                </div>

                {/* Visual capacity bar */}
                <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className={`h-full rounded-full transition-all ${
                            queue.alert_count === 0
                                ? 'bg-green-400'
                                : queue.alert_count <= 5
                                  ? 'bg-green-500'
                                  : queue.alert_count <= 10
                                    ? 'bg-yellow-500'
                                    : 'bg-red-500'
                        }`}
                        style={{
                            width: `${Math.min(100, (queue.alert_count / 20) * 100)}%`,
                        }}
                    />
                </div>
            </div>

            {/* Alert cards */}
            <div className="flex-1 space-y-2 overflow-y-auto p-3" style={{ maxHeight: 'calc(100vh - 320px)' }}>
                {queue.alerts.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <CheckCircle2 className="mb-2 h-8 w-8 text-green-400" />
                        <p className="text-sm text-muted-foreground">Queue clear</p>
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
                        />
                    ))
                )}
            </div>
        </div>
    );
}

// --- Main Page Component ---

export default function EscalationQueue({ queues, allQueues, serverTime, can }: Props) {
    const [nowMs, setNowMs] = useState(() => new Date(serverTime).getTime());
    const [selectedAlertIds, setSelectedAlertIds] = useState<Set<number>>(new Set());
    const [bulkEscalating, setBulkEscalating] = useState(false);
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Client-side countdown ticker (1 second)
    useEffect(() => {
        countdownTimerRef.current = setInterval(() => {
            setNowMs((prev) => prev + 1000);
        }, 1000);

        return () => {
            if (countdownTimerRef.current) clearInterval(countdownTimerRef.current);
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

    const handleBulkEscalate = () => {
        if (selectedAlertIds.size === 0 || bulkEscalating) return;
        setBulkEscalating(true);
        router.post(
            '/control-room/escalations/bulk-escalate',
            { alert_ids: Array.from(selectedAlertIds) },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBulkEscalating(false);
                    setSelectedAlertIds(new Set());
                },
            },
        );
    };

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
                <PageHeader
                    title="Escalation Queue"
                    description="Kanban-style triage queue management with SLA tracking and escalation workflows."
                    actions={
                        can.manage && selectedAlertIds.size > 0 ? (
                            <Button
                                size="sm"
                                variant="default"
                                onClick={handleBulkEscalate}
                                disabled={bulkEscalating}
                            >
                                <ArrowUpRight className="mr-2 h-4 w-4" />
                                Escalate {selectedAlertIds.size} Alert{selectedAlertIds.size !== 1 ? 's' : ''}
                            </Button>
                        ) : undefined
                    }
                />

                {/* Summary stats */}
                <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Active Queues
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{queues.length}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Total Alerts
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{totalAlerts}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                SLA Breached
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-bold ${totalBreached > 0 ? 'text-red-600' : ''}`}>
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
                            <p className="text-2xl font-bold">{selectedAlertIds.size}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Kanban columns */}
                {queues.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <CheckCircle2 className="mb-4 h-12 w-12 text-green-400" />
                            <p className="text-lg font-medium text-muted-foreground">
                                No active triage queues configured
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Set up triage queues to enable the escalation workflow.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex gap-4 overflow-x-auto pb-4">
                        {queues.map((queue) => (
                            <QueueColumn
                                key={queue.id}
                                queue={queue}
                                allQueues={allQueues}
                                canManage={can.manage}
                                nowMs={nowMs}
                                selectedAlertIds={selectedAlertIds}
                                onToggleSelect={handleToggleSelect}
                            />
                        ))}
                    </div>
                )}
            </PageShell>

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
