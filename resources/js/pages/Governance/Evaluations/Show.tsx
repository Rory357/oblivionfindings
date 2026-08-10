import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle, Lock, Play, Star } from 'lucide-react';

interface Question {
    text: string;
    type: 'rating' | 'text' | 'yes_no';
}

interface Response {
    id: number;
    board_member: { user: { name: string } };
    is_complete: boolean;
    submitted_at: string | null;
}

interface Evaluation {
    id: number;
    title: string;
    evaluation_type: string;
    status: string;
    period_start: string;
    period_end: string;
    due_date: string;
    questions: Question[];
    responses: Response[];
}

interface Props extends PageProps {
    evaluation: Evaluation;
    boardMembers: Array<{ id: number; user: { name: string } }>;
    myResponse: {
        answers: Record<string, string>;
        overall_comments: string;
    } | null;
    responseRate: { total: number; completed: number };
}

export default function EvaluationShow({
    auth,
    evaluation,
    boardMembers,
    myResponse,
    responseRate,
}: Props) {
    const { data, setData, post, processing } = useForm({
        answers: myResponse?.answers || ({} as Record<string, string>),
        overall_comments: myResponse?.overall_comments || '',
    });

    const handleRespond = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/governance/evaluations/${evaluation.id}/respond`);
    };

    const handleLaunch = () =>
        router.post(`/governance/evaluations/${evaluation.id}/launch`);
    const handleClose = () =>
        router.post(`/governance/evaluations/${evaluation.id}/close`);

    const getStatusColor = (status: string) => governanceStatusColor(status);

    return (
        <AppLayout>
            <Head title={evaluation.title} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/evaluations"
                        icon={Star}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="evaluation-title"
                            >
                                {evaluation.title}
                                <Badge
                                    className={cn(
                                        'text-xs',
                                        getStatusColor(evaluation.status),
                                    )}
                                >
                                    {evaluation.status}
                                </Badge>
                            </span>
                        }
                        description={`Period: ${new Date(evaluation.period_start).toLocaleDateString('en-NZ')} - ${new Date(evaluation.period_end).toLocaleDateString('en-NZ')}`}
                        stats={[
                            { label: 'Status', value: evaluation.status },
                            {
                                label: 'Responses',
                                value: `${responseRate.completed}/${responseRate.total}`,
                            },
                            {
                                label: 'Questions',
                                value: evaluation.questions.length,
                            },
                            {
                                label: 'Due',
                                value: new Date(
                                    evaluation.due_date,
                                ).toLocaleDateString('en-NZ'),
                            },
                        ]}
                        actions={
                            <>
                                {evaluation.status === 'draft' && (
                                    <Button onClick={handleLaunch}>
                                        <Play className="mr-2 h-4 w-4" /> Launch
                                    </Button>
                                )}
                                {evaluation.status === 'active' && (
                                    <Button
                                        variant="outline"
                                        onClick={handleClose}
                                    >
                                        <Lock className="mr-2 h-4 w-4" /> Close
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        {evaluation.status === 'active' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Your Response</CardTitle>
                                    <CardDescription>
                                        Answer each question below
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleRespond}
                                        className="space-y-6"
                                    >
                                        {evaluation.questions.map((q, i) => (
                                            <div key={i}>
                                                <Label className="text-base font-medium">
                                                    {i + 1}. {q.text}
                                                </Label>
                                                {q.type === 'rating' && (
                                                    <div className="mt-2 flex gap-2">
                                                        {[1, 2, 3, 4, 5].map(
                                                            (n) => (
                                                                <Button
                                                                    key={n}
                                                                    dusk={`rating-${i}-${n}`}
                                                                    type="button"
                                                                    variant={
                                                                        data
                                                                            .answers[
                                                                            String(
                                                                                i,
                                                                            )
                                                                        ] ===
                                                                        String(
                                                                            n,
                                                                        )
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setData(
                                                                            'answers',
                                                                            {
                                                                                ...data.answers,
                                                                                [String(
                                                                                    i,
                                                                                )]:
                                                                                    String(
                                                                                        n,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {n}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                                {q.type === 'text' && (
                                                    <Textarea
                                                        dusk={`answer-${i}`}
                                                        className="mt-2"
                                                        value={
                                                            data.answers[
                                                                String(i)
                                                            ] || ''
                                                        }
                                                        onChange={(e) =>
                                                            setData('answers', {
                                                                ...data.answers,
                                                                [String(i)]:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                )}
                                                {q.type === 'yes_no' && (
                                                    <div className="mt-2 flex gap-2">
                                                        {['Yes', 'No'].map(
                                                            (v) => (
                                                                <Button
                                                                    key={v}
                                                                    dusk={`answer-${i}-${v.toLowerCase()}`}
                                                                    type="button"
                                                                    variant={
                                                                        data
                                                                            .answers[
                                                                            String(
                                                                                i,
                                                                            )
                                                                        ] === v
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setData(
                                                                            'answers',
                                                                            {
                                                                                ...data.answers,
                                                                                [String(
                                                                                    i,
                                                                                )]:
                                                                                    v,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {v}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                        <div>
                                            <Label>Overall Comments</Label>
                                            <Textarea
                                                dusk="overall-comments"
                                                value={data.overall_comments}
                                                onChange={(e) =>
                                                    setData(
                                                        'overall_comments',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={3}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            dusk="submit-evaluation-response"
                                        >
                                            Submit Response
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Response Rate</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-3 text-center">
                                    <span className="text-3xl font-bold">
                                        {responseRate.completed}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        / {responseRate.total}
                                    </span>
                                </div>
                                <div className="h-2 w-full rounded-full bg-muted">
                                    <div
                                        className="h-2 rounded-full bg-status-info"
                                        style={{
                                            width: `${responseRate.total > 0 ? (responseRate.completed / responseRate.total) * 100 : 0}%`,
                                        }}
                                    />
                                </div>
                                <div className="mt-4 space-y-2">
                                    {evaluation.responses.map((r) => (
                                        <div
                                            key={r.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <CheckCircle
                                                className={cn(
                                                    'h-4 w-4',
                                                    r.is_complete
                                                        ? 'text-status-success'
                                                        : 'text-muted-foreground',
                                                )}
                                            />
                                            <span>
                                                {r.board_member?.user?.name}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
