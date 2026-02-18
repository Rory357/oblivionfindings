import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useMemo } from 'react';

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
    existingResponse: { id: number; answers: Record<string, string | number | boolean>; submitted_at: string | null } | null;
    summary: SummaryPayload;
    actionPlanOwners: Array<{ id: number; name: string }>;
    can: {
        manage: boolean;
        respond: boolean;
    };
};

export default function WellbeingSurveyShow({ survey, existingResponse, summary, actionPlanOwners, can }: Props) {
    const responseForm = useForm({
        answers: (existingResponse?.answers ?? {}) as Record<string, string | number | boolean>,
    });

    const actionPlanForm = useForm({
        owner_user_id: actionPlanOwners[0]?.id ? String(actionPlanOwners[0].id) : '',
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
        responseForm.post(`/hr/wellbeing/surveys/${survey.id}/responses`, { preserveScroll: true });
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
                { title: survey.title, href: `/hr/wellbeing/surveys/${survey.id}` },
            ]}
        >
            <Head title={`Survey · ${survey.title}`} />
            <PageShell>
                <PageHeader
                    title={survey.title}
                    description={survey.description ?? 'Engagement survey'}
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge variant={survey.status === 'published' ? 'default' : survey.status === 'closed' ? 'secondary' : 'outline'}>
                                {survey.status}
                            </Badge>
                            <Badge variant="outline">{survey.survey_type.toUpperCase()}</Badge>
                        </div>
                    }
                />

                {summary && (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Responses</CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">{summary.response_count}</CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Average Score</CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">{summary.average_overall_score ?? '-'}</CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">eNPS</CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">{summary.enps ?? '-'}</CardContent>
                        </Card>
                    </div>
                )}

                {can.respond && !existingResponse ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Submit Survey Response</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitResponse} className="space-y-4">
                                {sortedQuestions.map((question) => (
                                    <div key={question.id} className="space-y-2 rounded-lg border p-3">
                                        <Label className="text-sm">
                                            {question.question_text}
                                            {question.is_required && <span className="ml-1 text-destructive">*</span>}
                                        </Label>

                                        {question.question_type === 'text' && (
                                            <Textarea
                                                rows={3}
                                                value={String(responseForm.data.answers[String(question.id)] ?? '')}
                                                onChange={(event) => setAnswer(question.id, event.target.value)}
                                            />
                                        )}

                                        {(question.question_type === 'enps' || question.question_type === 'scale') && (
                                            <Input
                                                type="number"
                                                min={question.question_type === 'enps' ? 0 : 1}
                                                max={question.question_type === 'enps' ? 10 : 5}
                                                value={String(responseForm.data.answers[String(question.id)] ?? '')}
                                                onChange={(event) => setAnswer(question.id, event.target.value === '' ? '' : Number(event.target.value))}
                                            />
                                        )}

                                        {question.question_type === 'choice' && (
                                            <Select
                                                value={String(responseForm.data.answers[String(question.id)] ?? '')}
                                                onValueChange={(value) => setAnswer(question.id, value)}
                                            >
                                                <SelectTrigger><SelectValue placeholder="Select an option" /></SelectTrigger>
                                                <SelectContent>
                                                    {question.options.map((option) => (
                                                        <SelectItem key={option} value={option}>{option}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}

                                        {question.question_type === 'boolean' && (
                                            <div className="flex items-center gap-6">
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`q-${question.id}-yes`}
                                                        checked={responseForm.data.answers[String(question.id)] === true}
                                                        onCheckedChange={() => setAnswer(question.id, true)}
                                                    />
                                                    <Label htmlFor={`q-${question.id}-yes`} className="font-normal">Yes</Label>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`q-${question.id}-no`}
                                                        checked={responseForm.data.answers[String(question.id)] === false}
                                                        onCheckedChange={() => setAnswer(question.id, false)}
                                                    />
                                                    <Label htmlFor={`q-${question.id}-no`} className="font-normal">No</Label>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}

                                <Button type="submit" disabled={responseForm.processing}>
                                    {responseForm.processing ? 'Submitting...' : 'Submit Response'}
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

                {can.manage && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Create Action Plan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitActionPlan} className="space-y-3">
                                    <div className="space-y-2">
                                        <Label>Owner</Label>
                                        <Select
                                            value={actionPlanForm.data.owner_user_id}
                                            onValueChange={(value) => actionPlanForm.setData('owner_user_id', value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select owner" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {actionPlanOwners.map((owner) => (
                                                    <SelectItem key={owner.id} value={String(owner.id)}>
                                                        {owner.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {actionPlanOwners.length === 0 && (
                                            <p className="text-xs text-destructive">No active employees found for ownership.</p>
                                        )}
                                        {actionPlanForm.errors.owner_user_id && (
                                            <p className="text-xs text-destructive">{actionPlanForm.errors.owner_user_id}</p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Title</Label>
                                        <Input
                                            value={actionPlanForm.data.title}
                                            onChange={(event) => actionPlanForm.setData('title', event.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Description</Label>
                                        <Textarea
                                            rows={3}
                                            value={actionPlanForm.data.description}
                                            onChange={(event) => actionPlanForm.setData('description', event.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Priority</Label>
                                            <Select
                                                value={actionPlanForm.data.priority}
                                                onValueChange={(value: 'low' | 'medium' | 'high') => actionPlanForm.setData('priority', value)}
                                            >
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="low">Low</SelectItem>
                                                    <SelectItem value="medium">Medium</SelectItem>
                                                    <SelectItem value="high">High</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Due Date</Label>
                                            <Input
                                                type="date"
                                                value={actionPlanForm.data.due_date}
                                                onChange={(event) => actionPlanForm.setData('due_date', event.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <Button type="submit" disabled={actionPlanForm.processing || actionPlanOwners.length === 0}>
                                        {actionPlanForm.processing ? 'Saving...' : 'Create Action Plan'}
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
                                    <div key={plan.id} className="rounded-lg border p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-medium">{plan.title}</p>
                                            <Badge variant={plan.status === 'completed' ? 'default' : 'outline'}>{plan.status}</Badge>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {plan.owner?.name ?? 'Unassigned'} · {plan.priority} · {plan.progress_percent}%
                                        </p>
                                    </div>
                                ))}
                                {survey.action_plans.length === 0 && (
                                    <p className="text-sm text-muted-foreground">No action plans linked to this survey.</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}

