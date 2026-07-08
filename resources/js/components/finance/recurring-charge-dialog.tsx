import { useForm } from '@inertiajs/react';
import { ListChecks, Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AmountField, formatMoney } from './money';
import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
    useWizard,
} from './wizard';

/** Named to avoid clashing with new-invoice-dialog's `ClientOption` ({id,name}) in the barrel. */
export type ChargeClientOption = { id: number; first_name: string; last_name: string };

/** An existing charge to prefill the wizard with (edit mode). */
export type EditableRecurringCharge = {
    id: number;
    client_id: number | string | null;
    description: string;
    amount: string | number;
    frequency: string;
    next_charge_date: string | null;
    is_active: boolean;
};

const FREQUENCIES = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annually', label: 'Annually' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Client, amount & schedule', icon: RefreshCw },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ListChecks },
];

/**
 * Recurring Charge wizard — create/edit a recurring client billing charge as a
 * stepper modal (Details → Review). Posts to `finance.recurring_charges.store`
 * or PUTs `finance.recurring_charges.update` with the exact payload the retired
 * Create/Edit pages sent (client_id, description, amount, frequency,
 * next_charge_date, + is_active on edit).
 */
export function RecurringChargeDialog({
    open,
    onClose,
    clients,
    charge,
}: {
    open: boolean;
    onClose: () => void;
    clients: ChargeClientOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    charge?: EditableRecurringCharge | null;
}) {
    const isEdit = !!charge;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        client_id: string;
        description: string;
        amount: string;
        frequency: string;
        next_charge_date: string;
        is_active: boolean;
    }>(charge ? {
        client_id: charge.client_id != null ? String(charge.client_id) : '',
        description: charge.description ?? '',
        amount: charge.amount != null ? String(charge.amount) : '',
        frequency: charge.frequency ?? 'monthly',
        next_charge_date: charge.next_charge_date ? String(charge.next_charge_date).slice(0, 10) : '',
        is_active: charge.is_active,
    } : {
        client_id: '',
        description: '',
        amount: '',
        frequency: 'monthly',
        next_charge_date: '',
        is_active: true,
    });
    const { data, setData, processing, errors } = form;

    const clientOptions = clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` }));
    const clientLabel = clientOptions.find((c) => c.value === data.client_id)?.label ?? '—';
    const frequencyLabel = FREQUENCIES.find((f) => f.value === data.frequency)?.label ?? data.frequency;

    const detailsValid =
        !!data.client_id
        && !!data.description.trim()
        && data.amount !== ''
        && Number(data.amount) >= 0
        && !!data.frequency
        && !!data.next_charge_date;

    const close = () => {
        setSucceeded(false);
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const startAnother = () => {
        setSucceeded(false);
        reset();
        form.reset();
        form.clearErrors();
    };

    const submit = () => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        };
        if (isEdit && charge) {
            form.put(`/finance/recurring-charges/${charge.id}`, opts);
        } else {
            form.post('/finance/recurring-charges', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit recurring charge' : 'New recurring charge'}
            description={isEdit ? 'Update this recurring billing charge' : 'Set up a recurring billing charge for a client'}
            railIcon={RefreshCw}
            railTitle={isEdit ? 'Edit Charge' : 'New Charge'}
            railSub="Recurring billing"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Charge"
            success={succeeded ? (
                <WizardSuccessPane
                    title={isEdit ? 'Charge updated' : 'Recurring charge created'}
                    blurb={isEdit
                        ? 'The recurring charge has been saved. Future runs will use the updated schedule and amount.'
                        : 'The charge is set up and will bill on its next charge date. You can edit or deactivate it from this list any time.'}
                    actions={
                        <>
                            {!isEdit && (
                                <Button variant="outline" onClick={startAnother}>
                                    <Plus className="h-4 w-4" /> Add another
                                </Button>
                            )}
                            <Button onClick={close}>Done</Button>
                        </>
                    }
                />
            ) : undefined}
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next} disabled={!detailsValid}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !detailsValid}>
                            {isEdit ? 'Save changes' : 'Create charge'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={RefreshCw} title="Charge details" blurb="Who is billed, how much, and how often." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Client" required error={errors.client_id}>
                            <SelectInput
                                value={data.client_id}
                                onChange={(v) => setData('client_id', v)}
                                placeholder="Select client"
                                options={clientOptions}
                            />
                        </Field>
                        <Field label="Frequency" required error={errors.frequency}>
                            <SelectInput
                                value={data.frequency}
                                onChange={(v) => setData('frequency', v)}
                                placeholder="Select frequency"
                                options={FREQUENCIES}
                            />
                        </Field>
                        <Field label="Description" span required error={errors.description}>
                            <Input
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="e.g. Weekly community access transport"
                            />
                        </Field>
                        <Field label="Amount (NZD)" required error={errors.amount}>
                            <AmountField
                                value={data.amount}
                                onValueChange={(v) => setData('amount', v)}
                                aria-label="Charge amount"
                            />
                        </Field>
                        <Field label="Next charge date" required error={errors.next_charge_date}>
                            <Input
                                type="date"
                                value={data.next_charge_date}
                                onChange={(e) => setData('next_charge_date', e.target.value)}
                            />
                        </Field>
                        {isEdit && (
                            <Field label="Status" span error={errors.is_active}>
                                <Segmented
                                    value={data.is_active ? 'active' : 'inactive'}
                                    onChange={(v) => setData('is_active', v === 'active')}
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        { value: 'inactive', label: 'Inactive' },
                                    ]}
                                />
                            </Field>
                        )}
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title={isEdit ? 'Review & save' : 'Review & create'}
                        blurb={isEdit ? 'Updates this recurring charge.' : 'Creates the recurring charge — it bills automatically from its next charge date.'}
                    />
                    <ReviewCard icon={RefreshCw} title="Recurring charge">
                        <ReviewRow label="Client" value={clientLabel} />
                        <ReviewRow label="Description" value={data.description || '—'} />
                        <ReviewRow label="Amount" value={formatMoney(data.amount)} />
                        <ReviewRow label="Frequency" value={frequencyLabel} />
                        <ReviewRow label="Next charge" value={data.next_charge_date || '—'} />
                        {isEdit && <ReviewRow label="Status" value={data.is_active ? 'Active' : 'Inactive'} />}
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">{isEdit ? 'Saving…' : 'Creating…'}</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default RecurringChargeDialog;
