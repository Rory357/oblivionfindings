import { useForm } from '@inertiajs/react';
import { AlertTriangle, Boxes, Calculator, FileText, ListChecks } from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from './money';
import { PostingPreview, type PostingLine } from './posting-preview';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type FixedAssetGlAccount = { id: number; code: string; name: string };

/** An existing fixed asset to prefill the wizard with (edit mode). */
export type EditableFixedAsset = {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    category: string;
    purchase_date: string;
    purchase_cost: string | number;
    residual_value: string | number | null;
    useful_life_months: number | string;
    depreciation_method: string;
    gl_asset_account_id: number | string | null;
    gl_depreciation_account_id: number | string | null;
    gl_expense_account_id: number | string | null;
    notes: string | null;
    /** When true, purchase cost + date are locked (depreciation already recorded). */
    has_depreciations?: boolean;
};

const CATEGORIES = [
    { value: 'vehicle', label: 'Vehicle' },
    { value: 'equipment', label: 'Equipment' },
    { value: 'building', label: 'Building' },
    { value: 'furniture', label: 'Furniture' },
    { value: 'it_equipment', label: 'IT Equipment' },
    { value: 'land', label: 'Land' },
];

const METHODS = [
    { value: 'straight_line', label: 'Straight line' },
    { value: 'diminishing_value', label: 'Diminishing value' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Name, category & cost', icon: FileText },
    { key: 'depreciation', label: 'Depreciation & GL', blurb: 'Method & accounts', icon: Calculator },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ListChecks },
];

const categoryLabel = (v: string) => CATEGORIES.find((c) => c.value === v)?.label ?? v;
const methodLabel = (v: string) => METHODS.find((m) => m.value === v)?.label ?? v;

/**
 * Fixed Asset wizard — register/edit a fixed asset as a stepper modal
 * (Details → Depreciation & GL → Review). CREATE posts to
 * `finance.fixed-assets.store`; EDIT PUTs `finance.fixed-assets.update`. When a
 * Fixed-Asset GL account is chosen, CREATE posts an acquisition journal (DR the
 * asset account / CR Bank 1000) — so the review step shows a live posting preview
 * on create only. EDIT never posts a journal; if depreciation has already been
 * recorded, purchase cost and date are locked (mirrors the update service guard).
 */
export function FixedAssetDialog({
    open,
    onClose,
    assetAccounts,
    expenseAccounts,
    asset,
}: {
    open: boolean;
    onClose: () => void;
    /** Active asset-type GL accounts (also used for the accumulated-depreciation account). */
    assetAccounts: FixedAssetGlAccount[];
    /** Active expense-type GL accounts (for the depreciation expense account). */
    expenseAccounts: FixedAssetGlAccount[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    asset?: EditableFixedAsset | null;
}) {
    const isEdit = !!asset;
    const locked = !!asset?.has_depreciations;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        asset_name: string;
        asset_tag: string;
        category: string;
        purchase_date: string;
        purchase_cost: string;
        residual_value: string;
        useful_life_months: string;
        depreciation_method: string;
        gl_asset_account_id: string;
        gl_depreciation_account_id: string;
        gl_expense_account_id: string;
        notes: string;
    }>(asset ? {
        asset_name: asset.asset_name ?? '',
        asset_tag: asset.asset_tag ?? '',
        category: asset.category ?? '',
        purchase_date: asset.purchase_date ? String(asset.purchase_date).slice(0, 10) : '',
        purchase_cost: asset.purchase_cost != null ? String(asset.purchase_cost) : '',
        residual_value: asset.residual_value != null ? String(asset.residual_value) : '',
        useful_life_months: asset.useful_life_months != null ? String(asset.useful_life_months) : '',
        depreciation_method: asset.depreciation_method ?? 'straight_line',
        gl_asset_account_id: asset.gl_asset_account_id != null ? String(asset.gl_asset_account_id) : '',
        gl_depreciation_account_id: asset.gl_depreciation_account_id != null ? String(asset.gl_depreciation_account_id) : '',
        gl_expense_account_id: asset.gl_expense_account_id != null ? String(asset.gl_expense_account_id) : '',
        notes: asset.notes ?? '',
    } : {
        asset_name: '',
        asset_tag: '',
        category: '',
        purchase_date: '',
        purchase_cost: '',
        residual_value: '',
        useful_life_months: '',
        depreciation_method: 'straight_line',
        gl_asset_account_id: '',
        gl_depreciation_account_id: '',
        gl_expense_account_id: '',
        notes: '',
    });
    const { data, setData, processing, errors } = form;

    const assetOptions = assetAccounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    const expenseOptions = expenseAccounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    const assetAccountLabel = assetOptions.find((a) => a.value === data.gl_asset_account_id)?.label ?? '—';

    // Estimated monthly depreciation (illustrative preview, same maths as the page).
    const monthlyDepreciation = useMemo(() => {
        const cost = parseFloat(data.purchase_cost) || 0;
        const residual = parseFloat(data.residual_value) || 0;
        const months = parseInt(data.useful_life_months, 10) || 0;
        if (cost <= 0 || months <= 0) return null;
        if (data.depreciation_method === 'straight_line') return (cost - residual) / months;
        if (data.depreciation_method === 'diminishing_value') return cost * (2 / months);
        return null;
    }, [data.purchase_cost, data.residual_value, data.useful_life_months, data.depreciation_method]);

    // Acquisition journal preview — only when an asset GL account is chosen AND
    // we're creating (the update path never posts). DR asset / CR Bank 1000.
    const acquisitionLines: PostingLine[] = useMemo(() => {
        const cost = parseFloat(data.purchase_cost) || 0;
        if (isEdit || !data.gl_asset_account_id || cost <= 0) return [];
        const acct = assetAccounts.find((a) => String(a.id) === data.gl_asset_account_id);
        return [
            { accountCode: acct?.code, accountName: acct?.name ?? 'Fixed asset', debit: cost, memo: 'Asset acquisition' },
            { accountCode: '1000', accountName: 'Bank', credit: cost, memo: 'Payment for asset' },
        ];
    }, [isEdit, data.gl_asset_account_id, data.purchase_cost, assetAccounts]);

    const detailsValid =
        !!data.asset_name.trim()
        && !!data.category
        && !!data.purchase_date
        && data.purchase_cost !== ''
        && Number(data.purchase_cost) >= 0;
    const depreciationValid =
        !!data.depreciation_method
        && data.useful_life_months !== ''
        && Number(data.useful_life_months) >= 1;
    const allValid = detailsValid && depreciationValid;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            asset_name: d.asset_name,
            asset_tag: d.asset_tag || null,
            category: d.category,
            purchase_date: d.purchase_date,
            purchase_cost: d.purchase_cost,
            residual_value: d.residual_value === '' ? null : d.residual_value,
            useful_life_months: d.useful_life_months,
            depreciation_method: d.depreciation_method,
            gl_asset_account_id: d.gl_asset_account_id || null,
            gl_depreciation_account_id: d.gl_depreciation_account_id || null,
            gl_expense_account_id: d.gl_expense_account_id || null,
            notes: d.notes || null,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && asset) {
            form.put(`/finance/fixed-assets/${asset.id}`, opts);
        } else {
            form.post('/finance/fixed-assets', opts);
        }
    };

    const generalError = (errors as Record<string, string | undefined>).general;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit fixed asset' : 'Add fixed asset'}
            description={isEdit ? 'Update this fixed asset' : 'Register a fixed asset in the register'}
            railIcon={Boxes}
            railTitle={isEdit ? 'Edit Asset' : 'New Asset'}
            railSub="Fixed assets"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={allValid ? 100 : detailsValid ? 65 : 25}
            pctLabel="Asset"
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={(index === 0 && !detailsValid) || (index === 1 && !depreciationValid)}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !allValid}>
                            {isEdit ? 'Save changes' : 'Create asset'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={FileText} title="Asset details" blurb="What the asset is and what it cost." />
                    {generalError && (
                        <div className="mb-4">
                            <InfoCard icon={AlertTriangle} tone="crit">{generalError}</InfoCard>
                        </div>
                    )}
                    {locked && (
                        <div className="mb-4">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Depreciation has been recorded for this asset, so purchase cost and purchase date are locked.
                            </InfoCard>
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Asset name" required error={errors.asset_name}>
                            <Input value={data.asset_name} onChange={(e) => setData('asset_name', e.target.value)} placeholder="e.g. Toyota Hiace 2024" />
                        </Field>
                        <Field label="Asset tag" hint="optional" error={errors.asset_tag}>
                            <Input value={data.asset_tag} onChange={(e) => setData('asset_tag', e.target.value)} placeholder="e.g. FA-001" />
                        </Field>
                        <Field label="Category" required error={errors.category}>
                            <SelectInput
                                value={data.category}
                                onChange={(v) => setData('category', v)}
                                placeholder="Select category"
                                options={CATEGORIES}
                            />
                        </Field>
                        <Field label="Purchase date" required error={errors.purchase_date}>
                            <Input type="date" value={data.purchase_date} onChange={(e) => setData('purchase_date', e.target.value)} disabled={locked} />
                        </Field>
                        <Field label="Purchase cost (NZD)" required error={errors.purchase_cost}>
                            <Input type="number" step="0.01" min="0" value={data.purchase_cost} onChange={(e) => setData('purchase_cost', e.target.value)} placeholder="0.00" disabled={locked} />
                        </Field>
                        <Field label="Residual value (NZD)" hint="optional" error={errors.residual_value}>
                            <Input type="number" step="0.01" min="0" value={data.residual_value} onChange={(e) => setData('residual_value', e.target.value)} placeholder="0.00" />
                        </Field>
                        <Field label="Notes" span hint="optional" error={errors.notes}>
                            <Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Any additional notes about this asset" />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={Calculator} title="Depreciation & GL accounts" blurb="How it depreciates, and where it posts in the ledger." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Depreciation method" required error={errors.depreciation_method}>
                            <SelectInput
                                value={data.depreciation_method}
                                onChange={(v) => setData('depreciation_method', v)}
                                placeholder="Select method"
                                options={METHODS}
                            />
                        </Field>
                        <Field label="Useful life (months)" required error={errors.useful_life_months}>
                            <Input type="number" min="1" value={data.useful_life_months} onChange={(e) => setData('useful_life_months', e.target.value)} placeholder="e.g. 60" />
                        </Field>
                        {monthlyDepreciation !== null && monthlyDepreciation > 0 && (
                            <div className="sm:col-span-2">
                                <InfoCard icon={Calculator}>
                                    Estimated monthly depreciation: <strong>{formatMoney(monthlyDepreciation)}</strong>
                                    {data.depreciation_method === 'diminishing_value' && ' (first month — diminishing value tapers over time)'}
                                </InfoCard>
                            </div>
                        )}
                        <Field label="Fixed asset account" span hint="posts an acquisition journal on create" error={errors.gl_asset_account_id}>
                            <SelectInput
                                value={data.gl_asset_account_id}
                                onChange={(v) => setData('gl_asset_account_id', v)}
                                placeholder="None"
                                options={assetOptions}
                            />
                        </Field>
                        <Field label="Accumulated depreciation account" hint="defaults to 1590" error={errors.gl_depreciation_account_id}>
                            <SelectInput
                                value={data.gl_depreciation_account_id}
                                onChange={(v) => setData('gl_depreciation_account_id', v)}
                                placeholder="None (defaults to 1590)"
                                options={assetOptions}
                            />
                        </Field>
                        <Field label="Depreciation expense account" hint="defaults to 8000" error={errors.gl_expense_account_id}>
                            <SelectInput
                                value={data.gl_expense_account_id}
                                onChange={(v) => setData('gl_expense_account_id', v)}
                                placeholder="None (defaults to 8000)"
                                options={expenseOptions}
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead icon={ListChecks} title={isEdit ? 'Review & save' : 'Review & create'} blurb={isEdit ? 'Updates this fixed asset.' : 'Registers the asset — an acquisition journal posts if a GL asset account is set.'} />
                    <ReviewCard icon={FileText} title="Fixed asset">
                        <ReviewRow label="Name" value={data.asset_name || '—'} />
                        {data.asset_tag && <ReviewRow label="Tag" value={data.asset_tag} />}
                        <ReviewRow label="Category" value={categoryLabel(data.category)} />
                        <ReviewRow label="Purchase date" value={data.purchase_date} />
                        <ReviewRow label="Purchase cost" value={formatMoney(data.purchase_cost)} />
                        {data.residual_value !== '' && <ReviewRow label="Residual value" value={formatMoney(data.residual_value)} />}
                        <ReviewRow label="Depreciation" value={`${methodLabel(data.depreciation_method)} · ${data.useful_life_months || '—'} months`} />
                        <ReviewRow label="Asset account" value={assetAccountLabel} />
                    </ReviewCard>
                    {acquisitionLines.length > 0 && (
                        <div className="mt-4">
                            <PostingPreview lines={acquisitionLines} title="Acquisition journal preview" />
                        </div>
                    )}
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">{isEdit ? 'Saving…' : 'Creating…'}</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default FixedAssetDialog;
