import { Head } from '@inertiajs/react';
import { BarChart3, CheckCircle2, ListChecks, Star, Users } from 'lucide-react';
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
import type { StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';

import { evaluationTypeLabel } from './_dialogs';

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
interface RawResponse {
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
        aggregate_results?: Record<string, RawAggregate> | RawAggregate[] | null;
    };
}

/** Stored statuses ("open") presented the same way as the register. */
const STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    open: { label: 'Open', variant: 'info' },
    active: { label: 'Open', variant: 'info' },
    draft: { label: 'Draft', variant: 'neutral' },
    closed: { label: 'Closed', variant: 'success' },
};

interface QuestionResult {
    key: string;
    text: string;
    type: string;
    answered: number;
    average: number | null;
    distribution: { label: string; count: number }[];
}

function isYes(value: unknown): boolean {
    return [true, 1, '1', 'true', 'yes', 'Yes'].includes(value as never);
}

export default function EvaluationResults({ auth, evaluation }: Props) {
    const questions = evaluation.questions ?? [];
    const responses = evaluation.responses ?? [];
    const completed = responses.filter((r) => r.submitted);
    const respondents = evaluation.respondents ?? [];
    const anonymousCount = evaluation.anonymous_respondent_count ?? 0;
    const completionRate =
        responses.length > 0
            ? Math.round((completed.length / responses.length) * 100)
            : 0;
    const status = STATUS[evaluation.status] ?? {
        label: evaluation.status,
        variant: 'neutral' as StatusVariant,
    };

    // Results are derived from the submitted responses; a stored aggregate
    // (written when an evaluation is closed through the model) is only used
    // for the average when present.
    const results = useMemo<QuestionResult[]>(() => {
        const aggregates = evaluation.aggregate_results ?? {};
        return questions.map((question, index) => {
            const id = question.id ?? index + 1;
            const answers = completed
                .map((r) =>
                    (r.answers ?? []).find(
                        (a) => String(a.question_id) === String(id),
                    ),
                )
                .filter((a): a is RawAnswer => a !== undefined && a.answer != null);
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
                    label: String(score),
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
            };
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [evaluation]);

    const ratingAverages = results
        .map((r) => r.average)
        .filter((n): n is number => n !== null);
    const overallAverage =
        ratingAverages.length > 0
            ? ratingAverages.reduce((sum, n) => sum + n, 0) / ratingAverages.length
            : null;

    const resultsHref = `/governance/evaluations/${evaluation.id}/results`;

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
                { title: 'Results', href: resultsHref },
            ]}
        >
            <Head title={`${evaluation.title} — results`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={`/governance/evaluations/${evaluation.id}`}
                        icon={BarChart3}
                        title={`${evaluation.title} — results`}
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={status.variant}>
                                {status.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${evaluationTypeLabel(evaluation.evaluation_type)} evaluation · outcome summary from submitted responses`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Completion"
                                    value={`${completed.length}/${responses.length}`}
                                    tone={
                                        responses.length > 0 && completionRate < 80
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="View respondents"
                                    href={`/governance/evaluations/${evaluation.id}`}
                                >
                                    <PageHeaderMeterBar percent={completionRate} />
                                    <PageHeaderMeterCaption>
                                        {completionRate}% of started responses
                                        submitted
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Average rating"
                                    ariaLabel="View results by question"
                                    href={resultsHref}
                                >
                                    <PageHeaderMeterBig>
                                        {overallAverage !== null
                                            ? `${overallAverage.toFixed(1)} / 5`
                                            : '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        across {ratingAverages.length} rated
                                        questions
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Questions"
                                    ariaLabel="View the evaluation"
                                    href={`/governance/evaluations/${evaluation.id}`}
                                >
                                    <PageHeaderMeterBig>
                                        {questions.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        in this evaluation
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ListChecks className="h-4 w-4 text-primary" />
                                Question results
                            </CardTitle>
                            <CardDescription>
                                {questions.length} questions ·{' '}
                                {completed.length} submitted responses
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {results.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={ListChecks}
                                    title="No questions found"
                                    description="This evaluation has no questions configured."
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
                                                            {result.answered}{' '}
                                                            answered
                                                        </EntityChip>
                                                        {result.average !== null ? (
                                                            <EntityChip icon={Star}>
                                                                {result.average.toFixed(1)}{' '}
                                                                average
                                                            </EntityChip>
                                                        ) : null}
                                                    </div>
                                                    {result.distribution.length > 0 &&
                                                    result.answered > 0 ? (
                                                        <div className="grid gap-2 sm:grid-cols-2">
                                                            {result.distribution.map(
                                                                (bucket) => (
                                                                    <ProgressValue
                                                                        key={bucket.label}
                                                                        percent={
                                                                            (bucket.count /
                                                                                result.answered) *
                                                                            100
                                                                        }
                                                                    >
                                                                        {result.type ===
                                                                        'rating'
                                                                            ? `${bucket.label} of 5`
                                                                            : bucket.label}{' '}
                                                                        · {bucket.count}
                                                                    </ProgressValue>
                                                                ),
                                                            )}
                                                        </div>
                                                    ) : result.answered === 0 ? (
                                                        <p className="text-caption">
                                                            No submitted answers yet.
                                                        </p>
                                                    ) : (
                                                        <p className="text-caption">
                                                            Free-text question —
                                                            answers are not
                                                            summarised here.
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-4 w-4 text-primary" />
                                Respondents
                            </CardTitle>
                            <CardDescription>
                                Board members who submitted a response
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {completed.length === 0 ? (
                                <EmptyState
                                    variant="inline"
                                    icon={CheckCircle2}
                                    title="No completed responses yet"
                                />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {respondents.map((respondent, index) => (
                                        <li
                                            key={`${respondent.name}-${index}`}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                            <span className="truncate">
                                                {respondent.name}
                                            </span>
                                            <span className="ml-auto shrink-0 text-caption">
                                                {formatDateLong(respondent.submitted_at)}
                                            </span>
                                        </li>
                                    ))}
                                    {anonymousCount > 0 ? (
                                        <li className="text-caption">
                                            {anonymousCount === 1
                                                ? '1 anonymous response'
                                                : `${anonymousCount} anonymous responses`}
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
