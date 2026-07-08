import { useForm } from '@inertiajs/react';
import { ArrowDownCircle, ArrowUpCircle, ListChecks, Wallet } from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AmountField, formatMoney } from './money';
import { PostingPreview, type PostingLine } from './posting-preview';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type DonorFundTxnAccount = { id: number; code: string; name: string };
export type DonorFundTxnBankAccount = { id: number; name: string };
export type DonorFundGlSummary = { code: string; name: string } | null;

/** The fund the transaction posts against — enough to render the trust-journal preview. */
export type DonorFundTxnFund = {
    id: number;
    fund_name: string;
    fund_code: string;
    is_restricted: boolean;
    available_balance: number;
    /** The fund's GL account (liability/equity). Present only when the fund maps to one. */
    gl_account: DonorFundGlSummary;
};

type TxnType = 'receipt' | 'expenditure';

const today = () => new Date().toISOString().split('T')[0];

/**
 * Donor Fund transaction wizard — record a receipt (money in) or expenditure
 * (money out) against a fund as a stepper modal (Transaction → Review). A type
 * segmented control switches the payload between `finance.donor-funds.receipt`
 * and `.expenditure`. Both post a balanced trust journal when the fund maps to a
 * GL account: a receipt DRs Bank (or the chosen bank account's GL) and CRs the
 * fund; an expenditure DRs the chosen expense account and CRs the fund — so the
 * review step shows a live posting preview.
 */
export function DonorFundTransactionDialog({
    open,
    onClose,
    fund,
    expenseAccounts,
    bankAccounts,
    initialType = 'receipt',
}: {
    open: boolean;
    onClose: () => void;
    fund: DonorFundTxnFund;
    expenseAccounts: DonorFundTxnAccount[];
    bankAccounts: DonorFundTxnBankAccount[];
    initialType?: TxnType;
}) {
    const STEPS: readonly WizardStep[] = [
        { key: 'txn', label: 'Transaction', blurb: 'Type, amount & date', icon: Wallet },
        { key: 'review', label: 'Review', blurb: 'Confirm the journal', icon: ListChecks },
    ];
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        type: TxnType;
        transaction_date: string;
        description: string;
        amount: string;
        reference: string;
        bank_account_id: string;
        expense_account_id: string;
    }>({
        type: initialType,
        transaction_date: today(),
        description: '',
        amount: '',
        reference: '',
        bank_account_id: '',
        expense_account_id: '',
    });
    const { data, setData, processing, errors } = form;

    const bankOptions = bankAccounts.map((b) => ({ value: String(b.id), label: b.name }));
    const expenseOptions = expenseAccounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    const amount = Number(data.amount) || 0;

    const overBalance = data.type === 'expenditure' && fund.is_restricted && amount > fund.available_balance;

    // Trust-journal preview — only posts when the fund maps to a GL account.
    const journalLines: PostingLine[] = useMemo(() => {
        if (!fund.gl_account || amount <= 0) return [];
        if (data.type === 'receipt') {
            const bank = bankAccounts.find((b) => String(b.id) === data.bank_account_id);
            return [
                { accountCode: bank ? undefined : '1000', accountName: bank ? bank.name : 'Bank', debit: amount, memo: 'Fund receipt' },
                { accountCode: fund.gl_account.code, accountName: fund.gl_account.name, credit: amount, memo: 'Fund balance' },
            ];
        }
        // expenditure — needs an expense account to post; otherwise no journal.
        const expense = expenseAccounts.find((a) => String(a.id) === data.expense_account_id);
        if (!expense) return [];
        return [
            { accountCode: expense.code, accountName: expense.name, debit: amount, memo: 'Fund expenditure' },
            { accountCode: fund.gl_account.code, accountName: fund.gl_account.name, credit: amount, memo: 'Fund balance' },
        ];
    }, [fund.gl_account, amount, data.type, data.bank_account_id, data.expense_account_id, bankAccounts, expenseAccounts]);

    const txnValid =
        !!data.transaction_date
        && !!data.description.trim()
        && data.amount !== ''
        && amount > 0
        && !overBalance;

    const setType = (t: TxnType) => setData('type', t);

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        const isReceipt = data.type === 'receipt';
        form.transform((d) =>
            isReceipt
                ? {
                      transaction_date: d.transaction_date,
                      description: d.description,
                      amount: d.amount,
                      reference: d.reference || null,
                      bank_account_id: d.bank_account_id || null,
                  }
                : {
                      transaction_date: d.transaction_date,
                      description: d.description,
                      amount: d.amount,
                      reference: d.reference || null,
                      expense_account_id: d.expense_account_id || null,
                  },
        );
        form.post(`/finance/donor-funds/${fund.id}/${isReceipt ? 'receipt' : 'expenditure'}`, {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    const isReceipt = data.type === 'receipt';

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isReceipt ? 'Record receipt' : 'Record expenditure'}
            description={`${isReceipt ? 'Money in to' : 'Money out of'} ${fund.fund_name}`}
            railIcon={isReceipt ? ArrowDownCircle : ArrowUpCircle}
            railTitle={isReceipt ? 'Record Receipt' : 'Record Expenditure'}
            railSub={fund.fund_code}
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={txnValid ? 100 : 40}
            pctLabel="Transaction"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Amount <span className="font-semibold text-foreground">{formatMoney(amount)}</span>
                </span>
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next} disabled={!txnValid}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !txnValid}>
                            {isReceipt ? 'Record receipt' : 'Record expenditure'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={Wallet} title="Transaction details" blurb="Whether money is coming in or going out, and how much." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Type" span>
                            <Segmented
                                value={data.type}
                                onChange={(v) => setType(v as TxnType)}
                                options={[
                                    { value: 'receipt', label: 'Receipt (in)', icon: ArrowDownCircle },
                                    { value: 'expenditure', label: 'Expenditure (out)', icon: ArrowUpCircle },
                                ]}
                            />
                        </Field>
                        <Field label="Date" required error={errors.transaction_date}>
                            <Input type="date" value={data.transaction_date} onChange={(e) => setData('transaction_date', e.target.value)} />
                        </Field>
                        <Field label="Amount (NZD)" required error={errors.amount}>
                            <AmountField value={data.amount} onValueChange={(v) => setData('amount', v)} aria-label="Transaction amount" />
                        </Field>
                        <Field label="Description" span required error={errors.description}>
                            <Input
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder={isReceipt ? 'e.g. Q1 2026 grant instalment' : 'e.g. Staff training programme'}
                            />
                        </Field>
                        <Field label="Reference" hint="optional" error={errors.reference}>
                            <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} placeholder="Optional reference" />
                        </Field>
                        {isReceipt ? (
                            <Field label="Bank account" hint="optional" error={errors.bank_account_id}>
                                <SelectInput
                                    value={data.bank_account_id}
                                    onChange={(v) => setData('bank_account_id', v)}
                                    placeholder="Select bank account"
                                    options={bankOptions}
                                />
                            </Field>
                        ) : (
                            <Field label="Expense account" hint="posts the journal" error={errors.expense_account_id}>
                                <SelectInput
                                    value={data.expense_account_id}
                                    onChange={(v) => setData('expense_account_id', v)}
                                    placeholder="Select expense account"
                                    options={expenseOptions}
                                />
                            </Field>
                        )}
                        {overBalance && (
                            <div className="sm:col-span-2">
                                <InfoCard icon={ArrowUpCircle} tone="crit">
                                    This exceeds the fund's available balance ({formatMoney(fund.available_balance)}). Restricted
                                    funds can't be overspent.
                                </InfoCard>
                            </div>
                        )}
                        {(errors as Record<string, string | undefined>).receipt && (
                            <div className="sm:col-span-2">
                                <InfoCard icon={ArrowDownCircle} tone="crit">{(errors as Record<string, string>).receipt}</InfoCard>
                            </div>
                        )}
                        {(errors as Record<string, string | undefined>).expenditure && (
                            <div className="sm:col-span-2">
                                <InfoCard icon={ArrowUpCircle} tone="crit">{(errors as Record<string, string>).expenditure}</InfoCard>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={ListChecks} title="Review transaction" blurb={isReceipt ? 'Records the receipt and posts the trust journal.' : 'Records the expenditure and posts the trust journal.'} />
                    <ReviewCard icon={isReceipt ? ArrowDownCircle : ArrowUpCircle} title={isReceipt ? 'Receipt' : 'Expenditure'}>
                        <ReviewRow label="Fund" value={fund.fund_name} />
                        <ReviewRow label="Date" value={data.transaction_date} />
                        <ReviewRow label="Description" value={data.description || '—'} />
                        <ReviewRow label="Amount" value={formatMoney(amount)} />
                        {data.reference && <ReviewRow label="Reference" value={data.reference} />}
                    </ReviewCard>
                    {journalLines.length > 0 ? (
                        <div className="mt-4">
                            <PostingPreview lines={journalLines} title="Trust journal preview" />
                        </div>
                    ) : (
                        <div className="mt-4">
                            <InfoCard icon={Wallet} tone="warn">
                                {fund.gl_account
                                    ? isReceipt
                                        ? 'No journal will post until an amount is entered.'
                                        : 'Choose an expense account to post the trust journal — otherwise only the fund balance updates.'
                                    : 'This fund has no GL account mapped, so no journal will post — only the fund balance updates.'}
                            </InfoCard>
                        </div>
                    )}
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">Recording…</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default DonorFundTransactionDialog;
