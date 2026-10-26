import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, FilePenLine } from 'lucide-react';
import { useRef, useState } from 'react';
import { FormQuestions } from './form-questions';
import { answerText, type Answers } from './form-types';
import { label, referralHref, type ReferralDetail } from './types';

export function FormAnswersPanel({ referral }: { referral: ReferralDetail }) {
    const [editing, setEditing] = useState<string | null>(null);
    const fields = referral.form_fields ?? [];
    const recorded = referral.form_answers ?? [];
    if (!fields.length && !recorded.length) return null;
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-section-title">
                        Additional information
                    </h2>
                    {[
                        ...new Set(
                            fields
                                .filter((field) => field.editable)
                                .map((field) => field.audience),
                        ),
                    ].map((audience) => (
                        <Button
                            key={audience}
                            variant="outline"
                            onClick={() => setEditing(audience)}
                        >
                            Update {audience} answers
                        </Button>
                    ))}
                </div>
                <p className="text-subtle">
                    Source answers remain in their permitted referral audience.
                    They do not replace reviewed consent, assessment or funding
                    decisions.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <dl className="grid gap-4 md:grid-cols-2">
                    {fields.map((field) => (
                        <div key={field.id}>
                            <dt className="text-caption text-muted-foreground">
                                {field.label} · {label(field.audience)}
                                {field.required !== 'optional'
                                    ? ` · Required ${field.required === 'intake' ? 'at intake' : 'before handover'}`
                                    : ''}
                            </dt>
                            <dd className="text-subtle break-words whitespace-pre-wrap">
                                {answerText(field.value, field.type)}
                            </dd>
                        </div>
                    ))}
                </dl>
                {recorded.length > 0 && (
                    <details>
                        <summary className="text-subtle cursor-pointer rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            Recorded question wording and answers
                        </summary>
                        <dl className="mt-3 space-y-3">
                            {recorded.map((answer) => (
                                <div key={answer.definition.id}>
                                    <dt className="text-caption text-muted-foreground">
                                        {answer.definition.label} ·{' '}
                                        {label(answer.definition.audience)}
                                    </dt>
                                    <dd className="text-subtle break-words whitespace-pre-wrap">
                                        {answerText(
                                            answer.value,
                                            answer.definition.type,
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </details>
                )}
                {fields.length > 0 &&
                    !fields.some((field) => field.editable) && (
                        <p className="text-subtle">
                            Editing requires current collection authority, an
                            open referral and permission for the question’s
                            audience.
                        </p>
                    )}
            </CardContent>
            {editing && (
                <AnswerEditor
                    key={referral.reference}
                    referral={referral}
                    audience={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </Card>
    );
}

function AnswerEditor({
    referral,
    audience,
    onClose,
}: {
    referral: ReferralDetail;
    audience: string;
    onClose: () => void;
}) {
    const [fields] = useState(() =>
        (referral.form_fields ?? []).filter(
            (field) => field.editable && field.audience === audience,
        ),
    );
    const [version] = useState(referral.version);
    const [answers, setAnswers] = useState<Answers>(() =>
        Object.fromEntries(fields.map((field) => [field.id, field.value])),
    );
    const [reason, setReason] = useState('');
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const changed = fields.filter((field) => answers[field.id] !== field.value);
    function close() {
        if (busy) return;
        if (saved) onClose();
        else setDiscard(true);
    }
    async function save() {
        if (
            pending.current ||
            uncertain ||
            reason.trim().length < 5 ||
            !changed.length
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.patch(
                `${referralHref(referral.reference)}/forms`,
                {
                    version,
                    answers: Object.fromEntries(
                        changed.map((field) => [field.id, answers[field.id]]),
                    ),
                    reason,
                },
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : null;
            const errors = axios.isAxiosError(caught)
                ? caught.response?.data?.errors
                : null;
            setUncertain(status !== 422);
            setError(
                status === 422
                    ? errors
                        ? Object.values(errors as Record<string, string[]>)
                              .flat()
                              .join(' ')
                        : 'Review collection authority and the answers before saving.'
                    : 'Save was not confirmed. Close this draft and reload the referral to check the recorded answers before trying again.',
            );
            if (status === 422) setStep(0);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Update additional information"
                description={referral.name}
                railIcon={FilePenLine}
                railTitle="Additional information"
                railSub={referral.name}
                steps={[
                    {
                        key: 'answers',
                        label: 'Answers',
                        blurb: 'Record the person’s information',
                        icon: FilePenLine,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check the changes',
                        icon: ClipboardCheck,
                    },
                ]}
                stepIndex={step}
                onStepClick={(value) => {
                    if (!busy) setStep(value);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? setStep(0) : close())}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            disabled={
                                busy ||
                                uncertain ||
                                !changed.length ||
                                reason.trim().length < 5
                            }
                            onClick={() => setStep(1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy ||
                                uncertain ||
                                !changed.length ||
                                reason.trim().length < 5
                            }
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save answers'}
                        </Button>
                    )
                }
            >
                {saved ? (
                    <WizardSuccessPane
                        title="Answers saved"
                        blurb="The referral retains the recorded questions and their information audience. Service readiness will be reviewed again where required."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : (
                    <div className="space-y-5">
                        {error && (
                            <Alert variant="warning">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {step === 0 ? (
                            <fieldset
                                disabled={busy || uncertain}
                                className="space-y-5"
                            >
                                <FormQuestions
                                    fields={fields}
                                    answers={answers}
                                    onChange={setAnswers}
                                />
                                <Field label="Reason for this change">
                                    <Textarea
                                        value={reason}
                                        maxLength={2000}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                </Field>
                            </fieldset>
                        ) : (
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Answers to update"
                            >
                                {changed.map((field) => (
                                    <ReviewRow
                                        key={field.id}
                                        label={field.label}
                                        value={answerText(
                                            answers[field.id],
                                            field.type,
                                        )}
                                    />
                                ))}
                                <ReviewRow label="Reason" value={reason} />
                            </ReviewCard>
                        )}
                    </div>
                )}
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                title="Discard this draft?"
                description="Unsaved answers will be cleared. If a save was not confirmed, reload the referral and check its recorded answers."
                confirmText="Discard draft"
                onConfirm={onClose}
            />
        </>
    );
}
