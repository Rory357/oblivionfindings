import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';
import { OverviewTabsFooter } from '@/components/finance/overview-hub';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    BarChart3,
    CalendarDays,
    ExternalLink,
} from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Category = {
    key: string;
    label: string;
    amount: string;
    count: number;
};

type SiteRow = {
    site: {
        id: number;
        name: string;
        type: string;
        region?: string | null;
    };
    total_cost: string;
    budget: {
        planned: string;
        actual: string;
        variance: string;
        variance_pct: string;
        status: string;
    };
    top_category: Category | null;
    categories: Category[];
    trend: Array<{ month: string; amount: string }>;
    dashboard_url: string;
};

type Props = {
    filters: {
        from: string;
        to: string;
    };
    kpis: {
        total_cost: string;
        sites_over_budget: number;
        site_count: number;
        avg_cost_per_site: string;
        period: {
            from: string;
            to: string;
        };
        top_spenders: SiteRow[];
    };
    sites: SiteRow[];
    categoryKeys: Array<{ key: string; label: string }>;
};

type SortKey = 'site' | 'total_cost' | 'variance_pct' | 'top_category';

const money = (value: string | number) => formatMoney(Number(value));
const pct = (value: string | number) => `${Number(value).toFixed(1)}%`;
const numericSortValue = (row: SiteRow, key: SortKey) => {
    if (key === 'total_cost') return Number(row.total_cost);
    if (key === 'variance_pct') return Number(row.budget.variance_pct);

    return 0;
};

const budgetVariant = (status: string): 'critical' | 'warning' | 'success' | 'neutral' => {
    switch (status) {
        case 'over_budget':
            return 'critical';
        case 'approaching':
            return 'warning';
        case 'under_budget':
            return 'success';
        default:
            return 'neutral';
    }
};

const statusBadge = (status: string) => (
    <StatusBadge variant={budgetVariant(status)} size="sm" label={status === 'on_track' ? 'On Track' : undefined} status={status} />
);

export default function SitesFinancialOverview({
    filters,
    kpis,
    sites,
    categoryKeys,
}: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [sort, setSort] = useState<{
        key: SortKey;
        direction: 'asc' | 'desc';
    }>({ key: 'total_cost', direction: 'desc' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'By site' },
    ];

    const sortedSites = useMemo(() => {
        return [...sites].sort((a, b) => {
            const dir = sort.direction === 'asc' ? 1 : -1;

            if (sort.key === 'site') {
                return a.site.name.localeCompare(b.site.name) * dir;
            }

            if (sort.key === 'top_category') {
                return (
                    (a.top_category?.label ?? '').localeCompare(
                        b.top_category?.label ?? '',
                    ) * dir
                );
            }

            return (
                numericSortValue(a, sort.key) - numericSortValue(b, sort.key)
            ) * dir;
        });
    }, [sites, sort]);

    const chartData = useMemo(() => {
        return sortedSites.map((row) => {
            const data: Record<string, string | number> = {
                site: row.site.name,
            };

            row.categories.forEach((category) => {
                data[category.key] = Number(category.amount);
            });

            return data;
        });
    }, [sortedSites]);

    const submitFilters = () => {
        router.get(
            '/finance/sites',
            { from, to },
            { preserveScroll: true, preserveState: false },
        );
    };

    const updateSort = (key: SortKey) => {
        setSort((current) => ({
            key,
            direction:
                current.key === key && current.direction === 'desc'
                    ? 'asc'
                    : 'desc',
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Site Financials" />

            <PageLayout
                width="wide"
                hero={
                    <PageHero
                        category="finance"
                        icon={BarChart3}
                        title="All-Sites Comparison"
                        description={`Cost, budget variance and category mix across ${kpis.site_count} ${kpis.site_count === 1 ? 'site' : 'sites'} for the selected period.`}
                        stats={[
                            { label: 'Total cost', value: money(kpis.total_cost) },
                            { label: 'Sites over budget', value: kpis.sites_over_budget, tone: kpis.sites_over_budget > 0 ? 'warning' : undefined },
                            { label: 'Avg cost / site', value: money(kpis.avg_cost_per_site) },
                            { label: 'Sites', value: kpis.site_count },
                        ]}
                        footer={<OverviewTabsFooter active="by-site" />}
                        actions={
                            <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                <div className="space-y-1.5">
                                    <Label>From</Label>
                                    <Input
                                        type="date"
                                        value={from}
                                        onChange={(event) =>
                                            setFrom(event.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>To</Label>
                                    <Input
                                        type="date"
                                        value={to}
                                        onChange={(event) => setTo(event.target.value)}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    className="self-end"
                                    onClick={submitFilters}
                                >
                                    <CalendarDays className="h-4 w-4" />
                                    Apply
                                </Button>
                            </div>
                        }
                    />
                }
            >

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Cost by Site and Category
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {chartData.length > 0 && categoryKeys.length > 0 ? (
                            <ResponsiveContainer width="100%" height={360}>
                                <BarChart
                                    data={chartData}
                                    margin={{
                                        top: 12,
                                        right: 16,
                                        left: 8,
                                        bottom: 56,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        className="stroke-muted"
                                    />
                                    <XAxis
                                        dataKey="site"
                                        angle={-30}
                                        textAnchor="end"
                                        interval={0}
                                        height={72}
                                        className="text-xs"
                                    />
                                    <YAxis
                                        tickFormatter={(value) =>
                                            `$${Number(value / 1000).toFixed(0)}k`
                                        }
                                        className="text-xs"
                                    />
                                    <Tooltip
                                        formatter={(value) =>
                                            money(Number(value))
                                        }
                                    />
                                    <Legend />
                                    {categoryKeys.map((category, index) => (
                                        <Bar
                                            key={category.key}
                                            dataKey={category.key}
                                            name={category.label}
                                            stackId="cost"
                                            fill={chartColor(index)}
                                        />
                                    ))}
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyState
                                icon={BarChart3}
                                heading="No cost data for this period"
                                description="Costs appear once journals post against site cost centres inside the selected date range."
                            />
                        )}
                    </CardContent>
                </Card>

                {kpis.top_spenders.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Top Spenders
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                                {kpis.top_spenders.map((row, index) => (
                                    <li
                                        key={row.site.id}
                                        className="flex items-center gap-3 rounded-md border bg-muted/30 px-3 py-2"
                                    >
                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                                            {index + 1}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={row.dashboard_url}
                                                className="block truncate text-sm font-medium hover:underline"
                                            >
                                                {row.site.name}
                                            </Link>
                                            <p className="text-xs text-muted-foreground tabular-nums">
                                                {money(row.total_cost)}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Site Comparison
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortableHead
                                            label="Site"
                                            sortKey="site"
                                            activeSort={sort}
                                            onSort={updateSort}
                                        />
                                        <SortableHead
                                            label="Total Cost"
                                            sortKey="total_cost"
                                            activeSort={sort}
                                            onSort={updateSort}
                                            align="right"
                                        />
                                        <SortableHead
                                            label="vs Budget"
                                            sortKey="variance_pct"
                                            activeSort={sort}
                                            onSort={updateSort}
                                            align="right"
                                        />
                                        <SortableHead
                                            label="Top Category"
                                            sortKey="top_category"
                                            activeSort={sort}
                                            onSort={updateSort}
                                        />
                                        <TableHead>Trend</TableHead>
                                        <TableHead className="text-right">
                                            Dashboard
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sortedSites.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                No sites found for this period
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        sortedSites.map((row) => (
                                            <TableRow key={row.site.id}>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {row.site.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.site.region ??
                                                            row.site.type}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">
                                                    {money(row.total_cost)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex flex-col items-end gap-1">
                                                        <span className="font-medium tabular-nums">
                                                            {pct(
                                                                row.budget
                                                                    .variance_pct,
                                                            )}
                                                        </span>
                                                        {statusBadge(
                                                            row.budget.status,
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {row.top_category ? (
                                                        <div>
                                                            <div className="font-medium">
                                                                {
                                                                    row
                                                                        .top_category
                                                                        .label
                                                                }
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {money(
                                                                    row
                                                                        .top_category
                                                                        .amount,
                                                                )}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Sparkline
                                                        values={row.trend.map(
                                                            (point) =>
                                                                Number(
                                                                    point.amount,
                                                                ),
                                                        )}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={
                                                                row.dashboard_url
                                                            }
                                                        >
                                                            <ExternalLink className="h-4 w-4" />
                                                            Open
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}

function SortableHead({
    label,
    sortKey,
    activeSort,
    onSort,
    align = 'left',
}: {
    label: string;
    sortKey: SortKey;
    activeSort: { key: SortKey; direction: 'asc' | 'desc' };
    onSort: (key: SortKey) => void;
    align?: 'left' | 'right';
}) {
    const active = activeSort.key === sortKey;
    const Icon = activeSort.direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead className={align === 'right' ? 'text-right' : undefined}>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={align === 'right' ? 'ml-auto' : '-ml-3'}
                onClick={() => onSort(sortKey)}
            >
                {label}
                {active && <Icon className="h-3.5 w-3.5" />}
            </Button>
        </TableHead>
    );
}

function Sparkline({ values }: { values: number[] }) {
    if (values.length === 0) {
        return <span className="text-muted-foreground">-</span>;
    }

    const max = Math.max(...values, 1);
    const points = values
        .map((value, index) => {
            const x =
                values.length === 1 ? 48 : (index / (values.length - 1)) * 96;
            const y = 32 - (value / max) * 28;

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <svg
            viewBox="0 0 96 36"
            className="h-9 w-28"
            aria-label="Cost trend"
            role="img"
        >
            <polyline
                points={points}
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                className="text-primary"
            />
        </svg>
    );
}
