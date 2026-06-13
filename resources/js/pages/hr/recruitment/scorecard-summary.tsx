import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronUp, Star } from 'lucide-react';
import { useState } from 'react';
import {
    PolarAngleAxis,
    PolarGrid,
    PolarRadiusAxis,
    Radar,
    RadarChart,
    ResponsiveContainer,
    Tooltip,
} from 'recharts';

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
    strong_yes: {
        label: 'Strong Yes',
        color: 'bg-status-success-bg text-status-success border-status-success/30',
    },
    yes: {
        label: 'Yes',
        color: 'bg-status-success-bg text-status-success border-status-success/30',
    },
    neutral: {
        label: 'Neutral',
        color: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    },
    no: {
        label: 'No',
        color: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    },
    strong_no: {
        label: 'Strong No',
        color: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    },
};

function Stars({ count, max = 5 }: { count: number; max?: number }) {
    return (
        <div className="flex gap-0.5">
            {Array.from({ length: max }, (_, i) => (
                <Star
                    key={i}
                    className={`h-4 w-4 ${i < count ? 'fill-amber-400 text-status-warning' : 'text-muted-foreground/20'}`}
                />
            ))}
        </div>
    );
}

function getInitials(name: string) {
    return name
        .split(' ')
        .map((p) => p[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export default function ScorecardSummary({
    application,
    scorecards,
    criteriaAverages,
    recommendationCounts,
}: Props) {
    const candidate = application.candidate;
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const [expandedCards, setExpandedCards] = useState<Set<number>>(new Set());

    const toggleCard = (id: number) => {
        setExpandedCards((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const radarData = criteriaAverages.map((ca) => ({
        name: ca.name,
        score: Math.round(ca.average * 10) / 10,
        fullMark: 5,
    }));

    const avgOverall =
        scorecards.length > 0
            ? Math.round(
                  (scorecards.reduce(
                      (sum, sc) => sum + (sc.overall_rating ?? 0),
                      0,
                  ) /
                      scorecards.length) *
                      10,
              ) / 10
            : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                {
                    title: fullName,
                    href: `/hr/recruitment/candidates/${candidate.id}`,
                },
                { title: 'Scorecards', href: '#' },
            ]}
        >
            <Head title={`Scorecards - ${fullName}`} />
            <PageShell>
                <PageHero category="hr" variant="compact"
                    title="Scorecard Summary"
                    description={`${fullName} - ${application.position_title} (${scorecards.length} scorecards)`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={`/hr/recruitment/candidates/${candidate.id}`}
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Profile
                            </Link>
                        </Button>
                    }
                />

                {scorecards.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Star className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="text-lg font-medium">
                                No scorecards submitted yet
                            </p>
                            <p className="text-sm">
                                Interview scorecards will appear here once
                                interviewers submit them.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {/* Aggregated Overview */}
                        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {/* Radar Chart */}
                            <Card className="lg:col-span-2">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Criteria Averages
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {radarData.length > 2 ? (
                                        <div className="h-64">
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <RadarChart
                                                    data={radarData}
                                                    cx="50%"
                                                    cy="50%"
                                                    outerRadius="70%"
                                                >
                                                    <PolarGrid className="stroke-muted" />
                                                    <PolarAngleAxis
                                                        dataKey="name"
                                                        className="text-xs"
                                                        tick={{
                                                            fill: 'currentColor',
                                                            fontSize: 11,
                                                        }}
                                                    />
                                                    <PolarRadiusAxis
                                                        angle={30}
                                                        domain={[0, 5]}
                                                        tick={{
                                                            fill: 'currentColor',
                                                            fontSize: 10,
                                                        }}
                                                    />
                                                    <Radar
                                                        name="Average Score"
                                                        dataKey="score"
                                                        stroke="#3b82f6"
                                                        fill="#3b82f6"
                                                        fillOpacity={0.2}
                                                    />
                                                    <Tooltip />
                                                </RadarChart>
                                            </ResponsiveContainer>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {criteriaAverages.map((ca) => (
                                                <div
                                                    key={ca.name}
                                                    className="flex items-center justify-between"
                                                >
                                                    <span className="text-sm">
                                                        {ca.name}
                                                    </span>
                                                    <div className="flex items-center gap-2">
                                                        <Stars
                                                            count={Math.round(
                                                                ca.average,
                                                            )}
                                                        />
                                                        <span className="w-8 text-sm text-muted-foreground">
                                                            (
                                                            {ca.average.toFixed(
                                                                1,
                                                            )}
                                                            )
                                                        </span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Recommendation + Overall */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Consensus
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="py-3 text-center">
                                        <p className="mb-1 text-xs text-muted-foreground">
                                            Average Rating
                                        </p>
                                        <div className="flex items-center justify-center gap-2">
                                            <Stars
                                                count={Math.round(avgOverall)}
                                            />
                                            <span className="text-lg font-bold">
                                                {avgOverall.toFixed(1)}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <p className="text-xs font-medium text-muted-foreground">
                                            Recommendations
                                        </p>
                                        {Object.entries(
                                            recommendationCounts,
                                        ).map(([rec, count]) => {
                                            const config = recLabels[rec] ?? {
                                                label: rec,
                                                color: 'bg-muted text-muted-foreground',
                                            };
                                            return (
                                                <div
                                                    key={rec}
                                                    className="flex items-center justify-between"
                                                >
                                                    <Badge
                                                        variant="outline"
                                                        className={config.color}
                                                    >
                                                        {config.label}
                                                    </Badge>
                                                    <span className="text-sm font-bold">
                                                        {count}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Individual Scorecards */}
                        <h3 className="text-lg font-semibold">
                            Individual Scorecards ({scorecards.length})
                        </h3>
                        <div className="space-y-3">
                            {scorecards.map((sc) => {
                                const isExpanded = expandedCards.has(sc.id);
                                const config = recLabels[sc.recommendation] ?? {
                                    label: sc.recommendation,
                                    color: '',
                                };
                                return (
                                    <Card
                                        key={sc.id}
                                        className="overflow-hidden"
                                    >
                                        {/* eslint-disable-next-line no-restricted-syntax -- Entire scorecard rows are expandable CardContent, so the raw button preserves that card layout. */}
                                        <button
                                            type="button"
                                            onClick={() => toggleCard(sc.id)}
                                            className="w-full text-left"
                                        >
                                            <CardContent className="flex items-center justify-between gap-4 p-4">
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                                        {getInitials(
                                                            sc.interviewer.name,
                                                        )}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium">
                                                            {
                                                                sc.interviewer
                                                                    .name
                                                            }
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {sc.interview.interview_type.replace(
                                                                '_',
                                                                ' ',
                                                            )}{' '}
                                                            -{' '}
                                                            {new Date(
                                                                sc.interview
                                                                    .scheduled_at,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-3">
                                                    <Badge
                                                        variant="outline"
                                                        className={config.color}
                                                    >
                                                        {config.label}
                                                    </Badge>
                                                    {sc.overall_rating && (
                                                        <Stars
                                                            count={
                                                                sc.overall_rating
                                                            }
                                                        />
                                                    )}
                                                    {isExpanded ? (
                                                        <ChevronUp className="h-4 w-4 text-muted-foreground" />
                                                    ) : (
                                                        <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                    )}
                                                </div>
                                            </CardContent>
                                        </button>
                                        {isExpanded && (
                                            <div className="space-y-4 border-t px-4 pt-4 pb-4">
                                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    {sc.criteria.map(
                                                        (c, idx) => (
                                                            <div
                                                                key={idx}
                                                                className="rounded-lg border p-3"
                                                            >
                                                                <div className="mb-1 flex items-center justify-between">
                                                                    <span className="text-xs font-medium">
                                                                        {c.name}
                                                                    </span>
                                                                    <Stars
                                                                        count={
                                                                            c.rating
                                                                        }
                                                                    />
                                                                </div>
                                                                {c.notes && (
                                                                    <p className="text-xs text-muted-foreground">
                                                                        {
                                                                            c.notes
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                                {sc.strengths && (
                                                    <div className="rounded-lg border-l-2 border-l-green-500/50 py-1 pl-3">
                                                        <span className="text-xs font-semibold text-status-success">
                                                            Strengths:
                                                        </span>
                                                        <p className="text-sm text-muted-foreground">
                                                            {sc.strengths}
                                                        </p>
                                                    </div>
                                                )}
                                                {sc.concerns && (
                                                    <div className="rounded-lg border-l-2 border-l-red-500/50 py-1 pl-3">
                                                        <span className="text-xs font-semibold text-status-critical">
                                                            Concerns:
                                                        </span>
                                                        <p className="text-sm text-muted-foreground">
                                                            {sc.concerns}
                                                        </p>
                                                    </div>
                                                )}
                                                {sc.overall_notes && (
                                                    <div>
                                                        <span className="text-xs font-semibold">
                                                            Notes:
                                                        </span>
                                                        <p className="text-sm text-muted-foreground">
                                                            {sc.overall_notes}
                                                        </p>
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
