import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';

export type StockMetrics = {
    tracked: number;
    out: number;
    expiring: number;
    orders: number;
    counts: number;
    running_low: number;
    running_low_first: string | null;
    expired: number;
    to_receive: number;
    to_receive_due: number;
    to_receive_part: number;
    pharmacy_orders: number;
    drafts: number;
    people: number;
    controlled_hidden: number;
};

/**
 * The approved P06 meter row (README §2: Running low · Expiring · To receive ·
 * Counts to sign off · Pharmacy orders · Medicines in stock). Each opens its
 * view. The figures come from the server in the list's own scope.
 */
export function StockMeters({
    metrics: m,
    open,
}: {
    metrics: StockMetrics;
    open: (changes: Record<string, string | null>) => void;
}) {
    return (
        <>
            <PageHeaderMeterBlock
                label="Running low"
                tone={m.running_low ? 'warning' : 'brand'}
                onClick={() => open({ view: 'stock', show: 'low' })}
            >
                <PageHeaderMeterBig>{m.running_low}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.running_low && m.running_low_first
                        ? `First: ${m.running_low_first}`
                        : 'Nothing low'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Expiring"
                tone={m.expired ? 'critical' : m.expiring ? 'warning' : 'brand'}
                onClick={() => open({ view: 'expiring' })}
            >
                <PageHeaderMeterBig>{m.expiring}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.expired
                        ? `${m.expired} expired · remove`
                        : m.expiring
                          ? 'Within 30 days'
                          : 'None within 30 days'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="To receive"
                tone={m.to_receive ? 'warning' : 'brand'}
                onClick={() => open({ view: 'orders' })}
            >
                <PageHeaderMeterBig>{m.to_receive}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.to_receive_due
                        ? `${m.to_receive_due} due by today`
                        : m.to_receive && m.to_receive_part === m.to_receive
                          ? 'The rest of a part delivery'
                          : m.to_receive
                            ? 'On the way'
                            : 'Nothing due'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Counts to sign off"
                tone={m.counts ? 'warning' : 'brand'}
                onClick={() => open({ view: 'counts' })}
            >
                <PageHeaderMeterBig>{m.counts}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.counts
                        ? 'The house lead signs them off'
                        : 'None waiting'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Pharmacy orders"
                onClick={() => open({ view: 'orders' })}
            >
                <PageHeaderMeterBig>{m.pharmacy_orders}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.drafts
                        ? `${m.drafts} draft not sent`
                        : m.pharmacy_orders
                          ? 'Sent, waiting'
                          : 'None open'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Medicines in stock"
                onClick={() => open({ view: 'stock', show: null })}
            >
                <PageHeaderMeterBig>{m.tracked}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {m.controlled_hidden
                        ? `+${m.controlled_hidden} controlled hidden`
                        : `${m.people} ${m.people === 1 ? 'person' : 'people'}`}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
}
