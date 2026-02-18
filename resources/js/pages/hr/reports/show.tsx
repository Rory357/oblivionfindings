import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Download } from 'lucide-react';

interface Props {
    reportType: string;
    reportTitle: string;
    reportData: unknown[] | Record<string, unknown>;
    generatedAt: string | null;
    exportId: number | null;
    filters: {
        date_from: string | null;
        date_to: string | null;
    };
    can: { export: boolean };
}

export default function ReportShow({ reportType, reportTitle, reportData, generatedAt, exportId, filters, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Reports', href: '/hr/reports' },
        { title: reportTitle, href: '#' },
    ];

    const tableData: Record<string, unknown>[] = Array.isArray(reportData) ? reportData as Record<string, unknown>[] : [];
    const columns = tableData.length > 0 ? Object.keys(tableData[0]) : [];

    const exportQuery = new URLSearchParams({
        report_type: reportType,
        ...(filters.date_from ? { date_from: filters.date_from } : {}),
        ...(filters.date_to ? { date_to: filters.date_to } : {}),
    }).toString();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={reportTitle} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/hr/reports">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                </div>

                {/* Report Header */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-xl">{reportTitle}</CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Generated: {generatedAt || '\u2014'}
                                </p>
                            </div>
                            {can.export && (
                                <div className="flex items-center gap-2">
                                    {exportId && (
                                        <Button variant="outline" asChild>
                                            <a href={`/hr/reports/exports/${exportId}/download`}>
                                                <Download className="mr-2 h-4 w-4" />
                                                Download Export
                                            </a>
                                        </Button>
                                    )}
                                    <Button variant="ghost" asChild>
                                        <a href={`/hr/reports/export?${exportQuery}`}>
                                            <Download className="mr-2 h-4 w-4" />
                                            Regenerate CSV
                                        </a>
                                    </Button>
                                </div>
                            )}
                        </div>
                    </CardHeader>
                    {(filters.date_from || filters.date_to) && (
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {filters.date_from && <Badge variant="outline">From: {filters.date_from}</Badge>}
                                {filters.date_to && <Badge variant="outline">To: {filters.date_to}</Badge>}
                            </div>
                        </CardContent>
                    )}
                </Card>

                {/* Report Data Table */}
                <Card>
                    <CardContent className="p-0">
                        {tableData.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            {columns.map((col) => (
                                                <th key={col} className="px-4 py-3 text-left font-medium capitalize whitespace-nowrap">
                                                    {col.replace(/_/g, ' ')}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {tableData.map((row, rowIndex) => (
                                            <tr key={rowIndex} className="hover:bg-muted/30">
                                                {columns.map((col) => (
                                                    <td key={col} className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                                        {row[col] != null ? String(row[col]) : '\u2014'}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="px-4 py-8 text-center text-muted-foreground">
                                {reportData && typeof reportData === 'object' && !Array.isArray(reportData) ? (
                                    <pre className="mx-auto max-w-2xl overflow-x-auto text-left text-xs">
                                        {JSON.stringify(reportData, null, 2)}
                                    </pre>
                                ) : (
                                    <p>No data available for this report.</p>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
