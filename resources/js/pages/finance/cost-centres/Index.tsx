import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
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
import { Building2, Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { CostCentreDialog, type CostCentre } from './_dialogs';

type PageProps = {
    costCentres: CostCentre[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Cost centres', href: '/finance/cost-centres' },
];

const ACTIVE_OPTIONS = [
    { value: 'all', label: 'All cost centres' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

export default function CostCentresIndex({ costCentres }: PageProps) {
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [createOpen, setCreateOpen] = useState(false);
    const [editTarget, setEditTarget] = useState<CostCentre | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<CostCentre | null>(null);
    const [deleting, setDeleting] = useState(false);

    const ctx = useEntityContextMenu<CostCentre>();

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/finance/cost-centres/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const activeCount = costCentres.filter((c) => c.is_active).length;
    const typeCount = new Set(
        costCentres.map((c) => c.type).filter(Boolean),
    ).size;

    const query = search.trim().toLowerCase();
    const shown = costCentres.filter((centre) => {
        const matchesText =
            query === '' ||
            centre.code.toLowerCase().includes(query) ||
            centre.name.toLowerCase().includes(query) ||
            (centre.type ?? '').toLowerCase().includes(query);
        const matchesActive =
            activeFilter === 'all' ||
            (activeFilter === 'active' ? centre.is_active : !centre.is_active);
        return matchesText && matchesActive;
    });

    const actionsFor = (centre: CostCentre): MenuItem[] => [
        {
            label: 'Edit cost centre',
            icon: Pencil,
            onClick: () => setEditTarget(centre),
        },
        {
            label: 'Delete cost centre',
            icon: Trash2,
            danger: true,
            onClick: () => setDeleteTarget(centre),
        },
    ];

    const columns: EntityTableColumn<CostCentre>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '200px',
            cell: (centre) =>
                centre.type ? (
                    <span className="truncate text-muted-foreground">
                        {centre.type}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '140px',
            cell: (centre) => (
                <StatusBadge variant={centre.is_active ? 'success' : 'neutral'}>
                    {centre.is_active ? 'Active' : 'Inactive'}
                </StatusBadge>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={Layers}
            title="Cost centres"
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {activeCount} active
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${costCentres.length} cost centres · expense tracking and allocation`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search code, name or type…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        New cost centre
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Cost centres"
                        href="/finance/cost-centres"
                        ariaLabel="View every cost centre"
                    >
                        <PageHeaderMeterBig>
                            {costCentres.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            available to journals and bills
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        ariaLabel="Show only active cost centres"
                        onClick={() => setActiveFilter('active')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                costCentres.length === 0
                                    ? 0
                                    : (activeCount / costCentres.length) * 100
                            }
                            caption={`${activeCount} of ${costCentres.length} in use`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Inactive"
                        tone="warning"
                        ariaLabel="Show only inactive cost centres"
                        onClick={() => setActiveFilter('inactive')}
                    >
                        <PageHeaderMeterBig>
                            {costCentres.length - activeCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            retired from allocation
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Types"
                        href="/finance/journals"
                        ariaLabel="View journals coded to cost centres"
                    >
                        <PageHeaderMeterBig>{typeCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            department, site or programme groupings
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Active state"
                    value={activeFilter}
                    allValue="all"
                    options={ACTIVE_OPTIONS}
                    onChange={setActiveFilter}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cost centres" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Cost centres"
                        caption={`${shown.length} of ${costCentres.length} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            heading={
                                costCentres.length === 0
                                    ? 'No cost centres yet'
                                    : 'No cost centres match your search'
                            }
                            description={
                                costCentres.length === 0
                                    ? 'Create your first cost centre so journals and bills can be coded to a department, site or programme.'
                                    : 'Clear the search or the active-state filter to see every cost centre.'
                            }
                            action={
                                costCentres.length === 0 ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New cost centre
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setActiveFilter('all');
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
                            rowKey={(centre) => centre.id}
                            identityLabel="Cost centre"
                            minWidth={720}
                            identity={(centre) => ({
                                icon: Layers,
                                name: centre.name,
                                subline: centre.code,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            mutedFor={(centre) => !centre.is_active}
                            onOpen={(centre) => setEditTarget(centre)}
                            onRowContextMenu={(e, centre) =>
                                ctx.open(e, centre)
                            }
                        />
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Layers}
                    title={`${ctx.ctx.record.code} — ${ctx.ctx.record.name}`}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            <CostCentreDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
            />

            {editTarget ? (
                <CostCentreDialog
                    key={editTarget.id}
                    open
                    costCentre={editTarget}
                    onClose={() => setEditTarget(null)}
                />
            ) : null}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                title="Delete cost centre?"
                description={
                    <>
                        This permanently deletes cost centre{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.code} — {deleteTarget?.name}
                        </span>
                        . This can&rsquo;t be undone.
                    </>
                }
                confirmText="Delete cost centre"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
