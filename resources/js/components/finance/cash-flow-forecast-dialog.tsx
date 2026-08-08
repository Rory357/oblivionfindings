import { useForm } from '@inertiajs/react';
import { CalendarRange, Info, ListChecks, TrendingUp } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Segmented,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

const PERIOD_TYPES = [
    { value: 'weekly' as const, label: 'Weekly' },
    { value: 'fortnightly' as const, label: 'Fortnightly' },
    { value: 'monthly' as const, label: 'Monthly' },
];

const STEPS: readonly WizardStep[] = [
    {
        key: 'parameters',
        label: 'Parameters',
        blurb: 'Range & granularity',
        icon: CalendarRange,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & generate',
        icon: ListChecks,
    },
];

const today = () => new Date().toISOString().split('T')[0];
const plusMonths = (months: number) => {
    const d = new Date();
    d.setMonth(d.getMonth() + months);
    return d.toISOString().split('T')[0];
};

const fmtDate = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * Cash Flow Forecast wizard — pick a range and granularity as a stepper modal
 * (Parameters → Review). Posts to `finance.cash-flow-forecast.store`; the
 * server generates the forecast (invoices, bills, recurring entries, GST,
 * three scenarios) and redirects to the new forecast's page, so the modal
 * closes with the visit.
 */
export function CashFlowForecastDialog({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        period_start: string;
        period_end: string;
        period_type: string;
    }>({
        period_start: today(),
        period_end: plusMonths(3),
        period_type: 'weekly',
    });
    const { data, setData, processing, errors } = form;

    // Mirrors the backend `after:period_start` rule (strictly after).
    const rangeValid =
        !data.period_start ||
        !data.period_end ||
        data.period_end > data.period_start;
    const parametersValid =
        !!data.period_start &&
        !!data.period_end &&
        !!data.period_type &&
        rangeValid;
    const periodTypeLabel =
        PERIOD_TYPES.find((t) => t.value === data.period_type)?.label ??
        data.period_type;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.post('/finance/cash-flow-forecast', {
            preserveScroll: true,
            // Server redirects to the generated forecast; the modal just closes.
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New cash flow forecast"
            description="Select a forecast period and granularity"
            railIcon={TrendingUp}
            railTitle="New Forecast"
            railSub="Cash flow"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={parametersValid ? 100 : 40}
            pctLabel="Forecast"
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
                            disabled={!parametersValid}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !parametersValid}
                        >
                            {processing ? 'Generating…' : 'Generate forecast'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={CalendarRange}
                        title="Forecast parameters"
                        blurb="Define the date range and period granularity."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Period start"
                            required
                            error={errors.period_start}
                        >
                            <Input
                                type="date"
                                value={data.period_start}
                                onChange={(e) =>
                                    setData('period_start', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Period end"
                            required
                            error={
                                errors.period_end ??
                                (!rangeValid
                                    ? 'Must be after the start date.'
                                    : undefined)
                            }
                        >
                            <Input
                                type="date"
                                value={data.period_end}
                                onChange={(e) =>
                                    setData('period_end', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Period granularity"
                            span
                            required
                            hint="weekly is most granular; monthly suits longer ranges"
                            error={errors.period_type}
                        >
                            <Segmented
                                value={
                                    data.period_type as
                                        | 'weekly'
                                        | 'fortnightly'
                                        | 'monthly'
                                }
                                onChange={(v) => setData('period_type', v)}
                                options={PERIOD_TYPES}
                            />
                        </Field>
                        <InfoCard icon={Info}>
                            <span className="font-medium">
                                What will be included:
                            </span>{' '}
                            current bank balances as the opening position,
                            outstanding invoice receipts (AR), upcoming bill
                            payments (AP), recurring journal entries, GST
                            payment obligations, and three scenarios — Base,
                            Best and Worst Case.
                        </InfoCard>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Review & generate"
                        blurb="Generates the forecast and opens it with scenario comparison."
                    />
                    <ReviewCard icon={TrendingUp} title="Cash flow forecast">
                        <ReviewRow
                            label="Period"
                            value={
                                data.period_start && data.period_end
                                    ? `${fmtDate(data.period_start)} — ${fmtDate(data.period_end)}`
                                    : '—'
                            }
                        />
                        <ReviewRow
                            label="Granularity"
                            value={periodTypeLabel}
                        />
                        <ReviewRow
                            label="Scenarios"
                            value="Base · Best · Worst Case"
                        />
                    </ReviewCard>
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            Generating forecast…
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default CashFlowForecastDialog;
