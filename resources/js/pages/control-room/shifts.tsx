import PageHeader from '@/components/page-header';
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
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    CheckCircle,
    Clock,
    MessageSquarePlus,
    Pin,
    Plus,
    TrendingUp,
    User,
    X,
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
    handover_notes: string | null;
    priority_items: string[];
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
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const noteTypeColors: Record<string, string> = {
    note: 'bg-muted text-foreground border-border',
    action: 'bg-blue-100 text-blue-700 border-blue-200',
    escalation: 'bg-orange-100 text-orange-700 border-orange-200',
    decision: 'bg-primary/10 text-primary border-primary',
    handover: 'bg-green-100 text-green-700 border-green-200',
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
    can,
}: Props) {
    // New shift form state
    const [newShiftOpen, setNewShiftOpen] = useState(false);
    const [newShiftName, setNewShiftName] = useState('');
    const [newShiftLead, setNewShiftLead] = useState('');
    const [newShiftTeam, setNewShiftTeam] = useState<string[]>([]);

    // Handover dialog state
    const [handoverOpen, setHandoverOpen] = useState(false);
    const [handoverNotes, setHandoverNotes] = useState('');
    const [priorityItems, setPriorityItems] = useState<string[]>(['']);
    const [incomingLead, setIncomingLead] = useState('');
    const [incomingTeam, setIncomingTeam] = useState<string[]>([]);

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

    const handleHandover = (e: FormEvent) => {
        e.preventDefault();
        if (!activeShift) return;
        router.post(
            `/control-room/shifts/${activeShift.id}/handover`,
            {
                handover_notes: handoverNotes,
                priority_items: priorityItems.filter((item) => item.trim() !== ''),
                incoming_lead_user_id: parseInt(incomingLead),
                incoming_team_members: incomingTeam.map((id) => parseInt(id)),
            },
            {
                onSuccess: () => {
                    setHandoverOpen(false);
                    setHandoverNotes('');
                    setPriorityItems(['']);
                    setIncomingLead('');
                    setIncomingTeam([]);
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

    const addPriorityItem = () => setPriorityItems([...priorityItems, '']);
    const removePriorityItem = (index: number) =>
        setPriorityItems(priorityItems.filter((_, i) => i !== index));
    const updatePriorityItem = (index: number, value: string) => {
        const updated = [...priorityItems];
        updated[index] = value;
        setPriorityItems(updated);
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
                <PageHeader
                    title="Active Shifts"
                    description="Shift management, operator notes, and handover workflow."
                    actions={
                        can.manage && !activeShift ? (
                            <Dialog open={newShiftOpen} onOpenChange={setNewShiftOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Start New Shift
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-lg">
                                    <form onSubmit={handleStartShift}>
                                        <DialogHeader>
                                            <DialogTitle>Start New Shift</DialogTitle>
                                            <DialogDescription>
                                                Begin a new shift with a designated
                                                lead and team members.
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
                                                    onValueChange={setNewShiftLead}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select shift lead" />
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
                                            </div>
                                            <div>
                                                <Label>Team Members</Label>
                                                <div className="mt-1 flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-md border p-2">
                                                    {staff.map((s) => (
                                                        <button
                                                            key={s.id}
                                                            type="button"
                                                            onClick={() =>
                                                                toggleTeamMember(
                                                                    s.id.toString(),
                                                                    newShiftTeam,
                                                                    setNewShiftTeam,
                                                                )
                                                            }
                                                            className={`rounded-full border px-3 py-1 text-xs transition-colors ${
                                                                newShiftTeam.includes(
                                                                    s.id.toString(),
                                                                )
                                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                                    : 'hover:bg-muted'
                                                            }`}
                                                        >
                                                            {s.name}
                                                        </button>
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
                                                    !newShiftName || !newShiftLead
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
                                        <Badge className="bg-green-100 text-green-700 border-green-200">
                                            Active
                                        </Badge>
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
                                                Lead: {activeShift.shift_lead.name}
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
                                                <Button variant="outline" size="sm">
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
                                                            Record an observation,
                                                            action, or decision for
                                                            this shift.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <div className="mt-4 space-y-4">
                                                        <div>
                                                            <Label>Note Type</Label>
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
                                                                value={noteContent}
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
                                                                {noteContent.length}
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
                                                                    onChange={(e) =>
                                                                        setNotePinned(
                                                                            e.target
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
                                                                    onChange={(e) =>
                                                                        setNoteFollowup(
                                                                            e.target
                                                                                .checked,
                                                                        )
                                                                    }
                                                                    className="rounded border-border"
                                                                />
                                                                Requires follow-up
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <DialogFooter className="mt-6">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setNoteOpen(false)
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={!noteContent}
                                                        >
                                                            Add Note
                                                        </Button>
                                                    </DialogFooter>
                                                </form>
                                            </DialogContent>
                                        </Dialog>
                                        <Dialog
                                            open={handoverOpen}
                                            onOpenChange={setHandoverOpen}
                                        >
                                            <DialogTrigger asChild>
                                                <Button size="sm">
                                                    <ArrowRightLeft className="mr-2 h-4 w-4" />
                                                    Begin Handover
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="sm:max-w-lg">
                                                <form onSubmit={handleHandover}>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Shift Handover
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Complete the current shift
                                                            and hand over to the
                                                            incoming team.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <div className="mt-4 max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                                                        <div>
                                                            <Label htmlFor="handover-notes">
                                                                Handover Notes
                                                            </Label>
                                                            <Textarea
                                                                id="handover-notes"
                                                                value={handoverNotes}
                                                                onChange={(e) =>
                                                                    setHandoverNotes(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                rows={4}
                                                                required
                                                                placeholder="Summary of shift activity, outstanding issues..."
                                                            />
                                                        </div>
                                                        <div>
                                                            <Label>
                                                                Priority Items
                                                            </Label>
                                                            <div className="mt-1 space-y-2">
                                                                {priorityItems.map(
                                                                    (item, i) => (
                                                                        <div
                                                                            key={i}
                                                                            className="flex gap-2"
                                                                        >
                                                                            <Input
                                                                                value={
                                                                                    item
                                                                                }
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    updatePriorityItem(
                                                                                        i,
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                placeholder={`Priority item ${i + 1}`}
                                                                            />
                                                                            {priorityItems.length >
                                                                                1 && (
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="ghost"
                                                                                    size="icon"
                                                                                    onClick={() =>
                                                                                        removePriorityItem(
                                                                                            i,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    <X className="h-4 w-4" />
                                                                                </Button>
                                                                            )}
                                                                        </div>
                                                                    ),
                                                                )}
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={
                                                                        addPriorityItem
                                                                    }
                                                                >
                                                                    <Plus className="mr-1 h-3 w-3" />
                                                                    Add Item
                                                                </Button>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <Label>
                                                                Incoming Shift Lead
                                                            </Label>
                                                            <Select
                                                                value={incomingLead}
                                                                onValueChange={
                                                                    setIncomingLead
                                                                }
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue placeholder="Select incoming lead" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {staff.map(
                                                                        (s) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    s.id
                                                                                }
                                                                                value={s.id.toString()}
                                                                            >
                                                                                {
                                                                                    s.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div>
                                                            <Label>
                                                                Incoming Team Members
                                                            </Label>
                                                            <div className="mt-1 flex max-h-32 flex-wrap gap-2 overflow-y-auto rounded-md border p-2">
                                                                {staff.map((s) => (
                                                                    <button
                                                                        key={s.id}
                                                                        type="button"
                                                                        onClick={() =>
                                                                            toggleTeamMember(
                                                                                s.id.toString(),
                                                                                incomingTeam,
                                                                                setIncomingTeam,
                                                                            )
                                                                        }
                                                                        className={`rounded-full border px-3 py-1 text-xs transition-colors ${
                                                                            incomingTeam.includes(
                                                                                s.id.toString(),
                                                                            )
                                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                                : 'hover:bg-muted'
                                                                        }`}
                                                                    >
                                                                        {s.name}
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <DialogFooter className="mt-6">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setHandoverOpen(false)
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                !handoverNotes ||
                                                                !incomingLead
                                                            }
                                                        >
                                                            Complete Handover
                                                        </Button>
                                                    </DialogFooter>
                                                </form>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
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
                                        <AlertTriangle className="h-4 w-4 text-yellow-500" />
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
                                        <CheckCircle className="h-4 w-4 text-green-500" />
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
                                        <TrendingUp className="h-4 w-4 text-orange-500" />
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
                                        <AlertTriangle className="h-4 w-4 text-red-500" />
                                        <span className="text-xs text-muted-foreground">
                                            Open Alerts Now
                                        </span>
                                    </div>
                                    <div className="mt-1 text-2xl font-bold">
                                        {openAlertsCount}
                                        {criticalAlertsCount > 0 && (
                                            <span className="ml-2 text-sm font-normal text-red-600">
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
                                                </div>
                                                <div>
                                                    <Label>Team Members</Label>
                                                    <div className="mt-1 flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-md border p-2">
                                                        {staff.map((s) => (
                                                            <button
                                                                key={s.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    toggleTeamMember(
                                                                        s.id.toString(),
                                                                        newShiftTeam,
                                                                        setNewShiftTeam,
                                                                    )
                                                                }
                                                                className={`rounded-full border px-3 py-1 text-xs transition-colors ${
                                                                    newShiftTeam.includes(
                                                                        s.id.toString(),
                                                                    )
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'hover:bg-muted'
                                                                }`}
                                                            >
                                                                {s.name}
                                                            </button>
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
                                                ? 'border-yellow-300 bg-yellow-50/50 dark:border-yellow-800 dark:bg-yellow-950/20'
                                                : 'bg-card'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                {note.is_pinned && (
                                                    <Pin className="h-3.5 w-3.5 text-yellow-600" />
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
                                                        className="border-red-200 text-red-600"
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
                            <div className="rounded-lg border bg-card px-4 py-8 text-center text-sm text-muted-foreground">
                                No operator notes for this shift yet.
                            </div>
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
