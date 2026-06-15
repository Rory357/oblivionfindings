/* eslint-disable no-restricted-syntax -- the escalation toggle row is a styled
 * native container (not a Card) and every colour is a semantic token. */
/* PRN effectiveness review — Add-Client WizardShell chrome (3 steps:
 * outcome → observations → escalation & sign-off). Replaces the bare
 * prn-effect-dialog <Dialog> on the eMAR PRN Records page (the worker board
 * keeps the lightweight sheet). Posts to the worker effectiveness endpoint —
 * which now accepts the explicit review-minutes chip and updateOrCreates so a
 * re-record revises the single register entry — then partial-reloads the page
 * props and shows the success pane (no navigation). */
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, Segmented, StepHead, TilePicker } from '@/components/wizard/primitives';
import { ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import type { ClientInfo, PrnFollowUp } from '@/pages/meds/today/types';
import { useForm } from '@inertiajs/react';
import { Check, CheckCircle2, ChevronLeft, ChevronRight, FileText, Loader2, MinusCircle, Stethoscope, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

type Outcome = 'effective' | 'partially_effective' | 'not_effective';

const STEPS: WizardStep[] = [
    { key: 'outcome', label: 'Outcome', blurb: 'Did the dose help?', icon: Stethoscope },
    { key: 'observations', label: 'Observations', blurb: 'Resident response', icon: FileText },
    { key: 'signoff', label: 'Escalation & sign-off', blurb: 'Confirm the review', icon: Check },
];

const OUTCOME_TILES = [
    { key: 'effective', label: 'Effective', description: 'Symptom resolved', icon: CheckCircle2 },
    { key: 'partially_effective', label: 'Partial', description: 'Some relief', icon: MinusCircle },
    { key: 'not_effective', label: 'Not effective', description: 'No improvement', icon: XCircle },
];
const MINUTE_OPTS = [
    { value: '15', label: '15m' },
    { value: '30', label: '30m' },
    { value: '45', label: '45m' },
    { value: '60', label: '60m' },
    { value: '90', label: '90m' },
];
const OUTCOME_LABEL: Record<Outcome, string> = {
    effective: 'Effective',
    partially_effective: 'Partial',
    not_effective: 'Not effective',
};

function agoLabel(iso: string | null): string | null {
    if (!iso) return null;
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return null;
    const mins = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (mins < 60) return `${mins}m ago`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h}h ${m}m ago` : `${h}h ago`;
}

export function PrnEffectivenessDialog({
    followUp,
    client,
    onClose,
}: {
    followUp: PrnFollowUp;
    client: ClientInfo | undefined;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [outcome, setOutcome] = useState<Outcome | ''>('');
    const [minutes, setMinutes] = useState('30');
    const [escNeeded, setEscNeeded] = useState(false);
    const [done, setDone] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const form = useForm<{ observations: string; escalation_action: string }>({
        observations: '',
        escalation_action: '',
    });

    const ago = useMemo(() => agoLabel(followUp.given_at), [followUp.given_at]);
    const railSub =
        [followUp.medication_name, followUp.given_time ? `given ${followUp.given_time}` : null, ago]
            .filter(Boolean)
            .join(' · ') || 'PRN effectiveness';

    const validate = (i: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (i === 0 && !outcome) e.outcome = 'Choose how the dose worked';
        if (i === 2 && escNeeded && !form.data.escalation_action.trim()) e.escalation_action = 'Describe what was done';
        return e;
    };
    const next = () => {
        const e = validate(step);
        setErrors(e);
        if (!Object.keys(e).length) setStep((s) => Math.min(s + 1, STEPS.length - 1));
    };
    const back = () => setStep((s) => Math.max(s - 1, 0));

    const submit = () => {
        const e = validate(2);
        setErrors(e);
        if (Object.keys(e).length) return;
        form.transform(() => ({
            client_medication_administration_id: followUp.administration_id,
            effectiveness: outcome,
            review_minutes_after: minutes ? parseInt(minutes, 10) : null,
            observations: form.data.observations.trim() || null,
            escalation_needed: escNeeded,
            escalation_action: escNeeded ? form.data.escalation_action.trim() || null : null,
        }));
        form.post('/meds/today/prn/effect', {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setDone(true),
        });
    };

    const isLast = step === 2;

    if (done) {
        return (
            <WizardShell
                open
                onClose={onClose}
                title="PRN effectiveness recorded"
                description="The effectiveness review has been saved to the PRN register."
                railIcon={Stethoscope}
                railTitle={client?.name ?? 'Resident'}
                railSub={railSub}
                steps={STEPS}
                stepIndex={2}
                onStepClick={() => {}}
                pct={null}
                success={
                    <WizardSuccessPane
                        title="Effectiveness recorded"
                        blurb="The PRN register and the resident's MAR have been updated."
                        actions={
                            <Button type="button" onClick={onClose}>
                                Done
                            </Button>
                        }
                    />
                }
            />
        );
    }

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Record PRN effectiveness"
            description="A guided review of whether an as-needed dose helped."
            railIcon={Stethoscope}
            railTitle={client?.name ?? 'Resident'}
            railSub={railSub}
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => {
                if (i < step) setStep(i);
            }}
            pct={null}
            footerStart={
                step > 0 ? (
                    <Button type="button" variant="ghost" onClick={back}>
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isLast ? (
                        <Button type="button" onClick={submit} disabled={form.processing}>
                            {form.processing ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" /> Saving…
                                </>
                            ) : (
                                <>
                                    <Check className="h-4 w-4" /> Save review
                                </>
                            )}
                        </Button>
                    ) : (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Stethoscope} title="Did the dose help?" blurb="Record the outcome and when you reviewed it." />
                    <div className="grid gap-4">
                        <Field label="Outcome" required error={errors.outcome}>
                            <TilePicker value={outcome} onChange={(v) => setOutcome(v as Outcome)} cols={3} options={OUTCOME_TILES} />
                        </Field>
                        <Field label="Reviewed after the dose" hint="minutes">
                            <Segmented value={minutes} onChange={setMinutes} options={MINUTE_OPTS} />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={FileText} title="What did you observe?" blurb="Resident response and any side effects." />
                    <div className="grid gap-4">
                        <Field label="Observations" hint="optional">
                            <Textarea
                                rows={4}
                                value={form.data.observations}
                                onChange={(e) => form.setData('observations', e.target.value)}
                                placeholder="e.g. Settled within 30 minutes; pain score down from 7 to 2; no side effects."
                            />
                        </Field>
                        <InfoCard icon={FileText}>
                            Note the resident&rsquo;s response and any side effects here. Structured side-effect, pain-score
                            and post-dose-vitals fields are a planned enhancement (see the PRN gap analysis).
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Escalation & sign-off" blurb="Flag if anyone needs to know, then save." />
                    <div className="grid gap-4">
                        <div
                            className={cn(
                                'flex items-center justify-between gap-3 rounded-lg border p-3',
                                escNeeded ? 'border-status-critical/40 bg-status-critical-bg' : 'border-border',
                            )}
                        >
                            <div>
                                <div className="text-sm font-semibold">Escalation needed</div>
                                <div className="text-xs text-muted-foreground">Tell the team leader or on-call nurse.</div>
                            </div>
                            <Switch checked={escNeeded} onCheckedChange={setEscNeeded} aria-label="Escalation needed" />
                        </div>
                        {escNeeded ? (
                            <Field label="What was done?" required error={errors.escalation_action}>
                                <Textarea
                                    rows={2}
                                    value={form.data.escalation_action}
                                    onChange={(e) => form.setData('escalation_action', e.target.value)}
                                    placeholder="e.g. Called the on-call nurse at 1:40 pm…"
                                />
                            </Field>
                        ) : null}
                        <div className="rounded-lg border border-border p-4">
                            <ReviewRow label="Outcome" value={outcome ? OUTCOME_LABEL[outcome] : null} />
                            <ReviewRow label="Reviewed after" value={minutes ? `${minutes} min` : null} />
                            <ReviewRow label="Observations" value={form.data.observations.trim() || null} />
                            <ReviewRow label="Escalation" value={escNeeded ? form.data.escalation_action.trim() || 'Raised' : 'Not needed'} />
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default PrnEffectivenessDialog;
