import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Star, ChevronDown, ChevronUp, User, ArrowLeft } from 'lucide-react';
import { RadarChart, Radar, PolarGrid, PolarAngleAxis, PolarRadiusAxis, ResponsiveContainer, Tooltip } from 'recharts';

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

function getInitials(name: string) {
    return name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2);
}

export default function ScorecardSummary({ application, scorecards, criteriaAverages, recommendationCounts }: Props) {
    const candidate = application.candidate;
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const [expandedCards, setExpandedCards] = useState<Set<number>>(new Set());

    const toggleCard = (id: number) => {
        setExpandedCards(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const radarData = criteriaAverages.map(ca => ({
        name: ca.name,
        score: Math.round(ca.average * 10) / 10,
        fullMark: 5,
    }));

    const avgOverall = scorecards.length > 0
        ? Math.round((scorecards.reduce((sum, sc) => sum + (sc.overall_rating ?? 0), 0) / scorecards.length) * 10) / 10
        : 0;

    return (
        <AppLayout breadcrumbs={[
            { title: 'HR', href: '/hr' },
            { title: 'Recruitment', href: '/hr/recruitment' },
            { title: fullName, href: `/hr/recruitment/candidates/${candidate.id}` },
            { title: 'Scorecards', href: '#' },
        ]}>
            <Head title={`Scorecards - ${fullName}`} />
            <PageShell>
                <PageHeader
                    title="Scorecard Summary"
                    description={`${fullName} - ${application.position_title} (${scorecards.length} scorecards)`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/hr/recruitment/candidates/${candidate.id}`}>
                                <ArrowLeft className="mr-2 h-4 w-4" />Back to Profile
                            </Link>
                        </Button>
                    }
                />

                {scorecards.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Star className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="text-lg font-medium">No scorecards submitted yet</p>
                            <p className="text-sm">Interview scorecards will appear here once interviewers submit them.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {/* Aggregated Overview */}
                        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {/* Radar Chart */}
                            <Card className="lg:col-span-2">
                                <CardHeader><CardTitle className="text-base">Criteria Averages</CardTitle></CardHeader>
                                <CardContent>
                                    {radarData.length > 2 ? (
                                        <div className="h-64">
                                            <ResponsiveContainer width="100%" height="100%">
                                                <RadarChart data={radarData} cx="50%" cy="50%" outerRadius="70%">
                                                    <PolarGrid className="stroke-muted" />
                                                    <PolarAngleAxis dataKey="name" className="text-xs" tick={{ fill: 'currentColor', fontSize: 11 }} />
                                                    <PolarRadiusAxis angle={30} domain={[0, 5]} tick={{ fill: 'currentColor', fontSize: 10 }} />
                                                    <Radar name="Average Score" dataKey="score" stroke="#3b82f6" fill="#3b82f6" fillOpacity={0.2} />
                                                    <Tooltip />
                                                </RadarChart>
                                            </ResponsiveContainer>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {criteriaAverages.map((ca) => (
                                                <div key={ca.name} className="flex items-center justify-between">
                                                    <span className="text-sm">{ca.name}</span>
                                                    <div className="flex items-center gap-2">
                                                        <Stars count={Math.round(ca.average)} />
                                                        <span className="text-sm text-muted-foreground w-8">({ca.average.toFixed(1)})</span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Recommendation + Overall */}
                            <Card>
                                <CardHeader><CardTitle className="text-base">Consensus</CardTitle></CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="text-center py-3">
                                        <p className="text-xs text-muted-foreground mb-1">Average Rating</p>
                                        <div className="flex items-center justify-center gap-2">
                                            <Stars count={Math.round(avgOverall)} />
                                            <span className="text-lg font-bold">{avgOverall.toFixed(1)}</span>
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <p className="text-xs text-muted-foreground font-medium">Recommendations</p>
                                        {Object.entries(recommendationCounts).map(([rec, count]) => {
                                            const config = recLabels[rec] ?? { label: rec, color: 'bg-muted text-muted-foreground' };
                                            return (
                                                <div key={rec} className="flex items-center justify-between">
                                                    <Badge variant="outline" className={config.color}>{config.label}</Badge>
                                                    <span className="text-sm font-bold">{count}</span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Individual Scorecards */}
                        <h3 className="text-lg font-semibold">Individual Scorecards ({scorecards.length})</h3>
                        <div className="space-y-3">
                            {scorecards.map((sc) => {
                                const isExpanded = expandedCards.has(sc.id);
                                const config = recLabels[sc.recommendation] ?? { label: sc.recommendation, color: '' };
                                return (
                                    <Card key={sc.id} className="overflow-hidden">
                                        <button type="button" onClick={() => toggleCard(sc.id)} className="w-full text-left">
                                            <CardContent className="p-4 flex items-center justify-between gap-4">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                                        {getInitials(sc.interviewer.name)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-medium text-sm">{sc.interviewer.name}</p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {sc.interview.interview_type.replace('_', ' ')} - {new Date(sc.interview.scheduled_at).toLocaleDateString('en-NZ')}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <Badge variant="outline" className={config.color}>{config.label}</Badge>
                                                    {sc.overall_rating && <Stars count={sc.overall_rating} />}
                                                    {isExpanded ? <ChevronUp className="h-4 w-4 text-muted-foreground" /> : <ChevronDown className="h-4 w-4 text-muted-foreground" />}
                                                </div>
                                            </CardContent>
                                        </button>
                                        {isExpanded && (
                                            <div className="px-4 pb-4 border-t pt-4 space-y-4">
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
                                                    <div className="rounded-lg border-l-2 border-l-green-500/50 pl-3 py-1">
                                                        <span className="text-xs font-semibold text-green-500">Strengths:</span>
                                                        <p className="text-sm text-muted-foreground">{sc.strengths}</p>
                                                    </div>
                                                )}
                                                {sc.concerns && (
                                                    <div className="rounded-lg border-l-2 border-l-red-500/50 pl-3 py-1">
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
                                            </div>
                                        )}
                                    </Card>
                                );
                            })}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
