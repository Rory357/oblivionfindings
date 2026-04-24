import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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
    ArrowLeft,
    ArrowRight,
    Check,
    CheckCircle,
    Clock,
    MessageSquare,
    Pin,
    Plus,
    ShieldAlert,
    TrendingUp,
    Trash2,
    Users,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- TypeScript Interfaces ---

interface StaffMember {
    id: number;
    name: string;
}

interface ShiftData {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    shift_lead: StaffMember | null;
    team_members: StaffMember[];
    open_alerts_at_start: number;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
    duration_minutes: number | null;
}

interface AlertSummary {
    id: number;
    alert_type: string;
    severity: string;
    triggered_at: string | null;
}

interface OperatorNote {
    id: number;
    type: string;
    content: string;
    is_pinned: boolean;
    requires_followup: boolean;
    followup_at: string | null;
    user: StaffMember | null;
    created_at: string;
}

interface Props {
    shift: ShiftData;
    openAlertsCount: number;
    criticalAlertsCount: number;
    highAlertsCount: number;
    criticalAlerts: AlertSummary[];
    highAlerts: AlertSummary[];
    pinnedNotes: OperatorNote[];
    followupNotes: OperatorNote[];
    staff: StaffMember[];
}

// --- Helpers ---

const STEPS = ['Summary', 'Notes', 'Incoming Team', 'Confirm'] as const;

function formatDuration(minutes: number | null): string {
    if (minutes === null || minutes === undefined) return '-';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m}m`;
    return `${h}h ${m}m`;
}

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    return date.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ${diffMins % 60}m ago`;
    return `${Math.floor(diffHours / 24)}d ${diffHours % 24}h ago`;
}

function severityBadgeClass(severity: string): string {
    switch (severity) {
        case 'critical':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        case 'high':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'medium':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
}

// --- Step Indicator ---

function StepIndicator({ currentStep }: { currentStep: number }) {
    return (
        <nav aria-label="Handover steps" className="mb-8">
            <ol className="flex items-center justify-center gap-2">
                {STEPS.map((label, index) => {
                    const isCompleted = index < currentStep;
                    const isCurrent = index === currentStep;

                    return (
                        <li key={label} className="flex items-center gap-2">
                            <div className="flex items-center gap-2">
                                <span
                                    className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-medium transition-colors ${
                                        isCompleted
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : isCurrent
                                              ? 'border-primary bg-primary/10 text-primary'
                                              : 'border-muted-foreground/30 bg-muted text-muted-foreground'
                                    }`}
                                >
                                    {isCompleted ? (
                                        <Check className="h-4 w-4" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span
                                    className={`hidden text-sm font-medium sm:inline ${
                                        isCurrent
                                            ? 'text-foreground'
                                            : isCompleted
                                              ? 'text-primary'
                                              : 'text-muted-foreground'
                                    }`}
                                >
                                    {label}
                                </span>
                            </div>
                            {index < STEPS.length - 1 && (
                                <div
                                    className={`hidden h-0.5 w-8 sm:block lg:w-16 ${
                                        isCompleted ? 'bg-primary' : 'bg-muted-foreground/20'
                                    }`}
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

// --- Main Component ---

export default function ShiftHandover({
    shift,
    openAlertsCount,
    criticalAlertsCount,
    highAlertsCount,
    criticalAlerts,
    highAlerts,
    pinnedNotes,
    followupNotes,
    staff,
}: Props) {
    const [currentStep, setCurrentStep] = useState(0);
    const [submitting, setSubmitting] = useState(false);

    // Step 2: Handover notes state
    const [handoverNotes, setHandoverNotes] = useState('');
    const [priorityItems, setPriorityItems] = useState<string[]>([]);
    const [newPriorityItem, setNewPriorityItem] = useState('');

    // Step 3: Incoming shift state
    const [incomingShiftName, setIncomingShiftName] = useState('');
    const [incomingLeadUserId, setIncomingLeadUserId] = useState('');
    const [incomingTeamMembers, setIncomingTeamMembers] = useState<number[]>([]);

    const addPriorityItem = () => {
        const trimmed = newPriorityItem.trim();
        if (trimmed) {
            setPriorityItems((prev) => [...prev, trimmed]);
            setNewPriorityItem('');
        }
    };

    const removePriorityItem = (index: number) => {
        setPriorityItems((prev) => prev.filter((_, i) => i !== index));
    };

    const toggleTeamMember = (userId: number) => {
        setIncomingTeamMembers((prev) =>
            prev.includes(userId)
                ? prev.filter((id) => id !== userId)
                : [...prev, userId],
        );
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (submitting) return;
        setSubmitting(true);

        router.post(
            `/control-room/shifts/${shift.id}/handover`,
            {
                handover_notes: handoverNotes,
                priority_items: priorityItems,
                incoming_shift_name: incomingShiftName || undefined,
                incoming_lead_user_id: incomingLeadUserId ? Number(incomingLeadUserId) : undefined,
                incoming_team_members: incomingTeamMembers,
            },
            {
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const goNext = () => setCurrentStep((s) => Math.min(s + 1, STEPS.length - 1));
    const goBack = () => setCurrentStep((s) => Math.max(s - 1, 0));

    // All critical + high alerts combined for display
    const urgentAlerts = [...criticalAlerts, ...highAlerts];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Shifts', href: '/control-room/shifts' },
                { title: 'Handover', href: '#' },
            ]}
        >
            <Head title={`Handover - ${shift.name}`} />
            <PageShell>
                <PageHeader
                    title="Shift Handover"
                    description={`Hand over ${shift.name} to the incoming team.`}
                    backHref="/control-room/shifts"
                    backLabel="Back to Shifts"
                />

                <StepIndicator currentStep={currentStep} />

                {/* Step 1: Outgoing Shift Summary */}
                {currentStep === 0 && (
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Clock className="h-5 w-5" />
                                    Outgoing Shift Summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <p className="text-sm text-muted-foreground">Shift Name</p>
                                        <p className="font-medium">{shift.name}</p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Duration</p>
                                        <p className="font-medium">
                                            {formatDuration(shift.duration_minutes)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Shift Lead</p>
                                        <p className="font-medium">
                                            {shift.shift_lead?.name ?? 'Unassigned'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Team Members</p>
                                        <p className="font-medium">
                                            {shift.team_members.length > 0
                                                ? shift.team_members.map((m) => m.name).join(', ')
                                                : 'None'}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Metrics grid */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-info-bg">
                                            <TrendingUp className="h-5 w-5 text-status-info" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Alerts Created</p>
                                            <p className="text-2xl font-bold">{shift.alerts_created}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-success-bg">
                                            <CheckCircle className="h-5 w-5 text-status-success" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Alerts Resolved</p>
                                            <p className="text-2xl font-bold">{shift.alerts_resolved}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning-bg">
                                            <AlertTriangle className="h-5 w-5 text-status-warning" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Alerts Escalated</p>
                                            <p className="text-2xl font-bold">{shift.alerts_escalated}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-critical-bg">
                                            <ShieldAlert className="h-5 w-5 text-status-critical" />
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">Open Now</p>
                                            <p className="text-2xl font-bold">{openAlertsCount}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Critical / High alerts list */}
                        {urgentAlerts.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AlertTriangle className="h-4 w-4 text-status-critical" />
                                        Critical &amp; High Severity Alerts ({criticalAlertsCount + highAlertsCount})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {urgentAlerts.map((alert) => (
                                            <div
                                                key={alert.id}
                                                className="flex items-center justify-between rounded-lg border px-4 py-2"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <Badge
                                                        variant="outline"
                                                        className={severityBadgeClass(alert.severity)}
                                                    >
                                                        {alert.severity}
                                                    </Badge>
                                                    <span className="text-sm font-medium">
                                                        {alert.alert_type}
                                                    </span>
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatRelativeTime(alert.triggered_at)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        <div className="flex justify-end">
                            <Button onClick={goNext}>
                                Next
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 2: Handover Notes */}
                {currentStep === 1 && (
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <MessageSquare className="h-5 w-5" />
                                    Handover Notes
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <Label htmlFor="handover-notes">
                                        Handover Narrative
                                    </Label>
                                    <Textarea
                                        id="handover-notes"
                                        value={handoverNotes}
                                        onChange={(e) => setHandoverNotes(e.target.value)}
                                        placeholder="Summarise key events, ongoing situations, and anything the incoming team needs to know..."
                                        rows={6}
                                        className="mt-1.5"
                                    />
                                </div>

                                {/* Priority Items */}
                                <div>
                                    <Label>Priority Items</Label>
                                    <p className="mb-2 text-sm text-muted-foreground">
                                        Items that need immediate attention from the incoming team.
                                    </p>
                                    {priorityItems.length > 0 && (
                                        <ul className="mb-3 space-y-2">
                                            {priorityItems.map((item, index) => (
                                                <li
                                                    key={index}
                                                    className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2"
                                                >
                                                    <span className="flex-1 text-sm">{item}</span>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => removePriorityItem(index)}
                                                        className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="flex gap-2">
                                        <Input
                                            value={newPriorityItem}
                                            onChange={(e) => setNewPriorityItem(e.target.value)}
                                            placeholder="Add a priority item..."
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    addPriorityItem();
                                                }
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={addPriorityItem}
                                        >
                                            <Plus className="mr-1 h-4 w-4" />
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Pinned Notes */}
                        {pinnedNotes.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Pin className="h-4 w-4" />
                                        Pinned Operator Notes
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {pinnedNotes.map((note) => (
                                            <div
                                                key={note.id}
                                                className="rounded-lg border bg-status-warning-bg p-3"
                                            >
                                                <div className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                    <span className="font-medium">
                                                        {note.user?.name ?? 'Unknown'}
                                                    </span>
                                                    <span>&middot;</span>
                                                    <span>{formatDateTime(note.created_at)}</span>
                                                </div>
                                                <p className="text-sm">{note.content}</p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Follow-up Notes */}
                        {followupNotes.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Clock className="h-4 w-4" />
                                        Notes Requiring Follow-up
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {followupNotes.map((note) => (
                                            <div
                                                key={note.id}
                                                className="rounded-lg border bg-status-info-bg p-3"
                                            >
                                                <div className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                    <span className="font-medium">
                                                        {note.user?.name ?? 'Unknown'}
                                                    </span>
                                                    <span>&middot;</span>
                                                    <span>{formatDateTime(note.created_at)}</span>
                                                    {note.followup_at && (
                                                        <>
                                                            <span>&middot;</span>
                                                            <Badge
                                                                variant="outline"
                                                                className="bg-status-info-bg text-status-info border-status-info/30 text-xs"
                                                            >
                                                                Follow-up: {formatDateTime(note.followup_at)}
                                                            </Badge>
                                                        </>
                                                    )}
                                                </div>
                                                <p className="text-sm">{note.content}</p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        <div className="flex justify-between">
                            <Button variant="outline" onClick={goBack}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                            <Button onClick={goNext}>
                                Next
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 3: Incoming Shift Setup */}
                {currentStep === 2 && (
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Users className="h-5 w-5" />
                                    Incoming Shift Setup
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <Label htmlFor="incoming-shift-name">
                                        New Shift Name
                                    </Label>
                                    <Input
                                        id="incoming-shift-name"
                                        value={incomingShiftName}
                                        onChange={(e) => setIncomingShiftName(e.target.value)}
                                        placeholder={`e.g. Night Shift ${new Date().toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}`}
                                        className="mt-1.5"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="incoming-lead">
                                        Incoming Shift Lead
                                    </Label>
                                    <Select
                                        value={incomingLeadUserId}
                                        onValueChange={setIncomingLeadUserId}
                                    >
                                        <SelectTrigger id="incoming-lead" className="mt-1.5">
                                            <SelectValue placeholder="Select shift lead..." />
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

                                <div>
                                    <Label>Incoming Team Members</Label>
                                    <p className="mb-2 text-sm text-muted-foreground">
                                        Select the staff members joining the incoming shift.
                                    </p>
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {staff.map((s) => (
                                            <label
                                                key={s.id}
                                                className={`flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 transition-colors ${
                                                    incomingTeamMembers.includes(s.id)
                                                        ? 'border-primary bg-primary/5'
                                                        : 'hover:bg-muted/50'
                                                }`}
                                            >
                                                <Checkbox
                                                    checked={incomingTeamMembers.includes(s.id)}
                                                    onCheckedChange={() => toggleTeamMember(s.id)}
                                                />
                                                <span className="text-sm">{s.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {staff.length === 0 && (
                                        <p className="text-sm text-muted-foreground italic">
                                            No staff members available.
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex justify-between">
                            <Button variant="outline" onClick={goBack}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                            <Button onClick={goNext}>
                                Next
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 4: Confirmation & Submit */}
                {currentStep === 3 && (
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <CheckCircle className="h-5 w-5" />
                                    Handover Confirmation
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {/* Outgoing summary */}
                                <div>
                                    <h4 className="mb-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Outgoing Shift
                                    </h4>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <div>
                                                <p className="text-xs text-muted-foreground">Shift</p>
                                                <p className="text-sm font-medium">{shift.name}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Created</p>
                                                <p className="text-sm font-medium">{shift.alerts_created}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Resolved</p>
                                                <p className="text-sm font-medium">{shift.alerts_resolved}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Open Alerts</p>
                                                <p className="text-sm font-medium">{openAlertsCount}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Handover notes preview */}
                                <div>
                                    <h4 className="mb-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Handover Notes
                                    </h4>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        {handoverNotes ? (
                                            <p className="text-sm whitespace-pre-wrap">
                                                {handoverNotes.length > 500
                                                    ? `${handoverNotes.slice(0, 500)}...`
                                                    : handoverNotes}
                                            </p>
                                        ) : (
                                            <p className="text-sm italic text-muted-foreground">
                                                No handover notes provided.
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Priority items */}
                                {priorityItems.length > 0 && (
                                    <div>
                                        <h4 className="mb-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                            Priority Items ({priorityItems.length})
                                        </h4>
                                        <ul className="space-y-1 rounded-lg border bg-muted/30 p-4">
                                            {priorityItems.map((item, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-start gap-2 text-sm"
                                                >
                                                    <span className="mt-0.5 text-primary">&bull;</span>
                                                    {item}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {/* Incoming shift info */}
                                <div>
                                    <h4 className="mb-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Incoming Shift
                                    </h4>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <p className="text-xs text-muted-foreground">Shift Name</p>
                                                <p className="text-sm font-medium">
                                                    {incomingShiftName || 'Auto-generated'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Lead</p>
                                                <p className="text-sm font-medium">
                                                    {incomingLeadUserId
                                                        ? staff.find(
                                                              (s) => String(s.id) === incomingLeadUserId,
                                                          )?.name ?? 'Unknown'
                                                        : 'Not assigned'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Team</p>
                                                <p className="text-sm font-medium">
                                                    {incomingTeamMembers.length > 0
                                                        ? staff
                                                              .filter((s) =>
                                                                  incomingTeamMembers.includes(s.id),
                                                              )
                                                              .map((s) => s.name)
                                                              .join(', ')
                                                        : 'No members selected'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex justify-between">
                            <Button type="button" variant="outline" onClick={goBack}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                            <Button
                                type="submit"
                                size="lg"
                                disabled={submitting}
                            >
                                {submitting ? 'Completing Handover...' : 'Complete Handover'}
                            </Button>
                        </div>
                    </form>
                )}
            </PageShell>
        </AppLayout>
    );
}
