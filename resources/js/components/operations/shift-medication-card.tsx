import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type MedicationRow = {
    client_medication_id: number;
    scheduled_for?: string | null;
    scheduled_time?: string | null;
    schedule_state?: string;
    schedule_state_label?: { label: string } | null;
    can_record?: boolean;
    is_overdue?: boolean;
    requires_witness?: boolean;
    medication: {
        id: number;
        name: string;
        dosage?: string | null;
        controlled_drug?: boolean;
        is_prn?: boolean;
    };
};

type MedicationSummary = {
    stats?: {
        scheduled?: {
            completed?: number;
            due?: number;
            late?: number;
            missed?: number;
        };
    } | null;
    allergies?: Array<{
        id: number;
        allergen: string;
        reaction?: string | null;
        is_severe?: boolean;
    }>;
    due: MedicationRow[];
    prn: MedicationRow[];
    recent_history: Array<{
        id: number;
        medication_name: string;
        status: string;
        administered_at?: string | null;
        is_controlled?: boolean;
        is_prn?: boolean;
    }>;
} | null;

type Props = {
    clientId: number;
    shiftId: number;
    shiftStatus: string;
    canRecord: boolean;
    summary: MedicationSummary;
    witnesses: Array<{ id: number; name: string }>;
};

function toLocalDateTimeInput(iso?: string | null) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromLocalDateTimeInput(value: string) {
    return value ? new Date(value).toISOString() : null;
}

function sentenceCase(value: string) {
    return value
        .split('_')
        .join(' ')
        .replace(/^\w/, (match) => match.toUpperCase());
}

export default function ShiftMedicationCard({
    clientId,
    shiftId,
    shiftStatus,
    canRecord,
    summary,
    witnesses,
}: Props) {
    const [open, setOpen] = useState(false);
    const [activeRow, setActiveRow] = useState<MedicationRow | null>(null);
    const canRecordOnShift = canRecord && shiftStatus !== 'completed';

    const adminForm = useForm({
        status: 'given',
        reason: '',
        dose_given: '',
        notes: '',
        scheduled_for: '' as string | null,
        administered_at: '',
        shift_id: shiftId as number | string,
        witnessed_by: '__none__',
    });

    const outstandingCount = useMemo(
        () =>
            Number(summary?.stats?.scheduled?.due ?? 0) +
            Number(summary?.stats?.scheduled?.late ?? 0) +
            Number(summary?.stats?.scheduled?.missed ?? 0),
        [summary],
    );

    const needsReason = useMemo(() => {
        if (!activeRow) return false;
        if (adminForm.data.status !== 'given') return true;
        if (activeRow.medication.is_prn) return true;

        if (adminForm.data.scheduled_for && adminForm.data.administered_at) {
            try {
                const scheduled = new Date(adminForm.data.scheduled_for);
                const administeredAt = new Date(
                    fromLocalDateTimeInput(adminForm.data.administered_at) ||
                        new Date().toISOString(),
                );
                const diffMinutes =
                    (administeredAt.getTime() - scheduled.getTime()) / 60000;
                return diffMinutes < -60 || diffMinutes > 30;
            } catch {
                return false;
            }
        }

        return false;
    }, [
        activeRow,
        adminForm.data.administered_at,
        adminForm.data.scheduled_for,
        adminForm.data.status,
    ]);

    const needsWitness = useMemo(
        () =>
            !!activeRow &&
            (activeRow.requires_witness ||
                activeRow.medication.controlled_drug) &&
            adminForm.data.status === 'given',
        [activeRow, adminForm.data.status],
    );

    const canSubmit = useMemo(
        () =>
            !!activeRow &&
            (!needsReason || !!adminForm.data.reason.trim()) &&
            (!needsWitness || adminForm.data.witnessed_by !== '__none__'),
        [
            activeRow,
            adminForm.data.reason,
            adminForm.data.witnessed_by,
            needsReason,
            needsWitness,
        ],
    );

    const openAdministrationDialog = (row: MedicationRow) => {
        setActiveRow(row);
        adminForm.reset();
        adminForm.setData('status', 'given');
        adminForm.setData('reason', '');
        adminForm.setData('dose_given', '');
        adminForm.setData('notes', '');
        adminForm.setData('scheduled_for', row.scheduled_for ?? '');
        adminForm.setData(
            'administered_at',
            toLocalDateTimeInput(new Date().toISOString()),
        );
        adminForm.setData('shift_id', shiftId);
        adminForm.setData('witnessed_by', '__none__');
        setOpen(true);
    };

    const submitAdministration = () => {
        if (!activeRow) return;

        adminForm.transform((data) => ({
            ...data,
            administered_at: fromLocalDateTimeInput(data.administered_at),
            scheduled_for: data.scheduled_for || null,
            witnessed_by:
                data.witnessed_by === '__none__' ? null : data.witnessed_by,
        }));

        adminForm.post(
            `/operations/clients/${clientId}/medical/medications/${activeRow.medication.id}/administrations`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setActiveRow(null);
                },
            },
        );
    };

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3">
                    <div>
                        <CardTitle className="text-base">Medication</CardTitle>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Due doses, PRN access, and recorded administrations
                            for this shift.
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/clients/${clientId}/mar`}>
                                Open MAR
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={`/operations/clients/${clientId}/medical`}
                            >
                                Medical
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {!summary ? (
                        <div className="rounded-md border p-3 text-sm text-muted-foreground">
                            Medication information is not available for this
                            shift.
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-3 md:grid-cols-4">
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Outstanding
                                    </div>
                                    <div className="mt-1 text-2xl font-semibold">
                                        {outstandingCount}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Given
                                    </div>
                                    <div className="mt-1 text-2xl font-semibold">
                                        {summary.stats?.scheduled?.completed ??
                                            0}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Late
                                    </div>
                                    <div className="mt-1 text-2xl font-semibold">
                                        {summary.stats?.scheduled?.late ?? 0}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Missed
                                    </div>
                                    <div className="mt-1 text-2xl font-semibold">
                                        {summary.stats?.scheduled?.missed ?? 0}
                                    </div>
                                </div>
                            </div>

                            {summary.allergies &&
                            summary.allergies.length > 0 ? (
                                <div className="rounded-md border p-3">
                                    <div className="text-sm font-medium">
                                        Medication allergies
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {summary.allergies.map((allergy) => (
                                            <Badge
                                                key={allergy.id}
                                                variant={
                                                    allergy.is_severe
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {allergy.allergen}
                                                {allergy.reaction
                                                    ? ` | ${allergy.reaction}`
                                                    : ''}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-medium">
                                        Due or late doses
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {summary.due.length} item
                                        {summary.due.length === 1 ? '' : 's'}
                                    </div>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {summary.due.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">
                                            No due or late doses are showing
                                            right now.
                                        </div>
                                    ) : (
                                        summary.due.map((row) => (
                                            <div
                                                key={`${row.medication.id}-${row.scheduled_for ?? row.client_medication_id}`}
                                                className="flex flex-col gap-3 rounded-md border p-3 md:flex-row md:items-center md:justify-between"
                                            >
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <div className="text-sm font-medium">
                                                            {
                                                                row.medication
                                                                    .name
                                                            }
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                row.is_overdue
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {row
                                                                .schedule_state_label
                                                                ?.label ??
                                                                sentenceCase(
                                                                    row.schedule_state ??
                                                                        'due',
                                                                )}
                                                        </Badge>
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.medication
                                                            .dosage ||
                                                            'Dose not specified'}
                                                        {row.scheduled_time
                                                            ? ` | Scheduled ${row.scheduled_time}`
                                                            : ''}
                                                    </div>
                                                </div>
                                                {canRecordOnShift &&
                                                row.can_record ? (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            openAdministrationDialog(
                                                                row,
                                                            )
                                                        }
                                                    >
                                                        Record
                                                    </Button>
                                                ) : null}
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-medium">
                                        PRN medications
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {summary.prn.length} medication
                                        {summary.prn.length === 1 ? '' : 's'}
                                    </div>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {summary.prn.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">
                                            No PRN medications are active for
                                            this client.
                                        </div>
                                    ) : (
                                        summary.prn.map((row) => (
                                            <div
                                                key={`prn-${row.medication.id}`}
                                                className="flex flex-col gap-3 rounded-md border p-3 md:flex-row md:items-center md:justify-between"
                                            >
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <div className="text-sm font-medium">
                                                            {
                                                                row.medication
                                                                    .name
                                                            }
                                                        </div>
                                                        <Badge variant="outline">
                                                            PRN
                                                        </Badge>
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {row.medication
                                                            .dosage ||
                                                            'Dose not specified'}
                                                    </div>
                                                </div>
                                                {canRecordOnShift &&
                                                row.can_record ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            openAdministrationDialog(
                                                                row,
                                                            )
                                                        }
                                                    >
                                                        Record PRN
                                                    </Button>
                                                ) : null}
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Recent administrations on this shift
                                </div>
                                <div className="mt-3 space-y-2">
                                    {summary.recent_history.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">
                                            No medication administrations have
                                            been recorded against this shift
                                            yet.
                                        </div>
                                    ) : (
                                        summary.recent_history.map((entry) => (
                                            <div
                                                key={entry.id}
                                                className="flex flex-col gap-1 rounded-md border p-3 md:flex-row md:items-center md:justify-between"
                                            >
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {entry.medication_name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {entry.administered_at
                                                            ? new Date(
                                                                  entry.administered_at,
                                                              ).toLocaleString()
                                                            : 'No administration time recorded'}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="outline">
                                                        {sentenceCase(
                                                            entry.status,
                                                        )}
                                                    </Badge>
                                                    {entry.is_controlled ? (
                                                        <Badge variant="secondary">
                                                            Controlled
                                                        </Badge>
                                                    ) : null}
                                                    {entry.is_prn ? (
                                                        <Badge variant="secondary">
                                                            PRN
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Record medication administration
                        </DialogTitle>
                    </DialogHeader>

                    {activeRow ? (
                        <div className="space-y-3">
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    {activeRow.medication.name}
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {activeRow.medication.dosage ||
                                        'Dose not specified'}
                                    {activeRow.scheduled_time
                                        ? ` | Scheduled ${activeRow.scheduled_time}`
                                        : ' | PRN'}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label>Status</Label>
                                <Select
                                    value={adminForm.data.status}
                                    onValueChange={(value) =>
                                        adminForm.setData('status', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="given">
                                            Given
                                        </SelectItem>
                                        <SelectItem value="refused">
                                            Refused
                                        </SelectItem>
                                        <SelectItem value="withheld">
                                            Withheld
                                        </SelectItem>
                                        <SelectItem value="missed">
                                            Missed
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Administered at</Label>
                                <Input
                                    type="datetime-local"
                                    value={adminForm.data.administered_at}
                                    onChange={(event) =>
                                        adminForm.setData(
                                            'administered_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            {needsWitness ? (
                                <div className="space-y-1">
                                    <Label>Witness</Label>
                                    <Select
                                        value={adminForm.data.witnessed_by}
                                        onValueChange={(value) =>
                                            adminForm.setData(
                                                'witnessed_by',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select witness" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Select witness
                                            </SelectItem>
                                            {witnesses.map((witness) => (
                                                <SelectItem
                                                    key={witness.id}
                                                    value={String(witness.id)}
                                                >
                                                    {witness.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : null}

                            <div className="space-y-1">
                                <Label>Reason</Label>
                                <Input
                                    value={adminForm.data.reason}
                                    onChange={(event) =>
                                        adminForm.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    placeholder={
                                        needsReason
                                            ? 'Required for this administration'
                                            : 'Optional'
                                    }
                                />
                                {needsReason ? (
                                    <div className="text-xs text-muted-foreground">
                                        A reason is required for PRN, non-given,
                                        or outside-window administrations.
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-1">
                                <Label>Dose given</Label>
                                <Input
                                    value={adminForm.data.dose_given}
                                    onChange={(event) =>
                                        adminForm.setData(
                                            'dose_given',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Notes</Label>
                                <Textarea
                                    value={adminForm.data.notes}
                                    onChange={(event) =>
                                        adminForm.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={adminForm.processing || !canSubmit}
                            onClick={submitAdministration}
                        >
                            Save administration
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
