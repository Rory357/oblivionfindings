import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
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
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Calendar,
    Clock,
    Copy,
    Eye,
    MessageSquarePlus,
    Pin,
    Plus,
    User,
} from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

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
    actions: {
        can_open_handover: boolean;
        can_add_note: boolean;
        can_copy_summary: boolean;
    };
}

interface OperatorNote {
    id: number;
    type: string;
    content: string;
    is_pinned: boolean;
    requires_followup: boolean;
    followup_at: string | null;
    alert_id: number | null;
    alert_reference: string | null;
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
    actions: {
        can_copy_summary: boolean;
    };
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

function formatDuration(minutes: number | null): string {
    if (minutes === null || minutes === undefined) return '-';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m}m`;
    return `${h}h ${m}m`;
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

    const activeShiftActions = (): ControlRoomRowAction[] => {
        if (!activeShift) return [];
        const actions: ControlRoomRowAction[] = [];

        if (activeShift.actions.can_open_handover) {
            actions.push({
                key: 'handover',
                label:
                    activeShift.handover_status === 'prepared'
                        ? 'Review prepared handover'
                        : 'Prepare handover',
                icon: Eye,
                onSelect: () =>
                    router.visit(
                        `/control-room/shifts/${activeShift.id}/handover`,
                    ),
            });
        }
        if (activeShift.actions.can_add_note) {
            actions.push({
                key: 'add-note',
                label: 'Add operator note',
                icon: MessageSquarePlus,
                onSelect: () => setNoteOpen(true),
            });
        }
        if (activeShift.actions.can_copy_summary) {
            actions.push({
                key: 'copy-summary',
                label: 'Copy shift summary',
                icon: Copy,
                onSelect: () =>
                    void navigator.clipboard?.writeText(
                        `${activeShift.name}: ${activeShift.alerts_created} created, ${activeShift.alerts_resolved} resolved, ${activeShift.alerts_escalated} escalated`,
                    ),
            });
        }

        return actions;
    };

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: [
                    'activeShift',
                    'notes',
                    'recentShifts',
                    'openAlertsCount',
                    'criticalAlertsCount',
                ],
                preserveScroll: true,
            });
        }, 30_000);

        return () => window.clearInterval(interval);
    }, []);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Active Shifts', href: '#' },
            ]}
        >
            <Head title="Active Shifts - Control Room" />
            <PageShell>
                <CommandCentrePage
                    current="/control-room/shifts"
                    icon={Calendar}
                    title="Shifts"
                    description="Keep the live desk staffed and transfer ownership through a prepared, accepted handover."
                    status={
                        activeShift ? 'Active shift running' : 'No active shift'
                    }
                    freshness={
                        activeShift?.handover_status === 'prepared'
                            ? 'Incoming acceptance required'
                            : undefined
                    }
                    metricGroups={[
                        {
                            title: 'Shift operations',
                            icon: Calendar,
                            metrics: [
                                {
                                    label: 'Open now',
                                    value: String(openAlertsCount),
                                    caption: 'active alerts',
                                    tone:
                                        openAlertsCount > 0
                                            ? 'warning'
                                            : 'success',
                                },
                                {
                                    label: 'Critical',
                                    value: String(criticalAlertsCount),
                                    caption: 'act first',
                                    tone:
                                        criticalAlertsCount > 0
                                            ? 'critical'
                                            : 'success',
                                },
                                {
                                    label: 'Resolved',
                                    value: String(
                                        activeShift?.alerts_resolved ?? 0,
                                    ),
                                    caption: activeShift
                                        ? 'this shift'
                                        : 'no active shift',
                                    tone: 'success',
                                },
                                {
                                    label: 'Escalated',
                                    value: String(
                                        activeShift?.alerts_escalated ?? 0,
                                    ),
                                    caption: activeShift
                                        ? 'this shift'
                                        : 'no active shift',
                                    tone:
                                        (activeShift?.alerts_escalated ?? 0) > 0
                                            ? 'warning'
                                            : 'neutral',
                                },
                            ],
                        },
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
                >
                    {/* Current Active Shift */}
                    {activeShift ? (
                        <ControlRoomRowActions
                            label={`Actions for ${activeShift.name}`}
                            items={activeShiftActions()}
                        >
                            {({ rowProps, overflowButton }) => (
                                <Card {...rowProps}>
                                    <CardHeader className="pb-3">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <CardTitle className="flex min-w-0 flex-wrap items-center gap-2 break-words">
                                                    <span className="min-w-0 break-words">
                                                        {activeShift.name}
                                                    </span>
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
                                                <div className="mt-1 flex flex-col gap-1 text-sm text-muted-foreground sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4">
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3.5 w-3.5" />
                                                        Started{' '}
                                                        {formatRelative(
                                                            activeShift.starts_at,
                                                        )}
                                                    </span>
                                                    {activeShift.shift_lead && (
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3.5 w-3.5" />
                                                            Lead:{' '}
                                                            {
                                                                activeShift
                                                                    .shift_lead
                                                                    .name
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex w-full flex-wrap gap-2 sm:w-auto sm:justify-end">
                                                {can.manage && (
                                                    <>
                                                        <Dialog
                                                            open={noteOpen}
                                                            onOpenChange={
                                                                setNoteOpen
                                                            }
                                                        >
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                >
                                                                    <MessageSquarePlus className="mr-2 h-4 w-4" />
                                                                    Add Note
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent className="sm:max-w-lg">
                                                                <form
                                                                    onSubmit={
                                                                        handleAddNote
                                                                    }
                                                                >
                                                                    <DialogHeader>
                                                                        <DialogTitle>
                                                                            Add
                                                                            Operator
                                                                            Note
                                                                        </DialogTitle>
                                                                        <DialogDescription>
                                                                            Record
                                                                            an
                                                                            observation,
                                                                            action,
                                                                            or
                                                                            decision
                                                                            for
                                                                            this
                                                                            shift.
                                                                        </DialogDescription>
                                                                    </DialogHeader>
                                                                    <div className="mt-4 space-y-4">
                                                                        <div>
                                                                            <Label>
                                                                                Note
                                                                                Type
                                                                            </Label>
                                                                            <Select
                                                                                value={
                                                                                    noteType
                                                                                }
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
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    setNoteContent(
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                rows={
                                                                                    4
                                                                                }
                                                                                maxLength={
                                                                                    2000
                                                                                }
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
                                                                                Pin
                                                                                note
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
                                                                            Add
                                                                            Note
                                                                        </Button>
                                                                    </DialogFooter>
                                                                </form>
                                                            </DialogContent>
                                                        </Dialog>
                                                        <Button
                                                            size="sm"
                                                            asChild
                                                        >
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
                                                    </>
                                                )}
                                                {overflowButton}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        {activeShift.handover_status ===
                                            'prepared' && (
                                            <div className="mb-5 flex items-center justify-between gap-6 rounded-lg border border-status-warning/30 bg-status-warning-bg/40 p-4">
                                                <div>
                                                    <p className="font-semibold">
                                                        Ownership has not
                                                        changed yet
                                                    </p>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        Prepared for{' '}
                                                        {activeShift
                                                            .incoming_lead
                                                            ?.name ??
                                                            'the incoming lead'}{' '}
                                                        on{' '}
                                                        {formatDateTime(
                                                            activeShift.handover_prepared_at,
                                                        )}
                                                        . This shift remains
                                                        active until they
                                                        accept.
                                                    </p>
                                                </div>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={`/control-room/shifts/${activeShift.id}/handover`}
                                                    >
                                                        Open handover
                                                    </Link>
                                                </Button>
                                            </div>
                                        )}
                                        {/* Team Members */}
                                        {activeShift.team_members.length >
                                            0 && (
                                            <div className="mb-4 flex flex-wrap items-center gap-2">
                                                <span className="text-xs font-medium text-muted-foreground">
                                                    Team:
                                                </span>
                                                {activeShift.team_members.map(
                                                    (member) => (
                                                        <Badge
                                                            key={member.id}
                                                            variant="secondary"
                                                        >
                                                            {member.name}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        )}

                                        <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                                            <span>
                                                {activeShift.alerts_created}{' '}
                                                alerts created
                                            </span>
                                            <span aria-hidden>·</span>
                                            <span>
                                                {activeShift.alerts_resolved}{' '}
                                                resolved
                                            </span>
                                            <span aria-hidden>·</span>
                                            <span>
                                                {activeShift.alerts_escalated}{' '}
                                                escalated
                                            </span>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </ControlRoomRowActions>
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
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="e.g. Night Shift 27 Mar"
                                                            required
                                                        />
                                                    </div>
                                                    <div>
                                                        <Label>
                                                            Shift Lead
                                                        </Label>
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
                                                            Team Members
                                                        </Label>
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
                                                            setNewShiftOpen(
                                                                false,
                                                            )
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
                                                    {formatRelative(
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
                                                        {note.alert_reference ??
                                                            `Alert ${note.alert_id}`}
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

                    {/* Shift history worklist */}
                    {recentShifts.length > 0 && (
                        <section
                            className="mt-6"
                            aria-labelledby="shift-history-heading"
                        >
                            <h2 className="mb-3 text-lg font-semibold">
                                <span id="shift-history-heading">
                                    Shift History
                                </span>
                            </h2>
                            {/* eslint-disable-next-line no-restricted-syntax -- This is a semantic worklist group; each child is its own interactive shift card. */}
                            <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                {recentShifts.map((shift) => {
                                    const actions: ControlRoomRowAction[] =
                                        shift.actions.can_copy_summary
                                            ? [
                                                  {
                                                      key: 'copy-summary',
                                                      label: 'Copy shift summary',
                                                      icon: Copy,
                                                      onSelect: () =>
                                                          void navigator.clipboard?.writeText(
                                                              `${shift.name}: ${shift.alerts_created} created, ${shift.alerts_resolved} resolved, ${shift.alerts_escalated} escalated`,
                                                          ),
                                                  },
                                              ]
                                            : [];

                                    return (
                                        <ControlRoomRowActions
                                            key={shift.id}
                                            label={`Actions for ${shift.name}`}
                                            items={actions}
                                        >
                                            {({ rowProps, overflowButton }) => (
                                                <article
                                                    {...rowProps}
                                                    className="grid gap-3 p-4 hover:bg-muted/30 sm:grid-cols-[minmax(12rem,1.3fr)_minmax(12rem,1fr)_minmax(16rem,1.2fr)_auto] sm:items-center"
                                                >
                                                    <div>
                                                        <p className="font-semibold">
                                                            {shift.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            Lead{' '}
                                                            {shift.shift_lead
                                                                ?.name ??
                                                                'not recorded'}
                                                        </p>
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        <p>
                                                            {formatDateTime(
                                                                shift.starts_at,
                                                            )}
                                                        </p>
                                                        <p>
                                                            {formatDuration(
                                                                shift.duration_minutes,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                                                        <span>
                                                            {
                                                                shift.alerts_created
                                                            }{' '}
                                                            created
                                                        </span>
                                                        <span>
                                                            {
                                                                shift.alerts_resolved
                                                            }{' '}
                                                            resolved
                                                        </span>
                                                        <span>
                                                            {
                                                                shift.alerts_escalated
                                                            }{' '}
                                                            escalated
                                                        </span>
                                                    </div>
                                                    {overflowButton}
                                                </article>
                                            )}
                                        </ControlRoomRowActions>
                                    );
                                })}
                            </div>
                        </section>
                    )}
                </CommandCentrePage>
            </PageShell>
        </AppLayout>
    );
}
