import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    Receipt,
    ShoppingCart,
    Wallet,
} from 'lucide-react';

type FundSummary = {
    id: number;
    name: string;
    type?: string | null;
    balance: number;
    low_balance_threshold?: number | null;
    is_active: boolean;
    notes?: string | null;
};

type FundTransaction = {
    id: number;
    type?: string | null;
    amount: number;
    running_balance?: number | null;
    description?: string | null;
    category?: string | null;
    transaction_date?: string | null;
    recorder?: string | null;
    reference?: string | null;
};

type PurchaseRequest = {
    id: number;
    description?: string | null;
    amount?: number | null;
    status?: string | null;
    requested_at?: string | null;
};

type Discrepancy = {
    id: number;
    description?: string | null;
    amount?: number | null;
    status?: string | null;
    raised_at?: string | null;
};

type FinanceTabProps = {
    clientId: number;
    clientFundsHref?: string;
    finance?: {
        funds?: FundSummary[];
        recent_transactions?: FundTransaction[];
        purchase_requests?: PurchaseRequest[];
        discrepancies?: Discrepancy[];
    };
};

function money(amount: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
}

function dateLabel(value?: string | null) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

export function FinanceTab({
    clientId,
    clientFundsHref,
    finance,
}: FinanceTabProps) {
    const funds = finance?.funds ?? [];
    const transactions = finance?.recent_transactions ?? [];
    const purchaseRequests = finance?.purchase_requests ?? [];
    const discrepancies = finance?.discrepancies ?? [];

    const totalBalance = funds.reduce(
        (sum, fund) => sum + (fund.balance ?? 0),
        0,
    );
    const lowFunds = funds.filter(
        (fund) =>
            fund.low_balance_threshold != null &&
            fund.balance < fund.low_balance_threshold,
    );

    return (
        <div className="space-y-6" data-test="client-finance-tab">
            {/* eslint-disable-next-line no-restricted-syntax -- intro panel without full Card chrome. */}
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Client finance
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Fund balances, ledger activity, and approvals.
                            Detailed ledger lives under{' '}
                            <Link
                                className="text-primary underline-offset-2 hover:underline"
                                href={
                                    clientFundsHref ??
                                    `/operations/client-funds?client=${clientId}`
                                }
                            >
                                Client Funds
                            </Link>
                            .
                        </p>
                    </div>
                    <div className="text-right">
                        <p className="text-xs text-muted-foreground">
                            Total balance
                        </p>
                        <p className="text-xl font-semibold">
                            {money(totalBalance)}
                        </p>
                    </div>
                </div>
                {lowFunds.length > 0 ? (
                    <div className="mt-3 flex items-center gap-2 rounded-md bg-status-warning-bg p-2 text-sm text-status-warning">
                        <AlertTriangle className="h-4 w-4" />
                        <span>
                            {lowFunds.length} fund
                            {lowFunds.length === 1 ? '' : 's'} below low-balance
                            threshold.
                        </span>
                    </div>
                ) : null}
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Wallet className="h-4 w-4 text-primary" />
                        Funds
                        <Badge variant="outline" className="ml-auto">
                            {funds.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {funds.length > 0 ? (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {funds.map((fund) => {
                                const low =
                                    fund.low_balance_threshold != null &&
                                    fund.balance < fund.low_balance_threshold;
                                return (
                                    <div
                                        key={fund.id}
                                        className={cn(
                                            'rounded-lg border bg-card p-4',
                                            low &&
                                                'border-status-warning/40 bg-status-warning-bg/30',
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="font-medium">
                                                    {fund.name}
                                                </p>
                                                {fund.type ? (
                                                    <p className="text-xs text-muted-foreground capitalize">
                                                        {fund.type}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {fund.is_active ? (
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px] text-muted-foreground"
                                                >
                                                    Inactive
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="mt-2 text-2xl font-semibold">
                                            {money(fund.balance)}
                                        </p>
                                        {fund.low_balance_threshold != null ? (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Low at{' '}
                                                {money(
                                                    fund.low_balance_threshold,
                                                )}
                                            </p>
                                        ) : null}
                                        {fund.notes ? (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {fund.notes}
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <EmptyState
                            icon={Wallet}
                            title="No funds linked to this client"
                            description="Funds are created from the Client Funds module. They appear here once linked."
                        />
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Receipt className="h-4 w-4 text-primary" />
                        Recent fund transactions
                        <Badge variant="outline" className="ml-auto">
                            {transactions.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {transactions.length > 0 ? (
                        <ul className="divide-y">
                            {transactions.map((tx) => {
                                const isOutflow =
                                    tx.amount < 0 ||
                                    ['debit', 'spend', 'withdrawal'].includes(
                                        String(tx.type ?? '').toLowerCase(),
                                    );
                                const Icon = isOutflow
                                    ? ArrowDownCircle
                                    : ArrowUpCircle;
                                return (
                                    <li
                                        key={tx.id}
                                        className="flex items-start gap-3 py-3"
                                    >
                                        <Icon
                                            className={cn(
                                                'mt-0.5 h-5 w-5 shrink-0',
                                                isOutflow
                                                    ? 'text-status-critical'
                                                    : 'text-status-success',
                                            )}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {tx.description ??
                                                    tx.category ??
                                                    'Transaction'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {dateLabel(tx.transaction_date)}
                                                {tx.recorder
                                                    ? ` · ${tx.recorder}`
                                                    : ''}
                                                {tx.reference
                                                    ? ` · ref ${tx.reference}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <p
                                            className={cn(
                                                'shrink-0 text-sm font-semibold',
                                                isOutflow
                                                    ? 'text-status-critical'
                                                    : 'text-status-success',
                                            )}
                                        >
                                            {money(Math.abs(tx.amount))}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground italic">
                            No transactions yet.
                        </p>
                    )}
                </CardContent>
            </Card>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ShoppingCart className="h-4 w-4 text-primary" />
                            Purchase requests
                            <Badge variant="outline" className="ml-auto">
                                {purchaseRequests.length}
                            </Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {purchaseRequests.length > 0 ? (
                            <ul className="divide-y">
                                {purchaseRequests.map((req) => (
                                    <li
                                        key={req.id}
                                        className="flex items-start gap-3 py-3 text-sm"
                                    >
                                        <Badge
                                            variant="outline"
                                            className="capitalize"
                                        >
                                            {req.status ?? 'pending'}
                                        </Badge>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {req.description ??
                                                    'Purchase request'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {dateLabel(req.requested_at)}
                                            </p>
                                        </div>
                                        {req.amount != null ? (
                                            <p className="shrink-0 font-semibold">
                                                {money(req.amount)}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState
                                icon={ShoppingCart}
                                title="No purchase requests yet"
                                description="Purchase request workflow is shipping in the next release. Requests for client expenses will surface here for approval."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                            Financial discrepancies
                            <Badge variant="outline" className="ml-auto">
                                {discrepancies.length}
                            </Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {discrepancies.length > 0 ? (
                            <ul className="divide-y">
                                {discrepancies.map((d) => (
                                    <li
                                        key={d.id}
                                        className="flex items-start gap-3 py-3 text-sm"
                                    >
                                        <Badge
                                            variant="outline"
                                            className="capitalize"
                                        >
                                            {d.status ?? 'open'}
                                        </Badge>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {d.description ?? 'Discrepancy'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {dateLabel(d.raised_at)}
                                            </p>
                                        </div>
                                        {d.amount != null ? (
                                            <p className="shrink-0 font-semibold text-status-warning">
                                                {money(Math.abs(d.amount))}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState
                                icon={AlertTriangle}
                                title="No open discrepancies"
                                description="Discrepancy tracking (missing money, reconciliation issues) ships with the dedicated Finance module."
                            />
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="flex justify-end">
                <Button asChild variant="outline">
                    <Link
                        href={
                            clientFundsHref ??
                            `/operations/client-funds?client=${clientId}`
                        }
                    >
                        Open full Client Funds
                    </Link>
                </Button>
            </div>
        </div>
    );
}
