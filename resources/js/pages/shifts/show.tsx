/**
 * @deprecated LEGACY PAGE — Not rendered by any controller.
 * The active shift detail is at: pages/operations/shifts/show.tsx
 * Rendered by: ShiftController::show → inertia('operations/shifts/show')
 * This file is kept as reference only. Do not develop against this file.
 */
import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import FleetHero from '@/components/fleet-hero';
import { ShiftStatusBadge } from '@/components/shift-status-badge';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useMemo, useState } from 'react';
import {
    CalendarDays,
    Clock,
    MapPin,
    User,
    CheckCircle2,
    ArrowRight,
    FileText,
    AlertTriangle,
    Handshake,
    ClipboardCheck,
} from 'lucide-react';

type Task = {
    id: number;
    label: string;
    is_completed: boolean;
};

type Note = {
    id: number;
    type: string;
    occurred_at?: string | null;
    subject?: string | null;
    body?: string | null;
    meta?: any;
    actor?: { id: number; name: string } | null;
};

type LinkedTimesheet = {
    id: number;
    status: string;
    work_date: string;
    starts_at: string;
    ends_at: string;
} | null;

type HandoverSummary = {
    id: number;
    status: string;
    incoming_staff_name?: string | null;
} | null;

type Props = {
    shift: {
        id: number;
        client_id: number;
        service_context_id?: number | null;
        user_id: number;
        starts_at: string;
        ends_at: string;
        actual_starts_at?: string | null;
        actual_ends_at?: string | null;
        status: string;
        location?: string | null;
        notes?: string | null;
        client: { id: number; first_name: string; last_name: string };
        staff: { id: number; name: string; email?: string };
        service_context?: { id: number; name: string; type: string; is_active: boolean } | null;
        tasks: Task[];
        is_sleepover?: boolean;
        is_on_call?: boolean;
        expected_break_minutes?: number | null;
    };
    handover: Note[];
    notes: Note[];
    incidents: any[];
    incidentTemplates: any[];
    linkedTimesheet?: LinkedTimesheet;
    handoverSummary?: HandoverSummary;
    can: {
        add_note: boolean;
        create_incident: boolean;
    };
};

const templates = [
    { key: 'shift_note', label: 'Shift note', body: '' },
    { key: 'progress_note', label: 'Progress note', body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:' },
    { key: 'handover', label: 'Handover', body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-' },
];

const noteTypeColor: Record<string, string> = {
    shift_note: 'border-l-slate-400',
    progress_note: 'border-l-indigo-400',
    handover: 'border-l-blue-400',
    incident: 'border-l-red-400',
    general: 'border-l-emerald-400',
};

function getWorkflowGuidance(status: string, hasTimesheet: boolean, hasHandover: boolean): { message: string; variant: 'info' | 'warning' | 'success' } | null {
    switch (status) {
        case 'draft':
            return { message: 'This shift is in draft. Assign staff and set to scheduled when ready.', variant: 'info' };
        case 'scheduled':
            return { message: 'Shift is scheduled. Staff can clock in or start the shift when it begins.', variant: 'info' };
        case 'in_progress':
            return { message: 'Shift is in progress. Complete the shift when finished — a timesheet will be created automatically.', variant: 'warning' };
        case 'completed':
            if (!hasTimesheet) {
                return { message: 'Shift completed but no timesheet linked yet. Create a timesheet to record hours.', variant: 'warning' };
            }
            return { message: 'Shift completed and timesheet linked.', variant: 'success' };
        case 'cancelled':
            return { message: 'This shift has been cancelled. Downstream records may have been affected.', variant: 'warning' };
        default:
            return null;
    }
}

export default function ShiftShow({ shift, handover, notes, incidents, incidentTemplates, linkedTimesheet, handoverSummary, can }: Props) {
    const { auth } = usePage().props as any;
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const canMarkTasks = auth?.can?.shifts?.update || auth?.can?.shifts?.tasksUpdateSelf || auth?.can?.shifts?.manageAny;
    const canActShift = auth?.can?.shifts?.update || auth?.can?.shifts?.manageAny;
    const canCreateTimesheet = auth?.can?.timesheets?.create || auth?.can?.timesheets?.manageAny;
    const canStartShift = canActShift && shift.status === 'scheduled';
    const canCompleteShift = canActShift && (shift.status === 'scheduled' || shift.status === 'in_progress');
    const [tasks, setTasks] = useState<Task[]>(shift.tasks ?? []);
    const [completeOpen, setCompleteOpen] = useState(() => {
        try {
            return new URLSearchParams(window.location.search).get('complete') === '1';
        } catch {
            return false;
        }
    });
    const [incidentOpen, setIncidentOpen] = useState(false);
    const incidentForm = useForm({
        template_id: '',
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        description: '',
        requires_followup: false,
        immediate_action_taken: '',
        witnesses: '',
    });

    const applyIncidentTemplate = (id: string) => {
        incidentForm.setData('template_id', id);
        const t = (incidentTemplates || []).find((x: any) => String(x.id) === String(id));
        if (!t) return;
        if (t.type) incidentForm.setData('type', t.type);
        if (t.severity) incidentForm.setData('severity', t.severity);
        if (t.default_description && !incidentForm.data.description) incidentForm.setData('description', t.default_description);
    };

    const clientName = `${shift.client.first_name} ${shift.client.last_name}`.trim();
    const incompleteCount = useMemo(() => tasks.filter((t) => !t.is_completed).length, [tasks]);
    const completedCount = useMemo(() => tasks.filter((t) => t.is_completed).length, [tasks]);
    const hasProgressOrShiftNotes = useMemo(
        () => (notes ?? []).some((n) => n.type === 'progress_note' || n.type === 'shift_note'),
        [notes],
    );

    const completeForm = useForm<{
        final_note_subject: string;
        final_note_body: string;
        allow_incomplete_tasks: boolean;
        incomplete_tasks_reason: string;
    }>({
        final_note_subject: 'Shift summary',
        final_note_body: '',
        allow_incomplete_tasks: false,
        incomplete_tasks_reason: '',
    });

    const noteForm = useForm<{ type: string; subject: string; goal: string; body: string; visibility: string; pin: boolean; shift_id: number }>(
        {
            type: 'shift_note',
            subject: '',
            goal: '',
            body: '',
            visibility: 'internal',
            pin: false,
            shift_id: shift.id,
        },
    );

    const activeTemplate = useMemo(() => templates.find((t) => t.key === noteForm.data.type), [noteForm.data.type]);

    const guidance = getWorkflowGuidance(shift.status, !!linkedTimesheet, !!handoverSummary);

    function getXsrfTokenFromCookie(): string | null {
        const pair = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='));
        if (!pair) return null;
        const value = pair.split('=')[1];
        if (!value) return null;
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    }

    async function toggleTask(task: Task, next: boolean) {
        setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, is_completed: next } : t)));
        try {
            const res = await fetch(`/shifts/${shift.id}/tasks/${task.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(getXsrfTokenFromCookie()
                        ? { 'X-XSRF-TOKEN': getXsrfTokenFromCookie() as string }
                        : {
                            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content,
                        }),
                },
                body: JSON.stringify({ is_completed: next }),
            });
            if (!res.ok) throw new Error('Request failed');
        } catch {
            setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, is_completed: !next } : t)));
        }
    }

    const shiftHours = (() => {
        const s = new Date(shift.starts_at).getTime();
        const e = new Date(shift.ends_at).getTime();
        if (Number.isNaN(s) || Number.isNaN(e) || e <= s) return '—';
        return `${((e - s) / (1000 * 60 * 60)).toFixed(1)}h`;
    })();

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['shift.plural'] ?? 'Shifts', href: '/shifts' },
                { title: `${clientName} — ${new Date(shift.starts_at).toLocaleDateString()}`, href: `/shifts/${shift.id}` },
            ]}
        >
            <Head title={`${clientName} — Shift`} />

            <PageShell>
                {/* Hero header */}
                <FleetHero
                    title={clientName}
                    description={`${new Date(shift.starts_at).toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}`}
                    icon={<CalendarDays className="h-7 w-7 text-white" />}
                    backHref="/shifts"
                    backLabel="All shifts"
                    stats={[
                        { label: 'Duration', value: shiftHours },
                        { label: 'Tasks', value: `${completedCount}/${tasks.length}` },
                        { label: 'Notes', value: notes.length },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            <ShiftStatusBadge status={shift.status} showIcon className="border-white/30 bg-white/10 text-white" />
                        </div>
                    }
                >
                    {/* Shift detail row inside hero */}
                    <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/70">
                        <span className="inline-flex items-center gap-1">
                            <Clock className="h-3.5 w-3.5" />
                            {new Date(shift.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            {' – '}
                            {new Date(shift.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </span>
                        {shift.location ? (
                            <span className="inline-flex items-center gap-1">
                                <MapPin className="h-3.5 w-3.5" />
                                {shift.location}
                            </span>
                        ) : null}
                        <span className="inline-flex items-center gap-1">
                            <User className="h-3.5 w-3.5" />
                            {shift.staff?.name ?? 'Unassigned'}
                        </span>
                        {shift.service_context ? (
                            <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">
                                {shift.service_context.name}
                            </Badge>
                        ) : null}
                        {shift.is_sleepover ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">Sleepover</Badge> : null}
                        {shift.is_on_call ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">On-call</Badge> : null}
                        {shift.actual_starts_at ? (
                            <span className="inline-flex items-center gap-1 text-white/50">
                                Actual: {new Date(shift.actual_starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                {shift.actual_ends_at ? `–${new Date(shift.actual_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}
                            </span>
                        ) : null}
                    </div>
                </FleetHero>

                {/* Workflow guidance banner */}
                {guidance ? (
                    <div className={`flex items-center gap-3 rounded-xl border p-4 ${
                        guidance.variant === 'warning' ? 'border-status-warning/30 bg-status-warning' :
                        guidance.variant === 'success' ? 'border-status-success/30 bg-status-success' :
                        'border-status-info/30 bg-status-info'
                    }`}>
                        {guidance.variant === 'warning' ? <AlertTriangle className="h-4 w-4 text-status-warning shrink-0" /> :
                         guidance.variant === 'success' ? <CheckCircle2 className="h-4 w-4 text-status-success shrink-0" /> :
                         <ArrowRight className="h-4 w-4 text-status-info shrink-0" />}
                        <span className={`text-sm ${
                            guidance.variant === 'warning' ? 'text-status-warning dark:text-status-warning' :
                            guidance.variant === 'success' ? 'text-status-success dark:text-status-success' :
                            'text-status-info dark:text-status-info'
                        }`}>
                            {guidance.message}
                        </span>
                    </div>
                ) : null}

                {/* Action bar */}
                <div className="flex flex-wrap items-center gap-2">
                    {canStartShift ? (
                        <Button onClick={() => router.patch(`/shifts/${shift.id}/start`, {}, { preserveScroll: true })}>
                            Start shift
                        </Button>
                    ) : null}
                    {canCompleteShift ? (
                        <Button variant={canStartShift ? 'outline' : 'default'} onClick={() => setCompleteOpen(true)}>
                            Complete shift
                        </Button>
                    ) : null}
                    {can.create_incident ? (
                        <Button variant="outline" onClick={() => setIncidentOpen(true)}>
                            Report incident
                        </Button>
                    ) : null}
                    {canCreateTimesheet && !linkedTimesheet ? (
                        <Button variant="outline" asChild>
                            <Link href={`/timesheets/create?shift_id=${shift.id}`}>
                                <FileText className="mr-1.5 h-3.5 w-3.5" />
                                Create timesheet
                            </Link>
                        </Button>
                    ) : null}
                    {auth?.can?.shifts?.update ? (
                        <Button variant="ghost" asChild>
                            <Link href={`/shifts/${shift.id}/edit`}>Edit</Link>
                        </Button>
                    ) : null}
                </div>

                {/* Integration cards row */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {/* Linked timesheet */}
                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                <FileText className="h-3.5 w-3.5" />
                                Linked Timesheet
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {linkedTimesheet ? (
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/timesheets/${linkedTimesheet.id}/edit`} className="font-medium underline">
                                            Timesheet #{linkedTimesheet.id}
                                        </Link>
                                        <TimesheetStatusBadge status={linkedTimesheet.status} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(linkedTimesheet.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        {' – '}
                                        {new Date(linkedTimesheet.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No timesheet linked yet.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Handover status */}
                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                <Handshake className="h-3.5 w-3.5" />
                                Handover
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {handoverSummary ? (
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline" className={
                                            handoverSummary.status === 'acknowledged' ? 'border-status-success/30 text-status-success bg-status-success' :
                                            handoverSummary.status === 'submitted' ? 'border-status-warning/30 text-status-warning bg-status-warning' :
                                            'border-border/30 text-muted-foreground bg-muted-foreground/80/10'
                                        }>
                                            {handoverSummary.status.charAt(0).toUpperCase() + handoverSummary.status.slice(1)}
                                        </Badge>
                                    </div>
                                    {handoverSummary.incoming_staff_name ? (
                                        <p className="text-xs text-muted-foreground">
                                            Incoming: {handoverSummary.incoming_staff_name}
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No handover required.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Task progress */}
                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                <ClipboardCheck className="h-3.5 w-3.5" />
                                Task Progress
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {tasks.length > 0 ? (
                                <div className="space-y-2">
                                    <div className="flex items-baseline gap-2">
                                        <span className="text-2xl font-bold tabular-nums">{completedCount}</span>
                                        <span className="text-sm text-muted-foreground">/ {tasks.length} completed</span>
                                    </div>
                                    <div className="h-1.5 w-full rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary transition-all duration-300"
                                            style={{ width: `${tasks.length > 0 ? (completedCount / tasks.length) * 100 : 0}%` }}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No tasks assigned.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Complete shift dialog */}
                <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Complete shift</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div className="rounded-lg border p-3">
                                <div className="text-sm font-medium">Checklist</div>
                                <div className="mt-2 text-sm text-muted-foreground">
                                    {incompleteCount === 0 ? 'All shift tasks are completed.' : `${incompleteCount} task${incompleteCount === 1 ? '' : 's'} still incomplete.`}
                                </div>
                                {incompleteCount > 0 ? (
                                    <div className="mt-3 space-y-3">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={completeForm.data.allow_incomplete_tasks}
                                                onCheckedChange={(v) => completeForm.setData('allow_incomplete_tasks', Boolean(v))}
                                            />
                                            <div className="text-sm">Allow completion with incomplete tasks</div>
                                        </div>
                                        {completeForm.data.allow_incomplete_tasks ? (
                                            <div>
                                                <Label>Reason (required)</Label>
                                                <Textarea
                                                    className="mt-1"
                                                    value={completeForm.data.incomplete_tasks_reason}
                                                    onChange={(e) => completeForm.setData('incomplete_tasks_reason', e.target.value)}
                                                    placeholder="Why are tasks incomplete?"
                                                />
                                                {completeForm.errors.incomplete_tasks_reason ? (
                                                    <div className="mt-1 text-xs text-status-critical">{completeForm.errors.incomplete_tasks_reason}</div>
                                                ) : null}
                                            </div>
                                        ) : null}
                                        {completeForm.errors.allow_incomplete_tasks ? (
                                            <div className="text-xs text-status-critical">{completeForm.errors.allow_incomplete_tasks}</div>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>

                            <div className="rounded-lg border p-3">
                                <div className="text-sm font-medium">Shift summary note</div>
                                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Subject</Label>
                                        <Input
                                            className="mt-1"
                                            value={completeForm.data.final_note_subject}
                                            onChange={(e) => completeForm.setData('final_note_subject', e.target.value)}
                                        />
                                        {completeForm.errors.final_note_subject ? (
                                            <div className="mt-1 text-xs text-status-critical">{completeForm.errors.final_note_subject}</div>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Label>Note {hasProgressOrShiftNotes ? '(optional if notes already added)' : '(required)'}</Label>
                                    {hasProgressOrShiftNotes ? (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            You already have notes recorded. You can leave this blank to auto-generate a completion summary.
                                        </div>
                                    ) : (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Provide a short summary to complete the shift, or add a progress note first.
                                        </div>
                                    )}
                                    <Textarea
                                        className="mt-1"
                                        value={completeForm.data.final_note_body}
                                        onChange={(e) => completeForm.setData('final_note_body', e.target.value)}
                                        placeholder="Summarise what happened during the shift, outcomes, any concerns, and handover items."
                                    />
                                    {completeForm.errors.final_note_body ? (
                                        <div className="mt-1 text-xs text-status-critical">{completeForm.errors.final_note_body}</div>
                                    ) : null}
                                </div>
                            </div>

                            <div className="rounded-lg border border-dashed p-3">
                                <div className="text-xs text-muted-foreground">
                                    A draft timesheet will be created automatically when this shift is completed.
                                </div>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCompleteOpen(false)}>Cancel</Button>
                            <Button
                                type="button"
                                disabled={completeForm.processing}
                                onClick={() =>
                                    completeForm.patch(`/shifts/${shift.id}/complete`, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setCompleteOpen(false);
                                            try {
                                                const url = new URL(window.location.href);
                                                url.searchParams.delete('complete');
                                                window.history.replaceState({}, '', url.toString());
                                            } catch {}
                                        },
                                    })
                                }
                            >
                                Complete shift
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Report incident dialog */}
                <Dialog open={incidentOpen} onOpenChange={setIncidentOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Report incident</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Template (optional)</Label>
                                    <Select
                                        value={incidentForm.data.template_id || '__none__'}
                                        onValueChange={(v) => applyIncidentTemplate(v === '__none__' ? '' : v)}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Pick a template" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">None</SelectItem>
                                            {(incidentTemplates || []).map((t: any) => (
                                                <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label>Type</Label>
                                    <Input value={incidentForm.data.type} onChange={(e) => incidentForm.setData('type', e.target.value)} />
                                </div>
                                <div className="space-y-1">
                                    <Label>Severity</Label>
                                    <Select value={incidentForm.data.severity} onValueChange={(v) => incidentForm.setData('severity', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {['low', 'medium', 'high'].map((s) => (
                                                <SelectItem key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Occurred at</Label>
                                    <Input type="datetime-local" value={incidentForm.data.occurred_at} onChange={(e) => incidentForm.setData('occurred_at', e.target.value)} />
                                </div>
                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox checked={!!incidentForm.data.requires_followup} onCheckedChange={(v) => incidentForm.setData('requires_followup', !!v)} />
                                    <Label>Requires follow-up</Label>
                                </div>
                            </div>
                            <div className="space-y-1">
                                <Label>Description</Label>
                                <Textarea value={incidentForm.data.description} onChange={(e) => incidentForm.setData('description', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Immediate action taken</Label>
                                <Textarea value={incidentForm.data.immediate_action_taken} onChange={(e) => incidentForm.setData('immediate_action_taken', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Witnesses</Label>
                                <Textarea value={incidentForm.data.witnesses} onChange={(e) => incidentForm.setData('witnesses', e.target.value)} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                disabled={incidentForm.processing}
                                onClick={() =>
                                    incidentForm.post(`/shifts/${shift.id}/incidents`, {
                                        onSuccess: () => {
                                            incidentForm.reset();
                                            setIncidentOpen(false);
                                        },
                                    })
                                }
                            >
                                Submit incident
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Pinned handover */}
                {handover.length ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Handshake className="h-4 w-4 text-muted-foreground" />
                                Pinned Handover
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {handover.map((h) => (
                                <div key={h.id} className="rounded-lg border border-l-4 border-l-blue-400 p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="text-sm font-medium">{h.subject || 'Handover'}</div>
                                        <div className="text-xs text-muted-foreground">{h.occurred_at ? new Date(h.occurred_at).toLocaleString() : ''}</div>
                                    </div>
                                    {h.body ? <div className="mt-2 whitespace-pre-wrap text-sm">{h.body}</div> : null}
                                    <div className="mt-2 text-xs text-muted-foreground">{h.actor?.name ? `By ${h.actor.name}` : ''}</div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ) : null}

                {/* Tasks */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ClipboardCheck className="h-4 w-4 text-muted-foreground" />
                            Tasks
                            {tasks.length > 0 ? (
                                <span className="text-xs font-normal text-muted-foreground">
                                    ({completedCount}/{tasks.length})
                                </span>
                            ) : null}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {tasks.map((t) => (
                            <div key={t.id} className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/20">
                                <Checkbox
                                    checked={t.is_completed}
                                    disabled={!canMarkTasks}
                                    onCheckedChange={(v) => toggleTask(t, Boolean(v))}
                                />
                                <div className={`text-sm ${t.is_completed ? 'line-through text-muted-foreground' : ''}`}>{t.label}</div>
                            </div>
                        ))}
                        {!tasks.length ? <div className="text-sm text-muted-foreground">No tasks added for this shift.</div> : null}
                    </CardContent>
                </Card>

                {/* Shift notes */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Shift Notes</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can.add_note ? (
                            <div className="rounded-lg border bg-muted/20 p-4">
                                <div className="text-sm font-medium">Add note</div>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Type</Label>
                                        <Select
                                            value={noteForm.data.type}
                                            onValueChange={(v) => {
                                                noteForm.setData('type', v);
                                                const tpl = templates.find((t) => t.key === v);
                                                if (tpl && noteForm.data.body.trim() === '') {
                                                    noteForm.setData('body', tpl.body);
                                                }
                                                noteForm.setData('pin', v === 'handover');
                                            }}
                                        >
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {templates.map((t) => (
                                                    <SelectItem key={t.key} value={t.key}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Subject (optional)</Label>
                                        <Input value={noteForm.data.subject} onChange={(e) => noteForm.setData('subject', e.target.value)} />
                                    </div>
                                </div>

                                {noteForm.data.type === 'progress_note' ? (
                                    <div className="mt-3">
                                        <Label>Goal/outcome (optional)</Label>
                                        <Input value={noteForm.data.goal} onChange={(e) => noteForm.setData('goal', e.target.value)} />
                                    </div>
                                ) : null}

                                <div className="mt-3">
                                    <Label>Note</Label>
                                    <Textarea rows={5} value={noteForm.data.body} onChange={(e) => noteForm.setData('body', e.target.value)} />
                                </div>

                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    <div className="flex items-center gap-2 text-xs">
                                        <Checkbox checked={noteForm.data.visibility === 'portal'} onCheckedChange={(v) => noteForm.setData('visibility', v ? 'portal' : 'internal')} />
                                        <span>Share in portal</span>
                                    </div>
                                    {noteForm.data.type === 'handover' ? (
                                        <div className="flex items-center gap-2 text-xs">
                                            <Checkbox checked={noteForm.data.pin} onCheckedChange={(v) => noteForm.setData('pin', Boolean(v))} />
                                            <span>Pin as handover</span>
                                        </div>
                                    ) : null}
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            noteForm.post(`/clients/${shift.client.id}/notes`, {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    noteForm.reset();
                                                    noteForm.setData({
                                                        type: 'shift_note',
                                                        subject: '',
                                                        goal: '',
                                                        body: '',
                                                        visibility: 'internal',
                                                        pin: false,
                                                        shift_id: shift.id,
                                                    });
                                                },
                                            })
                                        }
                                        disabled={noteForm.processing || !noteForm.data.body}
                                    >
                                        Add note
                                    </Button>
                                </div>
                                {activeTemplate?.body && noteForm.data.body.trim() === '' ? (
                                    <div className="mt-2 text-xs text-muted-foreground">Tip: selecting a type will insert a quick template.</div>
                                ) : null}
                            </div>
                        ) : null}

                        {notes.map((n) => (
                            <div key={n.id} className={`rounded-lg border border-l-4 p-3 ${noteTypeColor[n.type] ?? 'border-l-slate-300'}`}>
                                <div className="flex items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">{n.subject || n.type.replace('_', ' ')}</span>
                                        <Badge variant="outline" className="text-[10px]">{n.type.replace('_', ' ')}</Badge>
                                    </div>
                                    <div className="text-xs text-muted-foreground">{n.occurred_at ? new Date(n.occurred_at).toLocaleString() : ''}</div>
                                </div>
                                {n.meta?.goal ? <div className="mt-1 text-xs text-muted-foreground">Goal: {n.meta.goal}</div> : null}
                                {n.body ? <div className="mt-2 whitespace-pre-wrap text-sm">{n.body}</div> : null}
                                <div className="mt-2 text-xs text-muted-foreground">{n.actor?.name ? `By ${n.actor.name}` : ''}</div>
                            </div>
                        ))}
                        {!notes.length && !can.add_note ? <div className="text-sm text-muted-foreground">No notes for this shift yet.</div> : null}
                    </CardContent>
                </Card>

                {/* Incidents */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                            Incidents
                            {(incidents || []).length > 0 ? (
                                <Badge variant="outline" className="border-status-critical/30 text-status-critical bg-status-critical text-[10px]">
                                    {incidents.length}
                                </Badge>
                            ) : null}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {(incidents || []).map((i: any) => (
                            <div key={i.id} className="flex items-center justify-between rounded-lg border border-l-4 border-l-red-400 p-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">{i.type}</span>
                                        <Badge variant="outline" className={
                                            i.severity === 'high' ? 'border-status-critical/30 text-status-critical bg-status-critical' :
                                            i.severity === 'medium' ? 'border-status-warning/30 text-status-warning bg-status-warning' :
                                            'border-border/30 text-muted-foreground bg-muted-foreground/80/10'
                                        }>
                                            {i.severity}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">{i.status} · {i.occurred_at ? new Date(i.occurred_at).toLocaleString() : ''}</div>
                                </div>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={`/incidents/${i.id}`}>View</Link>
                                </Button>
                            </div>
                        ))}
                        {!(incidents || []).length && <div className="text-sm text-muted-foreground">No incidents reported for this shift.</div>}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
