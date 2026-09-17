import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    ListChecks,
    Receipt,
    Wallet,
} from 'lucide-react';
import { useMemo } from 'react';

import {
    EntityChip,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { EmptyList } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from './money';
import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type PaymentRunBankAccount = {
    id: number;
    name: string;
    bank_name: string;
};

/** An approved / partially-paid bill available for a batch payment. */
export type PaymentRunBill = {
    id: number;
    bill_number: string;
    bill_date: string;
    due_date: string;
    total_amount: number;
    amount_paid: number;
    amount_due: number;
    vendor: { id: number; name: string } | null;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Payment details',
        blurb: 'Bank account & date',
        icon: Wallet,
    },
    {
        key: 'bills',
        label: 'Select bills',
        blurb: 'What this run pays',
        icon: Receipt,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ListChecks,
    },
];

const today = () => new Date().toISOString().slice(0, 10);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const isOverdue = (dueDate: string) => new Date(dueDate) < new Date(today());

/**
 * Payment Run wizard — batch a set of approved bills into one bank payment as a
 * stepper modal (Payment details → Select bills → Review), replacing the routed
 * full-page create form. The bill picker is the shared `EntityTable` in its
 * `selection` mode with a live running total, so the "pick many records" step
 * looks like every other list in the app.
 *
 * Posts a draft to `finance.payment-runs.store`; approving, preparing the bank
 * file and recording settlement evidence stay on the run's own page.
 */
export function PaymentRunDialog({
    open,
    onClose,
    bankAccounts,
    bills,
}: {
    open: boolean;
    onClose: () => void;
    bankAccounts: PaymentRunBankAccount[];
    bills: PaymentRunBill[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        bank_account_id: string;
        payment_date: string;
        notes: string;
        bill_ids: number[];
    }>({
        bank_account_id: '',
        payment_date: today(),
        notes: '',
        bill_ids: [],
    });
    const { data, setData, processing, errors } = form;

    const bankAccountOptions = bankAccounts.map((a) => ({
        value: String(a.id),
        label: `${a.name} · ${a.bank_name}`,
    }));
    const bankAccount = bankAccounts.find(
        (a) => String(a.id) === data.bank_account_id,
    );

    const selectedKeys = useMemo(
        () => new Set<string | number>(data.bill_ids),
        [data.bill_ids],
    );
    const selectedTotal = useMemo(
        () =>
            bills
                .filter((b) => selectedKeys.has(b.id))
                .reduce((sum, b) => sum + Number(b.amount_due || 0), 0),
        [bills, selectedKeys],
    );

    const toggleBill = (bill: PaymentRunBill) =>
        setData(
            'bill_ids',
            data.bill_ids.includes(bill.id)
                ? data.bill_ids.filter((id) => id !== bill.id)
                : [...data.bill_ids, bill.id],
        );

    const selectAll = () =>
        setData(
            'bill_ids',
            bills.map((b) => b.id),
        );
    const clearSelection = () => setData('bill_ids', []);

    const detailsValid = !!data.bank_account_id && !!data.payment_date;
    const billsValid = data.bill_ids.length > 0;

    const columns: EntityTableColumn<PaymentRunBill>[] = [
        {
            key: 'bill_date',
            label: 'Bill date',
            width: '1fr',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.bill_date)}
                </span>
            ),
        },
        {
            key: 'due_date',
            label: 'Due',
            width: '1.2fr',
            cell: (b) =>
                isOverdue(b.due_date) ? (
                    <EntityStatusChip variant="critical" icon={AlertTriangle}>
                        {formatDate(b.due_date)}
                    </EntityStatusChip>
                ) : (
                    <span className="text-muted-foreground">
                        {formatDate(b.due_date)}
                    </span>
                ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1fr',
            align: 'right',
            cell: (b) => (
                <span className="tabular-nums text-muted-foreground">
                    {formatMoney(b.total_amount)}
                </span>
            ),
        },
        {
            key: 'due_amount',
            label: 'Amount due',
            width: '1fr',
            align: 'right',
            cell: (b) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(b.amount_due)}
                </span>
            ),
        },
    ];

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            bank_account_id: Number(d.bank_account_id),
            notes: d.notes || null,
        }));
        form.post('/finance/payment-runs', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New payment run"
            description="Batch approved bills into one bank payment"
            railIcon={Banknote}
            railTitle="New payment run"
            railSub="Accounts payable"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={
                detailsValid && billsValid ? 100 : detailsValid ? 60 : 20
            }
            pctLabel="Run"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    {data.bill_ids.length} of {bills.length} bills ·{' '}
                    <span className="font-semibold text-foreground tabular-nums">
                        {formatMoney(selectedTotal)}
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
                            disabled={
                                (index === 0 && !detailsValid) ||
                                (index === 1 && !billsValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={
                                processing || !detailsValid || !billsValid
                            }
                        >
                            Create payment run
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={Wallet}
                        title="Payment details"
                        blurb="Which account the batch is paid from, and when."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Bank account"
                            span
                            required
                            error={errors.bank_account_id}
                        >
                            <SelectInput
                                value={data.bank_account_id}
                                onChange={(v) =>
                                    setData('bank_account_id', v)
                                }
                                placeholder="Select bank account"
                                options={bankAccountOptions}
                            />
                        </Field>
                        <Field
                            label="Payment date"
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
                                placeholder="e.g. Fortnightly supplier run"
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={Receipt}
                        title="Select bills"
                        blurb="Approved and partially-paid bills. The run pays the amount still due on each."
                    />
                    {typeof errors.bill_ids === 'string' && (
                        <FieldErr>{errors.bill_ids}</FieldErr>
                    )}
                    {bills.length === 0 ? (
                        <EmptyList
                            icon={Receipt}
                            itemName="bill"
                            title="No bills are ready to pay"
                            description="Approve a bill first — only approved and partially-paid bills can go into a payment run."
                        />
                    ) : (
                        <>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <span className="text-caption">
                                    {data.bill_ids.length} of {bills.length}{' '}
                                    selected
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={selectAll}
                                        disabled={
                                            data.bill_ids.length ===
                                            bills.length
                                        }
                                    >
                                        Select all
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={clearSelection}
                                        disabled={data.bill_ids.length === 0}
                                    >
                                        Clear selection
                                    </Button>
                                </div>
                            </div>
                            <EntityTable
                                rows={bills}
                                rowKey={(b) => b.id}
                                identityLabel="Bill"
                                identity={(b) => ({
                                    icon: Receipt,
                                    name: b.bill_number,
                                    subline: b.vendor?.name ?? 'No vendor',
                                })}
                                columns={columns}
                                actionsFor={() => []}
                                onOpen={toggleBill}
                                selection={{
                                    keys: selectedKeys,
                                    onToggle: (b) => toggleBill(b),
                                    labelFor: (b) =>
                                        `Include bill ${b.bill_number} in this payment run`,
                                }}
                                minWidth={760}
                            />
                            {/* eslint-disable-next-line no-restricted-syntax -- running-total summary panel, not a content card */}
                            <div className="mt-4 flex items-center justify-between rounded-xl border border-border bg-card/60 p-3 text-sm">
                                <span className="text-muted-foreground">
                                    Selected total
                                </span>
                                <span className="text-base font-semibold tabular-nums">
                                    {formatMoney(selectedTotal)}
                                </span>
                            </div>
                        </>
                    )}
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Review & create"
                        blurb="Creates a draft run. Nothing is paid until it is approved, the bank file is prepared, and the bank confirms settlement."
                    />
                    <ReviewCard icon={Banknote} title="Payment run">
                        <ReviewRow
                            label="Bank account"
                            value={
                                bankAccount
                                    ? `${bankAccount.name} · ${bankAccount.bank_name}`
                                    : '—'
                            }
                        />
                        <ReviewRow
                            label="Payment date"
                            value={data.payment_date}
                        />
                        <ReviewRow
                            label="Bills"
                            value={String(data.bill_ids.length)}
                        />
                        <ReviewRow
                            label="Total (NZD)"
                            value={formatMoney(selectedTotal)}
                        />
                        {data.notes && (
                            <ReviewRow label="Notes" value={data.notes} />
                        )}
                    </ReviewCard>
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        {bills
                            .filter((b) => selectedKeys.has(b.id))
                            .slice(0, 12)
                            .map((b) => (
                                <EntityChip key={b.id} icon={Receipt}>
                                    {b.bill_number}
                                </EntityChip>
                            ))}
                        {data.bill_ids.length > 12 && (
                            <EntityChip outline>
                                +{data.bill_ids.length - 12} more
                            </EntityChip>
                        )}
                    </div>
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            Creating…
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default PaymentRunDialog;
