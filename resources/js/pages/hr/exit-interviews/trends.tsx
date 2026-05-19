import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Star, ThumbsUp, TrendingUp, Users } from 'lucide-react';
import { useMemo } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface Trends {
    departure_reasons: { reason: string; count: number }[];
    satisfaction_over_time: {
        month: string;
        avg_satisfaction: number;
        count: number;
    }[];
    recommend_stats: {
        would_recommend: number;
        would_not_recommend: number;
        total: number;
    };
    overall: { avg_satisfaction: number; total_interviews: number };
}

interface Props {
    trends: Trends;
    filters: { from: string; to: string };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Exit Interviews', href: '/hr/exit-interviews' },
    { title: 'Trends', href: '/hr/exit-interviews/trends' },
];

const reasonLabels: Record<string, string> = {
    career_growth: 'Career Growth',
    compensation: 'Compensation',
    work_life_balance: 'Work-Life Balance',
    management: 'Management Issues',
    culture: 'Company Culture',
    relocation: 'Relocation',
    retirement: 'Retirement',
    personal: 'Personal Reasons',
    redundancy: 'Redundancy',
    contract_end: 'Contract End',
    other: 'Other',
};

const pieColors = [
    '#3b82f6',
    '#10b981',
    '#f59e0b',
    '#ef4444',
    '#8b5cf6',
    '#ec4899',
    '#06b6d4',
    '#84cc16',
    '#f97316',
    '#6366f1',
    '#14b8a6',
];

export default function ExitInterviewTrends({ trends, filters }: Props) {
    const totalReasons = useMemo(
        () => trends.departure_reasons.reduce((sum, r) => sum + r.count, 0),
        [trends.departure_reasons],
    );

    const recommendPct =
        trends.recommend_stats.total > 0
            ? Math.round(
                  (trends.recommend_stats.would_recommend /
                      trends.recommend_stats.total) *
                      100,
              )
            : 0;

    const maxSatisfaction = useMemo(
        () =>
            Math.max(
                ...trends.satisfaction_over_time.map((s) => s.avg_satisfaction),
                5,
            ),
        [trends.satisfaction_over_time],
    );

    function handleFilter(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        router.get(
            '/hr/exit-interviews/trends',
            {
                from: formData.get('from') as string,
                to: formData.get('to') as string,
            },
            { preserveState: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Exit Interview Trends" />

            <PageShell>
                <PageHero
                    icon={TrendingUp}
                    title="Exit Interview Trends"
                    description="Aggregate analysis of departure feedback."
                    stats={[
                        { label: 'Interviews', value: trends.overall.total_interviews },
                        {
                            label: 'Avg satisfaction',
                            value: trends.overall.avg_satisfaction || '-',
                        },
                        { label: 'Would recommend', value: `${recommendPct}%` },
                        { label: 'Unique reasons', value: trends.departure_reasons.length },
                    ]}
                    actions={
                        <Button
                            size="sm"
                            variant="outline"
                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            onClick={() => router.get('/hr/exit-interviews')}
                        >
                            <ArrowLeft className="mr-1.5 h-4 w-4" />
                            Back to List
                        </Button>
                    }
                />

                {/* Date Filter */}
                <form onSubmit={handleFilter} className="mb-4">
                    <Card>
                        <CardContent className="flex flex-wrap items-end gap-4 p-4">
                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    From
                                </Label>
                                <Input
                                    type="date"
                                    name="from"
                                    defaultValue={filters.from}
                                />
                            </div>
                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    To
                                </Label>
                                <Input
                                    type="date"
                                    name="to"
                                    defaultValue={filters.to}
                                />
                            </div>
                            <Button type="submit" size="sm">
                                Apply
                            </Button>
                        </CardContent>
                    </Card>
                </form>

                {/* Summary Cards */}
                <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-info p-2">
                                <Users className="h-5 w-5 text-status-info" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {trends.overall.total_interviews}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Total Interviews
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-warning p-2">
                                <Star className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {trends.overall.avg_satisfaction || '-'}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Avg Satisfaction (1-5)
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-success p-2">
                                <ThumbsUp className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {recommendPct}%
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Would Recommend
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-primary/10 p-2">
                                <TrendingUp className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {trends.departure_reasons.length}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Unique Reasons
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* Departure Reasons */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Departure Reasons
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trends.departure_reasons.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No data available.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {trends.departure_reasons.map(
                                        (item, idx) => {
                                            const pct =
                                                totalReasons > 0
                                                    ? Math.round(
                                                          (item.count /
                                                              totalReasons) *
                                                              100,
                                                      )
                                                    : 0;
                                            return (
                                                <div key={item.reason}>
                                                    <div className="mb-1 flex items-center justify-between text-sm">
                                                        <span>
                                                            {reasonLabels[
                                                                item.reason
                                                            ] ?? item.reason}
                                                        </span>
                                                        <span className="text-muted-foreground">
                                                            {item.count} ({pct}
                                                            %)
                                                        </span>
                                                    </div>
                                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full transition-all"
                                                            style={{
                                                                width: `${pct}%`,
                                                                backgroundColor:
                                                                    pieColors[
                                                                        idx %
                                                                            pieColors.length
                                                                    ],
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        },
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Satisfaction Over Time */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Satisfaction Over Time
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trends.satisfaction_over_time.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No data available.
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {trends.satisfaction_over_time.map(
                                        (item) => {
                                            const pct =
                                                (item.avg_satisfaction / 5) *
                                                100;
                                            return (
                                                <div key={item.month}>
                                                    <div className="mb-1 flex items-center justify-between text-sm">
                                                        <span>
                                                            {item.month}
                                                        </span>
                                                        <span className="text-muted-foreground">
                                                            {
                                                                item.avg_satisfaction
                                                            }
                                                            /5 ({item.count}{' '}
                                                            interviews)
                                                        </span>
                                                    </div>
                                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-status-warning transition-all"
                                                            style={{
                                                                width: `${pct}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        },
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recommendation Split */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Would Recommend
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trends.recommend_stats.total === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No data available.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    <div className="flex items-center gap-4">
                                        <div className="flex-1">
                                            <div className="mb-1 flex justify-between text-sm">
                                                <span>Yes</span>
                                                <span className="text-status-success">
                                                    {
                                                        trends.recommend_stats
                                                            .would_recommend
                                                    }
                                                </span>
                                            </div>
                                            <div className="h-3 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-status-success"
                                                    style={{
                                                        width: `${(trends.recommend_stats.would_recommend / trends.recommend_stats.total) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <div className="flex-1">
                                            <div className="mb-1 flex justify-between text-sm">
                                                <span>No</span>
                                                <span className="text-status-critical">
                                                    {
                                                        trends.recommend_stats
                                                            .would_not_recommend
                                                    }
                                                </span>
                                            </div>
                                            <div className="h-3 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-status-critical"
                                                    style={{
                                                        width: `${(trends.recommend_stats.would_not_recommend / trends.recommend_stats.total) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <p className="text-center text-xs text-muted-foreground">
                                        Based on {trends.recommend_stats.total}{' '}
                                        responses
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
