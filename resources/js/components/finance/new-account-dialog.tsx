import { useForm } from '@inertiajs/react';
import { ListChecks, Tag, Wallet } from 'lucide-react';

import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
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
    { key: 'account', label: 'Account', blurb: 'Code, name & type', icon: Wallet },
    { key: 'options', label: 'Options', blurb: 'Tax, funding & flags', icon: Tag },
];

/**
 * New Account wizard — adds an account to the chart of accounts from a 2-step
 * Add-Client-grade modal, in place of the standalone Create page. Posts to
 * `finance.accounts.store` (redirects back to the chart on success).
 */
export function NewAccountDialog({
    open,
    onClose,
    parentAccounts,
    taxRates,
    fundingStreams,
}: {
    open: boolean;
    onClose: () => void;
    parentAccounts: ParentAccount[];
    taxRates: TaxRate[];
    fundingStreams: FundingStream[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm({
        code: '',
        name: '',
        type: '',
        sub_type: '',
        parent_id: '',
        description: '',
        gst_applicable: false,
        is_active: true,
        default_tax_rate_id: '',
        funding_stream_id: '',
    });
    const { data, setData, processing, errors } = form;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.post('/finance/accounts', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    const detailsReady = data.code.trim() !== '' && data.name.trim() !== '' && data.type !== '';

    const subTypeOptions = (data.type ? SUB_TYPES[data.type] ?? [] : []).map((s) => ({
        value: s.value,
        label: s.label,
    }));
    const parentOptions = [
        { value: '', label: 'None (top-level account)' },
        ...parentAccounts
            .filter((p) => !data.type || p.type === data.type)
            .map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` })),
    ];
    const taxOptions = [
        { value: '', label: 'None' },
        ...taxRates.map((t) => ({ value: String(t.id), label: `${t.name} (${t.rate}%)` })),
    ];
    const fsOptions = [
        { value: '', label: 'None' },
        ...fundingStreams.map((f) => ({ value: String(f.id), label: `${f.code} - ${f.name}` })),
    ];

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New account"
            description="Add a new account to the chart of accounts"
            railIcon={Wallet}
            railTitle="New Account"
            railSub="Chart of accounts"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next} disabled={!detailsReady}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !detailsReady}>
                            {processing ? 'Creating…' : 'Create account'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={Wallet} title="Account details" blurb="A unique code, a name, and what kind of account it is." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Account code" required error={errors.code}>
                            <Input
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="e.g. 1000"
                                maxLength={20}
                            />
                        </Field>
                        <Field label="Account name" required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Cash at Bank"
                                maxLength={255}
                            />
                        </Field>
                        <Field label="Type" required span error={errors.type}>
                            <Segmented
                                value={data.type}
                                onChange={(v) =>
                                    setData((prev) => ({ ...prev, type: v, sub_type: '', parent_id: '' }))
                                }
                                options={ACCOUNT_TYPES}
                            />
                        </Field>
                        <Field label="Sub type" error={errors.sub_type}>
                            <SelectInput
                                value={data.sub_type}
                                onChange={(v) => setData('sub_type', v)}
                                placeholder={data.type ? 'Select sub type' : 'Choose a type first'}
                                options={subTypeOptions}
                            />
                        </Field>
                        <Field label="Parent account" hint="optional" error={errors.parent_id}>
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
                    <StepHead icon={ListChecks} title="Options" blurb="Default tax, funding attribution, and flags." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Default tax rate" error={errors.default_tax_rate_id}>
                            <SelectInput
                                value={data.default_tax_rate_id}
                                onChange={(v) => setData('default_tax_rate_id', v)}
                                placeholder="None"
                                options={taxOptions}
                            />
                        </Field>
                        <Field label="Funding stream" error={errors.funding_stream_id}>
                            <SelectInput
                                value={data.funding_stream_id}
                                onChange={(v) => setData('funding_stream_id', v)}
                                placeholder="None"
                                options={fsOptions}
                            />
                        </Field>
                        <Field label="Description" span error={errors.description}>
                            <Textarea
                                rows={2}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Optional description for this account"
                            />
                        </Field>
                        <div className="flex items-center gap-6 sm:col-span-2">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.gst_applicable}
                                    onCheckedChange={(c) => setData('gst_applicable', c === true)}
                                />
                                GST applicable
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c === true)}
                                />
                                Active
                            </label>
                        </div>
                    </div>
                    <p className="mt-4 text-[13px] text-muted-foreground">
                        Creating <span className="font-semibold text-foreground">{data.code || '—'}</span>
                        {data.name ? ` · ${data.name}` : ''}
                    </p>
                </div>
            )}
        </WizardShell>
    );
}

export default NewAccountDialog;
