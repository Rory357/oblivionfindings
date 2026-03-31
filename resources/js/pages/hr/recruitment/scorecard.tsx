import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Star, User, Calendar, Briefcase, CheckCircle2 } from 'lucide-react';

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

type Props = { interview: Interview; existing: ExistingScorecard };

const defaultCriteria = ['Communication', 'Technical Skills', 'Cultural Fit', 'Problem Solving', 'Leadership'];
const recommendations = [
    { value: 'strong_yes', label: 'Strong Yes', color: 'text-green-500' },
    { value: 'yes', label: 'Yes', color: 'text-emerald-500' },
    { value: 'neutral', label: 'Neutral', color: 'text-amber-500' },
    { value: 'no', label: 'No', color: 'text-orange-500' },
    { value: 'strong_no', label: 'Strong No', color: 'text-red-500' },
];

function StarRating({ value, onChange, size = 'md' }: { value: number; onChange: (v: number) => void; size?: 'sm' | 'md' | 'lg' }) {
    const sizeClass = size === 'lg' ? 'h-7 w-7' : size === 'sm' ? 'h-4 w-4' : 'h-5 w-5';
    return (
        <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((star) => (
                <button key={star} type="button" onClick={() => onChange(star)} className="focus:outline-none hover:scale-110 transition-transform">
                    <Star className={`${sizeClass} transition-colors ${star <= value ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/20 hover:text-muted-foreground/50'}`} />
                </button>
            ))}
        </div>
    );
}

export default function ScorecardForm({ interview, existing }: Props) {
    const candidate = interview.application.candidate;
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const initials = ((candidate.first_name?.[0] ?? '') + (candidate.last_name?.[0] ?? '')).toUpperCase();

    const [criteria, setCriteria] = useState<Criterion[]>(
        existing?.criteria ?? defaultCriteria.map((name) => ({ name, rating: 0, notes: '' }))
    );
    const [overallRating, setOverallRating] = useState(existing?.overall_rating ?? 0);
    const [recommendation, setRecommendation] = useState(existing?.recommendation ?? '');
    const [strengths, setStrengths] = useState(existing?.strengths ?? '');
    const [concerns, setConcerns] = useState(existing?.concerns ?? '');
    const [overallNotes, setOverallNotes] = useState(existing?.overall_notes ?? '');
    const [submitting, setSubmitting] = useState(false);

    const ratedCount = criteria.filter(c => c.rating > 0).length;
    const progressPct = Math.round((ratedCount / criteria.length) * 100);

    function updateCriterion(index: number, field: keyof Criterion, value: string | number) {
        setCriteria((prev) => prev.map((c, i) => (i === index ? { ...c, [field]: value } : c)));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        router.post(`/hr/recruitment/interviews/${interview.id}/scorecard`, {
            criteria, overall_rating: overallRating || null, recommendation,
            strengths: strengths || null, concerns: concerns || null, overall_notes: overallNotes || null,
        }, { onFinish: () => setSubmitting(false) });
    }

    return (
        <AppLayout breadcrumbs={[
            { title: 'HR', href: '/hr' },
            { title: 'Recruitment', href: '/hr/recruitment' },
            { title: fullName, href: `/hr/recruitment/candidates/${candidate.id}` },
            { title: 'Scorecard', href: '#' },
        ]}>
            <Head title={`Scorecard - ${fullName}`} />
            <PageShell>
                <PageHeader title="Interview Scorecard" description={`Evaluate ${fullName} for ${interview.application.position_title}`} />

                {/* Candidate Banner */}
                <Card className="bg-gradient-to-r from-primary/5 to-transparent">
                    <CardContent className="p-4 flex items-center gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-lg font-bold text-primary">
                            {initials}
                        </div>
                        <div className="flex-1">
                            <h3 className="font-semibold">{fullName}</h3>
                            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground mt-0.5">
                                <span className="flex items-center gap-1"><Briefcase className="h-3 w-3" />{interview.application.position_title}</span>
                                <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{interview.scheduled_at}</span>
                                <Badge variant="outline" className="text-xs capitalize">{interview.interview_type.replace('_', ' ')}</Badge>
                            </div>
                        </div>
                        <div className="text-right shrink-0">
                            <div className="text-xs text-muted-foreground mb-1">{ratedCount} of {criteria.length} rated</div>
                            <div className="w-24 h-2 bg-muted/30 rounded-full overflow-hidden">
                                <div className={`h-full rounded-full transition-all duration-300 ${progressPct === 100 ? 'bg-green-500' : 'bg-primary'}`} style={{ width: `${progressPct}%` }} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Criteria Cards */}
                    {criteria.map((criterion, idx) => (
                        <Card key={idx} className={`transition-all ${criterion.rating > 0 ? 'border-primary/20' : ''}`}>
                            <CardContent className="p-5">
                                <div className="flex items-center justify-between mb-3">
                                    <div className="flex items-center gap-2">
                                        {criterion.rating > 0 && <CheckCircle2 className="h-4 w-4 text-green-500" />}
                                        <label className="font-medium">{criterion.name}</label>
                                    </div>
                                    <StarRating value={criterion.rating} onChange={(v) => updateCriterion(idx, 'rating', v)} size="lg" />
                                </div>
                                <Textarea
                                    placeholder={`Notes on ${criterion.name.toLowerCase()}...`}
                                    value={criterion.notes}
                                    onChange={(e) => updateCriterion(idx, 'notes', e.target.value)}
                                    rows={2}
                                    className="resize-none"
                                />
                            </CardContent>
                        </Card>
                    ))}

                    {/* Overall Assessment */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Overall Assessment</CardTitle></CardHeader>
                        <CardContent className="space-y-5">
                            <div className="flex items-center justify-between">
                                <label className="font-medium">Overall Rating</label>
                                <StarRating value={overallRating} onChange={setOverallRating} size="lg" />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">Recommendation *</label>
                                <Select value={recommendation} onValueChange={setRecommendation}>
                                    <SelectTrigger><SelectValue placeholder="Select recommendation" /></SelectTrigger>
                                    <SelectContent>
                                        {recommendations.map((r) => (
                                            <SelectItem key={r.value} value={r.value}>
                                                <span className={r.color}>{r.label}</span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">Strengths</label>
                                <Textarea value={strengths} onChange={(e) => setStrengths(e.target.value)} placeholder="Key strengths observed..." rows={3} className="border-l-2 border-l-green-500/50" />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">Concerns</label>
                                <Textarea value={concerns} onChange={(e) => setConcerns(e.target.value)} placeholder="Any concerns or red flags..." rows={3} className="border-l-2 border-l-red-500/50" />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">Additional Notes</label>
                                <Textarea value={overallNotes} onChange={(e) => setOverallNotes(e.target.value)} placeholder="Other observations..." rows={3} />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sticky Submit */}
                    <div className="sticky bottom-0 flex justify-end gap-3 border-t bg-background/95 backdrop-blur py-4 -mx-6 px-6">
                        <Button type="submit" disabled={submitting || !recommendation || criteria.some((c) => c.rating === 0)} className="min-w-[160px]">
                            {submitting ? 'Saving...' : existing ? 'Update Scorecard' : 'Submit Scorecard'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
