import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    RecordBatchWizard,
    type BatchRecord,
} from '@/components/finance/record-batch-wizard';
import { todayInAuckland } from '@/components/fleet-assets/vehicle-workspace/workspace-model';
import {
    EntityCard,
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
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useIsMobile } from '@/hooks/use-mobile';
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

type Status =
    | 'open'
    | 'ready'
    | 'mine'
    | 'overdue'
    | 'preparing'
    | 'changes_requested'
    | 'decided'
    | 'all';

interface Props {
    requests: ReviewRequest[];
    focus: ReviewRequest | null;
    total: number;
    pagination: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { status: Status; search: string; sort?: string };
    summary: {
        open: number;
        decided_30: number;
        oldest_open_at: string | null;
    };
    can: { decide: boolean };
}

const STATUS_OPTIONS = [
    { value: 'preparing', label: 'Preparing evidence' },
    { value: 'changes_requested', label: 'Returned for correction' },
    { value: 'ready', label: 'Ready for review' },
    { value: 'mine', label: 'Assigned to me' },
    { value: 'overdue', label: 'Overdue reviews' },
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
    pagination,
    filters,
    summary,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const isMobile = useIsMobile();
    const toggleSelection = (request: ReviewRequest) =>
        setSelectedIds((current) => {
            const next = new Set(current);
            if (next.has(request.id)) next.delete(request.id);
            else next.add(request.id);
            return next;
        });
    const nextAction = (request: ReviewRequest) =>
        request.status === 'preparing'
            ? 'Requester to finish evidence'
            : request.status === 'changes_requested'
              ? 'Requester to respond'
              : request.status === 'submitted'
                ? 'Finance to decide'
                : 'Decision recorded';
    const [batch, setBatch] = useState<BatchRecord[] | null>(null);
    const selectedRequests = requests.filter(
        (request) =>
            selectedIds.has(request.id) &&
            request.can_decide &&
            request.evidence_token,
    );
    const startBatch = () =>
        setBatch(
            selectedRequests.map((request) => ({
                id: request.id,
                label: request.reference ?? `Request ${request.id}`,
                href: `/finance/vehicle-reviews?request=${request.id}`,
                url: `/finance/vehicle-reviews/${request.id}/decision`,
                body: {
                    decision: 'resolved',
                    expected_version: request.lock_version,
                    evidence_token: request.evidence_token,
                },
                detail: (
                    <>
                        <p>
                            {vehicleLabel(request)} · {request.type_label} ·{' '}
                            {request.amount === null
                                ? 'No amount'
                                : formatMoney(request.amount)}
                        </p>
                        <p>{request.source}</p>
                        <p>{request.note}</p>
                        <p>
                            {request.files.length} supporting files — open the
                            record to review the evidence.
                        </p>
                    </>
                ),
            })),
        );
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
            key: 'owner',
            label: 'Next action / owner',
            width: '1.5fr',
            cell: (request) => (
                <span>
                    <strong className="block text-xs">
                        {nextAction(request)}
                    </strong>
                    <span className="text-xs text-muted-foreground">
                        {request.assigned_to ?? 'Unassigned'}
                        {request.due_on
                            ? ` · Due ${formatDateOnly(request.due_on)}`
                            : ''}
                    </span>
                </span>
            ),
        },
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
                <>
                    <PageHeaderFilterSelect
                        label="Sort"
                        value={filters.sort ?? 'oldest'}
                        options={[
                            { value: 'oldest', label: 'Oldest first' },
                            { value: 'newest', label: 'Newest first' },
                            { value: 'due', label: 'Due date' },
                            { value: 'amount', label: 'Largest amount' },
                        ]}
                        onChange={(sort) => apply({ sort })}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status}
                        options={STATUS_OPTIONS}
                        onChange={(value) => apply({ status: value as Status })}
                    />
                </>
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
                    caption={`${pagination.from ?? 0}–${pagination.to ?? 0} of ${total} requests${can.decide ? '' : ' · view only'}`}
                />
                {can.decide && (
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <Button
                            className="min-h-[44px] sm:min-h-9"
                            variant="outline"
                            disabled={!selectedRequests.length}
                            onClick={startBatch}
                        >
                            Resolve selected ({selectedRequests.length})
                        </Button>
                        <span className="text-caption">
                            Selections apply to this page. Each decision is
                            checked and saved separately.
                        </span>
                    </div>
                )}

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
                ) : isMobile ? (
                    <div
                        className="grid gap-5"
                        aria-label="Vehicle review request cards"
                    >
                        {requests.map((request) => (
                            <EntityCard
                                key={request.id}
                                name={
                                    request.reference ??
                                    `Request #${request.id}`
                                }
                                icon={Car}
                                subline={request.type_label}
                                meridian={
                                    request.files.some(
                                        (file) => file.waiting,
                                    ) ||
                                    Boolean(
                                        request.due_on &&
                                        request.due_on < todayInAuckland() &&
                                        [
                                            'preparing',
                                            'submitted',
                                            'changes_requested',
                                        ].includes(request.status),
                                    )
                                        ? 'warning'
                                        : 'success'
                                }
                                className="[&_button:not([role=checkbox])]:min-h-[44px] [&_button:not([role=checkbox])]:min-w-[44px] [&_label]:min-h-[44px] [&_label]:min-w-[44px] [&_label]:justify-center"
                                actions={menuFor(request)}
                                onOpen={() => setOpenId(request.id)}
                                onContextMenu={(event) =>
                                    ctx.open(event, request)
                                }
                                selection={
                                    can.decide
                                        ? {
                                              checked: selectedRequests.some(
                                                  (item) =>
                                                      item.id === request.id,
                                              ),
                                              label: `Select ${request.reference}`,
                                              disabled:
                                                  !request.can_decide ||
                                                  !request.evidence_token,
                                              onToggle: () =>
                                                  toggleSelection(request),
                                          }
                                        : undefined
                                }
                                chips={
                                    <>
                                        <StatusBadge variant={request.tone}>
                                            {request.status_label}
                                        </StatusBadge>
                                        <strong className="ml-auto tabular-nums">
                                            {request.amount === null
                                                ? '—'
                                                : formatMoney(request.amount)}
                                        </strong>
                                        <p className="w-full text-sm">
                                            {vehicleLabel(request)}
                                        </p>
                                        <p className="text-caption w-full">
                                            {nextAction(request)}
                                            {request.due_on
                                                ? ` · Due ${formatDateOnly(request.due_on)}`
                                                : ''}{' '}
                                            · {request.files.length} file(s)
                                        </p>
                                    </>
                                }
                                footer={{
                                    personName: request.assigned_to,
                                    primary:
                                        request.assigned_to ?? 'Unassigned',
                                    secondary: request.requested_by
                                        ? `Requested by ${request.requested_by}`
                                        : 'Requester unavailable',
                                }}
                                openLabel="Review"
                            />
                        ))}
                    </div>
                ) : (
                    <EntityTable
                        rows={requests}
                        selection={
                            can.decide
                                ? {
                                      keys: new Set(
                                          selectedRequests.map(
                                              (request) => request.id,
                                          ),
                                      ),
                                      onToggle: toggleSelection,
                                      labelFor: (request) =>
                                          `Select ${request.reference}`,
                                      canSelect: (request) =>
                                          request.can_decide &&
                                          !!request.evidence_token,
                                  }
                                : undefined
                        }
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
                <LaravelPagination
                    links={pagination.links}
                    lastPage={pagination.last_page}
                />
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
            {batch && (
                <RecordBatchWizard
                    title="Resolve selected requests"
                    description="This records the Finance review decision for each request. Bill approval, ledger posting and payment remain separate actions."
                    records={batch}
                    action="Resolve selected requests"
                    requireNote
                    onClose={() => {
                        setBatch(null);
                        setSelectedIds(new Set());
                        router.reload();
                    }}
                />
            )}
        </AppLayout>
    );
}
