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
    ArrowDown,
    ArrowUp,
    Camera,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Clock,
    File,
    FileText,
    History,
    Mail,
    MapPin,
    MessageSquare,
    Phone,
    Play,
    SkipForward,
    Timer,
    TrendingUp,
    User,
    Video,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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

interface PlaybookStep {
    id: number;
    title: string;
    status: 'pending' | 'in_progress' | 'completed' | 'skipped' | 'failed';
    notes: string | null;
    completed_at: string | null;
}

interface PlaybookRun {
    id: number;
    status: string;
    current_step: number;
    completed_steps: number;
    total_steps: number;
    playbook: {
        id: number;
        name: string;
        category: string;
    };
    steps: PlaybookStep[];
}

interface EvidenceItem {
    id: number;
    type: string;
    title: string;
    file_path: string | null;
    created_at: string | null;
}

interface EvidencePack {
    id: number;
    title: string;
    status: string;
    item_count: number;
    items: EvidenceItem[];
}

interface Communication {
    id: number;
    channel: 'in_app' | 'sms' | 'email' | 'phone';
    direction: 'inbound' | 'outbound';
    purpose: string | null;
    status: string;
    content: string | null;
    target_user_name: string | null;
    sent_at: string | null;
    created_at: string | null;
}

interface SlaData {
    acknowledge_deadline: string | null;
    response_deadline: string | null;
    resolution_deadline: string | null;
    acknowledge_breached: boolean;
    response_breached: boolean;
    resolution_breached: boolean;
}

interface ClientRef {
    id: number;
    name: string;
}

interface LocationData {
    lat: number;
    lng: number;
    description: string | null;
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
    playbook_run: PlaybookRun | null;
    evidence_packs: EvidencePack[];
    communications: Communication[];
    sla: SlaData | null;
    client: ClientRef | null;
    location: LocationData | null;
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

const stepStatusColors: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    in_progress: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    skipped: 'bg-yellow-100 text-yellow-800',
    failed: 'bg-red-100 text-red-800',
};

const channelColors: Record<string, string> = {
    in_app: 'bg-blue-100 text-blue-800',
    sms: 'bg-green-100 text-green-800',
    email: 'bg-purple-100 text-purple-800',
    phone: 'bg-orange-100 text-orange-800',
};

const channelIcons: Record<string, typeof MessageSquare> = {
    in_app: MessageSquare,
    sms: Phone,
    email: Mail,
    phone: Phone,
};

const evidenceTypeIcons: Record<string, typeof File> = {
    photo: Camera,
    document: File,
    cctv_bookmark: Video,
    note: FileText,
};

function formatCountdown(deadline: string | null): string {
    if (!deadline) return '-';
    const diff = new Date(deadline).getTime() - Date.now();
    if (diff <= 0) return 'EXPIRED';
    const hrs = Math.floor(diff / 3600000);
    const mins = Math.floor((diff % 3600000) / 60000);
    const secs = Math.floor((diff % 60000) / 1000);
    if (hrs > 0) return `${hrs}h ${mins}m ${secs}s`;
    if (mins > 0) return `${mins}m ${secs}s`;
    return `${secs}s`;
}

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

export default function ControlRoomShow({ alert, playbook_run, evidence_packs, communications, sla, client, location, audit_logs, can, staff }: Props) {
    const [notes, setNotes] = useState('');
    const [assignTo, setAssignTo] = useState(
        alert.assigned_to_user_id?.toString() || '',
    );
    const [escalationReason, setEscalationReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [expandedPacks, setExpandedPacks] = useState<Record<number, boolean>>({});
    const [, setTick] = useState(0);

    // Live SLA countdown ticker
    useEffect(() => {
        if (!sla) return;
        const hasActive = [sla.acknowledge_deadline, sla.response_deadline, sla.resolution_deadline].some(
            (d) => d && new Date(d).getTime() > Date.now(),
        );
        if (!hasActive) return;
        const interval = setInterval(() => setTick((t) => t + 1), 1000);
        return () => clearInterval(interval);
    }, [sla]);

    const togglePack = (id: number) => {
        setExpandedPacks((prev) => ({ ...prev, [id]: !prev[id] }));
    };

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

                        {/* Playbook Execution */}
                        {playbook_run && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Play className="h-5 w-5" />
                                        Playbook: {playbook_run.playbook.name}
                                    </CardTitle>
                                    <CardDescription>
                                        {playbook_run.playbook.category} &middot; {playbook_run.completed_steps}/{playbook_run.total_steps} steps completed
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {/* Progress bar */}
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary transition-all"
                                            style={{ width: `${playbook_run.total_steps > 0 ? (playbook_run.completed_steps / playbook_run.total_steps) * 100 : 0}%` }}
                                        />
                                    </div>
                                    {/* Steps list */}
                                    <div className="space-y-2">
                                        {playbook_run.steps.map((step) => (
                                            <div key={step.id} className="flex items-center justify-between rounded-md border p-3">
                                                <div className="flex items-center gap-3">
                                                    {step.status === 'completed' ? (
                                                        <CheckCircle className="h-4 w-4 text-green-500" />
                                                    ) : step.status === 'in_progress' ? (
                                                        <Clock className="h-4 w-4 text-blue-500" />
                                                    ) : step.status === 'failed' ? (
                                                        <XCircle className="h-4 w-4 text-red-500" />
                                                    ) : step.status === 'skipped' ? (
                                                        <SkipForward className="h-4 w-4 text-yellow-500" />
                                                    ) : (
                                                        <div className="h-4 w-4 rounded-full border-2 border-gray-300" />
                                                    )}
                                                    <div>
                                                        <p className="text-sm font-medium">{step.title}</p>
                                                        {step.completed_at && (
                                                            <p className="text-xs text-muted-foreground">
                                                                Completed {formatRelativeTime(step.completed_at)}
                                                            </p>
                                                        )}
                                                        {step.notes && (
                                                            <p className="text-xs text-muted-foreground">{step.notes}</p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge className={stepStatusColors[step.status] || 'bg-gray-100 text-gray-700'}>
                                                        {step.status.replace('_', ' ')}
                                                    </Badge>
                                                    {step.status === 'in_progress' && can.manage && (
                                                        <div className="flex gap-1">
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => handleAction('playbook-step-complete', { step_id: step.id })}
                                                                disabled={processing}
                                                            >
                                                                Complete
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => handleAction('playbook-step-skip', { step_id: step.id })}
                                                                disabled={processing}
                                                            >
                                                                Skip
                                                            </Button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Evidence Packs */}
                        {evidence_packs.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2">
                                            <File className="h-5 w-5" />
                                            Evidence
                                        </CardTitle>
                                        {can.manage && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => handleAction('evidence-pack-create')}
                                                disabled={processing}
                                            >
                                                Create Evidence Pack
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {evidence_packs.map((pack) => (
                                        <div key={pack.id} className="rounded-md border">
                                            <button
                                                type="button"
                                                className="flex w-full items-center justify-between p-3 text-left hover:bg-muted/50"
                                                onClick={() => togglePack(pack.id)}
                                            >
                                                <div className="flex items-center gap-2">
                                                    {expandedPacks[pack.id] ? (
                                                        <ChevronDown className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4" />
                                                    )}
                                                    <span className="text-sm font-medium">{pack.title}</span>
                                                    <Badge variant="secondary" className="text-xs">
                                                        {pack.item_count} items
                                                    </Badge>
                                                    <Badge variant="outline" className="text-xs">
                                                        {pack.status}
                                                    </Badge>
                                                </div>
                                            </button>
                                            {expandedPacks[pack.id] && pack.items.length > 0 && (
                                                <div className="border-t px-3 pb-3 pt-2">
                                                    <div className="space-y-1">
                                                        {pack.items.map((item) => {
                                                            const IconComponent = evidenceTypeIcons[item.type] || File;
                                                            return (
                                                                <div key={item.id} className="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted/50">
                                                                    <IconComponent className="h-4 w-4 text-muted-foreground" />
                                                                    <span>{item.title}</span>
                                                                    {item.created_at && (
                                                                        <span className="ml-auto text-xs text-muted-foreground">
                                                                            {formatRelativeTime(item.created_at)}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {/* Communication Log */}
                        {communications.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <MessageSquare className="h-5 w-5" />
                                        Communications
                                    </CardTitle>
                                    <CardDescription>
                                        {communications.length} message{communications.length !== 1 ? 's' : ''}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="relative">
                                        <div className="absolute left-3 top-0 h-full w-px bg-border" />
                                        <div className="space-y-4">
                                            {communications.map((comm) => {
                                                const ChannelIcon = channelIcons[comm.channel] || MessageSquare;
                                                return (
                                                    <div key={comm.id} className="relative flex gap-4 pl-8">
                                                        <div className="absolute left-1.5 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                                                        <div className="flex-1 space-y-1">
                                                            <div className="flex items-center gap-2">
                                                                <Badge className={channelColors[comm.channel] || 'bg-gray-100 text-gray-700'}>
                                                                    <ChannelIcon className="mr-1 h-3 w-3" />
                                                                    {comm.channel.replace('_', ' ')}
                                                                </Badge>
                                                                {comm.direction === 'outbound' ? (
                                                                    <ArrowUp className="h-3 w-3 text-blue-500" />
                                                                ) : (
                                                                    <ArrowDown className="h-3 w-3 text-green-500" />
                                                                )}
                                                                <span className="text-xs text-muted-foreground">
                                                                    {comm.direction}
                                                                </span>
                                                                {comm.purpose && (
                                                                    <Badge variant="outline" className="text-xs">
                                                                        {comm.purpose}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            {comm.content && (
                                                                <p className="text-sm">{comm.content}</p>
                                                            )}
                                                            <p className="text-xs text-muted-foreground">
                                                                {comm.target_user_name && <span>To: {comm.target_user_name} &middot; </span>}
                                                                {formatDateTime(comm.sent_at || comm.created_at)}
                                                            </p>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
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
                        {/* SLA Status */}
                        {sla && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Timer className="h-4 w-4" />
                                        SLA Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {[
                                        { label: 'Acknowledge', deadline: sla.acknowledge_deadline, breached: sla.acknowledge_breached, met: !!alert.acknowledged_at },
                                        { label: 'Response', deadline: sla.response_deadline, breached: sla.response_breached, met: alert.status === 'triaging' || alert.status === 'resolved' || alert.status === 'closed' },
                                        { label: 'Resolution', deadline: sla.resolution_deadline, breached: sla.resolution_breached, met: alert.status === 'resolved' || alert.status === 'closed' },
                                    ].map((row) => (
                                        <div key={row.label} className="flex items-center justify-between">
                                            <span className="text-sm font-medium">{row.label}</span>
                                            <div className="text-right">
                                                {row.breached ? (
                                                    <span className="text-sm font-bold text-red-600">BREACHED</span>
                                                ) : row.met ? (
                                                    <span className="text-sm font-bold text-green-600">MET</span>
                                                ) : row.deadline ? (
                                                    <div>
                                                        <span className="text-sm font-mono font-medium">
                                                            {formatCountdown(row.deadline)}
                                                        </span>
                                                        <p className="text-xs text-muted-foreground">
                                                            {new Date(row.deadline).toLocaleTimeString()}
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">N/A</span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {/* Related Client */}
                        {client && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Related Client</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <Link
                                        href={`/clients/${client.id}`}
                                        className="flex items-center gap-2 font-medium text-primary hover:underline"
                                    >
                                        <User className="h-4 w-4" />
                                        {client.name}
                                    </Link>
                                </CardContent>
                            </Card>
                        )}

                        {/* Location */}
                        {location && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <MapPin className="h-4 w-4" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="flex aspect-video items-center justify-center rounded-md bg-muted text-sm text-muted-foreground">
                                        <div className="text-center">
                                            <MapPin className="mx-auto h-8 w-8 mb-1" />
                                            <p className="font-mono text-xs">{location.lat.toFixed(6)}, {location.lng.toFixed(6)}</p>
                                        </div>
                                    </div>
                                    {location.description && (
                                        <p className="text-sm text-muted-foreground">{location.description}</p>
                                    )}
                                    <Button variant="outline" size="sm" className="w-full" asChild>
                                        <a
                                            href={`https://www.google.com/maps?q=${location.lat},${location.lng}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <MapPin className="mr-2 h-3 w-3" />
                                            View on Map
                                        </a>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

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
