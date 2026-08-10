import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { AddMedicationDialog } from '@/pages/emar/_dialogs';
import type { WitnessOption } from '@/pages/meds/today/types';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    FileText,
    HeartPulse,
    ShieldCheck,
    Syringe,
} from 'lucide-react';
import { useState } from 'react';

export type MarModal =
    | 'addMed'
    | 'inr'
    | 'syringe'
    | 'alerts'
    | 'verify'
    | 'warnings'
    | 'corrections'
    | null;

type AttentionAlert = {
    id: number;
    type: string;
    title: string;
    detail?: string | null;
    prompt_on_open: boolean;
};
type AwaitingOrder = { id: number; name: string; dosage: string };

export type PendingCorrection = {
    id: number;
    medication_name: string;
    status: string;
    dose_given?: string | null;
    correction_reason?: string | null;
    submitted_by?: string | null;
    submitted_at?: string | null;
};

type Props = {
    modal: MarModal;
    onClose: () => void;
    clientId: number;
    attentionAlerts: AttentionAlert[];
    awaitingVerification: AwaitingOrder[];
    corrections: PendingCorrection[];
    witnesses: WitnessOption[];
    suppression: { suppressed: boolean; reason: string | null };
};

function FooterRow({
    onCancel,
    submitLabel,
    processing,
    onBack,
}: {
    onCancel: () => void;
    submitLabel: string;
    processing: boolean;
    onBack?: () => void;
}) {
    return (
        <>
            {onBack ? (
                <Button type="button" variant="ghost" onClick={onBack}>
                    Back
                </Button>
            ) : (
                <Button type="button" variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
            )}
            <Button type="submit" disabled={processing}>
                {submitLabel}
            </Button>
        </>
    );
}

export default function MarGovernanceDialogs({
    modal,
    onClose,
    clientId,
    attentionAlerts,
    awaitingVerification,
    corrections,
    witnesses,
    suppression,
}: Props) {
    return (
        <>
            {modal === 'addMed' && (
                <AddMedicationDialog clientId={clientId} onClose={onClose} />
            )}
            {modal === 'inr' && (
                <RecordInrDialog clientId={clientId} onClose={onClose} />
            )}
            {modal === 'syringe' && (
                <SyringeDriverDialog
                    clientId={clientId}
                    witnesses={witnesses}
                    onClose={onClose}
                />
            )}
            {modal === 'alerts' && (
                <ManageAlertsDialog
                    clientId={clientId}
                    suppression={suppression}
                    onClose={onClose}
                />
            )}
            {modal === 'verify' && (
                <VerifyOrderDialog
                    orders={awaitingVerification}
                    onClose={onClose}
                />
            )}
            {modal === 'corrections' && (
                <CorrectionsReviewDialog
                    corrections={corrections}
                    onClose={onClose}
                />
            )}
            {modal === 'warnings' && (
                <WarningsDialog alerts={attentionAlerts} onClose={onClose} />
            )}
        </>
    );
}

// ── Add medication ─────────────────────────────────────────────────────────
// ── Record INR ─────────────────────────────────────────────────────────────
function RecordInrDialog({
    clientId,
    onClose,
}: {
    clientId: number;
    onClose: () => void;
}) {
    const form = useForm({
        inr_value: '',
        tested_on: '',
        target_range_low: '',
        target_range_high: '',
        dose_mg: '',
        next_test_date: '',
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/emar/clients/${clientId}/inr`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Record INR"
            description="Record a warfarin INR result"
            railIcon={HeartPulse}
            railTitle="Record INR"
            railSubtitle="Warfarin monitoring"
            steps={[
                {
                    key: 'result',
                    label: 'Result',
                    blurb: 'Value & schedule',
                    icon: HeartPulse,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow
                        onCancel={onClose}
                        submitLabel="Record INR"
                        processing={form.processing}
                    />
                </form>
            }
        >
            <form onSubmit={submit}>
                <StepHead
                    icon={HeartPulse}
                    title="INR result"
                    blurb="Results are retained — disable, never delete."
                />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field
                        label="INR value"
                        required
                        error={form.errors.inr_value}
                    >
                        <Input
                            type="number"
                            step="0.1"
                            value={form.data.inr_value}
                            onChange={(e) =>
                                form.setData('inr_value', e.target.value)
                            }
                            placeholder="e.g. 2.4"
                        />
                    </Field>
                    <Field
                        label="Tested on"
                        required
                        error={form.errors.tested_on}
                    >
                        <Input
                            type="date"
                            value={form.data.tested_on}
                            onChange={(e) =>
                                form.setData('tested_on', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Target range (low)">
                        <Input
                            type="number"
                            step="0.1"
                            value={form.data.target_range_low}
                            onChange={(e) =>
                                form.setData('target_range_low', e.target.value)
                            }
                            placeholder="2.0"
                        />
                    </Field>
                    <Field
                        label="Target range (high)"
                        error={form.errors.target_range_high}
                    >
                        <Input
                            type="number"
                            step="0.1"
                            value={form.data.target_range_high}
                            onChange={(e) =>
                                form.setData(
                                    'target_range_high',
                                    e.target.value,
                                )
                            }
                            placeholder="3.0"
                        />
                    </Field>
                    <Field label="Dose (mg)">
                        <Input
                            type="number"
                            step="0.01"
                            value={form.data.dose_mg}
                            onChange={(e) =>
                                form.setData('dose_mg', e.target.value)
                            }
                            placeholder="e.g. 5"
                        />
                    </Field>
                    <Field
                        label="Next test date"
                        error={form.errors.next_test_date}
                    >
                        <Input
                            type="date"
                            value={form.data.next_test_date}
                            onChange={(e) =>
                                form.setData('next_test_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Notes" span>
                        <Input
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            placeholder="Optional"
                        />
                    </Field>
                </div>
            </form>
        </MedsWizardDialog>
    );
}

// ── Start syringe driver ───────────────────────────────────────────────────
function SyringeDriverDialog({
    clientId,
    witnesses,
    onClose,
}: {
    clientId: number;
    witnesses: WitnessOption[];
    onClose: () => void;
}) {
    const form = useForm({
        commenced_at: '',
        rate: '',
        rate_unit: 'mL/hr',
        site_of_insertion: '',
        notes: '',
        contents: [{ name: '', dose: '', unit: 'mg', requires_witness: false }],
        witnessed_by: '' as string,
        witness_credential: '',
    });
    const content = form.data.contents[0]!;
    const requiresWitness = content.requires_witness;

    const setContent = (patch: Partial<typeof content>) =>
        form.setData('contents', [{ ...content, ...patch }]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            witnessed_by: data.witnessed_by ? Number(data.witnessed_by) : null,
        }));
        form.post(`/emar/clients/${clientId}/syringe-drivers`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Start syringe driver"
            description="Commence a continuous subcutaneous infusion"
            railIcon={Syringe}
            railTitle="Syringe driver"
            railSubtitle="Commence infusion"
            steps={[
                {
                    key: 'driver',
                    label: 'Driver',
                    blurb: 'Contents & rate',
                    icon: Syringe,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow
                        onCancel={onClose}
                        submitLabel="Start driver"
                        processing={form.processing}
                    />
                </form>
            }
        >
            <form onSubmit={submit}>
                <StepHead
                    icon={Syringe}
                    title="Commence syringe driver"
                    blurb="Controlled-drug contents require a witness countersignature."
                />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Medication" required>
                        <Input
                            value={content.name}
                            onChange={(e) =>
                                setContent({ name: e.target.value })
                            }
                            placeholder="e.g. Morphine sulfate"
                        />
                    </Field>
                    <Field label="Dose">
                        <Input
                            value={content.dose}
                            onChange={(e) =>
                                setContent({ dose: e.target.value })
                            }
                            placeholder="e.g. 10"
                        />
                    </Field>
                    <Field
                        label="Commenced at"
                        required
                        error={form.errors.commenced_at}
                    >
                        <Input
                            type="datetime-local"
                            value={form.data.commenced_at}
                            onChange={(e) =>
                                form.setData('commenced_at', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Rate">
                        <Input
                            value={form.data.rate}
                            onChange={(e) =>
                                form.setData('rate', e.target.value)
                            }
                            placeholder="e.g. 2 mL/hr"
                        />
                    </Field>
                    <Field label="Insertion site" span>
                        <Input
                            value={form.data.site_of_insertion}
                            onChange={(e) =>
                                form.setData(
                                    'site_of_insertion',
                                    e.target.value,
                                )
                            }
                            placeholder="e.g. Left upper arm"
                        />
                    </Field>
                    <Field label="Witness required" span>
                        <Segmented
                            value={requiresWitness ? 'yes' : 'no'}
                            onChange={(v) =>
                                setContent({ requires_witness: v === 'yes' })
                            }
                            options={[
                                { value: 'no', label: 'No' },
                                { value: 'yes', label: 'Yes (CD)' },
                            ]}
                        />
                    </Field>
                    {requiresWitness && (
                        <>
                            <Field
                                label="Witness"
                                error={form.errors.witnessed_by}
                            >
                                <SelectInput
                                    value={form.data.witnessed_by}
                                    onChange={(v) =>
                                        form.setData('witnessed_by', v)
                                    }
                                    placeholder="Select witness…"
                                    options={witnesses.map((w) => ({
                                        value: String(w.id),
                                        label: w.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Witness password / PIN"
                                error={form.errors.witness_credential}
                            >
                                <Input
                                    type="password"
                                    value={form.data.witness_credential}
                                    onChange={(e) =>
                                        form.setData(
                                            'witness_credential',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Re-authenticate"
                                />
                            </Field>
                        </>
                    )}
                </div>
            </form>
        </MedsWizardDialog>
    );
}

// ── Manage attention alerts ────────────────────────────────────────────────
function ManageAlertsDialog({
    clientId,
    suppression,
    onClose,
}: {
    clientId: number;
    suppression: { suppressed: boolean; reason: string | null };
    onClose: () => void;
}) {
    const form = useForm({
        type: 'warfarin',
        title: '',
        detail: '',
        prompt_on_open: true,
    });
    const suppressForm = useForm({
        suppress_med_admin_alerts: suppression.suppressed,
        reason: suppression.reason ?? '',
        basis: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/emar/clients/${clientId}/attention-alerts`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const saveSuppression = () => {
        suppressForm.post(`/emar/clients/${clientId}/alert-suppression`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Manage attention alerts"
            description="Add a chart alert surfaced before any dose is given"
            railIcon={AlertTriangle}
            railTitle="Attention alert"
            railSubtitle="Chart warning"
            steps={[
                {
                    key: 'alert',
                    label: 'Alert',
                    blurb: 'Type & message',
                    icon: AlertTriangle,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow
                        onCancel={onClose}
                        submitLabel="Add alert"
                        processing={form.processing}
                    />
                </form>
            }
        >
            {/* Med-admin alert suppression (1CHART "Disable Med Admin Alerts") — a
                per-resident, audited setting; suppressing requires a reason. */}
            <div className="mb-4 rounded-lg border p-3">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <div className="text-sm font-semibold">
                            Medication-admin alerts
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Suppress med-due reminders for this resident.
                            Audited.
                        </div>
                    </div>
                    <Segmented
                        value={
                            suppressForm.data.suppress_med_admin_alerts
                                ? 'suppressed'
                                : 'active'
                        }
                        onChange={(v) =>
                            suppressForm.setData(
                                'suppress_med_admin_alerts',
                                v === 'suppressed',
                            )
                        }
                        options={[
                            { value: 'active', label: 'Active' },
                            { value: 'suppressed', label: 'Suppressed' },
                        ]}
                    />
                </div>
                {suppressForm.data.suppress_med_admin_alerts && (
                    <div className="mt-3 grid gap-3">
                        <Field
                            label="Basis"
                            required
                            error={suppressForm.errors.basis}
                        >
                            <SelectInput
                                value={suppressForm.data.basis}
                                onChange={(v) =>
                                    suppressForm.setData('basis', v)
                                }
                                placeholder="Select the decision basis…"
                                options={[
                                    {
                                        value: 'capacity_assessment',
                                        label: 'Capacity assessment',
                                    },
                                    {
                                        value: 'mdt_decision',
                                        label: 'MDT decision',
                                    },
                                    {
                                        value: 'clinical_judgement',
                                        label: 'Clinical judgement',
                                    },
                                    {
                                        value: 'client_preference',
                                        label: 'Client preference',
                                    },
                                ]}
                            />
                        </Field>
                        <Field
                            label="Reason"
                            required
                            error={suppressForm.errors.reason}
                        >
                            <Input
                                value={suppressForm.data.reason}
                                onChange={(e) =>
                                    suppressForm.setData(
                                        'reason',
                                        e.target.value,
                                    )
                                }
                                placeholder="Why are med-admin alerts being suppressed?"
                            />
                        </Field>
                    </div>
                )}
                <div className="mt-3 flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={suppressForm.processing}
                        onClick={saveSuppression}
                    >
                        Save setting
                    </Button>
                </div>
            </div>

            <form onSubmit={submit}>
                <StepHead
                    icon={AlertTriangle}
                    title="Chart alert"
                    blurb="Prompt-on-open alerts must be acknowledged before recording."
                />
                <Field label="Alert type" span>
                    <TilePicker
                        value={form.data.type}
                        onChange={(v) => form.setData('type', v)}
                        cols={3}
                        options={[
                            {
                                key: 'warfarin',
                                label: 'Warfarin',
                                icon: HeartPulse,
                            },
                            {
                                key: 'paper_prescription',
                                label: 'Paper prescription',
                                icon: FileText,
                            },
                            {
                                key: 'chart_warning',
                                label: 'Other',
                                icon: AlertTriangle,
                            },
                        ]}
                    />
                </Field>
                <div className="mt-4 grid grid-cols-1 gap-4">
                    <Field label="Title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="e.g. Warfarin — INR monitoring"
                        />
                    </Field>
                    <Field label="Detail">
                        <Input
                            value={form.data.detail}
                            onChange={(e) =>
                                form.setData('detail', e.target.value)
                            }
                            placeholder="Optional detail"
                        />
                    </Field>
                    <Field label="Prompt on chart open">
                        <Segmented
                            value={form.data.prompt_on_open ? 'yes' : 'no'}
                            onChange={(v) =>
                                form.setData('prompt_on_open', v === 'yes')
                            }
                            options={[
                                { value: 'yes', label: 'Yes' },
                                { value: 'no', label: 'No' },
                            ]}
                        />
                    </Field>
                </div>
            </form>
        </MedsWizardDialog>
    );
}

// ── Verify order ───────────────────────────────────────────────────────────
function VerifyOrderDialog({
    orders,
    onClose,
}: {
    orders: AwaitingOrder[];
    onClose: () => void;
}) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const form = useForm({ rejection_reason: '' });

    const verify = (id: number) =>
        form.post(`/emar/medications/${id}/verify`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    const reject = (e: React.FormEvent) => {
        e.preventDefault();
        if (rejectId)
            form.post(`/emar/medications/${rejectId}/reject`, {
                preserveScroll: true,
                onSuccess: onClose,
            });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Verify orders"
            description="Verify or reject medication orders awaiting pharmacy sign-off"
            railIcon={ShieldCheck}
            railTitle="Order verification"
            railSubtitle={`${orders.length} awaiting`}
            steps={[
                {
                    key: 'verify',
                    label: 'Verify',
                    blurb: 'Approve or reject',
                    icon: ShieldCheck,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <StepHead
                icon={ShieldCheck}
                title="Awaiting verification"
                blurb="Unverified orders cannot be administered."
            />
            {orders.length === 0 ? (
                <InfoCard icon={ShieldCheck}>
                    No orders are awaiting verification.
                </InfoCard>
            ) : (
                <ul className="flex flex-col gap-2">
                    {orders.map((order) => (
                        <li key={order.id} className="rounded-lg border p-3">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <div className="text-sm font-medium">
                                        {order.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {order.dosage}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setRejectId(
                                                rejectId === order.id
                                                    ? null
                                                    : order.id,
                                            )
                                        }
                                    >
                                        Reject
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={form.processing}
                                        onClick={() => verify(order.id)}
                                    >
                                        Verify
                                    </Button>
                                </div>
                            </div>
                            {rejectId === order.id && (
                                <form
                                    onSubmit={reject}
                                    className="mt-2 flex items-center gap-2"
                                >
                                    <Input
                                        value={form.data.rejection_reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'rejection_reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Reason for rejection"
                                    />
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        disabled={form.processing}
                                    >
                                        Confirm
                                    </Button>
                                </form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </MedsWizardDialog>
    );
}

// ── Review corrections ─────────────────────────────────────────────────────
function CorrectionsReviewDialog({
    corrections,
    onClose,
}: {
    corrections: PendingCorrection[];
    onClose: () => void;
}) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const form = useForm({ reason: '' });

    const approve = (id: number) =>
        form.post(`/emar/corrections/${id}/approve`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    const reject = (e: React.FormEvent) => {
        e.preventDefault();
        if (rejectId)
            form.post(`/emar/corrections/${rejectId}/reject`, {
                preserveScroll: true,
                onSuccess: onClose,
            });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Review corrections"
            description="Approve or reject pending corrections to recorded administrations"
            railIcon={ClipboardCheck}
            railTitle="Corrections"
            railSubtitle={`${corrections.length} pending`}
            steps={[
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Approve or reject',
                    icon: ClipboardCheck,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <StepHead
                icon={ClipboardCheck}
                title="Pending corrections"
                blurb="An approved correction supersedes the original record; both are kept in the audit trail."
            />
            {corrections.length === 0 ? (
                <InfoCard icon={ClipboardCheck}>
                    No corrections are pending review.
                </InfoCard>
            ) : (
                <ul className="flex flex-col gap-2">
                    {corrections.map((correction) => (
                        <li
                            key={correction.id}
                            className="rounded-lg border p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="text-sm font-medium">
                                        {correction.medication_name}
                                        <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                            {correction.status}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        {[
                                            correction.dose_given,
                                            correction.submitted_by
                                                ? `by ${correction.submitted_by}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                    {correction.correction_reason && (
                                        <div className="mt-1 text-[11.5px] text-muted-foreground italic">
                                            “{correction.correction_reason}”
                                        </div>
                                    )}
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setRejectId(
                                                rejectId === correction.id
                                                    ? null
                                                    : correction.id,
                                            )
                                        }
                                    >
                                        Reject
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={form.processing}
                                        onClick={() => approve(correction.id)}
                                    >
                                        Approve
                                    </Button>
                                </div>
                            </div>
                            {rejectId === correction.id && (
                                <form
                                    onSubmit={reject}
                                    className="mt-2 flex items-center gap-2"
                                >
                                    <Input
                                        value={form.data.reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Reason for rejection (required)"
                                    />
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        disabled={
                                            form.processing ||
                                            !form.data.reason.trim()
                                        }
                                    >
                                        Confirm
                                    </Button>
                                </form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </MedsWizardDialog>
    );
}

// ── Chart warnings prompt ──────────────────────────────────────────────────
function WarningsDialog({
    alerts,
    onClose,
}: {
    alerts: AttentionAlert[];
    onClose: () => void;
}) {
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Chart warnings"
            description="Review active chart warnings before administering"
            railIcon={AlertTriangle}
            railTitle="Chart warnings"
            railSubtitle={`${alerts.length} active`}
            steps={[
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Acknowledge warnings',
                    icon: AlertTriangle,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button type="button" onClick={onClose}>
                    Acknowledge &amp; continue
                </Button>
            }
        >
            <StepHead
                icon={AlertTriangle}
                title="Active warnings"
                blurb="Review these before recording any dose."
            />
            <div className="flex flex-col gap-2">
                {alerts.map((alert) => (
                    <InfoCard
                        key={alert.id}
                        icon={AlertTriangle}
                        tone={alert.type === 'warfarin' ? 'crit' : 'warn'}
                    >
                        <span className="font-medium">{alert.title}</span>
                        {alert.detail ? (
                            <span className="block text-xs text-muted-foreground">
                                {alert.detail}
                            </span>
                        ) : null}
                    </InfoCard>
                ))}
            </div>
        </MedsWizardDialog>
    );
}
