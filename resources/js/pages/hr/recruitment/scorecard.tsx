import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Star } from 'lucide-react';

type Interview = {
    id: number;
    scheduled_at: string;
    interview_type: string;
    application: {
        id: number;
        position_title: string;
        candidate: { id: number; first_name: string; last_name: string };
    };
};

type Criterion = { name: string; rating: number; notes: string };

type ExistingScorecard = {
    criteria: Criterion[];
    overall_rating: number | null;
    recommendation: string;
    strengths: string | null;
    concerns: string | null;
    overall_notes: string | null;
} | null;

type Props = {
    interview: Interview;
    existing: ExistingScorecard;
};

const defaultCriteria = ['Communication', 'Technical Skills', 'Cultural Fit', 'Problem Solving', 'Leadership'];
const recommendations = [
    { value: 'strong_yes', label: 'Strong Yes' },
    { value: 'yes', label: 'Yes' },
    { value: 'neutral', label: 'Neutral' },
    { value: 'no', label: 'No' },
    { value: 'strong_no', label: 'Strong No' },
];

function StarRating({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    return (
        <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((star) => (
                <button key={star} type="button" onClick={() => onChange(star)} className="focus:outline-none">
                    <Star
                        className={`h-5 w-5 transition-colors ${star <= value ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/30'}`}
                    />
                </button>
            ))}
        </div>
    );
}

export default function ScorecardForm({ interview, existing }: Props) {
    const candidate = interview.application.candidate;
    const [criteria, setCriteria] = useState<Criterion[]>(
        existing?.criteria ??
            defaultCriteria.map((name) => ({ name, rating: 0, notes: '' }))
    );
    const [overallRating, setOverallRating] = useState(existing?.overall_rating ?? 0);
    const [recommendation, setRecommendation] = useState(existing?.recommendation ?? '');
    const [strengths, setStrengths] = useState(existing?.strengths ?? '');
    const [concerns, setConcerns] = useState(existing?.concerns ?? '');
    const [overallNotes, setOverallNotes] = useState(existing?.overall_notes ?? '');
    const [submitting, setSubmitting] = useState(false);

    function updateCriterion(index: number, field: keyof Criterion, value: string | number) {
        setCriteria((prev) => prev.map((c, i) => (i === index ? { ...c, [field]: value } : c)));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        router.post(`/hr/recruitment/interviews/${interview.id}/scorecard`, {
            criteria,
            overall_rating: overallRating || null,
            recommendation,
            strengths: strengths || null,
            concerns: concerns || null,
            overall_notes: overallNotes || null,
        }, { onFinish: () => setSubmitting(false) });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: `${candidate.first_name} ${candidate.last_name}`, href: `/hr/recruitment/candidates/${candidate.id}` },
                { title: 'Scorecard', href: '#' },
            ]}
        >
            <Head title={`Scorecard - ${candidate.first_name} ${candidate.last_name}`} />
            <PageShell>
                <PageHeader
                    title="Interview Scorecard"
                    description={`${candidate.first_name} ${candidate.last_name} - ${interview.application.position_title} (${interview.interview_type})`}
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Criteria */}
                    <Card>
                        <CardHeader><CardTitle>Evaluation Criteria</CardTitle></CardHeader>
                        <CardContent className="space-y-6">
                            {criteria.map((criterion, idx) => (
                                <div key={idx} className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <label className="text-sm font-medium">{criterion.name}</label>
                                        <StarRating value={criterion.rating} onChange={(v) => updateCriterion(idx, 'rating', v)} />
                                    </div>
                                    <Textarea
                                        placeholder={`Notes on ${criterion.name.toLowerCase()}...`}
                                        value={criterion.notes}
                                        onChange={(e) => updateCriterion(idx, 'notes', e.target.value)}
                                        rows={2}
                                    />
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    {/* Overall Assessment */}
                    <Card>
                        <CardHeader><CardTitle>Overall Assessment</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <label className="text-sm font-medium">Overall Rating</label>
                                <StarRating value={overallRating} onChange={setOverallRating} />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1 block">Recommendation</label>
                                <Select value={recommendation} onValueChange={setRecommendation}>
                                    <SelectTrigger><SelectValue placeholder="Select recommendation" /></SelectTrigger>
                                    <SelectContent>
                                        {recommendations.map((r) => (
                                            <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1 block">Strengths</label>
                                <Textarea value={strengths} onChange={(e) => setStrengths(e.target.value)} placeholder="Key strengths observed..." rows={3} />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1 block">Concerns</label>
                                <Textarea value={concerns} onChange={(e) => setConcerns(e.target.value)} placeholder="Any concerns or red flags..." rows={3} />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1 block">Additional Notes</label>
                                <Textarea value={overallNotes} onChange={(e) => setOverallNotes(e.target.value)} placeholder="Any other observations..." rows={3} />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={submitting || !recommendation || criteria.some((c) => c.rating === 0)}>
                            {submitting ? 'Saving...' : existing ? 'Update Scorecard' : 'Submit Scorecard'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
