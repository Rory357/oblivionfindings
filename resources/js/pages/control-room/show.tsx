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
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertTriangle,
    ArrowUpRight,
    BookOpen,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Edit2,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    ListTodo,
    MapPin,
    MessageSquare,
    Package,
    Paperclip,
    Pause,
    Pencil,
    Play,
    Plus,
    Reply,
    Search,
    Send,
    Shield,
    ShieldAlert,
    SkipForward,
    Square,
    StopCircle,
    Timer,
    Trash2,
    Upload,
    User,
    UserCheck,
    UserMinus,
    UserPlus,
    Users,
    XCircle,
} from 'lucide-react';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';

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
    playbook: { id: number; name: string; category: string };
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

interface SlaData {
    acknowledge_deadline: string | null;
    response_deadline: string | null;
    resolution_deadline: string | null;
    acknowledge_breached: boolean;
    response_breached: boolean;
    resolution_breached: boolean;
}

interface Attachment {
    name: string;
    path: string;
    size: number;
    mime_type: string;
}

interface DiscussionReply {
    id: number;
    type: string;
    content: string;
    is_internal: boolean;
    attachments: Attachment[];
    user: { id: number; name: string };
    edited_at: string | null;
    created_at: string;
}

interface Discussion {
    id: number;
    type: string;
    content: string;
    is_internal: boolean;
    attachments: Attachment[];
    mentions: number[];
    user: { id: number; name: string };
    edited_at: string | null;
    created_at: string;
    replies: DiscussionReply[];
}

interface Subtask {
    id: number;
    title: string;
    status: string;
    assigned_to: { id: number; name: string } | null;
}

interface Task {
    id: number;
    title: string;
    description: string | null;
    status: string;
    priority: string;
    due_at: string | null;
    completed_at: string | null;
    estimated_minutes: number | null;
    actual_minutes: number | null;
    sort_order: number;
    assigned_to: { id: number; name: string } | null;
    created_by_name: string | null;
    subtasks: Subtask[];
    created_at: string;
}

interface Watcher {
    id: number;
    user_id: number;
    user_name: string;
}

interface TimeEntry {
    id: number;
    user_name: string;
    user_id: number;
    started_at: string | null;
    ended_at: string | null;
    duration_minutes: number;
    description: string | null;
    task_id: number | null;
    is_running: boolean;
    created_at: string;
}

interface Alert {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    priority: string | null;
    due_at: string | null;
    category: string | null;
    resolution_code: string | null;
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
    time_spent_minutes: number;
    created_at?: string | null;
    updated_at?: string | null;
}

interface Props {
    alert: Alert;
    tasks: Task[];
    discussions: Discussion[];
    watchers: Watcher[];
    time_entries: TimeEntry[];
    time_spent_minutes: number;
    is_watching: boolean;
    audit_logs: AuditLogEntry[];
    playbook_run: PlaybookRun | null;
    evidence_packs: EvidencePack[];
    sla: SlaData | null;
    client: { id: number; name: string } | null;
    location: { lat: number; lng: number; description: string | null } | null;
    can: { manage: boolean; assign: boolean; escalate: boolean };
    staff: { id: number; name: string; email: string }[];
    config_options: {
        categories: { value: string; label: string; color: string | null }[];
        resolution_codes: { value: string; label: string; color: string | null }[];
    };
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
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-blue-500 text-white',
};

const STATUS_BADGE: Record<string, string> = {
    open: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    acknowledged: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
    triaging: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    resolved: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    closed: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
};

const PRIORITY_COLORS: Record<string, string> = {
    critical: 'bg-red-600 text-white hover:bg-red-700',
    high: 'bg-orange-500 text-white hover:bg-orange-600',
    medium: 'bg-yellow-500 text-black hover:bg-yellow-600',
    low: 'bg-blue-500 text-white hover:bg-blue-600',
};

const PRIORITY_CYCLE = ['low', 'medium', 'high', 'critical'] as const;

const DISCUSSION_TYPE_BADGE: Record<string, string> = {
    comment: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    internal_note: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    status_update: 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-300',
    escalation: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    resolution: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
};

const WORKFLOW_STEPS = ['open', 'acknowledged', 'triaging', 'resolved', 'closed'] as const;

function fmtDate(d: string | null): string {
    if (!d) return '\u2014';
    return new Date(d).toLocaleString('en-NZ', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function fmtShortDate(d: string | null): string {
    if (!d) return '';
    return new Date(d).toLocaleString('en-NZ', {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
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

function fmtDuration(mins: number): string {
    if (mins <= 0) return '0m';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    if (h > 0 && m > 0) return `${h}h ${m}m`;
    if (h > 0) return `${h}h`;
    return `${m}m`;
}

function fmtFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes}B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)}KB`;
    return `${(bytes / 1048576).toFixed(1)}MB`;
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

function useElapsedTimer(entry: TimeEntry | undefined): string {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!entry?.is_running) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [entry?.is_running]);

    if (!entry?.is_running || !entry.started_at) return '00:00:00';
    const elapsed = Math.max(0, now - new Date(entry.started_at).getTime());
    const h = Math.floor(elapsed / 3600000);
    const m = Math.floor((elapsed % 3600000) / 60000);
    const s = Math.floor((elapsed % 60000) / 1000);
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
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

function initial(name: string): string {
    return name.split(' ').map((w) => w[0]).join('').toUpperCase().slice(0, 2);
}

function isOverdue(dateStr: string | null): boolean {
    if (!dateStr) return false;
    return new Date(dateStr).getTime() < Date.now();
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function StatusStepper({ alert }: { alert: Alert }) {
    const currentIdx = stepIndex(alert.status);
    return (
        <div className="mt-5 flex items-start justify-between gap-0">
            {WORKFLOW_STEPS.map((step, i) => {
                const completed = i < currentIdx;
                const current = i === currentIdx;
                const ts = getStepTimestamp(alert, step);
                return (
                    <div key={step} className="flex flex-1 items-start">
                        <div className="flex min-w-[72px] flex-col items-center">
                            <div
                                className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-semibold transition-colors ${
                                    completed
                                        ? 'border-green-500 bg-green-500 text-white'
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
                                        ? 'text-green-600 dark:text-green-400'
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
                            <div className="flex-1 px-1 pt-4">
                                <div
                                    className={`h-0.5 w-full rounded ${
                                        i < currentIdx ? 'bg-green-500' : 'bg-muted-foreground/20'
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

function SlaGauge({ label, deadline, breached, met }: { label: string; deadline: string | null; breached: boolean; met: boolean }) {
    const countdown = useCountdown(deadline, breached);
    const now = Date.now();
    let pct = 100;
    let colorCls = 'bg-green-500';
    let textCls = 'text-green-600 dark:text-green-400';
    let statusLabel = 'On Track';

    if (breached || (deadline && new Date(deadline).getTime() <= now)) {
        pct = 100; colorCls = 'bg-red-500'; textCls = 'text-red-600 dark:text-red-400'; statusLabel = 'Breached';
    } else if (met) {
        pct = 100; colorCls = 'bg-green-500'; textCls = 'text-green-600 dark:text-green-400'; statusLabel = 'Met';
    } else if (deadline) {
        const remaining = Math.max(0, new Date(deadline).getTime() - now);
        pct = Math.min(100, Math.max(5, 100 - (remaining / (remaining + 3600000)) * 100));
        if (remaining < 900000) { colorCls = 'bg-red-500'; textCls = 'text-red-600 dark:text-red-400'; statusLabel = 'At Risk'; }
        else if (remaining < 3600000) { colorCls = 'bg-yellow-500'; textCls = 'text-yellow-600 dark:text-yellow-400'; statusLabel = 'At Risk'; }
    }

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between text-xs">
                <span className="font-medium">{label}</span>
                <span className={`font-semibold ${textCls}`}>{statusLabel}</span>
            </div>
            <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
                <div className={`h-full rounded-full transition-all duration-500 ${colorCls}`} style={{ width: `${pct}%` }} />
            </div>
            <div className="flex items-center justify-between text-[10px] text-muted-foreground">
                <span>{deadline ? fmtShortDate(deadline) : 'No SLA'}</span>
                <span className={`font-mono font-semibold ${textCls}`}>{countdown}</span>
            </div>
        </div>
    );
}

function AvatarCircle({ name, size = 'sm' }: { name: string; size?: 'sm' | 'md' }) {
    const cls = size === 'md' ? 'h-10 w-10 text-sm' : 'h-7 w-7 text-[10px]';
    return (
        <div className={`flex ${cls} flex-shrink-0 items-center justify-center rounded-full bg-primary/10 font-bold text-primary`}>
            {initial(name)}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function ControlRoomAlertShow({
    alert,
    tasks,
    discussions,
    watchers,
    time_entries,
    time_spent_minutes,
    is_watching,
    audit_logs,
    can,
    staff,
    playbook_run,
    evidence_packs,
    sla,
    client,
    location,
    config_options,
}: Props) {
    // ----- State -----
    const [processing, setProcessing] = useState(false);
    const [resolveOpen, setResolveOpen] = useState(false);
    const [resolveNotes, setResolveNotes] = useState('');
    const [escalateOpen, setEscalateOpen] = useState(false);
    const [escalateReason, setEscalateReason] = useState('');
    const [assigneeId, setAssigneeId] = useState<string>('');

    // Discussion state
    const [discussionContent, setDiscussionContent] = useState('');
    const [discussionType, setDiscussionType] = useState<string>('comment');
    const [replyingTo, setReplyingTo] = useState<number | null>(null);
    const [replyContent, setReplyContent] = useState('');
    const [editingDiscussion, setEditingDiscussion] = useState<number | null>(null);
    const [editContent, setEditContent] = useState('');

    // Task state
    const [addTaskOpen, setAddTaskOpen] = useState(false);
    const [taskTitle, setTaskTitle] = useState('');
    const [taskDesc, setTaskDesc] = useState('');
    const [taskAssignee, setTaskAssignee] = useState<string>('');
    const [taskPriority, setTaskPriority] = useState<string>('medium');
    const [taskDueAt, setTaskDueAt] = useState('');
    const [taskEstimated, setTaskEstimated] = useState('');
    const [taskFilter, setTaskFilter] = useState<'all' | 'mine' | 'overdue' | 'completed'>('all');

    // Time tracking state
    const [manualTimeOpen, setManualTimeOpen] = useState(false);
    const [manualHours, setManualHours] = useState('');
    const [manualMinutes, setManualMinutes] = useState('');
    const [manualDesc, setManualDesc] = useState('');
    const [manualDate, setManualDate] = useState('');
    const [stopDesc, setStopDesc] = useState('');
    const [stoppingTimer, setStoppingTimer] = useState(false);

    // Evidence state
    const [evidencePackOpen, setEvidencePackOpen] = useState(false);
    const [evidencePackTitle, setEvidencePackTitle] = useState('');
    const [evidenceNoteOpen, setEvidenceNoteOpen] = useState(false);
    const [evidenceNoteContent, setEvidenceNoteContent] = useState('');
    const [evidenceNotePackId, setEvidenceNotePackId] = useState<number | null>(null);

    // Sidebar edits
    const [editingDueDate, setEditingDueDate] = useState(false);
    const [dueDateVal, setDueDateVal] = useState(alert.due_at?.slice(0, 16) ?? '');
    const [editingCategory, setEditingCategory] = useState(false);
    const [categoryVal, setCategoryVal] = useState(alert.category ?? '');
    const [addWatcherOpen, setAddWatcherOpen] = useState(false);

    // Timer
    const runningEntry = useMemo(() => time_entries.find((e) => e.is_running), [time_entries]);
    const elapsed = useElapsedTimer(runningEntry);

    // ----- Helpers -----
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

    function handleAssign(staffId: string) {
        doAction('assign', { staff_id: staffId });
        setAssigneeId('');
    }

    function postDiscussion(parentId?: number) {
        const content = parentId ? replyContent : discussionContent;
        if (!content.trim()) return;
        setProcessing(true);
        router.post(
            `/control-room/alerts/${alert.id}/discussions`,
            {
                content: content.trim(),
                type: parentId ? 'comment' : discussionType,
                is_internal: !parentId && discussionType === 'internal_note',
                parent_id: parentId ?? null,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    if (parentId) { setReplyingTo(null); setReplyContent(''); }
                    else { setDiscussionContent(''); }
                },
            },
        );
    }

    function saveDiscussionEdit(id: number) {
        if (!editContent.trim()) return;
        setProcessing(true);
        router.put(`/control-room/discussions/${id}`, { content: editContent.trim() }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
            onSuccess: () => { setEditingDiscussion(null); setEditContent(''); },
        });
    }

    function handleCreateTask() {
        if (!taskTitle.trim()) return;
        setProcessing(true);
        router.post(
            `/control-room/alerts/${alert.id}/tasks`,
            {
                title: taskTitle.trim(),
                description: taskDesc.trim() || null,
                assigned_to_user_id: taskAssignee ? Number(taskAssignee) : null,
                priority: taskPriority,
                due_at: taskDueAt || null,
                estimated_minutes: taskEstimated ? Number(taskEstimated) : null,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setAddTaskOpen(false);
                    setTaskTitle(''); setTaskDesc(''); setTaskAssignee('');
                    setTaskPriority('medium'); setTaskDueAt(''); setTaskEstimated('');
                },
            },
        );
    }

    function toggleTaskStatus(task: Task) {
        const newStatus = task.status === 'completed' ? 'open' : 'completed';
        router.post(`/control-room/tasks/${task.id}/status`, { status: newStatus }, { preserveScroll: true });
    }

    function startTimer() {
        setProcessing(true);
        router.post(`/control-room/alerts/${alert.id}/time-entries/start`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    }

    function stopTimer(entryId: number) {
        setProcessing(true);
        router.post(`/control-room/time-entries/${entryId}/stop`, { description: stopDesc.trim() || null }, {
            preserveScroll: true,
            onFinish: () => { setProcessing(false); setStoppingTimer(false); setStopDesc(''); },
        });
    }

    function handleManualEntry() {
        const h = parseInt(manualHours) || 0;
        const m = parseInt(manualMinutes) || 0;
        const totalMins = h * 60 + m;
        if (totalMins <= 0) return;
        setProcessing(true);
        router.post(
            `/control-room/alerts/${alert.id}/time-entries`,
            { duration_minutes: totalMins, description: manualDesc.trim() || null, date: manualDate || null },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setManualTimeOpen(false);
                    setManualHours(''); setManualMinutes(''); setManualDesc(''); setManualDate('');
                },
            },
        );
    }

    function cyclePriority() {
        const current = alert.priority ?? 'medium';
        const idx = PRIORITY_CYCLE.indexOf(current as typeof PRIORITY_CYCLE[number]);
        const next = PRIORITY_CYCLE[(idx + 1) % PRIORITY_CYCLE.length];
        router.post(`/control-room/alerts/${alert.id}/meta`, { priority: next }, { preserveScroll: true });
    }

    function saveDueDate() {
        router.post(`/control-room/alerts/${alert.id}/meta`, { due_at: dueDateVal || null }, { preserveScroll: true });
        setEditingDueDate(false);
    }

    function saveCategory() {
        router.post(`/control-room/alerts/${alert.id}/meta`, { category: categoryVal.trim() || null }, { preserveScroll: true });
        setEditingCategory(false);
    }

    // ----- Computed -----
    const completedTasks = tasks.filter((t) => t.status === 'completed').length;
    const taskProgress = tasks.length > 0 ? (completedTasks / tasks.length) * 100 : 0;

    const filteredTasks = useMemo(() => {
        switch (taskFilter) {
            case 'mine': return tasks.filter((t) => t.assigned_to !== null); // shows assigned tasks
            case 'overdue': return tasks.filter((t) => t.status !== 'completed' && isOverdue(t.due_at));
            case 'completed': return tasks.filter((t) => t.status === 'completed');
            default: return tasks;
        }
    }, [tasks, taskFilter]);

    // Merge audit logs into discussion timeline
    const mergedTimeline = useMemo(() => {
        type TimelineItem =
            | { kind: 'discussion'; data: Discussion; ts: number }
            | { kind: 'audit'; data: AuditLogEntry; ts: number };

        const items: TimelineItem[] = [];
        for (const d of discussions) {
            items.push({ kind: 'discussion', data: d, ts: new Date(d.created_at).getTime() });
        }
        for (const a of audit_logs) {
            if (a.action.includes('.view')) continue;
            items.push({ kind: 'audit', data: a, ts: new Date(a.created_at).getTime() });
        }
        items.sort((a, b) => a.ts - b.ts);
        return items;
    }, [discussions, audit_logs]);

    const breadcrumbs = [
        { title: 'Control Room', href: '/control-room' },
        { title: `Alert #${alert.id}`, href: '#' },
    ];

    const ackMet = !!alert.acknowledged_at;
    const resMet = !!alert.resolved_at;

    const tabTriggerCls = 'data-[state=active]:border-b-2 data-[state=active]:border-primary rounded-none data-[state=active]:shadow-none';

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
                        <div className="space-y-1">
                            <h1 className="text-2xl font-bold tracking-tight">
                                {alert.alert_type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
                            </h1>
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span className="font-mono text-xs">#{alert.id}</span>
                                <Badge variant="outline" className="text-xs capitalize">{alert.source}</Badge>
                                <span className="flex items-center gap-1">
                                    <Clock className="h-3.5 w-3.5" />
                                    {fmtDate(alert.triggered_at)}
                                </span>
                                {alert.triggered_at && (
                                    <span className="text-xs text-muted-foreground">({timeAgo(alert.triggered_at)})</span>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Badge className={`px-3 py-1 text-sm font-semibold capitalize ${STATUS_BADGE[alert.status] ?? ''}`}>
                                {alert.status}
                            </Badge>
                            <Badge className={`px-3 py-1 text-sm font-semibold capitalize ${SEVERITY_BADGE[alert.severity] ?? 'bg-gray-500 text-white'}`}>
                                {alert.severity}
                            </Badge>
                            {alert.escalation_level > 0 && (
                                <Badge variant="destructive" className="px-3 py-1 text-sm font-semibold">
                                    <ShieldAlert className="mr-1 h-3.5 w-3.5" />
                                    L{alert.escalation_level}
                                </Badge>
                            )}
                        </div>
                    </div>
                    <StatusStepper alert={alert} />
                </div>

                {/* ============================================================ */}
                {/* MAIN GRID: Content + Sidebar                                 */}
                {/* ============================================================ */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* ---- Left: Tabbed Content ---- */}
                    <div className="space-y-4 lg:col-span-2">
                        <Tabs defaultValue="discussion">
                            <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 rounded-none border-b bg-transparent p-0">
                                <TabsTrigger value="discussion" className={tabTriggerCls}>
                                    <MessageSquare className="mr-1.5 h-3.5 w-3.5" />
                                    Discussion
                                    {discussions.length > 0 && (
                                        <Badge variant="secondary" className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]">
                                            {discussions.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="tasks" className={tabTriggerCls}>
                                    <ListTodo className="mr-1.5 h-3.5 w-3.5" />
                                    Tasks
                                    {tasks.length > 0 && (
                                        <Badge variant="secondary" className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]">
                                            {completedTasks}/{tasks.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="evidence" className={tabTriggerCls}>
                                    Evidence
                                    {evidence_packs.length > 0 && (
                                        <Badge variant="secondary" className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]">
                                            {evidence_packs.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="time" className={tabTriggerCls}>
                                    <Timer className="mr-1.5 h-3.5 w-3.5" />
                                    Time Log
                                    {time_entries.length > 0 && (
                                        <Badge variant="secondary" className="ml-1.5 h-5 min-w-[20px] px-1 text-[10px]">
                                            {fmtDuration(time_spent_minutes)}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="audit" className={tabTriggerCls}>
                                    <Shield className="mr-1.5 h-3.5 w-3.5" />
                                    Audit Trail
                                </TabsTrigger>
                            </TabsList>

                            {/* ====== Tab: Discussion ====== */}
                            <TabsContent value="discussion" className="space-y-4 pt-4">
                                {/* Compose form */}
                                {can.manage && (
                                    <Card>
                                        <CardContent className="pt-4">
                                            <Textarea
                                                value={discussionContent}
                                                onChange={(e) => setDiscussionContent(e.target.value)}
                                                placeholder="Write a comment or internal note..."
                                                rows={3}
                                                className="mb-3"
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                                        e.preventDefault();
                                                        postDiscussion();
                                                    }
                                                }}
                                            />
                                            <div className="flex items-center justify-between">
                                                <Select value={discussionType} onValueChange={setDiscussionType}>
                                                    <SelectTrigger className="w-[180px] text-sm">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="comment">Comment</SelectItem>
                                                        <SelectItem value="internal_note">Internal Note</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <Button
                                                    size="sm"
                                                    onClick={() => postDiscussion()}
                                                    disabled={!discussionContent.trim() || processing}
                                                >
                                                    <Send className="mr-1.5 h-3.5 w-3.5" />
                                                    Post
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Timeline */}
                                {mergedTimeline.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <MessageSquare className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">Start the discussion</p>
                                            <p className="mt-1 text-xs text-muted-foreground">Post the first comment using the form above.</p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-0">
                                        {mergedTimeline.map((item) => {
                                            if (item.kind === 'audit') {
                                                const log = item.data;
                                                return (
                                                    <div key={`audit-${log.id}`} className="flex items-center gap-2 py-2 pl-10">
                                                        <div className="h-px flex-1 bg-border" />
                                                        <span className="whitespace-nowrap text-xs text-muted-foreground">
                                                            {log.action.split('.').pop()?.replace(/([A-Z])/g, ' $1').trim() ?? log.action}
                                                            {log.user ? ` by ${log.user.name}` : ''}
                                                            {' \u2014 '}
                                                            {timeAgo(log.created_at)}
                                                        </span>
                                                        <div className="h-px flex-1 bg-border" />
                                                    </div>
                                                );
                                            }

                                            const disc = item.data;
                                            const isInternal = disc.is_internal;
                                            const isEditing = editingDiscussion === disc.id;

                                            return (
                                                <div
                                                    key={`disc-${disc.id}`}
                                                    className={`relative flex gap-3 py-3 ${
                                                        isInternal ? 'border-l-2 border-amber-400 bg-amber-50/50 pl-3 dark:bg-amber-950/10' : ''
                                                    }`}
                                                >
                                                    <AvatarCircle name={disc.user.name} />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <span className="text-sm font-semibold">{disc.user.name}</span>
                                                            <Badge className={`text-[10px] ${DISCUSSION_TYPE_BADGE[disc.type] ?? 'bg-gray-100 text-gray-600'}`}>
                                                                {disc.type.replace(/_/g, ' ')}
                                                            </Badge>
                                                            <span className="text-[10px] text-muted-foreground">{timeAgo(disc.created_at)}</span>
                                                            {disc.edited_at && (
                                                                <span className="text-[10px] italic text-muted-foreground">(edited)</span>
                                                            )}
                                                        </div>

                                                        {isEditing ? (
                                                            <div className="mt-2 space-y-2">
                                                                <Textarea
                                                                    value={editContent}
                                                                    onChange={(e) => setEditContent(e.target.value)}
                                                                    rows={3}
                                                                    className="text-sm"
                                                                />
                                                                <div className="flex gap-2">
                                                                    <Button size="sm" onClick={() => saveDiscussionEdit(disc.id)} disabled={!editContent.trim() || processing}>
                                                                        Save
                                                                    </Button>
                                                                    <Button size="sm" variant="ghost" onClick={() => { setEditingDiscussion(null); setEditContent(''); }}>
                                                                        Cancel
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <p className="mt-1 whitespace-pre-wrap text-sm">{disc.content}</p>
                                                        )}

                                                        {/* Attachments */}
                                                        {disc.attachments.length > 0 && (
                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                {disc.attachments.map((att, i) => (
                                                                    <a
                                                                        key={i}
                                                                        href={att.path}
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                        className="inline-flex items-center gap-1.5 rounded-md border bg-muted/50 px-2.5 py-1 text-xs hover:bg-muted"
                                                                    >
                                                                        <Paperclip className="h-3 w-3" />
                                                                        <span className="max-w-[120px] truncate">{att.name}</span>
                                                                        <span className="text-muted-foreground">({fmtFileSize(att.size)})</span>
                                                                    </a>
                                                                ))}
                                                            </div>
                                                        )}

                                                        {/* Actions */}
                                                        {!isEditing && (
                                                            <div className="mt-1.5 flex items-center gap-2">
                                                                {can.manage && (
                                                                    <button
                                                                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                                        onClick={() => { setReplyingTo(replyingTo === disc.id ? null : disc.id); setReplyContent(''); }}
                                                                    >
                                                                        <Reply className="h-3 w-3" />
                                                                        Reply
                                                                    </button>
                                                                )}
                                                                {can.manage && (
                                                                    <button
                                                                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                                        onClick={() => { setEditingDiscussion(disc.id); setEditContent(disc.content); }}
                                                                    >
                                                                        <Pencil className="h-3 w-3" />
                                                                        Edit
                                                                    </button>
                                                                )}
                                                            </div>
                                                        )}

                                                        {/* Replies */}
                                                        {disc.replies.length > 0 && (
                                                            <div className="relative mt-3 space-y-3 border-l-2 border-muted pl-4">
                                                                {disc.replies.map((reply) => (
                                                                    <div key={reply.id} className="flex gap-3">
                                                                        <AvatarCircle name={reply.user.name} />
                                                                        <div className="min-w-0 flex-1">
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="text-sm font-semibold">{reply.user.name}</span>
                                                                                <span className="text-[10px] text-muted-foreground">{timeAgo(reply.created_at)}</span>
                                                                                {reply.edited_at && (
                                                                                    <span className="text-[10px] italic text-muted-foreground">(edited)</span>
                                                                                )}
                                                                            </div>
                                                                            <p className="mt-0.5 whitespace-pre-wrap text-sm">{reply.content}</p>
                                                                            {reply.attachments.length > 0 && (
                                                                                <div className="mt-1.5 flex flex-wrap gap-2">
                                                                                    {reply.attachments.map((att, i) => (
                                                                                        <a
                                                                                            key={i}
                                                                                            href={att.path}
                                                                                            target="_blank"
                                                                                            rel="noreferrer"
                                                                                            className="inline-flex items-center gap-1.5 rounded-md border bg-muted/50 px-2.5 py-1 text-xs hover:bg-muted"
                                                                                        >
                                                                                            <Paperclip className="h-3 w-3" />
                                                                                            <span className="max-w-[120px] truncate">{att.name}</span>
                                                                                        </a>
                                                                                    ))}
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        )}

                                                        {/* Inline reply form */}
                                                        {replyingTo === disc.id && (
                                                            <div className="mt-3 border-l-2 border-primary/30 pl-4">
                                                                <Textarea
                                                                    value={replyContent}
                                                                    onChange={(e) => setReplyContent(e.target.value)}
                                                                    placeholder="Write a reply..."
                                                                    rows={2}
                                                                    className="text-sm"
                                                                    autoFocus
                                                                    onKeyDown={(e) => {
                                                                        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                                                            e.preventDefault();
                                                                            postDiscussion(disc.id);
                                                                        }
                                                                    }}
                                                                />
                                                                <div className="mt-2 flex gap-2">
                                                                    <Button size="sm" onClick={() => postDiscussion(disc.id)} disabled={!replyContent.trim() || processing}>
                                                                        <Reply className="mr-1.5 h-3 w-3" />
                                                                        Reply
                                                                    </Button>
                                                                    <Button size="sm" variant="ghost" onClick={() => { setReplyingTo(null); setReplyContent(''); }}>
                                                                        Cancel
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </TabsContent>

                            {/* ====== Tab: Tasks ====== */}
                            <TabsContent value="tasks" className="space-y-4 pt-4">
                                {/* Header */}
                                <div className="flex items-center justify-between gap-4">
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-medium">{completedTasks} of {tasks.length} complete</span>
                                        </div>
                                        {tasks.length > 0 && <Progress value={taskProgress} className="h-2" />}
                                    </div>
                                    {can.manage && (
                                        <Button size="sm" onClick={() => setAddTaskOpen(true)}>
                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                            Add Task
                                        </Button>
                                    )}
                                </div>

                                {/* Filters */}
                                {tasks.length > 0 && (
                                    <div className="flex gap-1">
                                        {(['all', 'mine', 'overdue', 'completed'] as const).map((f) => (
                                            <Button
                                                key={f}
                                                variant={taskFilter === f ? 'default' : 'ghost'}
                                                size="sm"
                                                className="h-7 text-xs capitalize"
                                                onClick={() => setTaskFilter(f)}
                                            >
                                                {f === 'mine' ? 'My Tasks' : f}
                                            </Button>
                                        ))}
                                    </div>
                                )}

                                {/* Task List */}
                                {filteredTasks.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <ListTodo className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                {tasks.length === 0 ? 'No tasks yet. Add one to get started.' : 'No tasks match this filter.'}
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-2">
                                        {filteredTasks.map((task) => {
                                            const completed = task.status === 'completed';
                                            const overdue = !completed && isOverdue(task.due_at);
                                            return (
                                                <Card
                                                    key={task.id}
                                                    className={`transition-colors ${
                                                        overdue ? 'border-red-300 dark:border-red-800' : ''
                                                    } ${completed ? 'opacity-60' : ''}`}
                                                >
                                                    <CardContent className="flex items-start gap-3 py-3">
                                                        {/* Checkbox */}
                                                        <div className="pt-0.5">
                                                            <Checkbox
                                                                checked={completed}
                                                                onCheckedChange={() => toggleTaskStatus(task)}
                                                                disabled={!can.manage}
                                                            />
                                                        </div>

                                                        {/* Content */}
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <span className={`text-sm font-medium ${completed ? 'line-through text-muted-foreground' : ''}`}>
                                                                    {task.title}
                                                                </span>
                                                                <Badge
                                                                    className={`text-[10px] ${
                                                                        PRIORITY_COLORS[task.priority] ?? 'bg-gray-500 text-white'
                                                                    }`}
                                                                >
                                                                    {task.priority}
                                                                </Badge>
                                                            </div>
                                                            {task.description && (
                                                                <p className="mt-0.5 text-xs text-muted-foreground line-clamp-2">{task.description}</p>
                                                            )}
                                                            <div className="mt-1.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                                {task.due_at && (
                                                                    <span className={`flex items-center gap-1 ${overdue ? 'font-semibold text-red-600 dark:text-red-400' : ''}`}>
                                                                        <Calendar className="h-3 w-3" />
                                                                        {fmtShortDate(task.due_at)}
                                                                        {overdue && ' (overdue)'}
                                                                    </span>
                                                                )}
                                                                {task.estimated_minutes && (
                                                                    <span className="flex items-center gap-1">
                                                                        <Clock className="h-3 w-3" />
                                                                        {fmtDuration(task.estimated_minutes)}
                                                                    </span>
                                                                )}
                                                                {task.created_by_name && (
                                                                    <span>by {task.created_by_name}</span>
                                                                )}
                                                            </div>

                                                            {/* Subtasks */}
                                                            {task.subtasks.length > 0 && (
                                                                <div className="mt-2 space-y-1 border-l-2 border-muted pl-3">
                                                                    {task.subtasks.map((sub) => (
                                                                        <div key={sub.id} className="flex items-center gap-2 text-xs">
                                                                            {sub.status === 'completed' ? (
                                                                                <CheckCircle2 className="h-3 w-3 text-green-500" />
                                                                            ) : (
                                                                                <Square className="h-3 w-3 text-muted-foreground" />
                                                                            )}
                                                                            <span className={sub.status === 'completed' ? 'line-through text-muted-foreground' : ''}>
                                                                                {sub.title}
                                                                            </span>
                                                                            {sub.assigned_to && (
                                                                                <span className="text-muted-foreground">- {sub.assigned_to.name}</span>
                                                                            )}
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Right: assignee */}
                                                        <div className="flex flex-col items-end gap-1">
                                                            {task.assigned_to && (
                                                                <AvatarCircle name={task.assigned_to.name} />
                                                            )}
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                )}
                            </TabsContent>

                            {/* ====== Tab: Evidence ====== */}
                            <TabsContent value="evidence" className="space-y-4 pt-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-medium">Evidence Packs ({evidence_packs.length})</h3>
                                    {can.manage && (
                                        <Button variant="outline" size="sm" onClick={() => setEvidencePackOpen(true)} disabled={processing}>
                                            <Package className="mr-1.5 h-3.5 w-3.5" />
                                            Create Pack
                                        </Button>
                                    )}
                                </div>

                                {evidence_packs.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <Package className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">No evidence packs attached.</p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    evidence_packs.map((pack) => (
                                        <Card key={pack.id}>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="flex items-center justify-between text-sm">
                                                    <span>{pack.title}</span>
                                                    <Badge variant="outline" className="text-xs capitalize">{pack.status}</Badge>
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {pack.items.length === 0 ? (
                                                    <p className="text-xs italic text-muted-foreground">No items in this pack.</p>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {pack.items.map((item) => (
                                                            <div key={item.id} className="flex items-center gap-3 rounded-lg bg-muted/50 px-3 py-2">
                                                                <FileText className="h-4 w-4 flex-shrink-0 text-muted-foreground" />
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-sm font-medium">{item.title}</p>
                                                                    <p className="text-[10px] capitalize text-muted-foreground">
                                                                        {item.type}{item.created_at && ` \u2014 ${fmtShortDate(item.created_at)}`}
                                                                    </p>
                                                                </div>
                                                                {item.file_path && (
                                                                    <Button variant="ghost" size="icon" className="h-7 w-7" asChild>
                                                                        <a href={item.file_path} target="_blank" rel="noreferrer">
                                                                            <ExternalLink className="h-3.5 w-3.5" />
                                                                        </a>
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                                {can.manage && (
                                                    <div className="mt-3 flex items-center gap-2">
                                                        <input
                                                            type="file"
                                                            id={`evidence-file-${pack.id}`}
                                                            className="hidden"
                                                            accept="image/*,.pdf,.doc,.docx"
                                                            onChange={(e) => {
                                                                const file = e.target.files?.[0];
                                                                if (!file) return;
                                                                const formData = new FormData();
                                                                formData.append('file', file);
                                                                formData.append('item_type', 'file');
                                                                router.post(`/control-room/evidence/${pack.id}/items`, formData, { preserveScroll: true, forceFormData: true });
                                                                e.target.value = '';
                                                            }}
                                                        />
                                                        <Button variant="outline" size="sm" onClick={() => document.getElementById(`evidence-file-${pack.id}`)?.click()} disabled={processing}>
                                                            <Upload className="mr-1.5 h-3.5 w-3.5" />
                                                            Upload File
                                                        </Button>
                                                        <Button variant="ghost" size="sm" onClick={() => { setEvidenceNotePackId(pack.id); setEvidenceNoteOpen(true); }} disabled={processing}>
                                                            <FileText className="mr-1.5 h-3.5 w-3.5" />
                                                            Add Note
                                                        </Button>
                                                        {pack.status === 'collecting' && (
                                                            <Button variant="ghost" size="sm" onClick={() => router.post(`/control-room/evidence/${pack.id}/complete`, {}, { preserveScroll: true })} disabled={processing}>
                                                                <Check className="mr-1.5 h-3.5 w-3.5" />
                                                                Complete
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    ))
                                )}
                            </TabsContent>

                            {/* ====== Tab: Time Log ====== */}
                            <TabsContent value="time" className="space-y-4 pt-4">
                                {/* Header */}
                                <div className="flex items-center justify-between gap-4">
                                    <div className="flex items-center gap-4">
                                        <div className="text-lg font-bold">
                                            Total: {fmtDuration(time_spent_minutes)}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button variant="outline" size="sm" onClick={() => setManualTimeOpen(true)}>
                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                            Manual Entry
                                        </Button>
                                        {runningEntry ? (
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => setStoppingTimer(true)}
                                                disabled={processing}
                                            >
                                                <StopCircle className="mr-1.5 h-3.5 w-3.5" />
                                                Stop Timer
                                            </Button>
                                        ) : (
                                            <Button size="sm" onClick={startTimer} disabled={processing}>
                                                <Play className="mr-1.5 h-3.5 w-3.5" />
                                                Start Timer
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                {/* Running timer display */}
                                {runningEntry && (
                                    <Card className="border-primary/30 bg-primary/5">
                                        <CardContent className="flex items-center justify-between py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="relative flex h-3 w-3">
                                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75" />
                                                    <span className="relative inline-flex h-3 w-3 rounded-full bg-primary" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium">Timer Running</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Started {timeAgo(runningEntry.started_at)}
                                                        {runningEntry.description && ` \u2014 ${runningEntry.description}`}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <span className="font-mono text-2xl font-bold text-primary">{elapsed}</span>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() => setStoppingTimer(true)}
                                                    disabled={processing}
                                                >
                                                    <StopCircle className="mr-1.5 h-4 w-4" />
                                                    Stop
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Time entries list */}
                                {time_entries.filter((e) => !e.is_running).length === 0 && !runningEntry ? (
                                    <Card>
                                        <CardContent className="py-12 text-center">
                                            <Timer className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">No time logged yet.</p>
                                            <p className="mt-1 text-xs text-muted-foreground">Start the timer or add a manual entry.</p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <Card>
                                        <CardContent className="divide-y pt-4">
                                            {time_entries
                                                .filter((e) => !e.is_running)
                                                .map((entry) => (
                                                    <div key={entry.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                                        <AvatarCircle name={entry.user_name} />
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium">{entry.user_name}</span>
                                                                <span className="text-sm font-semibold text-primary">{fmtDuration(entry.duration_minutes)}</span>
                                                            </div>
                                                            {entry.description && (
                                                                <p className="text-xs text-muted-foreground">{entry.description}</p>
                                                            )}
                                                            <p className="text-[10px] text-muted-foreground">{fmtDate(entry.created_at)}</p>
                                                        </div>
                                                        {can.manage && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7 text-muted-foreground hover:text-red-600"
                                                                onClick={() => router.delete(`/control-room/time-entries/${entry.id}`, { preserveScroll: true })}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
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
                                            <p className="mt-2 text-sm text-muted-foreground">No audit entries recorded.</p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center justify-between text-sm">
                                                <span className="flex items-center gap-2">
                                                    <Shield className="h-4 w-4" />
                                                    Activity Log ({audit_logs.length})
                                                </span>
                                                <Badge variant="outline" className="text-[10px]">Complete audit trail</Badge>
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="relative space-y-0">
                                                {audit_logs.filter((l) => !l.action.includes('.view')).map((log, idx, arr) => {
                                                    const actionMap: Record<string, { icon: typeof Check; label: string; color: string }> = {
                                                        'controlRoom.alert.acknowledge': { icon: Eye, label: 'Acknowledged', color: 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400' },
                                                        'controlRoom.alert.triage': { icon: Search, label: 'Triage started', color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' },
                                                        'controlRoom.alert.resolve': { icon: CheckCircle2, label: 'Resolved', color: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' },
                                                        'controlRoom.alert.close': { icon: XCircle, label: 'Closed', color: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' },
                                                        'controlRoom.alert.assign': { icon: UserCheck, label: 'Assigned', color: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400' },
                                                        'controlRoom.alert.unassign': { icon: UserMinus, label: 'Unassigned', color: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
                                                        'controlRoom.alert.escalate': { icon: ArrowUpRight, label: 'Escalated', color: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' },
                                                        'controlRoom.alert.addNote': { icon: MessageSquare, label: 'Note added', color: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400' },
                                                        'controlRoom.alert.create': { icon: Play, label: 'Alert created', color: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400' },
                                                    };
                                                    const config = actionMap[log.action] || {
                                                        icon: Clock,
                                                        label: log.action.split('.').pop()?.replace(/([A-Z])/g, ' $1') || log.action,
                                                        color: 'bg-muted text-muted-foreground',
                                                    };
                                                    const Icon = config.icon;

                                                    return (
                                                        <div key={log.id} className="relative flex gap-4 pb-5 last:pb-0">
                                                            {idx < arr.length - 1 && (
                                                                <div className="absolute bottom-0 left-[15px] top-8 w-px bg-border" />
                                                            )}
                                                            <div className={`relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${config.color}`}>
                                                                <Icon className="h-4 w-4" />
                                                            </div>
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex items-center gap-2">
                                                                    <p className="text-sm font-medium">{config.label}</p>
                                                                    {log.meta?.escalation_level && (
                                                                        <Badge variant="outline" className="border-red-200 text-[10px] text-red-600">
                                                                            L{String(log.meta.escalation_level)}
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                                    <User className="h-3 w-3" />
                                                                    {log.user?.name ?? 'System'}
                                                                    <span>&middot;</span>
                                                                    {fmtDate(log.created_at)}
                                                                </p>
                                                                {log.meta?.assigned_to && (
                                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                                        Assigned to user #{String(log.meta.assigned_to)}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}
                            </TabsContent>
                        </Tabs>
                    </div>

                    {/* ---- Right: Sidebar ---- */}
                    <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        {/* Ticket Info Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Ticket Info</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                {/* Priority */}
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Priority</span>
                                    <button
                                        onClick={cyclePriority}
                                        disabled={!can.manage}
                                        title="Click to change priority"
                                    >
                                        <Badge className={`capitalize ${PRIORITY_COLORS[alert.priority ?? 'medium'] ?? 'bg-gray-500 text-white'}`}>
                                            {alert.priority ?? 'medium'}
                                        </Badge>
                                    </button>
                                </div>

                                {/* Category */}
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Category</span>
                                    {can.manage && config_options.categories.length > 0 ? (
                                        <Select
                                            value={alert.category || 'none'}
                                            onValueChange={(v) => router.post(`/control-room/alerts/${alert.id}/meta`, { category: v === 'none' ? null : v }, { preserveScroll: true })}
                                        >
                                            <SelectTrigger className="h-7 w-36 text-xs">
                                                <SelectValue placeholder="Select..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">None</SelectItem>
                                                {config_options.categories.map((cat) => (
                                                    <SelectItem key={cat.value} value={cat.value}>
                                                        <span className="flex items-center gap-1.5">
                                                            {cat.color && <span className="inline-block h-2 w-2 rounded-full" style={{ backgroundColor: cat.color }} />}
                                                            {cat.label}
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <span className="text-sm">{alert.category || '\u2014'}</span>
                                    )}
                                </div>

                                {/* Due Date */}
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Due Date</span>
                                    {editingDueDate ? (
                                        <div className="flex items-center gap-1">
                                            <Input
                                                type="datetime-local"
                                                value={dueDateVal}
                                                onChange={(e) => setDueDateVal(e.target.value)}
                                                className="h-7 w-44 text-xs"
                                                autoFocus
                                                onKeyDown={(e) => { if (e.key === 'Enter') saveDueDate(); if (e.key === 'Escape') setEditingDueDate(false); }}
                                            />
                                            <Button size="icon" variant="ghost" className="h-6 w-6" onClick={saveDueDate}>
                                                <Check className="h-3 w-3" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <button
                                            className={`flex items-center gap-1 text-sm hover:text-primary ${
                                                isOverdue(alert.due_at) ? 'font-semibold text-red-600 dark:text-red-400' : ''
                                            }`}
                                            onClick={() => { setDueDateVal(alert.due_at?.slice(0, 16) ?? ''); setEditingDueDate(true); }}
                                            disabled={!can.manage}
                                        >
                                            <span>
                                                {alert.due_at ? fmtShortDate(alert.due_at) : '\u2014'}
                                                {isOverdue(alert.due_at) && ' (overdue)'}
                                            </span>
                                            {can.manage && <Pencil className="h-3 w-3 text-muted-foreground" />}
                                        </button>
                                    )}
                                </div>

                                {/* Resolution Code */}
                                {(alert.status === 'resolved' || alert.status === 'closed') && alert.resolution_code && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Resolution</span>
                                        <Badge variant="outline" className="text-xs capitalize">
                                            {alert.resolution_code.replace(/_/g, ' ')}
                                        </Badge>
                                    </div>
                                )}

                                {/* Asset */}
                                {alert.asset && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Asset</span>
                                        <Link href={`/fleet-assets/assets/${alert.asset.id}`} className="text-sm text-primary hover:underline">
                                            {alert.asset.name}
                                        </Link>
                                    </div>
                                )}

                                {/* Client */}
                                {client && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Client</span>
                                        <Link href={`/clients/${client.id}`} className="text-sm text-primary hover:underline">
                                            {client.name}
                                        </Link>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Watchers Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center justify-between text-base">
                                    <span>Watchers ({watchers.length})</span>
                                    {can.manage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7"
                                            onClick={() => setAddWatcherOpen(!addWatcherOpen)}
                                        >
                                            <UserPlus className="h-4 w-4" />
                                        </Button>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {/* Self watch toggle */}
                                <Button
                                    variant={is_watching ? 'default' : 'outline'}
                                    size="sm"
                                    className="w-full"
                                    onClick={() => router.post(`/control-room/alerts/${alert.id}/watchers/toggle`, {}, { preserveScroll: true })}
                                >
                                    {is_watching ? (
                                        <>
                                            <EyeOff className="mr-1.5 h-3.5 w-3.5" />
                                            Unwatch
                                        </>
                                    ) : (
                                        <>
                                            <Eye className="mr-1.5 h-3.5 w-3.5" />
                                            Watch
                                        </>
                                    )}
                                </Button>

                                {/* Add watcher dropdown */}
                                {addWatcherOpen && (
                                    <Select
                                        onValueChange={(val) => {
                                            router.post(`/control-room/alerts/${alert.id}/watchers`, { user_id: Number(val) }, { preserveScroll: true });
                                            setAddWatcherOpen(false);
                                        }}
                                    >
                                        <SelectTrigger className="text-sm">
                                            <SelectValue placeholder="Add a watcher..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff
                                                .filter((s) => !watchers.some((w) => w.user_id === s.id))
                                                .map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                )}

                                {/* Watcher list */}
                                {watchers.length > 0 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {watchers.map((w) => (
                                            <div key={w.id} className="group inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs">
                                                <span>{w.user_name}</span>
                                                {can.manage && (
                                                    <button
                                                        className="hidden text-muted-foreground hover:text-red-500 group-hover:inline"
                                                        onClick={() => router.delete(`/control-room/alerts/${alert.id}/watchers/${w.user_id}`, { preserveScroll: true })}
                                                    >
                                                        <XCircle className="h-3 w-3" />
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Time Summary Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Timer className="h-4 w-4" />
                                    Time Spent
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p className="text-2xl font-bold">{fmtDuration(time_spent_minutes)}</p>
                                {!runningEntry && (
                                    <Button size="sm" className="w-full" onClick={startTimer} disabled={processing}>
                                        <Play className="mr-1.5 h-3.5 w-3.5" />
                                        Start Timer
                                    </Button>
                                )}
                                {runningEntry && (
                                    <div className="flex items-center gap-2">
                                        <div className="relative flex h-2 w-2">
                                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75" />
                                            <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                                        </div>
                                        <span className="font-mono text-sm font-semibold text-primary">{elapsed}</span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* SLA Status */}
                        {sla && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Clock className="h-4 w-4" />
                                        SLA Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <SlaGauge label="Acknowledge" deadline={sla.acknowledge_deadline} breached={sla.acknowledge_breached} met={ackMet} />
                                    <SlaGauge label="Response" deadline={sla.response_deadline} breached={sla.response_breached} met={false} />
                                    <SlaGauge label="Resolution" deadline={sla.resolution_deadline} breached={sla.resolution_breached} met={resMet} />
                                </CardContent>
                            </Card>
                        )}

                        {/* Quick Actions */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Quick Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Button
                                    className="h-auto w-full justify-start gap-3 border border-yellow-500/30 bg-yellow-500/10 py-3 text-yellow-700 hover:bg-yellow-500/20 dark:text-yellow-400"
                                    variant="outline"
                                    disabled={alert.status !== 'open' || !can.manage || processing}
                                    onClick={() => doAction('acknowledge')}
                                >
                                    <Eye className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="text-sm font-semibold">Acknowledge</div>
                                        <div className="text-[11px] font-normal opacity-70">Mark as seen</div>
                                    </div>
                                </Button>

                                <Button
                                    className="h-auto w-full justify-start gap-3 border border-blue-500/30 bg-blue-500/10 py-3 text-blue-700 hover:bg-blue-500/20 dark:text-blue-400"
                                    variant="outline"
                                    disabled={!['open', 'acknowledged'].includes(alert.status) || !can.manage || processing}
                                    onClick={() => doAction('triage')}
                                >
                                    <Search className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="text-sm font-semibold">Start Triage</div>
                                        <div className="text-[11px] font-normal opacity-70">Begin investigation</div>
                                    </div>
                                </Button>

                                <Button
                                    className="h-auto w-full justify-start gap-3 border border-green-500/30 bg-green-500/10 py-3 text-green-700 hover:bg-green-500/20 dark:text-green-400"
                                    variant="outline"
                                    disabled={alert.status === 'resolved' || alert.status === 'closed' || !can.manage || processing}
                                    onClick={() => setResolveOpen(true)}
                                >
                                    <CheckCircle2 className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="text-sm font-semibold">Resolve</div>
                                        <div className="text-[11px] font-normal opacity-70">Mark resolved</div>
                                    </div>
                                </Button>

                                <Button
                                    className="h-auto w-full justify-start gap-3 border border-gray-500/30 bg-gray-500/10 py-3 text-gray-700 hover:bg-gray-500/20 dark:text-gray-400"
                                    variant="outline"
                                    disabled={alert.status !== 'resolved' || !can.manage || processing}
                                    onClick={() => doAction('close')}
                                >
                                    <XCircle className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="text-sm font-semibold">Close</div>
                                        <div className="text-[11px] font-normal opacity-70">Close permanently</div>
                                    </div>
                                </Button>

                                <Button
                                    className="h-auto w-full justify-start gap-3 border border-red-500/30 bg-red-500/10 py-3 text-red-700 hover:bg-red-500/20 dark:text-red-400"
                                    variant="outline"
                                    disabled={alert.status === 'closed' || !can.escalate || processing}
                                    onClick={() => setEscalateOpen(true)}
                                >
                                    <ArrowUpRight className="h-5 w-5 flex-shrink-0" />
                                    <div className="text-left">
                                        <div className="text-sm font-semibold">Escalate</div>
                                        <div className="text-[11px] font-normal opacity-70">Raise to L{alert.escalation_level + 1}</div>
                                    </div>
                                </Button>
                            </CardContent>
                        </Card>

                        {/* Assignment */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="h-4 w-4" />
                                    Assignment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center gap-3">
                                    <div
                                        className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold ${
                                            alert.assigned_to ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                                        }`}
                                    >
                                        {alert.assigned_to ? initial(alert.assigned_to.name) : '?'}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{alert.assigned_to?.name ?? 'Unassigned'}</p>
                                        {alert.assigned_to?.email && (
                                            <p className="truncate text-xs text-muted-foreground">{alert.assigned_to.email}</p>
                                        )}
                                    </div>
                                </div>

                                {can.assign && (
                                    <>
                                        <Button variant="default" size="sm" className="w-full" disabled={processing} onClick={() => doAction('assign-to-me')}>
                                            <UserCheck className="mr-1.5 h-4 w-4" />
                                            Assign to Me
                                        </Button>

                                        <Select value={assigneeId} onValueChange={(val) => handleAssign(val)}>
                                            <SelectTrigger className="text-sm">
                                                <SelectValue placeholder="Assign to..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>

                                        {alert.assigned_to && (
                                            <Button variant="ghost" size="sm" className="w-full text-muted-foreground" disabled={processing} onClick={() => doAction('unassign')}>
                                                <UserMinus className="mr-1.5 h-4 w-4" />
                                                Unassign
                                            </Button>
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {/* Location */}
                        {location && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <MapPin className="h-4 w-4" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {location.description && <p className="text-sm">{location.description}</p>}
                                    <p className="font-mono text-xs text-muted-foreground">
                                        {location.lat.toFixed(6)}, {location.lng.toFixed(6)}
                                    </p>
                                    <Button variant="outline" size="sm" className="w-full" asChild>
                                        <a href={`https://www.google.com/maps?q=${location.lat},${location.lng}`} target="_blank" rel="noreferrer">
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
                            <Button variant="ghost" onClick={() => setResolveOpen(false)}>Cancel</Button>
                            <Button onClick={handleResolve} disabled={!resolveNotes.trim() || processing} className="bg-green-600 text-white hover:bg-green-700">
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
                                    <Badge variant="destructive" className="ml-1">L{alert.escalation_level}</Badge>
                                </div>
                                <span className="text-muted-foreground">&rarr;</span>
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Escalate to:</span>{' '}
                                    <Badge variant="destructive" className="ml-1">L{alert.escalation_level + 1}</Badge>
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
                            <Button variant="ghost" onClick={() => setEscalateOpen(false)}>Cancel</Button>
                            <Button onClick={handleEscalate} disabled={!escalateReason.trim() || processing} variant="destructive">
                                <ArrowUpRight className="mr-1.5 h-4 w-4" />
                                Escalate to L{alert.escalation_level + 1}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Add Task Dialog */}
                <Dialog open={addTaskOpen} onOpenChange={setAddTaskOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Task</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4 py-2">
                            <div className="space-y-2">
                                <Label htmlFor="task-title">Title</Label>
                                <Input
                                    id="task-title"
                                    value={taskTitle}
                                    onChange={(e) => setTaskTitle(e.target.value)}
                                    placeholder="Task title..."
                                    autoFocus
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="task-desc">Description</Label>
                                <Textarea
                                    id="task-desc"
                                    value={taskDesc}
                                    onChange={(e) => setTaskDesc(e.target.value)}
                                    placeholder="Optional description..."
                                    rows={3}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label>Assignee</Label>
                                    <Select value={taskAssignee} onValueChange={setTaskAssignee}>
                                        <SelectTrigger className="text-sm">
                                            <SelectValue placeholder="Unassigned" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Priority</Label>
                                    <Select value={taskPriority} onValueChange={setTaskPriority}>
                                        <SelectTrigger className="text-sm">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="medium">Medium</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="critical">Critical</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="task-due">Due Date</Label>
                                    <Input
                                        id="task-due"
                                        type="datetime-local"
                                        value={taskDueAt}
                                        onChange={(e) => setTaskDueAt(e.target.value)}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="task-est">Estimated (minutes)</Label>
                                    <Input
                                        id="task-est"
                                        type="number"
                                        min="0"
                                        value={taskEstimated}
                                        onChange={(e) => setTaskEstimated(e.target.value)}
                                        placeholder="0"
                                    />
                                </div>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setAddTaskOpen(false)}>Cancel</Button>
                            <Button onClick={handleCreateTask} disabled={!taskTitle.trim() || processing}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add Task
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Manual Time Entry Dialog */}
                <Dialog open={manualTimeOpen} onOpenChange={setManualTimeOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Manual Time Entry</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4 py-2">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="manual-hours">Hours</Label>
                                    <Input
                                        id="manual-hours"
                                        type="number"
                                        min="0"
                                        value={manualHours}
                                        onChange={(e) => setManualHours(e.target.value)}
                                        placeholder="0"
                                        autoFocus
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="manual-minutes">Minutes</Label>
                                    <Input
                                        id="manual-minutes"
                                        type="number"
                                        min="0"
                                        max="59"
                                        value={manualMinutes}
                                        onChange={(e) => setManualMinutes(e.target.value)}
                                        placeholder="0"
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="manual-desc">Description</Label>
                                <Textarea
                                    id="manual-desc"
                                    value={manualDesc}
                                    onChange={(e) => setManualDesc(e.target.value)}
                                    placeholder="What did you work on?"
                                    rows={3}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="manual-date">Date</Label>
                                <Input
                                    id="manual-date"
                                    type="date"
                                    value={manualDate}
                                    onChange={(e) => setManualDate(e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setManualTimeOpen(false)}>Cancel</Button>
                            <Button
                                onClick={handleManualEntry}
                                disabled={((parseInt(manualHours) || 0) * 60 + (parseInt(manualMinutes) || 0)) <= 0 || processing}
                            >
                                <Clock className="mr-1.5 h-4 w-4" />
                                Add Entry
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Stop Timer Dialog */}
                <Dialog open={stoppingTimer} onOpenChange={setStoppingTimer}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Stop Timer</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="stop-desc">Description (optional)</Label>
                            <Textarea
                                id="stop-desc"
                                value={stopDesc}
                                onChange={(e) => setStopDesc(e.target.value)}
                                placeholder="What did you work on during this time?"
                                rows={3}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setStoppingTimer(false)}>Cancel</Button>
                            <Button
                                variant="destructive"
                                onClick={() => runningEntry && stopTimer(runningEntry.id)}
                                disabled={processing}
                            >
                                <StopCircle className="mr-1.5 h-4 w-4" />
                                Stop Timer
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Create Evidence Pack Dialog */}
                <Dialog open={evidencePackOpen} onOpenChange={setEvidencePackOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create Evidence Pack</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="pack-title">Pack Title</Label>
                            <Input
                                id="pack-title"
                                value={evidencePackTitle}
                                onChange={(e) => setEvidencePackTitle(e.target.value)}
                                placeholder="e.g. CCTV Footage, Witness Statements, Photos..."
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => { setEvidencePackOpen(false); setEvidencePackTitle(''); }}>Cancel</Button>
                            <Button
                                onClick={() => {
                                    if (!evidencePackTitle.trim()) return;
                                    router.post(`/control-room/alerts/${alert.id}/evidence`, { title: evidencePackTitle.trim() }, { preserveScroll: true });
                                    setEvidencePackOpen(false);
                                    setEvidencePackTitle('');
                                }}
                                disabled={!evidencePackTitle.trim() || processing}
                            >
                                <Package className="mr-1.5 h-4 w-4" />
                                Create Pack
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Add Evidence Note Dialog */}
                <Dialog open={evidenceNoteOpen} onOpenChange={setEvidenceNoteOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Evidence Note</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="evidence-note">Note Content</Label>
                            <Textarea
                                id="evidence-note"
                                value={evidenceNoteContent}
                                onChange={(e) => setEvidenceNoteContent(e.target.value)}
                                placeholder="Describe the evidence or observation..."
                                rows={4}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => { setEvidenceNoteOpen(false); setEvidenceNoteContent(''); setEvidenceNotePackId(null); }}>
                                Cancel
                            </Button>
                            <Button
                                onClick={() => {
                                    if (!evidenceNoteContent.trim() || !evidenceNotePackId) return;
                                    router.post(`/control-room/evidence/${evidenceNotePackId}/items`, {
                                        item_type: 'note',
                                        content: evidenceNoteContent.trim(),
                                    }, { preserveScroll: true });
                                    setEvidenceNoteOpen(false);
                                    setEvidenceNoteContent('');
                                    setEvidenceNotePackId(null);
                                }}
                                disabled={!evidenceNoteContent.trim() || processing}
                            >
                                <FileText className="mr-1.5 h-4 w-4" />
                                Add Note
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
