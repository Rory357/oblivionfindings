import { useForm } from '@inertiajs/react';
import { ListChecks, Plus, Sprout } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
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

export type FundingStreamRevenueAccount = { id: number; code: string; name: string };

/** An existing funding stream to prefill the wizard with (edit mode). */
export type EditableFundingStream = {
    id: number;
    code: string;
    name: string;
    funder_type: string | null;
    contact_name: string | null;
    contact_email: string | null;
    default_revenue_account_id: number | string | null;
    is_active: boolean;
};

// 'none' is the sentinel for "no funder type" — Radix can't take an empty-string
// SelectItem value, so it maps back to null on submit.
const FUNDER_TYPES = [
    { value: 'none', label: 'Not specified' },
    { value: 'whaikaha', label: 'Whaikaha' },
    { value: 'carer_support', label: 'Carer Support' },
    { value: 'nasc', label: 'NASC-allocated' },
    { value: 'egl_if', label: 'EGL / Individualised Funding' },
    { value: 'acc', label: 'ACC' },
    { value: 'te_whatu_ora', label: 'Te Whatu Ora' },
    { value: 'msd', label: 'MSD' },
    { value: 'private', label: 'Private' },
    { value: 'other', label: 'Other' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Code, name & funder', icon: Sprout },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ListChecks },
];

const funderTypeLabel = (v: string) => FUNDER_TYPES.find((t) => t.value === v)?.label ?? v;

/**
 * Funding Stream wizard — create/edit a funding stream as a stepper modal
 * (Details → Review). Posts to `finance.funding-streams.store` / PUTs
 * `finance.funding-streams.update` (code, name, funder_type, contact_name,
 * contact_email, default_revenue_account_id, is_active). Both endpoints enforce
 * per-org code uniqueness — the "code already exists" error surfaces on the
 * code field. `funder_type` uses a 'none' sentinel mapped to null on submit.
 */
export function FundingStreamDialog({
    open,
    onClose,
    revenueAccounts,
    fundingStream,
}: {
    open: boolean;
    onClose: () => void;
    revenueAccounts: FundingStreamRevenueAccount[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    fundingStream?: EditableFundingStream | null;
}) {
    const isEdit = !!fundingStream;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        code: string;
        name: string;
        funder_type: string;
        contact_name: string;
        contact_email: string;
        default_revenue_account_id: string;
        is_active: boolean;
    }>(fundingStream ? {
        code: fundingStream.code ?? '',
        name: fundingStream.name ?? '',
        funder_type: fundingStream.funder_type ?? 'none',
        contact_name: fundingStream.contact_name ?? '',
        contact_email: fundingStream.contact_email ?? '',
        default_revenue_account_id: fundingStream.default_revenue_account_id != null ? String(fundingStream.default_revenue_account_id) : 'none',
        is_active: fundingStream.is_active,
    } : {
        code: '',
        name: '',
        funder_type: 'none',
        contact_name: '',
        contact_email: '',
        default_revenue_account_id: 'none',
        is_active: true,
    });
    const { data, setData, processing, errors } = form;

    const revenueOptions = [
        { value: 'none', label: 'None' },
        ...revenueAccounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` })),
    ];
    const revenueLabel = revenueOptions.find((a) => a.value === data.default_revenue_account_id)?.label ?? 'None';

    const detailsValid = !!data.code.trim() && !!data.name.trim();

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
        form.transform((d) => ({
            code: d.code,
            name: d.name,
            funder_type: d.funder_type === 'none' ? null : d.funder_type,
            contact_name: d.contact_name || null,
            contact_email: d.contact_email || null,
            default_revenue_account_id: d.default_revenue_account_id === 'none' ? null : d.default_revenue_account_id,
            is_active: d.is_active,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        };
        if (isEdit && fundingStream) {
            form.put(`/finance/funding-streams/${fundingStream.id}`, opts);
        } else {
            form.post('/finance/funding-streams', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit funding stream' : 'New funding stream'}
            description={isEdit ? 'Update this funding stream' : 'Add a funding stream to track revenue sources'}
            railIcon={Sprout}
            railTitle={isEdit ? 'Edit Stream' : 'New Stream'}
            railSub="Funding streams"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Stream"
            success={succeeded ? (
                <WizardSuccessPane
                    title={isEdit ? 'Funding stream updated' : `${data.name || 'Funding stream'} created`}
                    blurb={isEdit
                        ? 'The funding stream details have been saved.'
                        : 'The funding stream is ready to allocate revenue against.'}
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
                            {isEdit ? 'Save changes' : 'Create funding stream'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={Sprout} title="Funding stream details" blurb="Identify the revenue source and its funder." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Code" required error={errors.code}>
                            <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. FS001" maxLength={20} />
                        </Field>
                        <Field label="Funder type" error={errors.funder_type}>
                            <SelectInput
                                value={data.funder_type}
                                onChange={(v) => setData('funder_type', v)}
                                placeholder="Select funder type"
                                options={FUNDER_TYPES}
                            />
                        </Field>
                        <Field label="Name" span required error={errors.name}>
                            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Whaikaha Residential Care" />
                        </Field>
                        <Field label="Contact name" hint="optional" error={errors.contact_name}>
                            <Input value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                        </Field>
                        <Field label="Contact email" hint="optional" error={errors.contact_email}>
                            <Input type="email" value={data.contact_email} onChange={(e) => setData('contact_email', e.target.value)} />
                        </Field>
                        <Field label="Default revenue account" span hint="optional" error={errors.default_revenue_account_id}>
                            <SelectInput
                                value={data.default_revenue_account_id}
                                onChange={(v) => setData('default_revenue_account_id', v)}
                                placeholder="None"
                                options={revenueOptions}
                            />
                        </Field>
                        <div className="flex items-center justify-between gap-3 sm:col-span-2">
                            <div>
                                <Label>Active</Label>
                                <p className="text-sm text-muted-foreground">Inactive streams are hidden from allocations</p>
                            </div>
                            <Switch
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked)}
                                aria-label="Active"
                            />
                        </div>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={ListChecks} title={isEdit ? 'Review & save' : 'Review & create'} blurb={isEdit ? 'Updates this funding stream.' : 'Creates the funding stream.'} />
                    <ReviewCard icon={Sprout} title="Funding stream">
                        <ReviewRow label="Code" value={data.code || '—'} />
                        <ReviewRow label="Name" value={data.name || '—'} />
                        <ReviewRow label="Funder type" value={funderTypeLabel(data.funder_type)} />
                        {data.contact_name && <ReviewRow label="Contact" value={data.contact_name} />}
                        {data.contact_email && <ReviewRow label="Email" value={data.contact_email} />}
                        <ReviewRow label="Default revenue account" value={revenueLabel} />
                        <ReviewRow label="Status" value={data.is_active ? 'Active' : 'Inactive'} />
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">{isEdit ? 'Saving…' : 'Creating…'}</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default FundingStreamDialog;
