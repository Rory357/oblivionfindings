import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card } from '@/components/ui/card';
import { Plus, Download, Trash2, Loader2, CheckCircle, XCircle, Clock } from 'lucide-react';

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

interface Props extends PageProps {
    exports: PaginatedExports;
}

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
    pending: { label: 'Pending', className: 'bg-gray-100 text-gray-800', icon: Clock },
    generating: { label: 'Generating', className: 'bg-blue-100 text-blue-800', icon: Loader2 },
    completed: { label: 'Completed', className: 'bg-green-100 text-green-800', icon: CheckCircle },
    failed: { label: 'Failed', className: 'bg-red-100 text-red-800', icon: XCircle },
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

export default function AuditExportsIndex({ auth, exports: exportData }: Props) {
    const handleDelete = (exportItem: AuditExport) => {
        if (confirm(`Are you sure you want to delete "${exportItem.export_name}"?`)) {
            router.delete(`/finance/audit-exports/${exportItem.id}`);
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Audit Exports', href: '/finance/audit-exports' },
            ]}
        >
            <Head title="Audit Exports" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Audit Exports</h1>
                        <p className="text-gray-500 mt-1">Generate audit trail reports for external auditors</p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/audit-exports/create">
                            <Plus className="w-4 h-4 mr-2" />
                            New Export
                        </Link>
                    </Button>
                </div>

                {/* Table */}
                <Card>
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
                                    <TableCell colSpan={7} className="text-center text-gray-500 py-8">
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
                                                <Badge className={statusCfg.className}>
                                                    <StatusIcon className={`w-3 h-3 mr-1 ${exp.status === 'generating' ? 'animate-spin' : ''}`} />
                                                    {statusCfg.label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">{formatFileSize(exp.file_size_bytes)}</TableCell>
                                            <TableCell>
                                                <div className="text-sm">{formatDateTime(exp.created_at)}</div>
                                                {exp.created_by && (
                                                    <div className="text-xs text-gray-400">{exp.created_by.name}</div>
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
                                                        <Trash2 className="w-4 h-4 text-red-500" />
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
                        <div className="flex items-center justify-center gap-1 p-4 border-t">
                            {exportData.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
