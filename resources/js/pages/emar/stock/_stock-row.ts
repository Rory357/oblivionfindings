import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { StockItem, SupplyOrder } from './_types';

/**
 * The approved P06 stock row (README §2, 1440-06): one state badge and the
 * lines that say why — expired packs, running low, a count to sign off, the
 * open order and the pack to use first. Quantities arrive from the server as
 * MedicationStockQuantity::display strings ("68", "2.5", "0").
 */
export type StockRowState =
    | 'none'
    | 'unknown'
    | 'arriving'
    | 'out'
    | 'expired'
    | 'low'
    | 'count_due'
    | 'use_first'
    | 'expiring'
    | 'ok';

/** Approved P06 defaults (P11 Q5): warn within 30 days, "use first" within 7. */
const EXPIRY_WARN_DAYS = 30;
const EXPIRY_CRITICAL_DAYS = 7;

/** "68 tablets", "1 tablet", "2.5 mL". */
export function amount(quantity: string, unit: string | null): string {
    const label = unit ?? '';
    return `${quantity} ${quantity === '1' ? label.replace(/s$/, '') : label}`.trim();
}

/** Pack expiry as printed on the pack: month and year. */
export const monthYear = (date: string) =>
    `${date.slice(5, 7)}/${date.slice(0, 4)}`;

export function orderLine(order: SupplyOrder): string {
    switch (order.status) {
        case 'draft':
            return `Order drafted — not sent to the pharmacy yet (#${order.id})`;
        case 'dispensed':
            return order.expected_delivery
                ? `Delivery due ${formatDateOnly(order.expected_delivery)} (#${order.id})`
                : `Dispensed — no delivery date given (#${order.id})`;
        case 'part_received':
            return `${order.still_to_come ?? 'The rest'} still to come${order.expected_delivery ? ` ${formatDateOnly(order.expected_delivery)}` : ''} (#${order.id})`;
        default:
            return `Ordered from ${order.pharmacy_name}${order.communication_recorded_at ? ` ${formatDateTime(order.communication_recorded_at)}` : ''} (#${order.id})`;
    }
}

export function stockRow(item: StockItem): {
    state: StockRowState;
    lines: string[];
} {
    const order = item.open_order ?? null;
    const ordered = order ? orderLine(order) : null;
    if (item.usable_display == null) {
        if (item.stock_id === null)
            return {
                state: order?.status === 'dispensed' ? 'arriving' : 'none',
                lines: [
                    ordered ?? 'Not ordered yet — order it from the pharmacy',
                ],
            };
        return {
            state: 'unknown',
            lines: [
                item.lots_started
                    ? 'The balance is not known — count it'
                    : 'Pack tracking has not been set up',
                ...(ordered ? [ordered] : []),
            ],
        };
    }
    const expired = item.expired_lots ?? [];
    const out = item.state === 'out';
    if (out && !expired.length) {
        if (order?.status === 'dispensed')
            return { state: 'arriving', lines: [ordered as string] };
        return {
            state: 'out',
            lines: [ordered ?? 'Order it from the pharmacy now'],
        };
    }
    const lines: string[] = [];
    if (expired.length)
        lines.push(
            `${expired
                .map(
                    (pack) =>
                        `${amount(pack.quantity, item.unit)} from ${pack.batch_number ?? 'a pack with no batch'} expired ${monthYear(pack.expiry_date)}`,
                )
                .join('; ')} — take out of use`,
        );
    const low = item.state === 'low';
    if (low && item.reorder_display != null)
        lines.push(
            `${amount(item.usable_display, item.unit)} left — at or below the reorder level (${item.reorder_display})`,
        );
    const count = item.count_to_sign_off ?? null;
    if (count)
        lines.push(
            `Counted${count.counted_at ? ` ${formatDateTime(count.counted_at)}` : ''} — the house lead signs it off`,
        );
    if (ordered && (low || out || order?.status !== 'draft'))
        lines.push(ordered);
    const days = item.next_expiry_in_days;
    const soon =
        days != null &&
        days >= 0 &&
        days <= EXPIRY_WARN_DAYS &&
        !!item.next_expiry;
    const several = (item.usable_packs ?? 0) > 1;
    if (soon && several)
        lines.push(
            `Use ${item.next_batch ?? 'the pack with no batch'} first — expires ${monthYear(item.next_expiry as string)}${item.next_quantity ? ` (${amount(item.next_quantity, item.unit)})` : ''}`,
        );
    else if (soon)
        lines.push(
            `${item.next_batch ?? 'Pack'} expires ${monthYear(item.next_expiry as string)}`,
        );
    const state: StockRowState = expired.length
        ? 'expired'
        : low
          ? 'low'
          : count
            ? 'count_due'
            : soon && several && (days as number) <= EXPIRY_CRITICAL_DAYS
              ? 'use_first'
              : soon
                ? 'expiring'
                : 'ok';
    return { state, lines };
}
