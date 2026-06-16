/* eslint-disable no-restricted-syntax -- summary/balance panes are custom-layout
   bordered surfaces inside the wizard, not Card components; all colours are tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, InfoCard, SelectInput, Segmented, StepHead, TilePicker } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { ENTRY_TYPES, entryDirection, type CdDiscrepancy, type CdLossReport, type CdMedication, type StaffOption } from '@/components/emar/controlled/types';
import { submitOffline, type OfflineAction } from '@/lib/offline-queue';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDownUp, Ban, FileWarning, Lock, Package, ShieldCheck, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

function medOptions(meds: CdMedication[]) {
    return meds.map((m) => ({ value: String(m.id), label: `${m.name} · ${m.client_name}` }));
}

/** Witness <SelectInput> options excluding the recorder (and any already-chosen
 * witness) — mirrors the server `different:auth id` rule so a user cannot
 * self-witness a controlled-drug transaction. */
function witnessOptions(staff: StaffOption[], exclude: (number | null | undefined)[]) {
    const skip = new Set(exclude.filter((id): id is number => typeof id === 'number'));
    return staff.filter((s) => !skip.has(s.id)).map((s) => ({ value: String(s.id), label: s.name }));
}

/**
 * When the device is offline, divert a CD mutation to the shared offline queue
 * (replayed on reconnect; the server dedupes on client_request_uuid). Returns
 * true when it queued, so the caller skips the online Inertia post. Mirrors the
 * prn-wizard pattern — online behaviour (validation errors + partial reload) is
 * left entirely to Inertia. CD-queue convergence (TODO Gx) — now closed.
 */
function queueIfOffline(action: OfflineAction, url: string, payload: Record<string, unknown>, onClose: () => void): boolean {
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        void submitOffline({
            action,
            url,
            payload,
            queuedMessage: 'Saved on this device — we’ll send it when you’re back online.',
        }).then(() => onClose());

        return true;
    }

    return false;
}

// ── Record CD entry (3-step) ─────────────────────────────────────────────────
export function RecordCdEntryDialog({ medications, staff, currentUserId, onClose }: { medications: CdMedication[]; staff: StaffOption[]; currentUserId?: number | null; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        medication_id: '',
        client_id: 0,
        medication_name: '',
        entry_type: 'administration',
        quantity: '',
        unit: '',
        on_hand_before: '',
        on_hand_after: '',
        batch_number: '',
        expiry_date: '',
        cd_schedule: '',
        witnessed_by: '',
        notes: '',
    });
    const isReceipt = form.data.entry_type === 'receipt';
    const med = medications.find((m) => String(m.id) === form.data.medication_id);
    const dir = entryDirection(form.data.entry_type);
    const expectedAfter = useMemo(() => {
        const before = parseFloat(form.data.on_hand_before);
        const qty = parseFloat(form.data.quantity);
        if (Number.isNaN(before) || Number.isNaN(qty) || dir === 0) return null;
        return before + dir * qty;
    }, [form.data.on_hand_before, form.data.quantity, dir]);

    const pickMed = (id: string) => {
        const m = medications.find((x) => String(x.id) === id);
        form.setData({
            ...form.data,
            medication_id: id,
            client_id: m?.client_id ?? 0,
            medication_name: m?.name ?? '',
            unit: m?.stock?.unit ?? '',
            on_hand_before: m?.stock?.on_hand != null ? String(m.stock.on_hand) : '',
            cd_schedule: m?.schedule != null ? String(m.schedule) : '',
        });
    };

    const submit = () => {
        if (queueIfOffline('cd_entry', '/emar/controlled/entries', { ...form.data, witnessed_by: form.data.witnessed_by ? Number(form.data.witnessed_by) : null }, onClose)) return;
        form.transform((d) => ({ ...d, witnessed_by: d.witnessed_by ? Number(d.witnessed_by) : null }));
        form.post('/emar/controlled/entries', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Controlled drug entry recorded');
                onClose();
            },
            onError: () => toast.error('Please check the entry — balance must reconcile'),
        });
    };

    const valid = [
        !!form.data.medication_id && !!form.data.quantity,
        !!form.data.on_hand_after && !!form.data.witnessed_by && (!isReceipt || (!!form.data.batch_number && !!form.data.expiry_date)),
        true,
    ];

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Record CD entry"
            description="Record a controlled-drug register movement."
            railIcon={Lock}
            railTitle="CD register entry"
            railSubtitle="Movement"
            steps={[
                { key: 'movement', label: 'Movement', blurb: 'Drug & quantity', icon: ArrowDownUp },
                { key: 'balance', label: 'Balance & witness', blurb: 'Reconcile', icon: ShieldCheck },
                { key: 'review', label: 'Review', blurb: 'Confirm', icon: Lock },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>
                        {step === 0 ? 'Cancel' : 'Back'}
                    </Button>
                    {step < 2 ? (
                        <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button>
                    ) : (
                        <Button onClick={submit} disabled={form.processing}>Record entry</Button>
                    )}
                </>
            }
        >
            {step === 0 && (
                <>
                    <StepHead icon={ArrowDownUp} title="Movement" blurb="Pick the controlled drug and the movement type." />
                    <Field label="Controlled drug" required span>
                        <SelectInput value={form.data.medication_id} onChange={pickMed} placeholder="Select CD…" options={medOptions(medications)} />
                    </Field>
                    <div className="mt-4">
                        <Field label="Movement type" span>
                            <TilePicker
                                value={form.data.entry_type}
                                onChange={(v) => form.setData('entry_type', v)}
                                cols={3}
                                options={ENTRY_TYPES.map((t) => ({ key: t.value, label: t.label, icon: t.value === 'receipt' || t.value === 'transfer_in' ? Package : ArrowDownUp }))}
                            />
                        </Field>
                    </div>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Quantity" required error={form.errors.quantity}>
                            <Input type="number" step="0.5" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} placeholder="e.g. 2" />
                        </Field>
                        <Field label="Unit">
                            <Input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="tablets / mL" />
                        </Field>
                        <Field label="CD schedule" error={form.errors.cd_schedule}>
                            <SelectInput value={form.data.cd_schedule} onChange={(v) => form.setData('cd_schedule', v)} placeholder="Classify…" options={[{ value: '2', label: 'Schedule 2' }, { value: '3', label: 'Schedule 3' }, { value: '4', label: 'Schedule 4' }]} />
                        </Field>
                    </div>
                </>
            )}

            {step === 1 && (
                <>
                    <StepHead icon={ShieldCheck} title="Balance & witness" blurb="The new balance must reconcile, and a witness is required." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Balance before" error={form.errors.on_hand_before}>
                            <Input type="number" step="0.5" value={form.data.on_hand_before} onChange={(e) => form.setData('on_hand_before', e.target.value)} />
                        </Field>
                        <Field label="Balance after" required error={form.errors.on_hand_after}>
                            <Input type="number" step="0.5" value={form.data.on_hand_after} onChange={(e) => form.setData('on_hand_after', e.target.value)} />
                        </Field>
                    </div>
                    {expectedAfter !== null && (
                        <div className="mt-3">
                            <InfoCard icon={ShieldCheck} tone={form.data.on_hand_after && parseFloat(form.data.on_hand_after) !== expectedAfter ? 'crit' : 'info'}>
                                {form.data.on_hand_before} {dir > 0 ? '+' : '−'} {form.data.quantity} should leave <strong>{expectedAfter}</strong>.{' '}
                                {form.data.on_hand_after && parseFloat(form.data.on_hand_after) !== expectedAfter ? 'This does not reconcile.' : ''}
                                <button type="button" className="ml-2 font-medium text-primary underline" onClick={() => form.setData('on_hand_after', String(expectedAfter))}>Use {expectedAfter}</button>
                            </InfoCard>
                        </div>
                    )}
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Witnessed by" required error={form.errors.witnessed_by}>
                            <SelectInput value={form.data.witnessed_by} onChange={(v) => form.setData('witnessed_by', v)} placeholder="Second signatory…" options={witnessOptions(staff, [currentUserId])} />
                        </Field>
                        <Field label={isReceipt ? 'Batch number (receipt)' : 'Batch number'} required={isReceipt} error={form.errors.batch_number}>
                            <Input value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} />
                        </Field>
                        <Field label={isReceipt ? 'Expiry date (receipt)' : 'Expiry date'} required={isReceipt} error={form.errors.expiry_date}>
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                        </Field>
                        <Field label="Notes" span>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </div>
                    {isReceipt ? <p className="mt-2 text-xs text-muted-foreground">Receipts require a batch number and expiry date for the new stock.</p> : null}
                </>
            )}

            {step === 2 && (
                <>
                    <StepHead icon={Lock} title="Review" blurb="Confirm the register entry." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Drug" value={med ? `${med.name} · ${med.client_name}` : '—'} />
                        <SummaryRow label="Movement" value={`${form.data.entry_type} · ${form.data.quantity} ${form.data.unit}`} />
                        <SummaryRow label="Balance" value={`${form.data.on_hand_before || '—'} → ${form.data.on_hand_after || '—'}`} />
                        <SummaryRow label="Witness" value={staff.find((s) => String(s.id) === form.data.witnessed_by)?.name ?? '—'} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Balance check (quick) ────────────────────────────────────────────────────
export function BalanceCheckDialog({ medications, staff, currentUserId, presetMedId, onClose }: { medications: CdMedication[]; staff: StaffOption[]; currentUserId?: number | null; presetMedId?: number | null; onClose: () => void }) {
    const preset = presetMedId ? medications.find((m) => m.id === presetMedId) : undefined;
    const form = useForm({
        medication_id: preset ? String(preset.id) : '',
        client_id: preset?.client_id ?? 0,
        medication_name: preset?.name ?? '',
        expected_balance: preset?.stock?.on_hand != null ? String(preset.stock.on_hand) : '',
        actual_balance: '',
        witnessed_by: '',
        discrepancy_notes: '',
    });
    const med = medications.find((m) => String(m.id) === form.data.medication_id);
    const mismatch = form.data.actual_balance !== '' && form.data.expected_balance !== '' && parseFloat(form.data.actual_balance) !== parseFloat(form.data.expected_balance);

    const pickMed = (id: string) => {
        const m = medications.find((x) => String(x.id) === id);
        form.setData({ ...form.data, medication_id: id, client_id: m?.client_id ?? 0, medication_name: m?.name ?? '', expected_balance: m?.stock?.on_hand != null ? String(m.stock.on_hand) : '' });
    };

    const submit = () => {
        if (queueIfOffline('cd_balance_check', '/emar/controlled/balance-check', { ...form.data, witnessed_by: form.data.witnessed_by ? Number(form.data.witnessed_by) : null }, onClose)) return;
        form.transform((d) => ({ ...d, witnessed_by: d.witnessed_by ? Number(d.witnessed_by) : null }));
        form.post('/emar/controlled/balance-check', { preserveScroll: true, onSuccess: () => { toast.success(mismatch ? 'Balance check recorded — discrepancy raised' : 'Balance check recorded'); onClose(); }, onError: () => toast.error('Please check the details') });
    };

    return (
        <MedsWizardDialog open onClose={onClose} title="Balance check" description="Reconcile the physical count against the register." railIcon={ShieldCheck} railTitle="Balance check" railSubtitle={med?.name ?? 'Stock count'} steps={[{ key: 'check', label: 'Balance check', blurb: 'Count & witness', icon: ShieldCheck }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={form.processing}>Cancel</Button><Button onClick={submit} disabled={!form.data.medication_id || !form.data.actual_balance || !form.data.witnessed_by || form.processing}>Record check</Button></>}>
            <StepHead icon={ShieldCheck} title="Stock balance check" blurb="A mismatch auto-raises a discrepancy and an incident." />
            <Field label="Controlled drug" required span>
                <SelectInput value={form.data.medication_id} onChange={pickMed} placeholder="Select CD…" options={medOptions(medications)} />
            </Field>
            {med ? (
                <p className={cn('mt-2 text-xs', med.overdue_check ? 'text-status-warning' : 'text-muted-foreground')}>
                    {med.days_since_check == null
                        ? 'No balance check on record yet.'
                        : `Last balance check ${med.days_since_check === 0 ? 'today' : `${med.days_since_check} day${med.days_since_check === 1 ? '' : 's'} ago`}.`}
                    {med.overdue_check ? ' Overdue — checks are due at least weekly.' : ''}
                </p>
            ) : null}
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Expected (register)" required>
                    <Input type="number" step="0.5" value={form.data.expected_balance} onChange={(e) => form.setData('expected_balance', e.target.value)} />
                </Field>
                <Field label="Actual (physical count)" required error={form.errors.actual_balance}>
                    <Input type="number" step="0.5" value={form.data.actual_balance} onChange={(e) => form.setData('actual_balance', e.target.value)} />
                </Field>
                <Field label="Witnessed by" required span error={form.errors.witnessed_by}>
                    <SelectInput value={form.data.witnessed_by} onChange={(v) => form.setData('witnessed_by', v)} placeholder="Second signatory…" options={witnessOptions(staff, [currentUserId])} />
                </Field>
            </div>
            {mismatch && (
                <div className="mt-3">
                    <InfoCard icon={AlertTriangle} tone="crit">Counts differ — this will raise a discrepancy. Document what was found.</InfoCard>
                    <div className="mt-2">
                        <Field label="Discrepancy note" required>
                            <Input value={form.data.discrepancy_notes} onChange={(e) => form.setData('discrepancy_notes', e.target.value)} placeholder="What was found / next steps" />
                        </Field>
                    </div>
                </div>
            )}
        </MedsWizardDialog>
    );
}

// ── Resolve discrepancy (quick) ──────────────────────────────────────────────
const RESOLUTION_ACTIONS = ['Recount confirmed', 'Recording error corrected', 'Stock located', 'Escalated to manager', 'Loss report raised'];
export function ResolveDiscrepancyDialog({ discrepancy, onClose }: { discrepancy: CdDiscrepancy; onClose: () => void }) {
    const form = useForm({ resolution_action: '', resolution_notes: '' });
    const submit = () => form.post(`/emar/controlled/discrepancies/${discrepancy.id}/resolve`, { preserveScroll: true, onSuccess: () => { toast.success('Discrepancy resolved'); onClose(); }, onError: () => toast.error('A resolution note is required') });
    return (
        <MedsWizardDialog open onClose={onClose} title="Resolve discrepancy" description="Close out a controlled-drug discrepancy." railIcon={AlertTriangle} railTitle="Resolve discrepancy" railSubtitle={discrepancy.medication?.name ?? 'CD discrepancy'} steps={[{ key: 'resolve', label: 'Resolve', blurb: 'Action & notes', icon: AlertTriangle }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={form.processing}>Cancel</Button><Button onClick={submit} disabled={!form.data.resolution_notes.trim() || form.processing}>Resolve</Button></>}>
            <StepHead icon={AlertTriangle} title="Resolve discrepancy" blurb="Resolution is logged against the linked incident." />
            <Field label="Action taken">
                <SelectInput value={form.data.resolution_action} onChange={(v) => form.setData('resolution_action', v)} placeholder="Select…" options={RESOLUTION_ACTIONS.map((a) => ({ value: a, label: a }))} />
            </Field>
            <div className="mt-3">
                <Field label="Resolution notes" required error={form.errors.resolution_notes}>
                    <Input value={form.data.resolution_notes} onChange={(e) => form.setData('resolution_notes', e.target.value)} placeholder="How it was resolved" />
                </Field>
            </div>
        </MedsWizardDialog>
    );
}

// ── Report CD loss (3-step) ──────────────────────────────────────────────────
export function ReportLossDialog({ medications, onClose }: { medications: CdMedication[]; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        medication_id: '',
        client_id: null as number | null,
        medication_name: '',
        quantity_lost: '',
        unit: '',
        circumstances: '',
        accountable_officer_name: '',
        reported_to_police: false,
        police_reference: '',
        reported_to_pharmacy: false,
        pharmacy_name: '',
        reported_to_regulator: false,
        regulator_name: '',
        regulator_reference: '',
    });
    const pickMed = (id: string) => {
        const m = medications.find((x) => String(x.id) === id);
        form.setData({ ...form.data, medication_id: id, client_id: m?.client_id ?? null, medication_name: m?.name ?? '', unit: m?.stock?.unit ?? '' });
    };
    const submit = () => {
        if (queueIfOffline('cd_loss_report', '/emar/controlled/loss-reports', { ...form.data }, onClose)) return;
        form.post('/emar/controlled/loss-reports', { preserveScroll: true, onSuccess: () => { toast.success('Loss report raised'); onClose(); }, onError: () => toast.error('Please check the report') });
    };
    const valid = [!!form.data.medication_name && !!form.data.quantity_lost && !!form.data.circumstances, true, true];
    return (
        <MedsWizardDialog open onClose={onClose} title="Report CD loss" description="Report a controlled-drug loss or discrepancy for investigation." railIcon={FileWarning} railTitle="CD loss report" railSubtitle="Investigation" steps={[{ key: 'details', label: 'Loss details', blurb: 'What & how much', icon: FileWarning }, { key: 'escalation', label: 'Escalation', blurb: 'Police / pharmacy', icon: ShieldCheck }, { key: 'review', label: 'Review', blurb: 'Confirm', icon: AlertTriangle }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 2 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button variant="destructive" onClick={submit} disabled={form.processing}>Raise loss report</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={FileWarning} title="Loss details" blurb="Record what was lost and the circumstances." />
                    <Field label="Controlled drug" required span>
                        <SelectInput value={form.data.medication_id} onChange={pickMed} placeholder="Select CD…" options={medOptions(medications)} />
                    </Field>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Quantity lost" required error={form.errors.quantity_lost}>
                            <Input type="number" step="0.5" value={form.data.quantity_lost} onChange={(e) => form.setData('quantity_lost', e.target.value)} />
                        </Field>
                        <Field label="Unit">
                            <Input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} />
                        </Field>
                        <Field label="Circumstances" required span error={form.errors.circumstances}>
                            <Input value={form.data.circumstances} onChange={(e) => form.setData('circumstances', e.target.value)} placeholder="How the loss occurred / was discovered" />
                        </Field>
                        <Field label="CD Accountable Officer" span error={form.errors.accountable_officer_name}>
                            <Input value={form.data.accountable_officer_name} onChange={(e) => form.setData('accountable_officer_name', e.target.value)} placeholder="Officer overseeing the loss" />
                        </Field>
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ShieldCheck} title="Escalation" blurb="Record any external reporting." />
                    <div className="flex flex-col gap-3">
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data.reported_to_police} onChange={(e) => form.setData('reported_to_police', e.target.checked)} />Reported to Police</label>
                        {form.data.reported_to_police && <Field label="Police reference"><Input value={form.data.police_reference} onChange={(e) => form.setData('police_reference', e.target.value)} /></Field>}
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data.reported_to_pharmacy} onChange={(e) => form.setData('reported_to_pharmacy', e.target.checked)} />Reported to pharmacy</label>
                        {form.data.reported_to_pharmacy && <Field label="Pharmacy name"><Input value={form.data.pharmacy_name} onChange={(e) => form.setData('pharmacy_name', e.target.value)} /></Field>}
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data.reported_to_regulator} onChange={(e) => form.setData('reported_to_regulator', e.target.checked)} />Notified regulator (Medsafe / Ministry of Health)</label>
                        {form.data.reported_to_regulator && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <Field label="Regulator"><Input value={form.data.regulator_name} onChange={(e) => form.setData('regulator_name', e.target.value)} placeholder="e.g. Medsafe" /></Field>
                                <Field label="Reference"><Input value={form.data.regulator_reference} onChange={(e) => form.setData('regulator_reference', e.target.value)} /></Field>
                            </div>
                        )}
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={AlertTriangle} title="Review" blurb="Confirm and raise the loss report." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Drug" value={form.data.medication_name} />
                        <SummaryRow label="Quantity lost" value={`${form.data.quantity_lost} ${form.data.unit}`} tone="crit" />
                        <SummaryRow label="Accountable Officer" value={form.data.accountable_officer_name || '—'} />
                        <SummaryRow label="Police" value={form.data.reported_to_police ? form.data.police_reference || 'Reported' : 'No'} />
                        <SummaryRow label="Regulator" value={form.data.reported_to_regulator ? [form.data.regulator_name, form.data.regulator_reference].filter(Boolean).join(' · ') || 'Notified' : 'No'} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Investigate / Resolve loss (quick) ───────────────────────────────────────
export function LossActionDialog({ report, action, onClose }: { report: CdLossReport; action: 'investigate' | 'resolve'; onClose: () => void }) {
    const field = action === 'investigate' ? 'investigation_notes' : 'resolution_outcome';
    const form = useForm<{ investigation_notes?: string; resolution_outcome?: string }>({ [field]: '' });
    const value = (form.data as Record<string, string>)[field] ?? '';
    const submit = () => form.post(`/emar/controlled/loss-reports/${report.id}/${action}`, { preserveScroll: true, onSuccess: () => { toast.success(action === 'investigate' ? 'Marked investigating' : 'Loss resolved'); onClose(); }, onError: () => toast.error('A note is required') });
    return (
        <MedsWizardDialog open onClose={onClose} title={action === 'investigate' ? 'Investigate loss' : 'Resolve loss'} description={report.medication_name ?? 'CD loss report'} railIcon={FileWarning} railTitle={action === 'investigate' ? 'Investigate' : 'Resolve'} railSubtitle={report.medication_name ?? ''} steps={[{ key: action, label: action === 'investigate' ? 'Investigate' : 'Resolve', blurb: 'Add note', icon: FileWarning }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={form.processing}>Cancel</Button><Button onClick={submit} disabled={!value.trim() || form.processing}>{action === 'investigate' ? 'Mark investigating' : 'Resolve'}</Button></>}>
            <StepHead icon={FileWarning} title={action === 'investigate' ? 'Investigation' : 'Resolution'} blurb="Logged against the linked incident." />
            <Field label={action === 'investigate' ? 'Investigation notes' : 'Resolution outcome'} required>
                <Input value={value} onChange={(e) => form.setData(field, e.target.value)} placeholder={action === 'investigate' ? 'Findings so far' : 'Final outcome'} />
            </Field>
        </MedsWizardDialog>
    );
}

// ── Record destruction (3-step) — SHARED with Destructions page (Page 7) ─────
const DESTRUCTION_REASONS = [
    { value: 'expired', label: 'Expired' },
    { value: 'ceased', label: 'Medication ceased' },
    { value: 'contaminated', label: 'Contaminated' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'deceased', label: 'Client deceased' },
    { value: 'discharged', label: 'Client discharged' },
    { value: 'surplus', label: 'Surplus stock' },
];
const DISPOSAL_METHODS = [
    { value: 'pharmacy_return', label: 'Return to pharmacy' },
    { value: 'incineration', label: 'Incineration' },
    { value: 'denaturing', label: 'Denaturing' },
    { value: 'sharps_bin', label: 'Sharps bin' },
    { value: 'other', label: 'Other' },
];

/**
 * Shared record-destruction wizard. Used by both the Controlled Drugs page
 * (medications are all controlled → always two witnesses + authorisation) and
 * the Destructions register (any active medication → witness 2 / authorisation
 * become required only when the picked medication is a controlled drug). When a
 * `sites` list is supplied the site is collected explicitly (gap 6).
 */
export function RecordDestructionDialog({ medications, staff, sites, defaultSiteId, currentUserId, onClose }: { medications: CdMedication[]; staff: StaffOption[]; sites?: { id: number; name: string }[]; defaultSiteId?: number | null; currentUserId?: number | null; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        medication_id: '',
        client_id: 0,
        site_id: defaultSiteId ? String(defaultSiteId) : '',
        medication_name: '',
        quantity: '',
        unit: '',
        reason: '',
        disposal_method: '',
        is_controlled_drug: false,
        witness_1_id: '',
        witness_2_id: '',
        authorised_by_name: '',
        denaturing_confirmed: false,
        notes: '',
    });
    const isCd = form.data.is_controlled_drug;
    const pickMed = (id: string) => {
        const m = medications.find((x) => String(x.id) === id);
        form.setData({ ...form.data, medication_id: id, client_id: m?.client_id ?? 0, medication_name: m?.name ?? '', unit: m?.stock?.unit ?? '', is_controlled_drug: !!m?.controlled_drug });
    };
    const submit = () => {
        const ids = {
            site_id: form.data.site_id ? Number(form.data.site_id) : null,
            witness_1_id: form.data.witness_1_id ? Number(form.data.witness_1_id) : null,
            witness_2_id: form.data.witness_2_id ? Number(form.data.witness_2_id) : null,
        };
        if (queueIfOffline('cd_destruction', '/emar/destructions', { ...form.data, ...ids }, onClose)) return;
        form.transform((d) => ({ ...d, ...ids }));
        form.post('/emar/destructions', { preserveScroll: true, onSuccess: () => { toast.success('Destruction recorded'); onClose(); }, onError: () => toast.error(isCd ? 'Please check — CD destruction needs two distinct witnesses + authorisation' : 'Please check the destruction details') });
    };
    const valid = [
        !!form.data.medication_name && !!form.data.quantity && !!form.data.unit && !!form.data.reason,
        !!form.data.disposal_method && !!form.data.witness_1_id && (!isCd || (!!form.data.witness_2_id && !!form.data.authorised_by_name && form.data.denaturing_confirmed)),
        true,
    ];
    return (
        <MedsWizardDialog open onClose={onClose} title="Record destruction" description={isCd ? 'Record a controlled-drug destruction with two witnesses.' : 'Record a medication destruction for the disposal register.'} railIcon={Trash2} railTitle="Destruction" railSubtitle="Disposal register" steps={[{ key: 'item', label: 'Item', blurb: 'Drug & quantity', icon: Package }, { key: 'method', label: isCd ? 'Method & witnesses' : 'Method & witness', blurb: isCd ? 'Two signatories' : 'One witness', icon: ShieldCheck }, { key: 'review', label: 'Review', blurb: 'Confirm', icon: Trash2 }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 2 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={form.processing}>Record destruction</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={Package} title="Item destroyed" blurb="What is being destroyed and why." />
                    <Field label="Medication" required span>
                        <SelectInput value={form.data.medication_id} onChange={pickMed} placeholder="Select medication…" options={medOptions(medications)} />
                    </Field>
                    {isCd && <p className="mt-2 text-xs font-medium text-status-critical">Controlled drug — two witnesses and authorisation are required.</p>}
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Quantity" required error={form.errors.quantity}>
                            <Input type="number" step="0.5" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </Field>
                        <Field label="Unit" required error={form.errors.unit}>
                            <Input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} />
                        </Field>
                        <Field label="Reason" required error={form.errors.reason}>
                            <SelectInput value={form.data.reason} onChange={(v) => form.setData('reason', v)} placeholder="Why destroyed…" options={DESTRUCTION_REASONS} />
                        </Field>
                        {sites && sites.length > 0 && (
                            <Field label="Site" error={form.errors.site_id}>
                                <SelectInput value={form.data.site_id} onChange={(v) => form.setData('site_id', v)} placeholder="Site…" options={sites.map((s) => ({ value: String(s.id), label: s.name }))} />
                            </Field>
                        )}
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ShieldCheck} title={isCd ? 'Method & witnesses' : 'Method & witness'} blurb={isCd ? 'CD destruction requires two distinct witnesses and authorisation.' : 'Record the disposal method and a witness.'} />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Disposal method" required span error={form.errors.disposal_method}>
                            <SelectInput value={form.data.disposal_method} onChange={(v) => form.setData('disposal_method', v)} placeholder="How disposed…" options={DISPOSAL_METHODS} />
                        </Field>
                        <Field label="Witness 1" required error={form.errors.witness_1_id}>
                            <SelectInput value={form.data.witness_1_id} onChange={(v) => form.setData('witness_1_id', v)} placeholder="First witness…" options={witnessOptions(staff, [currentUserId])} />
                        </Field>
                        {isCd && (
                            <Field label="Witness 2" required error={form.errors.witness_2_id}>
                                <SelectInput value={form.data.witness_2_id} onChange={(v) => form.setData('witness_2_id', v)} placeholder="Second witness…" options={witnessOptions(staff, [currentUserId, form.data.witness_1_id ? Number(form.data.witness_1_id) : null])} />
                            </Field>
                        )}
                        {isCd && (
                            <Field label="Authorised by" required span error={form.errors.authorised_by_name}>
                                <Input value={form.data.authorised_by_name} onChange={(e) => form.setData('authorised_by_name', e.target.value)} placeholder="Authorising person" />
                            </Field>
                        )}
                        {isCd && (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input type="checkbox" className="h-4 w-4" checked={form.data.denaturing_confirmed} onChange={(e) => form.setData('denaturing_confirmed', e.target.checked)} />
                                Denaturing kit used — the controlled drug was rendered irretrievable before disposal.
                            </label>
                        )}
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Trash2} title="Review" blurb="Confirm the destruction record." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Medication" value={form.data.medication_name} />
                        <SummaryRow label="Quantity" value={`${form.data.quantity} ${form.data.unit}`} />
                        <SummaryRow label="Reason" value={DESTRUCTION_REASONS.find((r) => r.value === form.data.reason)?.label ?? form.data.reason} />
                        <SummaryRow label="Method" value={DISPOSAL_METHODS.find((m) => m.value === form.data.disposal_method)?.label ?? form.data.disposal_method} />
                        <SummaryRow label={isCd ? 'Witnesses' : 'Witness'} value={[form.data.witness_1_id, form.data.witness_2_id].map((id) => staff.find((s) => String(s.id) === id)?.name).filter(Boolean).join(', ')} />
                        {isCd && <SummaryRow label="Authorised by" value={form.data.authorised_by_name} />}
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

/**
 * Void a destruction record. The register is immutable (MoD Regs 1977) — a record
 * is never deleted; voiding supersedes it (kept visible, struck through, with the
 * reason) and removes it from live counts.
 */
export function VoidDestructionDialog({ destruction, onClose }: { destruction: { id: number; medication_name: string | null; quantity: number | string | null; unit: string | null }; onClose: () => void }) {
    const form = useForm({ void_reason: '' });
    const submit = () => {
        form.post(`/emar/destructions/${destruction.id}/void`, { preserveScroll: true, onSuccess: () => { toast.success('Destruction record voided'); onClose(); }, onError: () => toast.error('A reason is required to void a record') });
    };
    return (
        <MedsWizardDialog open onClose={onClose} title="Void destruction record" description="Correct an erroneous entry. The record is retained and shown as voided — it is never deleted." railIcon={Ban} railTitle="Void record" railSubtitle="Immutable register" steps={[{ key: 'reason', label: 'Reason', blurb: 'Why void', icon: Ban }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={form.processing}>Cancel</Button><Button variant="destructive" onClick={submit} disabled={form.processing || !form.data.void_reason.trim()}>Void record</Button></>}>
            <StepHead icon={Ban} title="Void this record" blurb="Voiding keeps the original entry in the register, struck through, with this reason attached." />
            <div className="mb-4 rounded-lg border px-4">
                <SummaryRow label="Medication" value={destruction.medication_name ?? '—'} />
                <SummaryRow label="Quantity" value={`${destruction.quantity ?? '—'} ${destruction.unit ?? ''}`} />
            </div>
            <Field label="Reason for voiding" required error={form.errors.void_reason} span>
                <Input value={form.data.void_reason} onChange={(e) => form.setData('void_reason', e.target.value)} placeholder="e.g. Duplicate entry / wrong quantity recorded" />
            </Field>
        </MedsWizardDialog>
    );
}

export function CdPill({ label, tone }: { label: string; tone: string }) {
    return <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize', tone)}>{label}</span>;
}

export const CD_ICONS = { Ban, Lock, FileWarning };
