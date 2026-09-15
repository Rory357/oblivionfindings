import { Head } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    EyeOff,
    ListChecks,
    MessageSquareText,
    Star,
    Users,
} from 'lucide-react';
import { useMemo } from 'react';

import { EntityChip, ProgressValue } from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import { RATING_ANCHORS, evaluationSubject } from './_dialogs';

interface RawQuestion {
    id?: number | string | null;
    question?: string;
    text?: string;
    type?: string;
}

interface RawAnswer {
    question_id?: number | string;
    answer?: unknown;
    rating?: number | null;
}

/** Answers arrive detached from identity (no member, id or timestamp). */
export interface RawResponse {
    answers?: RawAnswer[] | null;
    submitted: boolean;
}

interface Respondent {
    name: string;
    submitted_at: string | null;
}

interface RawAggregate {
    avg_rating?: number | null;
    average_score?: number | null;
    response_count?: number;
}

interface Props extends PageProps {
    evaluation: {
        id: number;
        title: string;
        evaluation_type: string;
        status: string;
        questions?: RawQuestion[] | null;
        responses?: RawResponse[] | null;
        respondents?: Respondent[] | null;
        anonymous_respondent_count?: number;
        active_member_count?: number;
        committee_name?: string | null;
        aggregate_results?: Record<string, RawAggregate> | RawAggregate[] | null;
    };
}

interface QuestionResult {
    key: string;
    text: string;
    type: string;
    answered: number;
    average: number | null;
    distribution: { label: string; count: number }[];
    comments: string[];
}

function isYes(value: unknown): boolean {
    return [true, 1, '1', 'true', 'yes', 'Yes'].includes(value as never);
}

/**
 * Written answers for one question (or `overall_comments`), without names.
 * Sorted alphabetically per question, so the order can't be used to line a
 * comment up with the same person's other answers.
 */
export function writtenComments(
    responses: RawResponse[],
    questionId: string | number,
): string[] {
    return responses
        .filter((response) => response.submitted)
        .map((response) =>
            (response.answers ?? []).find(
                (answer) => String(answer.question_id) === String(questionId),
            ),
        )
        .map((answer) =>
            typeof answer?.answer === 'string' ? answer.answer.trim() : '',
        )
        .filter((text) => text !== '')
        .sort((a, b) => a.localeCompare(b, 'en-NZ'));
}

function CommentList({ comments }: { comments: string[] }) {
    return (
        <ul className="flex flex-col gap-2">
            {comments.map((comment, index) => (
                <li
                    key={index}
                    className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm whitespace-pre-line"
                >
                    {comment}
                </li>
            ))}
        </ul>
    );
}

export default function EvaluationResults({ auth, evaluation }: Props) {
    const questions = evaluation.questions ?? [];
    const responses = evaluation.responses ?? [];
    const completed = responses.filter((r) => r.submitted);
    const respondents = evaluation.respondents ?? [];
    const anonymousCount = evaluation.anonymous_respondent_count ?? 0;
    // Completion is measured against everyone who could answer.
    const activeMembers = evaluation.active_member_count ?? 0;
    const completionRate =
        activeMembers > 0
            ? Math.min(100, Math.round((completed.length / activeMembers) * 100))
            : 0;
    const chip = governanceStatus('evaluation_status', evaluation.status);

    // Results are derived from the submitted responses; a stored aggregate
    // (written when an evaluation is closed through the model) is only used
    // for the average when present.
    const results = useMemo<QuestionResult[]>(() => {
        const aggregates = evaluation.aggregate_results ?? {};
        const submitted = (evaluation.responses ?? []).filter((r) => r.submitted);
        return (evaluation.questions ?? []).map((question, index) => {
            const id = question.id ?? index + 1;
            const answers = submitted
                .map((r) =>
                    (r.answers ?? []).find(
                        (a) => String(a.question_id) === String(id),
                    ),
                )
                .filter((a): a is RawAnswer => a !== undefined && a.answer != null && a.answer !== '');
            const type = question.type ?? 'text';
            const stored = (aggregates as Record<string, RawAggregate>)[String(id)];
            let average: number | null = null;
            let distribution: QuestionResult['distribution'] = [];

            if (type === 'rating') {
                const ratings = answers
                    .map((a) => Number(a.rating ?? a.answer))
                    .filter((n) => Number.isFinite(n) && n >= 1 && n <= 5);
                average =
                    stored?.avg_rating ??
                    stored?.average_score ??
                    (ratings.length > 0
                        ? ratings.reduce((sum, n) => sum + n, 0) / ratings.length
                        : null);
                distribution = [5, 4, 3, 2, 1].map((score) => ({
                    label: `${score} – ${RATING_ANCHORS[score]}`,
                    count: ratings.filter((n) => Math.round(n) === score).length,
                }));
            } else if (type === 'yes_no') {
                const yes = answers.filter((a) => isYes(a.answer)).length;
                distribution = [
                    { label: 'Yes', count: yes },
                    { label: 'No', count: answers.length - yes },
                ];
            }

            return {
                key: String(id),
                text: question.question ?? question.text ?? `Question ${index + 1}`,
                type,
                answered: answers.length,
                average,
                distribution,
                comments: type === 'text' ? writtenComments(submitted, id) : [],
            };
        });
    }, [evaluation]);

    const overallComments = useMemo(
        () => writtenComments(evaluation.responses ?? [], 'overall_comments'),
        [evaluation],
    );

    const ratingAverages = results
        .map((r) => r.average)
        .filter((n): n is number => n !== null);
    const overallAverage =
        ratingAverages.length > 0
            ? ratingAverages.reduce((sum, n) => sum + n, 0) / ratingAverages.length
            : null;

    const evaluationHref = `/governance/evaluations/${evaluation.id}`;
    const resultsHref = `${evaluationHref}/results`;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Evaluations', href: '/governance/evaluations' },
                { title: evaluation.title, href: evaluationHref },
                { title: 'Results', href: resultsHref },
            ]}
        >
            <Head title={`${evaluation.title} — results`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={evaluationHref}
                        icon={BarChart3}
                        title={`${evaluation.title} — results`}
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${evaluationSubject(evaluation.evaluation_type, evaluation.committee_name)} · Results from submitted responses · Answers are shown without names`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Responded"
                                    value={`${completed.length}/${activeMembers}`}
                                    tone={
                                        activeMembers > 0 && completionRate < 80
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="See who has responded"
                                    href={evaluationHref}
                                >
                                    <PageHeaderMeterBar percent={completionRate} />
                                    <PageHeaderMeterCaption>
                                        {completionRate}% of current board members
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Average rating"
                                    ariaLabel="Go to the results by question"
                                    onClick={() =>
                                        document
                                            .getElementById('question-results')
                                            ?.scrollIntoView({ behavior: 'smooth' })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {overallAverage !== null
                                            ? `${overallAverage.toFixed(1)} / 5`
                                            : 'Not available'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {ratingAverages.length === 1
                                            ? 'across 1 rating question'
                                            : `across ${ratingAverages.length} rating questions`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Comments"
                                    ariaLabel="Go to the overall comments"
                                    onClick={() =>
                                        document
                                            .getElementById('overall-comments')
                                            ?.scrollIntoView({ behavior: 'smooth' })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {overallComments.length +
                                            results.reduce((sum, r) => sum + r.comments.length, 0)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        written answers and comments
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        <Card id="question-results">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ListChecks className="h-4 w-4 text-primary" />
                                    Results by question
                                </CardTitle>
                                <CardDescription>
                                    {questions.length === 1 ? '1 question' : `${questions.length} questions`}{' '}
                                    ·{' '}
                                    {completed.length === 1
                                        ? '1 response'
                                        : `${completed.length} responses`}
                                    . Comments are shown without names.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {results.length === 0 ? (
                                    <EmptyState
                                        variant="compact"
                                        icon={ListChecks}
                                        title="No questions"
                                        description="This evaluation doesn't have any questions."
                                    />
                                ) : (
                                    <ol className="flex flex-col gap-4">
                                        {results.map((result, idx) => (
                                            <li
                                                key={result.key}
                                                className="rounded-lg border border-border p-4"
                                            >
                                                <div className="flex items-start gap-3">
                                                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                                                        {idx + 1}
                                                    </span>
                                                    <div className="flex min-w-0 flex-1 flex-col gap-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="text-sm font-medium">
                                                                {result.text}
                                                            </p>
                                                            <EntityChip>
                                                                {result.answered} answered
                                                            </EntityChip>
                                                            {result.average !== null ? (
                                                                <EntityChip icon={Star}>
                                                                    {result.average.toFixed(1)} average
                                                                </EntityChip>
                                                            ) : null}
                                                        </div>
                                                        {result.answered === 0 ? (
                                                            <p className="text-caption">
                                                                No answers yet.
                                                            </p>
                                                        ) : result.type === 'text' ? (
                                                            <div className="flex flex-col gap-2">
                                                                <p className="text-caption">
                                                                    Comments ({result.comments.length})
                                                                </p>
                                                                <CommentList comments={result.comments} />
                                                            </div>
                                                        ) : (
                                                            <div className="grid gap-2 sm:grid-cols-2">
                                                                {result.distribution.map((bucket) => (
                                                                    <ProgressValue
                                                                        key={bucket.label}
                                                                        percent={(bucket.count / result.answered) * 100}
                                                                    >
                                                                        {bucket.label} · {bucket.count}
                                                                    </ProgressValue>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </CardContent>
                        </Card>

                        <Card id="overall-comments">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <MessageSquareText className="h-4 w-4 text-primary" />
                                    Overall comments
                                </CardTitle>
                                <CardDescription className="flex items-center gap-1.5">
                                    <EyeOff className="h-3.5 w-3.5" />
                                    Comments are shown without names.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {overallComments.length === 0 ? (
                                    <EmptyState
                                        variant="inline"
                                        icon={MessageSquareText}
                                        title="No overall comments"
                                    />
                                ) : (
                                    <CommentList comments={overallComments} />
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-4 w-4 text-primary" />
                                Who has responded
                            </CardTitle>
                            <CardDescription>
                                {completed.length} of {activeMembers} current board{' '}
                                {activeMembers === 1 ? 'member' : 'members'}. Names
                                aren't linked to answers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {completed.length === 0 ? (
                                <EmptyState
                                    variant="inline"
                                    icon={CheckCircle2}
                                    title="No responses yet"
                                />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {respondents.map((respondent, index) => (
                                        <li
                                            key={`${respondent.name}-${index}`}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                                            <span className="truncate">
                                                {respondent.name}
                                            </span>
                                        </li>
                                    ))}
                                    {anonymousCount > 0 ? (
                                        <li className="text-caption">
                                            {anonymousCount === 1
                                                ? '1 person responded without their name shown'
                                                : `${anonymousCount} people responded without their names shown`}
                                        </li>
                                    ) : null}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
