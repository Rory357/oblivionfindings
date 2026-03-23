import { HalfMoonGauge, FLEET_COLORS } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Gauge,
    Minus,
    Route,
    Shield,
    Timer,
    TrendingDown,
    TrendingUp,
    Zap,
} from 'lucide-react';
import { formatDateTime, formatDistance } from '@/lib/fleet-utils';


type Props = {
    driver: {
        id: number;
        name: string;
        email: string | null;
    };
    period: string;
    score: number;
    previous_score: number;
    fleet_avg_score: number;
    metrics: {
        harsh_brakes: number;
        hard_accels: number;
        speeding_events: number;
        idle_minutes: number;
        total_distance_km: number;
    };
    recent_events: Array<{
        id: number;
        type: string;
        severity: string | null;
        occurred_at: string | null;
        asset_id: number;
    }>;
};

function scoreColor(score: number): string {
    if (score >= 80) return FLEET_COLORS.primary;
    if (score >= 60) return FLEET_COLORS.warning;
    return FLEET_COLORS.danger;
}

function eventIcon(type: string) {
    switch (type) {
        case 'harsh_brake': return <AlertTriangle className="h-4 w-4 text-amber-500" />;
        case 'harsh_accel': return <Zap className="h-4 w-4 text-orange-500" />;
        case 'speeding': return <Gauge className="h-4 w-4 text-red-500" />;
        case 'idle': return <Timer className="h-4 w-4 text-blue-500" />;
        default: return <AlertTriangle className="h-4 w-4 text-gray-500" />;
    }
}

export default function DriverScorecard({
    driver,
    period,
    score,
    previous_score,
    fleet_avg_score,
    metrics,
    recent_events,
}: Props) {
    const scoreDiff = score - previous_score;
    const vsFleet = score - fleet_avg_score;

    const changePeriod = (newPeriod: string) => {
        router.get(`/fleet-assets/drivers/${driver.id}/scorecard`, { period: newPeriod }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Drivers', href: '/fleet-assets/drivers' },
                { title: driver.name, href: `/fleet-assets/drivers/${driver.id}` },
                { title: 'Scorecard', href: '#' },
            ]}
        >
            <Head title={`Scorecard: ${driver.name}`} />
            <PageShell>
                <PageHeader
                    title={`Driver Scorecard: ${driver.name}`}
                    description="Safety score and driving behavior analysis."
                    backHref={`/fleet-assets/drivers/${driver.id}`}
                    backLabel="Back to Driver"
                    actions={
                        <Select value={period} onValueChange={changePeriod}>
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="7">Last 7 days</SelectItem>
                                <SelectItem value="30">Last 30 days</SelectItem>
                                <SelectItem value="90">Last 90 days</SelectItem>
                            </SelectContent>
                        </Select>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[auto,1fr]">
                    {/* Score Gauge - HalfMoonGauge */}
                    <Card className="flex flex-col items-center justify-center px-8 py-6">
                        <CardHeader className="pb-2 pt-0">
                            <CardTitle className="text-base text-center">Overall Safety Score</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center justify-center pb-2">
                            <HalfMoonGauge
                                value={score}
                                label={`${score} / 100`}
                                size={200}
                                color={scoreColor(score)}
                            />

                            {/* Trend */}
                            <div className="mt-4 flex items-center gap-2">
                                {scoreDiff > 0 ? (
                                    <Badge variant="default" className="bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300">
                                        <TrendingUp className="mr-1 h-3 w-3" />
                                        +{scoreDiff} vs last period
                                    </Badge>
                                ) : scoreDiff < 0 ? (
                                    <Badge variant="destructive">
                                        <TrendingDown className="mr-1 h-3 w-3" />
                                        {scoreDiff} vs last period
                                    </Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        <Minus className="mr-1 h-3 w-3" />
                                        No change vs last period
                                    </Badge>
                                )}
                            </div>

                            {/* Fleet comparison */}
                            <div className="mt-3 text-sm text-muted-foreground">
                                Fleet average: <span className="font-medium">{fleet_avg_score}</span>
                                {vsFleet > 0 && <span className="text-purple-600 ml-1">(+{vsFleet} above)</span>}
                                {vsFleet < 0 && <span className="text-red-600 ml-1">({vsFleet} below)</span>}
                                {vsFleet === 0 && <span className="text-muted-foreground ml-1">(at average)</span>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Metrics Grid */}
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <AlertTriangle className="h-4 w-4 text-amber-500" />
                                    Harsh Braking
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold text-amber-600">{metrics.harsh_brakes}</div>
                                <p className="text-xs text-muted-foreground mt-1">events this period</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Zap className="h-4 w-4 text-orange-500" />
                                    Hard Acceleration
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold text-orange-600">{metrics.hard_accels}</div>
                                <p className="text-xs text-muted-foreground mt-1">events this period</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Gauge className="h-4 w-4 text-red-500" />
                                    Speeding Events
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold text-red-600">{metrics.speeding_events}</div>
                                <p className="text-xs text-muted-foreground mt-1">events this period</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Timer className="h-4 w-4 text-blue-500" />
                                    Idle Time
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{Math.round(metrics.idle_minutes / 60 * 10) / 10}h</div>
                                <p className="text-xs text-muted-foreground mt-1">{metrics.idle_minutes} minutes total</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Route className="h-4 w-4 text-purple-500" />
                                    Total Distance
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{formatDistance(metrics.total_distance_km)}</div>
                                <p className="text-xs text-muted-foreground mt-1">driven this period</p>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* Recent Driving Events */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Driving Events</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {(recent_events ?? []).length > 0 ? (
                            <div className="space-y-2">
                                {recent_events.map((event) => (
                                    <div key={event.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                        <div className="flex items-center gap-3">
                                            {eventIcon(event.type)}
                                            <div>
                                                <span className="font-medium capitalize">{(event.type ?? '').replace(/_/g, ' ')}</span>
                                                {event.severity && (
                                                    <Badge variant="outline" className="ml-2 text-[10px]">{event.severity}</Badge>
                                                )}
                                            </div>
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {event.occurred_at ? formatDateTime(event.occurred_at) : '---'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <Shield className="h-10 w-10 text-muted-foreground/50 mb-3" />
                                <p className="text-sm text-muted-foreground">No driving events recorded for this period.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
