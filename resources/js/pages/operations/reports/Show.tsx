import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { FileBarChart } from 'lucide-react';
import { useState } from 'react';

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
};

export default function ReportShow({
    report_type,
    report_meta,
    data,
    filters,
}: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');

    const handleFilter = () => {
        router.get(
            `/operations/reports/${report_type}`,
            { date_from: dateFrom, date_to: dateTo },
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

    const hasData = data && Object.keys(data).length > 0;

    return (
        <AppLayout>
            <Head title={report_meta?.name ?? 'Report'} />
            <PageHeader
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
                        {renderDataSections()}
                    </>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <FileBarChart className="mb-4 h-12 w-12 text-muted-foreground/30" />
                            <h2 className="text-lg font-semibold text-muted-foreground">
                                No Data Available
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
