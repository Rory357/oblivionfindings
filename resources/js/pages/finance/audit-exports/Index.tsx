import { Head, router } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { AuditExportDialog, ConfirmDialog, TaxTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Plus, Download, Trash2, FileText, History } from 'lucide-react';
import { useState } from 'react';

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
    canManage: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
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

export default function AuditExportsIndex({ exports: exportData, canManage = false }: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<AuditExport | null>(null);
    const [deleting, setDeleting] = useState(false);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/finance/audit-exports/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
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
                            canManage && (
                                <Button size="sm" onClick={() => setCreateOpen(true)}>
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    New Export
                                </Button>
                            )
                        }
                        footer={<TaxTabsFooter active="audit-exports" />}
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
                                                    <StatusBadge status={exp.status} />
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
                                                        {canManage && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label={`Delete ${exp.export_name}`}
                                                                onClick={() => setDeleteTarget(exp)}
                                                            >
                                                                <Trash2 className="w-4 h-4 text-destructive" />
                                                            </Button>
                                                        )}
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

            {canManage && (
                <AuditExportDialog open={createOpen} onClose={() => setCreateOpen(false)} />
            )}

            <ConfirmDialog
                open={!!deleteTarget}
                onOpenChange={(open) => !open && setDeleteTarget(null)}
                title="Delete audit export?"
                description={
                    <>
                        This permanently deletes{' '}
                        <span className="font-medium text-foreground">
                            &ldquo;{deleteTarget?.export_name}&rdquo;
                        </span>{' '}
                        and its generated file. This can&rsquo;t be undone.
                    </>
                }
                confirmLabel="Delete export"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
