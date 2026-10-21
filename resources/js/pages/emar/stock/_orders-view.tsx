import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { ClipboardList, ShieldCheck, Truck } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { PharmacyDispatchChip } from './_hub-tables';
import { amount, monthYear } from './_stock-row';
import type { Capabilities, Pager, SupplyOrder } from './_types';

/** The approved P06 sections (1440-12, README §2). */
const SECTIONS = [
    {
        key: 'receive',
        title: 'To receive',
        caption: 'Dispensed by the pharmacy, or part received',
        states: ['dispensed', 'part_received'],
        empty: 'Nothing to receive',
    },
    {
        key: 'sent',
        title: 'Waiting on the pharmacy',
        caption: 'Sent — record what they dispense when the label arrives',
        states: ['submitted', 'confirmed'],
        empty: 'Nothing waiting on the pharmacy',
    },
    {
        key: 'draft',
        title: 'Drafts',
        caption: 'Not sent yet',
        states: ['draft'],
        empty: 'Nothing drafted',
    },
    {
        key: 'closed',
        title: 'Closed',
        caption: 'Received, closed short or cancelled — read only, kept',
        states: ['delivered', 'received', 'closed_short', 'cancelled'],
        empty: 'Nothing closed yet',
    },
];

const BADGE: Record<string, { label: string; variant: StatusVariant }> = {
    draft: { label: 'Draft', variant: 'neutral' },
    submitted: { label: 'Sent', variant: 'info' },
    confirmed: { label: 'Sent', variant: 'info' },
    dispensed: { label: 'To receive', variant: 'warning' },
    part_received: { label: 'Part received', variant: 'warning' },
    delivered: { label: 'Received', variant: 'success' },
    received: { label: 'Received', variant: 'success' },
    closed_short: { label: 'Closed short', variant: 'neutral' },
    cancelled: { label: 'Cancelled', variant: 'neutral' },
};

const at = (iso?: string | null) => (iso ? ` ${formatDateTime(iso)}` : '');

/** What each order's state means now, in the approved words. */
export function orderLines(order: SupplyOrder): string[] {
    const unit = order.unit ?? null;
    const due = order.expected_delivery
        ? formatDateOnly(order.expected_delivery)
        : null;
    switch (order.status) {
        case 'draft':
            return [
                `Drafted${at(order.created_at)} — not sent to the pharmacy yet`,
            ];
        case 'submitted':
        case 'confirmed':
            return [
                `Sent${at(order.submitted_at ?? order.communication_recorded_at)}${order.needed_by ? ` · needed by ${formatDateOnly(order.needed_by)}` : ''} — waiting for the pharmacy`,
                ...(order.overdue
                    ? [
                          due
                              ? `Delivery overdue — expected ${due}`
                              : 'Overdue — past the date it was needed by',
                      ]
                    : []),
            ];
        case 'dispensed':
            return [
                `${order.pharmacy_name} dispensed ${amount(order.dispensed_display ?? String(order.quantity_ordered), unit)}${at(order.dispensed_at)} · batch ${order.batch_number ?? 'not printed'}, expires ${order.batch_expiry ? monthYear(order.batch_expiry) : 'not printed'}`,
                due
                    ? `${order.overdue ? 'Delivery overdue — expected' : 'Delivery due'} ${due}`
                    : 'No delivery date given',
            ];
        case 'part_received':
            return [
                `${amount(order.received_display ?? '0', unit)} received`,
                `${order.still_to_come ? amount(order.still_to_come, unit) : 'The rest'} still to come${due ? ` — ${order.overdue ? 'was expected' : 'expected'} ${due}` : ''}`,
            ];
        case 'cancelled':
        case 'closed_short':
            return [
                `${BADGE[order.status].label}${at(order.closed_at)}${order.closure_reason ? `: “${order.closure_reason}”` : ''}`,
            ];
        default:
            return [`Received${at(order.delivered_at)} — read only`];
    }
}

/** The row button is the order's next step. */
function NextStep({
    order,
    can,
    receive,
    open,
}: {
    order: SupplyOrder;
    can: Capabilities;
    receive: (order: SupplyOrder) => void;
    open: (order: SupplyOrder) => void;
}): ReactNode {
    const stop = (run: () => void) => (event: MouseEvent) => {
        event.stopPropagation();
        run();
    };
    if (['dispensed', 'part_received'].includes(order.status)) {
        if (order.controlled && !can.receive_controlled)
            return (
                <span className="text-caption text-right">
                    The house lead receives it, with a witness
                </span>
            );
        return can.receive ? (
            <Button size="sm" onClick={stop(() => receive(order))}>
                Receive
            </Button>
        ) : null;
    }
    if (!can.manage) return null;
    if (['submitted', 'confirmed'].includes(order.status))
        return (
            <Button
                size="sm"
                variant="outline"
                onClick={stop(() => open(order))}
            >
                Record dispensing
            </Button>
        );
    if (order.status === 'draft')
        return (
            <Button
                size="sm"
                variant="outline"
                onClick={stop(() => open(order))}
            >
                Review and send
            </Button>
        );
    return null;
}

/**
 * Approved P06 Deliveries & orders (1440-12): To receive · Waiting on the
 * pharmacy · Drafts · Closed. The server keeps each section together across
 * pages; on a paged list an empty section is left out rather than called empty.
 */
export function OrdersView({
    pager,
    can,
    open,
    receive,
    actions,
}: {
    pager: Pager<SupplyOrder>;
    can: Capabilities;
    open: (order: SupplyOrder) => void;
    receive: (order: SupplyOrder) => void;
    actions: (order: SupplyOrder) => MenuItem[];
}) {
    const [context, setContext] = useState<{
        x: number;
        y: number;
        order: SupplyOrder;
    } | null>(null);
    const paged = pager.last_page > 1;
    if (!pager.data.length)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={Truck}
                    title="No pharmacy orders yet"
                    description="Each order is one supply record, from draft to received."
                />
            </Card>
        );
    return (
        <div className="grid min-w-0 gap-5">
            {SECTIONS.map((section) => {
                const rows = pager.data.filter((order) =>
                    section.states.includes(order.status),
                );
                if (paged && !rows.length) return null;
                return (
                    <section
                        key={section.key}
                        className="grid min-w-0 gap-2.5"
                        aria-label={section.title}
                    >
                        <ListCaption
                            title={section.title}
                            caption={`${rows.length} shown · ${section.caption}`}
                        />
                        {rows.length ? (
                            <EntityTable<SupplyOrder>
                                rows={rows}
                                rowKey={(order) => order.id}
                                rowHeight="content"
                                minWidth={1000}
                                identityLabel="Order"
                                identityWidth="1.1fr"
                                identity={(order) => ({
                                    icon: order.controlled
                                        ? ShieldCheck
                                        : ClipboardList,
                                    name: order.medication_name,
                                    subline: `#${order.id}${order.controlled ? ' · controlled' : ''}`,
                                })}
                                columns={[
                                    {
                                        key: 'person',
                                        label: 'Person',
                                        width: '0.8fr',
                                        cell: (order) => order.client_name,
                                    },
                                    {
                                        key: 'ordered',
                                        label: 'Ordered',
                                        width: '0.6fr',
                                        cell: (order) =>
                                            amount(
                                                String(order.quantity_ordered),
                                                order.unit ?? null,
                                            ),
                                    },
                                    {
                                        key: 'state',
                                        label: 'State',
                                        width: '2fr',
                                        cell: (order) => (
                                            <span className="grid justify-items-start gap-1 py-0.5">
                                                <StatusBadge
                                                    className="w-fit"
                                                    variant={
                                                        BADGE[order.status]
                                                            ?.variant ??
                                                        'neutral'
                                                    }
                                                >
                                                    {BADGE[order.status]
                                                        ?.label ??
                                                        order.status.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                </StatusBadge>
                                                {orderLines(order).map(
                                                    (line) => (
                                                        <span
                                                            key={line}
                                                            className="text-caption"
                                                        >
                                                            {line}
                                                        </span>
                                                    ),
                                                )}
                                                {order.dispatch ? (
                                                    <PharmacyDispatchChip
                                                        dispatch={
                                                            order.dispatch
                                                        }
                                                    />
                                                ) : null}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'next',
                                        label: '',
                                        width: '170px',
                                        align: 'right',
                                        cell: (order) => (
                                            <NextStep
                                                order={order}
                                                can={can}
                                                receive={receive}
                                                open={open}
                                            />
                                        ),
                                    },
                                ]}
                                actionsFor={actions}
                                onOpen={open}
                                onRowContextMenu={(event, order) => {
                                    event.preventDefault();
                                    setContext({
                                        x: event.clientX,
                                        y: event.clientY,
                                        order,
                                    });
                                }}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState
                                    variant="compact"
                                    icon={Truck}
                                    title={section.empty}
                                />
                            </Card>
                        )}
                    </section>
                );
            })}
            <LaravelPagination links={pager.links} lastPage={pager.last_page} />
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={context.order.medication_name}
                    icon={Truck}
                    items={actions(context.order)}
                    onClose={() => setContext(null)}
                />
            )}
        </div>
    );
}
