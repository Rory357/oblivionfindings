import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Head, router } from '@inertiajs/react';
import { Briefcase, Calendar, CheckCircle2, ClipboardCheck, Star } from 'lucide-react';
import { useState } from 'react';

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

const defaultCriteria = [
    'Communication',
    'Technical Skills',
    'Cultural Fit',
    'Problem Solving',
    'Leadership',
];
const recommendations = [
    { value: 'strong_yes', label: 'Strong Yes', color: 'text-status-success' },
    { value: 'yes', label: 'Yes', color: 'text-status-success' },
    { value: 'neutral', label: 'Neutral', color: 'text-status-warning' },
    { value: 'no', label: 'No', color: 'text-status-warning' },
    { value: 'strong_no', label: 'Strong No', color: 'text-status-critical' },
];

function StarRating({
    value,
    onChange,
    size = 'md',
}: {
    value: number;
    onChange: (v: number) => void;
    size?: 'sm' | 'md' | 'lg';
}) {
    const sizeClass =
        size === 'lg' ? 'h-7 w-7' : size === 'sm' ? 'h-4 w-4' : 'h-5 w-5';
    return (
        <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((star) => (
                <Button
                    key={star}
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => onChange(star)}
                    className="h-auto w-auto p-0 hover:scale-110 hover:bg-transparent"
                >
                    <Star
                        className={`${sizeClass} transition-colors ${star <= value ? 'fill-amber-400 text-status-warning' : 'text-muted-foreground/20 hover:text-muted-foreground/50'}`}
                    />
                </Button>
            ))}
        </div>
    );
}

export default function ScorecardForm({ interview, existing }: Props) {
    const candidate = interview.application.candidate;
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const initials = (
        (candidate.first_name?.[0] ?? '') + (candidate.last_name?.[0] ?? '')
    ).toUpperCase();

    const [criteria, setCriteria] = useState<Criterion[]>(
        existing?.criteria ??
            defaultCriteria.map((name) => ({ name, rating: 0, notes: '' })),
    );
    const [overallRating, setOverallRating] = useState(
        existing?.overall_rating ?? 0,
    );
    const [recommendation, setRecommendation] = useState(
        existing?.recommendation ?? '',
    );
    const [strengths, setStrengths] = useState(existing?.strengths ?? '');
    const [concerns, setConcerns] = useState(existing?.concerns ?? '');
    const [overallNotes, setOverallNotes] = useState(
        existing?.overall_notes ?? '',
    );
    const [submitting, setSubmitting] = useState(false);

    const ratedCount = criteria.filter((c) => c.rating > 0).length;
    const progressPct = Math.round((ratedCount / criteria.length) * 100);

    function updateCriterion(
        index: number,
        field: keyof Criterion,
        value: string | number,
    ) {
        setCriteria((prev) =>
            prev.map((c, i) => (i === index ? { ...c, [field]: value } : c)),
        );
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            `/hr/recruitment/interviews/${interview.id}/scorecard`,
            {
                criteria,
                overall_rating: overallRating || null,
                recommendation,
                strengths: strengths || null,
                concerns: concerns || null,
                overall_notes: overallNotes || null,
            },
            { onFinish: () => setSubmitting(false) },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                {
                    title: fullName,
                    href: `/hr/recruitment/candidates/${candidate.id}`,
                },
                { title: 'Scorecard', href: '#' },
            ]}
        >
            <Head title={`Scorecard - ${fullName}`} />
            <PageShell>
                <PageHero category="hr"
                    icon={ClipboardCheck}
                    title="Interview Scorecard"
                    description={`Evaluate ${fullName} for ${interview.application.position_title}`}
                    stats={[
                        { label: 'Criteria', value: criteria.length },
                        { label: 'Rated', value: ratedCount },
                        { label: 'Progress', value: `${progressPct}%` },
                    ]}
                />

                {/* Candidate Banner */}
                <Card className="bg-gradient-to-r from-primary/5 to-transparent">
                    <CardContent className="flex items-center gap-4 p-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-lg font-bold text-primary">
                            {initials}
                        </div>
                        <div className="flex-1">
                            <h3 className="font-semibold">{fullName}</h3>
                            <div className="mt-0.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <Briefcase className="h-3 w-3" />
                                    {interview.application.position_title}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Calendar className="h-3 w-3" />
                                    {interview.scheduled_at}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="text-xs capitalize"
                                >
                                    {interview.interview_type.replace('_', ' ')}
                                </Badge>
                            </div>
                        </div>
                        <div className="shrink-0 text-right">
                            <div className="mb-1 text-xs text-muted-foreground">
                                {ratedCount} of {criteria.length} rated
                            </div>
                            <div className="h-2 w-24 overflow-hidden rounded-full bg-muted/30">
                                <div
                                    className={`h-full rounded-full transition-all duration-300 ${progressPct === 100 ? 'bg-status-success' : 'bg-primary'}`}
                                    style={{ width: `${progressPct}%` }}
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Criteria Cards */}
                    {criteria.map((criterion, idx) => (
                        <Card
                            key={idx}
                            className={`transition-all ${criterion.rating > 0 ? 'border-primary/20' : ''}`}
                        >
                            <CardContent className="p-5">
                                <div className="mb-3 flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        {criterion.rating > 0 && (
                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                        )}
                                        <label className="font-medium">
                                            {criterion.name}
                                        </label>
                                    </div>
                                    <StarRating
                                        value={criterion.rating}
                                        onChange={(v) =>
                                            updateCriterion(idx, 'rating', v)
                                        }
                                        size="lg"
                                    />
                                </div>
                                <Textarea
                                    placeholder={`Notes on ${criterion.name.toLowerCase()}...`}
                                    value={criterion.notes}
                                    onChange={(e) =>
                                        updateCriterion(
                                            idx,
                                            'notes',
                                            e.target.value,
                                        )
                                    }
                                    rows={2}
                                    className="resize-none"
                                />
                            </CardContent>
                        </Card>
                    ))}

                    {/* Overall Assessment */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Overall Assessment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="flex items-center justify-between">
                                <label className="font-medium">
                                    Overall Rating
                                </label>
                                <StarRating
                                    value={overallRating}
                                    onChange={setOverallRating}
                                    size="lg"
                                />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Recommendation *
                                </label>
                                <Select
                                    value={recommendation}
                                    onValueChange={setRecommendation}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select recommendation" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {recommendations.map((r) => (
                                            <SelectItem
                                                key={r.value}
                                                value={r.value}
                                            >
                                                <span className={r.color}>
                                                    {r.label}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Strengths
                                </label>
                                <Textarea
                                    value={strengths}
                                    onChange={(e) =>
                                        setStrengths(e.target.value)
                                    }
                                    placeholder="Key strengths observed..."
                                    rows={3}
                                    className="border-l-2 border-l-green-500/50"
                                />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Concerns
                                </label>
                                <Textarea
                                    value={concerns}
                                    onChange={(e) =>
                                        setConcerns(e.target.value)
                                    }
                                    placeholder="Any concerns or red flags..."
                                    rows={3}
                                    className="border-l-2 border-l-red-500/50"
                                />
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Additional Notes
                                </label>
                                <Textarea
                                    value={overallNotes}
                                    onChange={(e) =>
                                        setOverallNotes(e.target.value)
                                    }
                                    placeholder="Other observations..."
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sticky Submit */}
                    <div className="sticky bottom-0 -mx-6 flex justify-end gap-3 border-t bg-background/95 px-6 py-4 backdrop-blur">
                        <Button
                            type="submit"
                            disabled={
                                submitting ||
                                !recommendation ||
                                criteria.some((c) => c.rating === 0)
                            }
                            className="min-w-[160px]"
                        >
                            {submitting
                                ? 'Saving...'
                                : existing
                                  ? 'Update Scorecard'
                                  : 'Submit Scorecard'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
