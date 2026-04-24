import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Lock, Send, User } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

type SurveyQuestion = {
    id: number;
    question_type: 'enps' | 'scale' | 'text' | 'choice' | 'boolean';
    question_text: string;
    options: string[];
    is_required: boolean;
    sort_order: number;
};

type SurveyActionPlan = {
    id: number;
    title: string;
    status: string;
    priority: string;
    progress_percent: number;
    due_date: string | null;
    owner: { id: number; name: string } | null;
};

type SurveyPayload = {
    id: number;
    title: string;
    description: string | null;
    survey_type: 'pulse' | 'enps' | 'engagement';
    status: 'draft' | 'published' | 'closed';
    is_anonymous: boolean;
    starts_at: string | null;
    ends_at: string | null;
    questions: SurveyQuestion[];
    action_plans: SurveyActionPlan[];
};

type SurveyResponse = {
    id: number;
    respondent: string;
    answers: Record<string, string | number | boolean>;
    overall_score: number | null;
    submitted_at: string | null;
};

type SummaryPayload = {
    response_count: number;
    average_overall_score: number | null;
    enps: number | null;
    question_stats: Array<{
        id: number;
        question_text: string;
        question_type: string;
        responses: number;
        average: number | null;
    }>;
} | null;

type Props = {
    survey: SurveyPayload;
    existingResponse: {
        id: number;
        answers: Record<string, string | number | boolean>;
        submitted_at: string | null;
    } | null;
    summary: SummaryPayload;
    responses: SurveyResponse[];
    actionPlanOwners: Array<{ id: number; name: string }>;
    can: {
        manage: boolean;
        respond: boolean;
    };
};

export default function WellbeingSurveyShow({
    survey,
    existingResponse,
    summary,
    responses = [],
    actionPlanOwners,
    can,
}: Props) {
    const [expandedResponse, setExpandedResponse] = useState<number | null>(
        null,
    );
    const [confirmAction, setConfirmAction] = useState<
        'publish' | 'close' | null
    >(null);

    function handleStatusChange(action: 'publish' | 'close') {
        const url =
            action === 'publish'
                ? `/hr/wellbeing/surveys/${survey.id}/publish`
                : `/hr/wellbeing/surveys/${survey.id}/close`;
        router.post(url, {}, { preserveScroll: true });
        setConfirmAction(null);
    }

    const responseForm = useForm({
        answers: (existingResponse?.answers ?? {}) as Record<
            string,
            string | number | boolean
        >,
    });

    const actionPlanForm = useForm({
        owner_user_id: actionPlanOwners[0]?.id
            ? String(actionPlanOwners[0].id)
            : '',
        title: '',
        description: '',
        priority: 'medium' as 'low' | 'medium' | 'high',
        due_date: '',
        progress_percent: 0,
    });

    const sortedQuestions = useMemo(
        () => [...survey.questions].sort((a, b) => a.sort_order - b.sort_order),
        [survey.questions],
    );

    function setAnswer(questionId: number, value: string | number | boolean) {
        responseForm.setData('answers', {
            ...responseForm.data.answers,
            [String(questionId)]: value,
        });
    }

    function submitResponse(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        responseForm.post(`/hr/wellbeing/surveys/${survey.id}/responses`, {
            preserveScroll: true,
        });
    }

    function submitActionPlan(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        actionPlanForm.post(`/hr/wellbeing/surveys/${survey.id}/action-plans`, {
            preserveScroll: true,
            onSuccess: () => actionPlanForm.reset(),
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Wellbeing', href: '/hr/wellbeing' },
                {
                    title: survey.title,
                    href: `/hr/wellbeing/surveys/${survey.id}`,
                },
            ]}
        >
            <Head title={`Survey · ${survey.title}`} />
            <PageShell>
                <PageHeader
                    title={survey.title}
                    description={survey.description ?? 'Engagement survey'}
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge
                                variant={
                                    survey.status === 'published'
                                        ? 'default'
                                        : survey.status === 'closed'
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {survey.status}
                            </Badge>
                            <Badge variant="outline">
                                {survey.survey_type.toUpperCase()}
                            </Badge>
                            {can.manage && survey.status === 'draft' && (
                                <Button
                                    size="sm"
                                    onClick={() => setConfirmAction('publish')}
                                >
                                    <Send className="mr-1.5 h-3.5 w-3.5" />
                                    Publish
                                </Button>
                            )}
                            {can.manage && survey.status === 'published' && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => setConfirmAction('close')}
                                >
                                    <Lock className="mr-1.5 h-3.5 w-3.5" />
                                    Close
                                </Button>
                            )}
                        </div>
                    }
                />

                <AlertDialog
                    open={confirmAction !== null}
                    onOpenChange={(open) => !open && setConfirmAction(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {confirmAction === 'publish'
                                    ? 'Publish survey?'
                                    : 'Close survey?'}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {confirmAction === 'publish'
                                    ? 'This will make the survey available for staff to respond. You can close it later to stop accepting responses.'
                                    : 'This will stop accepting new responses. Existing responses will be preserved.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() =>
                                    confirmAction &&
                                    handleStatusChange(confirmAction)
                                }
                            >
                                {confirmAction === 'publish'
                                    ? 'Publish'
                                    : 'Close'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {summary && (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Responses
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">
                                {summary.response_count}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Average Score
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">
                                {summary.average_overall_score ?? '-'}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    eNPS
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">
                                {summary.enps ?? '-'}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {can.respond && !existingResponse ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Submit Survey Response</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={submitResponse}
                                className="space-y-4"
                            >
                                {sortedQuestions.map((question) => (
                                    <div
                                        key={question.id}
                                        className="space-y-2 rounded-lg border p-3"
                                    >
                                        <Label className="text-sm">
                                            {question.question_text}
                                            {question.is_required && (
                                                <span className="ml-1 text-destructive">
                                                    *
                                                </span>
                                            )}
                                        </Label>

                                        {question.question_type === 'text' && (
                                            <Textarea
                                                rows={3}
                                                value={String(
                                                    responseForm.data.answers[
                                                        String(question.id)
                                                    ] ?? '',
                                                )}
                                                onChange={(event) =>
                                                    setAnswer(
                                                        question.id,
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        )}

                                        {(question.question_type === 'enps' ||
                                            question.question_type ===
                                                'scale') && (
                                            <Input
                                                type="number"
                                                min={
                                                    question.question_type ===
                                                    'enps'
                                                        ? 0
                                                        : 1
                                                }
                                                max={
                                                    question.question_type ===
                                                    'enps'
                                                        ? 10
                                                        : 5
                                                }
                                                value={String(
                                                    responseForm.data.answers[
                                                        String(question.id)
                                                    ] ?? '',
                                                )}
                                                onChange={(event) =>
                                                    setAnswer(
                                                        question.id,
                                                        event.target.value ===
                                                            ''
                                                            ? ''
                                                            : Number(
                                                                  event.target
                                                                      .value,
                                                              ),
                                                    )
                                                }
                                            />
                                        )}

                                        {question.question_type ===
                                            'choice' && (
                                            <Select
                                                value={String(
                                                    responseForm.data.answers[
                                                        String(question.id)
                                                    ] ?? '',
                                                )}
                                                onValueChange={(value) =>
                                                    setAnswer(
                                                        question.id,
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select an option" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {question.options.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={option}
                                                                value={option}
                                                            >
                                                                {option}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        )}

                                        {question.question_type ===
                                            'boolean' && (
                                            <div className="flex items-center gap-6">
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`q-${question.id}-yes`}
                                                        checked={
                                                            responseForm.data
                                                                .answers[
                                                                String(
                                                                    question.id,
                                                                )
                                                            ] === true
                                                        }
                                                        onCheckedChange={() =>
                                                            setAnswer(
                                                                question.id,
                                                                true,
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`q-${question.id}-yes`}
                                                        className="font-normal"
                                                    >
                                                        Yes
                                                    </Label>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`q-${question.id}-no`}
                                                        checked={
                                                            responseForm.data
                                                                .answers[
                                                                String(
                                                                    question.id,
                                                                )
                                                            ] === false
                                                        }
                                                        onCheckedChange={() =>
                                                            setAnswer(
                                                                question.id,
                                                                false,
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`q-${question.id}-no`}
                                                        className="font-normal"
                                                    >
                                                        No
                                                    </Label>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}

                                <Button
                                    type="submit"
                                    disabled={responseForm.processing}
                                >
                                    {responseForm.processing
                                        ? 'Submitting...'
                                        : 'Submit Response'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Response Status</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            {existingResponse
                                ? `Response submitted${existingResponse.submitted_at ? ` at ${existingResponse.submitted_at}` : ''}.`
                                : 'This survey is not currently open for responses.'}
                        </CardContent>
                    </Card>
                )}

                {can.manage && summary && summary.question_stats.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Question Breakdown</CardTitle>
                            <CardDescription>
                                Per-question statistics across all responses
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Question</TableHead>
                                        <TableHead className="w-[100px]">
                                            Type
                                        </TableHead>
                                        <TableHead className="w-[100px] text-right">
                                            Responses
                                        </TableHead>
                                        <TableHead className="w-[100px] text-right">
                                            Average
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {summary.question_stats.map((stat) => (
                                        <TableRow key={stat.id}>
                                            <TableCell className="text-sm">
                                                {stat.question_text}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className="text-xs"
                                                >
                                                    {stat.question_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {stat.responses}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {stat.average !== null
                                                    ? stat.average
                                                    : '-'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {can.manage && responses.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Individual Responses</CardTitle>
                            <CardDescription>
                                {responses.length} response
                                {responses.length !== 1 ? 's' : ''}
                                {survey.is_anonymous &&
                                    ' (anonymous — names hidden)'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {responses.map((resp) => (
                                <Collapsible
                                    key={resp.id}
                                    open={expandedResponse === resp.id}
                                    onOpenChange={(open) =>
                                        setExpandedResponse(
                                            open ? resp.id : null,
                                        )
                                    }
                                >
                                    <CollapsibleTrigger asChild>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between rounded-lg border p-3 text-left transition-colors hover:bg-muted"
                                        >
                                            <div className="flex items-center gap-3">
                                                <User className="h-4 w-4 text-muted-foreground" />
                                                <span className="text-sm font-medium">
                                                    {resp.respondent}
                                                </span>
                                                {resp.overall_score !==
                                                    null && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs"
                                                    >
                                                        Score:{' '}
                                                        {resp.overall_score}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {resp.submitted_at && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {new Date(
                                                            resp.submitted_at,
                                                        ).toLocaleString(
                                                            'en-NZ',
                                                            {
                                                                day: '2-digit',
                                                                month: 'short',
                                                                year: 'numeric',
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                                {expandedResponse ===
                                                resp.id ? (
                                                    <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                ) : (
                                                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                )}
                                            </div>
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div className="mt-1 space-y-2 rounded-lg border bg-muted/30 p-4">
                                            {sortedQuestions.map((question) => {
                                                const answer =
                                                    resp.answers[
                                                        String(question.id)
                                                    ];
                                                return (
                                                    <div
                                                        key={question.id}
                                                        className="grid grid-cols-[1fr,auto] gap-4 border-b pb-2 last:border-0 last:pb-0"
                                                    >
                                                        <p className="text-sm text-muted-foreground">
                                                            {
                                                                question.question_text
                                                            }
                                                        </p>
                                                        <p className="min-w-[80px] text-right text-sm font-medium">
                                                            {answer !== null &&
                                                            answer !==
                                                                undefined &&
                                                            answer !== '' ? (
                                                                String(answer)
                                                            ) : (
                                                                <span className="text-muted-foreground italic">
                                                                    No answer
                                                                </span>
                                                            )}
                                                        </p>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {can.manage && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Create Action Plan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submitActionPlan}
                                    className="space-y-3"
                                >
                                    <div className="space-y-2">
                                        <Label>Owner</Label>
                                        <Select
                                            value={
                                                actionPlanForm.data
                                                    .owner_user_id
                                            }
                                            onValueChange={(value) =>
                                                actionPlanForm.setData(
                                                    'owner_user_id',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select owner" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {actionPlanOwners.map(
                                                    (owner) => (
                                                        <SelectItem
                                                            key={owner.id}
                                                            value={String(
                                                                owner.id,
                                                            )}
                                                        >
                                                            {owner.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {actionPlanOwners.length === 0 && (
                                            <p className="text-xs text-destructive">
                                                No active employees found for
                                                ownership.
                                            </p>
                                        )}
                                        {actionPlanForm.errors
                                            .owner_user_id && (
                                            <p className="text-xs text-destructive">
                                                {
                                                    actionPlanForm.errors
                                                        .owner_user_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Title</Label>
                                        <Input
                                            value={actionPlanForm.data.title}
                                            onChange={(event) =>
                                                actionPlanForm.setData(
                                                    'title',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Description</Label>
                                        <Textarea
                                            rows={3}
                                            value={
                                                actionPlanForm.data.description
                                            }
                                            onChange={(event) =>
                                                actionPlanForm.setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Priority</Label>
                                            <Select
                                                value={
                                                    actionPlanForm.data.priority
                                                }
                                                onValueChange={(
                                                    value:
                                                        | 'low'
                                                        | 'medium'
                                                        | 'high',
                                                ) =>
                                                    actionPlanForm.setData(
                                                        'priority',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="low">
                                                        Low
                                                    </SelectItem>
                                                    <SelectItem value="medium">
                                                        Medium
                                                    </SelectItem>
                                                    <SelectItem value="high">
                                                        High
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Due Date</Label>
                                            <Input
                                                type="date"
                                                value={
                                                    actionPlanForm.data.due_date
                                                }
                                                onChange={(event) =>
                                                    actionPlanForm.setData(
                                                        'due_date',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            actionPlanForm.processing ||
                                            actionPlanOwners.length === 0
                                        }
                                    >
                                        {actionPlanForm.processing
                                            ? 'Saving...'
                                            : 'Create Action Plan'}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Action Plans</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {survey.action_plans.map((plan) => (
                                    <div
                                        key={plan.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-medium">
                                                {plan.title}
                                            </p>
                                            <Badge
                                                variant={
                                                    plan.status === 'completed'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {plan.status}
                                            </Badge>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {plan.owner?.name ?? 'Unassigned'} ·{' '}
                                            {plan.priority} ·{' '}
                                            {plan.progress_percent}%
                                        </p>
                                    </div>
                                ))}
                                {survey.action_plans.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No action plans linked to this survey.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
