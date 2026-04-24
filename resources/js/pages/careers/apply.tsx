import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Briefcase, MapPin, Wifi } from 'lucide-react';
import { employmentTypeLabels, sourceChannelOptions } from '@/lib/job-posting-constants';
import type { ScreeningQuestion } from '@/types/job-postings';

type Props = {
    posting: {
        id: number;
        slug: string;
        title: string;
        department: string | null;
        location: string | null;
        employment_type: string;
        is_remote: boolean;
        screening_questions: ScreeningQuestion[];
    };
};

export default function CareersApply({ posting }: Props) {
    const initialScreeningAnswers: Record<string, string> = {};
    posting.screening_questions.forEach(q => { initialScreeningAnswers[q.id] = ''; });

    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        cover_letter: '',
        cv: null as File | null,
        screening_answers: initialScreeningAnswers,
        privacy_consent: false,
        source: 'career_page',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/careers/${posting.slug}/apply`);
    }

    function setScreeningAnswer(questionId: string, value: string) {
        form.setData('screening_answers', { ...form.data.screening_answers, [questionId]: value });
    }

    return (
        <>
            <Head title={`Apply - ${posting.title}`} />
            <div className="mx-auto max-w-3xl px-4 py-10 space-y-6">
                <div>
                    <Link href={`/careers/${posting.slug}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="h-4 w-4" /> Back to job details
                    </Link>
                    <h1 className="mt-3 text-3xl font-bold">Apply for {posting.title}</h1>
                    <div className="flex flex-wrap gap-2 mt-2 text-sm text-muted-foreground">
                        <span className="flex items-center gap-1"><Briefcase className="h-3.5 w-3.5" /> {employmentTypeLabels[posting.employment_type]}</span>
                        {posting.department && <span>{posting.department}</span>}
                        {posting.location && <span className="flex items-center gap-1"><MapPin className="h-3.5 w-3.5" /> {posting.location}</span>}
                        {posting.is_remote && <Badge variant="outline" className="text-xs gap-1 border-status-info/30 text-status-info bg-status-info"><Wifi className="h-3 w-3" /> Remote</Badge>}
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    {/* Personal Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Your Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label>First name *</Label>
                                    <Input value={form.data.first_name} onChange={e => form.setData('first_name', e.target.value)} className="mt-1" />
                                    {form.errors.first_name && <p className="text-sm text-destructive mt-1">{form.errors.first_name}</p>}
                                </div>
                                <div>
                                    <Label>Last name *</Label>
                                    <Input value={form.data.last_name} onChange={e => form.setData('last_name', e.target.value)} className="mt-1" />
                                    {form.errors.last_name && <p className="text-sm text-destructive mt-1">{form.errors.last_name}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label>Email *</Label>
                                    <Input type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} className="mt-1" />
                                    {form.errors.email && <p className="text-sm text-destructive mt-1">{form.errors.email}</p>}
                                </div>
                                <div>
                                    <Label>Phone</Label>
                                    <Input value={form.data.phone} onChange={e => form.setData('phone', e.target.value)} className="mt-1" />
                                </div>
                            </div>
                            <div>
                                <Label>How did you hear about this role?</Label>
                                <select
                                    className="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.data.source}
                                    onChange={e => form.setData('source', e.target.value)}
                                >
                                    {sourceChannelOptions.map(opt => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Screening Questions */}
                    {posting.screening_questions.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Screening Questions</CardTitle>
                                <CardDescription>Please answer the following questions</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {posting.screening_questions.map(q => (
                                    <fieldset key={q.id} aria-label={q.question}>
                                        <Label id={`label-${q.id}`}>{q.question} {q.required && <span className="text-destructive">*</span>}</Label>
                                        {q.type === 'yes_no' && (
                                            <div className="flex gap-4 mt-2" role="radiogroup" aria-labelledby={`label-${q.id}`}>
                                                {['Yes', 'No'].map(opt => (
                                                    <label key={opt} className="flex items-center gap-2 cursor-pointer">
                                                        <input
                                                            type="radio"
                                                            name={q.id}
                                                            value={opt.toLowerCase()}
                                                            checked={form.data.screening_answers[q.id] === opt.toLowerCase()}
                                                            onChange={e => setScreeningAnswer(q.id, e.target.value)}
                                                            className="h-4 w-4"
                                                            aria-label={`${q.question}: ${opt}`}
                                                        />
                                                        <span className="text-sm">{opt}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        )}
                                        {q.type === 'text' && (
                                            <Textarea
                                                value={form.data.screening_answers[q.id] || ''}
                                                onChange={e => setScreeningAnswer(q.id, e.target.value)}
                                                rows={3}
                                                className="mt-1"
                                            />
                                        )}
                                        {q.type === 'number' && (
                                            <Input
                                                type="number"
                                                value={form.data.screening_answers[q.id] || ''}
                                                onChange={e => setScreeningAnswer(q.id, e.target.value)}
                                                className="mt-1"
                                            />
                                        )}
                                        {q.type === 'date' && (
                                            <Input
                                                type="date"
                                                value={form.data.screening_answers[q.id] || ''}
                                                onChange={e => setScreeningAnswer(q.id, e.target.value)}
                                                className="mt-1"
                                            />
                                        )}
                                        {q.type === 'select' && q.options && (
                                            <select
                                                className="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                value={form.data.screening_answers[q.id] || ''}
                                                onChange={e => setScreeningAnswer(q.id, e.target.value)}
                                                aria-labelledby={`label-${q.id}`}
                                            >
                                                <option value="">Select an option...</option>
                                                {q.options.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                            </select>
                                        )}
                                    </fieldset>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    {/* Application Materials */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Application</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Cover letter</Label>
                                <Textarea
                                    rows={6}
                                    value={form.data.cover_letter}
                                    onChange={e => form.setData('cover_letter', e.target.value)}
                                    className="mt-1"
                                    placeholder="Tell us why you're interested in this role..."
                                />
                            </div>
                            <div>
                                <Label>CV / Resume</Label>
                                <Input
                                    type="file"
                                    accept=".pdf,.doc,.docx"
                                    onChange={e => form.setData('cv', e.target.files?.[0] ?? null)}
                                    className="mt-1"
                                />
                                <p className="text-xs text-muted-foreground mt-1">PDF, DOC, or DOCX (max 10MB)</p>
                                {form.errors.cv && <p className="text-sm text-destructive mt-1">{form.errors.cv}</p>}
                            </div>

                            <div className="flex items-start gap-2 pt-2">
                                <Checkbox
                                    checked={form.data.privacy_consent}
                                    onCheckedChange={checked => form.setData('privacy_consent', Boolean(checked))}
                                    id="privacy-consent"
                                />
                                <Label htmlFor="privacy-consent" className="font-normal text-sm leading-5">
                                    I consent to the collection and processing of my personal information for recruitment purposes in accordance with the Privacy Act 2020.
                                </Label>
                            </div>
                            {form.errors.privacy_consent && <p className="text-sm text-destructive">{form.errors.privacy_consent}</p>}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" size="lg" disabled={form.processing}>
                            {form.processing ? 'Submitting...' : 'Submit Application'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
