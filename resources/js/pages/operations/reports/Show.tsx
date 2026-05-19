import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { FileBarChart } from 'lucide-react';
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
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');
    const [clientId, setClientId] = useState(filters?.client_id ?? '');
    const [staffId, setStaffId] = useState(filters?.staff_id ?? '');
    const usesClientFilter = clients.length > 0;
    const usesStaffFilter = staff.length > 0;

    const handleFilter = () => {
        router.get(
            `/operations/reports/${report_type}`,
            {
                date_from: dateFrom,
                date_to: dateTo,
                client_id: usesClientFilter ? clientId || undefined : undefined,
                staff_id: usesStaffFilter ? staffId || undefined : undefined,
            },
            { preserveState: true },
        );
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

    const renderTable = (items: any[], label: string) => {
        if (!items?.length) return null;

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
                                    {report_meta.name} {label.replace(/_/g, ' ')}
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
                                <Bar dataKey={yKey} fill="hsl(var(--primary))" />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardContent>
            </Card>
        );
    };

    const hasData = data && Object.keys(data).length > 0;

    return (
        <AppLayout>
            <Head title={report_meta?.name ?? 'Report'} />
            <PageHero variant="compact"
                title={report_meta?.name ?? 'Report'}
                description={
                    report_meta?.description ??
                    `Operational report for ${report_type.replace(/-/g, ' ')}.`
                }
                backHref="/operations/reports"
            />
            <PageShell>
                {/* Filter controls */}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Input
                        type="date"
                        className="h-9 w-[160px] text-xs"
                        value={dateFrom}
                        onChange={(e) => setDateFrom(e.target.value)}
                    />
                    <Input
                        type="date"
                        className="h-9 w-[160px] text-xs"
                        value={dateTo}
                        onChange={(e) => setDateTo(e.target.value)}
                    />
                    {usesClientFilter && (
                        <select
                            className="h-9 rounded-md border bg-background px-3 text-xs"
                            value={clientId ?? ''}
                            onChange={(e) => setClientId(e.target.value)}
                            aria-label={`${clientSingular} filter`}
                        >
                            <option value="">All {clientSingular}s</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </select>
                    )}
                    {usesStaffFilter && (
                        <select
                            className="h-9 rounded-md border bg-background px-3 text-xs"
                            value={staffId ?? ''}
                            onChange={(e) => setStaffId(e.target.value)}
                            aria-label="Staff filter"
                        >
                            <option value="">All staff</option>
                            {staff.map((staffMember) => (
                                <option key={staffMember.id} value={staffMember.id}>
                                    {staffMember.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <Button
                        size="sm"
                        variant="default"
                        className="h-9 text-xs"
                        onClick={handleFilter}
                    >
                        Apply Filters
                    </Button>
                </div>

                {hasData ? (
                    <>
                        {renderSummaryCards()}
                        {reportChart()}
                        {renderDataSections()}
                    </>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <FileBarChart className="mb-4 h-12 w-12 text-muted-foreground/30" />
                            <h2 className="text-lg font-semibold text-muted-foreground">
                                No {report_meta?.name ?? 'Report'} Data Available
                            </h2>
                            <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground/80">
                                Select a date range and filters to generate this
                                report. Data will populate as operational
                                activity is recorded.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
