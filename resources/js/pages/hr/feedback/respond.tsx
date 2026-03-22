import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { Star } from 'lucide-react';

type User = { id: number; name: string };

type FeedbackRequestData = {
    id: number;
    subject: User | null;
    review_type: string;
    due_date: string | null;
};

type Props = {
    feedbackRequest: FeedbackRequestData;
    questions: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Respond', href: '#' },
];

function StarRating({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    return (
        <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((star) => (
                <button
                    key={star}
                    type="button"
                    onClick={() => onChange(star)}
                    className="focus:outline-none"
                >
                    <Star
                        className={`h-6 w-6 transition-colors ${
                            star <= value
                                ? 'fill-amber-400 text-amber-400'
                                : 'text-muted-foreground/30 hover:text-amber-400/50'
                        }`}
                    />
                </button>
            ))}
        </div>
    );
}

export default function FeedbackRespond({ feedbackRequest, questions }: Props) {
    const questionKeys = Object.keys(questions);

    const form = useForm({
        responses: questionKeys.map((key) => ({
            question_key: key,
            rating: 0,
            comment: '',
        })),
    });

    const updateResponse = (index: number, field: 'rating' | 'comment', value: number | string) => {
        const updated = [...form.data.responses];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('responses', updated);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/hr/feedback/${feedbackRequest.id}/respond`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submit Feedback" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Submit Feedback</h1>
                    <p className="text-sm text-muted-foreground">
                        Providing feedback for <strong>{feedbackRequest.subject?.name ?? 'Unknown'}</strong>
                        {feedbackRequest.due_date && (
                            <span className="ml-2">
                                (Due: {feedbackRequest.due_date})
                            </span>
                        )}
                    </p>
                </div>

                <form onSubmit={submit} className="max-w-2xl space-y-4">
                    {questionKeys.map((key, index) => (
                        <Card key={key}>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">{questions[key]}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Rating (1-5)</Label>
                                    <StarRating
                                        value={form.data.responses[index].rating}
                                        onChange={(v) => updateResponse(index, 'rating', v)}
                                    />
                                    {(form.errors as Record<string, string>)[`responses.${index}.rating`] && (
                                        <p className="text-sm text-destructive">
                                            {(form.errors as Record<string, string>)[`responses.${index}.rating`]}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label>Comments (optional)</Label>
                                    <Textarea
                                        value={form.data.responses[index].comment}
                                        onChange={(e) => updateResponse(index, 'comment', e.target.value)}
                                        placeholder="Share your observations..."
                                        rows={3}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    <div className="flex gap-3 pt-2">
                        <Button type="submit" disabled={form.processing}>
                            Submit Feedback
                        </Button>
                        <Button type="button" variant="outline" onClick={() => history.back()}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
