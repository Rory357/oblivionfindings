import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    Download,
    Eye,
    FolderOpen,
    Landmark,
    Tag,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';

import { UploadDocumentDialog, documentTypeIcon } from './_dialogs';

interface Document {
    id: number;
    title: string;
    category: string;
    file_name: string;
    file_size: number;
    is_confidential: boolean;
    version: number;
    updated_at: string | null;
}

interface Filters {
    search: string | null;
    document_type: string | null;
}

interface Props extends PageProps {
    documents: {
        data: Document[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
        last_page: number;
    };
    filters: Filters;
    summary: {
        total: number;
        by_type: Record<string, number> | [];
        updated_last_30_days: number;
    };
    categories: Array<{ value: string; label: string }>;
}

function cleanParams(values: Partial<Filters>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values).filter(
            ([, v]) => v !== null && v !== undefined && v !== '' && v !== 'all',
        ),
    ) as Record<string, string>;
}

export default function DocumentsIndex({
    auth,
    documents,
    filters,
    summary,
    categories,
}: Props) {
    const canManage = Boolean(auth.can?.governance?.documents?.manage);
    const [uploadOpen, setUploadOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<Document>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Partial<Filters>) =>
        router.get(
            '/governance/documents',
            cleanParams({ ...filters, ...patch }),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const typeCount = (type: string): number =>
        (summary.by_type as Record<string, number>)[type] ?? 0;
    const categoryLabel = (value: string) =>
        categories.find((c) => c.value === value)?.label ??
        value.replace(/_/g, ' ');

    const hasFilters = Boolean(filters.search || filters.document_type);
    const clearFilters = () =>
        router.get('/governance/documents', {}, { preserveScroll: true });

    const openDocument = (doc: Document) =>
        router.visit(`/governance/documents/${doc.id}`);
    const downloadDocument = (doc: Document) => {
        window.location.href = `/governance/documents/${doc.id}/download`;
    };

    const actionsFor = (doc: Document): MenuItem[] =>
        compactMenu([
            { label: 'Open details', icon: Eye, onClick: () => openDocument(doc) },
            { label: 'Download', icon: Download, onClick: () => downloadDocument(doc) },
        ]);

    const columns: EntityTableColumn<Document>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1.1fr',
            cell: (d) => (
                <EntityChip icon={documentTypeIcon(d.category)}>
                    {categoryLabel(d.category)}
                </EntityChip>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.5fr',
            cell: (d) => (
                <span className="text-muted-foreground tabular-nums">
                    v{d.version}
                </span>
            ),
        },
        {
            key: 'size',
            label: 'Size',
            width: '0.6fr',
            cell: (d) => (
                <span className="text-muted-foreground tabular-nums">
                    {formatFileSize(d.file_size) || '—'}
                </span>
            ),
        },
        {
            key: 'updated',
            label: 'Updated',
            width: '0.9fr',
            cell: (d) => formatDateLong(d.updated_at),
        },
    ];

    const header = (
        <PageHeader
            icon={FolderOpen}
            title="Governance documents"
            subline="Constitution, terms of reference, templates and board archives"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search documents by title…"
                    />
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Upload}
                            onClick={() => setUploadOpen(true)}
                        >
                            Upload document
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Documents"
                        ariaLabel="View all documents"
                        href="/governance/documents"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            in the governance library
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Constitution"
                        ariaLabel="View constitution and charter documents"
                        href="/governance/documents?document_type=constitution"
                    >
                        <PageHeaderMeterBig>
                            {typeCount('constitution')}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {typeCount('terms_of_reference')} terms of reference
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Board policies"
                        ariaLabel="View board policy documents"
                        href="/governance/documents?document_type=policy"
                    >
                        <PageHeaderMeterBig>{typeCount('policy')}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {typeCount('procedure')} procedures
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Updated · 30 days"
                        ariaLabel="View recently updated documents"
                        href="/governance/documents"
                    >
                        <PageHeaderMeterBig>
                            {summary.updated_last_30_days}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            newest listed first
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Tag}
                    label="All document types"
                    value={filters.document_type ?? 'all'}
                    options={[
                        { value: 'all', label: 'All document types' },
                        ...categories,
                    ]}
                    onChange={(v) => go({ document_type: v })}
                />
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Documents', href: '/governance/documents' },
            ]}
        >
            <Head title="Governance documents" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={
                            filters.document_type
                                ? categoryLabel(filters.document_type)
                                : 'Document library'
                        }
                        caption={`${documents.data.length} of ${documents.total} shown`}
                        right={
                            hasFilters ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="text-xs text-muted-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />

                    {documents.data.length === 0 ? (
                        <EmptyState
                            icon={hasFilters ? BookOpen : Landmark}
                            title={
                                hasFilters
                                    ? 'No documents match your filters'
                                    : 'No documents uploaded yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing the type filter or search term.'
                                    : canManage
                                      ? 'Upload the constitution, terms of reference and board templates.'
                                      : 'Board documents will appear here once they are uploaded.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setUploadOpen(true)}
                                    >
                                        <Upload className="h-3.5 w-3.5" />
                                        Upload document
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={documents.data}
                            rowKey={(d) => d.id}
                            identityLabel="Document"
                            identity={(d) => ({
                                icon: documentTypeIcon(d.category),
                                name: d.title,
                                subline: d.file_name,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            hrefFor={(d) => `/governance/documents/${d.id}`}
                            onOpen={openDocument}
                            onRowContextMenu={(e, d) => ctxMenu.open(e, d)}
                            minWidth={760}
                        />
                    )}

                    <LaravelPagination
                        links={documents.links}
                        lastPage={documents.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={documentTypeIcon(ctxMenu.ctx.record.category)}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage ? (
                <UploadDocumentDialog
                    open={uploadOpen}
                    onClose={() => setUploadOpen(false)}
                    categories={categories}
                />
            ) : null}
        </AppLayout>
    );
}
