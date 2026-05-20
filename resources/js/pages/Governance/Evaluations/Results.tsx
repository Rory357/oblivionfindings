import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { BarChart3, CheckCircle2, Users, ClipboardList } from 'lucide-react';

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
    const completionRate = responses.length > 0
        ? Math.round((completedResponses.length / responses.length) * 100)
        : 0;

    const getAggregateForQuestion = (questionId: number) =>
        aggregateResults.find((a: any) => a.question_id === questionId);

    const formatDate = (d: string | null) =>
        d ? new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

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
                        variant="compact"
                        backHref="/governance/evaluations"
                        title={`${evaluation.title} Results`}
                        description="Evaluation outcome summary and analysis."
                    />
                }
            >
                {/* Summary Card */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Type</p>
                                    <Badge className={cn('mt-1', statusColors[evaluation.evaluation_type] || 'bg-muted text-foreground')}>
                                        {typeLabels[evaluation.evaluation_type] ?? evaluation.evaluation_type}
                                    </Badge>
                                </div>
                                <ClipboardList className="w-8 h-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Status</p>
                                    <Badge className={cn('mt-1', statusColors[evaluation.status] || 'bg-muted text-foreground')}>
                                        {evaluation.status}
                                    </Badge>
                                </div>
                                <CheckCircle2 className="w-8 h-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Completion Rate</p>
                                    <p className="text-3xl font-bold">{completionRate}%</p>
                                    <p className="text-xs text-muted-foreground">
                                        {completedResponses.length} / {responses.length} responses
                                    </p>
                                </div>
                                <Users className="w-8 h-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Per-Question Results */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="w-5 h-5" />
                            Question Results
                        </CardTitle>
                        <CardDescription>{questions.length} questions</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {questions.length === 0 ? (
                            <p className="text-center text-muted-foreground py-8">No questions found.</p>
                        ) : (
                            <div className="space-y-6">
                                {questions.map((q: any, idx: number) => {
                                    const agg = getAggregateForQuestion(q.id);
                                    return (
                                        <div key={q.id} className="p-4 rounded-lg border">
                                            <div className="flex items-start gap-3">
                                                <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-muted text-foreground text-sm font-medium shrink-0">
                                                    {idx + 1}
                                                </span>
                                                <div className="flex-1">
                                                    <p className="font-medium text-foreground">{q.text ?? q.question}</p>
                                                    {agg && (
                                                        <div className="mt-3 space-y-2">
                                                            {agg.average_score !== undefined && agg.average_score !== null && (
                                                                <div className="flex items-center gap-3">
                                                                    <span className="text-sm text-muted-foreground">Average Score:</span>
                                                                    <Badge className="bg-status-info-bg text-status-info text-lg px-3">
                                                                        {typeof agg.average_score === 'number'
                                                                            ? agg.average_score.toFixed(1)
                                                                            : agg.average_score}
                                                                    </Badge>
                                                                </div>
                                                            )}
                                                            {agg.distribution && (
                                                                <div className="space-y-1">
                                                                    <span className="text-sm text-muted-foreground">Distribution:</span>
                                                                    <div className="flex gap-2 flex-wrap">
                                                                        {Object.entries(agg.distribution).map(([score, count]) => (
                                                                            <Badge key={score} variant="outline">
                                                                                {score}: {count as number}
                                                                            </Badge>
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                    {!agg && (
                                                        <p className="mt-2 text-sm text-muted-foreground">No aggregate data available.</p>
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
                        <CardDescription>Board members who completed the evaluation</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {completedResponses.length === 0 ? (
                            <p className="text-center text-muted-foreground py-8">No completed responses yet.</p>
                        ) : (
                            <div className="space-y-2">
                                {completedResponses.map((resp: any) => (
                                    <div key={resp.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted">
                                        <div>
                                            <p className="font-medium text-foreground">
                                                {resp.board_member?.user?.name ?? resp.board_member?.name ?? 'Unknown'}
                                            </p>
                                        </div>
                                        <span className="text-sm text-muted-foreground">
                                            Submitted: {formatDate(resp.submitted_at)}
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
