import { PersonDisc } from '@/components/lists/entity-cells';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import type { MouseEvent, ReactNode } from 'react';
import { amount, monthYear, stockRow, type StockRowState } from './_stock-row';
import type { Capabilities, Pager, StockItem, SupplyOrder } from './_types';

const BADGE: Record<StockRowState, { label: string; variant: StatusVariant }> =
    {
        none: { label: 'No stock', variant: 'warning' },
        unknown: { label: 'Not counted yet', variant: 'neutral' },
        arriving: { label: 'Delivery due', variant: 'info' },
        out: { label: 'Out of stock', variant: 'critical' },
        expired: { label: 'Expired pack', variant: 'critical' },
        low: { label: 'Running low', variant: 'warning' },
        count_due: { label: 'Count to sign off', variant: 'warning' },
        use_first: { label: 'Use first', variant: 'warning' },
        expiring: { label: 'Expiring', variant: 'info' },
        ok: { label: 'In stock', variant: 'success' },
    };

export type StockRowActions = {
    receive: (item: StockItem, order: SupplyOrder) => void;
    remove: (item: StockItem) => void;
    signOff: (countId: number) => void;
    order: (item: StockItem) => void;
    sendOrder: (order: SupplyOrder) => void;
};

/** "19 shown · 7 people", keeping "of total" when the list is paged. */
export function stockCaption(
    pager: Pager<StockItem>,
    people: number,
    hiddenControlled: number,
): string {
    const shown = pager.data.length;
    return `${shown}${pager.total > shown ? ` of ${pager.total}` : ''} shown · ${people} ${people === 1 ? 'person' : 'people'}${hiddenControlled ? ` · ${hiddenControlled} controlled medicine${hiddenControlled === 1 ? '' : 's'} not shown — needs controlled-medicine access` : ''}`;
}

/** The row button is the next step (README §2): one action, never two. */
function NextStep({
    item,
    can,
    act,
}: {
    item: StockItem;
    can: Capabilities;
    act: StockRowActions;
}): ReactNode {
    if (item.controlled)
        return (
            <span className="text-caption text-right">
                In the controlled register
            </span>
        );
    const { state } = stockRow(item);
    const order = item.open_order ?? null;
    const count = item.count_to_sign_off ?? null;
    const stop = (run: () => void) => (event: MouseEvent) => {
        event.stopPropagation();
        run();
    };
    if (state === 'arriving' && order && can.receive)
        return (
            <Button size="sm" onClick={stop(() => act.receive(item, order))}>
                Receive
            </Button>
        );
    if (state === 'expired' && can.manage && item.lots_started)
        return (
            <Button
                size="sm"
                variant="outline"
                onClick={stop(() => act.remove(item))}
            >
                Remove expired
            </Button>
        );
    if (state === 'count_due' && can.manage && count)
        return (
            <Button size="sm" onClick={stop(() => act.signOff(count.id))}>
                Sign off count
            </Button>
        );
    if (
        ['none', 'unknown', 'out', 'low'].includes(state) &&
        can.manage &&
        (item.receivable ?? item.active) &&
        (!order || order.status === 'draft')
    )
        return order ? (
            <Button
                size="sm"
                variant="outline"
                onClick={stop(() => act.sendOrder(order))}
            >
                Send the order
            </Button>
        ) : (
            <Button
                size="sm"
                variant="outline"
                onClick={stop(() => act.order(item))}
            >
                Order
            </Button>
        );
    return null;
}

/** The approved P06 table: Medicine · Person · On hand · Packs · State. */
export function stockColumns(
    can: Capabilities,
    act: StockRowActions,
): EntityTableColumn<StockItem>[] {
    return [
        {
            key: 'person',
            label: 'Person',
            width: '0.9fr',
            cell: (item) => (
                <span className="flex min-w-0 items-center gap-2">
                    <PersonDisc name={item.client_name} size={22} />
                    <span className="truncate">{item.client_name}</span>
                </span>
            ),
        },
        {
            key: 'on_hand',
            label: 'On hand',
            width: '0.9fr',
            cell: (item) => {
                if (item.usable_display == null)
                    return (
                        <span className="text-muted-foreground">
                            {item.stock_id === null
                                ? 'None yet'
                                : 'Not counted yet'}
                        </span>
                    );
                const supply =
                    item.days_supply != null
                        ? `${item.days_supply} days’ supply`
                        : item.reorder_display != null
                          ? `Reorder at ${item.reorder_display}`
                          : null;
                const held =
                    item.physical_display != null &&
                    item.physical_display !== item.usable_display
                        ? `${amount(item.physical_display, item.unit)} physically held`
                        : null;
                return (
                    <span className="grid">
                        <span className="font-semibold">
                            {amount(item.usable_display, item.unit)}
                        </span>
                        {supply && (
                            <span className="text-caption">{supply}</span>
                        )}
                        {held && <span className="text-caption">{held}</span>}
                    </span>
                );
            },
        },
        {
            key: 'packs',
            label: 'Packs',
            width: '0.9fr',
            cell: (item) =>
                item.pack_count ? (
                    <span className="grid">
                        <span>
                            {item.pack_count}{' '}
                            {item.pack_count === 1 ? 'pack' : 'packs'}
                        </span>
                        {(item.usable_packs ?? 0) > 0 && (
                            <span className="text-caption">
                                First:{' '}
                                {item.next_expiry
                                    ? monthYear(item.next_expiry)
                                    : 'no expiry printed'}
                            </span>
                        )}
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        {item.lots_started || item.stock_id === null
                            ? 'None yet'
                            : 'Not set up'}
                    </span>
                ),
        },
        {
            key: 'state',
            label: 'State',
            width: '1.7fr',
            cell: (item) => {
                const { state, lines } = stockRow(item);
                return (
                    <span className="grid justify-items-start gap-1 py-0.5">
                        <StatusBadge
                            className="w-fit"
                            variant={BADGE[state].variant}
                        >
                            {BADGE[state].label}
                        </StatusBadge>
                        {lines.map((line) => (
                            <span key={line} className="text-caption">
                                {line}
                            </span>
                        ))}
                    </span>
                );
            },
        },
        {
            key: 'next',
            label: '',
            width: '150px',
            align: 'right',
            cell: (item) => <NextStep item={item} can={can} act={act} />,
        },
    ];
}
