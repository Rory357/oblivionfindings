import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpDown,
    BarChart3,
    HeartPulse,
    Plus,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

type FlaggedSortKey = 'risk' | 'overtime' | 'consecutive';
const FLAG_SORT_ORDER: Record<string, number> = { red: 0, amber: 1, none: 2 };

type FlaggedStaff = {
    user_id: number;
    name: string | null;
    position_title: string | null;
    flag_level: 'red' | 'amber' | 'none';
    triggered_rules: string[];
    metrics: {
        overtime_hours: number;
        consecutive_days_worked: number;
        sick_leave_days_30d: number;
        sick_leave_days_90d: number;
        shifts_worked_7d: number;
        average_shift_length_hours: number;
    };
};

type Survey = {
    id: number;
    title: string;
    description: string | null;
    survey_type: 'pulse' | 'enps' | 'engagement';
    status: 'draft' | 'published' | 'closed';
    is_anonymous: boolean;
    starts_at: string | null;
    ends_at: string | null;
    question_count: number;
    questions: Array<{
        question_type: 'enps' | 'scale' | 'text' | 'choice' | 'boolean';
        question_text: string;
        options: string[];
        is_required: boolean;
        sort_order: number;
    }>;
    response_count: number;
    has_responded: boolean;
};

type ActionPlan = {
    id: number;
    title: string;
    priority: 'low' | 'medium' | 'high';
    status: 'open' | 'in_progress' | 'completed' | 'cancelled';
    progress_percent: number;
    due_date: string | null;
    days_until_due: number | null;
    is_overdue: boolean;
    is_due_soon: boolean;
    can_update: boolean;
    owner: { id: number; name: string } | null;
    survey: { id: number; title: string } | null;
};

type ActionPlanSlaSummary = {
    open_total: number;
    overdue: number;
    due_today: number;
    due_next_7_days: number;
    high_priority_overdue: number;
    avg_progress_open: number;
    completed_last_30_days: number;
};

type ActionPlanOwnerWorkload = {
    owner_user_id: number;
    owner_name: string | null;
    open_count: number;
    overdue_count: number;
    due_next_7_days: number;
    avg_progress_percent: number;
};

type Props = {
    wellbeingSummary: {
        total_staff: number;
        flagged_red: number;
        flagged_amber: number;
        healthy: number;
    };
    flaggedStaff: FlaggedStaff[];
    surveys: Survey[];
    actionPlans: ActionPlan[];
    slaSummary: ActionPlanSlaSummary;
    ownerWorkload: ActionPlanOwnerWorkload[];
    actionPlanOwners: Array<{ id: number; name: string }>;
    filters: {
        status: 'all' | 'open' | 'in_progress' | 'completed' | 'cancelled';
        owner: number | null;
    };
    can: { manage: boolean };
};

type QuestionDraft = {
    question_type: 'enps' | 'scale' | 'text' | 'choice' | 'boolean';
    question_text: string;
    options_text: string;
    is_required: boolean;
};

const initialQuestion: QuestionDraft = {
    question_type: 'enps',
    question_text: '',
    options_text: '',
    is_required: true,
};

const createDefaultQuestions = (): QuestionDraft[] => [
    {
        question_type: 'enps',
        question_text:
            'How likely are you to recommend us as a place to work? (0-10)',
        options_text: '',
        is_required: true,
    },
    {
        ...initialQuestion,
        question_type: 'text',
        question_text: 'What should we improve first?',
    },
];

export default function WellbeingIndex({
    wellbeingSummary,
    flaggedStaff,
    surveys,
    actionPlans,
    slaSummary,
    ownerWorkload,
    actionPlanOwners,
    filters,
    can,
}: Props) {
    const [editingSurveyId, setEditingSurveyId] = useState<number | null>(null);
    const [questions, setQuestions] = useState<QuestionDraft[]>(
        createDefaultQuestions(),
    );

    const surveyForm = useForm({
        title: '',
        description: '',
        survey_type: 'enps' as 'pulse' | 'enps' | 'engagement',
        is_anonymous: true,
        starts_at: '',
        ends_at: '',
        questions: [] as Array<{
            question_type: 'enps' | 'scale' | 'text' | 'choice' | 'boolean';
            question_text: string;
            options: string[] | null;
            is_required: boolean;
            sort_order: number;
        }>,
    });

    const openSurveys = useMemo(
        () =>
            surveys.filter(
                (survey) =>
                    survey.status === 'published' && !survey.has_responded,
            ).length,
        [surveys],
    );

    const [flaggedSortKey, setFlaggedSortKey] =
        useState<FlaggedSortKey>('risk');

    const sortedFlaggedStaff = useMemo(() => {
        const copy = [...flaggedStaff];
        switch (flaggedSortKey) {
            case 'risk':
                return copy.sort(
                    (a, b) =>
                        (FLAG_SORT_ORDER[a.flag_level] ?? 2) -
                            (FLAG_SORT_ORDER[b.flag_level] ?? 2) ||
                        b.triggered_rules.length - a.triggered_rules.length,
                );
            case 'overtime':
                return copy.sort(
                    (a, b) =>
                        b.metrics.overtime_hours - a.metrics.overtime_hours,
                );
            case 'consecutive':
                return copy.sort(
                    (a, b) =>
                        b.metrics.consecutive_days_worked -
                        a.metrics.consecutive_days_worked,
                );
            default:
                return copy;
        }
    }, [flaggedStaff, flaggedSortKey]);

    const isSurveyFormValid =
        surveyForm.data.title.trim().length > 0 &&
        questions.some((q) => q.question_text.trim().length > 0);

    function addQuestion() {
        setQuestions((current) => [...current, { ...initialQuestion }]);
    }

    function updateQuestion(index: number, patch: Partial<QuestionDraft>) {
        setQuestions((current) =>
            current.map((question, i) =>
                i === index ? { ...question, ...patch } : question,
            ),
        );
    }

    function removeQuestion(index: number) {
        setQuestions((current) => current.filter((_, i) => i !== index));
    }

    function resetSurveyComposer() {
        setEditingSurveyId(null);
        surveyForm.reset();
        setQuestions(createDefaultQuestions());
    }

    function startEditSurvey(survey: Survey) {
        setEditingSurveyId(survey.id);
        surveyForm.setData({
            title: survey.title,
            description: survey.description || '',
            survey_type: survey.survey_type,
            is_anonymous: survey.is_anonymous,
            starts_at: survey.starts_at || '',
            ends_at: survey.ends_at || '',
            questions: [],
        });

        const questionDrafts =
            survey.questions.length > 0
                ? survey.questions
                      .sort((a, b) => a.sort_order - b.sort_order)
                      .map((question) => ({
                          question_type: question.question_type,
                          question_text: question.question_text,
                          options_text: (question.options || []).join('\n'),
                          is_required: Boolean(question.is_required),
                      }))
                : [{ ...initialQuestion }];

        setQuestions(questionDrafts);
    }

    function submitSurvey(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const payloadQuestions = questions
            .map((question, index) => ({
                question_type: question.question_type,
                question_text: question.question_text.trim(),
                options:
                    question.question_type === 'choice'
                        ? question.options_text
                              .split('\n')
                              .map((value) => value.trim())
                              .filter(Boolean)
                        : null,
                is_required: question.is_required,
                sort_order: index + 1,
            }))
            .filter((question) => question.question_text !== '');

        const payload = {
            title: surveyForm.data.title,
            description: surveyForm.data.description,
            survey_type: surveyForm.data.survey_type,
            is_anonymous: surveyForm.data.is_anonymous,
            starts_at: surveyForm.data.starts_at || null,
            ends_at: surveyForm.data.ends_at || null,
            questions: payloadQuestions,
        };

        surveyForm.transform(() => payload);

        if (editingSurveyId) {
            surveyForm.put(`/hr/wellbeing/surveys/${editingSurveyId}`, {
                preserveScroll: true,
                onSuccess: () => resetSurveyComposer(),
            });
            return;
        }

        surveyForm.post('/hr/wellbeing/surveys', {
            preserveScroll: true,
            onSuccess: () => resetSurveyComposer(),
        });
    }

    function publishSurvey(id: number) {
        router.post(
            `/hr/wellbeing/surveys/${id}/publish`,
            {},
            { preserveScroll: true },
        );
    }

    function closeSurvey(id: number) {
        router.post(
            `/hr/wellbeing/surveys/${id}/close`,
            {},
            { preserveScroll: true },
        );
    }

    function applyActionPlanFilters(
        next: Partial<{ status: string; owner: string }>,
    ) {
        const status = next.status ?? filters.status;
        const owner =
            next.owner ?? (filters.owner ? String(filters.owner) : 'all');

        router.get(
            '/hr/wellbeing',
            {
                ...(status !== 'all' ? { status } : {}),
                ...(owner !== 'all' ? { owner } : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    }

    function updateActionPlanStatus(
        planId: number,
        status: 'in_progress' | 'completed',
    ) {
        const payload: {
            status: 'in_progress' | 'completed';
            progress_percent?: number;
        } = { status };
        if (status === 'completed') {
            payload.progress_percent = 100;
        }

        router.put(`/hr/wellbeing/action-plans/${planId}`, payload, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Wellbeing', href: '/hr/wellbeing' },
            ]}
        >
            <Head title="HR Wellbeing" />
            <PageShell>
                <PageHero
                    icon={HeartPulse}
                    title="Wellbeing & Engagement"
                    description="Workload risk indicators, survey sentiment, and action plans."
                    stats={[
                        { label: 'Staff', value: wellbeingSummary.total_staff },
                        { label: 'Red flags', value: wellbeingSummary.flagged_red },
                        { label: 'Amber flags', value: wellbeingSummary.flagged_amber },
                        { label: 'Open plans', value: slaSummary.open_total },
                    ]}
                />

                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Staff Assessed
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {wellbeingSummary.total_staff}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Red Flags
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold text-destructive">
                            {wellbeingSummary.flagged_red}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Amber Flags
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold text-status-warning">
                            {wellbeingSummary.flagged_amber}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Open Surveys
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {openSurveys}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 md:grid-cols-3 lg:grid-cols-6">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Open Plans
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">
                            {slaSummary.open_total}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Overdue Plans
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold text-destructive">
                            {slaSummary.overdue}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Due Today
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">
                            {slaSummary.due_today}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Due Next 7 Days
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">
                            {slaSummary.due_next_7_days}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Avg Progress
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">
                            {slaSummary.avg_progress_open}%
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Completed (30d)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">
                            {slaSummary.completed_last_30_days}
                        </CardContent>
                    </Card>
                </div>

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2">
                                    <Plus className="h-4 w-4" />
                                    {editingSurveyId
                                        ? 'Edit Engagement Survey'
                                        : 'Create Engagement Survey'}
                                </CardTitle>
                                {editingSurveyId && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={resetSurveyComposer}
                                    >
                                        Cancel Edit
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitSurvey} className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Title</Label>
                                        <Input
                                            value={surveyForm.data.title}
                                            onChange={(event) =>
                                                surveyForm.setData(
                                                    'title',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Quarterly Pulse Check"
                                        />
                                        {surveyForm.errors.title && (
                                            <p className="text-sm text-destructive">
                                                {surveyForm.errors.title}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Survey Type</Label>
                                        <Select
                                            value={surveyForm.data.survey_type}
                                            onValueChange={(
                                                value:
                                                    | 'pulse'
                                                    | 'enps'
                                                    | 'engagement',
                                            ) =>
                                                surveyForm.setData(
                                                    'survey_type',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="enps">
                                                    eNPS
                                                </SelectItem>
                                                <SelectItem value="pulse">
                                                    Pulse
                                                </SelectItem>
                                                <SelectItem value="engagement">
                                                    Engagement
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>Description</Label>
                                    <Textarea
                                        rows={3}
                                        value={surveyForm.data.description}
                                        onChange={(event) =>
                                            surveyForm.setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Collect employee sentiment for the current quarter."
                                    />
                                </div>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label>Start Date</Label>
                                        <Input
                                            type="date"
                                            value={surveyForm.data.starts_at}
                                            onChange={(event) =>
                                                surveyForm.setData(
                                                    'starts_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>End Date</Label>
                                        <Input
                                            type="date"
                                            value={surveyForm.data.ends_at}
                                            onChange={(event) =>
                                                surveyForm.setData(
                                                    'ends_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 pt-8">
                                        <Checkbox
                                            id="anonymous"
                                            checked={
                                                surveyForm.data.is_anonymous
                                            }
                                            onCheckedChange={(checked) =>
                                                surveyForm.setData(
                                                    'is_anonymous',
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor="anonymous"
                                            className="font-normal"
                                        >
                                            Anonymous responses
                                        </Label>
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <Label>Questions</Label>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={addQuestion}
                                        >
                                            Add Question
                                        </Button>
                                    </div>
                                    {questions.map((question, index) => (
                                        <div
                                            key={index}
                                            className="space-y-3 rounded-lg border p-3"
                                        >
                                            <div className="grid gap-3 md:grid-cols-4">
                                                <div className="md:col-span-1">
                                                    <Select
                                                        value={
                                                            question.question_type
                                                        }
                                                        onValueChange={(
                                                            value: QuestionDraft['question_type'],
                                                        ) =>
                                                            updateQuestion(
                                                                index,
                                                                {
                                                                    question_type:
                                                                        value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="enps">
                                                                eNPS (0-10)
                                                            </SelectItem>
                                                            <SelectItem value="scale">
                                                                Scale (1-5)
                                                            </SelectItem>
                                                            <SelectItem value="text">
                                                                Text
                                                            </SelectItem>
                                                            <SelectItem value="choice">
                                                                Choice
                                                            </SelectItem>
                                                            <SelectItem value="boolean">
                                                                Yes / No
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="md:col-span-3">
                                                    <Input
                                                        value={
                                                            question.question_text
                                                        }
                                                        onChange={(event) =>
                                                            updateQuestion(
                                                                index,
                                                                {
                                                                    question_text:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder={`Question ${index + 1}`}
                                                    />
                                                </div>
                                            </div>

                                            {question.question_type ===
                                                'choice' && (
                                                <div className="space-y-1">
                                                    <Label className="text-xs text-muted-foreground">
                                                        Answer options (one per
                                                        line) -- only used for
                                                        Choice questions
                                                    </Label>
                                                    <Textarea
                                                        rows={3}
                                                        value={
                                                            question.options_text
                                                        }
                                                        onChange={(event) =>
                                                            updateQuestion(
                                                                index,
                                                                {
                                                                    options_text:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder={
                                                            'Option one\nOption two\nOption three'
                                                        }
                                                    />
                                                </div>
                                            )}

                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`required-${index}`}
                                                        checked={
                                                            question.is_required
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            updateQuestion(
                                                                index,
                                                                {
                                                                    is_required:
                                                                        Boolean(
                                                                            checked,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`required-${index}`}
                                                        className="font-normal"
                                                    >
                                                        Required
                                                    </Label>
                                                </div>
                                                {questions.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            removeQuestion(
                                                                index,
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <Button
                                    type="submit"
                                    disabled={
                                        surveyForm.processing ||
                                        !isSurveyFormValid
                                    }
                                >
                                    {surveyForm.processing
                                        ? 'Saving...'
                                        : editingSurveyId
                                          ? 'Update Survey'
                                          : 'Create Draft Survey'}
                                </Button>
                                {!isSurveyFormValid && (
                                    <p className="text-xs text-muted-foreground">
                                        A title and at least one question are
                                        required.
                                    </p>
                                )}
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BarChart3 className="h-4 w-4" />
                                Surveys
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {surveys.map((survey) => (
                                <div
                                    key={survey.id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">
                                                {survey.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {survey.survey_type.toUpperCase()}{' '}
                                                - {survey.question_count}{' '}
                                                questions -{' '}
                                                {survey.response_count}{' '}
                                                responses
                                            </p>
                                        </div>
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
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={`/hr/wellbeing/surveys/${survey.id}`}
                                            >
                                                Open
                                            </Link>
                                        </Button>
                                        {can.manage && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    startEditSurvey(survey)
                                                }
                                            >
                                                Edit
                                            </Button>
                                        )}
                                        {can.manage &&
                                            survey.status === 'draft' && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        publishSurvey(survey.id)
                                                    }
                                                >
                                                    Publish
                                                </Button>
                                            )}
                                        {can.manage &&
                                            survey.status === 'published' && (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        closeSurvey(survey.id)
                                                    }
                                                >
                                                    Close
                                                </Button>
                                            )}
                                    </div>
                                </div>
                            ))}
                            {surveys.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No surveys available.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <HeartPulse className="h-4 w-4" />
                                Engagement Action Plans
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-2 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label className="text-xs text-muted-foreground">
                                        Status filter
                                    </Label>
                                    <Select
                                        value={filters.status}
                                        onValueChange={(value) =>
                                            applyActionPlanFilters({
                                                status: value,
                                            })
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All statuses
                                            </SelectItem>
                                            <SelectItem value="open">
                                                Open
                                            </SelectItem>
                                            <SelectItem value="in_progress">
                                                In progress
                                            </SelectItem>
                                            <SelectItem value="completed">
                                                Completed
                                            </SelectItem>
                                            <SelectItem value="cancelled">
                                                Cancelled
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {can.manage && (
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">
                                            Owner filter
                                        </Label>
                                        <Select
                                            value={
                                                filters.owner
                                                    ? String(filters.owner)
                                                    : 'all'
                                            }
                                            onValueChange={(value) =>
                                                applyActionPlanFilters({
                                                    owner: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">
                                                    All owners
                                                </SelectItem>
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
                                    </div>
                                )}
                            </div>
                            {actionPlans.map((plan) => (
                                <div
                                    key={plan.id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium">
                                            {plan.title}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                variant={
                                                    plan.status === 'completed'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {plan.status}
                                            </Badge>
                                            {plan.is_overdue &&
                                                plan.days_until_due != null && (
                                                    <Badge variant="destructive">
                                                        {Math.abs(
                                                            plan.days_until_due,
                                                        )}
                                                        d overdue
                                                    </Badge>
                                                )}
                                            {!plan.is_overdue &&
                                                plan.days_until_due === 0 && (
                                                    <Badge variant="secondary">
                                                        Due today
                                                    </Badge>
                                                )}
                                            {!plan.is_overdue &&
                                                plan.is_due_soon &&
                                                plan.days_until_due !== 0 && (
                                                    <Badge variant="secondary">
                                                        Due in{' '}
                                                        {plan.days_until_due}d
                                                    </Badge>
                                                )}
                                        </div>
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {plan.survey?.title ?? 'No survey'} -
                                        Owner:{' '}
                                        {plan.owner?.name ?? 'Unassigned'} -
                                        Progress: {plan.progress_percent}% -
                                        Due: {plan.due_date ?? 'Not set'}
                                    </p>
                                    {plan.can_update &&
                                        (plan.status === 'open' ||
                                            plan.status === 'in_progress') && (
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {plan.status === 'open' && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            updateActionPlanStatus(
                                                                plan.id,
                                                                'in_progress',
                                                            )
                                                        }
                                                    >
                                                        Mark in progress
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        updateActionPlanStatus(
                                                            plan.id,
                                                            'completed',
                                                        )
                                                    }
                                                >
                                                    Mark completed
                                                </Button>
                                            </div>
                                        )}
                                </div>
                            ))}
                            {actionPlans.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No action plans yet.
                                </p>
                            )}

                            {can.manage && ownerWorkload.length > 0 && (
                                <div className="rounded-lg border p-3">
                                    <p className="text-sm font-medium">
                                        Owner workload snapshot
                                    </p>
                                    <div className="mt-2 space-y-2">
                                        {ownerWorkload
                                            .slice(0, 5)
                                            .map((row) => (
                                                <div
                                                    key={row.owner_user_id}
                                                    className="flex items-center justify-between text-xs text-muted-foreground"
                                                >
                                                    <span>
                                                        {row.owner_name ??
                                                            `User #${row.owner_user_id}`}
                                                    </span>
                                                    <span>
                                                        Open {row.open_count} -
                                                        Overdue{' '}
                                                        {row.overdue_count} -
                                                        Due soon{' '}
                                                        {row.due_next_7_days}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4" />
                                Flagged Wellbeing Indicators
                            </CardTitle>
                            {flaggedStaff.length > 1 && (
                                <div className="flex items-center gap-2">
                                    <ArrowUpDown className="h-3.5 w-3.5 text-muted-foreground" />
                                    <Select
                                        value={flaggedSortKey}
                                        onValueChange={(
                                            value: FlaggedSortKey,
                                        ) => setFlaggedSortKey(value)}
                                    >
                                        <SelectTrigger className="h-8 w-[160px] text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="risk">
                                                Highest risk first
                                            </SelectItem>
                                            <SelectItem value="overtime">
                                                Most overtime
                                            </SelectItem>
                                            <SelectItem value="consecutive">
                                                Most consecutive days
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {sortedFlaggedStaff.map((entry) => (
                            <div
                                key={entry.user_id}
                                className="rounded-lg border p-3"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <p className="font-medium">
                                        {entry.name ?? 'Unknown user'}
                                    </p>
                                    <Badge
                                        variant={
                                            entry.flag_level === 'red'
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                    >
                                        {entry.flag_level.toUpperCase()}
                                    </Badge>
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {entry.position_title ?? 'Role not set'} -
                                    OT {entry.metrics.overtime_hours}h -
                                    Consecutive days{' '}
                                    {entry.metrics.consecutive_days_worked}
                                </p>
                                {entry.triggered_rules.length > 0 && (
                                    <ul className="mt-2 list-disc space-y-1 pl-4 text-xs text-muted-foreground">
                                        {entry.triggered_rules.map(
                                            (rule, index) => (
                                                <li key={index}>{rule}</li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </div>
                        ))}
                        {flaggedStaff.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No active wellbeing flags.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
