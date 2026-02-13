import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Plus, Download } from 'lucide-react';

interface PayrollRun {
    id: number;
    period_start: string;
    period_end: string;
    status: 'draft' | 'locked' | 'exported';
    total_hours: number;
    total_gross: number;
    items_count: number;
    created_at: string;
    locked_at: string | null;
    exported_at: string | null;
}

interface Props {
    runs: {
        data: PayrollRun[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    can: { manage: boolean; export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Draft',
    },
    locked: {
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        label: 'Locked',
    },
    exported: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Exported',
    },
};

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);
}

export default function PayrollIndex({ runs, can }: Props) {
    function handleExport(runId: number) {
        router.post(`/hr/payroll/${runId}/export`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Payroll Runs</h1>
                    {can.manage && (
                        <Button asChild>
                            <Link href="/hr/payroll/create">
                                <Plus className="mr-2 h-4 w-4" />
                                Create Run
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Period</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Total Hours</th>
                                    <th className="px-4 py-3 text-right font-medium">Total Gross</th>
                                    <th className="px-4 py-3 text-right font-medium">Items</th>
                                    <th className="px-4 py-3 text-left font-medium">Created</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {runs.data.map((run) => {
                                    const config = statusConfig[run.status] || statusConfig.draft;
                                    return (
                                        <tr key={run.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/hr/payroll/${run.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {run.period_start} &mdash; {run.period_end}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.total_hours.toFixed(1)}h
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(run.total_gross)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {run.items_count}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{run.created_at}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/hr/payroll/${run.id}`}>View</Link>
                                                    </Button>
                                                    {can.export_data && run.status === 'locked' && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => handleExport(run.id)}
                                                        >
                                                            <Download className="mr-1 h-3 w-3" />
                                                            Export
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {runs.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                                            No payroll runs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {runs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(runs.current_page - 1) * runs.per_page + 1} to{' '}
                            {Math.min(runs.current_page * runs.per_page, runs.total)} of{' '}
                            {runs.total} results
                        </p>
                        <div className="flex items-center gap-1">
                            {runs.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
