/* Add medication — BUILD-NEW modal on the shared Add-Client wizard chrome.
 * Posts to emar.medications.store (EmarController@storeMedication). 4 steps:
 * Medication · Schedule · Safety & supply · Review. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    Info,
    Pill,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import type { ClientOption } from './report-error-modal';

const STEPS = [
    { key: 'med', label: 'Medication', blurb: 'Drug & dose', icon: Pill },
    { key: 'schedule', label: 'Schedule', blurb: 'Frequency & start', icon: CalendarClock },
    { key: 'safety', label: 'Safety & supply', blurb: 'Controls & risk', icon: ShieldAlert },
    { key: 'review', label: 'Review', blurb: 'Confirm & add', icon: ClipboardCheck },
];

const FORMS = ['Tablet', 'Capsule', 'Liquid', 'Injection', 'Patch', 'Cream/ointment', 'Inhaler', 'Drops', 'Other'].map((v) => ({ value: v, label: v }));
const ROUTES = ['Oral', 'Topical', 'Subcutaneous', 'Intramuscular', 'Inhaled', 'Sublingual', 'Rectal', 'Other'].map((v) => ({ value: v, label: v }));
const FREQUENCIES = ['Once daily', 'Twice daily', 'Three times daily', 'Four times daily', 'At night', 'In the morning', 'Weekly', 'As required (PRN)'].map((v) => ({ value: v, label: v }));

export function AddMedicationModal({
    open,
    onClose,
    clients,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [clientId, setClientId] = useState('');
    const [name, setName] = useState('');
    const [brand, setBrand] = useState('');
    const [dose, setDose] = useState('');
    const [form, setForm] = useState('');
    const [route, setRoute] = useState('');
    const [frequency, setFrequency] = useState('');
    const [isPrn, setIsPrn] = useState<'no' | 'yes'>('no');
    const [prnReason, setPrnReason] = useState('');
    const [startDate, setStartDate] = useState('');
    const [controlled, setControlled] = useState<'no' | 'yes'>('no');
    const [witness, setWitness] = useState<'no' | 'yes'>('no');
    const [highRisk, setHighRisk] = useState<'no' | 'yes'>('no');
    const [indication, setIndication] = useState('');
    const [instructions, setInstructions] = useState('');

    const reset = () => {
        setStep(0);
        setClientId('');
        setName('');
        setBrand('');
        setDose('');
        setForm('');
        setRoute('');
        setFrequency('');
        setIsPrn('no');
        setPrnReason('');
        setStartDate('');
        setControlled('no');
        setWitness('no');
        setHighRisk('no');
        setIndication('');
        setInstructions('');
    };

    const close = () => {
        reset();
        onClose();
    };

    const step1Ok = clientId && name.trim() && dose.trim();
    const step2Ok = frequency.trim().length > 0;

    const submit = () => {
        setSaving(true);
        router.post(
            '/emar/medications',
            {
                client_id: Number(clientId),
                medication_name: name,
                brand_name: brand || null,
                dose,
                form: form || null,
                route: route || null,
                frequency,
                is_prn: isPrn === 'yes',
                prn_reason: isPrn === 'yes' ? prnReason || null : null,
                start_date: startDate || null,
                controlled_drug: controlled === 'yes',
                witness_required: witness === 'yes' || controlled === 'yes',
                high_risk: highRisk === 'yes',
                indication: indication || null,
                instructions: instructions || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Medication added to the chart');
                    close();
                },
                onError: () => toast.error('Could not add the medication'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const clientName = clients.find((c) => String(c.id) === clientId)?.name ?? '—';

    const footer = (
        <>
            <Button variant="ghost" onClick={step === 0 ? close : () => setStep((s) => s - 1)} disabled={saving}>
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step < 3 ? (
                <Button
                    onClick={() => setStep((s) => s + 1)}
                    disabled={(step === 0 && !step1Ok) || (step === 1 && !step2Ok)}
                >
                    Continue
                </Button>
            ) : (
                <Button onClick={submit} disabled={saving}>
                    <Pill className="h-4 w-4" />
                    {saving ? 'Adding…' : 'Add to medication chart'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Add a medication"
            description="Add a new medication to a client's chart."
            railIcon={Pill}
            railTitle="Add medication"
            railSubtitle="New chart entry"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead icon={Pill} title="Medication" blurb="Who is it for, and what is it?" />
                    <Field label="Client" required>
                        <SelectInput
                            value={clientId}
                            onChange={setClientId}
                            placeholder="Select client"
                            options={clients.map((c) => ({ value: String(c.id), label: c.site ? `${c.name} · ${c.site}` : c.name }))}
                        />
                    </Field>
                    <Field label="Medication name" required>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Clozapine" />
                    </Field>
                    <Field label="Brand name">
                        <Input value={brand} onChange={(e) => setBrand(e.target.value)} placeholder="Optional" />
                    </Field>
                    <Field label="Dose" required hint="e.g. 200 mg">
                        <Input value={dose} onChange={(e) => setDose(e.target.value)} placeholder="200 mg" />
                    </Field>
                    <Field label="Form">
                        <SelectInput value={form} onChange={setForm} placeholder="Select form" options={FORMS} />
                    </Field>
                    <Field label="Route">
                        <SelectInput value={route} onChange={setRoute} placeholder="Select route" options={ROUTES} />
                    </Field>
                </div>
            ) : step === 1 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead icon={CalendarClock} title="Schedule" blurb="How often, and from when?" />
                    <Field label="Frequency" required>
                        <SelectInput value={frequency} onChange={setFrequency} placeholder="Select frequency" options={FREQUENCIES} />
                    </Field>
                    <Field label="Start date">
                        {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in wizard primitives. */}
                        <input
                            type="date"
                            value={startDate}
                            onChange={(e) => setStartDate(e.target.value)}
                            className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </Field>
                    <Field label="As-required (PRN)?" span>
                        <Segmented
                            value={isPrn}
                            onChange={setIsPrn}
                            options={[
                                { value: 'no', label: 'Regular' },
                                { value: 'yes', label: 'PRN (as needed)' },
                            ]}
                        />
                    </Field>
                    {isPrn === 'yes' ? (
                        <Field label="PRN indication" span>
                            <Input value={prnReason} onChange={(e) => setPrnReason(e.target.value)} placeholder="e.g. for agitation" />
                        </Field>
                    ) : null}
                </div>
            ) : step === 2 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead icon={ShieldAlert} title="Safety & supply" blurb="Controls, witnessing and risk flags." />
                    <Field label="Controlled drug?" span>
                        <Segmented
                            value={controlled}
                            onChange={setControlled}
                            options={[
                                { value: 'no', label: 'No' },
                                { value: 'yes', label: 'Yes — controlled' },
                            ]}
                        />
                    </Field>
                    <Field label="Witness required at administration?" span>
                        <Segmented
                            value={controlled === 'yes' ? 'yes' : witness}
                            onChange={setWitness}
                            options={[
                                { value: 'no', label: 'No' },
                                { value: 'yes', label: 'Yes' },
                            ]}
                        />
                    </Field>
                    <Field label="High-risk medication?" span>
                        <Segmented
                            value={highRisk}
                            onChange={setHighRisk}
                            options={[
                                { value: 'no', label: 'No' },
                                { value: 'yes', label: 'Yes — high risk' },
                            ]}
                        />
                    </Field>
                    <Field label="Indication" span>
                        <Input value={indication} onChange={(e) => setIndication(e.target.value)} placeholder="What it's prescribed for (optional)" />
                    </Field>
                    <Field label="Administration instructions" span>
                        <Textarea value={instructions} onChange={(e) => setInstructions(e.target.value)} rows={2} placeholder="e.g. with food; check BP first (optional)" />
                    </Field>
                    {controlled === 'yes' ? (
                        <InfoCard icon={Info} tone="warn">
                            Controlled drugs require a witness and a running balance at every administration —
                            recorded through the CD register.
                        </InfoCard>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead icon={ClipboardCheck} title="Review" blurb="Confirm and add to the chart." />
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Client" value={clientName} />
                            <SummaryRow label="Medication" value={`${name}${brand ? ` (${brand})` : ''} ${dose}`} />
                            <SummaryRow label="Form / route" value={[form, route].filter(Boolean).join(' · ') || '—'} />
                            <SummaryRow label="Frequency" value={`${frequency}${isPrn === 'yes' ? ' · PRN' : ''}`} />
                            <SummaryRow label="Controlled" value={controlled === 'yes' ? 'Yes' : 'No'} tone={controlled === 'yes' ? 'crit' : undefined} />
                            <SummaryRow label="High risk" value={highRisk === 'yes' ? 'Yes' : 'No'} />
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        Adding creates the chart entry with an audit record. Scheduled doses appear on the
                        MAR once round times are generated.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default AddMedicationModal;
