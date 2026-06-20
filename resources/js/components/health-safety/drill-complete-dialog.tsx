/* eslint-disable no-restricted-syntax -- the outcome segmented control is an
 * intentional custom toggle surface on semantic tokens (Passed / actions / Failed). */
/* Complete drill wizard — the Add-Client idiom on WizardShell (4 steps). Records the
 * write-up and posts to /health-safety/drills/{id}/complete, which fires the
 * EmergencyDrillObserver (a non-passing outcome raises a drill_failure safety event +
 * Control Room signal). */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { WizardShell, WizardStepPane, ReviewCard, ReviewRow, type WizardStep } from '@/components/wizard/shell';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Flag,
    ShieldAlert,
    Timer,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CHIP, fmtEvacTime, type ChipTone } from '@/pages/health-safety/drills/shared';

const STEPS: WizardStep[] = [
    { key: 'timings', label: 'Timings', blurb: 'How it ran', icon: Timer },
    { key: 'rollcall', label: 'Roll-call', blurb: 'Everyone accounted', icon: ClipboardCheck },
    { key: 'outcome', label: 'Outcome', blurb: 'Verdict & learnings', icon: Flag },
    { key: 'review', label: 'Review', blurb: 'Confirm & record', icon: CheckCircle2 },
];

const OUTCOMES: { value: string; label: string; tone: ChipTone }[] = [
    { value: 'passed', label: 'Passed', tone: 'success' },
    { value: 'passed_actions', label: 'Passed with actions', tone: 'warning' },
    { value: 'failed', label: 'Failed', tone: 'critical' },
];

function nowLocal(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function DrillCompleteDialog({
    open,
    onClose,
    drill,
}: {
    open: boolean;
    onClose: () => void;
    drill: { id: number; reference: string; type_label: string };
}) {
    const [step, setStep] = useState(0);
    const last = STEPS.length - 1;

    const form = useForm({
        completed_at: nowLocal(),
        duration_minutes: '',
        evacuation_time_seconds: '',
        weather_conditions: '',
        total_participants: '',
        residents_evacuated: '',
        roll_call_completed: false as boolean,
        assembly_point_reached: false as boolean,
        all_areas_checked: false as boolean,
        outcome: '',
        improvements_identified: '',
        observer_notes: '',
    });

    const canContinue = (s: number): boolean => {
        if (s === 0) return !!form.data.completed_at;
        if (s === 2) return !!form.data.outcome;
        return true;
    };
    const goNext = () => step < last && canContinue(step) && setStep((s) => s + 1);
    const goBack = () => setStep((s) => Math.max(0, s - 1));

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.post(`/health-safety/drills/${drill.id}/complete`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) {
                    form.reset();
                    setStep(0);
                    onClose();
                }
            },
            onError: () => {
                const errs = form.errors as Record<string, string>;
                const stepOf: Record<string, number> = {
                    completed_at: 0,
                    duration_minutes: 0,
                    evacuation_time_seconds: 0,
                    weather_conditions: 0,
                    total_participants: 1,
                    residents_evacuated: 1,
                    outcome: 2,
                    improvements_identified: 2,
                    observer_notes: 2,
                };
                const first = Object.keys(errs).map((k) => stepOf[k] ?? last).sort((a, b) => a - b)[0];
                if (first != null) setStep(first);
            },
        });
    };

    const outcomeMeta = OUTCOMES.find((o) => o.value === form.data.outcome);

    const footerEnd = (
        <>
            {step > 0 ? (
                <Button type="button" variant="outline" onClick={goBack}>
                    <ChevronLeft className="mr-1 h-4 w-4" /> Back
                </Button>
            ) : null}
            <Button type="button" variant="ghost" onClick={onClose}>
                Cancel
            </Button>
            {step < last ? (
                <Button type="button" onClick={goNext} disabled={!canContinue(step)}>
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            ) : (
                <Button type="button" onClick={() => submit()} disabled={form.processing}>
                    <Check className="mr-1 h-4 w-4" /> Record completion
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Complete drill"
            description="Record the write-up"
            railIcon={CheckCircle2}
            railTitle="Complete drill"
            railSub={`${drill.reference} · ${drill.type_label}`}
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            pct={null}
            footerEnd={footerEnd}
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Timer} title="Timings" blurb="How the evacuation actually ran" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Completed at" required error={form.errors.completed_at} span>
                            <Input type="datetime-local" value={form.data.completed_at} onChange={(e) => form.setData('completed_at', e.target.value)} />
                        </Field>
                        <Field label="Duration (minutes)" error={form.errors.duration_minutes}>
                            <Input type="number" min={0} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', e.target.value)} />
                        </Field>
                        <Field label="Evacuation time (seconds)" error={form.errors.evacuation_time_seconds}>
                            <Input type="number" min={0} value={form.data.evacuation_time_seconds} onChange={(e) => form.setData('evacuation_time_seconds', e.target.value)} />
                        </Field>
                        <Field label="Weather conditions" error={form.errors.weather_conditions} span>
                            <Input value={form.data.weather_conditions} onChange={(e) => form.setData('weather_conditions', e.target.value)} placeholder="e.g. Fine, light wind · 14°C" />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={ClipboardCheck} title="Roll-call" blurb="Confirm everyone was accounted for" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Total participants" error={form.errors.total_participants}>
                            <Input type="number" min={0} value={form.data.total_participants} onChange={(e) => form.setData('total_participants', e.target.value)} />
                        </Field>
                        <Field label="Residents evacuated" error={form.errors.residents_evacuated}>
                            <Input type="number" min={0} value={form.data.residents_evacuated} onChange={(e) => form.setData('residents_evacuated', e.target.value)} />
                        </Field>
                        <div className="col-span-full flex flex-col gap-2.5">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.roll_call_completed} onCheckedChange={(v) => form.setData('roll_call_completed', !!v)} />
                                Roll-call completed
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.assembly_point_reached} onCheckedChange={(v) => form.setData('assembly_point_reached', !!v)} />
                                Assembly point reached
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.all_areas_checked} onCheckedChange={(v) => form.setData('all_areas_checked', !!v)} />
                                All areas checked
                            </label>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Flag} title="Outcome & learnings" blurb="The verdict and what to improve" />
                    <div className="flex flex-col gap-4">
                        <Field label="Outcome" required error={form.errors.outcome}>
                            <div className="inline-flex flex-wrap gap-2">
                                {OUTCOMES.map((o) => {
                                    const active = form.data.outcome === o.value;
                                    return (
                                        <button
                                            key={o.value}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => form.setData('outcome', o.value)}
                                            className={cn(
                                                'rounded-lg border px-4 py-2 text-sm font-semibold transition-colors',
                                                active ? `${CHIP[o.tone]} border-transparent ring-1 ring-current` : 'border-border bg-card text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {o.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                        <Field label="Improvements identified" error={form.errors.improvements_identified}>
                            <Textarea rows={3} value={form.data.improvements_identified} onChange={(e) => form.setData('improvements_identified', e.target.value)} placeholder="What should change before the next drill?" />
                        </Field>
                        <Field label="Observer notes" error={form.errors.observer_notes}>
                            <Textarea rows={2} value={form.data.observer_notes} onChange={(e) => form.setData('observer_notes', e.target.value)} />
                        </Field>
                        {form.data.outcome === 'passed_actions' || form.data.outcome === 'failed' ? (
                            <InfoCard icon={ShieldAlert} tone="warn">
                                A "passed with actions" or "failed" outcome raises a drill_failure safety event and notifies the Control Room when you record completion.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review & record" blurb="Confirm the completion write-up" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Timer} title="Timings" onEdit={() => setStep(0)}>
                            <ReviewRow label="Evacuation time" value={form.data.evacuation_time_seconds ? fmtEvacTime(Number(form.data.evacuation_time_seconds)) : undefined} />
                            <ReviewRow label="Duration" value={form.data.duration_minutes ? `${form.data.duration_minutes} min` : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={ClipboardCheck} title="Roll-call" onEdit={() => setStep(1)}>
                            <ReviewRow
                                label="Residents evacuated"
                                value={form.data.residents_evacuated && form.data.total_participants ? `${form.data.residents_evacuated} / ${form.data.total_participants}` : form.data.residents_evacuated || undefined}
                            />
                            <ReviewRow label="Roll-call completed" value={form.data.roll_call_completed ? 'Yes' : 'No'} />
                        </ReviewCard>
                        <ReviewCard icon={Flag} title="Outcome" onEdit={() => setStep(2)} span>
                            <ReviewRow
                                label="Outcome"
                                value={outcomeMeta ? <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[outcomeMeta.tone]}`}>{outcomeMeta.label}</span> : undefined}
                            />
                            <ReviewRow label="Improvements" value={form.data.improvements_identified} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
