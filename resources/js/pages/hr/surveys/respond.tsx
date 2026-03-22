import { useState, FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';

type Question = {
    id: number;
    question_text: string;
    question_type: string;
    options: string[] | null;
    is_required: boolean;
};

type SurveyData = {
    id: number;
    title: string;
    description: string | null;
    survey_type: string;
    is_anonymous: boolean;
    ends_at: string | null;
    questions: Question[];
};

type Props = {
    survey: SurveyData;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Surveys', href: '/hr/surveys' },
    { title: 'Respond', href: '#' },
];

export default function RespondSurvey({ survey }: Props) {
    const [answers, setAnswers] = useState<Record<number, { answer_text?: string; answer_rating?: number; answer_choice?: string }>>(
        () => {
            const initial: Record<number, any> = {};
            survey.questions.forEach((q) => {
                initial[q.id] = {};
            });
            return initial;
        }
    );
    const [processing, setProcessing] = useState(false);

    const setAnswer = (questionId: number, key: string, value: any) => {
        setAnswers((prev) => ({
            ...prev,
            [questionId]: { ...prev[questionId], [key]: value },
        }));
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = {
            answers: survey.questions.map((q) => ({
                question_id: q.id,
                ...answers[q.id],
            })),
        };

        router.post(`/hr/surveys/${survey.id}/respond`, payload, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Survey: ${survey.title}`} />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">{survey.title}</h1>
                    {survey.description && (
                        <p className="mt-2 text-sm text-muted-foreground">{survey.description}</p>
                    )}
                    <div className="mt-2 flex gap-2">
                        {survey.is_anonymous && (
                            <Badge variant="secondary">Anonymous</Badge>
                        )}
                        {survey.ends_at && (
                            <Badge variant="outline">Ends: {survey.ends_at}</Badge>
                        )}
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {survey.questions.map((question, index) => (
                        <Card key={question.id}>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium">
                                    {index + 1}. {question.question_text}
                                    {question.is_required && <span className="ml-1 text-destructive">*</span>}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {question.question_type === 'rating' && (
                                    <div className="flex gap-2">
                                        {[1, 2, 3, 4, 5].map((val) => (
                                            <button
                                                key={val}
                                                type="button"
                                                onClick={() => setAnswer(question.id, 'answer_rating', val)}
                                                className={`h-10 w-10 rounded-lg border text-sm font-medium transition-colors ${
                                                    answers[question.id]?.answer_rating === val
                                                        ? 'border-blue-500 bg-blue-500 text-white'
                                                        : 'hover:bg-muted'
                                                }`}
                                            >
                                                {val}
                                            </button>
                                        ))}
                                        <span className="ml-2 self-center text-xs text-muted-foreground">1 = Low, 5 = High</span>
                                    </div>
                                )}

                                {question.question_type === 'enps_score' && (
                                    <div>
                                        <div className="flex flex-wrap gap-2">
                                            {Array.from({ length: 11 }, (_, i) => i).map((val) => (
                                                <button
                                                    key={val}
                                                    type="button"
                                                    onClick={() => setAnswer(question.id, 'answer_rating', val)}
                                                    className={`h-10 w-10 rounded-lg border text-sm font-medium transition-colors ${
                                                        answers[question.id]?.answer_rating === val
                                                            ? val <= 6
                                                                ? 'border-red-500 bg-red-500 text-white'
                                                                : val <= 8
                                                                  ? 'border-yellow-500 bg-yellow-500 text-white'
                                                                  : 'border-emerald-500 bg-emerald-500 text-white'
                                                            : 'hover:bg-muted'
                                                    }`}
                                                >
                                                    {val}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                                            <span>Not at all likely</span>
                                            <span>Extremely likely</span>
                                        </div>
                                    </div>
                                )}

                                {question.question_type === 'text' && (
                                    <Textarea
                                        rows={3}
                                        value={answers[question.id]?.answer_text || ''}
                                        onChange={(e) => setAnswer(question.id, 'answer_text', e.target.value)}
                                        placeholder="Type your answer..."
                                    />
                                )}

                                {question.question_type === 'multiple_choice' && question.options && (
                                    <div className="space-y-2">
                                        {question.options.map((option, oIndex) => (
                                            <label
                                                key={oIndex}
                                                className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors ${
                                                    answers[question.id]?.answer_choice === option
                                                        ? 'border-blue-500 bg-blue-500/5'
                                                        : 'hover:bg-muted'
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name={`q_${question.id}`}
                                                    value={option}
                                                    checked={answers[question.id]?.answer_choice === option}
                                                    onChange={() => setAnswer(question.id, 'answer_choice', option)}
                                                    className="accent-blue-500"
                                                />
                                                <span className="text-sm">{option}</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}

                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => router.get('/hr/surveys')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Submitting...' : 'Submit Response'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
