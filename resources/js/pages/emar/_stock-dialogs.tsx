/* eslint-disable no-restricted-syntax -- wizard position/variance panes are custom-layout
   bordered surfaces inside the wizard shell, not Card components; all colours are tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import { Field, SelectInput, Segmented, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { submitEmarMutation } from '@/lib/emar-offline';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { router, useForm } from '@inertiajs/react';
import { Barcode, Check, ClipboardCheck, Package, ShieldCheck, ShoppingCart, Snowflake, Truck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type StockMed = {
    id: number;
    name: string;
    client_id: number;
    controlled?: boolean;
    client?: { first_name: string; last_name: string } | null;
    scan_verification?: MedicationScanVerification | null;
};
export type StockRow = {
    id: number;
    medication_id: number;
    medication_name: string | null;
    medication_dose: string | null;
    client_id: number | null;
    client_name: string;
    site_id: number | null;
    site_name: string | null;
    on_hand: number;
    unit: string;
    reorder_level: number | null;
    reorder_quantity: number | null;
    last_counted_at: string | null;
    expiry_date: string | null;
    batch_number: string | null;
    supplier_name: string | null;
    controlled: boolean;
    storage_condition: string;
    requires_cold_chain: boolean;
    is_low: boolean;
    is_expired: boolean;
    is_expiring_soon: boolean;
    is_expiring_90: boolean;
    scan_verification?: MedicationScanVerification | null;
};
export type StaffOpt = { id: number; name: string };
export type ClientOpt = { id: number; first_name: string; last_name: string };

const STORAGE_OPTIONS = [
    { value: 'ambient', label: 'Ambient' },
    { value: 'fridge', label: 'Fridge (2–8°C)' },
    { value: 'controlled_room', label: 'Controlled room' },
];

function medOptions(meds: StockMed[]) {
    return meds.map((m) => ({ value: String(m.id), label: m.client ? `${m.name} · ${m.client.first_name} ${m.client.last_name}` : m.name }));
}
const stockFor = (rows: StockRow[], medId: string | number | null) => rows.find((r) => String(r.medication_id) === String(medId)) ?? null;
const refreshStock = () => router.reload({ only: ['stockItems', 'lowStockCount', 'expiringCount', 'expiredCount', 'controlledRegister', 'pharmacyOrders'] });

// ── New pharmacy order (4-step) ──────────────────────────────────────────────
export function NewPharmacyOrderDialog({ clients, medications, stockItems, defaultClientId, defaultMedId, onClose }: { clients: ClientOpt[]; medications: StockMed[]; stockItems: StockRow[]; defaultClientId?: number | null; defaultMedId?: number | null; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const presetRow = defaultMedId ? stockFor(stockItems, defaultMedId) : null;
    const form = useForm({
        client_id: defaultClientId ? String(defaultClientId) : presetRow?.client_id ? String(presetRow.client_id) : '',
        client_medication_id: defaultMedId ? String(defaultMedId) : '',
        pharmacy_name: '',
        pharmacy_phone: '',
        order_type: 'routine',
        quantity_ordered: presetRow?.reorder_quantity ? String(presetRow.reorder_quantity) : '',
        order_notes: '',
        batch_number: '',
        batch_expiry: '',
    });
    const meds = medications.filter((m) => !form.data.client_id || m.client_id === Number(form.data.client_id));
    const position = stockFor(stockItems, form.data.client_medication_id);
    const pickMed = (id: string) => {
        const row = stockFor(stockItems, id);
        form.setData({ ...form.data, client_medication_id: id, quantity_ordered: form.data.quantity_ordered || (row?.reorder_quantity ? String(row.reorder_quantity) : '') });
    };
    const submit = () => form.post('/emar/stock/pharmacy-orders', { preserveScroll: true, onSuccess: () => { toast.success('Pharmacy order placed'); onClose(); }, onError: () => toast.error('Please check the order details') });
    const valid = [!!form.data.client_id && !!form.data.client_medication_id, !!form.data.pharmacy_name && !!form.data.quantity_ordered, true, true];
    return (
        <MedsWizardDialog open onClose={onClose} title="New pharmacy order" description="Order medication stock from a pharmacy." railIcon={ShoppingCart} railTitle="Pharmacy order" railSubtitle="Resupply" steps={[{ key: 'med', label: 'Client & medication', blurb: 'What to order', icon: Package }, { key: 'details', label: 'Order details', blurb: 'Pharmacy & qty', icon: ShoppingCart }, { key: 'batch', label: 'Batch & delivery', blurb: 'Optional', icon: Truck }, { key: 'review', label: 'Review & place', blurb: 'Confirm', icon: Check }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 3 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={form.processing}>Place order</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={Package} title="Client & medication" blurb="Who is this order for?" />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Client" required>
                            <SelectInput value={form.data.client_id} onChange={(v) => form.setData({ ...form.data, client_id: v, client_medication_id: '' })} placeholder="Select client…" options={clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` }))} />
                        </Field>
                        <Field label="Medication" required>
                            <SelectInput value={form.data.client_medication_id} onChange={pickMed} placeholder="Select medication…" options={medOptions(meds)} />
                        </Field>
                    </div>
                    {position && (
                        <div className="mt-4 rounded-lg border px-4">
                            <SummaryRow label="On hand" value={`${position.on_hand} ${position.unit}`} />
                            <SummaryRow label="Reorder level" value={position.reorder_level ?? '—'} />
                            <SummaryRow label="Suggested order qty" value={position.reorder_quantity ?? '—'} />
                        </div>
                    )}
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ShoppingCart} title="Order details" blurb="Pharmacy and quantity." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Pharmacy" required error={form.errors.pharmacy_name}>
                            <Input value={form.data.pharmacy_name} onChange={(e) => form.setData('pharmacy_name', e.target.value)} placeholder="Pharmacy name" />
                        </Field>
                        <Field label="Pharmacy phone" error={form.errors.pharmacy_phone}>
                            <Input value={form.data.pharmacy_phone} onChange={(e) => form.setData('pharmacy_phone', e.target.value)} placeholder="Phone" />
                        </Field>
                        <Field label="Order type">
                            <Segmented value={form.data.order_type} onChange={(v) => form.setData('order_type', v)} options={[{ value: 'routine', label: 'Routine' }, { value: 'repeat', label: 'Repeat' }, { value: 'urgent', label: 'Urgent' }]} />
                        </Field>
                        <Field label="Quantity" required error={form.errors.quantity_ordered}>
                            <Input type="number" min={1} value={form.data.quantity_ordered} onChange={(e) => form.setData('quantity_ordered', e.target.value)} />
                        </Field>
                        <Field label="Notes" span error={form.errors.order_notes}>
                            <Input value={form.data.order_notes} onChange={(e) => form.setData('order_notes', e.target.value)} placeholder="Special instructions" />
                        </Field>
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Truck} title="Batch & delivery" blurb="Optional — record if known now." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Batch number" error={form.errors.batch_number}>
                            <Input value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} placeholder="Batch" />
                        </Field>
                        <Field label="Batch expiry" error={form.errors.batch_expiry}>
                            <Input type="date" value={form.data.batch_expiry} onChange={(e) => form.setData('batch_expiry', e.target.value)} />
                        </Field>
                    </div>
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead icon={Check} title="Review & place" blurb="Confirm the order." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Medication" value={medications.find((m) => String(m.id) === form.data.client_medication_id)?.name ?? '—'} />
                        <SummaryRow label="Pharmacy" value={form.data.pharmacy_name} />
                        <SummaryRow label="Quantity" value={form.data.quantity_ordered} />
                        <SummaryRow label="Type" value={form.data.order_type} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Receive stock (4-step, scan-gated, offline-aware) ────────────────────────
export function ReceiveStockDialog({ medications, defaultMedId, onClose }: { medications: StockMed[]; defaultMedId?: number | null; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [capture, setCapture] = useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [busy, setBusy] = useState(false);
    const form = useForm({
        client_medication_id: defaultMedId ? String(defaultMedId) : '',
        quantity: '',
        notes: '',
        batch_number: '',
        expiry_date: '',
        scan_code: '',
        scan_source: 'manual' as 'manual' | 'scanner',
        scan_verified: false,
        scan_match_source: '',
    });
    const med = medications.find((m) => String(m.id) === form.data.client_medication_id) ?? null;
    const scanRequired = !!med?.scan_verification;
    const scanOk = !scanRequired || hasVerifiedMedicationScan(capture);

    async function submit() {
        form.clearErrors();
        if (scanRequired && !hasVerifiedMedicationScan(capture)) {
            form.setError('scan_code', 'Verify the medication code before receiving stock.');
            setStep(2);
            return;
        }
        setBusy(true);
        try {
            const result = await submitEmarMutation('/emar/stock/receive', {
                ...form.data,
                quantity: Number(form.data.quantity),
                expiry_date: form.data.expiry_date || null,
                notes: form.data.notes || null,
                batch_number: form.data.batch_number || null,
                ...toMedicationScanPayload(capture),
            }, { successMessage: 'Stock receipt recorded.', queuedMessage: 'Stock receipt saved offline and will sync when the device reconnects.' });
            if (result.status === 'conflict') return;
            onClose();
            if (result.status !== 'queued') refreshStock();
        } catch (error: unknown) {
            applyFormRequestErrors(error, (field, value) => (form.setError as (f: string, v: string) => void)(field, value), 'Failed to record stock receipt.');
            setStep(1);
        } finally {
            setBusy(false);
        }
    }

    const valid = [!!form.data.client_medication_id, !!form.data.quantity, scanOk, scanOk];
    return (
        <MedsWizardDialog open onClose={onClose} title="Receive stock" description="Record incoming medication stock into inventory." railIcon={Truck} railTitle="Receive stock" railSubtitle="Inbound" steps={[{ key: 'med', label: 'Medication', blurb: 'What arrived', icon: Package }, { key: 'qty', label: 'Quantity & batch', blurb: 'Amount received', icon: ClipboardCheck }, { key: 'scan', label: 'Scan verify', blurb: 'Confirm identity', icon: Barcode }, { key: 'confirm', label: 'Confirm', blurb: 'Add to stock', icon: Check }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 3 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={busy || !scanOk}>{busy ? 'Recording…' : 'Add to stock'}</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={Package} title="Medication" blurb="Match the delivery to a medication." />
                    <Field label="Medication" required span error={form.errors.client_medication_id}>
                        <SelectInput value={form.data.client_medication_id} onChange={(v) => { form.setData('client_medication_id', v); form.clearErrors('scan_code'); setCapture(emptyMedicationScanCapture()); }} placeholder="Select medication…" options={medOptions(medications)} />
                    </Field>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ClipboardCheck} title="Quantity & batch" blurb="How much arrived." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Quantity received" required error={form.errors.quantity}>
                            <Input type="number" min={1} value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </Field>
                        <Field label="Batch number" error={form.errors.batch_number}>
                            <Input value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} placeholder="Batch" />
                        </Field>
                        <Field label="Expiry date" error={form.errors.expiry_date}>
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                        </Field>
                        <Field label="Notes" error={form.errors.notes}>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Optional" />
                        </Field>
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={Barcode} title="Scan verify" blurb={scanRequired ? 'Scan or enter the code to confirm identity.' : 'No scan verification configured for this medication.'} />
                    <MedicationScanVerificationPanel
                        clientId={med ? med.client_id : null}
                        medicationId={med?.id ?? null}
                        scanVerification={med?.scan_verification}
                        resetKey={`receive-${form.data.client_medication_id}`}
                        requirementText="Verification is required before receiving stock."
                        onChange={(c) => { form.clearErrors('scan_code'); setCapture(c); }}
                    />
                    {form.errors.scan_code && <p className="mt-2 text-sm text-status-critical">{form.errors.scan_code}</p>}
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead icon={Check} title="Confirm" blurb="Review and add to stock." />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Medication" value={med?.name ?? '—'} />
                        <SummaryRow label="Quantity" value={form.data.quantity} />
                        <SummaryRow label="Batch" value={form.data.batch_number || '—'} />
                        <SummaryRow label="Verified" value={scanRequired ? (scanOk ? 'Yes' : 'No') : 'Not required'} />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Stock count (3-step, CD-aware) ───────────────────────────────────────────
export function StockCountDialog({ medications, stockItems, witnesses, defaultMedId, controlledOnly, onClose }: { medications: StockMed[]; stockItems: StockRow[]; witnesses: StaffOpt[]; defaultMedId?: number | null; controlledOnly?: boolean; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const meds = controlledOnly ? medications.filter((m) => m.controlled) : medications;
    const form = useForm({
        client_medication_id: defaultMedId ? String(defaultMedId) : '',
        counted: '',
        note: '',
        witnessed_by: '',
        confirmed: false,
    });
    const med = medications.find((m) => String(m.id) === form.data.client_medication_id) ?? null;
    const row = stockFor(stockItems, form.data.client_medication_id);
    const isCd = !!med?.controlled;
    const expected = row?.on_hand ?? 0;
    const variance = form.data.counted === '' ? null : Number(form.data.counted) - expected;

    const submit = () => {
        if (isCd) {
            const payload = { client_id: med?.client_id, medication_name: med?.name, expected_balance: expected, actual_balance: Number(form.data.counted), witnessed_by: Number(form.data.witnessed_by), discrepancy_notes: form.data.note || null };
            router.post('/emar/controlled/balance-check', payload, { preserveScroll: true, onSuccess: () => { toast.success('Balance check recorded'); onClose(); }, onError: () => toast.error('Check the count — a witness (not yourself) is required') });
        } else {
            router.post('/emar/stock/adjust', { client_medication_id: Number(form.data.client_medication_id), new_quantity: Number(form.data.counted), reason: form.data.note || 'Physical stock count' }, { preserveScroll: true, onSuccess: () => { toast.success('Stock count recorded'); onClose(); }, onError: () => toast.error('Could not record the count') });
        }
    };
    const valid = [!!form.data.client_medication_id, form.data.counted !== '', form.data.confirmed && (!isCd || !!form.data.witnessed_by)];
    return (
        <MedsWizardDialog open onClose={onClose} title="Stock count" description="Count physical stock and reconcile against the register." railIcon={ShieldCheck} railTitle="Stock count" railSubtitle={isCd ? 'CD balance check' : 'Physical count'} steps={[{ key: 'med', label: 'Select medication', blurb: 'What to count', icon: Package }, { key: 'count', label: 'Physical count', blurb: 'Counted units', icon: ClipboardCheck }, { key: 'sign', label: isCd ? 'Witness & sign' : 'Confirm & sign', blurb: 'Reconcile', icon: ShieldCheck }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 2 ? <Button onClick={() => setStep(step + 1)} disabled={!valid[step]}>Continue</Button> : <Button onClick={submit} disabled={!valid[2]}>Submit count</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={Package} title="Select medication" blurb="Which stock are you counting?" />
                    <Field label="Medication" required span>
                        <SelectInput value={form.data.client_medication_id} onChange={(v) => form.setData('client_medication_id', v)} placeholder="Select medication…" options={medOptions(meds)} />
                    </Field>
                    {row && (
                        <div className="mt-4 rounded-lg border px-4">
                            <SummaryRow label="Register / expected balance" value={`${expected} ${row.unit}`} />
                            {isCd && <SummaryRow label="Controlled drug" value="Yes — witness required" />}
                        </div>
                    )}
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ClipboardCheck} title="Physical count" blurb="Enter the counted units." />
                    <Field label="Counted units" required>
                        <Input type="number" min={0} value={form.data.counted} onChange={(e) => form.setData('counted', e.target.value)} />
                    </Field>
                    {variance !== null && (
                        <div className={`mt-4 rounded-lg border px-4 py-3 text-sm ${variance === 0 ? 'border-status-success/30 bg-status-success-bg/60 text-status-success' : 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'}`}>
                            {variance === 0 ? 'Reconciled — counted matches the register balance.' : `Discrepancy ${variance > 0 ? '+' : ''}${variance} ${row?.unit ?? ''} — investigate and witness before close of shift.`}
                        </div>
                    )}
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead icon={ShieldCheck} title={isCd ? 'Witness & sign' : 'Confirm & sign'} blurb={isCd ? 'A second person must witness the count.' : 'Confirm the recorded count.'} />
                    {isCd && (
                        <Field label="Witness" required>
                            <SelectInput value={form.data.witnessed_by} onChange={(v) => form.setData('witnessed_by', v)} placeholder="Select witness…" options={witnesses.map((w) => ({ value: String(w.id), label: w.name }))} />
                        </Field>
                    )}
                    <Field label="Reconciliation note" span>
                        <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder={variance && variance !== 0 ? 'Explain the discrepancy' : 'Optional note'} />
                    </Field>
                    <label className="mt-3 flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.confirmed} onChange={(e) => form.setData('confirmed', e.target.checked)} className="h-4 w-4 rounded border-border" />
                        I confirm I physically counted this stock in person.
                    </label>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Adjust stock (2-step: details + quantity) ────────────────────────────────
export function AdjustStockDialog({ item, onClose }: { item: StockRow; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        reorder_level: item.reorder_level !== null ? String(item.reorder_level) : '',
        reorder_quantity: item.reorder_quantity !== null ? String(item.reorder_quantity) : '',
        expiry_date: item.expiry_date ?? '',
        batch_number: item.batch_number ?? '',
        supplier_name: item.supplier_name ?? '',
        storage_condition: item.storage_condition ?? 'ambient',
        new_quantity: '',
        reason: '',
    });
    const qtyChanged = form.data.new_quantity !== '' && Number(form.data.new_quantity) !== item.on_hand;

    const submit = () => {
        const details = { reorder_level: form.data.reorder_level, reorder_quantity: form.data.reorder_quantity, expiry_date: form.data.expiry_date, batch_number: form.data.batch_number, supplier_name: form.data.supplier_name, storage_condition: form.data.storage_condition };
        form.transform(() => details);
        form.patch(`/emar/stock/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (!qtyChanged) { toast.success('Stock details saved'); onClose(); return; }
                router.post('/emar/stock/adjust', { client_medication_id: item.medication_id, new_quantity: Number(form.data.new_quantity), reason: form.data.reason }, { preserveScroll: true, onSuccess: () => { toast.success('Stock updated'); onClose(); }, onError: () => toast.error('Adjustment failed — a reason is required') });
            },
            onError: () => toast.error('Could not save stock details'),
        });
    };
    const valid = [true, !qtyChanged || !!form.data.reason.trim()];
    return (
        <MedsWizardDialog open onClose={onClose} title="Adjust stock" description={`Update stock details and correct the on-hand count for ${item.medication_name ?? 'this medication'}.`} railIcon={Package} railTitle="Adjust stock" railSubtitle={item.medication_name ?? 'Stock item'} steps={[{ key: 'details', label: 'Stock details', blurb: 'Reorder & batch', icon: Package }, { key: 'qty', label: 'Adjust quantity', blurb: 'With reason', icon: ClipboardCheck }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} disabled={form.processing}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 1 ? <Button onClick={() => setStep(1)}>Continue</Button> : <Button onClick={submit} disabled={form.processing || !valid[1]}>Save changes</Button>}</>}>
            {step === 0 && (
                <>
                    <StepHead icon={Package} title="Stock details" blurb="Reorder thresholds, batch and storage." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Reorder level" error={form.errors.reorder_level}>
                            <Input type="number" min={0} value={form.data.reorder_level} onChange={(e) => form.setData('reorder_level', e.target.value)} placeholder="Reorder at or below" />
                        </Field>
                        <Field label="Reorder quantity" error={form.errors.reorder_quantity}>
                            <Input type="number" min={1} value={form.data.reorder_quantity} onChange={(e) => form.setData('reorder_quantity', e.target.value)} placeholder="Suggested order qty" />
                        </Field>
                        <Field label="Expiry date" error={form.errors.expiry_date}>
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                        </Field>
                        <Field label="Batch number" error={form.errors.batch_number}>
                            <Input value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} placeholder="Batch" />
                        </Field>
                        <Field label="Supplier" error={form.errors.supplier_name}>
                            <Input value={form.data.supplier_name} onChange={(e) => form.setData('supplier_name', e.target.value)} placeholder="Supplier / pharmacy" />
                        </Field>
                        <Field label="Storage" error={form.errors.storage_condition}>
                            <SelectInput value={form.data.storage_condition} onChange={(v) => form.setData('storage_condition', v)} placeholder="Storage…" options={STORAGE_OPTIONS} />
                        </Field>
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead icon={ClipboardCheck} title="Adjust quantity" blurb="Correct the on-hand count — a reason is logged." />
                    <div className="mb-4 rounded-lg border px-4">
                        <SummaryRow label="Current on hand" value={`${item.on_hand} ${item.unit}`} />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="New quantity" error={form.errors.new_quantity}>
                            <Input type="number" min={0} value={form.data.new_quantity} onChange={(e) => form.setData('new_quantity', e.target.value)} placeholder="Leave blank to keep" />
                        </Field>
                        <Field label="Reason" required={qtyChanged} error={form.errors.reason}>
                            <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Why is the count changing?" />
                        </Field>
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

export const STOCK_ICONS = { Snowflake };
