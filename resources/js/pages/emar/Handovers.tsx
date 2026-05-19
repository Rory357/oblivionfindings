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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRightLeft,
    CheckCircle2,
    ClipboardList,
    Clock3,
    FilePenLine,
    Pill,
    Plus,
    Trash2,
    UserRoundCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type ShiftOption = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    location: string | null;
    service_context_name: string | null;
    client_name: string | null;
    staff_name: string | null;
};

type HandoverShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    shift_type: string | null;
    service_context_name: string | null;
};

type NamedPerson = {
    id: number;
    name: string;
};

type HandoverClient = {
    id: number;
    name: string;
};

type Handover = {
    id: number;
    status: string;
    handover_notes: string;
    client_mood: string | null;
    created_at: string | null;
    submitted_at: string | null;
    acknowledged_at: string | null;
    client: HandoverClient | null;
    outgoing_staff: NamedPerson | null;
    incoming_staff: NamedPerson | null;
    acknowledger: NamedPerson | null;
    outgoing_shift: HandoverShift | null;
    incoming_shift: HandoverShift | null;
    medications_due: string[];
    follow_up_items: string[];
    incidents_to_note: string[];
    tasks_pending: string[];
    can_submit: boolean;
    can_acknowledge: boolean;
    can_edit: boolean;
    can_delete: boolean;
};

type Props = {
    handovers: {
        data: Handover[];
        links: PaginationLink[];
        meta?: {
            total?: number;
            last_page?: number;
        };
    };
    shifts: ShiftOption[];
};

type FormState = {
    shift_id: string;
    incoming_shift_id: string;
    handover_notes: string;
    client_mood: string;
    medications_due_text: string;
    follow_up_items_text: string;
    incidents_to_note_text: string;
    tasks_pending_text: string;
    submit: boolean;
};

function toMultiline(items: string[]): string {
    return items.join('\n');
}

function buildFormState(handover: Handover | null): FormState {
    return {
        shift_id: handover?.outgoing_shift?.id
            ? String(handover.outgoing_shift.id)
            : '',
        incoming_shift_id: handover?.incoming_shift?.id
            ? String(handover.incoming_shift.id)
            : '',
        handover_notes: handover?.handover_notes ?? '',
        client_mood: handover?.client_mood ?? '',
        medications_due_text: toMultiline(handover?.medications_due ?? []),
        follow_up_items_text: toMultiline(handover?.follow_up_items ?? []),
        incidents_to_note_text: toMultiline(handover?.incidents_to_note ?? []),
        tasks_pending_text: toMultiline(handover?.tasks_pending ?? []),
        submit: true,
    };
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    return new Date(value).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatShiftTime(start: string | null, end: string | null): string {
    if (!start) {
        return 'Time not set';
    }

    const startLabel = new Date(start).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    if (!end) {
        return startLabel;
    }

    const endLabel = new Date(end).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return `${startLabel} to ${endLabel}`;
}

function statusBadge(status: string) {
    const label = status.charAt(0).toUpperCase() + status.slice(1);

    switch (status) {
        case 'acknowledged':
            return (
                <Badge className="bg-status-success-bg text-status-success">
                    {label}
                </Badge>
            );
        case 'submitted':
            return (
                <Badge className="bg-status-info-bg text-status-info">
                    {label}
                </Badge>
            );
        case 'draft':
            return <Badge variant="secondary">{label}</Badge>;
        default:
            return <Badge variant="outline">{label}</Badge>;
    }
}

function shiftLabel(shift: ShiftOption | HandoverShift | null): string {
    if (!shift) {
        return 'Shift not set';
    }

    const details = [
        shift.shift_type ? shift.shift_type.replace(/_/g, ' ') : null,
        shift.location ?? null,
        shift.service_context_name ?? null,
    ].filter(Boolean);

    return `${formatShiftTime(shift.starts_at, shift.ends_at)}${details.length ? ` • ${details.join(' • ')}` : ''}`;
}

function ListSection({
    title,
    items,
    emptyLabel,
}: {
    title: string;
    items: string[];
    emptyLabel: string;
}) {
    return (
        <div className="space-y-2">
            <div className="text-sm font-medium">{title}</div>
            {items.length === 0 ? (
                <div className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">
                    {emptyLabel}
                </div>
            ) : (
                <div className="space-y-2">
                    {items.map((item, index) => (
                        <div
                            key={`${title}-${index}`}
                            className="rounded-md border bg-muted/20 px-3 py-2 text-sm"
                        >
                            {item}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function HandoverDialog({
    open,
    onOpenChange,
    editing,
    shifts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editing: Handover | null;
    shifts: ShiftOption[];
}) {
    const form = useForm<FormState>(buildFormState(editing));

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData(buildFormState(editing));
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form helper is stable; hydrate only when the edited handover dialog opens.
    }, [editing, open]);

    const selectedShift = useMemo(
        () =>
            shifts.find((shift) => shift.id === Number(form.data.shift_id)) ??
            null,
        [form.data.shift_id, shifts],
    );

    const incomingShiftOptions = useMemo(() => {
        if (!selectedShift) {
            return shifts;
        }

        const matchingClient = shifts.filter(
            (shift) =>
                shift.id !== selectedShift.id &&
                shift.client_name &&
                selectedShift.client_name &&
                shift.client_name === selectedShift.client_name &&
                (shift.starts_at ?? '') >= (selectedShift.starts_at ?? ''),
        );

        if (matchingClient.length > 0) {
            return matchingClient;
        }

        return shifts.filter((shift) => shift.id !== selectedShift.id);
    }, [selectedShift, shifts]);

    function closeDialog() {
        onOpenChange(false);
        form.reset();
        form.clearErrors();
    }

    function submit(submitNow: boolean) {
        const payload = {
            shift_id: form.data.shift_id ? Number(form.data.shift_id) : null,
            incoming_shift_id: form.data.incoming_shift_id
                ? Number(form.data.incoming_shift_id)
                : null,
            handover_notes: form.data.handover_notes,
            client_mood: form.data.client_mood || null,
            medications_due_text: form.data.medications_due_text || null,
            follow_up_items_text: form.data.follow_up_items_text || null,
            incidents_to_note_text: form.data.incidents_to_note_text || null,
            tasks_pending_text: form.data.tasks_pending_text || null,
            submit: submitNow,
        };

        if (editing) {
            router.put(`/emar/handovers/${editing.id}`, payload, {
                preserveScroll: true,
                onSuccess: closeDialog,
            });

            return;
        }

        router.post('/emar/handovers', payload, {
            preserveScroll: true,
            onSuccess: closeDialog,
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ArrowRightLeft className="h-5 w-5" />
                        {editing
                            ? 'Edit Medication Handover'
                            : 'New Medication Handover'}
                    </DialogTitle>
                    <DialogDescription>
                        Capture medication-critical notes on the shared shift
                        handover record so operations, eMAR, and alerts stay
                        aligned.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="handover_shift_id">
                            Outgoing shift
                        </Label>
                        {editing ? (
                            <div className="rounded-md border bg-muted/20 px-3 py-2 text-sm">
                                {selectedShift
                                    ? `${selectedShift.client_name || 'Unassigned client'} • ${shiftLabel(selectedShift)}`
                                    : 'Shift not found'}
                            </div>
                        ) : (
                            <>
                                <Select
                                    value={form.data.shift_id}
                                    onValueChange={(value) =>
                                        form.setData((current) => ({
                                            ...current,
                                            shift_id: value,
                                            incoming_shift_id:
                                                current.incoming_shift_id ===
                                                value
                                                    ? ''
                                                    : current.incoming_shift_id,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="handover_shift_id">
                                        <SelectValue placeholder="Select outgoing shift" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {shifts.map((shift) => (
                                            <SelectItem
                                                key={shift.id}
                                                value={String(shift.id)}
                                            >
                                                {shift.client_name ||
                                                    'Unassigned client'}{' '}
                                                •{' '}
                                                {shift.staff_name ||
                                                    'Unassigned staff'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.shift_id ? (
                                    <div className="text-xs text-status-critical">
                                        {form.errors.shift_id}
                                    </div>
                                ) : null}
                            </>
                        )}
                        {selectedShift ? (
                            <div className="text-xs text-muted-foreground">
                                {shiftLabel(selectedShift)}
                            </div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="handover_incoming_shift_id">
                            Incoming shift
                        </Label>
                        <Select
                            value={form.data.incoming_shift_id || 'auto'}
                            onValueChange={(value) =>
                                form.setData(
                                    'incoming_shift_id',
                                    value === 'auto' ? '' : value,
                                )
                            }
                        >
                            <SelectTrigger id="handover_incoming_shift_id">
                                <SelectValue placeholder="Select incoming shift or leave automatic" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="auto">
                                    Let the shared handover workflow infer it
                                </SelectItem>
                                {incomingShiftOptions.map((shift) => (
                                    <SelectItem
                                        key={shift.id}
                                        value={String(shift.id)}
                                    >
                                        {shift.client_name ||
                                            'Unassigned client'}{' '}
                                        •{' '}
                                        {shift.staff_name || 'Unassigned staff'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.incoming_shift_id ? (
                            <div className="text-xs text-status-critical">
                                {form.errors.incoming_shift_id}
                            </div>
                        ) : null}
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="handover_client_mood">
                                Client presentation / mood
                            </Label>
                            <Input
                                id="handover_client_mood"
                                value={form.data.client_mood}
                                onChange={(event) =>
                                    form.setData(
                                        'client_mood',
                                        event.target.value,
                                    )
                                }
                                placeholder="Settled, anxious, sleepy, escalating..."
                            />
                            {form.errors.client_mood ? (
                                <div className="text-xs text-status-critical">
                                    {form.errors.client_mood}
                                </div>
                            ) : null}
                        </div>

                        <div className="rounded-md border bg-muted/20 p-3 text-sm text-muted-foreground">
                            Save a draft if you need to come back to it. Submit
                            once the medication handover is ready for the
                            incoming shift to review.
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="handover_notes">
                            Medication handover notes
                        </Label>
                        <Textarea
                            id="handover_notes"
                            value={form.data.handover_notes}
                            onChange={(event) =>
                                form.setData(
                                    'handover_notes',
                                    event.target.value,
                                )
                            }
                            placeholder="Summarise changes, issues, risks, and anything the next shift must know."
                            className="min-h-[130px]"
                        />
                        {form.errors.handover_notes ? (
                            <div className="text-xs text-status-critical">
                                {form.errors.handover_notes}
                            </div>
                        ) : null}
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="medications_due_text">
                                Medications still due
                            </Label>
                            <Textarea
                                id="medications_due_text"
                                value={form.data.medications_due_text}
                                onChange={(event) =>
                                    form.setData(
                                        'medications_due_text',
                                        event.target.value,
                                    )
                                }
                                placeholder="One item per line"
                                className="min-h-[110px]"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="follow_up_items_text">
                                Follow-up items
                            </Label>
                            <Textarea
                                id="follow_up_items_text"
                                value={form.data.follow_up_items_text}
                                onChange={(event) =>
                                    form.setData(
                                        'follow_up_items_text',
                                        event.target.value,
                                    )
                                }
                                placeholder="One item per line"
                                className="min-h-[110px]"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="incidents_to_note_text">
                                Incidents and exceptions to note
                            </Label>
                            <Textarea
                                id="incidents_to_note_text"
                                value={form.data.incidents_to_note_text}
                                onChange={(event) =>
                                    form.setData(
                                        'incidents_to_note_text',
                                        event.target.value,
                                    )
                                }
                                placeholder="One item per line"
                                className="min-h-[110px]"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="tasks_pending_text">
                                Medication tasks still pending
                            </Label>
                            <Textarea
                                id="tasks_pending_text"
                                value={form.data.tasks_pending_text}
                                onChange={(event) =>
                                    form.setData(
                                        'tasks_pending_text',
                                        event.target.value,
                                    )
                                }
                                placeholder="One item per line"
                                className="min-h-[110px]"
                            />
                        </div>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={closeDialog}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => submit(false)}
                        disabled={form.processing}
                    >
                        {form.processing ? 'Saving...' : 'Save Draft'}
                    </Button>
                    <Button
                        type="button"
                        onClick={() => submit(true)}
                        disabled={form.processing}
                    >
                        {form.processing ? 'Submitting...' : 'Submit Handover'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Handovers({ handovers, shifts }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Handover | null>(null);

    const totals = useMemo(
        () =>
            handovers.data.reduce(
                (summary, handover) => {
                    summary.total += 1;

                    if (handover.status === 'draft') {
                        summary.draft += 1;
                    } else if (handover.status === 'submitted') {
                        summary.submitted += 1;
                    } else if (handover.status === 'acknowledged') {
                        summary.acknowledged += 1;
                    }

                    return summary;
                },
                {
                    total: 0,
                    draft: 0,
                    submitted: 0,
                    acknowledged: 0,
                },
            ),
        [handovers.data],
    );

    function openCreateDialog() {
        setEditing(null);
        setDialogOpen(true);
    }

    function openEditDialog(handover: Handover) {
        setEditing(handover);
        setDialogOpen(true);
    }

    function submitHandover(handoverId: number) {
        router.post(
            `/emar/handovers/${handoverId}/submit`,
            {},
            { preserveScroll: true },
        );
    }

    function acknowledgeHandover(handoverId: number) {
        router.post(
            `/emar/handovers/${handoverId}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    }

    function deleteHandover(handoverId: number) {
        if (!window.confirm('Delete this medication handover draft?')) {
            return;
        }

        router.delete(`/emar/handovers/${handoverId}`, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Handovers" />
            <PageHero
                icon={ArrowLeftRight}
                title="Medication Handovers"
                description="Medication-focused handover notes now run on the shared shift handover workflow so incoming teams, operations, and eMAR stay in sync."
                stats={[
                    { label: 'Total', value: totals.total },
                    { label: 'Drafts', value: totals.draft },
                    { label: 'Submitted', value: totals.submitted },
                    { label: 'Acknowledged', value: totals.acknowledged },
                ]}
                actions={
                    <Button
                        onClick={openCreateDialog}
                        disabled={shifts.length === 0}
                    >
                        <Plus className="mr-2 h-4 w-4" />
                        New Handover
                    </Button>
                }
            />
            <PageShell>
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <ArrowRightLeft className="h-8 w-8 text-status-info" />
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    This Page
                                </div>
                                <div className="text-2xl font-semibold">
                                    {totals.total}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <FilePenLine className="h-8 w-8 text-status-warning" />
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Drafts
                                </div>
                                <div className="text-2xl font-semibold">
                                    {totals.draft}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <Clock3 className="h-8 w-8 text-status-info" />
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Submitted
                                </div>
                                <div className="text-2xl font-semibold">
                                    {totals.submitted}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <CheckCircle2 className="h-8 w-8 text-status-success" />
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Acknowledged
                                </div>
                                <div className="text-2xl font-semibold">
                                    {totals.acknowledged}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {shifts.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <ArrowRightLeft className="mx-auto mb-3 h-10 w-10 text-muted-foreground/30" />
                            <div className="text-lg font-medium">
                                No recent shifts available
                            </div>
                            <div className="mt-2 text-sm text-muted-foreground">
                                As soon as shifts are available in the shared
                                operations workflow, medication handovers can be
                                created from here.
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <div className="space-y-4">
                    {handovers.data.length === 0 ? (
                        <Card>
                            <CardContent className="py-14 text-center">
                                <Pill className="mx-auto mb-3 h-10 w-10 text-muted-foreground/30" />
                                <div className="text-lg font-medium">
                                    No medication handovers yet
                                </div>
                                <div className="mt-2 text-sm text-muted-foreground">
                                    Create the first medication handover to
                                    capture due medicines, exceptions, and
                                    follow-up tasks for the next shift.
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        handovers.data.map((handover) => (
                            <Card key={handover.id}>
                                <CardHeader className="gap-4">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <CardTitle className="text-lg">
                                                    {handover.client?.name ??
                                                        'Medication handover'}
                                                </CardTitle>
                                                {statusBadge(handover.status)}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                Outgoing shift:{' '}
                                                {shiftLabel(
                                                    handover.outgoing_shift,
                                                )}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                Incoming shift:{' '}
                                                {handover.incoming_shift
                                                    ? shiftLabel(
                                                          handover.incoming_shift,
                                                      )
                                                    : 'Will be inferred by the shared shift workflow'}
                                            </div>
                                        </div>

                                        <div className="flex flex-wrap gap-2">
                                            {handover.can_edit ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEditDialog(handover)
                                                    }
                                                >
                                                    <FilePenLine className="mr-1.5 h-4 w-4" />
                                                    Edit Draft
                                                </Button>
                                            ) : null}
                                            {handover.can_submit ? (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        submitHandover(
                                                            handover.id,
                                                        )
                                                    }
                                                >
                                                    Submit
                                                </Button>
                                            ) : null}
                                            {handover.can_acknowledge ? (
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() =>
                                                        acknowledgeHandover(
                                                            handover.id,
                                                        )
                                                    }
                                                >
                                                    <UserRoundCheck className="mr-1.5 h-4 w-4" />
                                                    Acknowledge
                                                </Button>
                                            ) : null}
                                            {handover.can_delete ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        deleteHandover(
                                                            handover.id,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="mr-1.5 h-4 w-4" />
                                                    Delete
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                </CardHeader>

                                <CardContent className="space-y-5">
                                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                        <div className="rounded-md border bg-muted/20 p-3">
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Outgoing Staff
                                            </div>
                                            <div className="mt-1 text-sm font-medium">
                                                {handover.outgoing_staff
                                                    ?.name ?? 'Not set'}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/20 p-3">
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Incoming Staff
                                            </div>
                                            <div className="mt-1 text-sm font-medium">
                                                {handover.incoming_staff
                                                    ?.name ?? 'Not set'}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/20 p-3">
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Submitted
                                            </div>
                                            <div className="mt-1 text-sm font-medium">
                                                {formatDateTime(
                                                    handover.submitted_at,
                                                )}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/20 p-3">
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Acknowledged
                                            </div>
                                            <div className="mt-1 text-sm font-medium">
                                                {handover.acknowledger?.name
                                                    ? `${handover.acknowledger.name} • ${formatDateTime(
                                                          handover.acknowledged_at,
                                                      )}`
                                                    : formatDateTime(
                                                          handover.acknowledged_at,
                                                      )}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                                        <div className="space-y-3">
                                            <div className="flex items-center gap-2">
                                                <ClipboardList className="h-4 w-4 text-muted-foreground" />
                                                <div className="font-medium">
                                                    Medication notes
                                                </div>
                                            </div>
                                            <div className="rounded-md border p-4 text-sm leading-6">
                                                {handover.handover_notes}
                                            </div>
                                        </div>

                                        <div className="space-y-3">
                                            <div className="flex items-center gap-2">
                                                <Pill className="h-4 w-4 text-muted-foreground" />
                                                <div className="font-medium">
                                                    Client presentation
                                                </div>
                                            </div>
                                            <div className="rounded-md border p-4 text-sm">
                                                {handover.client_mood ||
                                                    'No client presentation note recorded.'}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 xl:grid-cols-2">
                                        <ListSection
                                            title="Medications Due"
                                            items={handover.medications_due}
                                            emptyLabel="No due medications were listed."
                                        />
                                        <ListSection
                                            title="Follow-up Items"
                                            items={handover.follow_up_items}
                                            emptyLabel="No follow-up items were listed."
                                        />
                                        <ListSection
                                            title="Incidents To Note"
                                            items={handover.incidents_to_note}
                                            emptyLabel="No incidents or medication exceptions were listed."
                                        />
                                        <ListSection
                                            title="Tasks Pending"
                                            items={handover.tasks_pending}
                                            emptyLabel="No pending medication tasks were listed."
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>

                <LaravelPagination
                    links={handovers.links}
                    lastPage={handovers.meta?.last_page}
                />
            </PageShell>

            <HandoverDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                editing={editing}
                shifts={shifts}
            />
        </AppLayout>
    );
}
