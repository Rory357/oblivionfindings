import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    Calendar,
    Compass,
    Download,
    Eye,
    FileText,
    FolderArchive,
    Gavel,
    Layers,
    PiggyBank,
    Tag,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

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
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import {
    financialYearLabel,
    governanceStatus,
    meetingTypeLabel,
    policyCategoryLabel,
    refSuffix,
    resolutionChip,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';

interface DocumentRecord {
    id: number;
    title: string;
    category: string;
    category_label: string;
    file_name: string;
    format_label: string;
    file_size: number;
    version: number;
    updated_at: string | null;
}

interface MeetingRecord {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string | null;
    status: string | null;
    has_minutes: boolean;
    minutes_status?: string | null;
    minutes_version?: number | null;
}

interface ResolutionRecord {
    id: number;
    resolution_reference: string | null;
    title: string;
    status: string;
    outcome: string | null;
    voting_threshold: string | null;
    meeting_title?: string | null;
    decided_at: string | null;
}

interface PolicyRecord {
    id: number;
    title: string;
    category: string;
    version_number: number;
    effective_from?: string | null;
}

interface BudgetRecord {
    id: number;
    title: string;
    fiscal_year: string | number | null;
    status: string;
    approved_at: string | null;
}

interface PlanRecord {
    id: number;
    title: string;
    status: string;
    version_number: number | null;
    period_start: string | null;
    period_end: string | null;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Capabilities {
    documents: boolean;
    meetings: boolean;
    resolutions: boolean;
    policies: boolean;
    budgets?: boolean;
    plans?: boolean;
}

interface Props extends PageProps {
    tab: string;
    search?: string | null;
    category?: string | null;
    capabilities: Capabilities;
    documents: PaginatedData<DocumentRecord> | null;
    meetings: PaginatedData<MeetingRecord> | null;
    resolutions: PaginatedData<ResolutionRecord> | null;
    policies: PaginatedData<PolicyRecord> | null;
    budgets?: PaginatedData<BudgetRecord> | null;
    plans?: PaginatedData<PlanRecord> | null;
    categories: Array<{ value: string; label: string }>;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** One record-type section: caption, entity table (or truthful empty state), pager. */
function RecordSection<T extends { id: number }>({
    title,
    icon,
    page,
    searching,
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
    searching: boolean;
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
                    title={searching ? emptyTitle : 'Nothing recorded yet'}
                    description={
                        searching
                            ? 'Try another search term or record type.'
                            : undefined
                    }
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
    budgets = null,
    plans = null,
    categories,
}: Props) {
    const currentTab = initialTab || 'all';
    const [searchQuery, setSearchQuery] = useState(initialSearch || '');
    const lastSent = useRef(initialSearch || '');

    useEffect(() => {
        setSearchQuery(initialSearch || '');
        lastSent.current = initialSearch || '';
    }, [initialSearch]);

    const visit = (params: {
        tab?: string;
        search?: string;
        category?: string | null;
    }) => {
        const next = {
            tab: params.tab ?? currentTab,
            search: (params.search ?? searchQuery).trim() || undefined,
            category:
                (params.category === undefined ? category : params.category) ||
                undefined,
        };
        router.get('/governance/records', next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Search as you type, like the other registers.
    useEffect(() => {
        const handle = setTimeout(() => {
            const term = searchQuery.trim();
            if (term !== lastSent.current.trim()) {
                lastSent.current = term;
                visit({ search: term });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchQuery]);

    const typeOptions = [
        { value: 'all', label: 'All record types' },
        ...(capabilities.meetings
            ? [{ value: 'meetings', label: 'Meetings and minutes' }]
            : []),
        ...(capabilities.resolutions
            ? [{ value: 'resolutions', label: 'Resolutions' }]
            : []),
        ...(capabilities.policies
            ? [{ value: 'policies', label: 'Policies' }]
            : []),
        ...(capabilities.documents
            ? [{ value: 'documents', label: 'Documents' }]
            : []),
        ...(capabilities.budgets
            ? [{ value: 'budgets', label: 'Budgets' }]
            : []),
        ...(capabilities.plans
            ? [{ value: 'plans', label: 'Strategic plans' }]
            : []),
    ];

    const shows = (key: string) => currentTab === 'all' || currentTab === key;
    const searching = Boolean(initialSearch || category);
    const hasFilters = Boolean(initialSearch || category || currentTab !== 'all');
    const showCategory =
        capabilities.documents && (currentTab === 'all' || currentTab === 'documents');

    const recordCount = [
        capabilities.meetings ? meetings?.total : 0,
        capabilities.resolutions ? resolutions?.total : 0,
        capabilities.policies ? policies?.total : 0,
        capabilities.documents ? documents?.total : 0,
        capabilities.budgets ? budgets?.total : 0,
        capabilities.plans ? plans?.total : 0,
    ].reduce<number>((sum, value) => sum + (value ?? 0), 0);

    const meetingColumns: EntityTableColumn<MeetingRecord>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (m) => {
                const chip = governanceStatus('meeting_status', m.status);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'minutes',
            label: 'Minutes',
            width: '1fr',
            cell: (m) => {
                if (!m.has_minutes) return <EmptyValue />;
                const chip = governanceStatus('minutes_status', m.minutes_status);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
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
            key: 'result',
            label: 'Result',
            width: '0.9fr',
            cell: (r) => {
                const chip = resolutionChip(r.status, r.outcome);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'date',
            label: 'Decided',
            width: '0.9fr',
            cell: (r) => formatDateLong(r.decided_at, 'Not recorded'),
        },
    ];

    const policyColumns: EntityTableColumn<PolicyRecord>[] = [
        {
            key: 'category',
            label: 'Category',
            width: '1fr',
            cell: (p) => (
                <EntityChip icon={Tag}>{policyCategoryLabel(p.category)}</EntityChip>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.5fr',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    {p.version_number}
                </span>
            ),
        },
        {
            key: 'effective',
            label: 'In effect from',
            width: '0.9fr',
            cell: (p) => formatDateOnly(p.effective_from),
        },
    ];

    const documentColumns: EntityTableColumn<DocumentRecord>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1fr',
            cell: (d) => <EntityChip icon={Tag}>{d.category_label}</EntityChip>,
        },
        {
            key: 'format',
            label: 'Format',
            width: '0.7fr',
            cell: (d) => (
                <span className="text-muted-foreground">{d.format_label}</span>
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

    const budgetColumns: EntityTableColumn<BudgetRecord>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (b) => {
                const chip = governanceStatus('budget_status', b.status);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'approved',
            label: 'Approved by the board',
            width: '1fr',
            cell: (b) => formatDateLong(b.approved_at, 'Not recorded'),
        },
    ];

    const planColumns: EntityTableColumn<PlanRecord>[] = [
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (p) => {
                const chip = governanceStatus('strategic_plan_status', p.status);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'period',
            label: 'Covers',
            width: '1.2fr',
            cell: (p) =>
                p.period_start || p.period_end
                    ? `${formatDateOnly(p.period_start)} – ${formatDateOnly(p.period_end)}`
                    : <EmptyValue />,
        },
    ];

    const header = (
        <PageHeader
            icon={FolderArchive}
            title="Records"
            titleChip={
                <PageHeaderStatusChip variant={recordCount > 0 ? 'info' : 'neutral'}>
                    {recordCount > 0
                        ? plural(recordCount, 'record', 'records')
                        : 'Nothing recorded yet'}
                </PageHeaderStatusChip>
            }
            subline="Search everything the board has done: past meetings, resolutions, policies and files"
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
                                held, with their minutes
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.resolutions && (
                        <PageHeaderMeterBlock
                            label="Resolutions"
                            href="/governance/records?tab=resolutions"
                        >
                            <PageHeaderMeterBig>
                                {resolutions?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                decided by the board
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.policies && (
                        <PageHeaderMeterBlock
                            label="Policies"
                            href="/governance/records?tab=policies"
                        >
                            <PageHeaderMeterBig>
                                {policies?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>approved</PageHeaderMeterCaption>
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
                                reference files
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.budgets && (
                        <PageHeaderMeterBlock
                            label="Budgets"
                            href="/governance/records?tab=budgets"
                        >
                            <PageHeaderMeterBig>
                                {budgets?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                approved by the board
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    {capabilities.plans && (
                        <PageHeaderMeterBlock
                            label="Strategic plans"
                            href="/governance/records?tab=plans"
                        >
                            <PageHeaderMeterBig>
                                {plans?.total ?? 0}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                approved by the board
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                </>
            }
            actions={
                <PageHeaderSearch
                    value={searchQuery}
                    onChange={setSearchQuery}
                    placeholder="Search records…"
                />
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Layers}
                        label="Record type"
                        value={currentTab}
                        options={typeOptions}
                        onChange={(value) => visit({ tab: value })}
                    />
                    {showCategory ? (
                        <PageHeaderFilterSelect
                            icon={Tag}
                            label="Document type"
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
        !capabilities.documents &&
        !capabilities.budgets &&
        !capabilities.plans;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Records', href: '/governance/records' },
            ]}
        >
            <Head title="Records" />
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
                            title="No records are available to you"
                            description="Records appear here for the parts of Governance your role can see."
                        />
                    ) : null}

                    {shows('meetings') && capabilities.meetings ? (
                        <RecordSection
                            title="Past meetings and minutes"
                            icon={Calendar}
                            page={meetings}
                            searching={searching}
                            emptyTitle="No past meetings match"
                            identityLabel="Meeting"
                            identity={(m) => ({
                                name: m.title,
                                subline: meetingTypeLabel(m.meeting_type),
                            })}
                            columns={meetingColumns}
                            hrefFor={(m) => `/governance/meetings/${m.id}`}
                            actionsFor={(m) => [
                                {
                                    label: 'Open meeting',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/meetings/${m.id}`),
                                },
                            ]}
                        />
                    ) : null}

                    {shows('resolutions') && capabilities.resolutions ? (
                        <RecordSection
                            title="Resolutions"
                            icon={Gavel}
                            page={resolutions}
                            searching={searching}
                            emptyTitle="No resolutions match"
                            identityLabel="Resolution"
                            identity={(r) => ({
                                name: r.title,
                                subline: [
                                    r.meeting_title ? `From ${r.meeting_title}` : null,
                                    refSuffix(r.resolution_reference) || null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            columns={resolutionColumns}
                            hrefFor={(r) => `/governance/resolutions/${r.id}`}
                            actionsFor={(r) => [
                                {
                                    label: 'Open resolution',
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
                            searching={searching}
                            emptyTitle="No approved policies match"
                            identityLabel="Policy"
                            identity={(p) => ({
                                name: p.title,
                                subline: [
                                    policyCategoryLabel(p.category),
                                    p.effective_from
                                        ? `In effect from ${formatDateOnly(p.effective_from)}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
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
                            title="Documents"
                            icon={FileText}
                            page={documents}
                            searching={searching}
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
                                    label: 'Open details',
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

                    {shows('budgets') && capabilities.budgets ? (
                        <RecordSection
                            title="Approved budgets"
                            icon={PiggyBank}
                            page={budgets}
                            searching={searching}
                            emptyTitle="No approved budgets match"
                            identityLabel="Budget"
                            identity={(b) => ({
                                name: b.title,
                                subline: b.fiscal_year
                                    ? `Financial year ${financialYearLabel(b.fiscal_year)}`
                                    : undefined,
                            })}
                            columns={budgetColumns}
                            hrefFor={(b) => `/governance/budgets/${b.id}`}
                            actionsFor={(b) => [
                                {
                                    label: 'Open budget',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/budgets/${b.id}`),
                                },
                            ]}
                        />
                    ) : null}

                    {shows('plans') && capabilities.plans ? (
                        <RecordSection
                            title="Approved strategic plans"
                            icon={Compass}
                            page={plans}
                            searching={searching}
                            emptyTitle="No approved strategic plans match"
                            identityLabel="Strategic plan"
                            identity={(p) => ({
                                name: p.title,
                                subline: p.version_number
                                    ? `Version ${p.version_number}`
                                    : undefined,
                            })}
                            columns={planColumns}
                            hrefFor={(p) => `/governance/strategy/${p.id}`}
                            actionsFor={(p) => [
                                {
                                    label: 'Open strategic plan',
                                    icon: Eye,
                                    onClick: () =>
                                        router.visit(`/governance/strategy/${p.id}`),
                                },
                            ]}
                        />
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
