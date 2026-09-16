import {
    AuditExportDialog,
    ConfirmDialog,
    FinanceSectionRail,
} from '@/components/finance';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Download, History, Plus, Trash2, X } from 'lucide-react';
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
    total: number;
}

/** Org-wide totals — never the page in front of you. */
interface Summary {
    exports: number;
    completed: number;
    generating: number;
    failed: number;
    total_bytes: number;
}

interface Filters {
    status?: string;
    from?: string;
    to?: string;
}

interface PageProps {
    exports: PaginatedExports;
    summary: Summary;
    filters: Filters;
    canManage: boolean;
}

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'pending', label: 'Pending' },
    { value: 'generating', label: 'Generating' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Tax & compliance', href: '/finance/tax' },
    { title: 'Audit exports', href: '/finance/audit-exports' },
];

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const formatFileSize = (bytes: number | null) => {
    if (!bytes) return null;
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(1)} ${units[i]}`;
};

const SECTION_LABELS: [keyof AuditExport, string][] = [
    ['include_journals', 'Journals'],
    ['include_bank_reconciliations', 'Bank reconciliations'],
    ['include_ap', 'Payables'],
    ['include_ar', 'Receivables'],
    ['include_gst', 'GST'],
    ['include_fixed_assets', 'Fixed assets'],
];

const sectionsFor = (exp: AuditExport): string[] =>
    SECTION_LABELS.filter(([key]) => Boolean(exp[key])).map(
        ([, label]) => label,
    );

export default function AuditExportsIndex({
    exports: exportData,
    summary,
    filters,
    canManage = false,
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<AuditExport | null>(null);
    const [deleting, setDeleting] = useState(false);

    const status = filters.status ?? 'all';
    const from = filters.from ?? '';
    const to = filters.to ?? '';

    const apply = (next: Filters) => {
        const merged: Filters = { status, from, to, ...next };
        const params: Record<string, string> = {};
        Object.entries(merged).forEach(([key, value]) => {
            if (value && value !== 'all') params[key] = value;
        });
        router.get('/finance/audit-exports', params, { preserveState: true });
    };

    const clearFilters = () => {
        router.get('/finance/audit-exports', {}, { preserveState: true });
    };

    const hasFilters = status !== 'all' || Boolean(from) || Boolean(to);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/finance/audit-exports/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const ctx = useEntityContextMenu<AuditExport>();

    /** The ONE menu feeding both the kebab and the right-click menu. */
    const actionsFor = (exp: AuditExport): MenuItem[] => {
        const items: MenuItem[] = [];
        if (exp.status === 'completed') {
            items.push({
                label: 'Download',
                icon: Download,
                onClick: () =>
                    window.location.assign(
                        `/finance/audit-exports/${exp.id}/download`,
                    ),
            });
        }
        if (canManage) {
            items.push({
                label: 'Delete export',
                icon: Trash2,
                danger: true,
                onClick: () => setDeleteTarget(exp),
            });
        }
        return items;
    };

    const columns: EntityTableColumn<AuditExport>[] = [
        {
            key: 'period',
            label: 'Period',
            width: '210px',
            cell: (exp) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {shortDate(exp.period_from)} – {shortDate(exp.period_to)}
                </span>
            ),
        },
        {
            key: 'sections',
            label: 'Sections',
            width: '1.4fr',
            cell: (exp) => {
                const sections = sectionsFor(exp);
                if (sections.length === 0) return <EmptyValue />;
                return (
                    <span className="flex flex-wrap gap-1">
                        {sections.map((section) => (
                            <EntityChip key={section}>{section}</EntityChip>
                        ))}
                    </span>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (exp) => <StatusBadge status={exp.status} />,
        },
        {
            key: 'size',
            label: 'Size',
            width: '110px',
            align: 'right',
            cell: (exp) => {
                const size = formatFileSize(exp.file_size_bytes);
                return size ? (
                    <span className="tabular-nums">{size}</span>
                ) : (
                    <EmptyValue />
                );
            },
        },
        {
            key: 'created',
            label: 'Created',
            width: '200px',
            cell: (exp) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {formatDateTime(exp.created_at)}
                </span>
            ),
        },
    ];

    const totalSize = formatFileSize(summary.total_bytes);

    const header = (
        <PageHeader
            icon={History}
            title="Audit exports"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.failed > 0 ? 'critical' : 'success'}
                >
                    {summary.completed} ready
                </PageHeaderStatusChip>
            }
            subline={`Tax & compliance · ${summary.exports} export${
                summary.exports === 1 ? '' : 's'
            } for external auditors${totalSize ? ` · ${totalSize} stored` : ''}`}
            actions={
                canManage ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        New export
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Exports"
                        href="/finance/audit-exports"
                        ariaLabel="View every audit export"
                    >
                        <PageHeaderMeterBig>
                            {summary.exports}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {hasFilters
                                ? `matching this filter`
                                : `generated all time`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Ready to download"
                        tone="success"
                        href="/finance/audit-exports"
                        ariaLabel="View completed audit exports"
                    >
                        <PageHeaderMeterDonut
                            percent={
                                summary.exports === 0
                                    ? 0
                                    : (summary.completed / summary.exports) *
                                      100
                            }
                            caption={`${summary.completed} of ${summary.exports} complete`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Generating"
                        tone={summary.generating > 0 ? 'warning' : 'brand'}
                        href="/finance/audit-exports"
                        ariaLabel="View audit exports still generating"
                    >
                        <PageHeaderMeterBig>
                            {summary.generating}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            still being packaged
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Failed"
                        tone={summary.failed > 0 ? 'critical' : 'brand'}
                        href="/finance/audit-exports"
                        ariaLabel="View failed audit exports"
                    >
                        <PageHeaderMeterBig>
                            {summary.failed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need generating again
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue="all"
                        options={STATUS_OPTIONS}
                        onChange={(value) => apply({ status: value })}
                    />
                    <FinancePeriodFilter
                        url="/finance/audit-exports"
                        from={from}
                        to={to}
                        idPrefix="audit-exports-period"
                        onApply={(range) =>
                            apply({ from: range.from, to: range.to })
                        }
                    />
                    {hasFilters ? (
                        <PageHeaderFilterButton icon={X} onClick={clearFilters}>
                            Clear filters
                        </PageHeaderFilterButton>
                    ) : null}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit exports" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Exports"
                        caption={`${exportData.data.length} of ${summary.exports} shown`}
                    />

                    {exportData.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No audit exports match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={History}
                                itemName="audit export"
                                title="No audit exports yet"
                                description="Generate an audit trail report for your auditors to get started."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New audit export
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={exportData.data}
                                rowKey={(exp) => exp.id}
                                identityLabel="Export"
                                minWidth={1180}
                                identity={(exp) => ({
                                    icon: History,
                                    name: exp.export_name,
                                    subline: exp.created_by?.name,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                onRowContextMenu={(e, exp) => ctx.open(e, exp)}
                            />
                            <LaravelPagination
                                links={exportData.links}
                                lastPage={exportData.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={History}
                    title={ctx.ctx.record.export_name}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage && (
                <AuditExportDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                />
            )}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
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
                confirmText="Delete export"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
