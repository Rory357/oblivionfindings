import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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
    AlertTriangle,
    ArrowRightLeft,
    Calendar,
    CheckCircle,
    Clock,
    MessageSquarePlus,
    Pin,
    Plus,
    TrendingUp,
    User,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- TypeScript Interfaces ---

interface StaffMember {
    id: number;
    name: string;
}

interface ActiveShift {
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
    handover_status: 'none' | 'prepared' | 'accepted';
    handover_version: number;
    handover_prepared_at: string | null;
    incoming_lead: StaffMember | null;
}

interface OperatorNote {
    id: number;
    type: string;
    content: string;
    is_pinned: boolean;
    requires_followup: boolean;
    followup_at: string | null;
    alert_id: number | null;
    user: StaffMember | null;
    created_at: string;
}

interface RecentShift {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    shift_lead: StaffMember | null;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
    duration_minutes: number | null;
}

interface Props {
    activeShift: ActiveShift | null;
    notes: OperatorNote[];
    recentShifts: RecentShift[];
    openAlertsCount: number;
    criticalAlertsCount: number;
    staff: StaffMember[];
    eligibleLeads: StaffMember[];
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const noteTypeColors: Record<string, string> = {
    note: 'bg-muted text-foreground border-border',
    action: 'bg-status-info-bg text-status-info border-status-info/30',
    escalation:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    decision: 'bg-primary/10 text-primary border-primary',
    handover:
        'bg-status-success-bg text-status-success border-status-success/30',
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ${diffMins % 60}m ago`;
    return `${diffDays}d ${diffHours % 24}h ago`;
}

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

// --- Component ---

export default function ControlRoomShifts({
    activeShift,
    notes,
    recentShifts,
    openAlertsCount,
    criticalAlertsCount,
    staff,
    eligibleLeads,
    can,
}: Props) {
    // New shift form state
    const [newShiftOpen, setNewShiftOpen] = useState(false);
    const [newShiftName, setNewShiftName] = useState('');
    const [newShiftLead, setNewShiftLead] = useState('');
    const [newShiftTeam, setNewShiftTeam] = useState<string[]>([]);

    // Add note form state
    const [noteOpen, setNoteOpen] = useState(false);
    const [noteType, setNoteType] = useState('note');
    const [noteContent, setNoteContent] = useState('');
    const [notePinned, setNotePinned] = useState(false);
    const [noteFollowup, setNoteFollowup] = useState(false);

    const handleStartShift = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            '/control-room/shifts',
            {
                name: newShiftName,
                shift_lead_user_id: parseInt(newShiftLead),
                team_members: newShiftTeam.map((id) => parseInt(id)),
            },
            {
                onSuccess: () => {
                    setNewShiftOpen(false);
                    setNewShiftName('');
                    setNewShiftLead('');
                    setNewShiftTeam([]);
                },
            },
        );
    };

    const handleAddNote = (e: FormEvent) => {
        e.preventDefault();
        if (!activeShift) return;
        router.post(
            `/control-room/shifts/${activeShift.id}/note`,
            {
                type: noteType,
                content: noteContent,
                is_pinned: notePinned,
                requires_followup: noteFollowup,
            },
            {
                onSuccess: () => {
                    setNoteOpen(false);
                    setNoteContent('');
                    setNoteType('note');
                    setNotePinned(false);
                    setNoteFollowup(false);
                },
            },
        );
    };

    const toggleTeamMember = (
        id: string,
        current: string[],
        setter: (val: string[]) => void,
    ) => {
        if (current.includes(id)) {
            setter(current.filter((v) => v !== id));
        } else {
            setter([...current, id]);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Active Shifts', href: '#' },
            ]}
        >
            <Head title="Active Shifts - Control Room" />
            <PageShell>
                <PageHero
                    icon={Calendar}
                    title="Active Shifts"
                    description="Shift management, operator notes, and handover workflow."
                    stats={[
                        { label: 'Active shift', value: activeShift ? 1 : 0 },
                        { label: 'Open alerts', value: openAlertsCount },
                        { label: 'Critical', value: criticalAlertsCount },
                        { label: 'Recent shifts', value: recentShifts.length },
                    ]}
                    actions={
                        can.manage && !activeShift ? (
                            <Dialog
                                open={newShiftOpen}
                                onOpenChange={setNewShiftOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Start New Shift
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-lg">
                                    <form onSubmit={handleStartShift}>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Start New Shift
                                            </DialogTitle>
                                            <DialogDescription>
                                                Begin a new shift with a
                                                designated lead and team
                                                members.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="mt-4 space-y-4">
                                            <div>
                                                <Label htmlFor="shift-name">
                                                    Shift Name
                                                </Label>
                                                <Input
                                                    id="shift-name"
                                                    value={newShiftName}
                                                    onChange={(e) =>
                                                        setNewShiftName(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Night Shift 27 Mar"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label>Shift Lead</Label>
                                                <Select
                                                    value={newShiftLead}
                                                    onValueChange={
                                                        setNewShiftLead
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select shift lead" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {eligibleLeads.map(
                                                            (s) => (
                                                                <SelectItem
                                                                    key={s.id}
                                                                    value={s.id.toString()}
                                                                >
                                                                    {s.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Team Members</Label>
                                                <div className="mt-1 flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-md border p-2">
                                                    {staff.map((s) => (
                                                        <Button
                                                            key={s.id}
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                toggleTeamMember(
                                                                    s.id.toString(),
                                                                    newShiftTeam,
                                                                    setNewShiftTeam,
                                                                )
                                                            }
                                                            className={`h-7 rounded-full px-3 text-xs ${
                                                                newShiftTeam.includes(
                                                                    s.id.toString(),
                                                                )
                                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                                    : 'hover:bg-muted'
                                                            }`}
                                                        >
                                                            {s.name}
                                                        </Button>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                        <DialogFooter className="mt-6">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setNewShiftOpen(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    !newShiftName ||
                                                    !newShiftLead
                                                }
                                            >
                                                Start Shift
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        ) : null
                    }
                />

                {/* Current Active Shift */}
                {activeShift ? (
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex items-start justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        {activeShift.name}
                                        <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                            Active
                                        </Badge>
                                        {activeShift.handover_status ===
                                            'prepared' && (
                                            <Badge
                                                variant="outline"
                                                className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                            >
                                                Handover prepared
                                            </Badge>
                                        )}
                                    </CardTitle>
                                    <div className="mt-1 flex items-center gap-4 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Clock className="h-3.5 w-3.5" />
                                            Started{' '}
                                            {formatRelativeTime(
                                                activeShift.starts_at,
                                            )}
                                        </span>
                                        {activeShift.shift_lead && (
                                            <span className="flex items-center gap-1">
                                                <User className="h-3.5 w-3.5" />
                                                Lead:{' '}
                                                {activeShift.shift_lead.name}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                {can.manage && (
                                    <div className="flex gap-2">
                                        <Dialog
                                            open={noteOpen}
                                            onOpenChange={setNoteOpen}
                                        >
                                            <DialogTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <MessageSquarePlus className="mr-2 h-4 w-4" />
                                                    Add Note
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="sm:max-w-lg">
                                                <form onSubmit={handleAddNote}>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Add Operator Note
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Record an
                                                            observation, action,
                                                            or decision for this
                                                            shift.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <div className="mt-4 space-y-4">
                                                        <div>
                                                            <Label>
                                                                Note Type
                                                            </Label>
                                                            <Select
                                                                value={noteType}
                                                                onValueChange={
                                                                    setNoteType
                                                                }
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="note">
                                                                        Note
                                                                    </SelectItem>
                                                                    <SelectItem value="action">
                                                                        Action
                                                                    </SelectItem>
                                                                    <SelectItem value="escalation">
                                                                        Escalation
                                                                    </SelectItem>
                                                                    <SelectItem value="decision">
                                                                        Decision
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div>
                                                            <Label htmlFor="note-content">
                                                                Content
                                                            </Label>
                                                            <Textarea
                                                                id="note-content"
                                                                value={
                                                                    noteContent
                                                                }
                                                                onChange={(e) =>
                                                                    setNoteContent(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                rows={4}
                                                                maxLength={2000}
                                                                required
                                                                placeholder="Enter note details..."
                                                            />
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {
                                                                    noteContent.length
                                                                }
                                                                /2000
                                                            </p>
                                                        </div>
                                                        <div className="flex gap-4">
                                                            <label className="flex items-center gap-2 text-sm">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        notePinned
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setNotePinned(
                                                                            e
                                                                                .target
                                                                                .checked,
                                                                        )
                                                                    }
                                                                    className="rounded border-border"
                                                                />
                                                                Pin note
                                                            </label>
                                                            <label className="flex items-center gap-2 text-sm">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        noteFollowup
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setNoteFollowup(
                                                                            e
                                                                                .target
                                                                                .checked,
                                                                        )
                                                                    }
                                                                    className="rounded border-border"
                                                                />
                                                                Requires
                                                                follow-up
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <DialogFooter className="mt-6">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setNoteOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                !noteContent
                                                            }
                                                        >
                                                            Add Note
                                                        </Button>
                                                    </DialogFooter>
                                                </form>
                                            </DialogContent>
                                        </Dialog>
                                        <Button size="sm" asChild>
                                            {/* Handover is a guided, stepped page — summary, notes, incoming team, confirm. */}
                                            <Link
                                                href={`/control-room/shifts/${activeShift.id}/handover`}
                                            >
                                                <ArrowRightLeft className="mr-2 h-4 w-4" />
                                                {activeShift.handover_status ===
                                                'prepared'
                                                    ? 'Review Prepared Handover'
                                                    : 'Prepare Handover'}
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            {activeShift.handover_status === 'prepared' && (
                                <div className="mb-5 flex items-center justify-between gap-6 rounded-lg border border-status-warning/30 bg-status-warning-bg/40 p-4">
                                    <div>
                                        <p className="font-semibold">
                                            Ownership has not changed yet
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Prepared for{' '}
                                            {activeShift.incoming_lead?.name ??
                                                'the incoming lead'}{' '}
                                            on{' '}
                                            {formatDateTime(
                                                activeShift.handover_prepared_at,
                                            )}
                                            . This shift remains active until
                                            they accept.
                                        </p>
                                    </div>
                                    <Button asChild variant="outline">
                                        <Link
                                            href={`/control-room/shifts/${activeShift.id}/handover`}
                                        >
                                            Open handover
                                        </Link>
                                    </Button>
                                </div>
                            )}
                            {/* Team Members */}
                            {activeShift.team_members.length > 0 && (
                                <div className="mb-4 flex flex-wrap items-center gap-2">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        Team:
                                    </span>
                                    {activeShift.team_members.map((member) => (
                                        <Badge
                                            key={member.id}
                                            variant="secondary"
                                        >
                                            {member.name}
                                        </Badge>
                                    ))}
                                </div>
                            )}

                            {/* Metrics Grid */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="rounded-lg border bg-muted/30 p-3">
                                    <div className="flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                                        <span className="text-xs text-muted-foreground">
                                            Alerts Created
                                        </span>
                                    </div>
                                    <div className="mt-1 text-2xl font-bold">
                                        {activeShift.alerts_created}
                                    </div>
                                </div>
                                <div className="rounded-lg border bg-muted/30 p-3">
                                    <div className="flex items-center gap-2">
                                        <CheckCircle className="h-4 w-4 text-status-success" />
                                        <span className="text-xs text-muted-foreground">
                                            Alerts Resolved
                                        </span>
                                    </div>
                                    <div className="mt-1 text-2xl font-bold">
                                        {activeShift.alerts_resolved}
                                    </div>
                                </div>
                                <div className="rounded-lg border bg-muted/30 p-3">
                                    <div className="flex items-center gap-2">
                                        <TrendingUp className="h-4 w-4 text-status-warning" />
                                        <span className="text-xs text-muted-foreground">
                                            Alerts Escalated
                                        </span>
                                    </div>
                                    <div className="mt-1 text-2xl font-bold">
                                        {activeShift.alerts_escalated}
                                    </div>
                                </div>
                                <div className="rounded-lg border bg-muted/30 p-3">
                                    <div className="flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-status-critical" />
                                        <span className="text-xs text-muted-foreground">
                                            Open Alerts Now
                                        </span>
                                    </div>
                                    <div className="mt-1 text-2xl font-bold">
                                        {openAlertsCount}
                                        {criticalAlertsCount > 0 && (
                                            <span className="ml-2 text-sm font-normal text-status-critical">
                                                ({criticalAlertsCount} critical)
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Clock className="mb-3 h-12 w-12 text-muted-foreground/50" />
                            <h3 className="text-lg font-medium">
                                No Active Shift
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Start a new shift to begin tracking operator
                                activity and alerts.
                            </p>
                            {can.manage && (
                                <Dialog
                                    open={newShiftOpen}
                                    onOpenChange={setNewShiftOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button className="mt-4">
                                            <Plus className="mr-2 h-4 w-4" />
                                            Start New Shift
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-lg">
                                        <form onSubmit={handleStartShift}>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Start New Shift
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Begin a new shift with a
                                                    designated lead and team
                                                    members.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div className="mt-4 space-y-4">
                                                <div>
                                                    <Label htmlFor="shift-name-empty">
                                                        Shift Name
                                                    </Label>
                                                    <Input
                                                        id="shift-name-empty"
                                                        value={newShiftName}
                                                        onChange={(e) =>
                                                            setNewShiftName(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="e.g. Night Shift 27 Mar"
                                                        required
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Shift Lead</Label>
                                                    <Select
                                                        value={newShiftLead}
                                                        onValueChange={
                                                            setNewShiftLead
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select shift lead" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {eligibleLeads.map(
                                                                (s) => (
                                                                    <SelectItem
                                                                        key={
                                                                            s.id
                                                                        }
                                                                        value={s.id.toString()}
                                                                    >
                                                                        {s.name}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div>
                                                    <Label>Team Members</Label>
                                                    <div className="mt-1 flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-md border p-2">
                                                        {staff.map((s) => (
                                                            <Button
                                                                key={s.id}
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    toggleTeamMember(
                                                                        s.id.toString(),
                                                                        newShiftTeam,
                                                                        setNewShiftTeam,
                                                                    )
                                                                }
                                                                className={`h-7 rounded-full px-3 text-xs ${
                                                                    newShiftTeam.includes(
                                                                        s.id.toString(),
                                                                    )
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'hover:bg-muted'
                                                                }`}
                                                            >
                                                                {s.name}
                                                            </Button>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                            <DialogFooter className="mt-6">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setNewShiftOpen(false)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        !newShiftName ||
                                                        !newShiftLead
                                                    }
                                                >
                                                    Start Shift
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Operator Notes Timeline */}
                {activeShift && (
                    <div className="mt-6">
                        <h2 className="mb-3 text-lg font-semibold">
                            Operator Notes
                        </h2>
                        {notes.length > 0 ? (
                            <div className="space-y-3">
                                {notes.map((note) => (
                                    <div
                                        key={note.id}
                                        className={`rounded-lg border p-3 ${
                                            note.is_pinned
                                                ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30'
                                                : 'bg-card'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                {note.is_pinned && (
                                                    <Pin className="h-3.5 w-3.5 text-status-warning" />
                                                )}
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        noteTypeColors[
                                                            note.type
                                                        ] || ''
                                                    }
                                                >
                                                    {note.type}
                                                </Badge>
                                                {note.requires_followup && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-critical/30 text-status-critical"
                                                    >
                                                        Follow-up required
                                                    </Badge>
                                                )}
                                            </div>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {formatRelativeTime(
                                                    note.created_at,
                                                )}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm whitespace-pre-wrap">
                                            {note.content}
                                        </p>
                                        <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                            {note.user && (
                                                <span className="flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    {note.user.name}
                                                </span>
                                            )}
                                            {note.alert_id && (
                                                <span>
                                                    Alert #{note.alert_id}
                                                </span>
                                            )}
                                            {note.followup_at && (
                                                <span>
                                                    Follow-up:{' '}
                                                    {formatDateTime(
                                                        note.followup_at,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <Card className="px-4 py-8 text-center text-sm text-muted-foreground">
                                No operator notes for this shift yet.
                            </Card>
                        )}
                    </div>
                )}

                {/* Shift History Table */}
                {recentShifts.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-3 text-lg font-semibold">
                            Shift History
                        </h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-2 text-left font-medium">
                                            Shift Name
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Lead
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Started
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Ended
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Duration
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Created
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Resolved
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Escalated
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recentShifts.map((shift) => (
                                        <tr
                                            key={shift.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-2 font-medium">
                                                {shift.name}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {shift.shift_lead?.name ?? '-'}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {formatDateTime(
                                                    shift.starts_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {formatDateTime(shift.ends_at)}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {formatDuration(
                                                    shift.duration_minutes,
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {shift.alerts_created}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {shift.alerts_resolved}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {shift.alerts_escalated}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
