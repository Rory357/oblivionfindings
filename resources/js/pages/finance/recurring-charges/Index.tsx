import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
    RecurringChargeDialog,
    type ChargeClientOption,
    type EditableRecurringCharge,
} from '@/components/finance';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
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
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const ALL = '__all';

type RecurringCharge = {
    id: number;
    name: string;
    client_id: number | null;
    description: string;
    amount: number;
    frequency: string;
    is_active: boolean;
    next_charge_date: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
};

type Filters = { q?: string; status?: string };

type Props = {
    charges: {
        data: RecurringCharge[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    stats: {
        active: number;
        inactive: number;
        monthly_total: number;
        next_due: number;
    };
    canManage: boolean;
    clients: ChargeClientOption[];
};

const FREQUENCY_LABELS: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annually: 'Annually',
};

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Recurring charges', href: '/finance/recurring-charges' },
];

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function RecurringChargesIndex({
    charges,
    filters = {},
    stats,
    canManage = false,
    clients = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editCharge, setEditCharge] =
        useState<EditableRecurringCharge | null>(null);
    const [deleteCharge, setDeleteCharge] = useState<RecurringCharge | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState(filters.q ?? '');
    const ctxMenu = useEntityContextMenu<RecurringCharge>();

    const go = (patch: Filters) => {
        const next = { ...filters, ...patch };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        if (next.status && next.status !== ALL) query.status = next.status;
        router.get('/finance/recurring-charges', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        setSearch(filters.q ?? '');
    }, [filters.q]);

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.q ?? '') !== search) {
                go({ q: search.trim() || undefined });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(filters.q || filters.status);

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/recurring-charges', {}, { preserveState: true });
    };

    const openEdit = (charge: RecurringCharge) =>
        setEditCharge({
            id: charge.id,
            client_id: charge.client_id ?? charge.client?.id ?? null,
            description: charge.description || charge.name,
            amount: charge.amount,
            frequency: charge.frequency,
            next_charge_date: charge.next_charge_date,
            is_active: charge.is_active,
        });

    const clientName = (charge: RecurringCharge) =>
        charge.client
            ? `${charge.client.first_name} ${charge.client.last_name}`
            : 'No client';

    const actionsFor = (charge: RecurringCharge): MenuItem[] =>
        compactMenu(
            canManage
                ? [
                      {
                          label: 'Edit charge',
                          icon: Pencil,
                          onClick: () => openEdit(charge),
                      },
                      { separator: true },
                      {
                          label: 'Delete charge',
                          icon: Trash2,
                          danger: true,
                          onClick: () => setDeleteCharge(charge),
                      },
                  ]
                : [],
        );

    const header = (
        <PageHeader
            variant="index"
            icon={RefreshCw}
            title="Recurring charges"
            titleChip={
                <PageHeaderStatusChip
                    variant={stats.active > 0 ? 'success' : 'neutral'}
                >
                    {stats.active} active
                </PageHeaderStatusChip>
            }
            subline={`Scheduled client billing · ${formatMoney(
                stats.monthly_total,
            )} per cycle · ${stats.next_due} due in the next 7 days`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search charges, clients…"
                    />
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New charge
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Active charges"
                        tone="success"
                        href="/finance/recurring-charges?status=active"
                        ariaLabel="View active recurring charges"
                    >
                        <PageHeaderMeterBig>{stats.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Still generating billing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Value per cycle"
                        href="/finance/recurring-charges?status=active"
                        ariaLabel="View the charges making up this value"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(stats.monthly_total)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across active charges
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due this week"
                        tone={stats.next_due > 0 ? 'warning' : 'brand'}
                        href="/finance/recurring-charges?status=active"
                        ariaLabel="View charges due in the next seven days"
                    >
                        <PageHeaderMeterBig>
                            {stats.next_due}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Next charge within 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Paused"
                        tone={stats.inactive > 0 ? 'warning' : 'brand'}
                        href="/finance/recurring-charges?status=inactive"
                        ariaLabel="View inactive recurring charges"
                    >
                        <PageHeaderMeterBig>
                            {stats.inactive}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Not generating billing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={filters.status || ALL}
                    allValue={ALL}
                    options={STATUS_OPTIONS}
                    onChange={(value) =>
                        go({ status: value === ALL ? undefined : value })
                    }
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring charges" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Recurring charges"
                        caption={`${charges.data.length} of ${charges.total} shown`}
                    />

                    {charges.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No charges match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={RefreshCw}
                                itemName="recurring charge"
                                title="No recurring charges yet"
                                description="Set up a scheduled charge so regular client billing raises itself."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New charge
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityCardGrid>
                                {charges.data.map((charge) => (
                                    <EntityCard
                                        key={charge.id}
                                        meridian={
                                            charge.is_active
                                                ? 'success'
                                                : 'warning'
                                        }
                                        icon={RefreshCw}
                                        name={charge.name}
                                        subline={clientName(charge)}
                                        actions={actionsFor(charge)}
                                        onOpen={
                                            canManage
                                                ? () => openEdit(charge)
                                                : undefined
                                        }
                                        openLabel="Edit"
                                        onContextMenu={(e) =>
                                            ctxMenu.open(e, charge)
                                        }
                                        muted={!charge.is_active}
                                        chips={
                                            <>
                                                <EntityStatusChip
                                                    variant={
                                                        charge.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {charge.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </EntityStatusChip>
                                                <EntityChip outline>
                                                    {FREQUENCY_LABELS[
                                                        charge.frequency
                                                    ] ?? charge.frequency}
                                                </EntityChip>
                                                <EntityChip icon={CalendarDays}>
                                                    Next{' '}
                                                    {formatDate(
                                                        charge.next_charge_date,
                                                    )}
                                                </EntityChip>
                                            </>
                                        }
                                        metric={{
                                            label: 'Amount per cycle',
                                            value: formatMoney(charge.amount),
                                            percent: null,
                                        }}
                                    />
                                ))}
                            </EntityCardGrid>
                            <LaravelPagination
                                links={charges.links}
                                lastPage={charges.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={RefreshCw}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage && (
                <RecurringChargeDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    clients={clients}
                />
            )}

            {canManage && editCharge && (
                <RecurringChargeDialog
                    key={editCharge.id}
                    open
                    charge={editCharge}
                    onClose={() => setEditCharge(null)}
                    clients={clients}
                />
            )}

            <ConfirmDialog
                variant="destructive"
                open={deleteCharge !== null}
                onClose={() => setDeleteCharge(null)}
                title="Delete this recurring charge?"
                description={
                    deleteCharge
                        ? `“${deleteCharge.name}” stops generating billing immediately. Billing entries it already raised are kept.`
                        : ''
                }
                confirmText="Delete charge"
                processing={deleting}
                onConfirm={() => {
                    if (!deleteCharge) return;
                    router.delete(
                        `/finance/recurring-charges/${deleteCharge.id}`,
                        {
                            preserveScroll: true,
                            onStart: () => setDeleting(true),
                            onFinish: () => {
                                setDeleting(false);
                                setDeleteCharge(null);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
