/* eslint-disable no-restricted-syntax -- summary/detail panes are custom-layout
   bordered surfaces inside the wizard, not Card components; all colours are tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { ChipMulti, Field, InfoCard, SelectInput, Segmented, StepHead, TilePicker } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ClientOption, MedOption, PrescriptionOrder, StaffOption } from '@/components/emar/prescriptions/types';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, Link2, Package, PenTool, Pill, ShieldCheck, Stethoscope } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

function CheckRow({ checked, label, hint, onChange }: { checked: boolean; label: string; hint?: string; onChange: (v: boolean) => void }) {
    return (
        <label className="flex items-start gap-2.5 rounded-lg border bg-background px-3 py-2.5 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 h-4 w-4" />
            <span>
                <span className="font-medium">{label}</span>
                {hint && <span className="block text-xs text-muted-foreground">{hint}</span>}
            </span>
        </label>
    );
}

const VERBAL = ['verbal', 'telephone'];

// ── New / changed order (4-step) ─────────────────────────────────────────────
export function NewOrderDialog({ clients, staff, onClose }: { clients: ClientOption[]; staff: StaffOption[]; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        client_id: '',
        order_type: 'new',
        order_date: '',
        prescriber_name: '',
        prescriber_registration: '',
        prescriber_type: '',
        medication_name: '',
        dose: '',
        route: '',
        frequency: '',
        indication: '',
        instructions: '',
        effective_date: '',
        expiry_date: '',
        read_back_confirmed: false,
        read_back_witnessed_by: '',
    });
    const isVerbal = VERBAL.includes(form.data.order_type);

    const submit = () => {
        form.transform((d) => ({ ...d, read_back_witnessed_by: d.read_back_witnessed_by ? Number(d.read_back_witnessed_by) : null }));
        form.post('/emar/prescriptions', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isVerbal ? 'Order recorded — awaiting prescriber countersignature.' : 'Prescriber order recorded.');
                onClose();
            },
            onError: () => toast.error('Please check the order details'),
        });
    };

    const valid = [
        !!form.data.client_id && !!form.data.order_date,
        !!form.data.prescriber_name,
        !!form.data.medication_name && !!form.data.dose && !!form.data.route && !!form.data.frequency,
        !isVerbal || form.data.read_back_confirmed,
    ];

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="New prescriber order"
            description="Record a prescriber order for a resident."
            railIcon={FileText}
            railTitle="Prescriber order"
            railSubtitle="New / changed order"
            steps={[
                { key: 'context', label: 'Order context', blurb: 'Type & resident', icon: FileText },
                { key: 'prescriber', label: 'Prescriber', blurb: 'Who ordered', icon: Stethoscope },
                { key: 'med', label: 'Medication', blurb: 'Dose & route', icon: Pill },
                { key: 'review', label: 'Read-back & review', blurb: 'Confirm', icon: PenTool },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>
                        {step === 0 ? 'Cancel' : 'Back'}
                    </Button>
                    {step < 3 ? (
                        <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>
                            Continue
                        </Button>
                    ) : (
                        <Button onClick={submit} disabled={!valid[3] || form.processing}>
                            Record order
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 && (
                <>
                    <StepHead icon={FileText} title="Order context" blurb="What kind of order, and for whom." />
                    <Field label="Order type" span>
                        <TilePicker
                            value={form.data.order_type}
                            onChange={(v) => form.setData('order_type', v)}
                            cols={3}
                            options={[
                                { key: 'new', label: 'New', icon: FileText },
                                { key: 'change', label: 'Change', icon: PenTool },
                                { key: 'cease', label: 'Cease', icon: AlertTriangle },
                                { key: 'verbal', label: 'Verbal', icon: Stethoscope },
                                { key: 'telephone', label: 'Telephone', icon: Stethoscope },
                            ]}
                        />
                    </Field>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Resident" required error={form.errors.client_id}>
                            <SelectInput value={form.data.client_id} onChange={(v) => form.setData('client_id', v)} placeholder="Select resident…" options={clients.map((c) => ({ value: String(c.id), label: `${c.last_name}, ${c.first_name}` }))} />
                        </Field>
                        <Field label="Order date" required error={form.errors.order_date}>
                            <Input type="date" value={form.data.order_date} onChange={(e) => form.setData('order_date', e.target.value)} />
                        </Field>
                    </div>
                    {isVerbal && (
                        <div className="mt-3">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Verbal &amp; telephone orders must be countersigned by the prescriber within <strong>24 hours</strong>. You&apos;ll record the read-back at the final step.
                            </InfoCard>
                        </div>
                    )}
                </>
            )}

            {step === 1 && (
                <>
                    <StepHead icon={Stethoscope} title="Prescriber" blurb="Who authorised this order." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Prescriber name" required span error={form.errors.prescriber_name}>
                            <Input value={form.data.prescriber_name} onChange={(e) => form.setData('prescriber_name', e.target.value)} placeholder="e.g. Dr Singh" />
                        </Field>
                        <Field label="Registration (MCNZ)">
                            <Input value={form.data.prescriber_registration} onChange={(e) => form.setData('prescriber_registration', e.target.value)} placeholder="Optional" />
                        </Field>
                        <Field label="Prescriber type">
                            <SelectInput value={form.data.prescriber_type} onChange={(v) => form.setData('prescriber_type', v)} placeholder="Select…" options={[{ value: 'gp', label: 'GP' }, { value: 'specialist', label: 'Specialist' }, { value: 'nurse_practitioner', label: 'Nurse practitioner' }]} />
                        </Field>
                    </div>
                </>
            )}

            {step === 2 && (
                <>
                    <StepHead icon={Pill} title="Medication & dosing" blurb="What was ordered." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Medication" required span error={form.errors.medication_name}>
                            <Input value={form.data.medication_name} onChange={(e) => form.setData('medication_name', e.target.value)} placeholder="Generic name & strength" />
                        </Field>
                        <Field label="Dose" required error={form.errors.dose}>
                            <Input value={form.data.dose} onChange={(e) => form.setData('dose', e.target.value)} placeholder="e.g. 500mg" />
                        </Field>
                        <Field label="Route" required error={form.errors.route}>
                            <Input value={form.data.route} onChange={(e) => form.setData('route', e.target.value)} placeholder="Oral / IM" />
                        </Field>
                        <Field label="Frequency" required error={form.errors.frequency}>
                            <Input value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} placeholder="e.g. Twice daily" />
                        </Field>
                        <Field label="Indication">
                            <Input value={form.data.indication} onChange={(e) => form.setData('indication', e.target.value)} placeholder="Reason" />
                        </Field>
                        <Field label="Instructions" span>
                            <Input value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} placeholder="Administration notes" />
                        </Field>
                        <Field label="Effective date">
                            <Input type="date" value={form.data.effective_date} onChange={(e) => form.setData('effective_date', e.target.value)} />
                        </Field>
                        <Field label="Expiry date">
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                        </Field>
                    </div>
                </>
            )}

            {step === 3 && (
                <>
                    <StepHead icon={PenTool} title="Read-back & review" blurb={isVerbal ? 'Confirm the read-back, then review.' : 'Review the order.'} />
                    {isVerbal && (
                        <div className="mb-4 flex flex-col gap-3">
                            <CheckRow checked={form.data.read_back_confirmed} label="Read-back confirmed" hint="The order was read back to the prescriber and confirmed correct." onChange={(v) => form.setData('read_back_confirmed', v)} />
                            <Field label="Read-back witness">
                                <SelectInput value={form.data.read_back_witnessed_by} onChange={(v) => form.setData('read_back_witnessed_by', v)} placeholder="Select witness…" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                            </Field>
                            <InfoCard icon={AlertTriangle} tone="warn">This order will await prescriber countersignature within 24 hours.</InfoCard>
                        </div>
                    )}
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Type" value={form.data.order_type} />
                        <SummaryRow label="Resident" value={clients.find((c) => String(c.id) === form.data.client_id)?.last_name ?? '—'} />
                        <SummaryRow label="Medication" value={`${form.data.medication_name} ${form.data.dose}`} />
                        <SummaryRow label="Prescriber" value={form.data.prescriber_name} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Countersign (single) ─────────────────────────────────────────────────────
export function CountersignDialog({ order, onClose }: { order: PrescriptionOrder; onClose: () => void }) {
    const [method, setMethod] = useState<'in_person' | 'electronic'>('electronic');
    const [declared, setDeclared] = useState(false);
    const form = useForm({});
    const submit = () => {
        form.transform(() => ({ countersign_method: method }));
        form.post(`/emar/prescriptions/${order.id}/countersign`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Order countersigned');
                onClose();
            },
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Countersign order"
            description="Prescriber countersignature for a verbal/telephone order."
            railIcon={PenTool}
            railTitle="Countersign"
            railSubtitle={order.client_name}
            steps={[{ key: 'sign', label: 'Countersign', blurb: 'Confirm & sign', icon: PenTool }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={!declared || form.processing}>
                        Countersign
                    </Button>
                </>
            }
        >
            <StepHead icon={PenTool} title="Prescriber countersignature" blurb="Confirm the order details are correct as charted." />
            <div className="rounded-lg border px-4">
                <SummaryRow label="Resident" value={order.client_name} />
                <SummaryRow label="Medication" value={`${order.medication_name} ${order.dose ?? ''}`} />
                <SummaryRow label="Prescriber" value={`${order.prescriber_name}${order.prescriber_registration ? ` · ${order.prescriber_registration}` : ''}`} />
                <SummaryRow label="Ordered" value={order.order_date ?? '—'} />
            </div>
            <div className="mt-4">
                <Field label="Method" span>
                    <Segmented value={method} onChange={setMethod} options={[{ value: 'in_person', label: 'In person' }, { value: 'electronic', label: 'Electronic' }]} />
                </Field>
            </div>
            <div className="mt-3">
                <CheckRow checked={declared} label="I confirm this order as the prescriber (or on their authority)." onChange={setDeclared} />
            </div>
        </MedsWizardDialog>
    );
}

// ── Record dispensing (single) ───────────────────────────────────────────────
export function DispenseDialog({ order, staff, onClose }: { order: PrescriptionOrder; staff: StaffOption[]; onClose: () => void }) {
    const form = useForm({
        status: 'dispensed',
        pharmacy_name: '',
        batch_number: '',
        batch_expiry: '',
        dispensed_by: '',
        dispensed_at: '',
        pharmacy_notes: '',
    });
    const submit = () => {
        form.transform((d) => ({ ...d, dispensed_by: d.dispensed_by ? Number(d.dispensed_by) : null }));
        form.put(`/emar/prescriptions/${order.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Dispensing recorded');
                onClose();
            },
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Record dispensing"
            description="Record the pharmacy dispense for this order."
            railIcon={Package}
            railTitle="Dispensing"
            railSubtitle={order.client_name}
            steps={[{ key: 'dispense', label: 'Dispense', blurb: 'Pharmacy & batch', icon: Package }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        Record dispensing
                    </Button>
                </>
            }
        >
            <StepHead icon={Package} title="Dispensing" blurb={`${order.medication_name} · ${order.client_name}`} />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Pharmacy" span>
                    <Input value={form.data.pharmacy_name} onChange={(e) => form.setData('pharmacy_name', e.target.value)} placeholder="e.g. Community Pharmacy" />
                </Field>
                <Field label="Batch number">
                    <Input value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} />
                </Field>
                <Field label="Batch expiry">
                    <Input type="date" value={form.data.batch_expiry} onChange={(e) => form.setData('batch_expiry', e.target.value)} />
                </Field>
                <Field label="Dispensed by">
                    <SelectInput value={form.data.dispensed_by} onChange={(v) => form.setData('dispensed_by', v)} placeholder="Select…" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                </Field>
                <Field label="Dispensed date">
                    <Input type="date" value={form.data.dispensed_at} onChange={(e) => form.setData('dispensed_at', e.target.value)} />
                </Field>
                <Field label="Pharmacy notes" span>
                    <Input value={form.data.pharmacy_notes} onChange={(e) => form.setData('pharmacy_notes', e.target.value)} />
                </Field>
            </div>
        </MedsWizardDialog>
    );
}

// ── Link order → MAR (single) ────────────────────────────────────────────────
export function LinkMarDialog({ order, medications, onClose }: { order: PrescriptionOrder; medications: MedOption[]; onClose: () => void }) {
    const form = useForm({ client_medication_id: order.client_medication_id ? String(order.client_medication_id) : '' });
    const clientMeds = medications.filter((m) => m.client_id === order.client_id);
    const submit = () => {
        form.transform((d) => ({ client_medication_id: d.client_medication_id ? Number(d.client_medication_id) : null }));
        form.put(`/emar/prescriptions/${order.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Order linked to MAR');
                onClose();
            },
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Link order to MAR"
            description="Link this order to a charted medication."
            railIcon={Link2}
            railTitle="Link → MAR"
            railSubtitle={order.client_name}
            steps={[{ key: 'link', label: 'Link', blurb: 'Pick medication', icon: Link2 }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={!form.data.client_medication_id || form.processing}>
                        Link order
                    </Button>
                </>
            }
        >
            <StepHead icon={Link2} title="Link to charted medication" blurb="Connect this prescriber order to a medication on the resident's MAR." />
            {clientMeds.length === 0 ? (
                <InfoCard icon={AlertTriangle} tone="warn">This resident has no charted medications yet. Add the medication on the Medications page first.</InfoCard>
            ) : (
                <Field label="Charted medication" required>
                    <SelectInput value={form.data.client_medication_id} onChange={(v) => form.setData('client_medication_id', v)} placeholder="Select medication…" options={clientMeds.map((m) => ({ value: String(m.id), label: m.name }))} />
                </Field>
            )}
        </MedsWizardDialog>
    );
}

const MDT_OPTIONS = ['Prescriber', 'Pharmacist', 'Family / whānau', 'Welfare guardian', 'Advocate', 'Care manager'];

// ── Covert authorisation (4-step) ────────────────────────────────────────────
export function CovertDialog({ clients, medications, onClose }: { clients: ClientOption[]; medications: MedOption[]; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        client_id: '',
        client_medication_id: '',
        lacks_capacity: 'yes',
        capacity_note: '',
        mdt: [] as string[],
        rationale: '',
        least_restrictive: '',
        administration_method: '',
        pharmacist_confirmed: 'yes',
        pharmacist_advice: '',
        offered_overtly: false,
        authorised_by_name: '',
        authorised_by_registration: '',
        authorised_date: '',
        review_date: '',
    });
    const clientMeds = medications.filter((m) => m.client_id === Number(form.data.client_id));

    const submit = () => {
        form.transform((d) => ({
            client_id: Number(d.client_id),
            client_medication_id: Number(d.client_medication_id),
            authorised_by_name: d.authorised_by_name,
            authorised_by_registration: d.authorised_by_registration,
            clinical_justification: [d.rationale, d.capacity_note ? `Capacity: ${d.capacity_note}` : '', d.least_restrictive ? `Least-restrictive options: ${d.least_restrictive}` : ''].filter(Boolean).join('\n'),
            legal_basis: `Best interests (PPPR Act). Capacity: ${d.lacks_capacity === 'yes' ? 'lacks capacity' : 'has capacity'}. MDT consulted: ${d.mdt.join(', ') || 'none'}.${d.offered_overtly ? ' Offered overtly first.' : ''}`,
            administration_method: d.administration_method,
            pharmacist_advice: [d.pharmacist_confirmed === 'yes' ? 'Pharmacist confirmed.' : '', d.pharmacist_advice].filter(Boolean).join(' '),
            authorised_date: d.authorised_date,
            review_date: d.review_date,
        }));
        form.post('/emar/prescriptions/covert', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Covert authorisation recorded');
                onClose();
            },
            onError: () => toast.error('Please check the authorisation details'),
        });
    };

    const valid = [
        !!form.data.client_id && !!form.data.client_medication_id,
        !!form.data.rationale,
        !!form.data.administration_method,
        !!form.data.authorised_by_name && !!form.data.authorised_date && !!form.data.review_date,
    ];

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Covert authorisation"
            description="Authorise covert (disguised) administration under a best-interest process."
            railIcon={ShieldCheck}
            railTitle="Covert authorisation"
            railSubtitle="Best-interest process"
            steps={[
                { key: 'capacity', label: 'Capacity', blurb: 'Assessment', icon: ShieldCheck },
                { key: 'mdt', label: 'Best interest', blurb: 'MDT decision', icon: Stethoscope },
                { key: 'method', label: 'Med & method', blurb: 'How given', icon: Pill },
                { key: 'review', label: 'Authorise', blurb: 'Sign & review', icon: PenTool },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>
                        {step === 0 ? 'Cancel' : 'Back'}
                    </Button>
                    {step < 3 ? (
                        <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>
                            Continue
                        </Button>
                    ) : (
                        <Button onClick={submit} disabled={!valid[3] || form.processing}>
                            Authorise
                        </Button>
                    )}
                </>
            }
        >
            <div className="mb-3">
                <InfoCard icon={AlertTriangle} tone="crit">
                    Covert administration is a restrictive practice. It requires a capacity assessment, a best-interest MDT decision, pharmacist advice, and a review date — and the medication must be offered overtly first.
                </InfoCard>
            </div>

            {step === 0 && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Resident" required error={form.errors.client_id}>
                        <SelectInput value={form.data.client_id} onChange={(v) => { form.setData('client_id', v); form.setData('client_medication_id', ''); }} placeholder="Select resident…" options={clients.map((c) => ({ value: String(c.id), label: `${c.last_name}, ${c.first_name}` }))} />
                    </Field>
                    <Field label="Medication" required error={form.errors.client_medication_id}>
                        <SelectInput value={form.data.client_medication_id} onChange={(v) => form.setData('client_medication_id', v)} placeholder={form.data.client_id ? 'Select medication…' : 'Pick a resident first'} options={clientMeds.map((m) => ({ value: String(m.id), label: m.name }))} />
                    </Field>
                    <Field label="Lacks capacity for this decision" span>
                        <Segmented value={form.data.lacks_capacity} onChange={(v) => form.setData('lacks_capacity', v)} options={[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }]} />
                    </Field>
                    <Field label="Capacity assessment note" span>
                        <Input value={form.data.capacity_note} onChange={(e) => form.setData('capacity_note', e.target.value)} placeholder="Assessor & date" />
                    </Field>
                </div>
            )}

            {step === 1 && (
                <>
                    <Field label="MDT consulted" span>
                        <ChipMulti values={form.data.mdt} onChange={(v) => form.setData('mdt', v)} options={MDT_OPTIONS} />
                    </Field>
                    <div className="mt-3 grid grid-cols-1 gap-4">
                        <Field label="Best-interest rationale" required>
                            <Input value={form.data.rationale} onChange={(e) => form.setData('rationale', e.target.value)} placeholder="Why covert administration is in the resident's best interest" />
                        </Field>
                        <Field label="Least-restrictive options considered">
                            <Input value={form.data.least_restrictive} onChange={(e) => form.setData('least_restrictive', e.target.value)} placeholder="What else was tried" />
                        </Field>
                    </div>
                </>
            )}

            {step === 2 && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Administration method" required span error={form.errors.administration_method}>
                        <Input value={form.data.administration_method} onChange={(e) => form.setData('administration_method', e.target.value)} placeholder="e.g. crushed in yoghurt" />
                    </Field>
                    <Field label="Pharmacist confirmed safe to disguise" span>
                        <Segmented value={form.data.pharmacist_confirmed} onChange={(v) => form.setData('pharmacist_confirmed', v)} options={[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }]} />
                    </Field>
                    <Field label="Pharmacist advice" span>
                        <Input value={form.data.pharmacist_advice} onChange={(e) => form.setData('pharmacist_advice', e.target.value)} placeholder="Advice on covert administration" />
                    </Field>
                    <Field label="Offered overtly first" span>
                        <CheckRow checked={form.data.offered_overtly} label="The medication was offered openly before covert administration." onChange={(v) => form.setData('offered_overtly', v)} />
                    </Field>
                </div>
            )}

            {step === 3 && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Authorising prescriber" required span error={form.errors.authorised_by_name}>
                        <Input value={form.data.authorised_by_name} onChange={(e) => form.setData('authorised_by_name', e.target.value)} placeholder="e.g. Dr Singh" />
                    </Field>
                    <Field label="Registration">
                        <Input value={form.data.authorised_by_registration} onChange={(e) => form.setData('authorised_by_registration', e.target.value)} />
                    </Field>
                    <Field label="Authorised date" required error={form.errors.authorised_date}>
                        <Input type="date" value={form.data.authorised_date} onChange={(e) => form.setData('authorised_date', e.target.value)} />
                    </Field>
                    <Field label="Next review" required error={form.errors.review_date}>
                        <Input type="date" value={form.data.review_date} onChange={(e) => form.setData('review_date', e.target.value)} />
                    </Field>
                </div>
            )}
        </MedsWizardDialog>
    );
}
