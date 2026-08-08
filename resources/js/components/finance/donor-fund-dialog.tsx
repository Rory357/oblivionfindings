import { useForm } from '@inertiajs/react';
import { CalendarClock, HandHeart, Landmark, ListChecks } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useState } from 'react';
import { formatMoney } from './money';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
} from './wizard';

export type DonorFundGlAccount = { id: number; code: string; name: string };
export type DonorFundFundingStream = { id: number; name: string };

const FUND_TYPES = [
    { value: 'grant', label: 'Grant' },
    { value: 'donation', label: 'Donation' },
    { value: 'bequest', label: 'Bequest' },
    { value: 'trust', label: 'Trust' },
    { value: 'government', label: 'Government' },
    { value: 'sponsorship', label: 'Sponsorship' },
];

const STEPS: readonly WizardStep[] = [
    {
        key: 'fund',
        label: 'Fund',
        blurb: 'Code, name & donor',
        icon: HandHeart,
    },
    {
        key: 'accounting',
        label: 'Accounting & dates',
        blurb: 'GL, dates & reporting',
        icon: Landmark,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ListChecks,
    },
];

const fundTypeLabel = (v: string) =>
    FUND_TYPES.find((t) => t.value === v)?.label ?? v;

/**
 * Donor Fund wizard — create a donor-restricted fund as a stepper modal
 * (Fund → Accounting & dates → Review). Posts to `finance.donor-funds.store`
 * (CREATE-ONLY here; fund details are edited from the fund's own page). No GL
 * journal is posted on creation — receipts and expenditures post the trust
 * journals later — so there's no posting preview.
 */
export function DonorFundDialog({
    open,
    onClose,
    glAccounts,
    fundingStreams,
}: {
    open: boolean;
    onClose: () => void;
    /** Active liability/equity GL accounts the fund can map to. */
    glAccounts: DonorFundGlAccount[];
    fundingStreams: DonorFundFundingStream[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        fund_code: string;
        fund_name: string;
        donor_name: string;
        donor_contact: string;
        fund_type: string;
        gl_account_id: string;
        funding_stream_id: string;
        budget_amount: string;
        start_date: string;
        end_date: string;
        restrictions: string;
        reporting_requirements: string;
        next_report_due: string;
        is_restricted: boolean;
    }>({
        fund_code: '',
        fund_name: '',
        donor_name: '',
        donor_contact: '',
        fund_type: 'grant',
        gl_account_id: '',
        funding_stream_id: '',
        budget_amount: '',
        start_date: '',
        end_date: '',
        restrictions: '',
        reporting_requirements: '',
        next_report_due: '',
        is_restricted: true,
    });
    const { data, setData, processing, errors } = form;

    const glOptions = glAccounts.map((a) => ({
        value: String(a.id),
        label: `${a.code} · ${a.name}`,
    }));
    const streamOptions = fundingStreams.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));
    const glLabel =
        glOptions.find((a) => a.value === data.gl_account_id)?.label ?? '—';
    const streamLabel =
        streamOptions.find((s) => s.value === data.funding_stream_id)?.label ??
        '—';

    // Mirror the backend `after_or_equal:start_date` rule so users see it before submit.
    const datesValid =
        !data.start_date || !data.end_date || data.end_date >= data.start_date;
    const fundValid =
        !!data.fund_code.trim() && !!data.fund_name.trim() && !!data.fund_type;
    const accountingValid = datesValid;
    const allValid = fundValid && accountingValid;

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
            fund_code: d.fund_code,
            fund_name: d.fund_name,
            donor_name: d.donor_name || null,
            donor_contact: d.donor_contact || null,
            fund_type: d.fund_type,
            gl_account_id: d.gl_account_id || null,
            funding_stream_id: d.funding_stream_id || null,
            budget_amount: d.budget_amount === '' ? null : d.budget_amount,
            start_date: d.start_date || null,
            end_date: d.end_date || null,
            restrictions: d.restrictions || null,
            reporting_requirements: d.reporting_requirements || null,
            next_report_due: d.next_report_due || null,
            is_restricted: d.is_restricted,
        }));
        form.post('/finance/donor-funds', {
            preserveScroll: true,
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New donor fund"
            description="Add a donor-restricted fund for grants and donations"
            railIcon={HandHeart}
            railTitle="New Fund"
            railSub="Donor funds"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={allValid ? 100 : fundValid ? 65 : 30}
            pctLabel="Fund"
            success={
                succeeded ? (
                    <WizardSuccessPane
                        title={`${data.fund_name || 'Donor fund'} created`}
                        blurb="The fund is ready. Open it to record receipts, expenditure, and generate reports."
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    onClick={startAnother}
                                >
                                    Add another
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
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
                                (index === 0 && !fundValid) ||
                                (index === 1 && !accountingValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !allValid}
                        >
                            Create fund
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={HandHeart}
                        title="Fund details"
                        blurb="Identify the fund and its donor."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Fund code"
                            required
                            error={errors.fund_code}
                        >
                            <Input
                                value={data.fund_code}
                                onChange={(e) =>
                                    setData('fund_code', e.target.value)
                                }
                                placeholder="e.g. GNT-2026-001"
                            />
                        </Field>
                        <Field
                            label="Fund type"
                            required
                            error={errors.fund_type}
                        >
                            <SelectInput
                                value={data.fund_type}
                                onChange={(v) => setData('fund_type', v)}
                                placeholder="Select type"
                                options={FUND_TYPES}
                            />
                        </Field>
                        <Field
                            label="Fund name"
                            span
                            required
                            error={errors.fund_name}
                        >
                            <Input
                                value={data.fund_name}
                                onChange={(e) =>
                                    setData('fund_name', e.target.value)
                                }
                                placeholder="e.g. Lotteries NZ Community Grant"
                            />
                        </Field>
                        <Field
                            label="Donor name"
                            hint="optional"
                            error={errors.donor_name}
                        >
                            <Input
                                value={data.donor_name}
                                onChange={(e) =>
                                    setData('donor_name', e.target.value)
                                }
                                placeholder="e.g. Lotteries NZ"
                            />
                        </Field>
                        <Field
                            label="Donor contact"
                            hint="optional"
                            error={errors.donor_contact}
                        >
                            <Input
                                value={data.donor_contact}
                                onChange={(e) =>
                                    setData('donor_contact', e.target.value)
                                }
                                placeholder="e.g. grants@lotterygrants.govt.nz"
                            />
                        </Field>
                        <Field
                            label="Budget amount (NZD)"
                            span
                            hint="optional"
                            error={errors.budget_amount}
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.budget_amount}
                                onChange={(e) =>
                                    setData('budget_amount', e.target.value)
                                }
                                placeholder="Total grant amount"
                            />
                        </Field>
                        <div className="flex items-center justify-between gap-3 sm:col-span-2">
                            <div>
                                <Label>Restricted fund</Label>
                                <p className="text-sm text-muted-foreground">
                                    Expenditure is limited to the available
                                    balance
                                </p>
                            </div>
                            <Switch
                                checked={data.is_restricted}
                                onCheckedChange={(checked) =>
                                    setData('is_restricted', checked)
                                }
                                aria-label="Restricted fund"
                            />
                        </div>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={Landmark}
                        title="Accounting, dates & reporting"
                        blurb="Where it posts, when it runs, and what reporting it needs."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="GL account"
                            hint="liability / equity"
                            error={errors.gl_account_id}
                        >
                            <SelectInput
                                value={data.gl_account_id}
                                onChange={(v) => setData('gl_account_id', v)}
                                placeholder="Select GL account"
                                options={glOptions}
                            />
                        </Field>
                        <Field
                            label="Funding stream"
                            hint="optional"
                            error={errors.funding_stream_id}
                        >
                            <SelectInput
                                value={data.funding_stream_id}
                                onChange={(v) =>
                                    setData('funding_stream_id', v)
                                }
                                placeholder="Select funding stream"
                                options={streamOptions}
                            />
                        </Field>
                        <Field
                            label="Start date"
                            hint="optional"
                            error={errors.start_date}
                        >
                            <Input
                                type="date"
                                value={data.start_date}
                                onChange={(e) =>
                                    setData('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="End date"
                            hint="optional"
                            error={
                                errors.end_date ??
                                (!datesValid
                                    ? 'Must be on or after the start date.'
                                    : undefined)
                            }
                        >
                            <Input
                                type="date"
                                value={data.end_date}
                                onChange={(e) =>
                                    setData('end_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Next report due"
                            span
                            hint="optional"
                            error={errors.next_report_due}
                        >
                            <Input
                                type="date"
                                value={data.next_report_due}
                                onChange={(e) =>
                                    setData('next_report_due', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Restrictions"
                            span
                            hint="optional"
                            error={errors.restrictions}
                        >
                            <Textarea
                                rows={2}
                                value={data.restrictions}
                                onChange={(e) =>
                                    setData('restrictions', e.target.value)
                                }
                                placeholder="How may funds be used? Any restrictions or conditions…"
                            />
                        </Field>
                        <Field
                            label="Reporting requirements"
                            span
                            hint="optional"
                            error={errors.reporting_requirements}
                        >
                            <Textarea
                                rows={2}
                                value={data.reporting_requirements}
                                onChange={(e) =>
                                    setData(
                                        'reporting_requirements',
                                        e.target.value,
                                    )
                                }
                                placeholder="What reporting is required? Frequency, format, etc."
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Review & create"
                        blurb="Creates the fund — record receipts and expenditure from its page afterwards."
                    />
                    <ReviewCard icon={CalendarClock} title="Donor fund">
                        <ReviewRow label="Code" value={data.fund_code || '—'} />
                        <ReviewRow label="Name" value={data.fund_name || '—'} />
                        <ReviewRow
                            label="Type"
                            value={fundTypeLabel(data.fund_type)}
                        />
                        {data.donor_name && (
                            <ReviewRow label="Donor" value={data.donor_name} />
                        )}
                        {data.budget_amount !== '' && (
                            <ReviewRow
                                label="Budget"
                                value={formatMoney(data.budget_amount)}
                            />
                        )}
                        <ReviewRow
                            label="Restricted"
                            value={data.is_restricted ? 'Yes' : 'No'}
                        />
                        <ReviewRow label="GL account" value={glLabel} />
                        {data.funding_stream_id && (
                            <ReviewRow
                                label="Funding stream"
                                value={streamLabel}
                            />
                        )}
                        {(data.start_date || data.end_date) && (
                            <ReviewRow
                                label="Period"
                                value={`${data.start_date || 'Any time'} — ${data.end_date || 'ongoing'}`}
                            />
                        )}
                    </ReviewCard>
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

export default DonorFundDialog;
