import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Calendar, CheckCircle2, MessageSquare, Send, Star } from 'lucide-react';

type User = { id: number; name: string };
type FeedbackRequestData = { id: number; subject: User | null; review_type: string; due_date: string | null };
type Props = { feedbackRequest: FeedbackRequestData; questions: Record<string, string> };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Respond', href: '#' },
];

const RATING_LABELS = ['', 'Poor', 'Below Average', 'Average', 'Good', 'Excellent'];

const QUESTION_ICONS: Record<string, string> = {
    communication: 'from-blue-500/10 to-blue-500/5',
    teamwork: 'from-emerald-500/10 to-emerald-500/5',
    leadership: 'from-violet-500/10 to-violet-500/5',
    technical: 'from-amber-500/10 to-amber-500/5',
    initiative: 'from-pink-500/10 to-pink-500/5',
    overall: 'from-indigo-500/10 to-indigo-500/5',
};

const AVATAR_COLORS = ['bg-blue-500', 'bg-primary', 'bg-emerald-500', 'bg-amber-500', 'bg-pink-500', 'bg-cyan-500'];
function avatarColor(id: number) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }
function getInitials(name: string) { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }

function StarRating({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    return (
        <div className="flex items-center gap-3">
            <div className="flex gap-1">
                {[1, 2, 3, 4, 5].map((star) => (
                    <button key={star} type="button" onClick={() => onChange(star)} className="group/star focus:outline-none">
                        <Star className={`h-7 w-7 transition-all ${star <= value ? 'fill-amber-400 text-amber-400 scale-110' : 'text-muted-foreground/20 hover:text-amber-300 group-hover/star:scale-110'}`} />
                    </button>
                ))}
            </div>
            {value > 0 && (
                <Badge variant="outline" className={`text-[10px] ${value >= 4 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : value >= 3 ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-red-200 bg-red-50 text-red-700'}`}>
                    {RATING_LABELS[value]}
                </Badge>
            )}
        </div>
    );
}

export default function FeedbackRespond({ feedbackRequest, questions }: Props) {
    const questionKeys = Object.keys(questions);
    const form = useForm({
        responses: questionKeys.map((key) => ({ question_key: key, rating: 0, comment: '' })),
    });

    const updateResponse = (index: number, field: 'rating' | 'comment', value: number | string) => {
        const updated = [...form.data.responses];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('responses', updated);
    };

    const submit = (e: React.FormEvent) => { e.preventDefault(); form.post(`/hr/feedback/${feedbackRequest.id}/respond`); };
    const answeredCount = form.data.responses.filter(r => r.rating > 0).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submit Feedback" />
            <div className="space-y-6 p-4 lg:p-6">

                {/* Hero */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 p-6 text-white shadow-lg">
                    <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/5" />
                    <div className="absolute -bottom-8 right-20 h-24 w-24 rounded-full bg-white/5" />
                    <div className="relative flex items-center gap-4">
                        <div className={`flex h-14 w-14 items-center justify-center rounded-2xl border-2 border-white/30 text-lg font-bold shadow-md ${avatarColor(feedbackRequest.subject?.id ?? 0)}`}>
                            {getInitials(feedbackRequest.subject?.name ?? '?')}
                        </div>
                        <div className="flex-1">
                            <h1 className="text-xl font-bold">Provide Feedback</h1>
                            <p className="text-white/70">for <strong className="text-white">{feedbackRequest.subject?.name ?? 'Unknown'}</strong></p>
                        </div>
                        <div className="flex items-center gap-4">
                            {feedbackRequest.due_date && (
                                <div className="flex items-center gap-1.5 text-sm text-white/70">
                                    <Calendar className="h-4 w-4" />
                                    Due {feedbackRequest.due_date}
                                </div>
                            )}
                            <div className="text-center">
                                <div className="text-2xl font-bold">{answeredCount}/{questionKeys.length}</div>
                                <div className="text-[10px] uppercase tracking-wider text-white/60">Answered</div>
                            </div>
                        </div>
                    </div>
                    {/* Progress bar */}
                    <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/20">
                        <div className="h-full rounded-full bg-white/80 transition-all duration-500" style={{ width: `${(answeredCount / questionKeys.length) * 100}%` }} />
                    </div>
                </div>

                <form onSubmit={submit} className="mx-auto max-w-3xl space-y-4">
                    {questionKeys.map((key, index) => {
                        const gradient = QUESTION_ICONS[key] || 'from-slate-500/10 to-slate-500/5';
                        return (
                            <Card key={key} className={`overflow-hidden bg-gradient-to-br ${gradient} transition-all hover:shadow-sm`}>
                                <CardContent className="p-5">
                                    <div className="mb-4 flex items-start gap-3">
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{index + 1}</div>
                                        <div>
                                            <h3 className="text-sm font-semibold capitalize">{key.replace(/_/g, ' ')}</h3>
                                            <p className="mt-0.5 text-xs text-muted-foreground">{questions[key]}</p>
                                        </div>
                                    </div>

                                    <div className="ml-10 space-y-3">
                                        <StarRating value={form.data.responses[index].rating} onChange={(v) => updateResponse(index, 'rating', v)} />
                                        {(form.errors as Record<string, string>)[`responses.${index}.rating`] && (
                                            <p className="text-xs text-red-600">{(form.errors as Record<string, string>)[`responses.${index}.rating`]}</p>
                                        )}
                                        <Textarea
                                            value={form.data.responses[index].comment}
                                            onChange={(e) => updateResponse(index, 'comment', e.target.value)}
                                            placeholder="Share your observations... (optional)"
                                            rows={2}
                                            className="bg-white/80 text-sm"
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {/* Submit */}
                    <div className="flex items-center justify-between rounded-xl border bg-gradient-to-r from-violet-50 to-purple-50 p-4">
                        <div className="flex items-center gap-2 text-sm">
                            {answeredCount === questionKeys.length ? (
                                <><CheckCircle2 className="h-4 w-4 text-emerald-500" /><span className="text-emerald-700 font-medium">All questions answered</span></>
                            ) : (
                                <span className="text-muted-foreground">{answeredCount} of {questionKeys.length} questions rated</span>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => history.back()}>Cancel</Button>
                            <Button type="submit" className="gap-1.5 bg-primary hover:bg-primary" disabled={form.processing}>
                                <Send className="h-3.5 w-3.5" />Submit Feedback
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
