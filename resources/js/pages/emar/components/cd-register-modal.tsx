/* CD register entry — BUILD-NEW modal on the shared Add-Client wizard chrome.
 * Posts to emar.controlled.entries.store with the idempotency envelope
 * (client_request_uuid). Witness is mandatory and must differ from the signer. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { ClipboardCheck, Info, Lock, UserCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import type { ClientOption } from './report-error-modal';

export type MedicationOption = {
    id: number;
    client_id: number;
    name: string;
    unit: string | null;
    controlled: boolean;
};
export type WitnessOption = { id: number; name: string };

const STEPS = [
    { key: 'entry', label: 'Entry', blurb: 'Type & quantity', icon: Lock },
    {
        key: 'witness',
        label: 'Witness & balance',
        blurb: 'Counter-sign',
        icon: UserCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Sign to register',
        icon: ClipboardCheck,
    },
];

const ENTRY_TYPES = [
    { value: 'receipt', label: 'Receipt — stock received' },
    { value: 'administration', label: 'Administration — given to client' },
    { value: 'disposal', label: 'Disposal / destruction' },
    { value: 'transfer_in', label: 'Transfer in' },
    { value: 'transfer_out', label: 'Transfer out' },
    { value: 'balance_check', label: 'Balance check / count' },
    { value: 'adjustment', label: 'Adjustment' },
];

const labelOf = (opts: { value: string; label: string }[], v: string) =>
    opts.find((o) => o.value === v)?.label ?? '—';

function newUuid(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto)
        return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r =
            (Math.floor(Date.now() / 1000) + Math.floor(performance.now())) %
            16;
        const v = c === 'x' ? r : (r % 4) + 8;
        return v.toString(16);
    });
}

export function CdRegisterModal({
    open,
    onClose,
    clients,
    medications,
    witnesses,
    currentUserId,
    initialClientId,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    medications: MedicationOption[];
    witnesses: WitnessOption[];
    currentUserId: number;
    initialClientId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [uuid, setUuid] = useState('');
    const [clientId, setClientId] = useState(
        initialClientId ? String(initialClientId) : '',
    );
    const [medName, setMedName] = useState('');
    const [entryType, setEntryType] = useState('');
    const [quantity, setQuantity] = useState('');
    const [unit, setUnit] = useState('');
    const [onHandBefore, setOnHandBefore] = useState('');
    const [onHandAfter, setOnHandAfter] = useState('');
    const [witnessedBy, setWitnessedBy] = useState('');
    const [batch, setBatch] = useState('');
    const [expiry, setExpiry] = useState('');
    const [notes, setNotes] = useState('');

    // Stamp one idempotency key per modal opening and seed the client from the
    // triggering row (if any).
    useEffect(() => {
        if (open) {
            setUuid(newUuid());
            setClientId(initialClientId ? String(initialClientId) : '');
        }
    }, [open, initialClientId]);

    const reset = () => {
        setStep(0);
        setClientId(initialClientId ? String(initialClientId) : '');
        setMedName('');
        setEntryType('');
        setQuantity('');
        setUnit('');
        setOnHandBefore('');
        setOnHandAfter('');
        setWitnessedBy('');
        setBatch('');
        setExpiry('');
        setNotes('');
    };

    const close = () => {
        reset();
        onClose();
    };

    const clientControlledMeds = medications.filter(
        (m) => String(m.client_id) === clientId && m.controlled,
    );

    const step1Ok = clientId && medName.trim() && entryType && quantity.trim();
    const step2Ok = witnessedBy.length > 0;

    const submit = () => {
        setSaving(true);
        router.post(
            '/emar/controlled/entries',
            {
                client_id: Number(clientId),
                medication_name: medName,
                entry_type: entryType,
                quantity: Number(quantity),
                unit: unit || null,
                on_hand_before: onHandBefore ? Number(onHandBefore) : null,
                on_hand_after: onHandAfter ? Number(onHandAfter) : null,
                witnessed_by: Number(witnessedBy),
                batch_number: batch || null,
                expiry_date: expiry || null,
                notes: notes || null,
                client_request_uuid: uuid,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        'Controlled-drug entry signed to the register',
                    );
                    close();
                },
                onError: () => toast.error('Could not sign the CD entry'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const clientName =
        clients.find((c) => String(c.id) === clientId)?.name ?? '—';
    const witnessName =
        witnesses.find((w) => String(w.id) === witnessedBy)?.name ?? '—';
    const eligibleWitnesses = witnesses.filter((w) => w.id !== currentUserId);

    const footer = (
        <>
            <Button
                variant="ghost"
                onClick={step === 0 ? close : () => setStep((s) => s - 1)}
                disabled={saving}
            >
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step < 2 ? (
                <Button
                    onClick={() => setStep((s) => s + 1)}
                    disabled={
                        (step === 0 && !step1Ok) || (step === 1 && !step2Ok)
                    }
                >
                    Continue
                </Button>
            ) : (
                <Button onClick={submit} disabled={saving}>
                    <Lock className="h-4 w-4" />
                    {saving ? 'Signing…' : 'Sign to CD register'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Controlled-drug register entry"
            description="Record a controlled-drug movement to the register with a witness."
            railIcon={Lock}
            railTitle="CD register entry"
            railSubtitle="Witnessed & balanced"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={Lock}
                        title="Register entry"
                        blurb="What moved, and how much?"
                    />
                    <Field label="Client" required>
                        <SelectInput
                            value={clientId}
                            onChange={(v) => {
                                setClientId(v);
                                setMedName('');
                            }}
                            placeholder="Select client"
                            options={clients.map((c) => ({
                                value: String(c.id),
                                label: c.site
                                    ? `${c.name} · ${c.site}`
                                    : c.name,
                            }))}
                        />
                    </Field>
                    <Field label="Controlled drug" required>
                        {clientControlledMeds.length > 0 ? (
                            <SelectInput
                                value={medName}
                                onChange={(v) => {
                                    setMedName(v);
                                    const m = clientControlledMeds.find(
                                        (x) => x.name === v,
                                    );
                                    if (m?.unit) setUnit(m.unit);
                                }}
                                placeholder="Select medication"
                                options={clientControlledMeds.map((m) => ({
                                    value: m.name,
                                    label: m.name,
                                }))}
                            />
                        ) : (
                            <Input
                                value={medName}
                                onChange={(e) => setMedName(e.target.value)}
                                placeholder="Medication name"
                            />
                        )}
                    </Field>
                    <Field label="Entry type" required span>
                        <SelectInput
                            value={entryType}
                            onChange={setEntryType}
                            placeholder="Select entry type"
                            options={ENTRY_TYPES}
                        />
                    </Field>
                    <Field label="Quantity" required>
                        <Input
                            type="number"
                            inputMode="decimal"
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            placeholder="0"
                        />
                    </Field>
                    <Field label="Unit">
                        <Input
                            value={unit}
                            onChange={(e) => setUnit(e.target.value)}
                            placeholder="e.g. tablets, mg"
                        />
                    </Field>
                </div>
            ) : step === 1 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={UserCheck}
                        title="Witness & balance"
                        blurb="Counter-signature and running balance."
                    />
                    <Field label="Balance before">
                        <Input
                            type="number"
                            inputMode="decimal"
                            value={onHandBefore}
                            onChange={(e) => setOnHandBefore(e.target.value)}
                            placeholder="On hand before"
                        />
                    </Field>
                    <Field label="Balance after">
                        <Input
                            type="number"
                            inputMode="decimal"
                            value={onHandAfter}
                            onChange={(e) => setOnHandAfter(e.target.value)}
                            placeholder="On hand after"
                        />
                    </Field>
                    <Field
                        label="Witness"
                        required
                        hint="must differ from you"
                        span
                    >
                        <SelectInput
                            value={witnessedBy}
                            onChange={setWitnessedBy}
                            placeholder={
                                eligibleWitnesses.length
                                    ? 'Select witness'
                                    : 'No eligible witnesses'
                            }
                            options={eligibleWitnesses.map((w) => ({
                                value: String(w.id),
                                label: w.name,
                            }))}
                        />
                    </Field>
                    <Field label="Batch number">
                        <Input
                            value={batch}
                            onChange={(e) => setBatch(e.target.value)}
                            placeholder="Optional"
                        />
                    </Field>
                    <Field label="Expiry date">
                        {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in wizard primitives. */}
                        <input
                            type="date"
                            value={expiry}
                            onChange={(e) => setExpiry(e.target.value)}
                            className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </Field>
                    <InfoCard icon={Info} tone="warn">
                        Controlled-drug entries are witnessed and immutable. The
                        running balance is the legal record — count carefully.
                    </InfoCard>
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & sign"
                        blurb="Confirm the register entry."
                    />
                    <Field label="Notes" span>
                        <Textarea
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={2}
                            placeholder="Optional context for the register entry"
                        />
                    </Field>
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Client" value={clientName} />
                            <SummaryRow label="Drug" value={medName || '—'} />
                            <SummaryRow
                                label="Entry"
                                value={labelOf(ENTRY_TYPES, entryType)}
                            />
                            <SummaryRow
                                label="Quantity"
                                value={`${quantity}${unit ? ` ${unit}` : ''}`}
                            />
                            <SummaryRow
                                label="Balance"
                                value={
                                    onHandBefore || onHandAfter
                                        ? `${onHandBefore || '—'} → ${onHandAfter || '—'}`
                                        : '—'
                                }
                            />
                            <SummaryRow
                                label="Witness"
                                value={witnessName}
                                tone="success"
                            />
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        Signing records this to the controlled-drug register
                        with an audit entry. The entry is idempotent — a retried
                        submit won't double-post.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default CdRegisterModal;
