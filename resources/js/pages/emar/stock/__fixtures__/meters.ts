import type { StockMetrics } from '../_meters';

/** Stock hub meters for tests: all zero unless a test sets them. */
export function stockMeters(
    overrides: Partial<StockMetrics> = {},
): StockMetrics {
    return {
        tracked: 0,
        out: 0,
        expiring: 0,
        orders: 0,
        counts: 0,
        running_low: 0,
        running_low_first: null,
        expired: 0,
        to_receive: 0,
        to_receive_due: 0,
        to_receive_part: 0,
        pharmacy_orders: 0,
        drafts: 0,
        people: 0,
        controlled_hidden: 0,
        ...overrides,
    };
}
