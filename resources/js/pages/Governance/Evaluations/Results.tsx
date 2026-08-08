import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    ClipboardList,
    Star,
    Users,
} from 'lucide-react';

interface Props extends PageProps {
    evaluation: any;
}

const statusColors: Record<string, string> = {
    draft: 'bg-muted text-foreground',
    open: 'bg-status-info-bg text-status-info',
    closed: 'bg-status-success-bg text-status-success',
    completed: 'bg-status-success-bg text-status-success',
};

const typeLabels: Record<string, string> = {
    board: 'Board Evaluation',
    chair: 'Chair Evaluation',
    self: 'Self-Assessment',
    peer: 'Peer Review',
    committee: 'Committee Evaluation',
};

export default function EvaluationResults({ auth, evaluation }: Props) {
    const questions = evaluation.questions ?? [];
    const responses = evaluation.responses ?? [];
    const aggregateResults = evaluation.aggregate_results ?? [];

    const completedResponses = responses.filter((r: any) => r.is_complete);
    const completionRate =
        responses.length > 0
            ? Math.round((completedResponses.length / responses.length) * 100)
            : 0;

    const getAggregateForQuestion = (questionId: number) =>
        aggregateResults.find((a: any) => a.question_id === questionId);

    const formatDate = (d: string | null) =>
        d
            ? new Date(d).toLocaleDateString('en-NZ', {
                  day: '2-digit',
                  month: 'short',
                  year: 'numeric',
              })
            : '-';

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Evaluations', href: '/governance/evaluations' },
                { title: 'Results', href: '#' },
            ]}
        >
            <Head title={`${evaluation.title} - Results`} />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/evaluations"
                        icon={Star}
                        title={`${evaluation.title} Results`}
                        description="Evaluation outcome summary and analysis."
                        stats={[
                            { label: 'Status', value: evaluation.status },
                            {
                                label: 'Completion',
                                value: `${completionRate}%`,
                            },
                            {
                                label: 'Responses',
                                value: `${completedResponses.length}/${responses.length}`,
                            },
                            { label: 'Questions', value: questions.length },
                        ]}
                    />
                }
            >
                {/* Summary Card */}
                <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Type
                                    </p>
                                    <Badge
                                        className={cn(
                                            'mt-1',
                                            statusColors[
                                                evaluation.evaluation_type
                                            ] || 'bg-muted text-foreground',
                                        )}
                                    >
                                        {typeLabels[
                                            evaluation.evaluation_type
                                        ] ?? evaluation.evaluation_type}
                                    </Badge>
                                </div>
                                <ClipboardList className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Status
                                    </p>
                                    <Badge
                                        className={cn(
                                            'mt-1',
                                            statusColors[evaluation.status] ||
                                                'bg-muted text-foreground',
                                        )}
                                    >
                                        {evaluation.status}
                                    </Badge>
                                </div>
                                <CheckCircle2 className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Completion Rate
                                    </p>
                                    <p className="text-3xl font-bold">
                                        {completionRate}%
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {completedResponses.length} /{' '}
                                        {responses.length} responses
                                    </p>
                                </div>
                                <Users className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Per-Question Results */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="h-5 w-5" />
                            Question Results
                        </CardTitle>
                        <CardDescription>
                            {questions.length} questions
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {questions.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No questions found.
                            </p>
                        ) : (
                            <div className="space-y-6">
                                {questions.map((q: any, idx: number) => {
                                    const agg = getAggregateForQuestion(q.id);
                                    return (
                                        <div
                                            key={q.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <div className="flex items-start gap-3">
                                                <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-medium text-foreground">
                                                    {idx + 1}
                                                </span>
                                                <div className="flex-1">
                                                    <p className="font-medium text-foreground">
                                                        {q.text ?? q.question}
                                                    </p>
                                                    {agg && (
                                                        <div className="mt-3 space-y-2">
                                                            {agg.average_score !==
                                                                undefined &&
                                                                agg.average_score !==
                                                                    null && (
                                                                    <div className="flex items-center gap-3">
                                                                        <span className="text-sm text-muted-foreground">
                                                                            Average
                                                                            Score:
                                                                        </span>
                                                                        <Badge className="bg-status-info-bg px-3 text-lg text-status-info">
                                                                            {typeof agg.average_score ===
                                                                            'number'
                                                                                ? agg.average_score.toFixed(
                                                                                      1,
                                                                                  )
                                                                                : agg.average_score}
                                                                        </Badge>
                                                                    </div>
                                                                )}
                                                            {agg.distribution && (
                                                                <div className="space-y-1">
                                                                    <span className="text-sm text-muted-foreground">
                                                                        Distribution:
                                                                    </span>
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {Object.entries(
                                                                            agg.distribution,
                                                                        ).map(
                                                                            ([
                                                                                score,
                                                                                count,
                                                                            ]) => (
                                                                                <Badge
                                                                                    key={
                                                                                        score
                                                                                    }
                                                                                    variant="outline"
                                                                                >
                                                                                    {
                                                                                        score
                                                                                    }

                                                                                    :{' '}
                                                                                    {
                                                                                        count as number
                                                                                    }
                                                                                </Badge>
                                                                            ),
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                    {!agg && (
                                                        <p className="mt-2 text-sm text-muted-foreground">
                                                            No aggregate data
                                                            available.
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Respondents */}
                <Card>
                    <CardHeader>
                        <CardTitle>Respondents</CardTitle>
                        <CardDescription>
                            Board members who completed the evaluation
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {completedResponses.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No completed responses yet.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {completedResponses.map((resp: any) => (
                                    <div
                                        key={resp.id}
                                        className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted"
                                    >
                                        <div>
                                            <p className="font-medium text-foreground">
                                                {resp.board_member?.user
                                                    ?.name ??
                                                    resp.board_member?.name ??
                                                    'Unknown'}
                                            </p>
                                        </div>
                                        <span className="text-sm text-muted-foreground">
                                            Submitted:{' '}
                                            {formatDate(resp.submitted_at)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
