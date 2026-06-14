import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Field, InfoCard, SelectInput, Segmented, StepHead, TilePicker } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { WitnessOption } from '@/pages/meds/today/types';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, HeartPulse, Pill, ShieldCheck, Syringe } from 'lucide-react';
import { useState } from 'react';

export type MarModal = 'addMed' | 'inr' | 'syringe' | 'alerts' | 'verify' | 'warnings' | null;

type AttentionAlert = { id: number; type: string; title: string; detail?: string | null; prompt_on_open: boolean };
type AwaitingOrder = { id: number; name: string; dosage: string };

type Props = {
    modal: MarModal;
    onClose: () => void;
    clientId: number;
    attentionAlerts: AttentionAlert[];
    awaitingVerification: AwaitingOrder[];
    witnesses: WitnessOption[];
};

function FooterRow({ onCancel, submitLabel, processing, onBack }: { onCancel: () => void; submitLabel: string; processing: boolean; onBack?: () => void }) {
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

export default function MarGovernanceDialogs({ modal, onClose, clientId, attentionAlerts, awaitingVerification, witnesses }: Props) {
    return (
        <>
            {modal === 'addMed' && <AddMedicationDialog clientId={clientId} onClose={onClose} />}
            {modal === 'inr' && <RecordInrDialog clientId={clientId} onClose={onClose} />}
            {modal === 'syringe' && <SyringeDriverDialog clientId={clientId} witnesses={witnesses} onClose={onClose} />}
            {modal === 'alerts' && <ManageAlertsDialog clientId={clientId} onClose={onClose} />}
            {modal === 'verify' && <VerifyOrderDialog orders={awaitingVerification} onClose={onClose} />}
            {modal === 'warnings' && <WarningsDialog alerts={attentionAlerts} onClose={onClose} />}
        </>
    );
}

// ── Add medication ─────────────────────────────────────────────────────────
function AddMedicationDialog({ clientId, onClose }: { clientId: number; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        client_id: clientId,
        medication_name: '',
        dose: '',
        frequency: '',
        route: '',
        form: '',
        instructions: '',
        is_prn: false,
        prn_reason: '',
        max_per_day: '',
        controlled_drug: false,
        high_risk: false,
        witness_required: false,
        pharmac_therapeutic_group: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/emar/medications', { preserveScroll: true, onSuccess: onClose });
    };

    const canContinue = form.data.medication_name && form.data.dose && form.data.frequency;

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Add medication"
            description="Add a new medication order to this resident's chart"
            railIcon={Pill}
            railTitle="Add medication"
            railSubtitle="New order"
            steps={[
                { key: 'details', label: 'Details', blurb: 'Drug, dose & route', icon: Pill },
                { key: 'class', label: 'Classification', blurb: 'PRN & flags', icon: ShieldCheck },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <form onSubmit={submit} className="contents">
                    {step === 0 ? (
                        <>
                            <Button type="button" variant="ghost" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="button" disabled={!canContinue} onClick={() => setStep(1)}>
                                Continue
                            </Button>
                        </>
                    ) : (
                        <FooterRow onCancel={onClose} onBack={() => setStep(0)} submitLabel="Add medication" processing={form.processing} />
                    )}
                </form>
            }
        >
            <form id="add-med-form" onSubmit={submit}>
                {step === 0 ? (
                    <>
                        <StepHead icon={Pill} title="Medication details" blurb="The new order defaults to awaiting pharmacy verification." />
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Medication name" required span error={form.errors.medication_name}>
                                <Input value={form.data.medication_name} onChange={(e) => form.setData('medication_name', e.target.value)} placeholder="e.g. Paracetamol" />
                            </Field>
                            <Field label="Dose" required error={form.errors.dose}>
                                <Input value={form.data.dose} onChange={(e) => form.setData('dose', e.target.value)} placeholder="e.g. 500mg" />
                            </Field>
                            <Field label="Frequency" required error={form.errors.frequency}>
                                <Input value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} placeholder="e.g. Twice daily" />
                            </Field>
                            <Field label="Route">
                                <Input value={form.data.route} onChange={(e) => form.setData('route', e.target.value)} placeholder="e.g. Oral" />
                            </Field>
                            <Field label="Form">
                                <Input value={form.data.form} onChange={(e) => form.setData('form', e.target.value)} placeholder="e.g. Tablet" />
                            </Field>
                            <Field label="Instructions" span>
                                <Input value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} placeholder="Administration notes" />
                            </Field>
                        </div>
                    </>
                ) : (
                    <>
                        <StepHead icon={ShieldCheck} title="Classification & schedule" blurb="Flag controlled, high-risk and PRN orders." />
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Type" span>
                                <Segmented
                                    value={form.data.is_prn ? 'prn' : 'scheduled'}
                                    onChange={(v) => form.setData('is_prn', v === 'prn')}
                                    options={[
                                        { value: 'scheduled', label: 'Scheduled' },
                                        { value: 'prn', label: 'PRN / as-required' },
                                    ]}
                                />
                            </Field>
                            {form.data.is_prn && (
                                <>
                                    <Field label="PRN reason">
                                        <Input value={form.data.prn_reason} onChange={(e) => form.setData('prn_reason', e.target.value)} placeholder="e.g. Pain" />
                                    </Field>
                                    <Field label="Max per day">
                                        <Input type="number" value={form.data.max_per_day} onChange={(e) => form.setData('max_per_day', e.target.value)} placeholder="e.g. 4" />
                                    </Field>
                                </>
                            )}
                            <Field label="Pharmac therapeutic group" span>
                                <Input value={form.data.pharmac_therapeutic_group} onChange={(e) => form.setData('pharmac_therapeutic_group', e.target.value)} placeholder="Optional" />
                            </Field>
                            <Field label="Flags" span>
                                <div className="flex flex-wrap gap-2">
                                    <ToggleChip active={form.data.controlled_drug} label="Controlled drug" onClick={() => form.setData('controlled_drug', !form.data.controlled_drug)} />
                                    <ToggleChip active={form.data.high_risk} label="High-risk" onClick={() => form.setData('high_risk', !form.data.high_risk)} />
                                    <ToggleChip active={form.data.witness_required} label="Witness required" onClick={() => form.setData('witness_required', !form.data.witness_required)} />
                                </div>
                            </Field>
                        </div>
                    </>
                )}
            </form>
        </MedsWizardDialog>
    );
}

function ToggleChip({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact flag toggle chip (custom pill, not a <Button>)
        <button
            type="button"
            onClick={onClick}
            className={
                active
                    ? 'rounded-full border border-primary bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary'
                    : 'rounded-full border px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-accent'
            }
        >
            {label}
        </button>
    );
}

// ── Record INR ─────────────────────────────────────────────────────────────
function RecordInrDialog({ clientId, onClose }: { clientId: number; onClose: () => void }) {
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
        form.post(`/emar/clients/${clientId}/inr`, { preserveScroll: true, onSuccess: onClose });
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
            steps={[{ key: 'result', label: 'Result', blurb: 'Value & schedule', icon: HeartPulse }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow onCancel={onClose} submitLabel="Record INR" processing={form.processing} />
                </form>
            }
        >
            <form onSubmit={submit}>
                <StepHead icon={HeartPulse} title="INR result" blurb="Results are retained — disable, never delete." />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="INR value" required error={form.errors.inr_value}>
                        <Input type="number" step="0.1" value={form.data.inr_value} onChange={(e) => form.setData('inr_value', e.target.value)} placeholder="e.g. 2.4" />
                    </Field>
                    <Field label="Tested on" required error={form.errors.tested_on}>
                        <Input type="date" value={form.data.tested_on} onChange={(e) => form.setData('tested_on', e.target.value)} />
                    </Field>
                    <Field label="Target range (low)">
                        <Input type="number" step="0.1" value={form.data.target_range_low} onChange={(e) => form.setData('target_range_low', e.target.value)} placeholder="2.0" />
                    </Field>
                    <Field label="Target range (high)" error={form.errors.target_range_high}>
                        <Input type="number" step="0.1" value={form.data.target_range_high} onChange={(e) => form.setData('target_range_high', e.target.value)} placeholder="3.0" />
                    </Field>
                    <Field label="Dose (mg)">
                        <Input type="number" step="0.01" value={form.data.dose_mg} onChange={(e) => form.setData('dose_mg', e.target.value)} placeholder="e.g. 5" />
                    </Field>
                    <Field label="Next test date" error={form.errors.next_test_date}>
                        <Input type="date" value={form.data.next_test_date} onChange={(e) => form.setData('next_test_date', e.target.value)} />
                    </Field>
                    <Field label="Notes" span>
                        <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Optional" />
                    </Field>
                </div>
            </form>
        </MedsWizardDialog>
    );
}

// ── Start syringe driver ───────────────────────────────────────────────────
function SyringeDriverDialog({ clientId, witnesses, onClose }: { clientId: number; witnesses: WitnessOption[]; onClose: () => void }) {
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

    const setContent = (patch: Partial<typeof content>) => form.setData('contents', [{ ...content, ...patch }]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, witnessed_by: data.witnessed_by ? Number(data.witnessed_by) : null }));
        form.post(`/emar/clients/${clientId}/syringe-drivers`, { preserveScroll: true, onSuccess: onClose });
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
            steps={[{ key: 'driver', label: 'Driver', blurb: 'Contents & rate', icon: Syringe }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow onCancel={onClose} submitLabel="Start driver" processing={form.processing} />
                </form>
            }
        >
            <form onSubmit={submit}>
                <StepHead icon={Syringe} title="Commence syringe driver" blurb="Controlled-drug contents require a witness countersignature." />
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Medication" required>
                        <Input value={content.name} onChange={(e) => setContent({ name: e.target.value })} placeholder="e.g. Morphine sulfate" />
                    </Field>
                    <Field label="Dose">
                        <Input value={content.dose} onChange={(e) => setContent({ dose: e.target.value })} placeholder="e.g. 10" />
                    </Field>
                    <Field label="Commenced at" required error={form.errors.commenced_at}>
                        <Input type="datetime-local" value={form.data.commenced_at} onChange={(e) => form.setData('commenced_at', e.target.value)} />
                    </Field>
                    <Field label="Rate">
                        <Input value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} placeholder="e.g. 2 mL/hr" />
                    </Field>
                    <Field label="Insertion site" span>
                        <Input value={form.data.site_of_insertion} onChange={(e) => form.setData('site_of_insertion', e.target.value)} placeholder="e.g. Left upper arm" />
                    </Field>
                    <Field label="Witness required" span>
                        <Segmented
                            value={requiresWitness ? 'yes' : 'no'}
                            onChange={(v) => setContent({ requires_witness: v === 'yes' })}
                            options={[
                                { value: 'no', label: 'No' },
                                { value: 'yes', label: 'Yes (CD)' },
                            ]}
                        />
                    </Field>
                    {requiresWitness && (
                        <>
                            <Field label="Witness" error={form.errors.witnessed_by}>
                                <SelectInput
                                    value={form.data.witnessed_by}
                                    onChange={(v) => form.setData('witnessed_by', v)}
                                    placeholder="Select witness…"
                                    options={witnesses.map((w) => ({ value: String(w.id), label: w.name }))}
                                />
                            </Field>
                            <Field label="Witness password / PIN" error={form.errors.witness_credential}>
                                <Input type="password" value={form.data.witness_credential} onChange={(e) => form.setData('witness_credential', e.target.value)} placeholder="Re-authenticate" />
                            </Field>
                        </>
                    )}
                </div>
            </form>
        </MedsWizardDialog>
    );
}

// ── Manage attention alerts ────────────────────────────────────────────────
function ManageAlertsDialog({ clientId, onClose }: { clientId: number; onClose: () => void }) {
    const form = useForm({ type: 'warfarin', title: '', detail: '', prompt_on_open: true });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/emar/clients/${clientId}/attention-alerts`, { preserveScroll: true, onSuccess: onClose });
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
            steps={[{ key: 'alert', label: 'Alert', blurb: 'Type & message', icon: AlertTriangle }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <form onSubmit={submit} className="contents">
                    <FooterRow onCancel={onClose} submitLabel="Add alert" processing={form.processing} />
                </form>
            }
        >
            <form onSubmit={submit}>
                <StepHead icon={AlertTriangle} title="Chart alert" blurb="Prompt-on-open alerts must be acknowledged before recording." />
                <Field label="Alert type" span>
                    <TilePicker
                        value={form.data.type}
                        onChange={(v) => form.setData('type', v)}
                        cols={3}
                        options={[
                            { key: 'warfarin', label: 'Warfarin', icon: HeartPulse },
                            { key: 'paper_prescription', label: 'Paper prescription', icon: FileText },
                            { key: 'chart_warning', label: 'Other', icon: AlertTriangle },
                        ]}
                    />
                </Field>
                <div className="mt-4 grid grid-cols-1 gap-4">
                    <Field label="Title" required error={form.errors.title}>
                        <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Warfarin — INR monitoring" />
                    </Field>
                    <Field label="Detail">
                        <Input value={form.data.detail} onChange={(e) => form.setData('detail', e.target.value)} placeholder="Optional detail" />
                    </Field>
                    <Field label="Prompt on chart open">
                        <Segmented
                            value={form.data.prompt_on_open ? 'yes' : 'no'}
                            onChange={(v) => form.setData('prompt_on_open', v === 'yes')}
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
function VerifyOrderDialog({ orders, onClose }: { orders: AwaitingOrder[]; onClose: () => void }) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const form = useForm({ rejection_reason: '' });

    const verify = (id: number) => form.post(`/emar/medications/${id}/verify`, { preserveScroll: true, onSuccess: onClose });
    const reject = (e: React.FormEvent) => {
        e.preventDefault();
        if (rejectId) form.post(`/emar/medications/${rejectId}/reject`, { preserveScroll: true, onSuccess: onClose });
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
            steps={[{ key: 'verify', label: 'Verify', blurb: 'Approve or reject', icon: ShieldCheck }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <StepHead icon={ShieldCheck} title="Awaiting verification" blurb="Unverified orders cannot be administered." />
            {orders.length === 0 ? (
                <InfoCard icon={ShieldCheck}>No orders are awaiting verification.</InfoCard>
            ) : (
                <ul className="flex flex-col gap-2">
                    {orders.map((order) => (
                        <li key={order.id} className="rounded-lg border p-3">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <div className="text-sm font-medium">{order.name}</div>
                                    <div className="text-xs text-muted-foreground">{order.dosage}</div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setRejectId(rejectId === order.id ? null : order.id)}>
                                        Reject
                                    </Button>
                                    <Button size="sm" disabled={form.processing} onClick={() => verify(order.id)}>
                                        Verify
                                    </Button>
                                </div>
                            </div>
                            {rejectId === order.id && (
                                <form onSubmit={reject} className="mt-2 flex items-center gap-2">
                                    <Input
                                        value={form.data.rejection_reason}
                                        onChange={(e) => form.setData('rejection_reason', e.target.value)}
                                        placeholder="Reason for rejection"
                                    />
                                    <Button type="submit" variant="destructive" size="sm" disabled={form.processing}>
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
function WarningsDialog({ alerts, onClose }: { alerts: AttentionAlert[]; onClose: () => void }) {
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Chart warnings"
            description="Review active chart warnings before administering"
            railIcon={AlertTriangle}
            railTitle="Chart warnings"
            railSubtitle={`${alerts.length} active`}
            steps={[{ key: 'review', label: 'Review', blurb: 'Acknowledge warnings', icon: AlertTriangle }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button type="button" onClick={onClose}>
                    Acknowledge &amp; continue
                </Button>
            }
        >
            <StepHead icon={AlertTriangle} title="Active warnings" blurb="Review these before recording any dose." />
            <div className="flex flex-col gap-2">
                {alerts.map((alert) => (
                    <InfoCard key={alert.id} icon={AlertTriangle} tone={alert.type === 'warfarin' ? 'crit' : 'warn'}>
                        <span className="font-medium">{alert.title}</span>
                        {alert.detail ? <span className="block text-xs text-muted-foreground">{alert.detail}</span> : null}
                    </InfoCard>
                ))}
            </div>
        </MedsWizardDialog>
    );
}
