import { Head, router, useForm } from '@inertiajs/react';
import {
    BarChart3,
    CalendarClock,
    CheckCircle2,
    CircleDashed,
    EyeOff,
    ListChecks,
    Lock,
    Pencil,
    Play,
    Star,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { ProgressValue } from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { FieldErr, InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly, toDateInput } from '@/lib/datetime';
import { governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    EvaluationWizardDialog,
    QUESTION_TYPES,
    RATING_ANCHORS,
    evaluationSubject,
    unansweredQuestionErrors,
    type CommitteeOption,
} from './_dialogs';

interface Question {
    id: number | string | null;
    text: string;
    type: 'rating' | 'text' | 'yes_no';
    required: boolean;
}

interface Respondent {
    name: string;
    submitted_at: string | null;
}

interface Evaluation {
    id: number;
    title: string;
    evaluation_type: string;
    board_committee_id: number | null;
    committee_name: string | null;
    status: string;
    period_start: string;
    period_end: string;
    due_date: string;
    questions: Question[];
    respondents: Respondent[];
    anonymous_respondent_count: number;
}

interface Props extends PageProps {
    evaluation: Evaluation;
    myResponse: {
        answers: Record<string, string>;
        overall_comments: string;
        submitted_at: string | null;
    } | null;
    responseRate: { total: number; completed: number };
    /** Why this viewer can't answer right now; null when the form is theirs to use. */
    respondBlockedReason: string | null;
    /** For editing a draft committee evaluation. */
    committees?: CommitteeOption[];
}

function scrollToSection(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function EvaluationShow({
    auth,
    evaluation,
    myResponse,
    responseRate,
    respondBlockedReason,
    committees = [],
}: Props) {
    const canManage = Boolean(auth.can?.governance?.evaluations?.manage);
    const form = useForm({
        answers: myResponse?.answers ?? ({} as Record<string, string>),
        overall_comments: myResponse?.overall_comments ?? '',
    });
    const { data, setData, processing } = form;
    const [clientErrors, setClientErrors] = useState<Record<string, string>>({});
    const [editOpen, setEditOpen] = useState(false);
    const [confirm, setConfirm] = useState<'open' | 'close' | null>(null);

    const today = toDateInput(new Date());
    const isDraft = evaluation.status === 'draft';
    const isOpen = evaluation.status === 'active' || evaluation.status === 'open';
    const pastDue = isOpen && evaluation.due_date < today;
    const canRespond = respondBlockedReason === null;
    const chip = governanceStatus('evaluation_status', evaluation.status);
    const resultsHref = `/governance/evaluations/${evaluation.id}/results`;
    const responsePercent =
        responseRate.total > 0
            ? Math.min(100, (responseRate.completed / responseRate.total) * 100)
            : 0;
    const hasRatingQuestion = evaluation.questions.some((q) => q.type === 'rating');

    const serverErrors = form.errors as Record<string, string | undefined>;
    const answerError = (index: number) =>
        clientErrors[`answers.${index}`] ?? serverErrors[`answers.${index}`];

    const setAnswer = (index: number, value: string) => {
        setData('answers', { ...data.answers, [String(index)]: value });
        if (clientErrors[`answers.${index}`]) {
            const next = { ...clientErrors };
            delete next[`answers.${index}`];
            setClientErrors(next);
        }
    };

    const handleRespond = (event: React.FormEvent) => {
        event.preventDefault();
        const missing = unansweredQuestionErrors(evaluation.questions, data.answers);
        setClientErrors(missing);
        const first = Object.keys(missing)[0];
        if (first) {
            document
                .getElementById(`question-${first.split('.')[1]}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        form.post(`/governance/evaluations/${evaluation.id}/respond`, {
            preserveScroll: true,
        });
    };

    const runConfirmed = () => {
        if (confirm === 'open') {
            router.post(`/governance/evaluations/${evaluation.id}/launch`, {}, { preserveScroll: true });
        } else if (confirm === 'close') {
            router.post(`/governance/evaluations/${evaluation.id}/close`, {}, { preserveScroll: true });
        }
    };

    // The server's reason says who answers ("Only board members…") before it
    // says anything about timing, so the heading follows the same order.
    const notForViewer = respondBlockedReason?.startsWith('Only ') ?? false;
    const blockedTitle = notForViewer
        ? "You don't answer this evaluation"
        : isDraft
          ? 'Not open for responses yet'
          : !isOpen
            ? 'This evaluation is closed'
            : 'The due date has passed';

    const responseCountWords = `${responseRate.completed} of ${responseRate.total} current board ${responseRate.total === 1 ? 'member' : 'members'}`;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Evaluations', href: '/governance/evaluations' },
                {
                    title: evaluation.title,
                    href: `/governance/evaluations/${evaluation.id}`,
                },
            ]}
        >
            <Head title={evaluation.title} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/evaluations"
                        icon={Star}
                        title={evaluation.title}
                        titleDusk="evaluation-title"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${evaluationSubject(evaluation.evaluation_type, evaluation.committee_name)} · Covers ${formatDateOnly(evaluation.period_start)} – ${formatDateOnly(evaluation.period_end)} · Responses due ${formatDateOnly(evaluation.due_date)}`}
                        actions={
                            <>
                                {!isDraft ? (
                                    <PageHeaderGlassButton
                                        icon={BarChart3}
                                        onClick={() => router.visit(resultsHref)}
                                    >
                                        Results
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canManage && isDraft ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit draft
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canManage && isOpen ? (
                                    <PageHeaderGlassButton
                                        icon={Lock}
                                        onClick={() => setConfirm('close')}
                                    >
                                        Close evaluation
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canManage && isDraft ? (
                                    <PageHeaderPrimaryButton
                                        icon={Play}
                                        onClick={() => setConfirm('open')}
                                    >
                                        Open for responses
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Responses"
                                    value={`${responseRate.completed}/${responseRate.total}`}
                                    ariaLabel="See who has responded"
                                    onClick={() => scrollToSection('who-responded')}
                                >
                                    <PageHeaderMeterBar percent={responsePercent} />
                                    <PageHeaderMeterCaption>
                                        {Math.round(responsePercent)}% of current
                                        board members
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Questions"
                                    ariaLabel="Go to the questions"
                                    onClick={() => scrollToSection('evaluation-questions')}
                                >
                                    <PageHeaderMeterBig>
                                        {evaluation.questions.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        plus overall comments
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {!isDraft ? (
                                    <PageHeaderMeterBlock
                                        label="Not yet responded"
                                        tone={
                                            isOpen && responseRate.total - responseRate.completed > 0
                                                ? pastDue
                                                    ? 'critical'
                                                    : 'warning'
                                                : 'brand'
                                        }
                                        ariaLabel="See who has responded"
                                        onClick={() => scrollToSection('who-responded')}
                                    >
                                        <PageHeaderMeterBig>
                                            {Math.max(0, responseRate.total - responseRate.completed)}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {pastDue
                                                ? `Responses were due ${formatDateOnly(evaluation.due_date)}`
                                                : isOpen
                                                  ? `Due ${formatDateOnly(evaluation.due_date)}`
                                                  : 'Evaluation closed'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                {canRespond || myResponse ? (
                                    <PageHeaderMeterBlock
                                        label="Your response"
                                        tone={myResponse ? 'success' : 'warning'}
                                        ariaLabel="Go to your response"
                                        onClick={() =>
                                            scrollToSection('evaluation-questions')
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {myResponse ? 'Responded' : 'Not yet'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {myResponse?.submitted_at
                                                ? `Saved ${formatDateLong(myResponse.submitted_at)}`
                                                : `Answer by ${formatDateOnly(evaluation.due_date)}`}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div id="evaluation-questions" className="flex flex-col gap-5 lg:col-span-2">
                        {canRespond ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Your response</CardTitle>
                                    <CardDescription>
                                        {myResponse
                                            ? 'You have responded. Change any answer and save again until the evaluation closes.'
                                            : 'Answer every question, then save your response.'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleRespond}
                                        noValidate
                                        className="flex flex-col gap-6"
                                    >
                                        <InfoCard icon={EyeOff}>
                                            Your answers are shown to the board
                                            without your name. The board can see
                                            who has responded.
                                        </InfoCard>
                                        {hasRatingQuestion ? (
                                            <p className="text-caption">
                                                For ratings, 1 = Strongly disagree
                                                and 5 = Strongly agree. Every
                                                question needs an answer.
                                            </p>
                                        ) : (
                                            <p className="text-caption">
                                                Every question needs an answer.
                                            </p>
                                        )}
                                        {evaluation.questions.map((q, i) => {
                                            const current = data.answers[String(i)] ?? '';
                                            const error = answerError(i);
                                            const errorId = `question-${i}-error`;
                                            return (
                                                <fieldset
                                                    key={i}
                                                    id={`question-${i}`}
                                                    aria-describedby={error ? errorId : undefined}
                                                    aria-invalid={error ? true : undefined}
                                                >
                                                    <legend className="text-sm font-medium">
                                                        {i + 1}. {q.text}
                                                        {q.required ? (
                                                            <>
                                                                <span
                                                                    aria-hidden="true"
                                                                    className="ml-0.5 text-status-critical"
                                                                >
                                                                    *
                                                                </span>
                                                                <span className="sr-only">
                                                                    {' '}
                                                                    (required)
                                                                </span>
                                                            </>
                                                        ) : null}
                                                    </legend>
                                                    {q.type === 'rating' ? (
                                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                                            {[1, 2, 3, 4, 5].map((n) => {
                                                                const chosen = current === String(n);
                                                                return (
                                                                    <Button
                                                                        key={n}
                                                                        dusk={`rating-${i}-${n}`}
                                                                        type="button"
                                                                        title={RATING_ANCHORS[n]}
                                                                        aria-label={`${n} – ${RATING_ANCHORS[n]}`}
                                                                        aria-pressed={chosen}
                                                                        variant={chosen ? 'default' : 'outline'}
                                                                        size="sm"
                                                                        onClick={() => setAnswer(i, String(n))}
                                                                    >
                                                                        {n}
                                                                    </Button>
                                                                );
                                                            })}
                                                            <span className="text-caption">
                                                                {current && RATING_ANCHORS[Number(current)]
                                                                    ? RATING_ANCHORS[Number(current)]
                                                                    : '1 = Strongly disagree … 5 = Strongly agree'}
                                                            </span>
                                                        </div>
                                                    ) : null}
                                                    {q.type === 'text' ? (
                                                        <Textarea
                                                            dusk={`answer-${i}`}
                                                            aria-label={q.text}
                                                            className="mt-2"
                                                            value={current}
                                                            onChange={(e) => setAnswer(i, e.target.value)}
                                                        />
                                                    ) : null}
                                                    {q.type === 'yes_no' ? (
                                                        <div className="mt-2 flex gap-2">
                                                            {['Yes', 'No'].map((v) => (
                                                                <Button
                                                                    key={v}
                                                                    dusk={`answer-${i}-${v.toLowerCase()}`}
                                                                    type="button"
                                                                    aria-pressed={current === v}
                                                                    variant={current === v ? 'default' : 'outline'}
                                                                    size="sm"
                                                                    onClick={() => setAnswer(i, v)}
                                                                >
                                                                    {v}
                                                                </Button>
                                                            ))}
                                                        </div>
                                                    ) : null}
                                                    <FieldErr id={errorId}>{error}</FieldErr>
                                                </fieldset>
                                            );
                                        })}
                                        <div>
                                            <Label htmlFor="overall-comments">
                                                Overall comments (optional)
                                            </Label>
                                            <Textarea
                                                id="overall-comments"
                                                dusk="overall-comments"
                                                className="mt-2"
                                                value={data.overall_comments}
                                                onChange={(e) =>
                                                    setData('overall_comments', e.target.value)
                                                }
                                                rows={3}
                                            />
                                            <FieldErr>{serverErrors.overall_comments}</FieldErr>
                                        </div>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                dusk="submit-evaluation-response"
                                            >
                                                {myResponse ? 'Save changes' : 'Save response'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        ) : (
                            <>
                                <EmptyState
                                    icon={notForViewer ? UserRound : isDraft ? CircleDashed : isOpen ? CalendarClock : Lock}
                                    title={blockedTitle}
                                    description={respondBlockedReason ?? undefined}
                                    action={
                                        !isDraft ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.visit(resultsHref)}
                                            >
                                                <BarChart3 className="h-3.5 w-3.5" />
                                                View results
                                            </Button>
                                        ) : undefined
                                    }
                                />
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <ListChecks className="h-4 w-4 text-primary" />
                                            Questions
                                        </CardTitle>
                                        <CardDescription>
                                            {isDraft && canManage
                                                ? 'Check the questions before you open the evaluation. They can’t be changed once it’s open.'
                                                : 'What board members are asked, plus overall comments.'}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {evaluation.questions.length === 0 ? (
                                            <EmptyState
                                                variant="inline"
                                                icon={ListChecks}
                                                title="No questions yet"
                                            />
                                        ) : (
                                            <ol className="flex flex-col gap-2">
                                                {evaluation.questions.map((q, i) => (
                                                    <li
                                                        key={i}
                                                        className="flex items-start justify-between gap-3 rounded-lg border border-border p-3 text-sm"
                                                    >
                                                        <span>
                                                            {i + 1}. {q.text}
                                                        </span>
                                                        <span className="shrink-0 text-caption">
                                                            {QUESTION_TYPES.find((t) => t.value === q.type)?.label ??
                                                                'Written answer'}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ol>
                                        )}
                                    </CardContent>
                                </Card>
                            </>
                        )}
                    </div>

                    <Card id="who-responded">
                        <CardHeader>
                            <CardTitle>Who has responded</CardTitle>
                            <CardDescription>{responseCountWords}</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ProgressValue percent={responsePercent}>
                                {Math.round(responsePercent)}% responded
                            </ProgressValue>
                            {responseRate.completed === 0 ? (
                                <EmptyState
                                    variant="inline"
                                    icon={CircleDashed}
                                    title={isDraft ? 'Not open for responses yet' : 'No responses yet'}
                                />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {evaluation.respondents.map((respondent, index) => (
                                        <li
                                            key={`${respondent.name}-${index}`}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                                            <span className="truncate">{respondent.name}</span>
                                        </li>
                                    ))}
                                    {evaluation.anonymous_respondent_count > 0 ? (
                                        <li className="text-caption">
                                            {evaluation.anonymous_respondent_count === 1
                                                ? '1 person responded without their name shown'
                                                : `${evaluation.anonymous_respondent_count} people responded without their names shown`}
                                        </li>
                                    ) : null}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

            <ConfirmDialog
                open={confirm !== null}
                onClose={() => setConfirm(null)}
                onConfirm={runConfirmed}
                variant="default"
                title={
                    confirm === 'close'
                        ? 'Close this evaluation?'
                        : 'Open this evaluation for responses?'
                }
                description={
                    confirm === 'close'
                        ? `No more responses can be added or changed. So far ${responseCountWords} ${responseRate.total === 1 ? 'has' : 'have'} responded.`
                        : `Board members can answer straight away, until ${formatDateOnly(evaluation.due_date)}. The questions can't be changed once it's open.`
                }
                confirmText={confirm === 'close' ? 'Close evaluation' : 'Open for responses'}
            />

            {canManage && isDraft ? (
                <EvaluationWizardDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    committees={committees}
                    evaluation={{
                        id: evaluation.id,
                        title: evaluation.title,
                        evaluation_type: evaluation.evaluation_type,
                        board_committee_id: evaluation.board_committee_id,
                        period_start: evaluation.period_start,
                        period_end: evaluation.period_end,
                        due_date: evaluation.due_date,
                        questions: evaluation.questions.map((q) => ({
                            text: q.text,
                            type: q.type,
                        })),
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
