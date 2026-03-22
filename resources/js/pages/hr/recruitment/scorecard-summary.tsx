import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Star } from 'lucide-react';

type Criterion = { name: string; rating: number; notes: string };

type Scorecard = {
    id: number;
    interviewer: { id: number; name: string };
    interview: { id: number; interview_type: string; scheduled_at: string };
    criteria: Criterion[];
    overall_rating: number | null;
    recommendation: string;
    strengths: string | null;
    concerns: string | null;
    overall_notes: string | null;
    created_at: string;
};

type CriteriaAverage = { name: string; average: number; count: number };

type Application = {
    id: number;
    position_title: string;
    candidate: { id: number; first_name: string; last_name: string };
};

type Props = {
    application: Application;
    scorecards: Scorecard[];
    criteriaAverages: CriteriaAverage[];
    recommendationCounts: Record<string, number>;
};

const recLabels: Record<string, { label: string; color: string }> = {
    strong_yes: { label: 'Strong Yes', color: 'bg-green-500/10 text-green-500 border-green-500/30' },
    yes: { label: 'Yes', color: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/30' },
    neutral: { label: 'Neutral', color: 'bg-amber-500/10 text-amber-500 border-amber-500/30' },
    no: { label: 'No', color: 'bg-orange-500/10 text-orange-500 border-orange-500/30' },
    strong_no: { label: 'Strong No', color: 'bg-red-500/10 text-red-500 border-red-500/30' },
};

function Stars({ count, max = 5 }: { count: number; max?: number }) {
    return (
        <div className="flex gap-0.5">
            {Array.from({ length: max }, (_, i) => (
                <Star key={i} className={`h-4 w-4 ${i < count ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/20'}`} />
            ))}
        </div>
    );
}

export default function ScorecardSummary({ application, scorecards, criteriaAverages, recommendationCounts }: Props) {
    const candidate = application.candidate;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: `${candidate.first_name} ${candidate.last_name}`, href: `/hr/recruitment/candidates/${candidate.id}` },
                { title: 'Scorecards', href: '#' },
            ]}
        >
            <Head title={`Scorecards - ${candidate.first_name} ${candidate.last_name}`} />
            <PageShell>
                <PageHeader
                    title="Scorecard Summary"
                    description={`${candidate.first_name} ${candidate.last_name} - ${application.position_title} (${scorecards.length} scorecards)`}
                />

                {scorecards.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No scorecards submitted yet for this application.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {/* Aggregated Scores */}
                        <div className="grid gap-4 md:grid-cols-2">
                            <Card>
                                <CardHeader><CardTitle>Average Scores by Criteria</CardTitle></CardHeader>
                                <CardContent className="space-y-3">
                                    {criteriaAverages.map((ca) => (
                                        <div key={ca.name} className="flex items-center justify-between">
                                            <span className="text-sm">{ca.name}</span>
                                            <div className="flex items-center gap-2">
                                                <Stars count={Math.round(ca.average)} />
                                                <span className="text-sm text-muted-foreground">({ca.average})</span>
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader><CardTitle>Recommendation Breakdown</CardTitle></CardHeader>
                                <CardContent className="space-y-3">
                                    {Object.entries(recommendationCounts).map(([rec, count]) => {
                                        const config = recLabels[rec] ?? { label: rec, color: 'bg-muted text-muted-foreground' };
                                        return (
                                            <div key={rec} className="flex items-center justify-between">
                                                <Badge variant="outline" className={config.color}>{config.label}</Badge>
                                                <span className="text-sm font-medium">{count}</span>
                                            </div>
                                        );
                                    })}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Individual Scorecards */}
                        <h3 className="text-lg font-semibold">Individual Scorecards</h3>
                        {scorecards.map((sc) => (
                            <Card key={sc.id}>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">{sc.interviewer.name}</CardTitle>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline" className={recLabels[sc.recommendation]?.color ?? ''}>
                                                {recLabels[sc.recommendation]?.label ?? sc.recommendation}
                                            </Badge>
                                            {sc.overall_rating && <Stars count={sc.overall_rating} />}
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {sc.interview.interview_type} - {new Date(sc.interview.scheduled_at).toLocaleDateString()}
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {sc.criteria.map((c, idx) => (
                                            <div key={idx} className="rounded-lg border p-3">
                                                <div className="flex items-center justify-between mb-1">
                                                    <span className="text-xs font-medium">{c.name}</span>
                                                    <Stars count={c.rating} />
                                                </div>
                                                {c.notes && <p className="text-xs text-muted-foreground">{c.notes}</p>}
                                            </div>
                                        ))}
                                    </div>
                                    {sc.strengths && (
                                        <div>
                                            <span className="text-xs font-semibold text-green-500">Strengths:</span>
                                            <p className="text-sm text-muted-foreground">{sc.strengths}</p>
                                        </div>
                                    )}
                                    {sc.concerns && (
                                        <div>
                                            <span className="text-xs font-semibold text-red-400">Concerns:</span>
                                            <p className="text-sm text-muted-foreground">{sc.concerns}</p>
                                        </div>
                                    )}
                                    {sc.overall_notes && (
                                        <div>
                                            <span className="text-xs font-semibold">Notes:</span>
                                            <p className="text-sm text-muted-foreground">{sc.overall_notes}</p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
