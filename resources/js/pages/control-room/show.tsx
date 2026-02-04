import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle,
    Clock,
    History,
    MessageSquare,
    TrendingUp,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface FleetSignal {
    id: number;
    signal_type: string;
    severity_hint: string;
    occurred_at: string | null;
    payload: Record<string, any> | null;
}

interface UserRef {
    id: number;
    name: string;
}

interface AuditLogEntry {
    id: number;
    action: string;
    user: UserRef | null;
    meta: Record<string, any> | null;
    created_at: string;
}

interface Alert {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    asset_id: number | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    fleet_signal_id: number | null;
    fleet_signal: FleetSignal | null;
    assigned_to_user_id: number | null;
    assigned_to: { id: number; name: string; email: string } | null;
    acknowledged_by: UserRef | null;
    resolved_by: UserRef | null;
    closed_by: UserRef | null;
    escalated_by: UserRef | null;
    assigned_by: UserRef | null;
    created_by: UserRef | null;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    escalated_at: string | null;
    assigned_at: string | null;
    escalation_level: number;
    context: Record<string, any> | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
}

interface Props {
    alert: Alert;
    audit_logs: AuditLogEntry[];
    can: {
        manage: boolean;
        assign: boolean;
        escalate: boolean;
    };
    staff: { id: number; name: string; email: string }[];
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-blue-500 text-white',
};

const statusColors: Record<string, string> = {
    open: 'bg-red-100 text-red-800 border-red-200',
    ack: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    triaging: 'bg-blue-100 text-blue-800 border-blue-200',
    resolved: 'bg-green-100 text-green-800 border-green-200',
    closed: 'bg-gray-100 text-gray-800 border-gray-200',
};

const actionLabels: Record<string, string> = {
    'controlRoom.alert.view': 'Viewed alert',
    'controlRoom.alert.acknowledge': 'Acknowledged alert',
    'controlRoom.alert.triage': 'Started triage',
    'controlRoom.alert.resolve': 'Resolved alert',
    'controlRoom.alert.close': 'Closed alert',
    'controlRoom.alert.assign': 'Assigned alert',
    'controlRoom.alert.unassign': 'Unassigned alert',
    'controlRoom.alert.escalate': 'Escalated alert',
    'controlRoom.alert.addNote': 'Added note',
    'controlRoom.alert.create': 'Created alert',
};

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString();
}

function formatRelativeTime(isoString: string): string {
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString();
}

export default function ControlRoomShow({ alert, audit_logs, can, staff }: Props) {
    const [notes, setNotes] = useState('');
    const [assignTo, setAssignTo] = useState(
        alert.assigned_to_user_id?.toString() || '',
    );
    const [escalationReason, setEscalationReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const handleAction = (
        action: string,
        data: Record<string, any> = {},
    ) => {
        setProcessing(true);
        router.post(
            `/control-room/alerts/${alert.id}/${action}`,
            data,
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const handleAssign = () => {
        if (!assignTo) return;
        handleAction('assign', { assigned_to_user_id: parseInt(assignTo) });
    };

    const handleEscalate = () => {
        if (!escalationReason.trim()) return;
        handleAction('escalate', { escalation_reason: escalationReason });
        setEscalationReason('');
    };

    const handleAddNote = () => {
        if (!notes.trim()) return;
        handleAction('note', { note: notes });
        setNotes('');
    };

    const isClosed = alert.status === 'closed' || alert.status === 'resolved';

    // Filter out view actions from audit trail for cleaner display
    const significantAuditLogs = audit_logs.filter(
        (log) => log.action !== 'controlRoom.alert.view',
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: `Alert #${alert.id}`, href: '#' },
            ]}
        >
            <Head title={`Alert #${alert.id}`} />
            <PageShell>
                <PageHeader
                    title={
                        <div className="flex items-center gap-3">
                            <span>{alert.alert_type}</span>
                            <Badge className={severityColors[alert.severity]}>
                                {alert.severity}
                            </Badge>
                            <Badge
                                variant="outline"
                                className={statusColors[alert.status]}
                            >
                                {alert.status}
                            </Badge>
                            {alert.escalation_level > 0 && (
                                <Badge
                                    variant="outline"
                                    className="border-orange-300 text-orange-600"
                                >
                                    Escalation L{alert.escalation_level}
                                </Badge>
                            )}
                        </div>
                    }
                    description={`Alert #${alert.id} | Source: ${alert.source}`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/control-room">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Dashboard
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Main Content */}
                    <div className="space-y-4 lg:col-span-2">
                        {/* Alert Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Alert Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Triggered At
                                        </Label>
                                        <p className="font-medium">
                                            {formatDateTime(alert.triggered_at)}
                                        </p>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Source
                                        </Label>
                                        <p className="font-medium">
                                            {alert.source}
                                        </p>
                                    </div>
                                    {alert.acknowledged_at && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Acknowledged
                                            </Label>
                                            <p className="font-medium">
                                                {formatDateTime(alert.acknowledged_at)}
                                            </p>
                                            {alert.acknowledged_by && (
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <User className="h-3 w-3" />
                                                    by {alert.acknowledged_by.name}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    {alert.resolved_at && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Resolved
                                            </Label>
                                            <p className="font-medium">
                                                {formatDateTime(alert.resolved_at)}
                                            </p>
                                            {alert.resolved_by && (
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <User className="h-3 w-3" />
                                                    by {alert.resolved_by.name}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    {alert.closed_at && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Closed
                                            </Label>
                                            <p className="font-medium">
                                                {formatDateTime(alert.closed_at)}
                                            </p>
                                            {alert.closed_by && (
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <User className="h-3 w-3" />
                                                    by {alert.closed_by.name}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    {alert.escalated_at && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Escalated to L{alert.escalation_level}
                                            </Label>
                                            <p className="font-medium">
                                                {formatDateTime(alert.escalated_at)}
                                            </p>
                                            {alert.escalated_by && (
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <User className="h-3 w-3" />
                                                    by {alert.escalated_by.name}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    {alert.assigned_to && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Assigned To
                                            </Label>
                                            <p className="flex items-center gap-1 font-medium">
                                                <User className="h-4 w-4" />
                                                {alert.assigned_to.name}
                                            </p>
                                            {alert.assigned_by && alert.assigned_at && (
                                                <p className="text-xs text-muted-foreground">
                                                    by {alert.assigned_by.name} at{' '}
                                                    {formatDateTime(alert.assigned_at)}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {alert.asset && (
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Related Asset
                                        </Label>
                                        <p>
                                            <Link
                                                href={`/assets/${alert.asset.id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {alert.asset.name}
                                            </Link>
                                            {alert.asset.asset_tag && (
                                                <span className="ml-2 text-muted-foreground">
                                                    ({alert.asset.asset_tag})
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                )}

                                {alert.notes && (
                                    <div>
                                        <Label className="text-xs text-muted-foreground">
                                            Notes
                                        </Label>
                                        <p className="whitespace-pre-wrap rounded-md bg-muted p-3 text-sm">
                                            {alert.notes}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Fleet Signal Details */}
                        {alert.fleet_signal && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Fleet Signal</CardTitle>
                                    <CardDescription>
                                        Signal that triggered this alert
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Signal Type
                                            </Label>
                                            <p className="font-medium">
                                                {alert.fleet_signal.signal_type}
                                            </p>
                                        </div>
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Occurred At
                                            </Label>
                                            <p className="font-medium">
                                                {formatDateTime(
                                                    alert.fleet_signal
                                                        .occurred_at,
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    {alert.fleet_signal.payload && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">
                                                Payload
                                            </Label>
                                            <pre className="mt-1 overflow-auto rounded-md bg-muted p-3 text-xs">
                                                {JSON.stringify(
                                                    alert.fleet_signal.payload,
                                                    null,
                                                    2,
                                                )}
                                            </pre>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Activity Log (Notes) */}
                        {(alert.context?.activity_log?.length ?? 0) > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {alert.context!.activity_log.map(
                                            (entry: any, i: number) => (
                                                <div
                                                    key={i}
                                                    className="flex gap-3 border-l-2 border-muted pl-3"
                                                >
                                                    <MessageSquare className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                                    <div>
                                                        <p className="text-sm">
                                                            {entry.content}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {entry.user_name} -{' '}
                                                            {formatDateTime(
                                                                entry.created_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Audit Trail */}
                        {significantAuditLogs.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <History className="h-5 w-5" />
                                        Audit Trail
                                    </CardTitle>
                                    <CardDescription>
                                        Complete history of actions on this alert
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="relative">
                                        <div className="absolute left-3 top-0 h-full w-px bg-border" />
                                        <div className="space-y-4">
                                            {significantAuditLogs.map((log) => (
                                                <div
                                                    key={log.id}
                                                    className="relative flex gap-4 pl-8"
                                                >
                                                    <div className="absolute left-1.5 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                                                    <div className="flex-1">
                                                        <p className="text-sm font-medium">
                                                            {actionLabels[log.action] ||
                                                                log.action.split('.').pop()}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {log.user?.name || 'System'} -{' '}
                                                            {formatRelativeTime(log.created_at)}
                                                        </p>
                                                        {log.meta && Object.keys(log.meta).length > 0 && (
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                {log.meta.escalation_level && (
                                                                    <span>Level: {log.meta.escalation_level}</span>
                                                                )}
                                                                {log.meta.assigned_to && (
                                                                    <span>Assigned to ID: {log.meta.assigned_to}</span>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {new Date(log.created_at).toLocaleTimeString()}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Actions Sidebar */}
                    <div className="space-y-4">
                        {/* Quick Actions */}
                        {can.manage && !isClosed && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Quick Actions
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {alert.status === 'open' && (
                                        <Button
                                            className="w-full"
                                            variant="outline"
                                            onClick={() =>
                                                handleAction('acknowledge')
                                            }
                                            disabled={processing}
                                        >
                                            <CheckCircle className="mr-2 h-4 w-4" />
                                            Acknowledge
                                        </Button>
                                    )}
                                    {(alert.status === 'open' ||
                                        alert.status === 'ack') && (
                                        <Button
                                            className="w-full"
                                            variant="outline"
                                            onClick={() =>
                                                handleAction('triage')
                                            }
                                            disabled={processing}
                                        >
                                            <Clock className="mr-2 h-4 w-4" />
                                            Start Triage
                                        </Button>
                                    )}
                                    {alert.status !== 'resolved' &&
                                        alert.status !== 'closed' && (
                                            <Button
                                                className="w-full"
                                                variant="default"
                                                onClick={() => {
                                                    const resNotes =
                                                        prompt(
                                                            'Enter resolution notes:',
                                                        );
                                                    if (resNotes) {
                                                        handleAction('resolve', {
                                                            resolution_notes:
                                                                resNotes,
                                                        });
                                                    }
                                                }}
                                                disabled={processing}
                                            >
                                                <CheckCircle className="mr-2 h-4 w-4" />
                                                Resolve
                                            </Button>
                                        )}
                                    {alert.status === 'resolved' && (
                                        <Button
                                            className="w-full"
                                            variant="secondary"
                                            onClick={() =>
                                                handleAction('close')
                                            }
                                            disabled={processing}
                                        >
                                            <XCircle className="mr-2 h-4 w-4" />
                                            Close
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Assignment */}
                        {can.assign && !isClosed && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Assignment
                                    </CardTitle>
                                    {alert.assigned_to && (
                                        <CardDescription>
                                            Currently assigned to{' '}
                                            {alert.assigned_to.name}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Select
                                        value={assignTo}
                                        onValueChange={setAssignTo}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select assignee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={s.id.toString()}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="flex gap-2">
                                        <Button
                                            onClick={handleAssign}
                                            disabled={!assignTo || processing}
                                            className="flex-1"
                                        >
                                            <User className="mr-2 h-4 w-4" />
                                            Assign
                                        </Button>
                                        {alert.assigned_to && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    handleAction('unassign')
                                                }
                                                disabled={processing}
                                            >
                                                Unassign
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Escalation */}
                        {can.escalate && !isClosed && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Escalation
                                    </CardTitle>
                                    <CardDescription>
                                        Current level: {alert.escalation_level}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Textarea
                                        placeholder="Escalation reason..."
                                        value={escalationReason}
                                        onChange={(e) =>
                                            setEscalationReason(e.target.value)
                                        }
                                        rows={2}
                                    />
                                    <Button
                                        className="w-full"
                                        variant="destructive"
                                        onClick={handleEscalate}
                                        disabled={
                                            !escalationReason.trim() ||
                                            processing
                                        }
                                    >
                                        <TrendingUp className="mr-2 h-4 w-4" />
                                        Escalate to L
                                        {(alert.escalation_level || 0) + 1}
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {/* Add Note */}
                        {can.manage && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Add Note
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Textarea
                                        placeholder="Add a note..."
                                        value={notes}
                                        onChange={(e) =>
                                            setNotes(e.target.value)
                                        }
                                        rows={3}
                                    />
                                    <Button
                                        className="w-full"
                                        variant="secondary"
                                        onClick={handleAddNote}
                                        disabled={!notes.trim() || processing}
                                    >
                                        <MessageSquare className="mr-2 h-4 w-4" />
                                        Add Note
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {/* Alert Closed */}
                        {isClosed && (
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <CheckCircle className="h-5 w-5 text-green-500" />
                                        <span>
                                            This alert has been{' '}
                                            {alert.status === 'closed'
                                                ? 'closed'
                                                : 'resolved'}
                                            {alert.status === 'resolved' && alert.resolved_by && (
                                                <> by {alert.resolved_by.name}</>
                                            )}
                                            {alert.status === 'closed' && alert.closed_by && (
                                                <> by {alert.closed_by.name}</>
                                            )}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
