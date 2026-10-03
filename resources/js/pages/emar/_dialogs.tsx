import { LegacyOrderLink } from '@/pages/emar/orders/_legacy-link';
/* eslint-disable no-restricted-syntax -- detail/instruction panes are custom-layout
   bordered surfaces inside the wizard, not Card components; all colours are semantic tokens. */
import type {
    ClientOption,
    MedDetailPayload,
    MedRow,
} from '@/components/emar/medications/types';
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    BadgeCheck,
    Ban,
    CheckCircle2,
    ClipboardList,
    FileText,
    FileUp,
    HeartPulse,
    History,
    Pencil,
    Pill,
    Printer,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ── helpers ──────────────────────────────────────────────────────────────────
export type OrderAllergy = {
    allergen: string;
    severity: string | null;
    source: string | null;
};

/** null = still checking; 'unavailable' = couldn't be read; [] = none recorded. */
export type OrderAllergyState = OrderAllergy[] | 'unavailable' | null;

/**
 * Read GET /api/medications/clients/{id}/allergies. `recorded_allergies` is
 * the medication register + health profile list (EM-07) that dose-time
 * checks use; the register-only `allergies` list is the fallback. A response
 * without either list is 'unavailable' — never "none recorded".
 */
export function parseOrderAllergies(
    data: unknown,
): OrderAllergy[] | 'unavailable' {
    const body = (data ?? {}) as {
        recorded_allergies?: unknown;
        allergies?: unknown;
    };
    const list: unknown[] | null = Array.isArray(body.recorded_allergies)
        ? body.recorded_allergies
        : Array.isArray(body.allergies)
          ? body.allergies
          : null;
    if (list === null) return 'unavailable';

    return list.flatMap((entry): OrderAllergy[] => {
        const { allergen, severity, source } = (entry ?? {}) as {
            allergen?: unknown;
            severity?: unknown;
            source?: unknown;
        };
        if (typeof allergen !== 'string' || allergen.trim() === '') return [];

        return [
            {
                allergen: allergen.trim(),
                severity: typeof severity === 'string' ? severity : null,
                source: typeof source === 'string' ? source : null,
            },
        ];
    });
}

/** Fetch a resident's recorded allergies; any failure is 'unavailable'. */
export async function loadOrderAllergies(
    clientId: number,
    get: (url: string) => Promise<{ data: unknown }> = axios.get,
): Promise<OrderAllergy[] | 'unavailable'> {
    try {
        const response = await get(
            `/api/medications/clients/${clientId}/allergies`,
        );

        return parseOrderAllergies(response.data);
    } catch {
        return 'unavailable';
    }
}

/** Allergy step of the new-order wizard. An empty list is never a reassurance. */
export function OrderAllergyNotice({
    allergies,
    clash,
}: {
    allergies: OrderAllergyState;
    clash?: OrderAllergy;
}) {
    if (clash) {
        return (
            <InfoCard icon={AlertTriangle} tone="crit">
                <strong>Allergy alert:</strong> this client has a recorded
                allergy to {clash.allergen}. Confirm with the prescriber before
                charting.
            </InfoCard>
        );
    }
    if (allergies === null) {
        return (
            <InfoCard icon={HeartPulse}>Checking client allergies…</InfoCard>
        );
    }
    if (allergies === 'unavailable') {
        return (
            <InfoCard icon={AlertTriangle} tone="warn">
                Allergy record couldn&apos;t be loaded — check the health
                profile before ordering.
            </InfoCard>
        );
    }
    if (allergies.length === 0) {
        return <InfoCard icon={HeartPulse}>No allergies recorded.</InfoCard>;
    }

    return (
        <InfoCard icon={HeartPulse}>
            Recorded allergies: {allergies.map((a) => a.allergen).join(', ')}.
            No name match with this drug.
        </InfoCard>
    );
}

// ── Add medication (shared 4-step wizard; reused by MAR governance) ───────────
export function AddMedicationDialog({
    clientId,
    clients,
    onClose,
}: {
    clientId?: number | null;
    clients?: ClientOption[];
    onClose: () => void;
}) {
    return (
        <LegacyOrderLink
            clientId={clientId}
            clients={clients}
            onClose={onClose}
        />
    );
}

// ── Edit medication (single form) ────────────────────────────────────────────
export function EditMedicationDialog({
    medication,
    onClose,
}: {
    medication: MedRow;
    onClose: () => void;
}) {
    return <LegacyOrderLink medication={medication} onClose={onClose} />;
}

// ── Medication detail (read-only) ────────────────────────────────────────────
export function MedicationDetailDialog({
    medication,
    canVerify,
    onClose,
    onEdit,
    onDiscontinue,
    onReject,
    onVerify,
}: {
    medication: MedRow;
    canVerify: boolean;
    onClose: () => void;
    onEdit: () => void;
    onDiscontinue: () => void;
    onReject: () => void;
    onVerify: () => void;
}) {
    const pending = medication.approval_status === 'pending_verification';
    const rejected = medication.approval_status === 'rejected';
    const flags =
        [
            medication.is_prn && 'PRN',
            medication.controlled_drug && 'Controlled drug',
            medication.high_risk && 'High-risk',
            medication.witness_required && 'Witness required',
            medication.interaction_severity &&
                `Interaction: ${medication.interaction_severity}`,
        ]
            .filter(Boolean)
            .join(' · ') || 'None';

    // Stock-movement history + per-client interaction detail are lazy-loaded on
    // open (kept off the whole-register payload), mirroring the allergies fetch.
    const [detail, setDetail] = useState<MedDetailPayload | null>(null);
    const [loadingDetail, setLoadingDetail] = useState(true);
    useEffect(() => {
        let cancelled = false;
        setLoadingDetail(true);
        axios
            .get<MedDetailPayload>(`/emar/medications/${medication.id}/detail`)
            .then((r) => !cancelled && setDetail(r.data))
            .catch(
                () =>
                    !cancelled &&
                    setDetail({ movements: [], interactions: [] }),
            )
            .finally(() => !cancelled && setLoadingDetail(false));
        return () => {
            cancelled = true;
        };
    }, [medication.id]);

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={medication.name}
            description={`${medication.client_name} · ${medication.state}`}
            railIcon={Pill}
            railTitle={medication.name}
            railSubtitle={medication.client_name}
            steps={[
                {
                    key: 'detail',
                    label: 'Details',
                    blurb: 'Order summary',
                    icon: Pill,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {pending && canVerify && (
                            <>
                                <Button variant="outline" onClick={onReject}>
                                    <Ban className="h-4 w-4" /> Reject
                                </Button>
                                <Button onClick={onVerify}>
                                    <BadgeCheck className="h-4 w-4" /> Verify
                                    order
                                </Button>
                            </>
                        )}
                        <Button
                            variant={
                                pending && canVerify ? 'outline' : 'default'
                            }
                            onClick={onEdit}
                        >
                            <Pencil className="h-4 w-4" /> Edit
                        </Button>
                        {medication.state === 'active' && (
                            <Button variant="outline" onClick={onDiscontinue}>
                                <Ban className="h-4 w-4 text-status-critical" />{' '}
                                Discontinue
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            onClick={() =>
                                router.visit(
                                    `/operations/clients/${medication.client_id}?tab=mar`,
                                )
                            }
                        >
                            <User className="h-4 w-4" /> Client
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() =>
                                router.visit(
                                    `/emar/mar?client_id=${medication.client_id}`,
                                )
                            }
                        >
                            <FileText className="h-4 w-4" /> MAR
                        </Button>
                        <Button variant="ghost" onClick={() => window.print()}>
                            <Printer className="h-4 w-4" /> Print
                        </Button>
                    </div>
                </>
            }
        >
            {pending && (
                <InfoCard icon={ShieldCheck} tone="warn">
                    Awaiting prescriber verification — not administrable until
                    verified.
                    {canVerify
                        ? ' Use Verify order below to confirm, or Reject to decline.'
                        : ''}
                </InfoCard>
            )}
            {rejected && (
                <InfoCard icon={AlertTriangle} tone="crit">
                    Rejected
                    {medication.rejection_reason
                        ? `: ${medication.rejection_reason}`
                        : ''}
                    .
                </InfoCard>
            )}
            <div className="mt-3 rounded-lg border px-4">
                <SummaryRow
                    label="Dose"
                    value={
                        [medication.dosage, medication.dose_unit]
                            .filter(Boolean)
                            .join(' ') || '—'
                    }
                />
                <SummaryRow
                    label="Frequency"
                    value={
                        medication.is_prn
                            ? `PRN${medication.prn_reason ? ` · ${medication.prn_reason}` : ''}`
                            : (medication.frequency ?? '—')
                    }
                />
                <SummaryRow
                    label="Route / form"
                    value={
                        [medication.route, medication.form]
                            .filter(Boolean)
                            .join(' · ') || '—'
                    }
                />
                <SummaryRow
                    label="Indication"
                    value={medication.indication ?? '—'}
                />
                <SummaryRow
                    label="Prescriber"
                    value={medication.prescriber ?? '—'}
                />
                <SummaryRow
                    label="Flags"
                    value={flags}
                    tone={
                        medication.controlled_drug || medication.high_risk
                            ? 'crit'
                            : undefined
                    }
                />
                {medication.is_prn && (
                    <SummaryRow
                        label="PRN limit"
                        value={
                            medication.max_per_day
                                ? `${medication.max_per_day} per 24h`
                                : '—'
                        }
                    />
                )}
                <SummaryRow
                    label="Stock"
                    value={
                        medication.stock
                            ? `${medication.stock.on_hand ?? '—'} ${medication.stock.unit ?? ''}${medication.stock.low ? ' · low' : ''}`
                            : '—'
                    }
                />
            </div>
            {medication.instructions && (
                <div className="mt-3 rounded-lg border bg-background p-3 text-sm">
                    <div className="mb-1 font-medium">Instructions</div>
                    <p className="text-muted-foreground">
                        {medication.instructions}
                    </p>
                </div>
            )}
            <div className="mt-3 rounded-lg border bg-background p-4">
                <div className="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                    <FileText className="h-4 w-4 text-muted-foreground" /> Audit
                    trail
                </div>
                <SummaryRow
                    label="Charted by"
                    value={
                        [medication.created_by_name, medication.created_at]
                            .filter(Boolean)
                            .join(' · ') || '—'
                    }
                />
                <SummaryRow
                    label="Verification"
                    value={
                        pending
                            ? 'Awaiting prescriber verification'
                            : rejected
                              ? `Rejected${medication.rejection_reason ? ` — ${medication.rejection_reason}` : ''}`
                              : [
                                    medication.verified_by_name,
                                    medication.verified_at,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'Verified'
                    }
                    tone={pending ? undefined : rejected ? 'crit' : 'success'}
                />
                {medication.review_date && (
                    <SummaryRow
                        label="Review due"
                        value={medication.review_date}
                    />
                )}
                {medication.state === 'ceased' && (
                    <SummaryRow
                        label="Ceased"
                        value={
                            [
                                medication.ceased_by_name,
                                medication.ceased_at,
                                medication.ceased_reason,
                            ]
                                .filter(Boolean)
                                .join(' · ') || '—'
                        }
                        tone="crit"
                    />
                )}
            </div>

            <div className="mt-3 rounded-lg border bg-background p-4">
                <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                    <History className="h-4 w-4 text-muted-foreground" /> Recent
                    stock activity
                </div>
                {loadingDetail ? (
                    <p className="text-[13px] text-muted-foreground">
                        Loading…
                    </p>
                ) : detail && detail.movements.length > 0 ? (
                    <ul className="flex flex-col gap-2">
                        {detail.movements.map((m, i) => (
                            <li
                                key={i}
                                className="flex items-start gap-2.5 text-[13px]"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                        movementDot(m.status),
                                    )}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-baseline gap-x-2">
                                        <span className="font-medium">
                                            {m.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {m.at ?? '—'}
                                        </span>
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {[
                                            m.type === 'count'
                                                ? 'Stock count'
                                                : 'Administration',
                                            m.by,
                                            m.note,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-[13px] text-muted-foreground">
                        No recorded stock activity yet — doses given and
                        completed stock counts will appear here.
                    </p>
                )}
            </div>

            <div className="mt-3 rounded-lg border bg-background p-4">
                <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                    <AlertTriangle className="h-4 w-4 text-muted-foreground" />{' '}
                    Interactions
                </div>
                {loadingDetail ? (
                    <p className="text-[13px] text-muted-foreground">
                        Loading…
                    </p>
                ) : detail && detail.interactions.length > 0 ? (
                    <ul className="flex flex-col gap-2.5">
                        {detail.interactions.map((it, i) => (
                            <li key={i} className="rounded-md border px-3 py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[13px] font-medium">
                                        with {it.other}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                            severityTone(it.severity),
                                        )}
                                    >
                                        {it.severity_label}
                                    </span>
                                </div>
                                {it.description && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {it.description}
                                    </p>
                                )}
                                {it.clinical_effects && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        <span className="font-medium text-foreground">
                                            Effects:
                                        </span>{' '}
                                        {it.clinical_effects}
                                    </p>
                                )}
                                {it.management && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        <span className="font-medium text-foreground">
                                            Manage:
                                        </span>{' '}
                                        {it.management}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : medication.interaction_severity ? (
                    <p className="text-[13px] text-muted-foreground">
                        Flagged{' '}
                        <span className="font-medium text-status-warning">
                            {medication.interaction_severity}
                        </span>{' '}
                        interaction — no further detail recorded.
                    </p>
                ) : (
                    <p className="text-[13px] text-muted-foreground">
                        No interactions recorded against the client's other
                        current medications.
                    </p>
                )}
            </div>
        </MedsWizardDialog>
    );
}

// ── Discontinue (required reason) ────────────────────────────────────────────
export function DiscontinueDialog({
    medication,
    onClose,
    action,
}: {
    medication: Pick<MedRow, 'id' | 'name'>;
    onClose: () => void;
    action?: string;
}) {
    const form = useForm({
        reason: '',
        request_key: crypto.randomUUID(),
    });
    const submit = () => {
        form.post(action ?? `/emar/medications/${medication.id}/discontinue`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Medication discontinued');
                onClose();
            },
            onError: () => toast.error('A reason is required'),
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={`Discontinue ${medication.name}?`}
            formState={form}
            description="The medication is ceased and archived; records are kept."
            railIcon={Ban}
            railTitle="Discontinue"
            railSubtitle={medication.name}
            steps={[
                {
                    key: 'confirm',
                    label: 'Confirm',
                    blurb: 'Reason required',
                    icon: Ban,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={!form.data.reason.trim() || form.processing}
                    >
                        Discontinue medication
                    </Button>
                </>
            }
        >
            <StepHead
                icon={Ban}
                title={`Discontinue ${medication.name}`}
                blurb="This ceases the order. Records are retained for audit."
            />
            <Field label="Reason" required error={form.errors.reason}>
                <Input
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Why is this medication being ceased?"
                    maxLength={255}
                />
            </Field>
        </MedsWizardDialog>
    );
}

// ── Reject order (required reason) ───────────────────────────────────────────
export function RejectOrderDialog({
    medication,
    onClose,
}: {
    medication: MedRow;
    onClose: () => void;
}) {
    return (
        <LegacyOrderLink
            medication={medication}
            checkMode="send_back"
            onClose={onClose}
        />
    );
}

// ── Import CSV ──────────────────────────────────────────────────────────────
export function ImportCsvDialog({ onClose }: { onClose: () => void }) {
    const form = useForm<{ csv_file: File | null }>({ csv_file: null });
    const submit = () => {
        form.post('/emar/medications/import', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                onClose();
            },
            onError: () => toast.error('Import failed — check the file format'),
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Import medications"
            formState={form}
            description="Bulk-import medication orders from a CSV file."
            railIcon={FileUp}
            railTitle="Import CSV"
            railSubtitle="Bulk load"
            steps={[
                {
                    key: 'import',
                    label: 'Import',
                    blurb: 'Upload file',
                    icon: FileUp,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={!form.data.csv_file || form.processing}
                    >
                        Import
                    </Button>
                </>
            }
        >
            <StepHead
                icon={FileUp}
                title="Import from CSV"
                blurb="Rows are validated before anything is saved."
            />
            <InfoCard icon={ClipboardList}>
                Expected columns:{' '}
                <code>
                    client_name, medication_name, dose, frequency, route
                </code>
            </InfoCard>
            <div className="mt-4">
                <Field label="Upload file" required>
                    {/* eslint-disable-next-line no-restricted-syntax -- native file input; no shadcn file control */}
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) =>
                            form.setData(
                                'csv_file',
                                e.target.files?.[0] ?? null,
                            )
                        }
                        className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm"
                    />
                </Field>
            </div>
        </MedsWizardDialog>
    );
}

// ── Drug interactions (reference) ────────────────────────────────────────────
export function InteractionsDialog({
    medications,
    onClose,
}: {
    medications: MedRow[];
    onClose: () => void;
}) {
    const flagged = medications.filter((m) => m.interaction_severity);
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Drug interactions"
            description="Medications with a recorded interaction against another current order."
            railIcon={AlertTriangle}
            railTitle="Interactions"
            railSubtitle={`${flagged.length} flagged`}
            steps={[
                {
                    key: 'interactions',
                    label: 'Interactions',
                    blurb: 'Reference',
                    icon: AlertTriangle,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <StepHead
                icon={AlertTriangle}
                title="Recorded interactions"
                blurb="Review before the next round."
            />
            {flagged.length === 0 ? (
                <InfoCard icon={CheckCircle2}>
                    No interactions recorded across the current register.
                </InfoCard>
            ) : (
                <ul className="flex flex-col gap-2">
                    {flagged.map((m) => (
                        <li
                            key={m.id}
                            className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                        >
                            <span>
                                <span className="font-medium">{m.name}</span>
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {m.client_name}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                    severityTone(m.interaction_severity),
                                )}
                            >
                                {m.interaction_severity}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </MedsWizardDialog>
    );
}

function severityTone(severity: string | null): string {
    const s = (severity ?? '').toLowerCase();
    if (
        s.includes('contraindicated') ||
        s.includes('major') ||
        s.includes('severe') ||
        s.includes('high')
    )
        return 'bg-status-critical-bg text-status-critical';
    if (s.includes('moderate'))
        return 'bg-status-warning-bg text-status-warning';
    return 'bg-status-info-bg text-status-info';
}

/** Stock-activity timeline dot colour by movement status (semantic tokens). */
function movementDot(status: string | null): string {
    const s = (status ?? '').toLowerCase();
    if (s === 'given' || s === 'counted') return 'bg-status-success';
    if (s === 'refused' || s === 'discrepancy') return 'bg-status-critical';
    if (s === 'missed') return 'bg-status-warning';
    return 'bg-muted-foreground';
}
