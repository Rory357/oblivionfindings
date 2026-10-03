import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DoseWindow } from '@/lib/emar-dose-window';
import type { WitnessPickerOption } from '@/lib/witness-pin';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
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
        scan_verification?: ScanVerification | null;
    };
};

type ScanVerification = {
    primary_code: string;
    primary_label: string;
    primary_source: string;
    internal_code: string;
    vendor_barcode?: string | null;
    nzulm_code?: string | null;
    requires_internal_code: boolean;
    svg_url: string;
    code_options: Array<{
        source: string;
        label: string;
        value: string;
    }>;
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
    dose_window?: DoseWindow | null;
} | null;

type Props = {
    clientId: number;
    shiftId: number;
    shiftStatus: string;
    canRecord: boolean;
    canRecordControlled: boolean;
    summary: MedicationSummary;
    witnesses: WitnessPickerOption[];
};

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
    canRecordControlled,
    summary,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const [activeRow, setActiveRow] = useState<MedicationRow | null>(null);
    const canRecordOnShift =
        canRecord && !['completed', 'cancelled'].includes(shiftStatus);
    const canRecordRow = (row: MedicationRow) =>
        canRecordOnShift &&
        (!row.medication.controlled_drug || canRecordControlled);
    const openAdministrationDialog = (row: MedicationRow) => {
        if (canRecordRow(row) && (row.medication.is_prn || row.scheduled_for))
            setActiveRow(row);
    };

    const outstandingCount = useMemo(
        () =>
            Number(summary?.stats?.scheduled?.due ?? 0) +
            Number(summary?.stats?.scheduled?.late ?? 0) +
            Number(summary?.stats?.scheduled?.missed ?? 0),
        [summary],
    );

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
                            <Link href={`/emar/mar?client_id=${clientId}`}>
                                Open MAR
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={`/emar/medications?client_id=${clientId}`}
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
                                                {canRecordRow(row) &&
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
                                                {canRecordRow(row) &&
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

            {activeRow && (
                <RecordDoseDialog
                    key={`${activeRow.medication.id}:${activeRow.scheduled_for ?? 'prn'}`}
                    target={
                        activeRow.medication.is_prn
                            ? {
                                  kind: 'prn',
                                  orderId: activeRow.medication.id,
                                  label: {
                                      medicine: activeRow.medication.name,
                                  },
                              }
                            : {
                                  kind: 'scheduled',
                                  orderId: activeRow.medication.id,
                                  scheduledFor: activeRow.scheduled_for!,
                                  label: {
                                      medicine: activeRow.medication.name,
                                  },
                              }
                    }
                    entry="shift"
                    signedAs={{ name: auth.user.name, role_label: null }}
                    shiftContext={{
                        shiftId,
                        scanVerification:
                            activeRow.medication.scan_verification,
                    }}
                    onClose={() => setActiveRow(null)}
                    onRecorded={(result) => {
                        if (result.status !== 'queued')
                            router.reload({ preserveScroll: true });
                    }}
                />
            )}
        </>
    );
}
