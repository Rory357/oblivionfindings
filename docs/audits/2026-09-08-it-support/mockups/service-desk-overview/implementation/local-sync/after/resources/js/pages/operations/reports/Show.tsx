import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, FileBarChart, Users } from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Option = {
    id: number;
    name: string;
};

type Props = {
    report_type: string;
    report_meta: { name: string; description: string };
    data: Record<string, any>;
    filters: {
        date_from?: string;
        date_to?: string;
        client_id?: string | null;
        staff_id?: string | null;
    };
    clients: Option[];
    staff: Option[];
};

export default function ReportShow({
    report_type,
    report_meta,
    data,
    filters,
    clients,
    staff,
}: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const [search, setSearch] = useState('');
    const usesClientFilter = clients.length > 0;
    const usesStaffFilter = staff.length > 0;

    const applyServer = (overrides: Partial<Record<string, string>>) => {
        router.get(
            `/operations/reports/${report_type}`,
            {
                date_from: overrides.date_from ?? filters?.date_from ?? '',
                date_to: overrides.date_to ?? filters?.date_to ?? '',
                client_id: usesClientFilter
                    ? (overrides.client_id ??
                          String(filters?.client_id ?? '')) ||
                      undefined
                    : undefined,
                staff_id: usesStaffFilter
                    ? (overrides.staff_id ?? String(filters?.staff_id ?? '')) ||
                      undefined
                    : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    // Period presets — raw date params stay in the URL so deep-linked
    // custom ranges keep working and read as "Custom range".
    const isoDate = (d: Date) => d.toISOString().split('T')[0];
    const today = new Date();
    const presets: Record<string, { from: string; to: string; label: string }> =
        {
            last7: {
                from: isoDate(new Date(today.getTime() - 6 * 86400000)),
                to: isoDate(today),
                label: 'Last 7 days',
            },
            last30: {
                from: isoDate(new Date(today.getTime() - 29 * 86400000)),
                to: isoDate(today),
                label: 'Last 30 days',
            },
            this_month: {
                from: isoDate(
                    new Date(today.getFullYear(), today.getMonth(), 1),
                ),
                to: isoDate(today),
                label: 'This month',
            },
            last_month: {
                from: isoDate(
                    new Date(today.getFullYear(), today.getMonth() - 1, 1),
                ),
                to: isoDate(new Date(today.getFullYear(), today.getMonth(), 0)),
                label: 'Last month',
            },
        };
    const currentPreset =
        Object.entries(presets).find(
            ([, p]) =>
                p.from === (filters?.date_from ?? '') &&
                p.to === (filters?.date_to ?? ''),
        )?.[0] ?? 'custom';
    const periodOptions = [
        ...Object.entries(presets).map(([value, p]) => ({
            value,
            label: p.label,
        })),
        ...(currentPreset === 'custom'
            ? [
                  {
                      value: 'custom',
                      label:
                          filters?.date_from && filters?.date_to
                              ? `${filters.date_from} – ${filters.date_to}`
                              : 'All time',
                  },
              ]
            : []),
    ];

    const matchesSearch = (row: any) => {
        const q = search.trim().toLowerCase();
        if (!q) return true;
        return JSON.stringify(row).toLowerCase().includes(q);
    };

    const renderValue = (value: any): string => {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'number') {
            if (Number.isInteger(value)) return value.toLocaleString('en-NZ');
            return value.toLocaleString('en-NZ', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 2,
            });
        }
        if (typeof value === 'string') return value;
        return JSON.stringify(value);
    };

    const renderSummaryCards = () => {
        const summaryKeys = Object.entries(data).filter(
            ([, v]) => typeof v !== 'object' || v === null,
        );
        if (summaryKeys.length === 0) return null;

        return (
            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {summaryKeys.map(([key, value]) => (
                    <Card key={key}>
                        <CardContent className="pt-4">
                            <p className="text-2xl font-bold">
                                {typeof value === 'number' &&
                                (key.includes('amount') ||
                                    key.includes('billed') ||
                                    key.includes('budget') ||
                                    key.includes('claimed'))
                                    ? `$${value.toLocaleString('en-NZ', { minimumFractionDigits: 2 })}`
                                    : typeof value === 'number' &&
                                        key.includes('rate')
                                      ? `${value}%`
                                      : renderValue(value)}
                            </p>
                            <p className="text-xs text-muted-foreground capitalize">
                                {key.replace(/_/g, ' ')}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>
        );
    };

    const renderTable = (allItems: any[], label: string) => {
        if (!allItems?.length) return null;
        const items = allItems.filter(matchesSearch);
        if (!items.length) return null;

        const sample = items[0];
        const columns = Object.keys(sample).filter(
            (k) => typeof sample[k] !== 'object' || sample[k] === null,
        );

        return (
            <Card className="mb-4">
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium capitalize">
                        {label.replace(/_/g, ' ')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                {report_meta.name} {label.replace(/_/g, ' ')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                    {columns.map((col) => (
                                        <th
                                            key={col}
                                            className="pr-4 pb-2 capitalize"
                                        >
                                            {col.replace(/_/g, ' ')}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item, idx) => (
                                    <tr
                                        key={idx}
                                        className="border-b last:border-0"
                                    >
                                        {columns.map((col) => (
                                            <td
                                                key={col}
                                                className="py-2 pr-4 text-xs"
                                            >
                                                {typeof item[col] ===
                                                    'number' &&
                                                (col.includes('amount') ||
                                                    col.includes(
                                                        'total_amount',
                                                    ))
                                                    ? `$${item[col].toLocaleString('en-NZ', { minimumFractionDigits: 2 })}`
                                                    : renderValue(item[col])}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        );
    };

    const renderObjectTable = (obj: Record<string, any>, label: string) => {
        const entries = Object.entries(obj);
        if (entries.length === 0) return null;
        const firstValue = entries[0]?.[1];

        // If values are objects, render as grouped
        if (typeof firstValue === 'object' && firstValue !== null) {
            const subKeys = Object.keys(firstValue);
            return (
                <Card className="mb-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium capitalize">
                            {label.replace(/_/g, ' ')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    {report_meta.name}{' '}
                                    {label.replace(/_/g, ' ')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                        <th className="pr-4 pb-2">Type</th>
                                        {subKeys.map((k) => (
                                            <th
                                                key={k}
                                                className="pr-4 pb-2 capitalize"
                                            >
                                                {k.replace(/_/g, ' ')}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map(([key, val]) => (
                                        <tr
                                            key={key}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 pr-4 text-xs font-medium capitalize">
                                                {key.replace(/_/g, ' ')}
                                            </td>
                                            {subKeys.map((sk) => (
                                                <td
                                                    key={sk}
                                                    className="py-2 pr-4 text-xs"
                                                >
                                                    {renderValue(
                                                        (val as any)[sk],
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            );
        }

        // Simple key-value
        return (
            <Card className="mb-4">
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium capitalize">
                        {label.replace(/_/g, ' ')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="space-y-1">
                        {entries.map(([key, val]) => (
                            <div
                                key={key}
                                className="flex items-center justify-between text-xs"
                            >
                                <span className="text-muted-foreground capitalize">
                                    {key.replace(/_/g, ' ')}
                                </span>
                                <Badge variant="secondary">
                                    {renderValue(val)}
                                </Badge>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
        );
    };

    const renderDataSections = () => {
        const sections = Object.entries(data).filter(
            ([, v]) => typeof v === 'object' && v !== null,
        );
        if (sections.length === 0) return null;

        return sections.map(([key, value]) => {
            if (Array.isArray(value)) {
                return renderTable(value, key);
            }
            return renderObjectTable(value as Record<string, any>, key);
        });
    };

    const reportChart = () => {
        let chartData: Array<Record<string, string | number>> = [];
        const xKey = 'name';
        const yKey = 'value';
        let title = '';

        if (report_type === 'billing' && Array.isArray(data.by_status)) {
            title = 'Billing by Status';
            chartData = data.by_status.map((row: any) => ({
                name: row.status ?? 'Unknown',
                value: Number(row.total_amount ?? 0),
            }));
        }

        if (
            report_type === 'staff-utilisation' &&
            Array.isArray(data.by_staff)
        ) {
            title = 'Hours by Staff';
            chartData = data.by_staff.map((row: any) => ({
                name: row.staff_name ?? `Staff ${row.user_id}`,
                value: Number(row.total_hours ?? 0),
            }));
        }

        if (
            report_type === 'shift-analytics' &&
            data.by_day_of_week &&
            typeof data.by_day_of_week === 'object'
        ) {
            title = 'Shifts by Day of Week';
            chartData = Object.entries(data.by_day_of_week).map(
                ([day, count]) => ({
                    name: day,
                    value: Number(count ?? 0),
                }),
            );
        }

        chartData = chartData.filter((row) => Number(row[yKey]) > 0);
        if (chartData.length === 0) return null;

        return (
            <Card
                className="mb-4"
                data-test="operations-report-chart"
                data-testid="operations-report-chart"
            >
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        {title}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-72">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={chartData}>
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis
                                    dataKey={xKey}
                                    tick={{ fontSize: 12 }}
                                    interval={0}
                                />
                                <YAxis tick={{ fontSize: 12 }} />
                                <Tooltip />
                                <Bar
                                    dataKey={yKey}
                                    fill="hsl(var(--primary))"
                                />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardContent>
            </Card>
        );
    };

    const hasData = data && Object.keys(data).length > 0;

    const clientOptions = [
        { value: 'all', label: `All ${clientSingular.toLowerCase()}s` },
        ...clients.map((c) => ({ value: String(c.id), label: c.name })),
    ];
    const staffOptions = [
        { value: 'all', label: 'All staff' },
        ...staff.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/reports"
            icon={FileBarChart}
            title={report_meta?.name ?? 'Report'}
            titleChip={
                hasData ? (
                    <PageHeaderStatusChip variant="success">
                        Data available
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        No data
                    </PageHeaderStatusChip>
                )
            }
            subline={
                report_meta?.description ??
                `Operational report for ${report_type.replace(/-/g, ' ')}`
            }
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search report rows…"
                />
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={CalendarRange}
                        label="Period"
                        value={currentPreset}
                        allValue="__none__"
                        options={periodOptions}
                        onChange={(v) => {
                            const preset = presets[v];
                            if (preset) {
                                applyServer({
                                    date_from: preset.from,
                                    date_to: preset.to,
                                });
                            }
                        }}
                    />
                    {usesClientFilter ? (
                        <PageHeaderFilterSelect
                            icon={Users}
                            label={`All ${clientSingular.toLowerCase()}s`}
                            value={String(filters?.client_id ?? 'all') || 'all'}
                            options={clientOptions}
                            onChange={(v) =>
                                applyServer({
                                    client_id: v === 'all' ? '' : v,
                                })
                            }
                        />
                    ) : null}
                    {usesStaffFilter ? (
                        <PageHeaderFilterSelect
                            icon={Users}
                            label="All staff"
                            value={String(filters?.staff_id ?? 'all') || 'all'}
                            options={staffOptions}
                            onChange={(v) =>
                                applyServer({
                                    staff_id: v === 'all' ? '' : v,
                                })
                            }
                        />
                    ) : null}
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Reports', href: '/operations/reports' },
                {
                    title: report_meta?.name ?? 'Report',
                    href: `/operations/reports/${report_type}`,
                },
            ]}
        >
            <Head title={report_meta?.name ?? 'Report'} />

            <PageLayout hero={header}>
                {hasData ? (
                    <div>
                        {renderSummaryCards()}
                        {reportChart()}
                        {renderDataSections()}
                    </div>
                ) : (
                    <EmptyState
                        icon={FileBarChart}
                        title={`No ${report_meta?.name ?? 'report'} data available`}
                        description="Select a date range and filters to generate this report. Data will populate as operational activity is recorded."
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
