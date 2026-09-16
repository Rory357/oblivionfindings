import { useForm } from '@inertiajs/react';
import { ListChecks, Tag, Wallet } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

type ParentAccount = { id: number; code: string; name: string; type?: string };
type TaxRate = { id: number; name: string; code: string; rate: string };
type FundingStream = { id: number; code: string; name: string };

/** The chart row an edit opens with — every field the update endpoint accepts. */
export type EditableAccount = {
    id: number;
    code: string;
    name: string;
    type: string;
    sub_type: string | null;
    parent_id: number | null;
    description: string | null;
    gst_applicable: boolean;
    is_active: boolean;
    default_tax_rate_id: number | null;
    funding_stream_id: number | null;
};

const ACCOUNT_TYPES = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'revenue', label: 'Revenue' },
    { value: 'expense', label: 'Expense' },
];

const SUB_TYPES: Record<string, { value: string; label: string }[]> = {
    asset: [
        { value: 'current_asset', label: 'Current Asset' },
        { value: 'fixed_asset', label: 'Fixed Asset' },
        { value: 'bank', label: 'Bank' },
        { value: 'accounts_receivable', label: 'Accounts Receivable' },
        { value: 'inventory', label: 'Inventory' },
        { value: 'other_asset', label: 'Other Asset' },
    ],
    liability: [
        { value: 'current_liability', label: 'Current Liability' },
        { value: 'long_term_liability', label: 'Long Term Liability' },
        { value: 'accounts_payable', label: 'Accounts Payable' },
        { value: 'tax_payable', label: 'Tax Payable' },
        { value: 'other_liability', label: 'Other Liability' },
    ],
    equity: [
        { value: 'retained_earnings', label: 'Retained Earnings' },
        { value: 'share_capital', label: 'Share Capital' },
        { value: 'reserves', label: 'Reserves' },
        { value: 'other_equity', label: 'Other Equity' },
    ],
    revenue: [
        { value: 'operating_revenue', label: 'Operating Revenue' },
        { value: 'grant_income', label: 'Grant Income' },
        { value: 'funding_income', label: 'Funding Income' },
        { value: 'other_income', label: 'Other Income' },
    ],
    expense: [
        { value: 'operating_expense', label: 'Operating Expense' },
        { value: 'cost_of_sales', label: 'Cost of Sales' },
        { value: 'payroll', label: 'Payroll' },
        { value: 'depreciation', label: 'Depreciation' },
        { value: 'administration', label: 'Administration' },
        { value: 'other_expense', label: 'Other Expense' },
    ],
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'account',
        label: 'Account',
        blurb: 'Code, name & type',
        icon: Wallet,
    },
    {
        key: 'options',
        label: 'Options',
        blurb: 'Tax, funding & flags',
        icon: Tag,
    },
];

/**
 * Account wizard — adds or edits a chart-of-accounts row from a 2-step
 * Add-Client-grade modal, in place of the standalone Create/Edit pages. CREATE
 * posts to `finance.accounts.store`; EDIT (the `account` prop) PUTs
 * `finance.accounts.update`. Both redirect back to the chart on success.
 */
export function NewAccountDialog({
    open,
    onClose,
    parentAccounts,
    taxRates,
    fundingStreams,
    account,
}: {
    open: boolean;
    onClose: () => void;
    parentAccounts: ParentAccount[];
    taxRates: TaxRate[];
    fundingStreams: FundingStream[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    account?: EditableAccount | null;
}) {
    const isEdit = !!account;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const idString = (value: number | null | undefined) =>
        value == null ? '' : String(value);

    const form = useForm({
        code: account?.code ?? '',
        name: account?.name ?? '',
        type: account?.type ?? '',
        sub_type: account?.sub_type ?? '',
        parent_id: idString(account?.parent_id),
        description: account?.description ?? '',
        gst_applicable: account?.gst_applicable ?? false,
        is_active: account?.is_active ?? true,
        default_tax_rate_id: idString(account?.default_tax_rate_id),
        funding_stream_id: idString(account?.funding_stream_id),
    });
    const { data, setData, processing, errors } = form;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && account) {
            form.put(`/finance/accounts/${account.id}`, options);
        } else {
            form.post('/finance/accounts', options);
        }
    };

    const detailsReady =
        data.code.trim() !== '' && data.name.trim() !== '' && data.type !== '';

    const subTypeOptions = (data.type ? (SUB_TYPES[data.type] ?? []) : []).map(
        (s) => ({
            value: s.value,
            label: s.label,
        }),
    );
    // No empty-string option values — Radix Select forbids them. The placeholder
    // ("None") conveys the unselected state for these optional fields.
    const parentOptions = parentAccounts
        .filter((p) => p.id !== account?.id)
        .filter((p) => !data.type || p.type === data.type)
        .map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` }));
    const taxOptions = taxRates.map((t) => ({
        value: String(t.id),
        label: `${t.name} (${t.rate}%)`,
    }));
    const fsOptions = fundingStreams.map((f) => ({
        value: String(f.id),
        label: `${f.code} - ${f.name}`,
    }));

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit account' : 'New account'}
            description={
                isEdit
                    ? 'Update this account in the chart of accounts'
                    : 'Add a new account to the chart of accounts'
            }
            railIcon={Wallet}
            railTitle={isEdit ? 'Edit account' : 'New account'}
            railSub="Chart of accounts"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
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
                            disabled={!detailsReady}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !detailsReady}
                        >
                            {processing
                                ? isEdit
                                    ? 'Saving…'
                                    : 'Creating…'
                                : isEdit
                                  ? 'Save account'
                                  : 'Create account'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={Wallet}
                        title="Account details"
                        blurb="A unique code, a name, and what kind of account it is."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Account code"
                            required
                            error={errors.code}
                        >
                            <Input
                                value={data.code}
                                onChange={(e) =>
                                    setData('code', e.target.value)
                                }
                                placeholder="e.g. 1000"
                                maxLength={20}
                            />
                        </Field>
                        <Field
                            label="Account name"
                            required
                            error={errors.name}
                        >
                            <Input
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Cash at Bank"
                                maxLength={255}
                            />
                        </Field>
                        <Field label="Type" required span error={errors.type}>
                            <Segmented
                                value={data.type}
                                onChange={(v) =>
                                    setData((prev) => ({
                                        ...prev,
                                        type: v,
                                        sub_type: '',
                                        parent_id: '',
                                    }))
                                }
                                options={ACCOUNT_TYPES}
                            />
                        </Field>
                        <Field label="Sub type" error={errors.sub_type}>
                            <SelectInput
                                value={data.sub_type}
                                onChange={(v) => setData('sub_type', v)}
                                placeholder={
                                    data.type
                                        ? 'Select sub type'
                                        : 'Choose a type first'
                                }
                                options={subTypeOptions}
                            />
                        </Field>
                        <Field
                            label="Parent account"
                            hint="optional"
                            error={errors.parent_id}
                        >
                            <SelectInput
                                value={data.parent_id}
                                onChange={(v) => setData('parent_id', v)}
                                placeholder="None (top-level account)"
                                options={parentOptions}
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Options"
                        blurb="Default tax, funding attribution, and flags."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Default tax rate"
                            error={errors.default_tax_rate_id}
                        >
                            <SelectInput
                                value={data.default_tax_rate_id}
                                onChange={(v) =>
                                    setData('default_tax_rate_id', v)
                                }
                                placeholder="None"
                                options={taxOptions}
                            />
                        </Field>
                        <Field
                            label="Funding stream"
                            error={errors.funding_stream_id}
                        >
                            <SelectInput
                                value={data.funding_stream_id}
                                onChange={(v) =>
                                    setData('funding_stream_id', v)
                                }
                                placeholder="None"
                                options={fsOptions}
                            />
                        </Field>
                        <Field
                            label="Description"
                            span
                            error={errors.description}
                        >
                            <Textarea
                                rows={2}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                placeholder="Optional description for this account"
                            />
                        </Field>
                        <div className="flex items-center gap-6 sm:col-span-2">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.gst_applicable}
                                    onCheckedChange={(c) =>
                                        setData('gst_applicable', c === true)
                                    }
                                />
                                GST applicable
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.is_active}
                                    onCheckedChange={(c) =>
                                        setData('is_active', c === true)
                                    }
                                />
                                Active
                            </label>
                        </div>
                    </div>
                    <p className="mt-4 text-[13px] text-muted-foreground">
                        {isEdit ? 'Saving' : 'Creating'}{' '}
                        <span className="font-semibold text-foreground">
                            {data.code || '—'}
                        </span>
                        {data.name ? ` · ${data.name}` : ''}
                    </p>
                </div>
            )}
        </WizardShell>
    );
}

export default NewAccountDialog;
