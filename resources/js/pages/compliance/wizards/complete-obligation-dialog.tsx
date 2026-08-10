import { Button } from '@/components/ui/button';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Loader2,
    Paperclip,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type ObligationOption = {
    id: number;
    title: string;
    framework: string;
    due_date?: string | null;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'obligation',
        label: 'Obligation',
        blurb: 'Which one is done',
        icon: ShieldCheck,
    },
    {
        key: 'confirm',
        label: 'Confirm',
        blurb: 'Mark complete',
        icon: CheckCircle2,
    },
];

export function CompleteObligationDialog({
    open,
    onClose,
    obligations,
    initialObligationId = null,
    onRecordEvidence,
}: {
    open: boolean;
    onClose: () => void;
    obligations: ObligationOption[];
    initialObligationId?: number | null;
    /** Cross-link: jump to the Record-evidence wizard for this obligation. */
    onRecordEvidence?: (obligationId: number) => void;
}) {
    const form = useForm<{ _modal: boolean }>({ _modal: true });
    const { processing } = form;
    const [obligationId, setObligationId] = useState<string>(
        initialObligationId ? String(initialObligationId) : '',
    );
    const [stepIndex, setStepIndex] = useState(initialObligationId ? 1 : 0);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState(false);

    const obligation = useMemo(
        () => obligations.find((o) => String(o.id) === obligationId) ?? null,
        [obligations, obligationId],
    );

    const goTo = (idx: number) =>
        setStepIndex(Math.max(0, Math.min(idx, STEPS.length - 1)));
    const next = () => {
        if (!obligationId) {
            setError('Choose the obligation you have completed');
            return;
        }
        setError(null);
        goTo(stepIndex + 1);
    };

    const submit = () => {
        if (!obligationId) {
            setError('Choose the obligation you have completed');
            goTo(0);
            return;
        }
        form.post(`/governance/compliance/${obligationId}/complete`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success('Obligation marked complete');
                setDone(true);
            },
            onError: () => toast.error('Could not complete the obligation'),
        });
    };

    const cur = STEPS[stepIndex];
    const isConfirm = cur.key === 'confirm';

    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Complete obligation"
                description="Mark a compliance obligation as complete."
                railIcon={BadgeCheck}
                railTitle="Complete obligation"
                railSub="Governance register"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Obligation completed"
                        blurb={
                            <>
                                <strong>{obligation?.title}</strong> is marked
                                complete. If it recurs, the next occurrence has
                                been scheduled automatically.
                            </>
                        }
                        actions={
                            <Button asChild>
                                <a href="/governance/compliance">
                                    <ShieldCheck className="h-4 w-4" /> Open
                                    register
                                </a>
                            </Button>
                        }
                    />
                }
            />
        );
    }

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Complete obligation"
            description="Mark a compliance obligation as complete."
            railIcon={BadgeCheck}
            railTitle="Complete obligation"
            railSub="Governance register"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={goTo}
            footerStart={
                stepIndex > 0 && !initialObligationId ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => goTo(stepIndex - 1)}
                    >
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isConfirm ? (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing}
                        >
                            {processing ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" />{' '}
                                    Completing…
                                </>
                            ) : (
                                <>
                                    <Check className="h-4 w-4" /> Mark complete
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
            {cur.key === 'obligation' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="Which obligation is complete?"
                        blurb="Completing records who and when, and schedules the next occurrence."
                    />
                    <Field
                        label="Obligation"
                        required
                        error={error ?? undefined}
                    >
                        <SelectInput
                            value={obligationId}
                            onChange={(v) => {
                                setObligationId(v);
                                setError(null);
                            }}
                            placeholder="Choose an obligation"
                            options={obligations.map((o) => ({
                                value: String(o.id),
                                label: `${o.title} · ${o.framework}`,
                            }))}
                        />
                    </Field>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Confirm completion"
                        blurb="This marks the obligation complete and timestamps it against you."
                    />
                    <div className="grid gap-3">
                        <ReviewCard icon={ShieldCheck} title="Obligation">
                            <ReviewRow
                                label="Obligation"
                                value={obligation?.title}
                            />
                            <ReviewRow
                                label="Framework"
                                value={obligation?.framework}
                            />
                            <ReviewRow
                                label="Was due"
                                value={obligation?.due_date}
                            />
                        </ReviewCard>
                        {obligation && onRecordEvidence ? (
                            <InfoCard icon={Paperclip}>
                                Good practice: attach the evidence that
                                satisfies this obligation.{' '}
                                <Button
                                    unstyled
                                    type="button"
                                    onClick={() =>
                                        onRecordEvidence(obligation.id)
                                    }
                                    className="font-semibold text-primary hover:underline"
                                >
                                    Record evidence instead
                                </Button>
                                .
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default CompleteObligationDialog;
