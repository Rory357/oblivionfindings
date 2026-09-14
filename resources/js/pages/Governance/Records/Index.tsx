import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    Calendar,
    Download,
    Eye,
    FileText,
    FolderArchive,
    Gavel,
    Layers,
    Tag,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
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
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

interface DocumentRecord {
    id: number;
    title: string;
    category: string;
    file_name: string;
    file_size: number;
    is_confidential: boolean;
    version: number;
    updated_at: string;
}

interface MeetingRecord {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    status: string;
    has_minutes: boolean;
    minutes_status?: string;
    minutes_version?: number;
}

interface ResolutionRecord {
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
    outcome: string;
    voting_threshold: string;
    meeting_title?: string;
    created_at: string;
}

interface PolicyRecord {
    id: number;
    policy_code: string;
    title: string;
    category: string;
    version_number: number;
    effective_from?: string;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props extends PageProps {
    tab: string;
    search?: string | null;
    category?: string | null;
    capabilities: {
        documents: boolean;
        meetings: boolean;
        resolutions: boolean;
        policies: boolean;
    };
    documents: PaginatedData<DocumentRecord> | null;
    meetings: PaginatedData<MeetingRecord> | null;
    resolutions: PaginatedData<ResolutionRecord> | null;
    policies: PaginatedData<PolicyRecord> | null;
    categories: Array<{ value: string; label: string }>;
}

const humanise = (value: string | null | undefined) =>
    value ? value.replace(/_/g, ' ') : '';

/** One record-type section: caption, entity table (or honest empty state), pager. */
function RecordSection<T extends { id: number }>({
    title,
    icon,
    page,
    emptyTitle,
    identityLabel,
    identity,
    columns,
    hrefFor,
    actionsFor,
}: {
    title: string;
    icon: LucideIcon;
    page: PaginatedData<T> | null;
    emptyTitle: string;
    identityLabel: string;
    identity: (row: T) => { name: string; subline?: ReactNode };
    columns: EntityTableColumn<T>[];
    hrefFor: (row: T) => string;
    actionsFor: (row: T) => MenuItem[];
}) {
    const rows = page?.data ?? [];
    const ctxMenu = useEntityContextMenu<T>();
    return (
        <section className="flex flex-col gap-3">
            <ListCaption
                title={title}
                caption={`${rows.length} of ${page?.total ?? 0} shown`}
            />
            {rows.length === 0 ? (
                <EmptyState
                    icon={icon}
                    variant="compact"
                    title={emptyTitle}
                    description="Try another search term or record type."
                />
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    identityLabel={identityLabel}
                    identity={(row) => ({ icon, ...identity(row) })}
                    columns={columns}
                    hrefFor={hrefFor}
                    onOpen={(row) => router.visit(hrefFor(row))}
                    actionsFor={actionsFor}
                    onRowContextMenu={(e, row) => ctxMenu.open(e, row)}
                    minWidth={760}
                />
            )}
            {page ? (
                <LaravelPagination
                    links={page.links}
                    lastPage={page.last_page}
                    preserveScroll
                />
            ) : null}
            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={icon}
                    title={identity(ctxMenu.ctx.record).name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </section>
    );
}

export default function RecordsIndex({
    auth,
    tab: initialTab,
    search: initialSearch,
    category,
    capabilities,
    documents,
    meetings,
    resolutions,
    policies,
    categories,
}: Props) {
    const currentTab = initialTab || 'all';
    const [searchQuery, setSearchQuery] = useState(initialSearch || '');

    useEffect(() => {
        setSearchQuery(initialSearch || '');
    }, [initialSearch]);

    const visit = (params: {
        tab?: string;
        search?: string;
        category?: string | null;
    }) => {
        const next = {
            tab: params.tab ?? currentTab,
            search: (params.search ?? searchQuery) || undefined,
            category:
                (params.category === undefined ? category : params.category) ||
                undefined,
        };
        router.get('/governance/records', next, {
            preserveState: true,
            replace: true,
        });
    };

    const typeOptions = [
        { value: 'all', label: 'All record types' },
        ...(capabilities.meetings
            ? [{ value: 'meetings', label: 'Meetings & minutes' }]
            : []),
        ...(capabilities.resolutions
            ? [{ value: 'resolutions', label: 'Decisions' }]
            : []),
        ...(capabilities.policies
            ? [{ value: 'policies', label: 'Policies' }]
            : []),
        ...(capabilities.documents
            ? [{ value: 'documents', label: 'Documents' }]
            : []),
    ];

    const shows = (key: string) => currentTab === 'all' || currentTab === key;
    const hasFilters = Boolean(initialSearch || category || currentTab !== 'all');
    const showCategory =
        capabilities.documents && (currentTab === 'all' || currentTab === 'documents');

    const meetingColumns: EntityTableColumn<MeetingRecord>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (m) => <StatusBadge status={m.status} className="rounded-[8px]" />,
        },
        {
            key: 'minutes',
            label: 'Minutes',
            width: '1fr',
            cell: (m) =>
                m.has_minutes ? (
                    <EntityStatusChip variant="success">
                        Minutes {humanise(m.minutes_status) || 'recorded'}
                    </EntityStatusChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'date',
            label: 'Held',
            width: '0.9fr',
            cell: (m) => formatDateLong(m.scheduled_at, 'Date not set'),
        },
    ];

    const resolutionColumns: EntityTableColumn<ResolutionRecord>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (r) => <StatusBadge status={r.status} className="rounded-[8px]" />,
        },
        {
            key: 'outcome',
            label: 'Outcome',
            width: '0.8fr',
            cell: (r) =>
                r.outcome ? (
                    <EntityChip>
                        <span className="capitalize">{humanise(r.outcome)}</span>
                    </EntityChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'date',
            label: 'Recorded',
            width: '0.9fr',
            cell: (r) => formatDateLong(r.created_at),
        },
    ];

    const policyColumns: EntityTableColumn<PolicyRecord>[] = [
        {
            key: 'category',
            label: 'Category',
            width: '1fr',
            cell: (p) => (
                <EntityChip icon={Tag}>
                    <span className="capitalize">{humanise(p.category)}</span>
                </EntityChip>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.5fr',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    v{p.version_number}
                </span>
            ),
        },
        {
            key: 'effective',
            label: 'Effective',
            width: '0.9fr',
            cell: (p) => formatDateOnly(p.effective_from),
        },
    ];

    const documentColumns: EntityTableColumn<DocumentRecord>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1fr',
            cell: (d) => (
                <EntityChip icon={Tag}>
                    <span className="capitalize">{humanise(d.category)}</span>
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
    ];

    const header = (
        <PageHeader
            icon={FolderArchive}
            title="Governance records"
            subline="Past meetings and minutes, carried decisions, approved policies and board documents"
            meters={
                <>
                    {capabilities.meetings && (
                        <PageHeaderMeterBlock
                            label="Past meetings"
                            href="/governance/records?tab=meetings"
                        >
                            <PageHeaderMeterBig>
                                {meetings?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Historical sessions
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.resolutions && (
                        <PageHeaderMeterBlock
                            label="Decisions made"
                            href="/governance/records?tab=resolutions"
                        >
                            <PageHeaderMeterBig>
                                {resolutions?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Carried resolutions
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.policies && (
                        <PageHeaderMeterBlock
                            label="Approved policies"
                            href="/governance/records?tab=policies"
                        >
                            <PageHeaderMeterBig>
                                {policies?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Active policy library
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.documents && (
                        <PageHeaderMeterBlock
                            label="Documents"
                            href="/governance/records?tab=documents"
                        >
                            <PageHeaderMeterBig>
                                {documents?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Charters & templates
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                </>
            }
            actions={
                <PageHeaderSearch
                    value={searchQuery}
                    onChange={setSearchQuery}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            visit({ search: searchQuery });
                        }
                    }}
                    placeholder="Search records by title…"
                />
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Layers}
                        label="All record types"
                        value={currentTab}
                        options={typeOptions}
                        onChange={(value) => visit({ tab: value })}
                    />
                    {showCategory ? (
                        <PageHeaderFilterSelect
                            icon={Tag}
                            label="All document types"
                            value={category ?? 'all'}
                            options={[
                                { value: 'all', label: 'All document types' },
                                ...categories,
                            ]}
                            onChange={(value) =>
                                visit({ category: value === 'all' ? null : value })
                            }
                        />
                    ) : null}
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    const noSections =
        !capabilities.meetings &&
        !capabilities.resolutions &&
        !capabilities.policies &&
        !capabilities.documents;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Records search', href: '/governance/records' },
            ]}
        >
            <Head title="Governance records" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {hasFilters ? (
                        <div className="flex justify-end">
                            <Button
                                variant="outline"
                                size="sm"
                                className="text-xs text-muted-foreground"
                                onClick={() =>
                                    router.get('/governance/records', {}, { replace: true })
                                }
                            >
                                <X className="h-3.5 w-3.5" />
                                Clear filters
                            </Button>
                        </div>
                    ) : null}

                    {noSections ? (
                        <EmptyState
                            icon={FolderArchive}
                            title="No governance records are available to you"
                            description="Records appear here for the registers your role can view."
                        />
                    ) : null}

                    {shows('meetings') && capabilities.meetings ? (
                        <RecordSection
                            title="Past meetings & minutes"
                            icon={Calendar}
                            page={meetings}
                            emptyTitle="No past meetings match"
                            identityLabel="Meeting"
                            identity={(m) => ({
                                name: m.title,
                                subline: (
                                    <span className="capitalize">
                                        {humanise(m.meeting_type)}
                                    </span>
                                ),
                            })}
                            columns={meetingColumns}
                            hrefFor={(m) => `/governance/meetings/${m.id}`}
                            actionsFor={(m) => [
                                {
                                    label: 'Open meeting workspace',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/meetings/${m.id}`),
                                },
                            ]}
                        />
                    ) : null}

                    {shows('resolutions') && capabilities.resolutions ? (
                        <RecordSection
                            title="Decisions & resolutions"
                            icon={Gavel}
                            page={resolutions}
                            emptyTitle="No carried decisions match"
                            identityLabel="Decision"
                            identity={(r) => ({
                                name: r.title,
                                subline: `${r.resolution_reference}${r.meeting_title ? ` · From ${r.meeting_title}` : ''}`,
                            })}
                            columns={resolutionColumns}
                            hrefFor={(r) => `/governance/resolutions/${r.id}`}
                            actionsFor={(r) => [
                                {
                                    label: 'Open decision',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/resolutions/${r.id}`),
                                },
                            ]}
                        />
                    ) : null}

                    {shows('policies') && capabilities.policies ? (
                        <RecordSection
                            title="Approved policies"
                            icon={BookOpen}
                            page={policies}
                            emptyTitle="No approved policies match"
                            identityLabel="Policy"
                            identity={(p) => ({
                                name: p.title,
                                subline: p.policy_code,
                            })}
                            columns={policyColumns}
                            hrefFor={(p) => `/governance/policies/${p.id}`}
                            actionsFor={(p) => [
                                {
                                    label: 'Open policy',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/policies/${p.id}`),
                                },
                            ]}
                        />
                    ) : null}

                    {shows('documents') && capabilities.documents ? (
                        <RecordSection
                            title="Governance documents & charters"
                            icon={FileText}
                            page={documents}
                            emptyTitle="No documents match"
                            identityLabel="Document"
                            identity={(d) => ({
                                name: d.title,
                                subline: d.file_name,
                            })}
                            columns={documentColumns}
                            hrefFor={(d) => `/governance/documents/${d.id}`}
                            actionsFor={(d) => [
                                {
                                    label: 'Details',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/documents/${d.id}`),
                                },
                                {
                                    label: 'Download',
                                    icon: Download,
                                    onClick: () => {
                                        window.location.href = `/governance/documents/${d.id}/download`;
                                    },
                                },
                            ]}
                        />
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
