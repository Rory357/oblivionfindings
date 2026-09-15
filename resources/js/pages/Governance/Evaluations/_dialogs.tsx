import { useForm } from '@inertiajs/react';
import {
    CalendarRange,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Crown,
    ListChecks,
    Loader2,
    Plus,
    Star,
    Trash2,
    UserRound,
    Users,
    UsersRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    FieldErr,
    InfoCard,
    SelectInput,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { evaluationTypeLabel as sharedEvaluationTypeLabel } from '@/lib/governance-labels';

export const EVALUATION_TYPES = [
    {
        key: 'board',
        label: sharedEvaluationTypeLabel('board'),
        description: 'How well the board works as a whole',
        icon: Users,
    },
    {
        key: 'committee',
        label: sharedEvaluationTypeLabel('committee'),
        description: 'How well a board committee is working',
        icon: UsersRound,
    },
    {
        key: 'chair',
        label: sharedEvaluationTypeLabel('chair'),
        description: 'How the chair leads the board',
        icon: Crown,
    },
    {
        key: 'individual',
        label: sharedEvaluationTypeLabel('individual'),
        description: 'Each member reflects on their own contribution',
        icon: UserRound,
    },
] as const;

export function evaluationTypeLabel(type: string | null | undefined): string {
    return type ? sharedEvaluationTypeLabel(type) : 'Not set';
}

export const QUESTION_TYPES = [
    { value: 'rating', label: 'Rating from 1 to 5' },
    { value: 'text', label: 'Written answer' },
    { value: 'yes_no', label: 'Yes or no' },
];

/** The words beside a 1–5 rating, so members know which end is which. */
export const RATING_ANCHORS: Record<number, string> = {
    1: 'Strongly disagree',
    2: 'Disagree',
    3: 'Neutral',
    4: 'Agree',
    5: 'Strongly agree',
};

/** "Choose a rating from 1 to 5." — the same words the server sends back. */
export function answerRequiredMessage(type: string): string {
    if (type === 'rating') return 'Choose a rating from 1 to 5.';
    if (type === 'yes_no') return 'Choose Yes or No.';
    return 'Answer this question.';
}

/**
 * Checked before a response is sent: one message per unanswered question,
 * keyed `answers.N` like the server's validation errors.
 */
export function unansweredQuestionErrors(
    questions: { type: string; required?: boolean }[],
    answers: Record<string, string | undefined>,
): Record<string, string> {
    const errors: Record<string, string> = {};
    questions.forEach((question, index) => {
        if (question.required === false) return;
        if ((answers[String(index)] ?? '').trim() !== '') return;
        errors[`answers.${index}`] = answerRequiredMessage(question.type);
    });
    return errors;
}

/** An existing draft to edit with the same wizard. */
export interface EvaluationWizardRecord {
    id: number;
    title: string;
    evaluation_type: string;
    board_committee_id?: number | null;
    period_start: string;
    period_end: string;
    due_date: string;
    questions: { text: string; type: string }[];
}

export interface CommitteeOption {
    id: number;
    name: string;
}

/** "Committee — Finance and audit committee" when a committee is named. */
export function evaluationSubject(
    type: string | null | undefined,
    committeeName?: string | null,
): string {
    const label = evaluationTypeLabel(type);
    return type === 'committee' && committeeName ? `${label} — ${committeeName}` : label;
}

type QuestionType = 'rating' | 'text' | 'yes_no';

interface EvaluationForm {
    title: string;
    evaluation_type: string;
    /** Only for committee evaluations; '' until chosen. */
    board_committee_id: string;
    period_start: string;
    period_end: string;
    due_date: string;
    questions: { text: string; type: QuestionType }[];
}

type StepKey = 'details' | 'period' | 'questions' | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Title and who is evaluated',
        icon: Star,
    },
    {
        key: 'period',
        label: 'Period',
        blurb: 'Period covered and due date',
        icon: CalendarRange,
    },
    {
        key: 'questions',
        label: 'Questions',
        blurb: 'What members answer',
        icon: ListChecks,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];

function stepForField(field: string): StepKey {
    if (field === 'title' || field === 'evaluation_type' || field === 'board_committee_id')
        return 'details';
    if (field.startsWith('questions')) return 'questions';
    return 'period';
}

function todayIso(): string {
    return toDateInput(new Date());
}

function validateStep(key: StepKey, data: EvaluationForm): Record<string, string> {
    const errors: Record<string, string> = {};
    if (key === 'details' && !data.title.trim()) {
        errors.title = 'Give the evaluation a title.';
    }
    if (key === 'details' && data.evaluation_type === 'committee' && !data.board_committee_id) {
        errors.board_committee_id = 'Choose which committee is being evaluated.';
    }
    if (key === 'period') {
        if (!data.period_start) errors.period_start = 'Set the period start.';
        if (!data.period_end) errors.period_end = 'Set the period end.';
        if (
            data.period_start &&
            data.period_end &&
            data.period_end <= data.period_start
        )
            errors.period_end = 'The period must end after it starts.';
        if (!data.due_date) errors.due_date = 'Set when responses are due.';
        else if (data.due_date <= todayIso())
            errors.due_date = 'The due date must be after today.';
    }
    if (key === 'questions') {
        if (data.questions.length === 0)
            errors.questions = 'Add at least one question.';
        data.questions.forEach((q, i) => {
            if (!q.text.trim())
                errors[`questions.${i}.text`] = 'Write the question.';
        });
    }
    return errors;
}

export function EvaluationWizardDialog({
    open,
    onClose,
    evaluation = null,
    committees = [],
}: {
    open: boolean;
    onClose: () => void;
    /** Present = edit this draft (prefilled); absent = create. */
    evaluation?: EvaluationWizardRecord | null;
    /** Committees a committee evaluation can be about. */
    committees?: CommitteeOption[];
}) {
    return open ? (
        <EvaluationWizardBody
            onClose={onClose}
            evaluation={evaluation}
            committees={committees}
        />
    ) : null;
}

function asQuestionType(type: string): QuestionType {
    return type === 'text' || type === 'yes_no' ? type : 'rating';
}

function EvaluationWizardBody({
    onClose,
    evaluation,
    committees,
}: {
    onClose: () => void;
    evaluation: EvaluationWizardRecord | null;
    committees: CommitteeOption[];
}) {
    const isEdit = evaluation !== null;
    const form = useForm<EvaluationForm>({
        title: evaluation?.title ?? '',
        evaluation_type: evaluation?.evaluation_type ?? 'board',
        board_committee_id: evaluation?.board_committee_id
            ? String(evaluation.board_committee_id)
            : '',
        period_start: evaluation?.period_start ?? '',
        period_end: evaluation?.period_end ?? '',
        due_date: evaluation?.due_date ?? '',
        questions: evaluation?.questions.length
            ? evaluation.questions.map((q) => ({
                  text: q.text,
                  type: asQuestionType(q.type),
              }))
            : [{ text: '', type: 'rating' }],
    });
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const current = STEPS[stepIndex];
    const err = (name: string) =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const pct = useMemo(() => {
        const checks = [
            data.title.trim(),
            data.evaluation_type,
            data.period_start,
            data.period_end,
            data.due_date,
            data.questions.some((q) => q.text.trim()),
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goTo = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const errors = validateStep(current.key, data);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const updateQuestion = (
        index: number,
        patch: Partial<EvaluationForm['questions'][number]>,
    ) =>
        setData(
            'questions',
            data.questions.map((q, i) => (i === index ? { ...q, ...patch } : q)),
        );

    const submit = () => {
        const all: Record<string, string> = {};
        for (const step of STEPS) Object.assign(all, validateStep(step.key, data));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(stepForField(Object.keys(all)[0]));
            return;
        }
        setClientErrors({});
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                if (first) goTo(stepForField(first));
            },
        };
        if (isEdit && evaluation) {
            form.put(`/governance/evaluations/${evaluation.id}`, options);
            return;
        }
        form.post('/governance/evaluations', options);
    };

    const isReview = current.key === 'review';

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={isEdit ? 'Edit board evaluation' : 'New board evaluation'}
                description={
                    isEdit
                        ? 'Change the details or questions while the evaluation is still a draft.'
                        : 'Set up an evaluation of the board, a committee, the chair or individual members, with its period and questions.'
                }
                railIcon={Star}
                railTitle={isEdit ? 'Edit evaluation' : 'New evaluation'}
                railSub="Board and members"
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={
                    done ? (
                        <WizardSuccessPane
                            title={isEdit ? 'Evaluation saved' : 'Evaluation created'}
                            blurb={`“${data.title}” is saved as a draft. Open it for responses when members should answer.`}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save evaluation' : 'Create evaluation'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={current.key}>
                    {current.key === 'details' ? (
                        <div className="grid gap-4">
                            <Field label="Title" required error={err('title')}>
                                <Input
                                    id="evaluation-title-input"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="e.g. 2026 Board effectiveness review"
                                />
                            </Field>
                            <Field
                                label="Evaluation type"
                                required
                                error={err('evaluation_type')}
                            >
                                <TilePicker
                                    value={data.evaluation_type}
                                    onChange={(v) => setData('evaluation_type', v)}
                                    options={EVALUATION_TYPES.map((t) => ({
                                        key: t.key,
                                        label: t.label,
                                        description: t.description,
                                        icon: t.icon,
                                    }))}
                                />
                            </Field>
                            {data.evaluation_type === 'committee' ? (
                                <Field
                                    label="Which committee?"
                                    required
                                    error={err('board_committee_id')}
                                >
                                    {committees.length > 0 ? (
                                        <SelectInput
                                            ariaLabel="Which committee?"
                                            placeholder="Choose a committee"
                                            value={data.board_committee_id}
                                            onChange={(v) => setData('board_committee_id', v)}
                                            options={committees.map((c) => ({
                                                value: String(c.id),
                                                label: c.name,
                                            }))}
                                        />
                                    ) : (
                                        <InfoCard icon={UsersRound} tone="warn">
                                            No committees are set up yet. Choose another
                                            kind of evaluation, or ask an administrator to
                                            add the committee.
                                        </InfoCard>
                                    )}
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {current.key === 'period' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Period start"
                                required
                                error={err('period_start')}
                            >
                                <Input
                                    id="evaluation-period-start"
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
                                error={err('period_end')}
                            >
                                <Input
                                    id="evaluation-period-end"
                                    type="date"
                                    value={data.period_end}
                                    onChange={(e) =>
                                        setData('period_end', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Responses due"
                                required
                                hint="Must be after today"
                                error={err('due_date')}
                            >
                                <Input
                                    id="evaluation-due-date"
                                    type="date"
                                    value={data.due_date}
                                    onChange={(e) =>
                                        setData('due_date', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ) : null}

                    {current.key === 'questions' ? (
                        <div className="flex flex-col gap-3">
                            {data.questions.map((q, i) => (
                                <div
                                    key={i}
                                    className="flex items-start gap-3 rounded-lg border border-border p-3"
                                >
                                    <span className="mt-2 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">
                                        {i + 1}
                                    </span>
                                    <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-[1fr_180px]">
                                        <div>
                                            <Input
                                                aria-label={`Question ${i + 1}`}
                                                placeholder={`Question ${i + 1}`}
                                                value={q.text}
                                                onChange={(e) =>
                                                    updateQuestion(i, {
                                                        text: e.target.value,
                                                    })
                                                }
                                            />
                                            <FieldErr>
                                                {err(`questions.${i}.text`)}
                                            </FieldErr>
                                        </div>
                                        <SelectInput
                                            ariaLabel={`Question ${i + 1} answer type`}
                                            placeholder="Answer type"
                                            value={q.type}
                                            onChange={(v) =>
                                                updateQuestion(i, {
                                                    type: v as QuestionType,
                                                })
                                            }
                                            options={QUESTION_TYPES}
                                        />
                                    </div>
                                    {data.questions.length > 1 ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Remove question ${i + 1}`}
                                            onClick={() =>
                                                setData(
                                                    'questions',
                                                    data.questions.filter(
                                                        (_, idx) => idx !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4 text-status-critical" />
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                            <FieldErr>{err('questions')}</FieldErr>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setData('questions', [
                                            ...data.questions,
                                            { text: '', type: 'rating' },
                                        ])
                                    }
                                >
                                    <Plus className="h-4 w-4" /> Add question
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {isReview ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={Star}
                                title="Details"
                                onEdit={() => goTo('details')}
                            >
                                <ReviewRow label="Title" value={data.title} />
                                <ReviewRow
                                    label="Who is evaluated"
                                    value={evaluationSubject(
                                        data.evaluation_type,
                                        committees.find(
                                            (c) => String(c.id) === data.board_committee_id,
                                        )?.name,
                                    )}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarRange}
                                title="Period"
                                onEdit={() => goTo('period')}
                            >
                                <ReviewRow
                                    label="Period"
                                    value={
                                        data.period_start && data.period_end
                                            ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
                                            : ''
                                    }
                                />
                                <ReviewRow
                                    label="Responses due"
                                    value={formatDateOnly(data.due_date, '')}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ListChecks}
                                title={`Questions (${data.questions.length})`}
                                onEdit={() => goTo('questions')}
                                span
                            >
                                {data.questions.map((q, i) => (
                                    <ReviewRow
                                        key={i}
                                        label={`${i + 1}. ${q.text || 'Untitled question'}`}
                                        value={
                                            QUESTION_TYPES.find(
                                                (t) => t.value === q.type,
                                            )?.label
                                        }
                                    />
                                ))}
                            </ReviewCard>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                mode={isEdit ? 'edit' : 'create'}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your changes to the details and questions will be lost. The saved draft stays as it was.'
                        : 'The evaluation details and questions entered so far will be lost.'
                }
            />
        </>
    );
}
