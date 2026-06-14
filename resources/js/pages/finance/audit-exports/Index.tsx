import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Plus, Download, Trash2, Loader2, CheckCircle, XCircle, Clock, FileText, History } from 'lucide-react';

interface AuditExport {
    id: number;
    export_name: string;
    period_from: string;
    period_to: string;
    include_journals: boolean;
    include_bank_reconciliations: boolean;
    include_ap: boolean;
    include_ar: boolean;
    include_gst: boolean;
    include_fixed_assets: boolean;
    status: string;
    file_size_bytes: number | null;
    generated_at: string | null;
    downloaded_at: string | null;
    created_by: { id: number; name: string } | null;
    created_at: string;
}

interface PaginatedExports {
    data: AuditExport[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface PageProps {
    exports: PaginatedExports;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Audit Exports', href: '/finance/audit-exports' },
];

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const formatDateTime = (date: string | null) =>
    date ? new Date(date).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const formatFileSize = (bytes: number | null) => {
    if (!bytes) return '-';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(1)} ${units[i]}`;
};

const statusConfig: Record<string, { label: string; className: string; icon: typeof Clock }> = {
    pending: { label: 'Pending', className: 'bg-muted text-muted-foreground border-border', icon: Clock },
    generating: { label: 'Generating', className: 'bg-status-info-bg text-status-info border-status-info/30', icon: Loader2 },
    completed: { label: 'Completed', className: 'bg-status-success-bg text-status-success border-status-success/30', icon: CheckCircle },
    failed: { label: 'Failed', className: 'bg-status-critical-bg text-status-critical border-status-critical/30', icon: XCircle },
};

const getSections = (exp: AuditExport): string[] => {
    const sections: string[] = [];
    if (exp.include_journals) sections.push('Journals');
    if (exp.include_bank_reconciliations) sections.push('Bank Recon');
    if (exp.include_ap) sections.push('AP');
    if (exp.include_ar) sections.push('AR');
    if (exp.include_gst) sections.push('GST');
    if (exp.include_fixed_assets) sections.push('Assets');
    return sections;
};

export default function AuditExportsIndex({ exports: exportData }: PageProps) {
    const handleDelete = (exportItem: AuditExport) => {
        if (confirm(`Are you sure you want to delete "${exportItem.export_name}"?`)) {
            router.delete(`/finance/audit-exports/${exportItem.id}`);
        }
    };

    const completedCount = exportData.data.filter((e) => e.status === 'completed').length;
    const generatingCount = exportData.data.filter((e) => e.status === 'generating').length;
    const failedCount = exportData.data.filter((e) => e.status === 'failed').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Exports" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={History}
                        title="Audit Exports"
                        description="Generate audit trail reports for external auditors"
                        stats={[
                            { label: 'Total', value: exportData.data.length },
                            { label: 'Completed', value: completedCount },
                            { label: 'Generating', value: generatingCount },
                            { label: 'Failed', value: failedCount },
                        ]}
                        actions={
                            <Button asChild size="sm">
                                <Link href="/finance/audit-exports/create">
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    New Export
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                {/* Table */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <FileText className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Exports</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Export Name</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Sections</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {exportData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No audit exports found. Create one to get started.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    exportData.data.map((exp) => {
                                        const statusCfg = statusConfig[exp.status] ?? statusConfig.pending;
                                        const StatusIcon = statusCfg.icon;

                                        return (
                                            <TableRow key={exp.id}>
                                                <TableCell className="font-medium">{exp.export_name}</TableCell>
                                                <TableCell>
                                                    <span className="text-sm">
                                                        {formatDate(exp.period_from)} - {formatDate(exp.period_to)}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        {getSections(exp).map((s) => (
                                                            <Badge key={s} variant="outline" className="text-xs">
                                                                {s}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={statusCfg.className}>
                                                        <StatusIcon className={`w-3 h-3 mr-1 ${exp.status === 'generating' ? 'animate-spin' : ''}`} />
                                                        {statusCfg.label}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-sm">{formatFileSize(exp.file_size_bytes)}</TableCell>
                                                <TableCell>
                                                    <div className="text-sm">{formatDateTime(exp.created_at)}</div>
                                                    {exp.created_by && (
                                                        <div className="text-xs text-muted-foreground">{exp.created_by.name}</div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {exp.status === 'completed' && (
                                                            <Button variant="outline" size="sm" asChild>
                                                                <a href={`/finance/audit-exports/${exp.id}/download`}>
                                                                    <Download className="w-4 h-4 mr-1" />
                                                                    Download
                                                                </a>
                                                            </Button>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleDelete(exp)}
                                                        >
                                                            <Trash2 className="w-4 h-4 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {exportData.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {exportData.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
