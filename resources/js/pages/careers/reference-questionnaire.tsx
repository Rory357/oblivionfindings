import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type Question = {
    key: string;
    label: string;
    type: 'text' | 'choice' | 'rating';
    options?: string[];
};

type Props = {
    token: string;
    refereeName: string;
    candidateName: string;
    questions: Question[];
    completed: boolean;
};

export default function ReferenceQuestionnaire({
    token,
    refereeName,
    candidateName,
    questions,
    completed,
}: Props) {
    const [answers, setAnswers] = useState<Record<string, string>>({});
    const form = useForm<{ responses: Record<string, string> }>({
        responses: {},
    });

    const setAnswer = (key: string, value: string) =>
        setAnswers((a) => ({ ...a, [key]: value }));

    const submit = () => {
        form.transform(() => ({ responses: answers }));
        form.post(`/careers/references/${token}`, { preserveScroll: true });
    };

    return (
        <PageLayout>
            <Head title="Reference questionnaire" />
            <PageHero
                category="hr"
                icon={ShieldCheck}
                title="Reference questionnaire"
                description={`Your feedback on ${candidateName}`}
            />

            <div className="mx-auto mt-6 w-full max-w-2xl">
                {completed ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <CheckCircle2 className="h-10 w-10 text-status-success" />
                            <h2 className="text-lg font-bold">
                                Thank you, {refereeName}
                            </h2>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Your reference for {candidateName} has been
                                submitted. There's nothing more to do.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Kia ora {refereeName}</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {candidateName} has listed you as a referee.
                                Please answer the questions below — it takes a
                                couple of minutes.
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-6">
                            {questions.map((q) => (
                                <div key={q.key}>
                                    <Label className="mb-2 block text-sm font-semibold">
                                        {q.label}
                                    </Label>
                                    {q.type === 'text' ? (
                                        <Textarea
                                            value={answers[q.key] ?? ''}
                                            onChange={(e) =>
                                                setAnswer(q.key, e.target.value)
                                            }
                                            rows={3}
                                        />
                                    ) : q.type === 'choice' ? (
                                        <div className="flex flex-wrap gap-2">
                                            {(q.options ?? []).map((opt) => (
                                                <Button
                                                    unstyled
                                                    key={opt}
                                                    type="button"
                                                    onClick={() =>
                                                        setAnswer(q.key, opt)
                                                    }
                                                    className={cn(
                                                        'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                                                        answers[q.key] === opt
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border bg-card hover:border-primary/50',
                                                    )}
                                                >
                                                    {opt}
                                                </Button>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="flex gap-2">
                                            {[1, 2, 3, 4, 5].map((n) => (
                                                <Button
                                                    unstyled
                                                    key={n}
                                                    type="button"
                                                    onClick={() =>
                                                        setAnswer(
                                                            q.key,
                                                            String(n),
                                                        )
                                                    }
                                                    className={cn(
                                                        'h-10 w-10 rounded-lg border text-sm font-bold transition-colors',
                                                        answers[q.key] ===
                                                            String(n)
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'border-border bg-card hover:border-primary/50',
                                                    )}
                                                >
                                                    {n}
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}

                            <Button
                                onClick={submit}
                                disabled={form.processing}
                                className="self-end"
                            >
                                Submit reference
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </PageLayout>
    );
}
