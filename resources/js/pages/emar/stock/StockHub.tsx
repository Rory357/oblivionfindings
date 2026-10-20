import { ConfirmDialog } from '@/components/confirm-dialog';
import { MedicationJourneyReturn } from '@/components/emar/medication-journey-return';
import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import { medicationReturnParams } from '@/lib/medication-navigation';
import { Head, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CalendarClock,
    Camera,
    ClipboardCheck,
    Clock,
    Eye,
    Package,
    PackageCheck,
    PackageMinus,
    Pill,
    ShieldCheck,
    Truck,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { MedicinePicture } from '../catalogue/_medicine-picture';
import { ConnectedServicesMenu } from '../connected/_entry-points';
import {
    CountReview,
    CountWizard,
    MovementDialog,
    NewSupplyOrder,
    SupplyOrderDialog,
} from './_dialogs';
import { StockMeters, type StockMetrics } from './_meters';
import { ControlledOpeningDialog } from './_opening';
import { OrdersView } from './_orders-view';
import { PackPhotoDialog } from './_photo';
import { ReceiveWizard } from './_receive';
import { ReceivePicker, receiveBlockedReason } from './_receive-picker';
import { RecentMovements } from './_recent-movements';
import { useStockCommand } from './_requests';
import { stockCaption, stockColumns } from './_stock-columns';
import { TableList } from './_table-list';
import type {
    Capabilities,
    ItemDetail,
    Movement,
    Pager,
    StockCount,
    StockItem,
    SupplyOrder,
} from './_types';

const states: Record<string, { label: string; variant: StatusVariant }> = {
    unknown: { label: 'Not counted yet', variant: 'neutral' },
    out: { label: 'Out of stock', variant: 'critical' },
    expired: { label: 'Expired pack', variant: 'critical' },
    low: { label: 'Running low', variant: 'warning' },
    ok: { label: 'In stock', variant: 'success' },
    draft: { label: 'Draft', variant: 'neutral' },
    submitted: { label: 'Contact recorded', variant: 'info' },
    confirmed: { label: 'Confirmed', variant: 'info' },
    dispensed: { label: 'To receive', variant: 'warning' },
    part_received: { label: 'Part received', variant: 'warning' },
    delivered: { label: 'Received', variant: 'success' },
    received: { label: 'Received', variant: 'success' },
    closed_short: { label: 'Closed short', variant: 'neutral' },
    cancelled: { label: 'Cancelled', variant: 'neutral' },
    needs_review: { label: 'Count to sign off', variant: 'warning' },
    counted: { label: 'Count matches', variant: 'success' },
    reviewed: { label: 'Signed off', variant: 'success' },
};
function State({ value }: { value: string }) {
    const state = states[value] ?? {
        label: value.replaceAll('_', ' '),
        variant: 'neutral' as const,
    };
    return (
        <StatusBadge className="w-fit" variant={state.variant}>
            {state.label}
        </StatusBadge>
    );
}
// The approved P06 Show filter (README §2, 1440-02/-04; Main, 21 Oct).
const SHOW_OPTIONS = [
    { value: 'all', label: 'All medicines' },
    { value: 'attention', label: 'Needs attention' },
    { value: 'low', label: 'Running low or out' },
    { value: 'self', label: 'Self-managed' },
];
const SHOW_TITLES: Record<string, string> = {
    all: 'Medicines held',
    attention: 'Needs attention',
    low: 'Running low or out',
    self: 'Self-managed',
    out: 'Out of stock',
};
const moveLabels: Record<string, string> = {
    opening: 'Recorded balance',
    received: 'Received',
    given: 'Given',
    going_out: 'Went out',
    coming_back: 'Came back',
    returned_pharmacy: 'Returned to pharmacy',
    removed_expired: 'Expired pack removed',
    damaged: 'Damaged stock removed',
    quarantined: 'Pack taken out of use',
    count_correction: 'Count correction',
    controlled_receipt: 'Controlled receipt',
};
type View =
    | 'stock'
    | 'orders'
    | 'counts'
    | 'expiring'
    | 'removals'
    | 'movements';
type Props = {
    items: Pager<StockItem>;
    orders: Pager<SupplyOrder>;
    counts: Pager<StockCount>;
    movements: Pager<Movement>;
    sites: { id: number; name: string }[];
    pharmacies: string[];
    filters: {
        view: View;
        search: string;
        site_id: number | null;
        client_id?: number | null;
        show?: string;
        lot_id?: number | null;
        medication_id?: number | null;
    };
    /** The approved P06 meters, in the list’s scope (Main, 20 Oct). */
    metrics: StockMetrics;
    /** No controlled-medicine view: the counts and list cover visible medicines. */
    controlled_left_out?: boolean;
    /** People across the whole stock list, for "n shown · n people". */
    item_people?: number;
    can: Capabilities;
    lots_enabled: boolean;
    selected_client?: { id: number; name: string } | null;
    focused_count?: StockCount | null;
};
type Modal =
    | {
          kind: 'item';
          id: number;
          action?:
              | 'receive'
              | 'count'
              | 'order'
              | 'move'
              | 'going_out'
              | 'coming_back';
          order?: SupplyOrder;
      }
    | { kind: 'order'; order: SupplyOrder }
    | { kind: 'count'; record: StockCount }
    | { kind: 'receive_pick' }
    | null;

export default function StockHub({
    items,
    orders,
    counts,
    movements,
    sites,
    pharmacies = [],
    filters,
    metrics,
    can,
    lots_enabled,
    selected_client = null,
    focused_count = null,
    controlled_left_out = false,
    item_people,
}: Props) {
    // EA-079 (Lane C8): Home-rooted, in the rail's own labels.
    const breadcrumbs = useEmarBreadcrumbs();
    const [search, setSearch] = useState(filters.search);
    const [modal, setModal] = useState<Modal>(null);
    // The approved P06 header: the houses in view (the House filter narrows
    // them to one), and when the stock was read.
    const [asAt, setAsAt] = useState(() => new Date().toISOString());
    const sitesInView = filters.site_id
        ? sites.filter((site) => site.id === filters.site_id)
        : sites;
    const houseNames = sitesInView.map((site) => site.name);
    const houses =
        houseNames.length > 3
            ? `${houseNames.slice(0, 3).join(', ')} and ${houseNames.length - 3} more`
            : houseNames.length > 1
              ? `${houseNames.slice(0, -1).join(', ')} and ${houseNames[houseNames.length - 1]}`
              : (houseNames[0] ?? 'your houses');
    useEffect(() => {
        if (filters.medication_id)
            setModal({ kind: 'item', id: filters.medication_id });
    }, [filters.medication_id]);
    useEffect(() => {
        if (focused_count) setModal({ kind: 'count', record: focused_count });
    }, [focused_count]);
    const refresh = () =>
        router.reload({
            only: [
                'items',
                'orders',
                'counts',
                'movements',
                'metrics',
                'item_people',
                'pharmacies',
            ],
            preserveScroll: true,
        });
    const visit = (changes: Record<string, string | number | null>) =>
        router.get(
            '/emar/stock/packs',
            Object.fromEntries(
                Object.entries({
                    ...filters,
                    ...medicationReturnParams(),
                    search,
                    ...changes,
                }).filter(
                    ([, value]) =>
                        value !== null && value !== undefined && value !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );
    const openItem = (
        item: StockItem,
        action?: Exclude<Extract<Modal, { kind: 'item' }>['action'], undefined>,
    ) => setModal({ kind: 'item', id: item.id, action });
    const itemActions = (item: StockItem): MenuItem[] => [
        {
            label: 'Open the stock item',
            icon: Eye,
            onClick: () => openItem(item),
        },
        ...(can.receive && !item.controlled
            ? [
                  {
                      label: 'Receive a delivery',
                      icon: Truck,
                      onClick: () => openItem(item, 'receive'),
                      disabled: receiveBlockedReason(item),
                  },
                  {
                      label: 'Count it',
                      icon: ClipboardCheck,
                      onClick: () => openItem(item, 'count'),
                      disabled: !item.lots_started
                          ? 'Pack tracking has not been set up.'
                          : undefined,
                  },
                  {
                      label: 'Going out or coming back',
                      icon: ArrowLeftRight,
                      onClick: () => openItem(item, 'going_out'),
                      disabled: !item.lots_started
                          ? 'Pack tracking has not been set up.'
                          : undefined,
                  },
              ]
            : []),
        ...(can.receive_controlled && item.controlled
            ? [
                  {
                      label: 'Receive a witnessed delivery',
                      icon: Truck,
                      onClick: () => openItem(item, 'receive'),
                      disabled: !(item.receivable ?? item.active)
                          ? 'This medicine has been stopped.'
                          : !item.lots_started && item.stock_id !== null
                            ? 'Pack tracking has not been set up.'
                            : undefined,
                  },
              ]
            : []),
        ...(can.manage && !item.controlled
            ? [
                  {
                      label: 'Order from the pharmacy',
                      icon: Truck,
                      onClick: () => openItem(item, 'order'),
                      disabled: !(item.receivable ?? item.active)
                          ? 'This medicine has been stopped.'
                          : undefined,
                  },
                  {
                      label: 'Adjust or remove',
                      icon: Package,
                      onClick: () => openItem(item, 'move'),
                      disabled: !item.lots_started
                          ? 'Pack tracking has not been set up.'
                          : undefined,
                  },
              ]
            : []),
        ...(item.controlled
            ? [
                  {
                      label: 'Open the controlled register',
                      icon: ShieldCheck,
                      onClick: () =>
                          router.visit(
                              `/emar/controlled?client_id=${item.client_id}&client_medication_id=${item.id}${item.site_id ? '&site_id=' + item.site_id : ''}`,
                          ),
                  },
              ]
            : []),
    ];
    const orderOpen = (order: SupplyOrder) =>
        setModal({ kind: 'order', order });
    const orderActions = (order: SupplyOrder): MenuItem[] => [
        {
            label: 'Open the supply record',
            icon: Eye,
            onClick: () => orderOpen(order),
        },
        ...((order.controlled ? can.receive_controlled : can.receive) &&
        ['dispensed', 'part_received'].includes(order.status)
            ? [
                  {
                      label: 'Receive this delivery',
                      icon: Truck,
                      onClick: () =>
                          setModal({
                              kind: 'item',
                              id: order.client_medication_id,
                              action: 'receive',
                              order,
                          }),
                  },
              ]
            : []),
    ];
    const countOpen = (record: StockCount) =>
        setModal({ kind: 'count', record });
    const rail = [
        { key: 'stock' as const, label: 'Stock', icon: Package },
        {
            key: 'orders' as const,
            label: 'Deliveries & orders',
            icon: Truck,
            // Approved P06: deliveries to receive and counts to sign off; no zeros.
            count: metrics.to_receive || undefined,
        },
        {
            key: 'counts' as const,
            label: 'Counts',
            icon: ClipboardCheck,
            count: metrics.counts || undefined,
        },
        { key: 'expiring' as const, label: 'Expiring', icon: CalendarClock },
        { key: 'removals' as const, label: 'Removals', icon: PackageMinus },
        ...(can.controlled
            ? [
                  {
                      key: 'controlled' as const,
                      label: 'Controlled',
                      icon: ShieldCheck,
                  },
              ]
            : []),
    ];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock & controlled drugs" />
            <div className="grid min-w-0 grid-cols-1 gap-5">
                <PageHeader
                    wrapTitle
                    title="Stock & controlled drugs"
                    icon={Package}
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {houseNames.length}{' '}
                            {houseNames.length === 1 ? 'house' : 'houses'}
                        </PageHeaderStatusChip>
                    }
                    subline={`Medicines held for people at ${houses} · times in Pacific/Auckland`}
                    actions={
                        <>
                            <MedicationJourneyReturn />
                            <ConnectedServicesMenu
                                clientId={filters.client_id ?? undefined}
                            />
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search medicines or batches"
                                onKeyDown={(event) =>
                                    event.key === 'Enter' && visit({})
                                }
                            />
                            <PageHeaderGlassButton
                                onClick={() =>
                                    router.visit(
                                        `/emar/stock?${new URLSearchParams({
                                            ...(filters.site_id
                                                ? {
                                                      site_id: String(
                                                          filters.site_id,
                                                      ),
                                                  }
                                                : {}),
                                            ...(filters.client_id
                                                ? {
                                                      client_id: String(
                                                          filters.client_id,
                                                      ),
                                                  }
                                                : {}),
                                        })}`,
                                    )
                                }
                            >
                                Stock overview
                            </PageHeaderGlassButton>
                            {can.receive && (
                                <PageHeaderPrimaryButton
                                    icon={PackageCheck}
                                    onClick={() =>
                                        setModal({ kind: 'receive_pick' })
                                    }
                                >
                                    Receive a delivery
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={<StockMeters metrics={metrics} open={visit} />}
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="House"
                                value={
                                    filters.site_id
                                        ? String(filters.site_id)
                                        : 'all'
                                }
                                options={[
                                    {
                                        value: 'all',
                                        label: 'My approved houses',
                                    },
                                    ...sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    })),
                                ]}
                                onChange={(value) =>
                                    visit({
                                        site_id:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                        client_id: null,
                                        medication_id: null,
                                    })
                                }
                            />
                            {selected_client && (
                                <PageHeaderGlassButton
                                    onClick={() =>
                                        visit({
                                            client_id: null,
                                            medication_id: null,
                                        })
                                    }
                                    title="Clear the selected person"
                                >
                                    {selected_client.name}
                                    <X
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </PageHeaderGlassButton>
                            )}
                            {filters.view === 'stock' && (
                                <PageHeaderFilterSelect
                                    label="Show"
                                    value={filters.show ?? 'all'}
                                    options={[
                                        ...SHOW_OPTIONS,
                                        // An older ?show=out link still shows what it filters.
                                        ...(filters.show === 'out'
                                            ? [
                                                  {
                                                      value: 'out',
                                                      label: SHOW_TITLES.out,
                                                  },
                                              ]
                                            : []),
                                    ]}
                                    onChange={(value) =>
                                        visit({
                                            show:
                                                value === 'all' ? null : value,
                                        })
                                    }
                                />
                            )}
                            <PageHeaderFilterButton
                                icon={Clock}
                                onClick={() =>
                                    router.reload({
                                        onSuccess: () =>
                                            setAsAt(new Date().toISOString()),
                                    })
                                }
                            >
                                As at {formatTime(asAt)} · Pacific/Auckland
                            </PageHeaderFilterButton>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={rail}
                            // All movements (?view=movements) sits under Stock.
                            value={
                                filters.view === 'movements'
                                    ? 'stock'
                                    : filters.view
                            }
                            ariaLabel="Stock views"
                            onSelect={(view) =>
                                view === 'controlled'
                                    ? router.visit('/emar/controlled')
                                    : visit({ view })
                            }
                        />
                    }
                />
                {controlled_left_out && (
                    <p className="text-caption text-muted-foreground">
                        Controlled medicines are left out of these counts and
                        this list. The counts cover visible medicines.
                    </p>
                )}
                {['stock', 'expiring'].includes(filters.view) && (
                    <TableList
                        title={
                            filters.view === 'expiring'
                                ? 'Packs needing an expiry check'
                                : (SHOW_TITLES[filters.show ?? 'all'] ??
                                  'Medicines held')
                        }
                        caption={stockCaption(
                            items,
                            item_people ??
                                new Set(
                                    items.data.map((item) => item.client_id),
                                ).size,
                            metrics.controlled_hidden,
                        )}
                        identityLabel="Medicine"
                        identityWidth="1.25fr"
                        pager={items}
                        name={(item) => item.name}
                        subline={(item) =>
                            [
                                item.site_name,
                                item.controlled ? 'controlled' : null,
                                item.held ? 'on hold' : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }
                        open={openItem}
                        actions={itemActions}
                        columns={stockColumns(can, {
                            receive: (item, order) =>
                                setModal({
                                    kind: 'item',
                                    id: item.id,
                                    action: 'receive',
                                    order,
                                }),
                            remove: (item) => openItem(item, 'move'),
                            signOff: (countId) => visit({ count_id: countId }),
                            order: (item) => openItem(item, 'order'),
                            sendOrder: (order) =>
                                setModal({ kind: 'order', order }),
                        })}
                    />
                )}
                {filters.view === 'stock' && (
                    <RecentMovements
                        moves={movements.data}
                        label={(move) =>
                            moveLabels[move.kind] ??
                            move.kind.replaceAll('_', ' ')
                        }
                        open={(move) =>
                            setModal({ kind: 'item', id: move.medication_id })
                        }
                        viewAll={() => visit({ view: 'movements' })}
                    />
                )}
                {filters.view === 'orders' && (
                    <OrdersView
                        pager={orders}
                        can={can}
                        open={orderOpen}
                        receive={(order) =>
                            setModal({
                                kind: 'item',
                                id: order.client_medication_id,
                                action: 'receive',
                                order,
                            })
                        }
                        actions={orderActions}
                    />
                )}
                {filters.view === 'counts' && (
                    <TableList
                        title="Recent counts"
                        pager={counts}
                        name={(record) => record.medication_name}
                        subline={(record) => record.client_name}
                        open={countOpen}
                        actions={(record) => [
                            {
                                label:
                                    record.state === 'needs_review' &&
                                    can.manage
                                        ? 'Review this count'
                                        : 'Open the count',
                                icon: ClipboardCheck,
                                onClick: () => countOpen(record),
                            },
                        ]}
                        columns={[
                            {
                                key: 'counted',
                                label: 'Counted',
                                width: '1.2fr',
                                cell: (record) =>
                                    formatDateTime(record.counted_at),
                            },
                            {
                                key: 'person',
                                label: 'Counted by',
                                width: '1fr',
                                cell: (record) =>
                                    record.counted_by_name ?? 'Unknown',
                            },
                            {
                                key: 'status',
                                label: 'Result',
                                width: '1fr',
                                cell: (record) => (
                                    <State value={record.state} />
                                ),
                            },
                        ]}
                    />
                )}
                {['movements', 'removals'].includes(filters.view) && (
                    <TableList
                        title={
                            filters.view === 'removals'
                                ? 'Removed or taken out of use'
                                : 'All movements'
                        }
                        pager={movements}
                        name={(move) => move.medication_name}
                        subline={(move) => move.client_name}
                        open={(move) =>
                            setModal({ kind: 'item', id: move.medication_id })
                        }
                        actions={(move) => [
                            {
                                label: 'Open the stock item',
                                icon: Eye,
                                onClick: () =>
                                    setModal({
                                        kind: 'item',
                                        id: move.medication_id,
                                    }),
                            },
                        ]}
                        columns={[
                            {
                                key: 'kind',
                                label: 'Movement',
                                width: '1.1fr',
                                cell: (move) =>
                                    moveLabels[move.kind] ??
                                    move.kind.replaceAll('_', ' '),
                            },
                            {
                                key: 'qty',
                                label: 'Quantity',
                                width: '0.7fr',
                                cell: (move) =>
                                    move.quantity_display ?? move.quantity,
                            },
                            {
                                key: 'why',
                                label: 'Reason',
                                width: '1.8fr',
                                cell: (move) => move.reason,
                            },
                            {
                                key: 'when',
                                label: 'Recorded',
                                width: '1.2fr',
                                cell: (move) =>
                                    formatDateTime(move.recorded_at),
                            },
                        ]}
                    />
                )}
            </div>
            {modal?.kind === 'item' && (
                <ItemWorkspace
                    id={modal.id}
                    initialLotId={
                        filters.medication_id === modal.id
                            ? filters.lot_id
                            : null
                    }
                    initialAction={modal.action}
                    order={modal.order}
                    pharmacies={pharmacies}
                    can={can}
                    lotsEnabled={lots_enabled}
                    onClose={() => setModal(null)}
                    onSaved={refresh}
                />
            )}
            {modal?.kind === 'receive_pick' && (
                <ReceivePicker
                    sites={sites}
                    siteId={filters.site_id}
                    onPick={({ medicationId, order }) =>
                        setModal({
                            kind: 'item',
                            id: medicationId,
                            action: 'receive',
                            order,
                        })
                    }
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.kind === 'order' && (
                <SupplyOrderDialog
                    order={modal.order}
                    canManage={can.manage}
                    onClose={() => setModal(null)}
                    onSaved={refresh}
                    onReceive={() =>
                        setModal({
                            kind: 'item',
                            id: modal.order.client_medication_id,
                            action: 'receive',
                            order: modal.order,
                        })
                    }
                />
            )}
            {modal?.kind === 'count' && (
                <CountReview
                    record={modal.record}
                    canManage={can.manage}
                    onClose={() => setModal(null)}
                    onSaved={refresh}
                />
            )}
        </AppLayout>
    );
}

function ItemWorkspace({
    id,
    initialLotId,
    initialAction,
    order,
    pharmacies,
    can,
    lotsEnabled,
    onClose,
    onSaved,
}: {
    id: number;
    initialLotId?: number | null;
    initialAction?:
        | 'receive'
        | 'count'
        | 'order'
        | 'move'
        | 'going_out'
        | 'coming_back';
    order?: SupplyOrder;
    pharmacies: string[];
    can: Capabilities;
    lotsEnabled: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [item, setItem] = useState<ItemDetail | null>(null);
    const [error, setError] = useState(false);
    const [retry, setRetry] = useState(0);
    const [action, setAction] = useState<
        typeof initialAction | 'photo' | 'order_detail'
    >(initialAction);
    const [selectedOrder, setSelectedOrder] = useState<SupplyOrder | null>(
        null,
    );
    const [setupConfirm, setSetupConfirm] = useState(false);
    const [section, setSection] = useState(initialLotId ? 1 : 0);
    const [file, setFile] = useState<PreviewFile | null>(null);
    const command = useStockCommand();
    useEffect(() => {
        const controller = new AbortController();
        fetch(`/emar/stock/packs/medicine/${id}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('unavailable');
                return response.json();
            })
            .then((data: ItemDetail) => {
                setItem(data);
                setError(false);
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError') setError(true);
            });
        return () => controller.abort();
    }, [id, retry]);
    if (!item || error)
        return (
            <SettingsModal
                frontline
                title="Stock item"
                description="Person-owned pack and movement records"
                onClose={onClose}
            >
                {error ? (
                    <ErrorState
                        title="We cannot show this record"
                        message="It may be unavailable or outside your medication access."
                        onRetry={() => {
                            setError(false);
                            setRetry(retry + 1);
                        }}
                    />
                ) : (
                    <SkeletonTable rows={3} />
                )}
            </SettingsModal>
        );
    const back = () => {
        setAction(undefined);
        setSelectedOrder(null);
        setRetry(retry + 1);
    };
    if (setupConfirm && item.controlled)
        return (
            <ControlledOpeningDialog
                item={item}
                onClose={() => setSetupConfirm(false)}
                onSaved={() => {
                    setRetry(retry + 1);
                    onSaved();
                }}
            />
        );
    if (action === 'photo')
        return <PackPhotoDialog item={item} onClose={back} onSaved={onSaved} />;
    if (action === 'order_detail' && selectedOrder)
        return (
            <SupplyOrderDialog
                order={selectedOrder}
                canManage={can.manage}
                onClose={back}
                onSaved={onSaved}
                onReceive={() => setAction('receive')}
            />
        );
    if (action === 'receive')
        return (
            <ReceiveWizard
                item={item}
                order={selectedOrder ?? order}
                onClose={back}
                onSaved={onSaved}
            />
        );
    if (action === 'count')
        return <CountWizard item={item} onClose={back} onSaved={onSaved} />;
    if (action === 'order')
        return (
            <NewSupplyOrder
                item={item}
                pharmacies={pharmacies}
                onClose={back}
                onSaved={onSaved}
            />
        );
    if (action === 'move' || action === 'going_out' || action === 'coming_back')
        return (
            <MovementDialog
                item={item}
                mode={action}
                onClose={back}
                onSaved={onSaved}
            />
        );
    // C11: newest first, like the person record's Medicines › Photos.
    const photos = item.packs
        .flatMap((pack) => pack.photos.map((photo) => ({ pack, photo })))
        .sort(
            (a, b) =>
                b.photo.taken_at.localeCompare(a.photo.taken_at) ||
                b.photo.id - a.photo.id,
        );
    const canPhotograph = item.photo_policy?.can_photograph ?? can.manage;
    const setup = async () => {
        const result = await command.run({
            action: 'initialise',
            client_medication_id: item.id,
            confirm_balance: true,
        });
        if (result) {
            setSetupConfirm(false);
            setRetry(retry + 1);
            onSaved();
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={onClose}
                title={item.name}
                description={item.client_name}
                railIcon={Pill}
                railTitle={item.name}
                railSub={item.client_name}
                sequential={false}
                steps={[
                    {
                        key: 'medicine',
                        label: 'This medicine',
                        blurb: item.client_name,
                        icon: Pill,
                    },
                    {
                        key: 'packs',
                        label: 'Packs',
                        blurb: `${item.packs.length} kept`,
                        icon: Package,
                    },
                    {
                        key: 'movements',
                        label: 'Movements',
                        blurb: 'Permanent history',
                        icon: ArrowLeftRight,
                    },
                    {
                        key: 'orders',
                        label: 'Pharmacy orders',
                        blurb: 'Supply and deliveries',
                        icon: Truck,
                    },
                    {
                        key: 'photos',
                        label: 'Pack photos',
                        blurb: `${photos.length} kept`,
                        icon: Camera,
                    },
                ]}
                stepIndex={section}
                onStepClick={setSection}
                headerLabel={
                    [
                        'This medicine',
                        'Packs',
                        'Movements',
                        'Pharmacy orders',
                        'Pack photos',
                    ][section]
                }
                footerEnd={<Button onClick={onClose}>Close</Button>}
            >
                <div className="grid gap-5">
                    {section === 0 && (
                        <>
                            <State value={item.state} />
                            <ReviewCard icon={Pill} title={item.name}>
                                <ReviewRow
                                    label="Person"
                                    value={item.client_name}
                                />
                                <ReviewRow
                                    label="House"
                                    value={item.site_name}
                                />
                                <ReviewRow
                                    label={
                                        item.controlled
                                            ? 'Physical register balance'
                                            : 'Physically held'
                                    }
                                    value={
                                        item.physical_on_hand == null
                                            ? 'Unknown'
                                            : `${item.physical_on_hand} ${item.unit}`
                                    }
                                />
                                <ReviewRow
                                    label="Usable on hand"
                                    value={
                                        item.usable_on_hand == null
                                            ? 'Unknown'
                                            : `${item.usable_on_hand} ${item.unit}`
                                    }
                                />
                                <ReviewRow
                                    label="Last counted"
                                    value={
                                        item.last_counted_at
                                            ? formatDateTime(
                                                  item.last_counted_at,
                                              )
                                            : 'Not counted yet'
                                    }
                                />
                                <ReviewRow
                                    label="Days of supply"
                                    value={
                                        item.days_supply === null
                                            ? 'Not available — stock-use quantity is not configured'
                                            : item.days_supply
                                    }
                                />
                            </ReviewCard>
                            <MedicinePicture medicationId={item.id} />
                            {!item.lots_started && (
                                <SettingsNotice role="note">
                                    {lotsEnabled
                                        ? 'The recorded stock balance has not been set up as packs yet. Check the recorded balance before carrying it forward.'
                                        : 'Pack tracking is not turned on yet. You can still view recorded balances and history.'}
                                </SettingsNotice>
                            )}
                            {Object.values(command.errors).map((message) => (
                                <SettingsNotice key={message}>
                                    {message}
                                </SettingsNotice>
                            ))}
                            {!item.lots_started &&
                                lotsEnabled &&
                                can.manage &&
                                !item.controlled && (
                                    <Button
                                        disabled={
                                            command.saving ||
                                            item.on_hand === null
                                        }
                                        onClick={() => setSetupConfirm(true)}
                                    >
                                        Use the checked recorded balance
                                    </Button>
                                )}
                            {!item.lots_started &&
                                lotsEnabled &&
                                item.controlled &&
                                can.receive_controlled && (
                                    <Button
                                        disabled={
                                            item.on_hand === null ||
                                            !item.unit?.trim()
                                        }
                                        onClick={() => setSetupConfirm(true)}
                                    >
                                        Start witnessed pack tracking
                                    </Button>
                                )}
                            <div className="flex flex-wrap gap-2">
                                {can.receive_controlled && item.controlled && (
                                    <Button
                                        disabled={
                                            !(item.receivable ?? item.active) ||
                                            (!item.lots_started &&
                                                item.stock_id !== null)
                                        }
                                        onClick={() => setAction('receive')}
                                    >
                                        Receive a witnessed delivery
                                    </Button>
                                )}
                                {can.receive && !item.controlled && (
                                    <>
                                        <Button
                                            disabled={
                                                !item.lots_started &&
                                                item.stock_id !== null
                                            }
                                            onClick={() => setAction('receive')}
                                        >
                                            Receive a delivery
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={!item.lots_started}
                                            onClick={() => setAction('count')}
                                        >
                                            Count it
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                !item.lots_started ||
                                                !item.pack_count
                                            }
                                            onClick={() =>
                                                setAction('going_out')
                                            }
                                        >
                                            Going out
                                        </Button>
                                        {item.outward.length > 0 && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setAction('coming_back')
                                                }
                                            >
                                                Coming back
                                            </Button>
                                        )}
                                    </>
                                )}
                                {can.manage && !item.controlled && (
                                    <>
                                        <Button
                                            variant="outline"
                                            onClick={() => setAction('order')}
                                        >
                                            Order from pharmacy
                                        </Button>
                                        {item.lots_started && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setAction('move')
                                                }
                                            >
                                                Adjust or remove
                                            </Button>
                                        )}
                                    </>
                                )}
                                {item.controlled && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.visit(
                                                `/emar/controlled?client_id=${item.client_id}&client_medication_id=${item.id}${item.site_id ? '&site_id=' + item.site_id : ''}`,
                                            )
                                        }
                                    >
                                        Open the controlled register
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                    {section === 1 &&
                        (item.packs.length ? (
                            [...item.packs]
                                .sort(
                                    (a, b) =>
                                        Number(b.id === initialLotId) -
                                        Number(a.id === initialLotId),
                                )
                                .map((pack) => (
                                    <ReviewCard
                                        key={pack.id}
                                        icon={Package}
                                        title={
                                            pack.batch_number ??
                                            (pack.batch_not_printed
                                                ? 'Batch not printed on the pack'
                                                : 'Batch unknown')
                                        }
                                    >
                                        {pack.id === initialLotId && (
                                            <StatusBadge variant="info">
                                                Selected pack from calendar
                                            </StatusBadge>
                                        )}
                                        <ReviewRow
                                            label="Remaining"
                                            value={`${pack.quantity_remaining} ${item.unit}`}
                                        />
                                        <ReviewRow
                                            label="Expiry"
                                            value={
                                                pack.expiry_date
                                                    ? formatDateOnly(
                                                          pack.expiry_date.slice(
                                                              0,
                                                              10,
                                                          ),
                                                      )
                                                    : pack.expiry_not_printed
                                                      ? 'Not printed on the pack'
                                                      : 'Unknown'
                                            }
                                        />
                                        <ReviewRow
                                            label="Source"
                                            value={
                                                pack.source_reference ??
                                                pack.source.replaceAll('_', ' ')
                                            }
                                        />
                                        <ReviewRow
                                            label="Recorded"
                                            value={formatDateTime(
                                                pack.received_at,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Pack state"
                                            value={
                                                pack.state === 'quarantined'
                                                    ? 'Out of use'
                                                    : 'Open'
                                            }
                                        />
                                        {pack.source === 'recorded_balance' && (
                                            <p className="text-caption">
                                                Carried forward from the
                                                recorded balance. Original
                                                receipt date and label checks
                                                are unknown.
                                            </p>
                                        )}
                                    </ReviewCard>
                                ))
                        ) : (
                            <EmptyState
                                icon={Package}
                                title="No packs yet"
                                description="No pack receipts have been recorded."
                            />
                        ))}
                    {section === 2 &&
                        ((item.movements ?? []).length ? (
                            <>
                                {item.movements?.map((move) => (
                                    <ReviewCard
                                        key={move.id}
                                        icon={ArrowLeftRight}
                                        title={
                                            moveLabels[move.kind] ??
                                            move.kind.replaceAll('_', ' ')
                                        }
                                    >
                                        <ReviewRow
                                            label="Quantity"
                                            value={`${move.quantity} ${item.unit}`}
                                        />
                                        <ReviewRow
                                            label="Pack balance before / after"
                                            value={`${move.balance_before} / ${move.balance_after}`}
                                        />
                                        <ReviewRow
                                            label="Reason"
                                            value={move.reason}
                                        />
                                        <ReviewRow
                                            label="Recorded"
                                            value={`${formatDateTime(move.recorded_at)} · ${move.recorded_by_name ?? 'Unknown'}`}
                                        />
                                        {move.notes && <p>{move.notes}</p>}
                                    </ReviewCard>
                                ))}
                                {item.history_truncated && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.visit(
                                                `/emar/stock/packs?view=movements&medication_id=${item.id}`,
                                            )
                                        }
                                    >
                                        Open all movements
                                    </Button>
                                )}
                            </>
                        ) : (
                            <EmptyState
                                icon={ArrowLeftRight}
                                title="No movements yet"
                                description="Recorded receipts, doses, returns and adjustments appear here."
                            />
                        ))}
                    {section === 3 &&
                        ((item.orders ?? []).length ? (
                            item.orders?.map((supply) => (
                                <ReviewCard
                                    key={supply.id}
                                    icon={Truck}
                                    title={`${supply.pharmacy_name} · #${supply.id}`}
                                >
                                    <State value={supply.status} />
                                    <ReviewRow
                                        label="Received / ordered"
                                        value={`${Number(supply.quantity_received ?? 0)} / ${supply.quantity_ordered}`}
                                    />
                                    <ReviewRow
                                        label="Due to arrive"
                                        value={
                                            supply.expected_delivery
                                                ? formatDateOnly(
                                                      supply.expected_delivery,
                                                  )
                                                : 'Not recorded'
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setSelectedOrder(supply);
                                            setAction('order_detail');
                                        }}
                                    >
                                        Open supply record
                                    </Button>
                                </ReviewCard>
                            ))
                        ) : (
                            <EmptyState
                                icon={Truck}
                                title="No pharmacy orders yet"
                                description="Supply records for this person and medicine appear here."
                            />
                        ))}
                    {section === 4 && (
                        <div className="space-y-3">
                            {canPhotograph && item.packs.length > 0 && (
                                <Button onClick={() => setAction('photo')}>
                                    Add or replace a pack photo
                                </Button>
                            )}
                            {photos.length ? (
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {photos.map(({ pack, photo }) => (
                                        <figure
                                            key={photo.id}
                                            className="space-y-1.5"
                                        >
                                            <Button
                                                unstyled
                                                type="button"
                                                className="block w-full rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                aria-label={`View the pack photo taken ${formatDateTime(photo.taken_at)}`}
                                                onClick={() =>
                                                    setFile({
                                                        id: photo.id,
                                                        name: `${item.name} — photo of the supplied pack`,
                                                        filename:
                                                            photo.original_name,
                                                        mime: photo.mime,
                                                        bytes: photo.bytes,
                                                        source: `Taken ${formatDateTime(photo.taken_at)} by ${photo.taken_by_name ?? 'Unknown'} · ${pack.label ?? pack.batch_number ?? 'Batch unknown'}`,
                                                        previewUrl:
                                                            photo.preview_url,
                                                        downloadUrl:
                                                            photo.download_url,
                                                    })
                                                }
                                            >
                                                <img
                                                    src={photo.preview_url}
                                                    alt={`Pack photo — ${pack.label ?? pack.batch_number ?? 'pack'}`}
                                                    className="aspect-[4/3] w-full rounded-lg border object-cover"
                                                />
                                            </Button>
                                            <figcaption className="text-caption">
                                                {formatDateTime(photo.taken_at)}{' '}
                                                ·{' '}
                                                {photo.taken_by_name ??
                                                    'Unknown'}{' '}
                                                ·{' '}
                                                {pack.batch_number ??
                                                    'no batch'}
                                                <br />
                                                {photo.description ??
                                                    (photo.pack_changed
                                                        ? 'Pack looks different from last time'
                                                        : 'Pack as received')}
                                                {pack.supplier
                                                    ? ` · ${pack.supplier}`
                                                    : ''}
                                            </figcaption>
                                        </figure>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {item.photo_policy?.mode === 'off'
                                        ? 'No pack photo yet.'
                                        : 'No pack photo yet. The next receipt prompts for one.'}
                                </p>
                            )}
                            <p className="text-caption">
                                Photos are stored privately
                                {item.controlled
                                    ? ', and shown only to people with controlled-medicine access'
                                    : ''}
                                . The latest is shown in the dose dialog.
                                Earlier photos are kept. Check the label — the
                                picture is a guide only.
                            </p>
                        </div>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={setupConfirm}
                onClose={() => setSetupConfirm(false)}
                onConfirm={() => void setup()}
                processing={command.saving}
                title="Carry forward this checked balance?"
                description={`This creates pack records from ${item.on_hand ?? 'unknown'} ${item.unit ?? ''}. Original receipt dates and label checks remain unknown. Existing stock history is retained.`}
                confirmText="Use checked balance"
                variant="default"
            />
            <FilePreviewDialog file={file} onClose={() => setFile(null)} />
        </>
    );
}
