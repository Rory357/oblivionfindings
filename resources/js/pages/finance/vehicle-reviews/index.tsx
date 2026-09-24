import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
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
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Car, ClipboardCheck, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import {
    ReviewRequestDialog,
    vehicleLabel,
    type ReviewRequest,
} from './_dialogs';

type Status = 'open' | 'decided' | 'all';

interface Props {
    requests: ReviewRequest[];
    focus: ReviewRequest | null;
    total: number;
    filters: { status: Status; search: string };
    summary: {
        open: number;
        decided_30: number;
        oldest_open_at: string | null;
    };
    can: { decide: boolean };
}

const STATUS_OPTIONS = [
    { value: 'open', label: 'Waiting for Finance' },
    { value: 'decided', label: 'Decided' },
    { value: 'all', label: 'All requests' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Payables', href: '/finance/payables' },
    { title: 'Vehicle reviews', href: '/finance/vehicle-reviews' },
];

/**
 * Finance › Vehicle reviews: requests Fleet raises from a vehicle's Finance
 * view, decided here by Finance without Fleet access.
 */
export default function VehicleReviewsIndex({
    requests,
    focus,
    total,
    filters,
    summary,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    // A request opened from All Tasks or a notification opens straight away.
    const [openId, setOpenId] = useState<number | null>(focus?.id ?? null);
    const selected =
        requests.find((request) => request.id === openId) ??
        (focus && focus.id === openId ? focus : null);

    const apply = (next: Partial<Props['filters']>) =>
        router.get(
            '/finance/vehicle-reviews',
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(
                    ([key, value]) =>
                        value && !(key === 'status' && value === 'open'),
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );

    const ctx = useEntityContextMenu<ReviewRequest>();

    const menuFor = (request: ReviewRequest): MenuItem[] =>
        compactMenu([
            {
                label: request.can_decide ? 'Review request' : 'View request',
                icon: ClipboardCheck,
                onClick: () => setOpenId(request.id),
            },
            request.vehicle.url
                ? {
                      label: 'Open vehicle',
                      icon: ExternalLink,
                      onClick: () => router.get(request.vehicle.url ?? ''),
                  }
                : null,
        ]);

    const columns: EntityTableColumn<ReviewRequest>[] = [
        {
            key: 'type',
            label: 'Request',
            width: '1.4fr',
            cell: (request) => (
                <span className="truncate">{request.type_label}</span>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '0.9fr',
            align: 'right',
            cell: (request) => (
                <span className="font-semibold tabular-nums">
                    {request.amount === null
                        ? '—'
                        : formatMoney(request.amount)}
                </span>
            ),
        },
        {
            key: 'requested',
            label: 'Requested',
            width: '1.3fr',
            cell: (request) => (
                <span className="truncate text-muted-foreground">
                    {[
                        request.requested_by,
                        request.requested_at
                            ? formatDateOnly(toDateInput(request.requested_at))
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            ),
        },
        {
            key: 'evidence',
            label: 'Evidence',
            width: '0.8fr',
            cell: (request) => (
                <span className="text-muted-foreground">
                    {request.files.length === 1
                        ? '1 file'
                        : `${request.files.length} files`}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '170px',
            cell: (request) => (
                <StatusBadge variant={request.tone}>
                    {request.status_label}
                </StatusBadge>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Car}
            title="Vehicle reviews"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.open > 0 ? 'warning' : 'success'}
                >
                    {summary.open} waiting
                </PageHeaderStatusChip>
            }
            subline="Requests Fleet sends to Finance from a vehicle’s Finance view. Resolve or decline them here; the requester sees the decision on the vehicle."
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') apply({ search });
                    }}
                    placeholder="Search requests, vehicles or notes…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Waiting for Finance"
                        tone={summary.open > 0 ? 'warning' : undefined}
                        href="/finance/vehicle-reviews"
                        ariaLabel="View requests waiting for Finance"
                    >
                        <PageHeaderMeterBig>{summary.open}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.oldest_open_at
                                ? `Oldest since ${formatDateTime(summary.oldest_open_at)}`
                                : 'Nothing waiting'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Decided in 30 days"
                        href="/finance/vehicle-reviews?status=decided"
                        ariaLabel="View decided requests"
                    >
                        <PageHeaderMeterBig>
                            {summary.decided_30}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Resolved or declined
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={filters.status}
                    options={STATUS_OPTIONS}
                    onChange={(value) => apply({ status: value as Status })}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vehicle reviews" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Vehicle review requests"
                    caption={`${requests.length} of ${total} shown${can.decide ? '' : ' · view only'}`}
                />

                {requests.length === 0 ? (
                    filters.search || filters.status !== 'open' ? (
                        <EmptySearch
                            onClear={() => {
                                setSearch('');
                                router.get(
                                    '/finance/vehicle-reviews',
                                    {},
                                    { preserveState: true },
                                );
                            }}
                            title="No requests match these filters"
                        />
                    ) : (
                        <EmptyList
                            icon={Car}
                            itemName="request"
                            title="Nothing waiting for Finance"
                            description="Requests appear here when someone asks Finance to review a vehicle’s invoice, purchase, fixed asset or cost allocation."
                        />
                    )
                ) : (
                    <EntityTable
                        rows={requests}
                        rowKey={(request) => request.id}
                        identityLabel="Vehicle"
                        identity={(request) => ({
                            icon: Car,
                            name: request.reference ?? `Request #${request.id}`,
                            subline: vehicleLabel(request),
                        })}
                        columns={columns}
                        actionsFor={menuFor}
                        onOpen={(request) => setOpenId(request.id)}
                        onRowContextMenu={ctx.open}
                        mutedFor={(request) => request.status !== 'submitted'}
                        minWidth={1080}
                    />
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Car}
                    title={
                        ctx.ctx.record.reference ??
                        `Request #${ctx.ctx.record.id}`
                    }
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            <ReviewRequestDialog
                request={selected}
                onClose={() => setOpenId(null)}
            />
        </AppLayout>
    );
}
