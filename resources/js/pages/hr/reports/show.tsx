import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Download } from 'lucide-react';

interface Props {
    report: {
        type: string;
        title: string;
        generated_at: string;
        parameters: Record<string, string>;
        data: any;
    };
    can: { export_data: boolean };
}

export default function ReportShow({ report, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Reports', href: '/hr/reports' },
        { title: report.title, href: '#' },
    ];

    // Extract table columns from the data if it's an array of objects
    const tableData: Record<string, any>[] = Array.isArray(report.data) ? report.data : [];
    const columns = tableData.length > 0 ? Object.keys(tableData[0]) : [];

    function handleExportCsv() {
        window.location.href = `/hr/reports/export?type=${report.type}&${new URLSearchParams(report.parameters).toString()}`;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={report.title} />
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
                                <CardTitle className="text-xl">{report.title}</CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Generated: {report.generated_at}
                                </p>
                            </div>
                            {can.export_data && (
                                <Button variant="outline" onClick={handleExportCsv}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    {Object.keys(report.parameters || {}).length > 0 && (
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(report.parameters).map(([key, val]) => (
                                    <Badge key={key} variant="outline">
                                        <span className="capitalize">{key.replace(/_/g, ' ')}</span>: {val}
                                    </Badge>
                                ))}
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
                                {report.data && typeof report.data === 'object' && !Array.isArray(report.data) ? (
                                    <pre className="mx-auto max-w-2xl overflow-x-auto text-left text-xs">
                                        {JSON.stringify(report.data, null, 2)}
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
