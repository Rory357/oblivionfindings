/* Stock movement — BUILD-NEW modal on the shared Add-Client wizard chrome.
 * Receive (emar.stock.receive) or adjust (emar.stock.adjust) a medication's
 * stock. Both endpoints key on client_medication_id. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    createMedicationMutationReplayState,
    emarMutationWasAccepted,
    prepareMedicationMutationReplayState,
    submitEmarMutation,
} from '@/lib/emar-offline';
import { router } from '@inertiajs/react';
import { ClipboardCheck, Info, Package, PackagePlus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { genericStockMedications } from '../medication-stock-governance';
import type { MedicationOption } from './cd-register-modal';
import type { ClientOption } from './report-error-modal';

const STEPS = [
    {
        key: 'action',
        label: 'Action',
        blurb: 'Receive or adjust',
        icon: Package,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'Quantity & batch',
        icon: PackagePlus,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Save change',
        icon: ClipboardCheck,
    },
];

export function StockMovementModal({
    open,
    onClose,
    clients,
    medications,
    initialClientId,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    medications: MedicationOption[];
    initialClientId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const stockReplay = useRef(createMedicationMutationReplayState());
    const [action, setAction] = useState<'receive' | 'adjust'>('receive');
    const [clientId, setClientId] = useState(
        initialClientId ? String(initialClientId) : '',
    );
    const [medId, setMedId] = useState('');
    const [quantity, setQuantity] = useState('');
    const [newQuantity, setNewQuantity] = useState('');
    const [reason, setReason] = useState('');
    const [batch, setBatch] = useState('');
    const [expiry, setExpiry] = useState('');

    // Seed the client from the triggering row (if any) on each opening.
    useEffect(() => {
        if (open) {
            stockReplay.current = createMedicationMutationReplayState();
            setClientId(initialClientId ? String(initialClientId) : '');
            setMedId('');
        }
    }, [open, initialClientId]);

    const reset = () => {
        setStep(0);
        setAction('receive');
        setClientId(initialClientId ? String(initialClientId) : '');
        setMedId('');
        setQuantity('');
        setNewQuantity('');
        setReason('');
        setBatch('');
        setExpiry('');
    };

    const close = () => {
        reset();
        onClose();
    };

    const clientMeds = genericStockMedications(medications).filter(
        (m) => String(m.client_id) === clientId,
    );
    const step1Ok = clientId && medId;
    const step2Ok =
        action === 'receive'
            ? quantity.trim() && Number(quantity) > 0
            : newQuantity.trim() !== '' && reason.trim().length > 0;

    const submit = async () => {
        setSaving(true);
        const url =
            action === 'receive' ? '/emar/stock/receive' : '/emar/stock/adjust';
        const payload =
            action === 'receive'
                ? {
                      client_medication_id: Number(medId),
                      quantity,
                      batch_number: batch || null,
                      expiry_date: expiry || null,
                  }
                : {
                      client_medication_id: Number(medId),
                      new_quantity: newQuantity,
                      reason,
                  };
        stockReplay.current = prepareMedicationMutationReplayState(
            stockReplay.current,
            { action, client_id: Number(clientId), ...payload },
        );
        try {
            const result = await submitEmarMutation(
                url,
                {
                    ...payload,
                    client_request_uuid: stockReplay.current.uuid,
                },
                {
                    action: 'stock_update',
                    successMessage:
                        action === 'receive'
                            ? 'Stock received'
                            : 'Stock count adjusted',
                },
            );
            if (emarMutationWasAccepted(result.status)) {
                stockReplay.current = createMedicationMutationReplayState();
                close();
                router.reload({
                    only: [
                        'stockItems',
                        'lowStockCount',
                        'expiringCount',
                        'expiredCount',
                    ],
                });
            }
        } catch {
            toast.error('Could not save the stock change');
        } finally {
            setSaving(false);
        }
    };

    const medName = clientMeds.find((m) => String(m.id) === medId)?.name ?? '—';
    const clientName =
        clients.find((c) => String(c.id) === clientId)?.name ?? '—';

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
                    <Package className="h-4 w-4" />
                    {saving ? 'Saving…' : 'Save stock change'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Stock movement"
            description="Receive new stock or adjust the on-hand count for a medication."
            railIcon={Package}
            railTitle="Stock movement"
            railSubtitle="Receive or adjust"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={Package}
                        title="Stock action"
                        blurb="What kind of change, and for which medication?"
                    />
                    <Field label="Action" span>
                        <Segmented
                            value={action}
                            onChange={setAction}
                            options={[
                                { value: 'receive', label: 'Receive stock' },
                                { value: 'adjust', label: 'Adjust count' },
                            ]}
                        />
                    </Field>
                    <Field label="Client" required>
                        <SelectInput
                            value={clientId}
                            onChange={(v) => {
                                setClientId(v);
                                setMedId('');
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
                    <Field label="Medication" required>
                        <SelectInput
                            value={medId}
                            onChange={setMedId}
                            placeholder={
                                clientId
                                    ? clientMeds.length
                                        ? 'Select medication'
                                        : 'No medications'
                                    : 'Select a client first'
                            }
                            options={clientMeds.map((m) => ({
                                value: String(m.id),
                                label: m.name,
                            }))}
                        />
                    </Field>
                </div>
            ) : step === 1 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={PackagePlus}
                        title={
                            action === 'receive'
                                ? 'Receipt details'
                                : 'Adjustment details'
                        }
                        blurb={
                            action === 'receive'
                                ? 'How much arrived?'
                                : 'Set the corrected count and why.'
                        }
                    />
                    {action === 'receive' ? (
                        <>
                            <Field label="Quantity received" required>
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    step={0.01}
                                    value={quantity}
                                    onChange={(e) =>
                                        setQuantity(e.target.value)
                                    }
                                    placeholder="0"
                                />
                            </Field>
                            <Field label="Batch number">
                                <Input
                                    value={batch}
                                    onChange={(e) => setBatch(e.target.value)}
                                    placeholder="Optional"
                                />
                            </Field>
                            <Field label="Expiry date" span>
                                {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in wizard primitives. */}
                                <input
                                    type="date"
                                    value={expiry}
                                    onChange={(e) => setExpiry(e.target.value)}
                                    className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                                />
                            </Field>
                        </>
                    ) : (
                        <>
                            <Field label="New on-hand count" required>
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    step={0.01}
                                    value={newQuantity}
                                    onChange={(e) =>
                                        setNewQuantity(e.target.value)
                                    }
                                    placeholder="0"
                                />
                            </Field>
                            <Field label="Reason for adjustment" required span>
                                <Textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    rows={3}
                                    placeholder="e.g. recount after discrepancy, damaged stock disposed"
                                />
                            </Field>
                        </>
                    )}
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review"
                        blurb="Confirm the stock change."
                    />
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow
                                label="Action"
                                value={
                                    action === 'receive'
                                        ? 'Receive stock'
                                        : 'Adjust count'
                                }
                            />
                            <SummaryRow label="Client" value={clientName} />
                            <SummaryRow label="Medication" value={medName} />
                            {action === 'receive' ? (
                                <>
                                    <SummaryRow
                                        label="Quantity"
                                        value={quantity || '—'}
                                    />
                                    <SummaryRow
                                        label="Batch / expiry"
                                        value={
                                            [batch, expiry]
                                                .filter(Boolean)
                                                .join(' · ') || '—'
                                        }
                                    />
                                </>
                            ) : (
                                <>
                                    <SummaryRow
                                        label="New count"
                                        value={newQuantity || '—'}
                                    />
                                    <SummaryRow
                                        label="Reason"
                                        value={reason || '—'}
                                    />
                                </>
                            )}
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        Stock changes are audited. Receipts update batch &amp;
                        expiry tracking; adjustments record the reason for the
                        recount.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default StockMovementModal;
