import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { FileSpreadsheet, Play, Download, Trash2, Plus } from 'lucide-react';
import { useState } from 'react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

type SavedReport = {
    id: number;
    name: string;
    description: string | null;
    report_type: string;
    fields: string[];
    is_scheduled: boolean;
    last_run_at: string | null;
    created_by: string;
    created_at: string;
};

type PaginatedReports = {
    data: SavedReport[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    reports: PaginatedReports;
    sources: Record<string, { label: string; fields: string[] }>;
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
    { title: 'Saved Reports', href: '/hr/reports/saved' },
];

const typeColors: Record<string, string> = {
    employee: 'border-status-info/30 text-status-info bg-status-info',
    leave: 'border-status-warning/30 text-status-warning bg-status-warning',
    compliance: 'border-status-success/30 text-status-success bg-status-success',
    time: 'border-primary/30 text-primary bg-primary/10',
    training: 'border-status-warning/30 text-status-warning bg-status-warning',
};

export default function SavedReports({ reports, sources }: Props) {
    const [runningId, setRunningId] = useState<number | null>(null);
    const [runData, setRunData] = useState<{ data: Record<string, string>[]; fields: string[] } | null>(null);

    const handleRun = async (report: SavedReport) => {
        setRunningId(report.id);
        setRunData(null);

        try {
            const response = await fetch(`/hr/reports/${report.id}/run`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });

            const result = await response.json();
            setRunData({ data: result.data || [], fields: result.fields || [] });
        } catch {
            // Handle error silently
        } finally {
            setRunningId(null);
        }
    };

    const handleExport = (reportId: number, format: 'csv' | 'excel') => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `/hr/reports/${reportId}/export`;

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
        form.appendChild(csrfInput);

        const formatInput = document.createElement('input');
        formatInput.type = 'hidden';
        formatInput.name = 'format';
        formatInput.value = format;
        form.appendChild(formatInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };

    const handleDelete = (reportId: number) => {
        if (!confirm('Are you sure you want to delete this saved report?')) return;
        router.delete(`/hr/reports/${reportId}`);
    };

    const formatLabel = (field: string) =>
        field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Saved Reports" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Saved Reports</h1>
                    <Button asChild>
                        <Link href="/hr/reports/builder">
                            <Plus className="mr-2 h-4 w-4" />
                            New Report
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Fields</TableHead>
                                    <TableHead>Last Run</TableHead>
                                    <TableHead>Created By</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reports.data.map((report) => (
                                    <TableRow key={report.id}>
                                        <TableCell>
                                            <div>
                                                <div className="font-medium">{report.name}</div>
                                                {report.description && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {report.description}
                                                    </div>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className={typeColors[report.report_type] || ''}
                                            >
                                                {sources[report.report_type]?.label || report.report_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {report.fields.length} fields
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {report.last_run_at || 'Never'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {report.created_by}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleRun(report)}
                                                    disabled={runningId === report.id}
                                                >
                                                    <Play className="mr-1 h-3 w-3" />
                                                    {runningId === report.id ? 'Running...' : 'Run'}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleExport(report.id, 'csv')}
                                                >
                                                    <Download className="mr-1 h-3 w-3" />
                                                    CSV
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleExport(report.id, 'excel')}
                                                >
                                                    <FileSpreadsheet className="mr-1 h-3 w-3" />
                                                    Excel
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleDelete(report.id)}
                                                    className="text-status-critical hover:text-status-critical"
                                                >
                                                    <Trash2 className="h-3 w-3" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {reports.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                            No saved reports yet. Create one using the Report Builder.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Run Results */}
                {runData && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Report Results ({runData.data.length} rows)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {runData.fields.map((field) => (
                                                <TableHead key={field}>{formatLabel(field)}</TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runData.data.map((row, i) => (
                                            <TableRow key={i}>
                                                {runData.fields.map((field) => (
                                                    <TableCell key={field}>
                                                        {row[field] !== null && row[field] !== undefined
                                                            ? String(row[field])
                                                            : '\u2014'}
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        ))}
                                        {runData.data.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={runData.fields.length}
                                                    className="py-8 text-center text-muted-foreground"
                                                >
                                                    No data found.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Pagination */}
                {reports.last_page > 1 && (
                    <LaravelPagination links={reports.links} />
                )}
            </div>
        </AppLayout>
    );
}
