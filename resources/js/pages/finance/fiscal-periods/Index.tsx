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
import { formatDateOnly } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarRange, Lock, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

import { FiscalPeriodDialog, type FiscalPeriod } from './_dialogs';

type PageProps = {
    periods: FiscalPeriod[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Fiscal periods', href: '/finance/fiscal-periods' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'locked', label: 'Locked' },
];

const periodDate = (value: string) =>
    formatDateOnly(value.slice(0, 10), value);

export default function FiscalPeriodsIndex({ periods }: PageProps) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [createOpen, setCreateOpen] = useState(false);
    const [editTarget, setEditTarget] = useState<FiscalPeriod | null>(null);
    const [closeTarget, setCloseTarget] = useState<FiscalPeriod | null>(null);
    const [closing, setClosing] = useState(false);

    const ctx = useEntityContextMenu<FiscalPeriod>();

    const handleClose = () => {
        if (!closeTarget) return;
        setClosing(true);
        router.post(
            `/finance/fiscal-periods/${closeTarget.id}/close`,
            {},
            {
                onFinish: () => setClosing(false),
                onSuccess: () => setCloseTarget(null),
            },
        );
    };

    const openCount = periods.filter((p) => p.status === 'open').length;
    const closedCount = periods.filter((p) => p.status === 'closed').length;
    const lockedCount = periods.filter((p) => p.status === 'locked').length;

    const query = search.trim().toLowerCase();
    const shown = periods.filter((period) => {
        const matchesText =
            query === '' || period.name.toLowerCase().includes(query);
        const matchesStatus =
            statusFilter === 'all' || period.status === statusFilter;
        return matchesText && matchesStatus;
    });

    const actionsFor = (period: FiscalPeriod): MenuItem[] => {
        const items: MenuItem[] = [];
        if (period.status === 'open') {
            items.push({
                label: 'Edit period',
                icon: Pencil,
                onClick: () => setEditTarget(period),
            });
            items.push({
                label: 'Close period',
                icon: Lock,
                onClick: () => setCloseTarget(period),
            });
        }
        return items;
    };

    const columns: EntityTableColumn<FiscalPeriod>[] = [
        {
            key: 'start',
            label: 'Start date',
            width: '150px',
            cell: (period) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {periodDate(period.start_date)}
                </span>
            ),
        },
        {
            key: 'end',
            label: 'End date',
            width: '150px',
            cell: (period) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {periodDate(period.end_date)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '130px',
            cell: (period) => <StatusBadge status={period.status} />,
        },
        {
            key: 'closed_by',
            label: 'Closed by',
            width: '190px',
            cell: (period) =>
                period.closed_by ? (
                    <span className="truncate">{period.closed_by}</span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    const header = (
        <PageHeader
            icon={CalendarRange}
            title="Fiscal periods"
            titleChip={
                <PageHeaderStatusChip
                    variant={openCount > 0 ? 'info' : 'neutral'}
                >
                    {openCount} open
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${periods.length} periods · ${closedCount} closed · ${lockedCount} locked`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search period name…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        New period
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Periods"
                        href="/finance/fiscal-periods"
                        ariaLabel="View every fiscal period"
                    >
                        <PageHeaderMeterBig>
                            {periods.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            accounting periods on record
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Open"
                        tone="success"
                        ariaLabel="Show only open periods"
                        onClick={() => setStatusFilter('open')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                periods.length === 0
                                    ? 0
                                    : (openCount / periods.length) * 100
                            }
                            caption={`${openCount} of ${periods.length} accepting postings`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Closed"
                        ariaLabel="Show only closed periods"
                        onClick={() => setStatusFilter('closed')}
                    >
                        <PageHeaderMeterBig>{closedCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            no further journals accepted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Locked"
                        tone="warning"
                        ariaLabel="Show only locked periods"
                        onClick={() => setStatusFilter('locked')}
                    >
                        <PageHeaderMeterBig>{lockedCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            sealed after year-end
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={statusFilter}
                    allValue="all"
                    options={STATUS_OPTIONS}
                    onChange={setStatusFilter}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal periods" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Fiscal periods"
                        caption={`${shown.length} of ${periods.length} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={CalendarRange}
                            heading={
                                periods.length === 0
                                    ? 'No fiscal periods yet'
                                    : 'No periods match your search'
                            }
                            description={
                                periods.length === 0
                                    ? 'Create your first period so journals, invoices and bills know which accounting period they belong to.'
                                    : 'Clear the search or the status filter to see every period.'
                            }
                            action={
                                periods.length === 0 ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New period
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            setStatusFilter('all');
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
                            rowKey={(period) => period.id}
                            identityLabel="Period"
                            minWidth={860}
                            identity={(period) => ({
                                icon: CalendarRange,
                                name: period.name,
                                subline: `${periodDate(period.start_date)} – ${periodDate(period.end_date)}`,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            mutedFor={(period) => period.status !== 'open'}
                            onOpen={(period) => {
                                if (period.status === 'open') {
                                    setEditTarget(period);
                                }
                            }}
                            onRowContextMenu={(e, period) =>
                                ctx.open(e, period)
                            }
                        />
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={CalendarRange}
                    title={ctx.ctx.record.name}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            <FiscalPeriodDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
            />

            {editTarget ? (
                <FiscalPeriodDialog
                    key={editTarget.id}
                    open
                    period={editTarget}
                    onClose={() => setEditTarget(null)}
                />
            ) : null}

            <ConfirmDialog
                open={!!closeTarget}
                onClose={() => setCloseTarget(null)}
                title="Close fiscal period?"
                description={
                    <>
                        This closes{' '}
                        <span className="font-medium text-foreground">
                            {closeTarget?.name} (
                            {closeTarget
                                ? periodDate(closeTarget.start_date)
                                : ''}{' '}
                            –{' '}
                            {closeTarget ? periodDate(closeTarget.end_date) : ''}
                            )
                        </span>
                        . Once closed,{' '}
                        <span className="font-medium text-foreground">
                            no further journals can be posted
                        </span>{' '}
                        to this period — invoices, bills, payments and manual
                        journals dated inside it will be rejected. Make sure the
                        period is fully reconciled first.
                    </>
                }
                confirmText="Close period"
                variant="destructive"
                processing={closing}
                onConfirm={handleClose}
            />
        </AppLayout>
    );
}
