import { useForm } from '@inertiajs/react';
import { Banknote, ListChecks, Wallet } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AmountField } from './money';
import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type ReceiptInvoice = {
    id: number;
    invoice_number: string;
    client_name: string | null;
    currency_code: string;
    total_amount: string | number;
    amount_due: number;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'receipt',
        label: 'Receipt',
        blurb: 'Amount & date received',
        icon: Wallet,
    },
    {
        key: 'review',
        label: 'Review & post',
        blurb: 'Confirm and post',
        icon: ListChecks,
    },
];

const money = (n: number | string, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(
        Number(n),
    );

/** A v4 uuid — `crypto.randomUUID` where available, else an RFC-4122 fallback. */
function newIdempotencyKey(): string {
    const cryptoApi = globalThis.crypto;
    if (cryptoApi?.randomUUID) return cryptoApi.randomUUID();

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = Math.floor(Math.random() * 16);
        const value = char === 'x' ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}

/**
 * Record Receipt wizard — record a (partial or full) payment received against an
 * AR invoice. Posts to `finance.receivables.allocate`, which posts a balanced
 * DR Bank / CR Accounts-Receivable journal + a `FinPaymentAllocation` and marks
 * the invoice paid once fully allocated. The amount defaults to (and is capped at)
 * the invoice's outstanding balance — the server re-validates amount ≤ due.
 */
export function RecordReceiptDialog({
    open,
    onClose,
    invoice,
}: {
    open: boolean;
    onClose: () => void;
    invoice: ReceiptInvoice | null;
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const due = invoice?.amount_due ?? 0;
    const currency = invoice?.currency_code ?? 'NZD';

    // `finance.receivables.allocate` requires a uuid idempotency key, so one is
    // minted per mount: a retry after a validation error replays the SAME key
    // and can never double-post the receipt.
    const [idempotencyKey] = useState(() => newIdempotencyKey());

    const form = useForm<{
        invoice_id: number | null;
        amount: string;
        payment_date: string;
        notes: string;
        idempotency_key: string;
    }>({
        invoice_id: invoice?.id ?? null,
        amount: due > 0 ? due.toFixed(2) : '',
        payment_date: new Date().toISOString().split('T')[0],
        notes: '',
        idempotency_key: idempotencyKey,
    });
    const { data, setData, processing, errors } = form;

    const amountNum = Number(data.amount || 0);
    const overpay = amountNum > due + 0.0001;
    const remaining = Math.max(0, due - amountNum);

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        if (!invoice) return;
        form.transform((d) => ({
            ...d,
            invoice_id: invoice.id,
            idempotency_key: idempotencyKey,
        }));
        form.post('/finance/receivables/allocate', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    if (!invoice) return null;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Record receipt"
            description={`Payment received for invoice ${invoice.invoice_number}`}
            railIcon={Banknote}
            railTitle="Record Receipt"
            railSub={invoice.invoice_number}
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={amountNum > 0 && !overpay ? 100 : 40}
            pctLabel="Receipt"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Outstanding{' '}
                    <span className="font-semibold text-foreground">
                        {money(due, currency)}
                    </span>
                </span>
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={back}
                            disabled={processing}
                        >
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={amountNum <= 0 || overpay}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || amountNum <= 0 || overpay}
                        >
                            Post receipt
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={Wallet}
                        title="Receipt details"
                        blurb="How much was received, and when."
                    />
                    {typeof errors.invoice_id === 'string' && (
                        <FieldErr>{errors.invoice_id}</FieldErr>
                    )}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Amount received"
                            required
                            error={errors.amount}
                        >
                            <AmountField
                                value={data.amount}
                                onValueChange={(v) => setData('amount', v)}
                                aria-label="Amount received"
                            />
                            {overpay && (
                                <FieldErr>
                                    Amount exceeds the outstanding balance of{' '}
                                    {money(due, currency)}.
                                </FieldErr>
                            )}
                        </Field>
                        <Field
                            label="Date received"
                            required
                            error={errors.payment_date}
                        >
                            <Input
                                type="date"
                                value={data.payment_date}
                                onChange={(e) =>
                                    setData('payment_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            span
                            hint="optional"
                            error={errors.notes}
                        >
                            <Textarea
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                placeholder="e.g. bank transfer ref 88421"
                            />
                        </Field>
                    </div>
                    <div className="mt-3 flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setData('amount', due.toFixed(2))}
                        >
                            Pay in full ({money(due, currency)})
                        </Button>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Review & post"
                        blurb="Posts DR Bank / CR Accounts Receivable and records the allocation."
                    />
                    <ReviewCard icon={Wallet} title="Receipt">
                        <ReviewRow
                            label="Invoice"
                            value={invoice.invoice_number}
                        />
                        <ReviewRow
                            label="Client"
                            value={invoice.client_name ?? '—'}
                        />
                        <ReviewRow
                            label="Invoice total"
                            value={money(invoice.total_amount, currency)}
                        />
                        <ReviewRow
                            label="Amount received"
                            value={money(amountNum, currency)}
                        />
                        <ReviewRow label="Date" value={data.payment_date} />
                        <ReviewRow
                            label="Remaining after"
                            value={
                                remaining <= 0
                                    ? 'Paid in full'
                                    : money(remaining, currency)
                            }
                        />
                    </ReviewCard>
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            Posting…
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default RecordReceiptDialog;
