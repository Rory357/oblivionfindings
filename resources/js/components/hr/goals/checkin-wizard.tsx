/* eslint-disable no-restricted-syntax -- Wizard footer + KR value rows use
 * native controls to match the Add-Client modal chrome. Semantic tokens only. */
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, MessageSquare } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import {
    Field,
    Segmented,
    StepHead,
    useWizard,
    WizardShell,
    type WizardStep,
    WizardStepPane,
} from '@/components/hr/wizard';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    type Confidence,
    krProgress,
    type Objective,
    ProgressBar,
    rollup,
} from './okr-shared';

const STEPS: WizardStep[] = [
    {
        key: 'update',
        label: 'Update',
        blurb: 'Latest values',
        icon: ClipboardCheck,
    },
    {
        key: 'summary',
        label: 'Summary',
        blurb: 'Confidence & note',
        icon: MessageSquare,
    },
];

const CONF_OPTIONS = [
    { value: 'on_track' as const, label: 'On track' },
    { value: 'at_risk' as const, label: 'At risk' },
    { value: 'off_track' as const, label: 'Off track' },
];

export function CheckinWizard({
    open,
    onClose,
    objective,
}: {
    open: boolean;
    onClose: () => void;
    objective: Objective | null;
}) {
    const hasKrs = !!objective && objective.key_results.length > 0;
    const wizard = useWizard(STEPS.length);
    const stepKey = STEPS[wizard.index]?.key;

    // Local KR working values keyed by id.
    const [krValues, setKrValues] = useState<Record<number, string>>({});
    const [krConf, setKrConf] = useState<Record<number, Confidence>>({});

    const form = useForm<{
        confidence: string;
        comment: string;
        manual_progress: string;
    }>({
        confidence: objective?.confidence ?? 'on_track',
        comment: '',
        manual_progress: String(objective?.progress_percentage ?? 0),
    });

    // Re-seed working state whenever a new objective opens.
    const seedKey = objective?.id ?? 0;
    useEffect(() => {
        if (!objective) return;
        const v: Record<number, string> = {};
        const c: Record<number, Confidence> = {};
        objective.key_results.forEach((k) => {
            v[k.id] = String(k.current_value);
            c[k.id] = k.confidence;
        });
        setKrValues(v);
        setKrConf(c);
        form.setData({
            confidence: objective.confidence,
            comment: '',
            manual_progress: String(objective.progress_percentage),
        });
        wizard.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [seedKey]);

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const projected = useMemo(() => {
        if (!objective) return 0;
        if (!hasKrs) return Number(form.data.manual_progress) || 0;
        return rollup(
            objective.key_results.map((k) => ({
                weight: k.weight,
                start_value: k.start_value,
                current_value: Number(krValues[k.id] ?? k.current_value),
                target_value: k.target_value,
            })),
        );
    }, [objective, hasKrs, krValues, form.data.manual_progress]);

    const willComplete = projected >= 100;

    const submit = () => {
        if (!objective) return;
        form.transform((data) => ({
            confidence: data.confidence,
            comment: data.comment.trim() || null,
            ...(hasKrs
                ? {
                      key_results: objective.key_results.map((k) => ({
                          id: k.id,
                          current_value: Number(
                              krValues[k.id] ?? k.current_value,
                          ),
                          confidence: krConf[k.id] ?? data.confidence,
                      })),
                  }
                : { manual_progress: Number(data.manual_progress) || 0 }),
        }));

        form.post(`/hr/goals/${objective.id}/checkin`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Log check-in"
            description="Update progress and confidence on this objective."
            railIcon={ClipboardCheck}
            railTitle="Log check-in"
            railSub={objective?.title ?? 'Update progress'}
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={projected}
            pctLabel="Projected roll-up"
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <button
                            type="button"
                            onClick={submit}
                            disabled={form.processing}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50"
                        >
                            {form.processing ? 'Saving…' : 'Log check-in'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {!objective ? null : stepKey === 'update' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Update progress"
                        blurb="Enter the latest value and confidence for each key result."
                    />
                    {hasKrs ? (
                        <div className="flex flex-col gap-3">
                            {objective.key_results.map((k) => {
                                const prog = krProgress({
                                    start_value: k.start_value,
                                    current_value: Number(
                                        krValues[k.id] ?? k.current_value,
                                    ),
                                    target_value: k.target_value,
                                });
                                return (
                                    <div
                                        key={k.id}
                                        className="rounded-xl border border-border bg-sidebar p-3.5"
                                    >
                                        <div className="mb-2.5 flex items-center justify-between gap-2.5">
                                            <span className="text-[13px] font-semibold">
                                                {k.title}
                                            </span>
                                            <span className="text-[11.5px] text-muted-foreground">
                                                {k.start_value}
                                                {k.unit} → {k.target_value}
                                                {k.unit}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <div className="flex items-center gap-2">
                                                <label className="text-[11.5px] text-muted-foreground">
                                                    Current
                                                </label>
                                                <Input
                                                    value={krValues[k.id] ?? ''}
                                                    onChange={(e) =>
                                                        setKrValues((p) => ({
                                                            ...p,
                                                            [k.id]: e.target
                                                                .value,
                                                        }))
                                                    }
                                                    className="h-8 w-24"
                                                />
                                                {k.unit && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {k.unit}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="flex flex-1 items-center gap-2">
                                                <ProgressBar pct={prog} />
                                                <span className="w-9 text-right text-xs font-bold tabular-nums">
                                                    {prog}%
                                                </span>
                                            </div>
                                        </div>
                                        <div className="mt-2.5">
                                            <Segmented
                                                value={
                                                    krConf[k.id] ?? k.confidence
                                                }
                                                onChange={(v) =>
                                                    setKrConf((p) => ({
                                                        ...p,
                                                        [k.id]: v as Confidence,
                                                    }))
                                                }
                                                options={CONF_OPTIONS}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="max-w-md rounded-xl border border-border bg-sidebar p-4">
                            <Field label="Overall progress (manual)">
                                <div className="flex items-center gap-3">
                                    <Input
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={form.data.manual_progress}
                                        onChange={(e) =>
                                            form.setData(
                                                'manual_progress',
                                                e.target.value,
                                            )
                                        }
                                        className="h-10 w-28 text-base font-bold"
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        %
                                    </span>
                                </div>
                            </Field>
                            <p className="mt-2.5 text-[11.5px] text-muted-foreground">
                                No key results — progress is updated manually
                                for this objective.
                            </p>
                        </div>
                    )}
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead
                        icon={MessageSquare}
                        title="Summary & comment"
                        blurb="Confirm the new roll-up, overall confidence and add a note."
                    />
                    <div className="flex flex-wrap gap-5">
                        <div className="min-w-[300px] flex-1">
                            <div className="mb-3.5 rounded-xl border border-border bg-sidebar p-4">
                                <div className="mb-2.5 flex items-center justify-between">
                                    <span className="text-xs text-muted-foreground">
                                        Previous
                                    </span>
                                    <span className="text-sm font-bold tabular-nums">
                                        {objective.progress_percentage}%
                                    </span>
                                </div>
                                <ProgressBar
                                    pct={objective.progress_percentage}
                                    className="mb-3.5 h-2"
                                />
                                <div className="mb-2.5 flex items-center justify-between">
                                    <span className="text-xs font-semibold text-primary">
                                        New roll-up
                                    </span>
                                    <span className="text-xl font-extrabold text-primary tabular-nums">
                                        {projected}%
                                    </span>
                                </div>
                                <ProgressBar
                                    pct={projected}
                                    className="h-2.5"
                                />
                            </div>
                            <Field label="Overall confidence">
                                <Segmented
                                    value={form.data.confidence}
                                    onChange={(v) =>
                                        form.setData('confidence', v)
                                    }
                                    options={CONF_OPTIONS}
                                />
                            </Field>
                            <Field label="Comment">
                                <Textarea
                                    rows={3}
                                    value={form.data.comment}
                                    onChange={(e) =>
                                        form.setData('comment', e.target.value)
                                    }
                                    placeholder="What changed since the last check-in?"
                                />
                            </Field>
                        </div>
                        {willComplete && (
                            <div
                                className={cn(
                                    'h-fit w-[200px] shrink-0 rounded-xl border border-status-success/35 bg-status-success-bg p-4 text-center',
                                )}
                            >
                                <div className="mx-auto mb-2.5 grid h-11 w-11 place-items-center rounded-full bg-status-success text-white">
                                    <ClipboardCheck className="h-6 w-6" />
                                </div>
                                <p className="text-[13px] font-bold text-status-success">
                                    This completes the objective 🎉
                                </p>
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default CheckinWizard;
