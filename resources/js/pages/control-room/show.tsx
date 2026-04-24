import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Head, Link, router } from '@inertiajs/react';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertTriangle,
    ArrowUpRight,
    BookOpen,
    Check,
    CheckCircle2,
    Clock,
    Eye,
    ExternalLink,
    FileText,
    MapPin,
    MessageSquare,
    Package,
    Phone,
    Mail,
    Play,
    Search,
    Send,
    Shield,
    ShieldAlert,
    SkipForward,
    Truck,
    Upload,
    User,
    UserCheck,
    UserMinus,
    Users,
    XCircle,
} from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface FleetSignal {
    id: number;
    signal_type: string;
    severity_hint: string;
    occurred_at: string | null;
    payload: Record<string, any> | null;
}

interface FleetContext {
    vehicle?: { id: number; name: string; asset_tag?: string; registration?: string; home_site?: string };
    driver?: { id: number };
    geofence?: { id: number; name: string };
    trip?: { id: number; started_at?: string; ended_at?: string; distance_km?: number; start_address?: string | null; end_address?: string | null };
    booking?: { id: number; purpose?: string; booked_by_user_id?: number };
    outing?: { id: number; title: string };
    affected_resident_count?: number;
    location?: { lat: number; lng: number; speed_kph?: number; last_seen_at?: string };
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
    fleet_context: FleetContext | null;
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
    client: { id: number; name: string } | null;
    location: { lat: number; lng: number; description: string | null } | null;
    audit_logs: AuditLogEntry[];
    can: { manage: boolean; assign: boolean; escalate: boolean };
    staff: { id: number; name: string; email: string }[];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const SEVERITY_BORDER: Record<string, string> = {
    critical: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-blue-500',
};

const SEVERITY_BADGE: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-info text-white',
};

const STATUS_BADGE: Record<string, string> = {
    open: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    acknowledged: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    triaging: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    resolved: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    closed: 'bg-muted text-foreground dark:bg-muted/30 dark:text-muted-foreground',
};

const WORKFLOW_STEPS = ['open', 'acknowledged', 'triaging', 'resolved', 'closed'] as const;

function fmtDate(d: string | null): string {
    if (!d) return '\u2014';
    return new Date(d).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fmtShortDate(d: string | null): string {
    if (!d) return '';
    return new Date(d).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function timeAgo(d: string | null): string {
    if (!d) return '';
    const diff = Date.now() - new Date(d).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ${mins % 60}m ago`;
    const days = Math.floor(hrs / 24);
    return `${days}d ${hrs % 24}h ago`;
}

function useCountdown(deadline: string | null, breached: boolean): string {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!deadline || breached) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [deadline, breached]);

    if (!deadline) return '\u2014';
    if (breached) return 'BREACHED';
    const remaining = new Date(deadline).getTime() - now;
    if (remaining <= 0) return 'BREACHED';
    const h = Math.floor(remaining / 3600000);
    const m = Math.floor((remaining % 3600000) / 60000);
    const s = Math.floor((remaining % 60000) / 1000);
    if (h > 0) return `${h}h ${m}m ${s}s`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
}

function getStepTimestamp(alert: Alert, step: string): string | null {
    switch (step) {
        case 'open': return alert.triggered_at;
        case 'acknowledged': return alert.acknowledged_at;
        case 'triaging': return alert.context?.triaging_at ?? null;
        case 'resolved': return alert.resolved_at;
        case 'closed': return alert.closed_at;
        default: return null;
    }
}

function stepIndex(status: string): number {
    const idx = WORKFLOW_STEPS.indexOf(status as (typeof WORKFLOW_STEPS)[number]);
    return idx >= 0 ? idx : 0;
}

function channelIcon(ch: Communication['channel']) {
    switch (ch) {
        case 'email': return <Mail className="h-4 w-4" />;
        case 'sms': return <MessageSquare className="h-4 w-4" />;
        case 'phone': return <Phone className="h-4 w-4" />;
        default: return <Send className="h-4 w-4" />;
    }
}

function initial(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function StatusStepper({ alert }: { alert: Alert }) {
    const currentIdx = stepIndex(alert.status);

    return (
        <div className="flex items-start justify-between gap-0 mt-5">
            {WORKFLOW_STEPS.map((step, i) => {
                const completed = i < currentIdx;
                const current = i === currentIdx;
                const ts = getStepTimestamp(alert, step);

                return (
                    <div key={step} className="flex flex-1 items-start">
                        <div className="flex flex-col items-center min-w-[72px]">
                            <div
                                className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-semibold transition-colors ${
                                    completed
                                        ? 'border-status-success/30 bg-status-success text-white'
                                        : current
                                          ? 'border-primary bg-primary text-primary-foreground'
                                          : 'border-muted-foreground/30 bg-muted text-muted-foreground'
                                }`}
                            >
                                {completed ? <Check className="h-4 w-4" /> : i + 1}
                            </div>
                            <span
                                className={`mt-1.5 text-xs font-medium capitalize ${
                                    completed
                                        ? 'text-status-success dark:text-status-success'
                                        : current
                                          ? 'text-foreground'
                                          : 'text-muted-foreground'
                                }`}
                            >
                                {step}
                            </span>
                            {ts && (
                                <span className="text-[10px] text-muted-foreground">
                                    {fmtShortDate(ts)}
                                </span>
                            )}
                        </div>
                        {i < WORKFLOW_STEPS.length - 1 && (
                            <div className="flex-1 pt-4 px-1">
                                <div
                                    className={`h-0.5 w-full rounded ${
                                        i < currentIdx
                                            ? 'bg-status-success'
                                            : 'bg-muted-foreground/20'
                                    }`}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function SlaGauge({
    label,
    deadline,
    breached,
    met,
}: {
    label: string;
    deadline: string | null;
    breached: boolean;
    met: boolean;
}) {
    const countdown = useCountdown(deadline, breached);

    const now = Date.now();
    let pct = 100;
    let colorCls = 'bg-status-success';
    let textCls = 'text-status-success dark:text-status-success';
    let statusLabel = 'On Track';

    if (breached || (deadline && new Date(deadline).getTime() <= now)) {
        pct = 100;
        colorCls = 'bg-status-critical';
        textCls = 'text-status-critical dark:text-status-critical';
        statusLabel = 'Breached';
    } else if (met) {
        pct = 100;
        colorCls = 'bg-status-success';
        textCls = 'text-status-success dark:text-status-success';
        statusLabel = 'Met';
    } else if (deadline) {
        const deadlineMs = new Date(deadline).getTime();
        const remaining = Math.max(0, deadlineMs - now);
        // Use 1hr as reference window for the progress bar
        pct = Math.min(100, Math.max(5, 100 - (remaining / (remaining + 3600000)) * 100));
        if (remaining < 900000) {
            // < 15 min
            colorCls = 'bg-status-critical';
            textCls = 'text-status-critical dark:text-status-critical';
            statusLabel = 'At Risk';
        } else if (remaining < 3600000) {
            // < 1hr
            colorCls = 'bg-status-warning';
            textCls = 'text-status-warning dark:text-status-warning';
            statusLabel = 'At Risk';
        }
    }

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between text-xs">
                <span className="font-medium">{label}</span>
                <span className={`font-semibold ${textCls}`}>{statusLabel}</span>
            </div>
            <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${colorCls}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                <span>{deadline ? fmtShortDate(deadline) : 'No SLA'}</span>
                <span className={`font-mono font-semibold ${textCls}`}>{countdown}</span>
            </div>
        </div>
    );
}

function MetaRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function ControlRoomAlertShow({
    alert,
    audit_logs,
    can,
    staff,
    playbook_run,
    evidence_packs,
    communications,
    sla,
    client,
    location,
}: Props) {
    const [resolveOpen, setResolveOpen] = useState(false);
    const [resolveNotes, setResolveNotes] = useState('');
    const [escalateOpen, setEscalateOpen] = useState(false);
    const [escalateReason, setEscalateReason] = useState('');
    const [newNote, setNewNote] = useState('');
    const [assigneeId, setAssigneeId] = useState<string>('');
    const [processing, setProcessing] = useState(false);
    const notesEndRef = useRef<HTMLDivElement>(null);

    const activityLog: Array<{
        content: string;
        user?: string;
        timestamp?: string;
        is_self?: boolean;
    }> = alert.context?.activity_log ?? [];

    useEffect(() => {
        notesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [activityLog.length]);

    function doAction(action: string, data: Record<string, any> = {}) {
        setProcessing(true);
        router.post(`/control-room/alerts/${alert.id}/${action}`, data, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    }

    function handleResolve() {
        if (!resolveNotes.trim()) return;
        doAction('resolve', { notes: resolveNotes });
        setResolveOpen(false);
        setResolveNotes('');
    }

    function handleEscalate() {
        if (!escalateReason.trim()) return;
        doAction('escalate', { reason: escalateReason });
        setEscalateOpen(false);
        setEscalateReason('');
    }

    function handleAddNote() {
        if (!newNote.trim()) return;
        doAction('note', { content: newNote });
        setNewNote('');
    }

    function handleAssign(staffId: string) {
        doAction('assign', { staff_id: staffId });
        setAssigneeId('');
    }

    const breadcrumbs = [
        { title: 'Control Room', href: '/control-room' },
        { title: `Alert #${alert.id}`, href: '#' },
    ];

    const ackMet = !!alert.acknowledged_at;
    const resMet = !!alert.resolved_at;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Alert #${alert.id}`} />
            <PageShell>
                {/* ============================================================ */}
                {/* HERO BANNER                                                  */}
                {/* ============================================================ */}
                <div
                    className={`rounded-xl border border-l-4 bg-card p-6 shadow-sm ${
                        SEVERITY_BORDER[alert.severity] ?? 'border-l-gray-400'
                    }`}
                >
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        {/* Left side */}
                        <div className="space-y-1">
                            <h1 className="text-2xl font-bold tracking-tight">
                                {alert.alert_type
                                    .replace(/_/g, ' ')
                                    .replace(/\b\w/g, (c) => c.toUpperCase())}
                            </h1>
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span className="font-mono text-xs">#{alert.id}</span>
                                <Badge variant="outline" className="text-xs capitalize">
                                    {alert.source}
                                </Badge>
                                <span className="flex items-center gap-1">
                                    <Clock className="h-3.5 w-3.5" />
                                    {fmtDate(alert.triggered_at)}
                                </span>
                                {alert.triggered_at && (
                                    <span className="text-xs text-muted-foreground">
                                        ({timeAgo(alert.triggered_at)})
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Right side */}
                        <div className="flex items-center gap-3">
                            <Badge
                                className={`px-3 py-1 text-sm font-semibold capitalize ${
                                    STATUS_BADGE[alert.status] ?? ''
                                }`}
                            >
                                {alert.status}
                            </Badge>
                            <Badge
                                className={`px-3 py-1 text-sm font-semibold capitalize ${
                                    SEVERITY_BADGE[alert.severity] ?? 'bg-muted-foreground/80 text-white'
                                }`}
                            >
                                {alert.severity}
                            </Badge>
                            {alert.escalation_level > 0 && (
                                <Badge
                                    variant="destructive"
                                    className="px-3 py-1 text-sm font-semibold"
                                >
                                    <ShieldAlert className="mr-1 h-3.5 w-3.5" />
                                    L{alert.escalation_level}
                                </Badge>
                            )}
                        </div>
                    </div>

                    {/* Status Stepper */}
                    <StatusStepper alert={alert} />
                </div>

                {/* ============================================================ */}
                {/* MAIN GRID: Content + Sidebar                                 */}
                {/* ============================================================ */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* ---- Left: Tabbed Content ---- */}
                    <div className="lg:col-span-2 space-y-4">
                        <Tabs defaultValue="details">
                            <TabsList className="w-full justify-start flex-wrap h-auto gap-1 bg-transparent p-0 border-b rounded-none">
                                <TabsTrigger
                                    value="details"
                                    className="data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none"
                                >
                                    Details
                                </TabsTrigger>
                                {playbook_run && (
                                    <TabsTrigger
                                        value="playbook"
                                        className="data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none"
                                    >
                                        <BookOpen className="mr-1.5 h-3.5 w-3.5" />
                                        Playbook
                                    </TabsTrigger>
                                )}
                                <TabsTrigger
                                    value="evidence"
                                    className="data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none"
                                >
                                    Evidence
                                    {evidence_packs.length > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]"
                                        >
                                            {evidence_packs.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger
                                    value="comms"
                                    className="data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none"
                                >
                                    Communications
                                    {communications.length > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]"
                                        >
                                            {communications.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger
                                    value="audit"
                                    className="data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none"
                                >
                                    Audit Trail
                                </TabsTrigger>
                            </TabsList>

                            {/* ====== Tab: Details ====== */}
                            <TabsContent value="details" className="space-y-6 pt-4">
                                {/* Metadata Grid */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">Alert Information</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2 text-sm">
                                            <MetaRow
                                                label="Triggered"
                                                value={fmtDate(alert.triggered_at)}
                                            />
                                            <MetaRow
                                                label="Source"
                                                value={
                                                    <Badge
                                                        variant="outline"
                                                        className="capitalize text-xs"
                                                    >
                                                        {alert.source}
                                                    </Badge>
                                                }
                                            />
                                            <MetaRow
                                                label="Acknowledged by"
                                                value={
                                                    alert.acknowledged_by
                                                        ? `${alert.acknowledged_by.name} \u2014 ${fmtDate(alert.acknowledged_at)}`
                                                        : '\u2014'
                                                }
                                            />
                                            <MetaRow
                                                label="Resolved by"
                                                value={
                                                    alert.resolved_by
                                                        ? `${alert.resolved_by.name} \u2014 ${fmtDate(alert.resolved_at)}`
                                                        : '\u2014'
                                                }
                                            />
                                            <MetaRow
                                                label="Closed by"
                                                value={
                                                    alert.closed_by
                                                        ? `${alert.closed_by.name} \u2014 ${fmtDate(alert.closed_at)}`
                                                        : '\u2014'
                                                }
                                            />
                                            {alert.escalation_level > 0 && (
                                                <MetaRow
                                                    label="Escalated"
                                                    value={
                                                        <>
                                                            L{alert.escalation_level}
                                                            {alert.escalated_by &&
                                                                ` by ${alert.escalated_by.name}`}
                                                            {alert.escalated_at &&
                                                                ` \u2014 ${fmtDate(alert.escalated_at)}`}
                                                        </>
                                                    }
                                                />
                                            )}
                                            {alert.created_by && (
                                                <MetaRow
                                                    label="Created by"
                                                    value={alert.created_by.name}
                                                />
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Asset */}
                                {alert.asset && (
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Package className="h-4 w-4" />
                                                Linked Asset
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <Link
                                                href={`/fleet-assets/assets/${alert.asset.id}`}
                                                className="text-sm font-medium text-primary hover:underline"
                                            >
                                                {alert.asset.name}{' '}
                                                <span className="text-muted-foreground">
                                                    ({alert.asset.asset_tag})
                                                </span>
                                            </Link>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Client */}
                                {client && (
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <User className="h-4 w-4" />
                                                Linked Client
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <Link
                                                href={`/clients/${client.id}`}
                                                className="text-sm font-medium text-primary hover:underline"
                                            >
                                                {client.name}
                                            </Link>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Fleet Context */}
                                {alert.fleet_signal && (() => {
                                    const fc = alert.fleet_context;
                                    const sig = alert.fleet_signal;
                                    return (
                                        <Card className="border-l-4 border-l-purple-500">
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <Truck className="h-4 w-4 text-primary" />
                                                    Fleet Context
                                                    <Badge className={`capitalize text-xs ml-auto ${SEVERITY_BADGE[sig.severity_hint] ?? ''}`}>
                                                        {sig.severity_hint}
                                                    </Badge>
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="text-sm space-y-3">
                                                {/* Signal type + time */}
                                                <div className="flex items-center justify-between">
                                                    <span className="font-medium capitalize">{sig.signal_type.replace(/[._]/g, ' ')}</span>
                                                    {sig.occurred_at && <span className="text-xs text-muted-foreground">{fmtDate(sig.occurred_at)}</span>}
                                                </div>

                                                {/* Vehicle */}
                                                {fc?.vehicle && (
                                                    <div className="rounded-md bg-muted/50 p-3 space-y-1">
                                                        <div className="flex items-center gap-2 text-xs font-semibold uppercase text-muted-foreground"><Truck className="h-3 w-3" /> Vehicle</div>
                                                        <div className="flex items-center justify-between">
                                                            <Link href={`/fleet-assets/vehicles/${fc.vehicle.id}`} className="font-medium text-primary hover:underline">{fc.vehicle.name}</Link>
                                                            {fc.vehicle.registration && <span className="text-xs text-muted-foreground">{fc.vehicle.registration}</span>}
                                                        </div>
                                                        {fc.vehicle.home_site && <div className="text-xs text-muted-foreground">Home: {fc.vehicle.home_site}</div>}
                                                    </div>
                                                )}

                                                {/* Driver */}
                                                {fc?.driver && (
                                                    <div className="flex items-center gap-2">
                                                        <User className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                        <span className="text-xs text-muted-foreground">Driver:</span>
                                                        <span className="font-medium">Staff #{fc.driver.id}</span>
                                                    </div>
                                                )}

                                                {/* Geofence */}
                                                {fc?.geofence && (
                                                    <div className="flex items-center gap-2">
                                                        <Shield className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                        <span className="text-xs text-muted-foreground">Geofence:</span>
                                                        <span className="font-medium">{fc.geofence.name}</span>
                                                    </div>
                                                )}

                                                {/* Affected Residents */}
                                                {fc?.affected_resident_count != null && fc.affected_resident_count > 0 && (
                                                    <div className="rounded-md bg-status-warning-bg dark:bg-status-warning p-3">
                                                        <div className="flex items-center gap-2 text-xs font-semibold uppercase text-status-warning dark:text-status-warning"><Users className="h-3 w-3" /> Affected Residents ({fc.affected_resident_count})</div>
                                                    </div>
                                                )}

                                                {/* Booking + Outing */}
                                                {(fc?.booking || fc?.outing) && (
                                                    <div className="space-y-1.5">
                                                        {fc?.booking && (
                                                            <div className="flex items-center gap-2 text-xs">
                                                                <Package className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                                <span className="text-muted-foreground">Booking:</span>
                                                                <Link href={`/fleet-assets/bookings/${fc.booking.id}`} className="text-primary hover:underline">{fc.booking.purpose ?? `#${fc.booking.id}`}</Link>
                                                            </div>
                                                        )}
                                                        {fc?.outing && (
                                                            <div className="flex items-center gap-2 text-xs">
                                                                <MapPin className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                                <span className="text-muted-foreground">Outing:</span>
                                                                <Link href={`/fleet-assets/outings/${fc.outing.id}`} className="text-primary hover:underline">{fc.outing.title}</Link>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Trip */}
                                                {fc?.trip && (
                                                    <div className="flex items-center gap-2 text-xs">
                                                        <ArrowUpRight className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                        <span className="text-muted-foreground">Trip:</span>
                                                        {fc.trip.distance_km != null && <span>{Number(fc.trip.distance_km).toFixed(1)} km</span>}
                                                        {fc.trip.start_address && <span className="text-muted-foreground">from {fc.trip.start_address}</span>}
                                                        {fc.trip.end_address && <span className="text-muted-foreground">to {fc.trip.end_address}</span>}
                                                    </div>
                                                )}

                                                {/* Location */}
                                                {fc?.location && (
                                                    <div className="flex items-center gap-2 text-xs">
                                                        <MapPin className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                        <span className="text-muted-foreground">Last known:</span>
                                                        <span>{fc.location.lat.toFixed(5)}, {fc.location.lng.toFixed(5)}</span>
                                                        {fc.location.speed_kph != null && <span className="text-muted-foreground">{fc.location.speed_kph} km/h</span>}
                                                        {fc.location.last_seen_at && <span className="text-muted-foreground">{timeAgo(fc.location.last_seen_at)}</span>}
                                                    </div>
                                                )}

                                                {/* Fallback: raw payload if no enriched context */}
                                                {!fc && sig.payload && Object.keys(sig.payload).length > 0 && (
                                                    <div className="rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">
                                                        {JSON.stringify(sig.payload, null, 2)}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    );
                                })()}

                                {/* Notes / Activity */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <MessageSquare className="h-4 w-4" />
                                            Notes &amp; Activity
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
                                            {activityLog.length === 0 && (
                                                <p className="text-sm text-muted-foreground italic">
                                                    No activity notes yet.
                                                </p>
                                            )}
                                            {activityLog.map((entry, i) => (
                                                <div
                                                    key={i}
                                                    className={`flex ${
                                                        entry.is_self
                                                            ? 'justify-end'
                                                            : 'justify-start'
                                                    }`}
                                                >
                                                    <div
                                                        className={`max-w-[80%] rounded-xl px-4 py-2.5 text-sm ${
                                                            entry.is_self
                                                                ? 'bg-primary text-primary-foreground rounded-br-sm'
                                                                : 'bg-muted rounded-bl-sm'
                                                        }`}
                                                    >
                                                        {entry.user && (
                                                            <div
                                                                className={`text-xs font-semibold mb-0.5 ${
                                                                    entry.is_self
                                                                        ? 'text-primary-foreground/80'
                                                                        : 'text-muted-foreground'
                                                                }`}
                                                            >
                                                                {entry.user}
                                                            </div>
                                                        )}
                                                        <p>{entry.content}</p>
                                                        {entry.timestamp && (
                                                            <div
                                                                className={`text-[10px] mt-1 ${
                                                                    entry.is_self
                                                                        ? 'text-primary-foreground/60'
                                                                        : 'text-muted-foreground'
                                                                }`}
                                                            >
                                                                {fmtShortDate(entry.timestamp)}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                            <div ref={notesEndRef} />
                                        </div>

                                        {/* Add Note */}
                                        {can.manage && (
                                            <div className="mt-4 flex gap-2">
                                                <Textarea
                                                    value={newNote}
                                                    onChange={(e) => setNewNote(e.target.value)}
                                                    placeholder="Add a note..."
                                                    className="min-h-[40px] resize-none text-sm"
                                                    rows={1}
                                                    onKeyDown={(e) => {
                                                        if (
                                                            e.key === 'Enter' &&
                                                            !e.shiftKey
                                                        ) {
                                                            e.preventDefault();
                                                            handleAddNote();
                                                        }
                                                    }}
                                                />
                                                <Button
                                                    size="sm"
                                                    onClick={handleAddNote}
                                                    disabled={!newNote.trim() || processing}
                                                >
                                                    <Send className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* ====== Tab: Playbook ====== */}
                            {playbook_run && (
                                <TabsContent value="playbook" className="space-y-4 pt-4">
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base flex items-center justify-between">
                                                <span className="flex items-center gap-2">
                                                    <BookOpen className="h-4 w-4" />
                                                    {playbook_run.playbook.name}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className="capitalize text-xs"
                                                >
                                                    {playbook_run.status}
                                                </Badge>
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="space-y-1">
                                                <div className="flex items-center justify-between text-xs text-muted-foreground">
                                                    <span>Progress</span>
                                                    <span>
                                                        {playbook_run.completed_steps}/
                                                        {playbook_run.total_steps} steps
                                                    </span>
                                                </div>
                                                <Progress
                                                    value={playbook_run.completed_steps}
                                                    max={playbook_run.total_steps}
                                                    className="h-2.5"
                                                />
                                            </div>

                                            <div className="divide-y">
                                                {playbook_run.steps.map((step, i) => (
                                                    <div
                                                        key={step.id}
                                                        className="flex items-center gap-3 py-3"
                                                    >
                                                        <div className="flex-shrink-0">
                                                            {step.status === 'completed' ? (
                                                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-status-success text-white">
                                                                    <Check className="h-4 w-4" />
                                                                </div>
                                                            ) : step.status === 'skipped' ? (
                                                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-white">
                                                                    <SkipForward className="h-4 w-4" />
                                                                </div>
                                                            ) : step.status === 'failed' ? (
                                                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-status-critical text-white">
                                                                    <XCircle className="h-4 w-4" />
                                                                </div>
                                                            ) : step.status === 'in_progress' ? (
                                                                <div className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-primary bg-primary/10 text-primary">
                                                                    <Play className="h-3.5 w-3.5" />
                                                                </div>
                                                            ) : (
                                                                <div className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-muted-foreground/30 text-muted-foreground text-xs">
                                                                    {i + 1}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium truncate">
                                                                {step.title}
                                                            </p>
                                                            {step.notes && (
                                                                <p className="text-xs text-muted-foreground truncate">
                                                                    {step.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-1.5">
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[10px] capitalize ${
                                                                    step.status === 'completed'
                                                                        ? 'border-status-success/30 text-status-success'
                                                                        : step.status === 'failed'
                                                                          ? 'border-status-critical/30 text-status-critical'
                                                                          : step.status ===
                                                                              'in_progress'
                                                                            ? 'border-primary text-primary'
                                                                            : ''
                                                                }`}
                                                            >
                                                                {step.status.replace('_', ' ')}
                                                            </Badge>
                                                            {can.manage &&
                                                                (step.status === 'pending' ||
                                                                    step.status ===
                                                                        'in_progress') && (
                                                                    <>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-7 w-7"
                                                                            title="Complete step"
                                                                            disabled={processing}
                                                                            onClick={() =>
                                                                                doAction(
                                                                                    'playbook-step',
                                                                                    {
                                                                                        step_id:
                                                                                            step.id,
                                                                                        action: 'complete',
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                                                        </Button>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-7 w-7"
                                                                            title="Skip step"
                                                                            disabled={processing}
                                                                            onClick={() =>
                                                                                doAction(
                                                                                    'playbook-step',
                                                                                    {
                                                                                        step_id:
                                                                                            step.id,
                                                                                        action: 'skip',
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <SkipForward className="h-4 w-4 text-muted-foreground" />
                                                                        </Button>
                                                                    </>
                                                                )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </TabsContent>
                            )}

                            {/* ====== Tab: Evidence ====== */}
                            <TabsContent value="evidence" className="space-y-4 pt-4">
                                {evidence_packs.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <Package className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No evidence packs attached.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    evidence_packs.map((pack) => (
                                        <Card key={pack.id}>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-sm flex items-center justify-between">
                                                    <span>{pack.title}</span>
                                                    <Badge
                                                        variant="outline"
                                                        className="capitalize text-xs"
                                                    >
                                                        {pack.status}
                                                    </Badge>
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {pack.items.length === 0 ? (
                                                    <p className="text-xs text-muted-foreground italic">
                                                        No items in this pack.
                                                    </p>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {pack.items.map((item) => (
                                                            <div
                                                                key={item.id}
                                                                className="flex items-center gap-3 rounded-lg bg-muted/50 px-3 py-2"
                                                            >
                                                                <FileText className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                                                                <div className="flex-1 min-w-0">
                                                                    <p className="text-sm font-medium truncate">
                                                                        {item.title}
                                                                    </p>
                                                                    <p className="text-[10px] text-muted-foreground capitalize">
                                                                        {item.type}
                                                                        {item.created_at &&
                                                                            ` \u2014 ${fmtShortDate(item.created_at)}`}
                                                                    </p>
                                                                </div>
                                                                {item.file_path && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7"
                                                                        asChild
                                                                    >
                                                                        <a
                                                                            href={item.file_path}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                        >
                                                                            <ExternalLink className="h-3.5 w-3.5" />
                                                                        </a>
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                                {can.manage && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-3"
                                                        onClick={() =>
                                                            doAction('evidence-upload', {
                                                                pack_id: pack.id,
                                                            })
                                                        }
                                                        disabled={processing}
                                                    >
                                                        <Upload className="mr-1.5 h-3.5 w-3.5" />
                                                        Upload Evidence
                                                    </Button>
                                                )}
                                            </CardContent>
                                        </Card>
                                    ))
                                )}
                            </TabsContent>

                            {/* ====== Tab: Communications ====== */}
                            <TabsContent value="comms" className="space-y-4 pt-4">
                                {communications.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <MessageSquare className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No communications logged.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <Card>
                                        <CardContent className="pt-6">
                                            <div className="relative space-y-0">
                                                {communications.map((comm, idx) => (
                                                    <div
                                                        key={comm.id}
                                                        className="relative flex gap-4 pb-6 last:pb-0"
                                                    >
                                                        {/* Timeline line */}
                                                        {idx < communications.length - 1 && (
                                                            <div className="absolute left-[15px] top-8 bottom-0 w-px bg-border" />
                                                        )}
                                                        {/* Icon */}
                                                        <div
                                                            className={`relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${
                                                                comm.direction === 'outbound'
                                                                    ? 'bg-primary/10 text-primary'
                                                                    : 'bg-muted text-muted-foreground'
                                                            }`}
                                                        >
                                                            {channelIcon(comm.channel)}
                                                        </div>
                                                        {/* Content */}
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <Badge
                                                                    variant="outline"
                                                                    className="capitalize text-[10px]"
                                                                >
                                                                    {comm.channel}
                                                                </Badge>
                                                                <Badge
                                                                    variant={
                                                                        comm.direction ===
                                                                        'outbound'
                                                                            ? 'default'
                                                                            : 'secondary'
                                                                    }
                                                                    className="text-[10px]"
                                                                >
                                                                    {comm.direction}
                                                                </Badge>
                                                                {comm.purpose && (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {comm.purpose}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {comm.target_user_name && (
                                                                <p className="text-xs text-muted-foreground mt-0.5">
                                                                    To: {comm.target_user_name}
                                                                </p>
                                                            )}
                                                            {comm.content && (
                                                                <p className="mt-1 text-sm">
                                                                    {comm.content}
                                                                </p>
                                                            )}
                                                            <p className="mt-1 text-[10px] text-muted-foreground">
                                                                {fmtDate(
                                                                    comm.sent_at ??
                                                                        comm.created_at,
                                                                )}
                                                            </p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}
                            </TabsContent>

                            {/* ====== Tab: Audit Trail ====== */}
                            <TabsContent value="audit" className="space-y-4 pt-4">
                                {audit_logs.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <Shield className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No audit entries recorded.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <Card>
                                        <CardContent className="pt-6">
                                            <div className="relative space-y-0">
                                                {audit_logs.map((log, idx) => (
                                                    <div
                                                        key={log.id}
                                                        className="relative flex gap-4 pb-5 last:pb-0"
                                                    >
                                                        {idx < audit_logs.length - 1 && (
                                                            <div className="absolute left-[15px] top-8 bottom-0 w-px bg-border" />
                                                        )}
                                                        <div className="relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                            <Clock className="h-4 w-4" />
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium capitalize">
                                                                {log.action.replace(/_/g, ' ')}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {log.user?.name ?? 'System'}{' '}
                                                                &middot;{' '}
                                                                {fmtDate(log.created_at)}
                                                            </p>
                                                            {log.meta &&
                                                                Object.keys(log.meta).length >
                                                                    0 && (
                                                                    <div className="mt-1 rounded bg-muted/50 px-2 py-1 text-[11px] font-mono text-muted-foreground">
                                                                        {Object.entries(
                                                                            log.meta,
                                                                        ).map(([k, v]) => (
                                                                            <div key={k}>
                                                                                <span className="font-semibold">
                                                                                    {k}:
                                                                                </span>{' '}
                                                                                {typeof v ===
                                                                                'object'
                                                                                    ? JSON.stringify(
                                                                                          v,
                                                                                      )
                                                                                    : String(v)}
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}
                            </TabsContent>
                        </Tabs>
                    </div>

                    {/* ---- Right: Sidebar ---- */}
                    <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        {/* SLA Status */}
                        {sla && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Clock className="h-4 w-4" />
                                        SLA Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <SlaGauge
                                        label="Acknowledge"
                                        deadline={sla.acknowledge_deadline}
                                        breached={sla.acknowledge_breached}
                                        met={ackMet}
                                    />
                                    <SlaGauge
                                        label="Response"
                                        deadline={sla.response_deadline}
                                        breached={sla.response_breached}
                                        met={false}
                                    />
                                    <SlaGauge
                                        label="Resolution"
                                        deadline={sla.resolution_deadline}
                                        breached={sla.resolution_breached}
                                        met={resMet}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {/* Quick Actions */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Quick Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {/* Acknowledge */}
                                <Button
                                    className="w-full justify-start gap-3 h-auto py-3 bg-status-warning-bg text-status-warning border border-status-warning/30 hover:bg-status-warning dark:text-status-warning"
                                    variant="outline"
                                    disabled={
                                        alert.status !== 'open' || !can.manage || processing
                                    }
                                    onClick={() => doAction('acknowledge')}
                                >
                                    <Eye className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="font-semibold text-sm">Acknowledge</div>
                                        <div className="text-[11px] font-normal opacity-70">
                                            Mark as seen
                                        </div>
                                    </div>
                                </Button>

                                {/* Start Triage */}
                                <Button
                                    className="w-full justify-start gap-3 h-auto py-3 bg-status-info-bg text-status-info border border-status-info/30 hover:bg-status-info dark:text-status-info"
                                    variant="outline"
                                    disabled={
                                        !['open', 'acknowledged'].includes(alert.status) ||
                                        !can.manage ||
                                        processing
                                    }
                                    onClick={() => doAction('triage')}
                                >
                                    <Search className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="font-semibold text-sm">Start Triage</div>
                                        <div className="text-[11px] font-normal opacity-70">
                                            Begin investigation
                                        </div>
                                    </div>
                                </Button>

                                {/* Resolve */}
                                <Button
                                    className="w-full justify-start gap-3 h-auto py-3 bg-status-success-bg text-status-success border border-status-success/30 hover:bg-status-success dark:text-status-success"
                                    variant="outline"
                                    disabled={
                                        alert.status === 'resolved' ||
                                        alert.status === 'closed' ||
                                        !can.manage ||
                                        processing
                                    }
                                    onClick={() => setResolveOpen(true)}
                                >
                                    <CheckCircle2 className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="font-semibold text-sm">Resolve</div>
                                        <div className="text-[11px] font-normal opacity-70">
                                            Mark resolved
                                        </div>
                                    </div>
                                </Button>

                                {/* Close */}
                                <Button
                                    className="w-full justify-start gap-3 h-auto py-3 bg-muted-foreground/80/10 text-foreground border border-border/30 hover:bg-muted-foreground/80/20 dark:text-muted-foreground"
                                    variant="outline"
                                    disabled={
                                        alert.status !== 'resolved' || !can.manage || processing
                                    }
                                    onClick={() => doAction('close')}
                                >
                                    <XCircle className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="font-semibold text-sm">Close</div>
                                        <div className="text-[11px] font-normal opacity-70">
                                            Close permanently
                                        </div>
                                    </div>
                                </Button>

                                {/* Escalate */}
                                <Button
                                    className="w-full justify-start gap-3 h-auto py-3 bg-status-critical-bg text-status-critical border border-status-critical/30 hover:bg-status-critical dark:text-status-critical"
                                    variant="outline"
                                    disabled={
                                        alert.status === 'closed' ||
                                        !can.escalate ||
                                        processing
                                    }
                                    onClick={() => setEscalateOpen(true)}
                                >
                                    <ArrowUpRight className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="font-semibold text-sm">Escalate</div>
                                        <div className="text-[11px] font-normal opacity-70">
                                            Raise to L{alert.escalation_level + 1}
                                        </div>
                                    </div>
                                </Button>
                            </CardContent>
                        </Card>

                        {/* Assignment */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Users className="h-4 w-4" />
                                    Assignment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {/* Current assignee */}
                                <div className="flex items-center gap-3">
                                    <div
                                        className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold ${
                                            alert.assigned_to
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground'
                                        }`}
                                    >
                                        {alert.assigned_to
                                            ? initial(alert.assigned_to.name)
                                            : '?'}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium truncate">
                                            {alert.assigned_to?.name ?? 'Unassigned'}
                                        </p>
                                        {alert.assigned_to?.email && (
                                            <p className="text-xs text-muted-foreground truncate">
                                                {alert.assigned_to.email}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {can.assign && (
                                    <>
                                        <Button
                                            variant="default"
                                            size="sm"
                                            className="w-full"
                                            disabled={processing}
                                            onClick={() => doAction('assign-me')}
                                        >
                                            <UserCheck className="mr-1.5 h-4 w-4" />
                                            Assign to Me
                                        </Button>

                                        <div className="flex gap-2">
                                            <Select
                                                value={assigneeId}
                                                onValueChange={(val) => handleAssign(val)}
                                            >
                                                <SelectTrigger className="flex-1 text-sm">
                                                    <SelectValue placeholder="Assign to..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {staff.map((s) => (
                                                        <SelectItem
                                                            key={s.id}
                                                            value={String(s.id)}
                                                        >
                                                            {s.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {alert.assigned_to && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="w-full text-muted-foreground"
                                                disabled={processing}
                                                onClick={() => doAction('unassign')}
                                            >
                                                <UserMinus className="mr-1.5 h-4 w-4" />
                                                Unassign
                                            </Button>
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {/* Client Quick View */}
                        {client && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <User className="h-4 w-4" />
                                        Client
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <Link
                                        href={`/clients/${client.id}`}
                                        className="text-sm font-medium text-primary hover:underline inline-flex items-center gap-1"
                                    >
                                        {client.name}
                                        <ExternalLink className="h-3 w-3" />
                                    </Link>
                                </CardContent>
                            </Card>
                        )}

                        {/* Location */}
                        {location && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <MapPin className="h-4 w-4" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {location.description && (
                                        <p className="text-sm">{location.description}</p>
                                    )}
                                    <p className="text-xs text-muted-foreground font-mono">
                                        {location.lat.toFixed(6)}, {location.lng.toFixed(6)}
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                        asChild
                                    >
                                        <a
                                            href={`https://www.google.com/maps?q=${location.lat},${location.lng}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <MapPin className="mr-1.5 h-3.5 w-3.5" />
                                            Open in Google Maps
                                        </a>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* ============================================================ */}
                {/* DIALOGS                                                      */}
                {/* ============================================================ */}

                {/* Resolve Dialog */}
                <Dialog open={resolveOpen} onOpenChange={setResolveOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Resolve Alert #{alert.id}</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="resolve-notes">Resolution Notes</Label>
                            <Textarea
                                id="resolve-notes"
                                value={resolveNotes}
                                onChange={(e) => setResolveNotes(e.target.value)}
                                placeholder="Describe how this alert was resolved..."
                                rows={4}
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setResolveOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                onClick={handleResolve}
                                disabled={!resolveNotes.trim() || processing}
                                className="bg-status-success hover:bg-status-success text-white"
                            >
                                <CheckCircle2 className="mr-1.5 h-4 w-4" />
                                Resolve
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Escalate Dialog */}
                <Dialog open={escalateOpen} onOpenChange={setEscalateOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Escalate Alert #{alert.id}</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <div className="flex items-center gap-3 rounded-lg bg-muted p-3">
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Current level:</span>{' '}
                                    <Badge variant="destructive" className="ml-1">
                                        L{alert.escalation_level}
                                    </Badge>
                                </div>
                                <span className="text-muted-foreground">&rarr;</span>
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Escalate to:</span>{' '}
                                    <Badge variant="destructive" className="ml-1">
                                        L{alert.escalation_level + 1}
                                    </Badge>
                                </div>
                            </div>
                            <Label htmlFor="escalate-reason">Escalation Reason</Label>
                            <Textarea
                                id="escalate-reason"
                                value={escalateReason}
                                onChange={(e) => setEscalateReason(e.target.value)}
                                placeholder="Why does this alert need escalation?"
                                rows={4}
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setEscalateOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                onClick={handleEscalate}
                                disabled={!escalateReason.trim() || processing}
                                variant="destructive"
                            >
                                <ArrowUpRight className="mr-1.5 h-4 w-4" />
                                Escalate to L{alert.escalation_level + 1}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
