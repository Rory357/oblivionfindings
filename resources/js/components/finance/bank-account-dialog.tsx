import { useForm } from '@inertiajs/react';
import { Banknote, Landmark, ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatMoney } from './money';
import type { AccountOption } from './new-bill-dialog';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
    useWizard,
} from './wizard';

/** An existing bank account to prefill the wizard with (edit mode). */
export type EditableBankAccount = {
    id: number;
    name: string;
    bank_name: string;
    account_number: string | null;
    account_type: string;
    gl_account_id: number | string | null;
    is_primary: boolean;
    is_active: boolean;
};

const ACCOUNT_TYPES = [
    { value: 'cheque', label: 'Cheque' },
    { value: 'savings', label: 'Savings' },
    { value: 'term_deposit', label: 'Term Deposit' },
    { value: 'credit_card', label: 'Credit Card' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'account', label: 'Account', blurb: 'Bank & account number', icon: Landmark },
    { key: 'review', label: 'Ledger & review', blurb: 'GL link & confirm', icon: ListChecks },
];

/**
 * Bank Account wizard — register/edit a bank account as a stepper modal
 * (Account → Ledger & review). Posts to `finance.bank-accounts.store` / PUTs
 * `finance.bank-accounts.update`. Opening balance is create-only (the update
 * FormRequest doesn't accept it — the balance is maintained by transactions),
 * so edit mode omits it from the payload exactly like the retired Edit page.
 */
export function BankAccountDialog({
    open,
    onClose,
    glAccounts,
    bankAccount,
}: {
    open: boolean;
    onClose: () => void;
    /** Active `bank` sub-type GL accounts this bank account can map to. */
    glAccounts: AccountOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    bankAccount?: EditableBankAccount | null;
}) {
    const isEdit = !!bankAccount;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        name: string;
        bank_name: string;
        account_number: string;
        account_type: string;
        gl_account_id: string;
        opening_balance: string;
        is_primary: boolean;
        is_active: boolean;
    }>(bankAccount ? {
        name: bankAccount.name ?? '',
        bank_name: bankAccount.bank_name ?? '',
        account_number: bankAccount.account_number ?? '',
        account_type: bankAccount.account_type ?? 'cheque',
        gl_account_id: bankAccount.gl_account_id != null ? String(bankAccount.gl_account_id) : '',
        opening_balance: '0.00', // display-only in edit mode; never submitted
        is_primary: bankAccount.is_primary,
        is_active: bankAccount.is_active,
    } : {
        name: '',
        bank_name: '',
        account_number: '',
        account_type: 'cheque',
        gl_account_id: '',
        opening_balance: '0.00',
        is_primary: false,
        is_active: true,
    });
    const { data, setData, processing, errors } = form;

    const glOptions = glAccounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    const glLabel = glOptions.find((a) => a.value === data.gl_account_id)?.label ?? '—';
    const typeLabel = ACCOUNT_TYPES.find((t) => t.value === data.account_type)?.label ?? data.account_type;

    const accountValid =
        !!data.name.trim()
        && !!data.bank_name.trim()
        && !!data.account_number.trim()
        && !!data.account_type;
    const ledgerValid =
        !!data.gl_account_id
        && (isEdit || (data.opening_balance !== '' && !Number.isNaN(Number(data.opening_balance))));

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
        // The update endpoint doesn't accept opening_balance — send exactly the
        // fields the retired Edit page sent.
        form.transform((d) =>
            isEdit
                ? {
                      name: d.name,
                      bank_name: d.bank_name,
                      account_number: d.account_number,
                      account_type: d.account_type,
                      gl_account_id: d.gl_account_id,
                      is_primary: d.is_primary,
                      is_active: d.is_active,
                  }
                : d,
        );
        const opts = {
            preserveScroll: true,
            // Create redirects back to the index (success pane shows); update
            // redirects to the account's page (modal leaves with the visit).
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        };
        if (isEdit && bankAccount) {
            form.put(`/finance/bank-accounts/${bankAccount.id}`, opts);
        } else {
            form.post('/finance/bank-accounts', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit bank account' : 'Add bank account'}
            description={isEdit ? 'Update this bank account' : 'Register a bank account for reconciliation'}
            railIcon={Banknote}
            railTitle={isEdit ? 'Edit Account' : 'New Account'}
            railSub="Banking & cash"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={accountValid && ledgerValid ? 100 : accountValid ? 70 : 30}
            pctLabel="Account"
            success={succeeded ? (
                <WizardSuccessPane
                    title={isEdit ? 'Bank account updated' : `${data.name || 'Bank account'} added`}
                    blurb={isEdit
                        ? 'The bank account details have been saved.'
                        : 'The account is registered and ready for transactions, feeds and reconciliation.'}
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
                        <Button type="button" onClick={next} disabled={!accountValid}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !accountValid || !ledgerValid}>
                            {isEdit ? 'Save changes' : 'Create bank account'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={Landmark} title="Account details" blurb="The bank account as it appears on statements." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Account name" span required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. ANZ Business Cheque"
                            />
                        </Field>
                        <Field label="Bank name" required error={errors.bank_name}>
                            <Input
                                value={data.bank_name}
                                onChange={(e) => setData('bank_name', e.target.value)}
                                placeholder="e.g. ANZ, Westpac, BNZ, ASB"
                            />
                        </Field>
                        <Field label="Account number" required error={errors.account_number}>
                            <Input
                                value={data.account_number}
                                onChange={(e) => setData('account_number', e.target.value)}
                                placeholder="XX-XXXX-XXXXXXX-XXX"
                            />
                        </Field>
                        <Field label="Account type" required error={errors.account_type}>
                            <SelectInput
                                value={data.account_type}
                                onChange={(v) => setData('account_type', v)}
                                placeholder="Select type"
                                options={ACCOUNT_TYPES}
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Ledger & review"
                        blurb={isEdit ? 'Which GL account it maps to, then confirm.' : 'Where it posts in the ledger and its starting balance.'}
                    />
                    <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="GL account" span={isEdit} required error={errors.gl_account_id}>
                            <SelectInput
                                value={data.gl_account_id}
                                onChange={(v) => setData('gl_account_id', v)}
                                placeholder="Select a GL account"
                                options={glOptions}
                            />
                        </Field>
                        {!isEdit && (
                            <Field label="Opening balance (NZD)" required error={errors.opening_balance}>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={data.opening_balance}
                                    onChange={(e) => setData('opening_balance', e.target.value)}
                                />
                            </Field>
                        )}
                        <div className="flex items-center justify-between gap-3 sm:col-span-2">
                            <div>
                                <Label>Primary account</Label>
                                <p className="text-sm text-muted-foreground">Set as the primary bank account</p>
                            </div>
                            <Switch
                                checked={data.is_primary}
                                onCheckedChange={(checked) => setData('is_primary', checked)}
                                aria-label="Primary account"
                            />
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:col-span-2">
                            <div>
                                <Label>Active</Label>
                                <p className="text-sm text-muted-foreground">Inactive accounts are hidden from lists</p>
                            </div>
                            <Switch
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked)}
                                aria-label="Active"
                            />
                        </div>
                    </div>
                    <ReviewCard icon={Banknote} title="Bank account">
                        <ReviewRow label="Name" value={data.name || '—'} />
                        <ReviewRow label="Bank" value={data.bank_name || '—'} />
                        <ReviewRow label="Account number" value={data.account_number || '—'} />
                        <ReviewRow label="Type" value={typeLabel} />
                        <ReviewRow label="GL account" value={glLabel} />
                        {!isEdit && <ReviewRow label="Opening balance" value={formatMoney(data.opening_balance)} />}
                        <ReviewRow label="Primary" value={data.is_primary ? 'Yes' : 'No'} />
                        <ReviewRow label="Status" value={data.is_active ? 'Active' : 'Inactive'} />
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">{isEdit ? 'Saving…' : 'Creating…'}</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default BankAccountDialog;
