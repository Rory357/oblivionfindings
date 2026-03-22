import { useState, FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Plus, Trash2, GripVertical } from 'lucide-react';

type Question = {
    question_text: string;
    question_type: string;
    options: string[];
    is_required: boolean;
};

type Props = {
    surveyTypes: string[];
    questionTypes: string[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Surveys', href: '/hr/surveys' },
    { title: 'Create Survey', href: '/hr/surveys/create' },
];

const typeLabels: Record<string, string> = {
    pulse: 'Pulse',
    enps: 'eNPS',
    engagement: 'Engagement',
    custom: 'Custom',
    rating: 'Rating (1-5)',
    text: 'Free Text',
    multiple_choice: 'Multiple Choice',
    enps_score: 'eNPS Score (0-10)',
};

const emptyQuestion: Question = {
    question_text: '',
    question_type: 'rating',
    options: [],
    is_required: true,
};

export default function CreateSurvey({ surveyTypes, questionTypes }: Props) {
    const [form, setForm] = useState({
        title: '',
        description: '',
        survey_type: 'pulse',
        is_anonymous: true,
        starts_at: '',
        ends_at: '',
    });

    const [questions, setQuestions] = useState<Question[]>([{ ...emptyQuestion }]);
    const [processing, setProcessing] = useState(false);

    const set = (key: string, value: any) => setForm((prev) => ({ ...prev, [key]: value }));

    const addQuestion = () => {
        setQuestions((prev) => [...prev, { ...emptyQuestion }]);
    };

    const removeQuestion = (index: number) => {
        setQuestions((prev) => prev.filter((_, i) => i !== index));
    };

    const updateQuestion = (index: number, key: keyof Question, value: any) => {
        setQuestions((prev) =>
            prev.map((q, i) => (i === index ? { ...q, [key]: value } : q))
        );
    };

    const addOption = (qIndex: number) => {
        setQuestions((prev) =>
            prev.map((q, i) => (i === qIndex ? { ...q, options: [...q.options, ''] } : q))
        );
    };

    const updateOption = (qIndex: number, oIndex: number, value: string) => {
        setQuestions((prev) =>
            prev.map((q, i) =>
                i === qIndex
                    ? { ...q, options: q.options.map((o, j) => (j === oIndex ? value : o)) }
                    : q
            )
        );
    };

    const removeOption = (qIndex: number, oIndex: number) => {
        setQuestions((prev) =>
            prev.map((q, i) =>
                i === qIndex
                    ? { ...q, options: q.options.filter((_, j) => j !== oIndex) }
                    : q
            )
        );
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.post(
            '/hr/surveys',
            {
                ...form,
                questions: questions.map((q, i) => ({
                    ...q,
                    sort_order: i,
                    options: q.question_type === 'multiple_choice' ? q.options.filter(Boolean) : null,
                })),
            },
            {
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Survey" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">Create Survey</h1>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Survey Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Survey Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    value={form.title}
                                    onChange={(e) => set('title', e.target.value)}
                                    placeholder="e.g. Q1 2026 Employee Satisfaction"
                                    required
                                />
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    rows={3}
                                    value={form.description}
                                    onChange={(e) => set('description', e.target.value)}
                                    placeholder="Describe the purpose of this survey..."
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Survey Type</Label>
                                <Select value={form.survey_type} onValueChange={(v) => set('survey_type', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {surveyTypes.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {typeLabels[t] || t}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.is_anonymous}
                                        onChange={(e) => set('is_anonymous', e.target.checked)}
                                        className="rounded border-slate-300"
                                    />
                                    Anonymous responses
                                </label>
                            </div>
                            <div className="space-y-2">
                                <Label>Start Date</Label>
                                <Input type="date" value={form.starts_at} onChange={(e) => set('starts_at', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>End Date</Label>
                                <Input type="date" value={form.ends_at} onChange={(e) => set('ends_at', e.target.value)} />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Questions */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Questions</CardTitle>
                                <Button type="button" variant="outline" size="sm" onClick={addQuestion}>
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add Question
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {questions.map((question, qIndex) => (
                                <div key={qIndex} className="rounded-lg border p-4 space-y-3">
                                    <div className="flex items-start gap-3">
                                        <GripVertical className="mt-2 h-4 w-4 text-muted-foreground" />
                                        <div className="flex-1 space-y-3">
                                            <div className="flex gap-3">
                                                <div className="flex-1">
                                                    <Input
                                                        value={question.question_text}
                                                        onChange={(e) => updateQuestion(qIndex, 'question_text', e.target.value)}
                                                        placeholder={`Question ${qIndex + 1}`}
                                                        required
                                                    />
                                                </div>
                                                <Select
                                                    value={question.question_type}
                                                    onValueChange={(v) => updateQuestion(qIndex, 'question_type', v)}
                                                >
                                                    <SelectTrigger className="w-44">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {questionTypes.map((t) => (
                                                            <SelectItem key={t} value={t}>
                                                                {typeLabels[t] || t}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {question.question_type === 'multiple_choice' && (
                                                <div className="space-y-2 pl-4">
                                                    {question.options.map((option, oIndex) => (
                                                        <div key={oIndex} className="flex gap-2">
                                                            <Input
                                                                value={option}
                                                                onChange={(e) => updateOption(qIndex, oIndex, e.target.value)}
                                                                placeholder={`Option ${oIndex + 1}`}
                                                            />
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => removeOption(qIndex, oIndex)}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </div>
                                                    ))}
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => addOption(qIndex)}
                                                    >
                                                        <Plus className="mr-1 h-3 w-3" />
                                                        Add Option
                                                    </Button>
                                                </div>
                                            )}

                                            <div className="flex items-center gap-4">
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        checked={question.is_required}
                                                        onChange={(e) => updateQuestion(qIndex, 'is_required', e.target.checked)}
                                                        className="rounded border-slate-300"
                                                    />
                                                    Required
                                                </label>
                                            </div>
                                        </div>
                                        {questions.length > 1 && (
                                            <Button type="button" variant="ghost" size="sm" onClick={() => removeQuestion(qIndex)}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => router.get('/hr/surveys')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Survey'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
