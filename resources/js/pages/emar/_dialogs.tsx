/* eslint-disable no-restricted-syntax -- detail/instruction panes are custom-layout
   bordered surfaces inside the wizard, not Card components; all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, InfoCard, SelectInput, Segmented, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ClientOption, MedRow } from '@/components/emar/medications/types';
import { previewDoseTimes } from '@/components/emar/medications/types';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Ban, BadgeCheck, CheckCircle2, ClipboardList, FileText, FileUp, HeartPulse, Pencil, Pill, Printer, ShieldCheck, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

// ── helpers ──────────────────────────────────────────────────────────────────
function ToggleRow({ checked, label, hint, onChange }: { checked: boolean; label: string; hint?: string; onChange: (v: boolean) => void }) {
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

// ── Add medication (shared 4-step wizard; reused by MAR governance) ───────────
export function AddMedicationDialog({ clientId, clients, onClose }: { clientId?: number | null; clients?: ClientOption[]; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const presetClient = clientId ?? null;
    const form = useForm({
        client_id: presetClient ? String(presetClient) : '',
        medication_name: '',
        brand_name: '',
        dose: '',
        dose_unit: '',
        frequency: '',
        route: '',
        form: '',
        instructions: '',
        indication: '',
        prescriber: '',
        start_date: '',
        is_prn: false,
        prn_reason: '',
        max_per_day: '',
        min_hours_between_doses: '',
        controlled_drug: false,
        high_risk: false,
        witness_required: false,
        pharmac_therapeutic_group: '',
    });

    const [allergies, setAllergies] = useState<{ allergen: string; severity?: string | null }[] | null>(null);
    const activeClient = presetClient ?? (form.data.client_id ? Number(form.data.client_id) : null);

    useEffect(() => {
        if (step !== 2 || !activeClient) return;
        let cancelled = false;
        axios
            .get(`/api/medications/clients/${activeClient}/allergies`)
            .then((r) => !cancelled && setAllergies(Array.isArray(r.data?.data) ? r.data.data : Array.isArray(r.data) ? r.data : []))
            .catch(() => !cancelled && setAllergies([]));
        return () => {
            cancelled = true;
        };
    }, [step, activeClient]);

    const doseTimes = previewDoseTimes(form.data.frequency, form.data.is_prn);
    const allergyClash = (allergies ?? []).find((a) => form.data.medication_name && a.allergen?.toLowerCase().includes(form.data.medication_name.toLowerCase().split(' ')[0] ?? '__'));

    const submit = () => {
        form.post('/emar/medications', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(form.data.controlled_drug || form.data.high_risk ? 'Medication added — routed for prescriber verification.' : 'Medication added.');
                onClose();
            },
            onError: () => toast.error('Please check the medication details'),
        });
    };

    const step0Valid = (presetClient || form.data.client_id) && form.data.medication_name && form.data.dose;
    const step1Valid = !!form.data.frequency;

    const footer = (
        <>
            <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step < 3 ? (
                <Button onClick={() => setStep(step + 1)} disabled={(step === 0 && !step0Valid) || (step === 1 && !step1Valid)}>
                    Continue
                </Button>
            ) : (
                <Button onClick={submit} disabled={form.processing}>
                    Add medication
                </Button>
            )}
        </>
    );

    const clientName = clients?.find((c) => String(c.id) === form.data.client_id);

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Add medication"
            description="Chart a new medication order."
            railIcon={Pill}
            railTitle="Add medication"
            railSubtitle="New order"
            steps={[
                { key: 'details', label: 'Details', blurb: 'Drug & prescriber', icon: Pill },
                { key: 'schedule', label: 'Schedule', blurb: 'Dosing & PRN', icon: ClipboardList },
                { key: 'safety', label: 'Safety', blurb: 'Allergies & flags', icon: ShieldCheck },
                { key: 'review', label: 'Review', blurb: 'Confirm', icon: CheckCircle2 },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 && (
                <>
                    <StepHead icon={Pill} title="Medication details" blurb="New orders default to awaiting prescriber verification." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {!presetClient && (
                            <Field label="Client" required span error={form.errors.client_id}>
                                <SelectInput
                                    value={form.data.client_id}
                                    onChange={(v) => form.setData('client_id', v)}
                                    placeholder="Select client…"
                                    options={(clients ?? []).map((c) => ({ value: String(c.id), label: `${c.last_name}, ${c.first_name}` }))}
                                />
                            </Field>
                        )}
                        <Field label="Authorising prescriber">
                            <Input value={form.data.prescriber} onChange={(e) => form.setData('prescriber', e.target.value)} placeholder="e.g. Dr Singh" />
                        </Field>
                        <Field label="Medication name" required span error={form.errors.medication_name}>
                            <Input value={form.data.medication_name} onChange={(e) => form.setData('medication_name', e.target.value)} placeholder="Generic name & strength" />
                        </Field>
                        <Field label="Dose" required error={form.errors.dose}>
                            <Input value={form.data.dose} onChange={(e) => form.setData('dose', e.target.value)} placeholder="e.g. 500mg" />
                        </Field>
                        <Field label="Dose unit">
                            <Input value={form.data.dose_unit} onChange={(e) => form.setData('dose_unit', e.target.value)} placeholder="mg / mL / tablet" />
                        </Field>
                        <Field label="Form">
                            <Input value={form.data.form} onChange={(e) => form.setData('form', e.target.value)} placeholder="Tablet / liquid" />
                        </Field>
                        <Field label="Route">
                            <Input value={form.data.route} onChange={(e) => form.setData('route', e.target.value)} placeholder="Oral / IM" />
                        </Field>
                        <Field label="Brand name">
                            <Input value={form.data.brand_name} onChange={(e) => form.setData('brand_name', e.target.value)} placeholder="Optional" />
                        </Field>
                        <Field label="Indication">
                            <Input value={form.data.indication} onChange={(e) => form.setData('indication', e.target.value)} placeholder="Reason for med" />
                        </Field>
                        <Field label="Instructions" span hint="Shown to workers at the point of administration.">
                            <Input value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} placeholder="Administration notes" />
                        </Field>
                    </div>
                </>
            )}

            {step === 1 && (
                <>
                    <StepHead icon={ClipboardList} title="Dosing schedule" blurb="Set the frequency; tick PRN for as-needed meds." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Frequency" required error={form.errors.frequency}>
                            <Input value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} placeholder="e.g. Twice daily" />
                        </Field>
                        <Field label="Start date">
                            <Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                        </Field>
                    </div>
                    <div className="mt-3">
                        {doseTimes ? (
                            <InfoCard icon={CheckCircle2}>
                                Scheduled dose times: <strong>{doseTimes.join(' · ')}</strong>
                            </InfoCard>
                        ) : (
                            <InfoCard icon={ClipboardList}>No fixed schedule — recorded as required.</InfoCard>
                        )}
                    </div>
                    <div className="mt-4">
                        <ToggleRow checked={form.data.is_prn} label="As-needed (PRN)" hint="Recorded on demand with a 24h limit." onChange={(v) => form.setData('is_prn', v)} />
                    </div>
                    {form.data.is_prn && (
                        <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="PRN reason">
                                <Input value={form.data.prn_reason} onChange={(e) => form.setData('prn_reason', e.target.value)} placeholder="e.g. Pain" />
                            </Field>
                            <Field label="Max per 24h">
                                <Input type="number" value={form.data.max_per_day} onChange={(e) => form.setData('max_per_day', e.target.value)} placeholder="e.g. 4" />
                            </Field>
                            <Field label="Min hours apart">
                                <Input type="number" value={form.data.min_hours_between_doses} onChange={(e) => form.setData('min_hours_between_doses', e.target.value)} placeholder="e.g. 4" />
                            </Field>
                        </div>
                    )}
                </>
            )}

            {step === 2 && (
                <>
                    <StepHead icon={ShieldCheck} title="Safety" blurb="Allergy cross-check and order classification." />
                    {allergyClash ? (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            <strong>Allergy alert:</strong> this client has a recorded allergy to {allergyClash.allergen}. Confirm with the prescriber before charting.
                        </InfoCard>
                    ) : allergies === null ? (
                        <InfoCard icon={HeartPulse}>Checking client allergies…</InfoCard>
                    ) : allergies.length === 0 ? (
                        <InfoCard icon={CheckCircle2}>No recorded allergies for this client.</InfoCard>
                    ) : (
                        <InfoCard icon={HeartPulse}>Recorded allergies: {allergies.map((a) => a.allergen).join(', ')}. No name match with this drug.</InfoCard>
                    )}
                    <div className="mt-4 grid grid-cols-1 gap-2.5">
                        <ToggleRow checked={form.data.controlled_drug} label="Controlled drug" hint="Requires a witness countersignature at administration." onChange={(v) => form.setData('controlled_drug', v)} />
                        <ToggleRow checked={form.data.high_risk} label="High-risk medication" onChange={(v) => form.setData('high_risk', v)} />
                        <ToggleRow checked={form.data.witness_required} label="Witness required" onChange={(v) => form.setData('witness_required', v)} />
                    </div>
                    <div className="mt-3">
                        <Field label="Pharmac therapeutic group">
                            <Input value={form.data.pharmac_therapeutic_group} onChange={(e) => form.setData('pharmac_therapeutic_group', e.target.value)} placeholder="Optional (reporting)" />
                        </Field>
                    </div>
                    {(form.data.controlled_drug || form.data.high_risk) && (
                        <div className="mt-3">
                            <InfoCard icon={ShieldCheck} tone="warn">
                                This order will be added <strong>awaiting verification</strong> and routed to a prescriber-verifier.
                            </InfoCard>
                        </div>
                    )}
                </>
            )}

            {step === 3 && (
                <>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Confirm the new medication order." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Client" value={presetClient ? 'This resident' : (clientName ? `${clientName.last_name}, ${clientName.first_name}` : '—')} />
                        <SummaryRow label="Medication" value={form.data.medication_name} />
                        <SummaryRow label="Dose" value={[form.data.dose, form.data.dose_unit].filter(Boolean).join(' ')} />
                        <SummaryRow label="Frequency" value={form.data.is_prn ? 'PRN / as-needed' : form.data.frequency} />
                        <SummaryRow label="Flags" value={[form.data.controlled_drug && 'CD', form.data.high_risk && 'High-risk', form.data.witness_required && 'Witness'].filter(Boolean).join(' · ') || 'None'} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Edit medication (single form) ────────────────────────────────────────────
export function EditMedicationDialog({ medication, onClose }: { medication: MedRow; onClose: () => void }) {
    const form = useForm({
        medication_name: medication.name,
        dose: medication.dosage ?? '',
        dose_unit: medication.dose_unit ?? '',
        frequency: medication.frequency ?? '',
        brand_name: medication.brand_name ?? '',
        form: medication.form ?? '',
        route: medication.route ?? '',
        instructions: medication.instructions ?? '',
        controlled_drug: medication.controlled_drug,
        high_risk: medication.high_risk,
        witness_required: medication.witness_required,
        pharmac_therapeutic_group: medication.pharmac_therapeutic_group ?? '',
    });

    const submit = () => {
        form.put(`/emar/medications/${medication.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Medication updated');
                onClose();
            },
            onError: () => toast.error('Please check the details'),
        });
    };

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Edit medication"
            description={medication.name}
            railIcon={Pill}
            railTitle="Edit medication"
            railSubtitle={medication.client_name}
            steps={[{ key: 'edit', label: 'Edit', blurb: 'Order details', icon: Pill }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        Save changes
                    </Button>
                </>
            }
        >
            <StepHead icon={Pill} title="Medication & schedule" blurb="Update the order details and safety flags." />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Medication name" required span error={form.errors.medication_name}>
                    <Input value={form.data.medication_name} onChange={(e) => form.setData('medication_name', e.target.value)} />
                </Field>
                <Field label="Dose">
                    <Input value={form.data.dose} onChange={(e) => form.setData('dose', e.target.value)} />
                </Field>
                <Field label="Dose unit">
                    <Input value={form.data.dose_unit} onChange={(e) => form.setData('dose_unit', e.target.value)} />
                </Field>
                <Field label="Frequency">
                    <Input value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} />
                </Field>
                <Field label="Brand name">
                    <Input value={form.data.brand_name} onChange={(e) => form.setData('brand_name', e.target.value)} />
                </Field>
                <Field label="Form">
                    <Input value={form.data.form} onChange={(e) => form.setData('form', e.target.value)} />
                </Field>
                <Field label="Route">
                    <Input value={form.data.route} onChange={(e) => form.setData('route', e.target.value)} />
                </Field>
                <Field label="Instructions" span>
                    <Input value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} />
                </Field>
            </div>
            <div className="mt-4 grid grid-cols-1 gap-2.5">
                <ToggleRow checked={form.data.controlled_drug} label="Controlled drug" onChange={(v) => form.setData('controlled_drug', v)} />
                <ToggleRow checked={form.data.high_risk} label="High-risk" onChange={(v) => form.setData('high_risk', v)} />
                <ToggleRow checked={form.data.witness_required} label="Witness required" onChange={(v) => form.setData('witness_required', v)} />
            </div>
        </MedsWizardDialog>
    );
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
            medication.interaction_severity && `Interaction: ${medication.interaction_severity}`,
        ]
            .filter(Boolean)
            .join(' · ') || 'None';
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={medication.name}
            description={`${medication.client_name} · ${medication.state}`}
            railIcon={Pill}
            railTitle={medication.name}
            railSubtitle={medication.client_name}
            steps={[{ key: 'detail', label: 'Details', blurb: 'Order summary', icon: Pill }]}
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
                                    <BadgeCheck className="h-4 w-4" /> Verify order
                                </Button>
                            </>
                        )}
                        <Button variant={pending && canVerify ? 'outline' : 'default'} onClick={onEdit}>
                            <Pencil className="h-4 w-4" /> Edit
                        </Button>
                        {medication.state === 'active' && (
                            <Button variant="outline" onClick={onDiscontinue}>
                                <Ban className="h-4 w-4 text-status-critical" /> Discontinue
                            </Button>
                        )}
                        <Button variant="ghost" onClick={() => router.visit(`/operations/clients/${medication.client_id}/care`)}>
                            <User className="h-4 w-4" /> Client
                        </Button>
                        <Button variant="ghost" onClick={() => router.visit(`/emar/mar?client_id=${medication.client_id}`)}>
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
                    Awaiting prescriber verification — not administrable until verified.{canVerify ? ' Use Verify order below to confirm, or Reject to decline.' : ''}
                </InfoCard>
            )}
            {rejected && (
                <InfoCard icon={AlertTriangle} tone="crit">
                    Rejected{medication.rejection_reason ? `: ${medication.rejection_reason}` : ''}.
                </InfoCard>
            )}
            <div className="mt-3 rounded-lg border px-4">
                <SummaryRow label="Dose" value={[medication.dosage, medication.dose_unit].filter(Boolean).join(' ') || '—'} />
                <SummaryRow label="Frequency" value={medication.is_prn ? `PRN${medication.prn_reason ? ` · ${medication.prn_reason}` : ''}` : medication.frequency ?? '—'} />
                <SummaryRow label="Route / form" value={[medication.route, medication.form].filter(Boolean).join(' · ') || '—'} />
                <SummaryRow label="Indication" value={medication.indication ?? '—'} />
                <SummaryRow label="Prescriber" value={medication.prescriber ?? '—'} />
                <SummaryRow label="Flags" value={flags} tone={medication.controlled_drug || medication.high_risk ? 'crit' : undefined} />
                {medication.is_prn && <SummaryRow label="PRN limit" value={medication.max_per_day ? `${medication.max_per_day} per 24h` : '—'} />}
                <SummaryRow label="Stock" value={medication.stock ? `${medication.stock.on_hand ?? '—'} ${medication.stock.unit ?? ''}${medication.stock.low ? ' · low' : ''}` : '—'} />
            </div>
            {medication.instructions && (
                <div className="mt-3 rounded-lg border bg-background p-3 text-sm">
                    <div className="mb-1 font-medium">Instructions</div>
                    <p className="text-muted-foreground">{medication.instructions}</p>
                </div>
            )}
        </MedsWizardDialog>
    );
}

// ── Discontinue (required reason) ────────────────────────────────────────────
export function DiscontinueDialog({ medication, onClose }: { medication: MedRow; onClose: () => void }) {
    const form = useForm({ reason: '' });
    const submit = () => {
        form.post(`/emar/medications/${medication.id}/discontinue`, {
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
            description="The medication is ceased and archived; records are kept."
            railIcon={Ban}
            railTitle="Discontinue"
            railSubtitle={medication.name}
            steps={[{ key: 'confirm', label: 'Confirm', blurb: 'Reason required', icon: Ban }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={submit} disabled={!form.data.reason.trim() || form.processing}>
                        Discontinue medication
                    </Button>
                </>
            }
        >
            <StepHead icon={Ban} title={`Discontinue ${medication.name}`} blurb="This ceases the order. Records are retained for audit." />
            <Field label="Reason" required error={form.errors.reason}>
                <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Why is this medication being ceased?" />
            </Field>
        </MedsWizardDialog>
    );
}

// ── Reject order (required reason) ───────────────────────────────────────────
export function RejectOrderDialog({ medication, onClose }: { medication: MedRow; onClose: () => void }) {
    const form = useForm({ rejection_reason: '' });
    const submit = () => {
        form.post(`/emar/medications/${medication.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Order rejected');
                onClose();
            },
            onError: () => toast.error('A reason is required'),
        });
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={`Reject ${medication.name}?`}
            description="The order is marked rejected and not administrable."
            railIcon={Ban}
            railTitle="Reject order"
            railSubtitle={medication.name}
            steps={[{ key: 'reject', label: 'Reject', blurb: 'Reason required', icon: Ban }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={submit} disabled={!form.data.rejection_reason.trim() || form.processing}>
                        Reject order
                    </Button>
                </>
            }
        >
            <StepHead icon={Ban} title="Reject order" blurb="Document why this order cannot be verified." />
            <Field label="Rejection reason" required error={form.errors.rejection_reason}>
                <Input value={form.data.rejection_reason} onChange={(e) => form.setData('rejection_reason', e.target.value)} placeholder="Reason for rejection" />
            </Field>
        </MedsWizardDialog>
    );
}

// ── Import CSV ───────────────────────────────────────────────────────────────
export function ImportCsvDialog({ onClose }: { onClose: () => void }) {
    const form = useForm<{ csv_file: File | null }>({ csv_file: null });
    const submit = () => {
        form.post('/emar/medications/import', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                toast.success('Medications imported');
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
            description="Bulk-import medication orders from a CSV file."
            railIcon={FileUp}
            railTitle="Import CSV"
            railSubtitle="Bulk load"
            steps={[{ key: 'import', label: 'Import', blurb: 'Upload file', icon: FileUp }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={!form.data.csv_file || form.processing}>
                        Import
                    </Button>
                </>
            }
        >
            <StepHead icon={FileUp} title="Import from CSV" blurb="Rows are validated before anything is saved." />
            <InfoCard icon={ClipboardList}>
                Expected columns: <code>client_name, medication_name, dose, frequency, route</code>
            </InfoCard>
            <div className="mt-4">
                <Field label="Upload file" required>
                    {/* eslint-disable-next-line no-restricted-syntax -- native file input; no shadcn file control */}
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) => form.setData('csv_file', e.target.files?.[0] ?? null)}
                        className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm"
                    />
                </Field>
            </div>
        </MedsWizardDialog>
    );
}

// ── Drug interactions (reference) ────────────────────────────────────────────
export function InteractionsDialog({ medications, onClose }: { medications: MedRow[]; onClose: () => void }) {
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
            steps={[{ key: 'interactions', label: 'Interactions', blurb: 'Reference', icon: AlertTriangle }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <StepHead icon={AlertTriangle} title="Recorded interactions" blurb="Review before the next round." />
            {flagged.length === 0 ? (
                <InfoCard icon={CheckCircle2}>No interactions recorded across the current register.</InfoCard>
            ) : (
                <ul className="flex flex-col gap-2">
                    {flagged.map((m) => (
                        <li key={m.id} className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm">
                            <span>
                                <span className="font-medium">{m.name}</span>
                                <span className="ml-2 text-xs text-muted-foreground">{m.client_name}</span>
                            </span>
                            <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', severityTone(m.interaction_severity))}>
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
    if (s.includes('major') || s.includes('severe') || s.includes('high')) return 'bg-status-critical-bg text-status-critical';
    if (s.includes('moderate')) return 'bg-status-warning-bg text-status-warning';
    return 'bg-status-info-bg text-status-info';
}
