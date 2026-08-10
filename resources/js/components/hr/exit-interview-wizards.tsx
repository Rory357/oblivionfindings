/* Exit-interview wizard — the stepper-modal replacement for the old
 * full-page "Record exit interview" form. Built on the shared HR wizard kit
 * (WizardShell + primitives) so it is visually identical to the asset /
 * offboarding modals. Posts to exit-interviews.store; recording an interview
 * also auto-completes the matching task on the employee's open offboarding
 * checklist (server-side seam). */
import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    Lock,
    MessageSquareQuote,
    MessagesSquare,
    Star,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

export interface ExitInterviewEmployeeOption {
    id: number;
    position_title: string | null;
    user: { id: number; name: string } | null;
}

export interface ExitInterviewerOption {
    id: number;
    name: string;
}

export interface DepartureReasonOption {
    value: string;
    label: string;
}

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Interview',
        blurb: 'Who, when & why',
        icon: User,
    },
    {
        key: 'ratings',
        label: 'Ratings',
        blurb: 'Satisfaction & recommend',
        icon: Star,
    },
    {
        key: 'feedback',
        label: 'Feedback',
        blurb: 'What they told us',
        icon: MessagesSquare,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: CheckCircle2,
    },
];

const RECOMMEND_OPTS = [
    { value: 'unspecified', label: 'Not specified' },
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

function pageFlashError(page: {
    props: Record<string, unknown>;
}): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/** Record a completed exit interview for a departing employee. */
export function ExitInterviewWizard({
    employees,
    interviewers,
    departureReasons,
    onClose,
}: {
    employees: ExitInterviewEmployeeOption[];
    interviewers: ExitInterviewerOption[];
    departureReasons: DepartureReasonOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        employee_profile_id: '',
        interviewer_user_id: '',
        interview_date: new Date().toISOString().substring(0, 10),
        departure_reason: '',
        would_recommend: 'unspecified',
        overall_satisfaction: 0,
        what_went_well: '',
        what_could_improve: '',
        management_feedback: '',
        culture_feedback: '',
        additional_comments: '',
        is_confidential: true as boolean,
    });

    const people: PersonOption[] = employees.map((e) => ({
        value: String(e.id),
        label: e.user?.name ?? 'Unknown',
        sub: e.position_title ?? undefined,
    }));

    const picked =
        employees.find((e) => String(e.id) === form.data.employee_profile_id) ??
        null;
    const interviewerName =
        interviewers.find((i) => String(i.id) === form.data.interviewer_user_id)
            ?.name ?? null;
    const reasonLabel =
        departureReasons.find((r) => r.value === form.data.departure_reason)
            ?.label ?? null;

    const detailsValid =
        form.data.employee_profile_id !== '' &&
        form.data.interviewer_user_id !== '' &&
        form.data.interview_date !== '' &&
        form.data.departure_reason !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            would_recommend:
                data.would_recommend === 'unspecified'
                    ? null
                    : data.would_recommend === 'yes',
            overall_satisfaction:
                data.overall_satisfaction === 0
                    ? null
                    : data.overall_satisfaction,
        }));
        form.post('/hr/exit-interviews', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Record exit interview"
            description="Capture structured departure feedback."
            railIcon={MessageSquareQuote}
            railTitle="Exit interview"
            railSub="Employee lifecycle"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Exit interview recorded"
                        blurb={
                            <>
                                {picked?.user?.name ?? 'The employee'}&rsquo;s
                                departure feedback is saved
                                {picked
                                    ? ' — any open offboarding exit-interview task was ticked off automatically'
                                    : ''}
                                .
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !detailsValid}
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Save exit interview'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={wizard.index === 0 && !detailsValid}
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={User}
                        title="Interview details"
                        blurb="Who is leaving, who conducted the interview, and the primary reason."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Departing employee"
                            required
                            span
                            error={form.errors.employee_profile_id}
                        >
                            <PeoplePicker
                                value={form.data.employee_profile_id}
                                onChange={(v) =>
                                    form.setData('employee_profile_id', v)
                                }
                                people={people}
                                placeholder="Select employee…"
                            />
                        </Field>
                        <Field
                            label="Interviewer"
                            required
                            error={form.errors.interviewer_user_id}
                        >
                            <SelectInput
                                value={form.data.interviewer_user_id}
                                onChange={(v) =>
                                    form.setData('interviewer_user_id', v)
                                }
                                placeholder="Select interviewer"
                                options={interviewers.map((i) => ({
                                    value: String(i.id),
                                    label: i.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Interview date"
                            required
                            error={form.errors.interview_date}
                        >
                            <Input
                                type="date"
                                value={form.data.interview_date}
                                onChange={(e) =>
                                    form.setData(
                                        'interview_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Primary departure reason"
                            required
                            span
                            error={form.errors.departure_reason}
                        >
                            <SelectInput
                                value={form.data.departure_reason}
                                onChange={(v) =>
                                    form.setData('departure_reason', v)
                                }
                                placeholder="Select reason"
                                options={departureReasons}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Star}
                        title="Ratings"
                        blurb="How satisfied were they overall, and would they recommend us?"
                    />
                    <Field label="Overall satisfaction (1–5)" hint="optional">
                        <div className="flex items-center gap-1">
                            {[1, 2, 3, 4, 5].map((star) => (
                                <Button
                                    key={star}
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Rate ${star} star${star === 1 ? '' : 's'}`}
                                    onClick={() =>
                                        form.setData(
                                            'overall_satisfaction',
                                            star,
                                        )
                                    }
                                    className="h-8 w-8"
                                >
                                    <Star
                                        className={`h-6 w-6 ${
                                            star <=
                                            form.data.overall_satisfaction
                                                ? 'fill-status-warning text-status-warning'
                                                : 'text-muted-foreground'
                                        }`}
                                    />
                                </Button>
                            ))}
                            {form.data.overall_satisfaction > 0 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="ml-2 text-xs text-muted-foreground"
                                    onClick={() =>
                                        form.setData('overall_satisfaction', 0)
                                    }
                                >
                                    Clear
                                </Button>
                            )}
                        </div>
                    </Field>
                    <div className="mt-4">
                        <Field label="Would recommend as an employer">
                            <Segmented
                                value={form.data.would_recommend}
                                onChange={(v) =>
                                    form.setData('would_recommend', v)
                                }
                                options={RECOMMEND_OPTS}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={MessagesSquare}
                        title="Feedback"
                        blurb="All fields are optional — capture what was shared."
                    />
                    <div className="grid gap-3.5">
                        <Field label="What went well">
                            <Textarea
                                rows={3}
                                value={form.data.what_went_well}
                                onChange={(e) =>
                                    form.setData(
                                        'what_went_well',
                                        e.target.value,
                                    )
                                }
                                placeholder="Positive experiences during their time here…"
                            />
                        </Field>
                        <Field label="What could improve">
                            <Textarea
                                rows={3}
                                value={form.data.what_could_improve}
                                onChange={(e) =>
                                    form.setData(
                                        'what_could_improve',
                                        e.target.value,
                                    )
                                }
                                placeholder="Areas for improvement…"
                            />
                        </Field>
                        <Field label="Management feedback">
                            <Textarea
                                rows={3}
                                value={form.data.management_feedback}
                                onChange={(e) =>
                                    form.setData(
                                        'management_feedback',
                                        e.target.value,
                                    )
                                }
                                placeholder="Feedback on management and leadership…"
                            />
                        </Field>
                        <Field label="Culture feedback">
                            <Textarea
                                rows={3}
                                value={form.data.culture_feedback}
                                onChange={(e) =>
                                    form.setData(
                                        'culture_feedback',
                                        e.target.value,
                                    )
                                }
                                placeholder="Feedback on company culture…"
                            />
                        </Field>
                        <Field label="Additional comments">
                            <Textarea
                                rows={3}
                                value={form.data.additional_comments}
                                onChange={(e) =>
                                    form.setData(
                                        'additional_comments',
                                        e.target.value,
                                    )
                                }
                                placeholder="Any other feedback…"
                            />
                        </Field>
                    </div>
                    <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <input
                            type="checkbox"
                            checked={form.data.is_confidential}
                            onChange={(e) =>
                                form.setData(
                                    'is_confidential',
                                    e.target.checked,
                                )
                            }
                            className="mt-0.5 h-4 w-4 accent-[var(--primary)]"
                        />
                        <span>
                            <span className="flex items-center gap-1.5 text-[13px] font-semibold">
                                <Lock className="h-3.5 w-3.5" /> Mark as
                                confidential
                            </span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Restricts detailed feedback to HR managers.
                            </span>
                        </span>
                    </label>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb="Check the details, then record the interview."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={User}
                            title="Interview"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow
                                label="Employee"
                                value={picked?.user?.name}
                            />
                            <ReviewRow
                                label="Interviewer"
                                value={interviewerName ?? undefined}
                            />
                            <ReviewRow
                                label="Date"
                                value={form.data.interview_date}
                            />
                            <ReviewRow
                                label="Reason"
                                value={reasonLabel ?? undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Star}
                            title="Ratings"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Satisfaction"
                                value={
                                    form.data.overall_satisfaction > 0
                                        ? `${form.data.overall_satisfaction}/5`
                                        : 'Not rated'
                                }
                            />
                            <ReviewRow
                                label="Would recommend"
                                value={
                                    RECOMMEND_OPTS.find(
                                        (o) =>
                                            o.value ===
                                            form.data.would_recommend,
                                    )?.label
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={MessagesSquare}
                            title="Feedback"
                            span
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Sections filled"
                                value={String(
                                    [
                                        form.data.what_went_well,
                                        form.data.what_could_improve,
                                        form.data.management_feedback,
                                        form.data.culture_feedback,
                                        form.data.additional_comments,
                                    ].filter((v) => v.trim() !== '').length,
                                )}
                            />
                            <ReviewRow
                                label="Confidential"
                                value={form.data.is_confidential ? 'Yes' : 'No'}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default ExitInterviewWizard;
