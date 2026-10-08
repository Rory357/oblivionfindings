import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import { MedicationJourneyReturn } from '@/components/emar/medication-journey-return';
import {
    StockDetailDialog,
    type OpenOrderSummary,
} from '@/components/emar/stock-detail-dialog';
import {
    EmarMeters,
    EmarViewFilter,
} from '@/components/emar/workspace-navigation';
import { EntityStatusChip } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { EntityFilter, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { emarScopedHref } from '@/lib/emar-navigation';
import {
    createMedicationMutationReplayState,
    emarMutationWasAccepted,
    prepareMedicationMutationReplayState,
    submitEmarMutation,
    type MedicationMutationReplayState,
} from '@/lib/emar-offline';
import {
    AdjustStockDialog,
    ControlledPharmacyDeliveryDialog,
    NewPharmacyOrderDialog,
    PharmacyDeliveryDialog,
    ReceiveStockDialog,
    StockCountDialog,
    type ClientOpt,
    type StaffOpt,
    type StockMed,
    type StockRow,
} from '@/pages/emar/_stock-dialogs';
import { pharmacyOrderAdvanceAction } from '@/pages/emar/medication-stock-governance';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Barcode,
    CalendarX2,
    ClipboardCheck,
    Clock,
    Eye,
    FileText,
    Package,
    Pencil,
    Plus,
    ShieldCheck,
    ShoppingCart,
    Truck,
    User,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useStockFilters } from './stock/_filters';
import {
    controlledColumns,
    pharmacyColumns,
    PharmacyOrderDetail,
    StockHubTable,
} from './stock/_hub-tables';
import {
    PHARMACY_NEXT_LABEL as NEXT_LABEL,
    type ControlledRegisterRow,
    type PharmacyOrderRow as OrderRow,
    type StockFilters,
    type StockPagination,
    type StockSummary,
} from './stock/_hub-types';
import { StockInventoryTable } from './stock/_inventory-table';
import { StockPagedList } from './stock/_pagination';

type Props = {
    can_record_controlled: boolean;
    can_view_controlled: boolean;
    stockItems: StockRow[];
    stockContext: StockRow[];
    openOrdersByMedication: Record<number, OrderRow>;
    filters: StockFilters;
    pagination: StockPagination;
    summary: StockSummary;
    lowStockCount: number;
    expiringCount: number;
    expiredCount: number;
    controlledRegister: ControlledRegisterRow[];
    pharmacyOrders: OrderRow[];
    selected_pharmacy_order?: OrderRow | null;
    clients: ClientOpt[];
    activeMedications: StockMed[];
    witnesses: StaffOpt[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    client_id: number | null;
};

type Modal =
    | { type: 'order-detail'; order: OrderRow }
    | { type: 'order'; clientId?: number; medId?: number }
    | { type: 'receive'; medId?: number }
    | { type: 'controlled-delivery'; order: OrderRow; item: StockRow }
    | { type: 'delivery'; order: OrderRow }
    | { type: 'count'; medId?: number; controlledOnly?: boolean }
    | { type: 'adjust'; item: StockRow }
    | { type: 'detail'; item: StockRow }
    | null;

const STALE_ORDER_DAYS = 7;

export default function StockManagement({
    can_record_controlled: canRecordControlled,
    can_view_controlled: canViewControlled,
    stockItems,
    stockContext,
    openOrdersByMedication,
    filters: serverFilters,
    pagination,
    summary,
    lowStockCount,
    expiringCount,
    expiredCount,
    controlledRegister,
    pharmacyOrders,
    selected_pharmacy_order,
    clients,
    activeMedications,
    witnesses,
    sites,
    active_site: activeSite,
    site_brand_colour: brandColour,
}: Props) {
    const breadcrumbs = useEmarBreadcrumbs();
    const { url: pageUrl } = usePage();
    const {
        filters,
        busy: loading,
        failed,
        change,
        retry,
    } = useStockFilters(serverFilters);
    const {
        view: activeTab,
        q: search,
        chip,
        site_id: siteFilter,
        client_id: clientFilter,
    } = filters;
    const setActiveTab = (view: string) => change({ view });
    const setSearch = (q: string) => change({ q }, 250);
    const setChip = (value: StockFilters['chip']) => change({ chip: value });
    const onSite = (site_id: number | null) => change({ site_id });
    const onClient = (client_id: number | null) => change({ client_id });
    const [advancing, setAdvancing] = useState<number | null>(null);
    const advanceInFlight = useRef(false);
    const loadStockContext = useCallback(
        async (
            medicationId: number,
            signal: AbortSignal,
        ): Promise<StockRow | null> => {
            const params = new URLSearchParams();
            if (siteFilter) params.set('site_id', String(siteFilter));
            if (clientFilter) params.set('client_id', String(clientFilter));
            const response = await fetch(
                '/emar/stock/context/' + medicationId + '?' + params,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal,
                },
            );
            if (!response.ok)
                throw new Error('Stock details could not be loaded');
            return (await response.json()).stock_item;
        },
        [siteFilter, clientFilter],
    );
    const [modal, setModal] = useState<Modal>(null);
    useEffect(() => {
        if (selected_pharmacy_order)
            setModal({ type: 'order-detail', order: selected_pharmacy_order });
    }, [selected_pharmacy_order]);
    const orderAdvanceReplay = useRef(
        new Map<number, MedicationMutationReplayState>(),
    );
    const [dismissed, setDismissed] = useState<string[]>([]);

    const controlledGovernedMedications = useMemo(
        () =>
            canRecordControlled
                ? activeMedications
                : activeMedications.filter(
                      (medication) => !medication.controlled,
                  ),
        [activeMedications, canRecordControlled],
    );

    const cdDiscrepancies = summary.controlled_discrepancies;
    const overdueOrders = summary.overdue_orders;

    // Stacked, dismissible alert strip — every count is already computed above.
    const alerts = (
        [
            {
                key: 'cd',
                tone: 'critical',
                icon: ShieldCheck,
                count: cdDiscrepancies,
                message: `${cdDiscrepancies} controlled-drug count${cdDiscrepancies === 1 ? '' : 's'} with an unreconciled balance — investigate before close of shift.`,
                tab: 'controlled',
            },
            {
                key: 'expired',
                tone: 'critical',
                icon: CalendarX2,
                count: expiredCount,
                message: `${expiredCount} item${expiredCount === 1 ? '' : 's'} expired — quarantine and remove from use.`,
                tab: 'expired',
            },
            {
                key: 'low',
                tone: 'warning',
                icon: AlertTriangle,
                count: lowStockCount,
                message: `${lowStockCount} item${lowStockCount === 1 ? '' : 's'} at or below reorder level — reorder now.`,
                tab: 'low',
            },
            {
                key: 'orders',
                tone: 'warning',
                icon: Clock,
                count: overdueOrders,
                message: `${overdueOrders} pharmacy order${overdueOrders === 1 ? '' : 's'} waiting ${STALE_ORDER_DAYS}+ days — chase the pharmacy.`,
                tab: 'orders',
            },
        ] as const
    ).filter((a) => a.count > 0 && !dismissed.includes(a.key));

    const stockByMed = useMemo(
        () => new Map(stockContext.map((s) => [s.medication_id, s])),
        [stockContext],
    );
    const openOrderFor = (medId: number | null): OpenOrderSummary | null => {
        if (medId == null) return null;
        const o = openOrdersByMedication[medId];
        return o
            ? {
                  status: o.status,
                  pharmacy_name: o.pharmacy_name,
                  order_type: o.order_type,
                  quantity_ordered: o.quantity_ordered,
                  ordered_at: o.ordered_at,
              }
            : null;
    };
    const runCount = (s: StockRow) => {
        if (s.controlled && !canRecordControlled) return;
        setModal({
            type: 'count',
            medId: s.medication_id,
            controlledOnly: s.controlled,
        });
    };

    const stockActionsFor = (s: StockRow): MenuItem[] => {
        const order = openOrderFor(s.medication_id);
        const canGovernBalance = !s.controlled || canRecordControlled;
        const orderRow = openOrdersByMedication[s.medication_id];
        const items: MenuItem[] = [
            {
                icon: Eye,
                label: 'View details',
                onClick: () => setModal({ type: 'detail', item: s }),
            },
            ...(canGovernBalance
                ? [
                      {
                          icon: Pencil,
                          label: 'Adjust stock',
                          onClick: () => setModal({ type: 'adjust', item: s }),
                      } satisfies MenuItem,
                      {
                          icon: ClipboardCheck,
                          label: s.controlled
                              ? 'Run CD balance check'
                              : 'Run count',
                          onClick: () => runCount(s),
                      } satisfies MenuItem,
                  ]
                : []),
            {
                icon: ShoppingCart,
                label: 'Order more',
                onClick: () =>
                    setModal({
                        type: 'order',
                        clientId: s.client_id ?? undefined,
                        medId: s.medication_id,
                    }),
            },
            ...(order && canGovernBalance
                ? [
                      {
                          icon: Truck,
                          label: 'Receive against order',
                          onClick: () => {
                              const action = orderRow
                                  ? pharmacyOrderAdvanceAction(orderRow)
                                  : null;
                              if (
                                  orderRow &&
                                  action === 'controlled-delivery'
                              ) {
                                  setModal({
                                      type: 'controlled-delivery',
                                      order: orderRow,
                                      item: s,
                                  });
                                  return;
                              }
                              if (orderRow && action === 'delivery') {
                                  setModal({
                                      type: 'delivery',
                                      order: orderRow,
                                  });
                                  return;
                              }

                              setModal({
                                  type: 'receive',
                                  medId: s.medication_id,
                              });
                          },
                      } satisfies MenuItem,
                  ]
                : []),
            { separator: true },
            ...(s.client_id
                ? [
                      {
                          icon: User,
                          label: 'View person',
                          onClick: () =>
                              router.visit(
                                  `/operations/clients/${s.client_id}?tab=mar`,
                              ),
                      } satisfies MenuItem,
                  ]
                : []),
            ...(s.mar_url
                ? [
                      {
                          icon: FileText,
                          label: 'Open on MAR',
                          onClick: () => router.visit(s.mar_url!),
                      } satisfies MenuItem,
                  ]
                : []),
            ...(s.is_expired && canGovernBalance
                ? [
                      { separator: true } satisfies MenuItem,
                      {
                          icon: AlertOctagon,
                          label: 'Quarantine / record stock adjustment',
                          danger: true,
                          onClick: () => setModal({ type: 'adjust', item: s }),
                      } satisfies MenuItem,
                  ]
                : []),
        ];
        return compactMenu(items);
    };

    const cdActionsFor = (row: ControlledRegisterRow): MenuItem[] => {
        const stock = stockByMed.get(row.medication_id);
        return compactMenu([
            stock && {
                icon: Eye,
                label: 'View details',
                onClick: () => setModal({ type: 'detail', item: stock }),
            },
            canRecordControlled && {
                icon: ShieldCheck,
                label: 'Record CD balance check',
                onClick: () =>
                    setModal({
                        type: 'count',
                        medId: row.medication_id,
                        controlledOnly: true,
                    }),
            },
            stock &&
                canRecordControlled && {
                    icon: Pencil,
                    label: 'Adjust stock',
                    onClick: () => setModal({ type: 'adjust', item: stock }),
                },
            stock && {
                icon: ShoppingCart,
                label: 'Order more',
                onClick: () =>
                    setModal({
                        type: 'order',
                        clientId: row.client_id ?? undefined,
                        medId: row.medication_id,
                    }),
            },
            { separator: true },
            !!row.client_id && {
                icon: User,
                label: 'View person',
                onClick: () =>
                    router.visit(
                        '/operations/clients/' + row.client_id + '?tab=mar',
                    ),
            },
            {
                icon: FileText,
                label: 'Open CD register',
                onClick: () =>
                    router.visit(
                        emarScopedHref(
                            `/emar/controlled?client_id=${row.client_id}&client_medication_id=${row.medication_id}`,
                            pageUrl,
                        ),
                    ),
            },
            row.discrepancy !== null &&
                row.discrepancy !== 0 && {
                    icon: AlertOctagon,
                    label: 'Investigate discrepancy',
                    danger: true,
                    onClick: () =>
                        router.visit(
                            emarScopedHref(
                                `/emar/controlled?view=discrepancies&client_id=${row.client_id}&client_medication_id=${row.medication_id}`,
                                pageUrl,
                            ),
                        ),
                },
        ]);
    };

    const advance = async (order: OrderRow) => {
        if (
            advanceInFlight.current ||
            !NEXT_LABEL[order.status] ||
            (order.controlled &&
                order.status === 'dispensed' &&
                !canRecordControlled)
        )
            return;
        const packItem = stockContext.find(
            (stock) =>
                stock.medication_id === order.medication_id &&
                !stock.controlled &&
                stock.pack_workflow_url,
        );
        if (packItem?.pack_workflow_url) {
            router.visit(packItem.pack_workflow_url + '&view=orders');
            return;
        }
        const action = pharmacyOrderAdvanceAction(order);
        if (action === 'delivery') {
            setModal({ type: 'delivery', order });
            return;
        }
        if (action === 'controlled-delivery') {
            const item = stockContext.find(
                (stock) => stock.medication_id === order.medication_id,
            );
            if (item && order.medication_id !== null) {
                setModal({ type: 'controlled-delivery', order, item });
                return;
            }

            router.visit(emarScopedHref('/emar/controlled', pageUrl));
            return;
        }

        advanceInFlight.current = true;
        setAdvancing(order.id);
        const currentReplay =
            orderAdvanceReplay.current.get(order.id) ??
            createMedicationMutationReplayState();
        const preparedReplay = prepareMedicationMutationReplayState(
            currentReplay,
            {
                pharmacy_order_id: order.id,
                client_medication_id: order.medication_id,
                current_status: order.status,
            },
        );
        orderAdvanceReplay.current.set(order.id, preparedReplay);
        try {
            const result = await submitEmarMutation(
                `/emar/stock/pharmacy-orders/${order.id}/advance`,
                {
                    expected_status: order.status,
                    client_request_uuid: preparedReplay.uuid,
                },
                {
                    action: 'stock_update',
                    successMessage:
                        NEXT_LABEL[order.status] ?? 'Order advanced',
                },
            );
            if (emarMutationWasAccepted(result.status)) {
                orderAdvanceReplay.current.delete(order.id);
                setModal(null);
                router.reload();
            }
        } catch {
            toast.error('Could not advance the pharmacy order');
        } finally {
            advanceInFlight.current = false;
            setAdvancing(null);
        }
    };
    const orderActionsFor = (order: OrderRow): MenuItem[] =>
        compactMenu([
            {
                icon: Eye,
                label: 'View order',
                onClick: () => setModal({ type: 'order-detail', order }),
            },
            !!NEXT_LABEL[order.status] &&
                (order.status !== 'dispensed' ||
                    !order.controlled ||
                    canRecordControlled) && {
                    icon: Truck,
                    label: NEXT_LABEL[order.status],
                    disabled:
                        advancing !== null ? 'Saving this order' : undefined,
                    onClick: () => {
                        void advance(order);
                    },
                },
        ]);

    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All stock',
            icon: Package,
            tone: 'primary',
            badge: summary.total_stock || undefined,
        },
        {
            id: 'low',
            label: 'Low stock',
            icon: AlertTriangle,
            tone: 'warning',
            badge: lowStockCount || undefined,
        },
        {
            id: 'expiring',
            label: 'Expiring',
            icon: Clock,
            tone: 'warning',
            badge: expiringCount || undefined,
        },
        {
            id: 'expired',
            label: 'Expired',
            icon: CalendarX2,
            tone: 'critical',
            badge: expiredCount || undefined,
        },
        ...(canViewControlled
            ? [
                  {
                      id: 'controlled',
                      label: 'Controlled drugs',
                      icon: ShieldCheck,
                      tone: 'primary' as const,
                      badge: summary.controlled || undefined,
                  },
              ]
            : []),
        {
            id: 'orders',
            label: 'Pharmacy orders',
            icon: ShoppingCart,
            tone: 'info',
            badge: summary.open_orders || undefined,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock & pharmacy" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    wrapTitle
                    brandColour={brandColour}
                    rail={
                        <EmarHubRail
                            scope={{
                                site_id: siteFilter,
                                client_id: clientFilter,
                            }}
                        />
                    }
                    icon={Package}
                    title="Stock & pharmacy"
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {activeSite?.name ?? 'All permitted houses'}
                        </PageHeaderStatusChip>
                    }
                    subline="Person-owned medication stock, counts and pharmacy orders"
                    meters={
                        <EmarMeters
                            items={[
                                {
                                    label: 'Tracked',
                                    value: summary.total_stock,
                                    caption: 'Items in the selected scope',
                                    onClick: () => setActiveTab('all'),
                                },
                                {
                                    label: 'Low stock',
                                    value: lowStockCount,
                                    caption: 'At or below reorder level',
                                    tone:
                                        lowStockCount > 0 ? 'warning' : 'brand',
                                    onClick: () => setActiveTab('low'),
                                },
                                {
                                    label: 'Expiring',
                                    value: expiringCount,
                                    caption: 'Within the next 30 days',
                                    tone:
                                        expiringCount > 0 ? 'warning' : 'brand',
                                    onClick: () => setActiveTab('expiring'),
                                },
                                {
                                    label: 'Pharmacy orders',
                                    value: summary.open_orders,
                                    caption: 'Orders still open',
                                    onClick: () => setActiveTab('orders'),
                                },
                            ]}
                        />
                    }
                    actions={
                        <>
                            <MedicationJourneyReturn />
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder={
                                    activeTab === 'orders'
                                        ? 'Search medication, person or pharmacy…'
                                        : activeTab === 'controlled'
                                          ? 'Search medication or person…'
                                          : 'Search medication, person or batch…'
                                }
                                ariaLabel={
                                    activeTab === 'orders'
                                        ? 'Search pharmacy orders'
                                        : activeTab === 'controlled'
                                          ? 'Search controlled stock'
                                          : 'Search stock'
                                }
                            />
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                disabled={loading || failed}
                                onClick={() => setModal({ type: 'order' })}
                            >
                                New pharmacy order
                            </PageHeaderPrimaryButton>
                            <PageHeaderGlassButton
                                icon={Truck}
                                disabled={loading || failed}
                                onClick={() => setModal({ type: 'receive' })}
                            >
                                Receive stock
                            </PageHeaderGlassButton>
                        </>
                    }
                    filters={
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {activeTab !== 'orders' && (
                                <EmarViewFilter
                                    value={activeTab}
                                    onChange={setActiveTab}
                                    items={TABS.filter(
                                        (item) => item.id !== 'orders',
                                    )}
                                    label="Stock view"
                                />
                            )}
                            {activeTab !== 'orders' &&
                                activeTab !== 'controlled' && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        {[
                                            {
                                                id: 'all' as const,
                                                label: 'All items',
                                            },
                                            ...(canViewControlled
                                                ? [
                                                      {
                                                          id: 'controlled' as const,
                                                          label: 'Controlled only',
                                                      },
                                                  ]
                                                : []),
                                            {
                                                id: 'cold_chain' as const,
                                                label: 'Cold chain',
                                            },
                                        ].map(({ id, label }) => (
                                            <PageHeaderFilterButton
                                                key={id}
                                                onClick={() => setChip(id)}
                                                active={chip === id}
                                                aria-pressed={chip === id}
                                            >
                                                {label}
                                            </PageHeaderFilterButton>
                                        ))}
                                        <PageHeaderFilterButton
                                            icon={Barcode}
                                            disabled={loading || failed}
                                            onClick={() =>
                                                setModal({ type: 'count' })
                                            }
                                        >
                                            Run stock count
                                        </PageHeaderFilterButton>
                                    </div>
                                )}
                            <div className="flex flex-wrap items-center gap-2">
                                {search && (
                                    <PageHeaderFilterButton
                                        onClick={() => setSearch('')}
                                    >
                                        Clear search
                                    </PageHeaderFilterButton>
                                )}
                                {sites.length > 0 && (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={siteFilter}
                                        onChange={onSite}
                                        onDark
                                        className="h-[23px] rounded-[8px] px-2 py-0 text-[11.5px]"
                                    />
                                )}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({
                                        id: c.id,
                                        name: `${c.first_name} ${c.last_name}`,
                                    }))}
                                    value={clientFilter}
                                    onChange={onClient}
                                    onDark
                                    className="h-[23px] rounded-[8px] px-2 py-0 text-[11.5px]"
                                />
                            </div>
                        </div>
                    }
                />

                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((a) => {
                            const Icon = a.icon;
                            const cls =
                                a.tone === 'critical'
                                    ? 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
                                    : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning';
                            return (
                                <div
                                    key={a.key}
                                    className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${cls}`}
                                >
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        <Icon className="h-4 w-4 shrink-0" />
                                        {a.message}
                                    </span>
                                    <div className="flex shrink-0 items-center gap-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setActiveTab(a.tab)}
                                        >
                                            Review
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Dismiss alert"
                                            onClick={() =>
                                                setDismissed((d) => [
                                                    ...d,
                                                    a.key,
                                                ])
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {failed ? (
                    <ErrorState
                        title="Stock could not be loaded"
                        message="Your filters are kept. Try again."
                        onRetry={retry}
                    />
                ) : loading ? (
                    <div role="status" aria-label="Loading stock">
                        <SkeletonTable rows={5} columns={6} />
                    </div>
                ) : (
                    <StockPagedList
                        title={
                            activeTab === 'orders'
                                ? 'Pharmacy orders'
                                : activeTab === 'controlled'
                                  ? 'Controlled stock'
                                  : 'Stock'
                        }
                        page={pagination}
                        onPage={(page) => change({ page })}
                        onPageSize={(per_page) => change({ per_page })}
                    >
                        {['all', 'low', 'expiring', 'expired'].includes(
                            activeTab,
                        ) && (
                            <StockInventoryTable
                                rows={stockItems}
                                actionsFor={stockActionsFor}
                                onOpen={(item) =>
                                    setModal({ type: 'detail', item })
                                }
                            />
                        )}
                        {canViewControlled && activeTab === 'controlled' && (
                            <>
                                <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                                    <p>
                                        Investigate any discrepancy with a
                                        witness before the end of the shift.
                                    </p>
                                    {canRecordControlled && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setModal({
                                                    type: 'count',
                                                    controlledOnly: true,
                                                })
                                            }
                                        >
                                            <ShieldCheck className="size-3.5" />
                                            Record CD balance check
                                        </Button>
                                    )}
                                </div>
                                <StockHubTable
                                    rows={controlledRegister}
                                    identityLabel="Medication"
                                    identityWidth="1.6fr"
                                    minWidth={880}
                                    identity={(row) => ({
                                        icon: ShieldCheck,
                                        name:
                                            row.medication_name ??
                                            'Medication not recorded',
                                        extra: row.cd_class ? (
                                            <EntityStatusChip variant="neutral">
                                                Class {row.cd_class}
                                            </EntityStatusChip>
                                        ) : undefined,
                                    })}
                                    columns={controlledColumns}
                                    actionsFor={cdActionsFor}
                                    onOpen={(row) => {
                                        const item = stockByMed.get(
                                            row.medication_id,
                                        );
                                        if (item)
                                            setModal({ type: 'detail', item });
                                    }}
                                    contextTitle={(row) =>
                                        (row.medication_name ??
                                            'Controlled stock') +
                                        ' · ' +
                                        row.client_name
                                    }
                                />
                            </>
                        )}
                        {activeTab === 'orders' && (
                            <StockHubTable
                                rows={pharmacyOrders}
                                identityLabel="Medication"
                                identityWidth="1.6fr"
                                minWidth={880}
                                identity={(row) => ({
                                    icon: ShoppingCart,
                                    name:
                                        row.medication_name ??
                                        'Medication not recorded',
                                    subline:
                                        'Order #' +
                                        row.id +
                                        (row.order_type
                                            ? ' · ' + row.order_type
                                            : ''),
                                    extra: row.controlled ? (
                                        <EntityStatusChip variant="neutral">
                                            CD
                                        </EntityStatusChip>
                                    ) : undefined,
                                })}
                                columns={pharmacyColumns}
                                actionsFor={orderActionsFor}
                                onOpen={(order) =>
                                    setModal({ type: 'order-detail', order })
                                }
                                contextTitle={(row) =>
                                    'Order #' + row.id + ' · ' + row.client_name
                                }
                            />
                        )}
                    </StockPagedList>
                )}
            </div>

            {modal?.type === 'order' && (
                <NewPharmacyOrderDialog
                    clients={clients}
                    medications={activeMedications}
                    stockItems={stockContext}
                    loadStockContext={loadStockContext}
                    defaultClientId={modal.clientId}
                    defaultMedId={modal.medId}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'receive' && (
                <ReceiveStockDialog
                    medications={controlledGovernedMedications}
                    defaultMedId={modal.medId}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'controlled-delivery' &&
                modal.order.medication_id !== null && (
                    <ControlledPharmacyDeliveryDialog
                        order={{
                            ...modal.order,
                            medication_id: modal.order.medication_id,
                            medication_name:
                                modal.order.medication_name ??
                                'Controlled drug',
                        }}
                        stockItem={modal.item}
                        witnesses={witnesses}
                        onClose={() => setModal(null)}
                    />
                )}
            {modal?.type === 'delivery' && (
                <PharmacyDeliveryDialog
                    order={modal.order}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'count' && (
                <StockCountDialog
                    medications={controlledGovernedMedications}
                    stockItems={stockContext}
                    loadStockContext={loadStockContext}
                    witnesses={witnesses}
                    defaultMedId={modal.medId}
                    controlledOnly={modal.controlledOnly}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'adjust' &&
                (!modal.item.controlled || canRecordControlled) && (
                    <AdjustStockDialog
                        item={modal.item}
                        onClose={() => setModal(null)}
                        onControlledCount={
                            modal.item.controlled
                                ? () => runCount(modal.item)
                                : undefined
                        }
                    />
                )}
            {modal?.type === 'detail' && (
                <StockDetailDialog
                    item={modal.item}
                    openOrder={openOrderFor(modal.item.medication_id)}
                    onClose={() => setModal(null)}
                    onAdjust={
                        !modal.item.controlled || canRecordControlled
                            ? () =>
                                  setModal({
                                      type: 'adjust',
                                      item: modal.item,
                                  })
                            : undefined
                    }
                    onCount={
                        !modal.item.controlled || canRecordControlled
                            ? () => runCount(modal.item)
                            : undefined
                    }
                    onOrder={() =>
                        setModal({
                            type: 'order',
                            clientId: modal.item.client_id ?? undefined,
                            medId: modal.item.medication_id,
                        })
                    }
                />
            )}

            {modal?.type === 'order-detail' && (
                <PharmacyOrderDetail
                    order={modal.order}
                    busy={advancing !== null}
                    onClose={() => setModal(null)}
                    onAdvance={
                        modal.order.status !== 'dispensed' ||
                        !modal.order.controlled ||
                        canRecordControlled
                            ? () => {
                                  void advance(modal.order);
                              }
                            : undefined
                    }
                />
            )}
        </AppLayout>
    );
}
