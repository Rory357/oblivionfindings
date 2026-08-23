import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { useState } from 'react';
import { TrendChartsGrid, type TrendSetsMap } from './components/trend-charts';

type ClientRef = {
    id: number;
    first_name: string;
    last_name: string;
};

type Filters = {
    date_from: string;
    date_to: string;
};

type Props = {
    client: ClientRef;
    filters: Filters;
    trend_sets: TrendSetsMap;
    has_chartable_data: boolean;
    chartable_observation_count: number;
};

function defaultDateRange(): Filters {
    const now = new Date();
    const from = new Date(now);
    from.setDate(now.getDate() - 29);

    const toDateInput = (value: Date) => {
        const year = value.getFullYear();
        const month = String(value.getMonth() + 1).padStart(2, '0');
        const day = String(value.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    return {
        date_from: toDateInput(from),
        date_to: toDateInput(now),
    };
}

export default function ClientTrends({
    client,
    filters,
    trend_sets,
    has_chartable_data,
    chartable_observation_count,
}: Props) {
    const [localFilters, setLocalFilters] = useState<Filters>(filters);
    const clientName = `${client.first_name} ${client.last_name}`;

    const applyFilters = () => {
        router.get(
            `/health-clinical/clients/${client.id}/trends`,
            localFilters,
            {
                replace: true,
            },
        );
    };

    const resetFilters = () => {
        const next = defaultDateRange();

        setLocalFilters(next);
        router.get(
            `/health-clinical/clients/${client.id}/trends`,
            {},
            {
                replace: true,
            },
        );
    };

    return (
        <AppLayout>
            <Head title={`Observation Trends — ${clientName}`} />

            <PageLayout
                hero={
                    <PageHero
                        pageType="task"
                        backHref={`/health-clinical/clients/${client.id}/summary`}
                        title="Observation Trends"
                        description={`${clientName} with chartable observation data over time.`}
                        actions={
                            <>
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="frontline-tap"
                                >
                                    <Link
                                        href={`/health-clinical/clients/${client.id}/summary`}
                                    >
                                        Health Summary
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="frontline-tap"
                                >
                                    <Link
                                        href={`/operations/clients/${client.id}`}
                                    >
                                        Client Profile
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="frontline-tap"
                                >
                                    <Link
                                        href={`/health-clinical/observations?client_id=${client.id}`}
                                    >
                                        Observation Register
                                    </Link>
                                </Button>
                            </>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Date Range</CardTitle>
                        <CardDescription>
                            Last 30 days is the default window for this trends
                            view.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="date_from" className="text-xs">
                                    From
                                </Label>
                                <Input
                                    id="date_from"
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_from: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="date_to" className="text-xs">
                                    To
                                </Label>
                                <Input
                                    id="date_to"
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_to: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button size="sm" onClick={applyFilters}>
                                    Apply
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={resetFilters}
                                >
                                    Reset
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {!has_chartable_data ? (
                    <Card>
                        <CardContent className="flex h-[220px] flex-col items-center justify-center gap-2 text-center">
                            <Activity className="text-muted-foreground h-8 w-8" />
                            <h2 className="text-lg font-semibold">
                                No chartable observations in this range
                            </h2>
                            <p className="text-muted-foreground max-w-lg text-sm">
                                Try a wider date range or review the observation
                                register for non-chartable entries such as
                                general notes or sleep logs.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <TrendChartsGrid trendSets={trend_sets} />
                )}

                <Card>
                    <CardContent className="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p className="text-sm font-medium">
                                Chartable observations in range
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {chartable_observation_count} entries across
                                weight, pain, vitals, and fluid intake.
                            </p>
                        </div>
                        <Link
                            href={`/health-clinical/observations?client_id=${client.id}&date_from=${filters.date_from}&date_to=${filters.date_to}`}
                        >
                            <Button size="sm" variant="outline">
                                View matching register entries
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
