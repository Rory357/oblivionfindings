import type { AssetFinanceTechnologyProjection } from '@/components/assets/asset-finance-technology-projection';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateOnly } from '@/lib/datetime';
import { formatDate, formatDateTime } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Info,
    Landmark,
    Link2,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Empty,
    Fact,
    Panel,
    SectionHeading,
    State,
    human,
} from './presentation';
import type { ProfileWorkspace } from './types';

export function AssetFinanceView({
    projection,
    data,
    assetTag,
    work,
    onRequest,
}: {
    projection?: AssetFinanceTechnologyProjection | null;
    data: ProfileWorkspace;
    assetTag: string;
    work: ReactNode;
    onRequest?: (type?: string) => void;
}) {
    const source =
        projection?.permissions.finance &&
        projection.reconciliation.state !== 'duplicate_financial_records'
            ? projection.finance
            : null;
    const money = (value?: number | null) =>
        value == null
            ? 'Unavailable'
            : new Intl.NumberFormat('en-NZ', {
                  style: 'currency',
                  currency: 'NZD',
              }).format(value);
    const costs = data.sources?.costs;
    const replacement = data.finance_reviews.find(
        (review) => review.type === 'Asset replacement review',
    );
    const costMoney = (amount: string, currency: string) =>
        new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(
            Number(amount),
        );
    return (
        <div className="space-y-5">
            <SectionHeading
                eyebrow={`Finance connection · ${assetTag}`}
                title="Asset value & costs"
                description="Linked records, service costs and decisions waiting with Finance."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {projection?.links.finance && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        source?.href || projection.links.finance
                                    }
                                >
                                    <Link2 />
                                    Open Finance
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        )}
                        {onRequest && (
                            <Button onClick={() => onRequest()}>
                                <ReceiptText />
                                Request Finance review
                            </Button>
                        )}
                    </div>
                }
            />
            <div className="flex gap-3 rounded-xl border border-status-info/25 bg-status-info-bg p-4">
                <Info className="size-4 shrink-0 text-status-info" />
                <div>
                    <strong className="text-subtle">
                        Financial decisions remain in Finance
                    </strong>
                    <p className="text-caption mt-1">
                        Editing the asset, attaching an invoice or completing
                        work does not approve expenditure, post a journal or
                        dispose of the fixed asset.
                    </p>
                </div>
            </div>
            {(!source || projection?.reconciliation.attention) && (
                <Card className="gap-0 p-4">
                    <strong className="text-subtle">
                        {projection?.reconciliation.title ||
                            'Finance access required'}
                    </strong>
                    <p className="text-subtle mt-1 text-muted-foreground">
                        {projection?.reconciliation.description ||
                            'Financial details are available only to authorised Finance viewers.'}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {projection?.reconciliation.actions.map((action) => (
                            <Button key={action.href} variant="outline" asChild>
                                <Link href={action.href}>
                                    {action.label}
                                    <ArrowUpRight />
                                </Link>
                            </Button>
                        ))}
                    </div>
                </Card>
            )}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    [
                        'Capitalised cost',
                        money(source?.purchase_cost),
                        'Recorded acquisition cost',
                    ],
                    [
                        'Accumulated depreciation',
                        money(source?.accumulated_depreciation),
                        'Posted by Finance',
                    ],
                    [
                        'Net book value',
                        money(source?.book_value),
                        'Authoritative Finance value',
                    ],
                    [
                        'Posted service costs',
                        costs?.allowed
                            ? costs.totals
                                  .map((total) =>
                                      costMoney(total.amount, total.currency),
                                  )
                                  .join(' + ') || 'No posted costs'
                            : 'Finance access required',
                        costs?.allowed
                            ? `${formatDateOnly(costs.from)} – ${formatDateOnly(costs.to)} · approved sites`
                            : 'Ledger access is required to show posted allocations',
                    ],
                ].map(([label, value, note]) => (
                    <section
                        key={label}
                        className="rounded-xl border bg-card p-5 shadow-sm"
                    >
                        <p className="text-caption text-muted-foreground">
                            {label}
                        </p>
                        <p className="text-page-title my-2">{value}</p>
                        <p className="text-caption text-muted-foreground">
                            {note}
                        </p>
                    </section>
                ))}
            </div>
            <div className="grid gap-5 lg:grid-cols-2">
                <Panel title="Fixed asset & allocation" icon={Landmark}>
                    <dl className="space-y-5">
                        <Fact label="Finance record">
                            {source ? (
                                <Link
                                    className="text-primary"
                                    href={source.href}
                                >
                                    {source.asset_tag || `FA-${source.id}`} ·{' '}
                                    {source.name}
                                    <ArrowUpRight className="ml-1 inline size-3" />
                                </Link>
                            ) : (
                                'No authorised fixed asset record linked'
                            )}
                        </Fact>
                        <Fact label="Acquired">
                            {source
                                ? formatDate(source.purchase_date)
                                : 'Unavailable'}
                        </Fact>
                        <Fact label="Recognition">
                            {source
                                ? human(source.status)
                                : 'Finance determines recognition'}
                        </Fact>
                        <Fact label="Disposal">
                            {source?.disposed_date
                                ? `${formatDate(source.disposed_date)} · ${money(source.disposal_proceeds)}`
                                : 'No disposal recorded in the available Finance source'}
                        </Fact>
                    </dl>
                </Panel>
                <Panel title="Linked work & exceptions" icon={Link2}>
                    {work}
                </Panel>
            </div>
            <div className="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
                <Panel title="Finance review requests" icon={ReceiptText}>
                    {projection?.permissions.finance ? (
                        data.finance_reviews.length ? (
                            data.finance_reviews.map((review) => (
                                <article
                                    key={review.id}
                                    className="space-y-2 border-b pb-4 last:border-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <strong className="text-subtle">
                                            {review.reference} · {review.type}
                                        </strong>
                                        <State value={review.status} />
                                    </div>
                                    <p className="text-caption text-muted-foreground">
                                        {formatDateTime(review.at)} ·{' '}
                                        {review.by}
                                    </p>
                                    <p className="text-subtle">{review.note}</p>
                                    {review.decision && (
                                        <p className="text-subtle">
                                            Finance decision: {review.decision}
                                        </p>
                                    )}
                                    <Link
                                        className="text-subtle inline-flex items-center gap-1 text-primary"
                                        href={review.url}
                                    >
                                        Open Finance review
                                        <ArrowUpRight className="size-3" />
                                    </Link>
                                </article>
                            ))
                        ) : (
                            <Empty>
                                No Finance review requests for this asset.
                            </Empty>
                        )
                    ) : (
                        <Empty>
                            Finance review details require Finance access.
                        </Empty>
                    )}
                </Panel>
                <Panel title="Replacement & retirement" icon={Wallet}>
                    <p className="text-subtle">
                        Review equipment needs and outstanding Maintenance
                        before requesting a replacement.
                    </p>
                    <dl className="space-y-5">
                        <Fact label="Replacement estimate">
                            {replacement?.amount
                                ? money(Number(replacement.amount))
                                : 'No estimate submitted'}
                        </Fact>
                        {replacement && (
                            <Fact label="Latest replacement request">
                                <Link
                                    className="text-primary"
                                    href={replacement.url}
                                >
                                    {replacement.reference} ·{' '}
                                    {human(replacement.status)}{' '}
                                    <ArrowUpRight className="inline size-3" />
                                </Link>
                            </Fact>
                        )}
                        <Fact label="Disposal decision">
                            Finance owns approval and financial disposal.
                        </Fact>
                    </dl>
                    <p className="text-caption text-muted-foreground">
                        No automatic write-off or replacement threshold is
                        configured here.
                    </p>
                    {onRequest && (
                        <Button
                            variant="outline"
                            onClick={() => onRequest('replacement_review')}
                        >
                            Request replacement review
                        </Button>
                    )}
                </Panel>
            </div>
            <div className="grid gap-5 lg:grid-cols-2">
                <Panel title="Posted service cost history" icon={Wallet}>
                    <p className="text-caption text-muted-foreground">
                        Posted maintenance allocations at your approved sites
                        for the last 12 months. Reversed journals and work
                        estimates are excluded. Different currencies stay
                        separate.
                    </p>
                    {costs?.allowed ? (
                        costs.entries.length ? (
                            costs.entries.map((entry) => (
                                <article
                                    key={entry.id}
                                    className="flex flex-wrap justify-between gap-3 border-b py-3 last:border-0"
                                >
                                    <div>
                                        <Link
                                            className="text-subtle text-primary"
                                            href={entry.url}
                                        >
                                            {entry.reference}{' '}
                                            <ArrowUpRight className="inline size-3" />
                                        </Link>
                                        <p className="text-caption text-muted-foreground">
                                            {formatDate(entry.date)}
                                        </p>
                                    </div>
                                    <strong className="text-subtle">
                                        {costMoney(
                                            entry.amount,
                                            entry.currency,
                                        )}
                                    </strong>
                                </article>
                            ))
                        ) : (
                            <Empty>
                                No posted service allocations in this period.
                            </Empty>
                        )
                    ) : (
                        <Empty>
                            Ledger access is required to view posted costs.
                        </Empty>
                    )}
                    {costs?.entries.length === 50 && (
                        <p className="text-caption">
                            Latest 50 allocations shown. The total includes
                            every posted allocation in the period.
                        </p>
                    )}
                </Panel>
                <Panel title="Supplier invoices" icon={ReceiptText}>
                    <p className="text-caption text-muted-foreground">
                        Invoice amounts and approval remain in Accounts Payable.
                        These amounts are not added to the posted cost total
                        again.
                    </p>
                    {costs?.bills.length ? (
                        costs.bills.map((bill) => (
                            <article
                                key={bill.id}
                                className="space-y-2 border-b py-3 last:border-0"
                            >
                                <div className="flex flex-wrap justify-between gap-3">
                                    <Link
                                        className="text-subtle text-primary"
                                        href={bill.url}
                                    >
                                        {bill.reference} ·{' '}
                                        {bill.supplier ||
                                            'Supplier unavailable'}{' '}
                                        <ArrowUpRight className="inline size-3" />
                                    </Link>
                                    <State value={bill.status} />
                                </div>
                                <p className="text-caption">
                                    {formatDate(bill.date)} ·{' '}
                                    {money(Number(bill.amount))}
                                </p>
                            </article>
                        ))
                    ) : (
                        <Empty>No authorised supplier invoices linked.</Empty>
                    )}
                </Panel>
            </div>
        </div>
    );
}
