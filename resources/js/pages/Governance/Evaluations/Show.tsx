import { Head, router, useForm } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle,
    CircleDashed,
    Lock,
    Play,
    Star,
} from 'lucide-react';

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
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';

import { evaluationTypeLabel } from './_dialogs';

interface Question {
    text: string;
    type: 'rating' | 'text' | 'yes_no';
}

interface Response {
    id: number;
    board_member: { user: { name: string | null } } | null;
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

const STATUS_VARIANT: Record<string, StatusVariant> = {
    active: 'info',
    draft: 'neutral',
    closed: 'success',
};

const STATUS_LABEL: Record<string, string> = {
    active: 'Open',
    draft: 'Draft',
    closed: 'Closed',
};

export default function EvaluationShow({
    auth,
    evaluation,
    myResponse,
    responseRate,
}: Props) {
    const canManage = Boolean(auth.can?.governance?.evaluations?.manage);
    const { data, setData, post, processing } = useForm({
        answers: myResponse?.answers || ({} as Record<string, string>),
        overall_comments: myResponse?.overall_comments || '',
    });
    const today = new Date().toISOString().split('T')[0];
    const isOpen = evaluation.status === 'active';
    const pastDue = isOpen && evaluation.due_date < today;
    const responsePercent =
        responseRate.total > 0
            ? (responseRate.completed / responseRate.total) * 100
            : 0;

    const handleRespond = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/governance/evaluations/${evaluation.id}/respond`, {
            preserveScroll: true,
        });
    };

    const handleLaunch = () =>
        router.post(
            `/governance/evaluations/${evaluation.id}/launch`,
            {},
            { preserveScroll: true },
        );
    const handleClose = () =>
        router.post(
            `/governance/evaluations/${evaluation.id}/close`,
            {},
            { preserveScroll: true },
        );

    const setAnswer = (index: number, value: string) =>
        setData('answers', { ...data.answers, [String(index)]: value });

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
                            <PageHeaderStatusChip
                                variant={
                                    STATUS_VARIANT[evaluation.status] ?? 'neutral'
                                }
                            >
                                {STATUS_LABEL[evaluation.status] ??
                                    evaluation.status}
                            </PageHeaderStatusChip>
                        }
                        subline={`${evaluationTypeLabel(evaluation.evaluation_type)} evaluation · ${formatDateOnly(evaluation.period_start)} – ${formatDateOnly(evaluation.period_end)} · Due ${formatDateOnly(evaluation.due_date)}`}
                        actions={
                            <>
                                <PageHeaderGlassButton
                                    icon={BarChart3}
                                    onClick={() =>
                                        router.visit(
                                            `/governance/evaluations/${evaluation.id}/results`,
                                        )
                                    }
                                >
                                    Results
                                </PageHeaderGlassButton>
                                {canManage && isOpen ? (
                                    <PageHeaderGlassButton
                                        icon={Lock}
                                        onClick={handleClose}
                                    >
                                        Close
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canManage && evaluation.status === 'draft' ? (
                                    <PageHeaderPrimaryButton
                                        icon={Play}
                                        onClick={handleLaunch}
                                    >
                                        Launch
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Responses"
                                    value={`${responseRate.completed}/${responseRate.total}`}
                                    ariaLabel="View evaluation results"
                                    href={`/governance/evaluations/${evaluation.id}/results`}
                                >
                                    <PageHeaderMeterBar percent={responsePercent} />
                                    <PageHeaderMeterCaption>
                                        {Math.round(responsePercent)}% of active
                                        board members
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Questions"
                                    ariaLabel="View results by question"
                                    href={`/governance/evaluations/${evaluation.id}/results`}
                                >
                                    <PageHeaderMeterBig>
                                        {evaluation.questions.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        plus overall comments
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Responses due"
                                    tone={pastDue ? 'critical' : 'brand'}
                                    ariaLabel="View open evaluations"
                                    href="/governance/evaluations?status=active"
                                >
                                    <PageHeaderMeterBig>
                                        {formatDateOnly(evaluation.due_date)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {pastDue
                                            ? 'Deadline has passed'
                                            : isOpen
                                              ? 'Open for responses'
                                              : evaluation.status === 'draft'
                                                ? 'Not yet launched'
                                                : 'Evaluation closed'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        {isOpen ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Your response</CardTitle>
                                    <CardDescription>
                                        {myResponse
                                            ? 'You have already responded — submitting again replaces your answers.'
                                            : 'Answer each question below.'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleRespond}
                                        className="flex flex-col gap-6"
                                    >
                                        {evaluation.questions.map((q, i) => (
                                            <fieldset key={i}>
                                                <legend className="text-sm font-medium">
                                                    {i + 1}. {q.text}
                                                </legend>
                                                {q.type === 'rating' ? (
                                                    <div className="mt-2 flex gap-2">
                                                        {[1, 2, 3, 4, 5].map(
                                                            (n) => (
                                                                <Button
                                                                    key={n}
                                                                    dusk={`rating-${i}-${n}`}
                                                                    type="button"
                                                                    aria-label={`Rate ${n} out of 5`}
                                                                    aria-pressed={
                                                                        data.answers[
                                                                            String(i)
                                                                        ] ===
                                                                        String(n)
                                                                    }
                                                                    variant={
                                                                        data.answers[
                                                                            String(i)
                                                                        ] ===
                                                                        String(n)
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setAnswer(
                                                                            i,
                                                                            String(n),
                                                                        )
                                                                    }
                                                                >
                                                                    {n}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                ) : null}
                                                {q.type === 'text' ? (
                                                    <Textarea
                                                        dusk={`answer-${i}`}
                                                        aria-label={q.text}
                                                        className="mt-2"
                                                        value={
                                                            data.answers[
                                                                String(i)
                                                            ] || ''
                                                        }
                                                        onChange={(e) =>
                                                            setAnswer(
                                                                i,
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                ) : null}
                                                {q.type === 'yes_no' ? (
                                                    <div className="mt-2 flex gap-2">
                                                        {['Yes', 'No'].map(
                                                            (v) => (
                                                                <Button
                                                                    key={v}
                                                                    dusk={`answer-${i}-${v.toLowerCase()}`}
                                                                    type="button"
                                                                    aria-pressed={
                                                                        data.answers[
                                                                            String(i)
                                                                        ] === v
                                                                    }
                                                                    variant={
                                                                        data.answers[
                                                                            String(i)
                                                                        ] === v
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setAnswer(
                                                                            i,
                                                                            v,
                                                                        )
                                                                    }
                                                                >
                                                                    {v}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                ) : null}
                                            </fieldset>
                                        ))}
                                        <div>
                                            <Label htmlFor="overall-comments">
                                                Overall comments
                                            </Label>
                                            <Textarea
                                                id="overall-comments"
                                                dusk="overall-comments"
                                                className="mt-2"
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
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                dusk="submit-evaluation-response"
                                            >
                                                {myResponse
                                                    ? 'Update response'
                                                    : 'Submit response'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        ) : (
                            <EmptyState
                                icon={
                                    evaluation.status === 'draft'
                                        ? CircleDashed
                                        : Lock
                                }
                                title={
                                    evaluation.status === 'draft'
                                        ? 'This evaluation has not been launched'
                                        : 'This evaluation is closed'
                                }
                                description={
                                    evaluation.status === 'draft'
                                        ? 'Members can respond once it is launched.'
                                        : 'Responses are no longer accepted. The results remain available.'
                                }
                                action={
                                    evaluation.status === 'closed' ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    `/governance/evaluations/${evaluation.id}/results`,
                                                )
                                            }
                                        >
                                            <BarChart3 className="h-3.5 w-3.5" />
                                            View results
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Response rate</CardTitle>
                            <CardDescription>
                                {responseRate.completed} of {responseRate.total}{' '}
                                active board members
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ProgressValue percent={responsePercent}>
                                {Math.round(responsePercent)}% responded
                            </ProgressValue>
                            {evaluation.responses.length > 0 ? (
                                <ul className="flex flex-col gap-2">
                                    {evaluation.responses.map((r) => (
                                        <li
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
                                                {r.board_member?.user?.name ??
                                                    'Board member'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <EmptyState
                                    variant="inline"
                                    icon={CircleDashed}
                                    title="No responses yet"
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
