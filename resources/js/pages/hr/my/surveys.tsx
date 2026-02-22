import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { type BreadcrumbItem } from '@/types';
import { CheckCircle2, MessageSquare } from 'lucide-react';

interface SurveyQuestion {
    id: number;
    question_type: 'scale' | 'enps' | 'text' | 'choice' | 'boolean';
    question_text: string;
    options: string[];
    is_required: boolean;
    sort_order: number;
}

interface Survey {
    id: number;
    title: string;
    description: string | null;
    is_anonymous: boolean;
    starts_at: string | null;
    ends_at: string | null;
    has_responded: boolean;
    questions: SurveyQuestion[];
}

interface Props {
    surveys: Survey[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Surveys', href: '/hr/my/surveys' },
];

function QuestionRenderer({
    question,
    value,
    onChange,
}: {
    question: SurveyQuestion;
    value: string;
    onChange: (val: string) => void;
}) {
    switch (question.question_type) {
        case 'scale':
            return (
                <RadioGroup value={value} onValueChange={onChange} className="flex flex-wrap gap-2">
                    {Array.from({ length: 10 }, (_, i) => i + 1).map((n) => (
                        <div key={n} className="flex items-center gap-1">
                            <RadioGroupItem value={String(n)} id={`q${question.id}-${n}`} />
                            <Label htmlFor={`q${question.id}-${n}`} className="text-sm cursor-pointer">
                                {n}
                            </Label>
                        </div>
                    ))}
                </RadioGroup>
            );
        case 'enps':
            return (
                <div>
                    <RadioGroup value={value} onValueChange={onChange} className="flex flex-wrap gap-2">
                        {Array.from({ length: 11 }, (_, i) => i).map((n) => (
                            <div key={n} className="flex items-center gap-1">
                                <RadioGroupItem value={String(n)} id={`q${question.id}-${n}`} />
                                <Label htmlFor={`q${question.id}-${n}`} className="text-sm cursor-pointer">
                                    {n}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                    <div className="flex justify-between text-xs text-muted-foreground mt-1">
                        <span>Not at all likely</span>
                        <span>Extremely likely</span>
                    </div>
                </div>
            );
        case 'boolean':
            return (
                <RadioGroup value={value} onValueChange={onChange} className="flex gap-4">
                    <div className="flex items-center gap-2">
                        <RadioGroupItem value="yes" id={`q${question.id}-yes`} />
                        <Label htmlFor={`q${question.id}-yes`} className="cursor-pointer">Yes</Label>
                    </div>
                    <div className="flex items-center gap-2">
                        <RadioGroupItem value="no" id={`q${question.id}-no`} />
                        <Label htmlFor={`q${question.id}-no`} className="cursor-pointer">No</Label>
                    </div>
                </RadioGroup>
            );
        case 'choice':
            return (
                <RadioGroup value={value} onValueChange={onChange} className="space-y-2">
                    {(question.options || []).map((opt, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <RadioGroupItem value={opt} id={`q${question.id}-opt${i}`} />
                            <Label htmlFor={`q${question.id}-opt${i}`} className="cursor-pointer">{opt}</Label>
                        </div>
                    ))}
                </RadioGroup>
            );
        case 'text':
        default:
            return (
                <Textarea
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="Your response..."
                    className="min-h-[80px]"
                />
            );
    }
}

function SurveyCard({ survey }: { survey: Survey }) {
    const [expanded, setExpanded] = useState(false);
    const [answers, setAnswers] = useState<Record<string, string>>({});
    const form = useForm({ answers: {} as Record<string, string> });

    const handleSubmit = () => {
        if (!confirm('Are you sure you want to submit this survey? You cannot change your answers after submission.')) return;
        form.transform(() => ({ answers }));
        form.post(`/hr/my/surveys/${survey.id}`, { preserveScroll: true });
    };

    const setAnswer = (questionId: number, value: string) => {
        setAnswers((prev) => ({ ...prev, [String(questionId)]: value }));
    };

    const allRequiredAnswered = survey.questions
        .filter((q) => q.is_required)
        .every((q) => {
            const val = answers[String(q.id)];
            return val !== undefined && val !== '';
        });

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <MessageSquare className="h-5 w-5 text-muted-foreground" />
                        <CardTitle className="text-base">{survey.title}</CardTitle>
                        {survey.is_anonymous && (
                            <Badge variant="outline" className="text-xs">Anonymous</Badge>
                        )}
                        {survey.has_responded && (
                            <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 bg-emerald-500/10 text-xs">
                                <CheckCircle2 className="h-3 w-3 mr-1" />
                                Completed
                            </Badge>
                        )}
                    </div>
                    {survey.ends_at && (
                        <span className="text-sm text-muted-foreground">Due: {survey.ends_at}</span>
                    )}
                </div>
                {survey.description && (
                    <CardDescription className="mt-1">{survey.description}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {survey.has_responded ? (
                    <p className="text-sm text-muted-foreground">Thank you for completing this survey.</p>
                ) : !expanded ? (
                    <Button variant="outline" onClick={() => setExpanded(true)}>
                        Start Survey ({survey.questions.length} question{survey.questions.length !== 1 ? 's' : ''})
                    </Button>
                ) : (
                    <div className="space-y-6">
                        {survey.questions.map((question, index) => (
                            <div key={question.id} className="space-y-2">
                                <Label className="text-sm font-medium">
                                    {index + 1}. {question.question_text}
                                    {question.is_required && <span className="text-red-500 ml-1">*</span>}
                                </Label>
                                <QuestionRenderer
                                    question={question}
                                    value={answers[String(question.id)] ?? ''}
                                    onChange={(val) => setAnswer(question.id, val)}
                                />
                            </div>
                        ))}

                        <div className="flex gap-2 border-t pt-4">
                            <Button onClick={handleSubmit} disabled={!allRequiredAnswered || form.processing}>
                                Submit Survey
                            </Button>
                            <Button variant="outline" onClick={() => setExpanded(false)}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function MySurveys({ surveys }: Props) {
    const pending = surveys.filter((s) => !s.has_responded);
    const completed = surveys.filter((s) => s.has_responded);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Surveys" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Surveys</h1>

                {surveys.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No surveys available at this time.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {pending.length > 0 && (
                            <div className="space-y-4">
                                <h2 className="text-lg font-semibold">Pending</h2>
                                {pending.map((survey) => (
                                    <SurveyCard key={survey.id} survey={survey} />
                                ))}
                            </div>
                        )}

                        {completed.length > 0 && (
                            <div className="space-y-4">
                                <h2 className="text-lg font-semibold">Completed</h2>
                                {completed.map((survey) => (
                                    <SurveyCard key={survey.id} survey={survey} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
