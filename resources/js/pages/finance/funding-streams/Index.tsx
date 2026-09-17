import {
    ConfirmDialog,
    type EditableFundingStream,
    FinanceSectionRail,
    FUNDING_STREAM_FUNDER_TYPES,
    FundingStreamDialog,
    fundingStreamFunderTypeLabel,
    type FundingStreamRevenueAccount,
} from '@/components/finance';
import {
    EmptyValue,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Sprout, Trash2 } from 'lucide-react';
import { useState } from 'react';

type RevenueAccount = FundingStreamRevenueAccount;

type FundingStream = {
    id: number;
    code: string;
    name: string;
    funder_type: string | null;
    contact_name: string | null;
    contact_email: string | null;
    default_revenue_account_id: number | null;
    default_revenue_account: RevenueAccount | null;
    is_active: boolean;
};

type PageProps = {
    fundingStreams: FundingStream[];
    revenueAccounts: RevenueAccount[];
    canManage: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Settings', href: '/finance/settings' },
    { title: 'Funding streams', href: '/finance/funding-streams' },
];

const funderTypeLabel = fundingStreamFunderTypeLabel;

const ACTIVE_OPTIONS = [
    { value: 'all', label: 'All funding streams' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

export default function FundingStreamsIndex({
    fundingStreams,
    revenueAccounts,
    canManage = false,
}: PageProps) {
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [funderFilter, setFunderFilter] = useState('all');
    const [createOpen, setCreateOpen] = useState(false);
    const [editStream, setEditStream] = useState<EditableFundingStream | null>(
        null,
    );
    const [deleteTarget, setDeleteTarget] = useState<FundingStream | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);

    const ctx = useEntityContextMenu<FundingStream>();

    function confirmDelete() {
        if (!deleteTarget) return;
        router.delete(`/finance/funding-streams/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    }

    const openEdit = (fs: FundingStream) =>
        setEditStream({
            id: fs.id,
            code: fs.code,
            name: fs.name,
            funder_type: fs.funder_type,
            contact_name: fs.contact_name,
            contact_email: fs.contact_email,
            default_revenue_account_id: fs.default_revenue_account_id,
            is_active: fs.is_active,
        });

    const activeCount = fundingStreams.filter((fs) => fs.is_active).length;
    const codedCount = fundingStreams.filter(
        (fs) => fs.default_revenue_account,
    ).length;

    // Only offer funder types that actually appear in the register.
    const funderOptions = [
        { value: 'all', label: 'All funder types' },
        ...FUNDING_STREAM_FUNDER_TYPES.filter((ft) =>
            fundingStreams.some((fs) => fs.funder_type === ft.value),
        ),
        ...(fundingStreams.some((fs) => !fs.funder_type)
            ? [{ value: 'none', label: 'Not specified' }]
            : []),
    ];

    const query = search.trim().toLowerCase();
    const shown = fundingStreams.filter((fs) => {
        const matchesText =
            query === '' ||
            fs.code.toLowerCase().includes(query) ||
            fs.name.toLowerCase().includes(query) ||
            (funderTypeLabel(fs.funder_type) ?? '')
                .toLowerCase()
                .includes(query) ||
            (fs.contact_name ?? '').toLowerCase().includes(query);
        const matchesActive =
            activeFilter === 'all' ||
            (activeFilter === 'active' ? fs.is_active : !fs.is_active);
        const matchesFunder =
            funderFilter === 'all' ||
            (funderFilter === 'none'
                ? !fs.funder_type
                : fs.funder_type === funderFilter);
        return matchesText && matchesActive && matchesFunder;
    });

    const actionsFor = (fs: FundingStream): MenuItem[] =>
        canManage
            ? [
                  {
                      label: 'Edit funding stream',
                      icon: Pencil,
                      onClick: () => openEdit(fs),
                  },
                  {
                      label: 'Delete funding stream',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setDeleteTarget(fs),
                  },
              ]
            : [];

    const columns: EntityTableColumn<FundingStream>[] = [
        {
            key: 'funder_type',
            label: 'Funder type',
            width: '200px',
            cell: (fs) =>
                fs.funder_type ? (
                    <span className="truncate text-muted-foreground">
                        {funderTypeLabel(fs.funder_type)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'revenue_account',
            label: 'Default revenue account',
            width: '1.4fr',
            cell: (fs) =>
                fs.default_revenue_account ? (
                    <span className="truncate">
                        <span className="font-mono">
                            {fs.default_revenue_account.code}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {fs.default_revenue_account.name}
                        </span>
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'contact',
            label: 'Funder contact',
            width: '1fr',
            cell: (fs) =>
                fs.contact_name || fs.contact_email ? (
                    <span className="truncate text-muted-foreground">
                        {fs.contact_name ?? fs.contact_email}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '140px',
            cell: (fs) => (
                <StatusBadge variant={fs.is_active ? 'success' : 'neutral'}>
                    {fs.is_active ? 'Active' : 'Inactive'}
                </StatusBadge>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={Sprout}
            title="Funding streams"
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {activeCount} active
                </PageHeaderStatusChip>
            }
            subline={`Settings · ${fundingStreams.length} streams · funder sources and their default revenue coding`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search code, name, funder or contact…"
                    />
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New funding stream
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Funding streams"
                        ariaLabel="Show every funding stream"
                        onClick={() => {
                            setActiveFilter('all');
                            setFunderFilter('all');
                            setSearch('');
                        }}
                    >
                        <PageHeaderMeterBig>
                            {fundingStreams.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            available to invoices and journals
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        ariaLabel="Show only active funding streams"
                        onClick={() => setActiveFilter('active')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                fundingStreams.length === 0
                                    ? 0
                                    : (activeCount / fundingStreams.length) *
                                      100
                            }
                            caption={`${activeCount} of ${fundingStreams.length} allocatable`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Inactive"
                        tone="warning"
                        ariaLabel="Show only inactive funding streams"
                        onClick={() => setActiveFilter('inactive')}
                    >
                        <PageHeaderMeterBig>
                            {fundingStreams.length - activeCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            hidden from new allocations
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Revenue coded"
                        href="/finance/accounts"
                        ariaLabel="View the chart of accounts"
                    >
                        <PageHeaderMeterBig>{codedCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {revenueAccounts.length} revenue accounts to code to
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Funder type"
                        value={funderFilter}
                        allValue="all"
                        options={funderOptions}
                        onChange={setFunderFilter}
                    />
                    <PageHeaderFilterSelect
                        label="Active state"
                        value={activeFilter}
                        allValue="all"
                        options={ACTIVE_OPTIONS}
                        onChange={setActiveFilter}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funding streams" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Funding streams"
                        caption={`${shown.length} of ${fundingStreams.length} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={Sprout}
                            heading={
                                fundingStreams.length === 0
                                    ? 'No funding streams yet'
                                    : 'No funding streams match your filters'
                            }
                            description={
                                fundingStreams.length === 0
                                    ? 'Add your first funding stream so invoices, journals and claims can be attributed to the funder that pays for them.'
                                    : 'Clear the search, funder type or active-state filter to see every funding stream.'
                            }
                            action={
                                fundingStreams.length === 0 ? (
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New funding stream
                                        </Button>
                                    ) : undefined
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setActiveFilter('all');
                                            setFunderFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={shown}
                            rowKey={(fs) => fs.id}
                            identityLabel="Funding stream"
                            minWidth={980}
                            identity={(fs) => ({
                                icon: Sprout,
                                name: fs.name,
                                subline: fs.code,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            mutedFor={(fs) => !fs.is_active}
                            onOpen={
                                canManage ? (fs) => openEdit(fs) : undefined
                            }
                            onRowContextMenu={(e, fs) => ctx.open(e, fs)}
                        />
                    )}
                </div>
            </PageLayout>

            {ctx.ctx && canManage ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Sprout}
                    title={`${ctx.ctx.record.code} — ${ctx.ctx.record.name}`}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage && (
                <FundingStreamDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    revenueAccounts={revenueAccounts}
                />
            )}

            {canManage && editStream && (
                <FundingStreamDialog
                    key={editStream.id}
                    open
                    fundingStream={editStream}
                    onClose={() => setEditStream(null)}
                    revenueAccounts={revenueAccounts}
                />
            )}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                title="Delete funding stream?"
                description={
                    <>
                        This permanently deletes the funding stream{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.code} — {deleteTarget?.name}
                        </span>
                        . This can&rsquo;t be undone.
                    </>
                }
                confirmText="Delete funding stream"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
