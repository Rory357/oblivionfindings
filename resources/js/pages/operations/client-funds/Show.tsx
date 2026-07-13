import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowDownCircle, ArrowUpCircle, Wallet } from 'lucide-react';

const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

function newIdempotencyKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
        /[xy]/g,
        (character) => {
            const random = Math.floor(Math.random() * 16);
            const value = character === 'x' ? random : (random & 0x3) | 0x8;

            return value.toString(16);
        },
    );
}

type FundTransaction = {
    id: number;
    transaction_type: 'credit' | 'debit' | string;
    amount: number | string;
    running_balance: number | string;
    description: string;
    reference: string | null;
    transaction_date: string | null;
};

type ClientFund = {
    id: number;
    fund_name?: string;
    name?: string;
    fund_type: string;
    balance: number | string;
    low_balance_threshold: number | string | null;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    transactions: FundTransaction[];
};

type Props = {
    fund: ClientFund;
};

function money(value: number | string | null | undefined): string {
    return nzd.format(Number(value ?? 0));
}

function formatDate(value: string | null): string {
    if (!value) return '-';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function ClientFundShow({ fund }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        type: 'debit',
        amount: '',
        description: '',
        reference: '',
        idempotency_key: newIdempotencyKey(),
    });
    const fundName = fund.fund_name ?? fund.name ?? 'Client fund';

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        post(`/operations/client-funds/${fund.id}/transactions`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('amount', 'description', 'reference');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <AppLayout>
            <Head title={fundName} />
            <PageHero
                variant="compact"
                title={fundName}
                description="Review fund balance and record transactions."
                backHref="/operations/client-funds"
            />
            <PageShell>
                <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
                    <div className="space-y-4">
                        <Card>
                            <CardContent className="flex flex-wrap items-center gap-4 p-5">
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <Wallet className="h-6 w-6" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold">
                                            {fundName}
                                        </h2>
                                        <Badge
                                            variant="outline"
                                            className="capitalize"
                                        >
                                            {fund.fund_type ?? 'general'}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {fund.client
                                            ? `${fund.client.first_name} ${fund.client.last_name}`
                                            : 'No client assigned'}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Current balance
                                    </p>
                                    <p className="text-2xl font-semibold tabular-nums">
                                        {money(fund.balance)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Transactions
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {(fund.transactions ?? []).length === 0 && (
                                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                        No transactions have been recorded for
                                        this fund yet.
                                    </div>
                                )}
                                {(fund.transactions ?? []).map(
                                    (transaction) => {
                                        const isCredit =
                                            transaction.transaction_type ===
                                            'credit';
                                        const Icon = isCredit
                                            ? ArrowUpCircle
                                            : ArrowDownCircle;
                                        return (
                                            <div
                                                key={transaction.id}
                                                className="flex items-center gap-3 rounded-lg border p-3"
                                            >
                                                <Icon
                                                    className={
                                                        isCredit
                                                            ? 'h-5 w-5 text-status-success'
                                                            : 'h-5 w-5 text-status-warning'
                                                    }
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {
                                                            transaction.description
                                                        }
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatDate(
                                                            transaction.transaction_date,
                                                        )}
                                                        {transaction.reference
                                                            ? ` - ${transaction.reference}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <div className="text-right text-sm tabular-nums">
                                                    <p
                                                        className={
                                                            isCredit
                                                                ? 'font-semibold text-status-success'
                                                                : 'font-semibold text-status-warning'
                                                        }
                                                    >
                                                        {isCredit ? '+' : '-'}
                                                        {money(
                                                            transaction.amount,
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {money(
                                                            transaction.running_balance,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Record Transaction
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="type">Type</Label>
                                    <select
                                        id="type"
                                        value={data.type}
                                        onChange={(event) =>
                                            setData('type', event.target.value)
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm"
                                    >
                                        <option value="credit">Credit</option>
                                        <option value="debit">Debit</option>
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="amount">Amount *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={data.amount}
                                        onChange={(event) =>
                                            setData(
                                                'amount',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {errors.amount && (
                                        <p className="text-xs text-destructive">
                                            {errors.amount}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="description">
                                        Description *
                                    </Label>
                                    <Textarea
                                        id="description"
                                        rows={3}
                                        value={data.description}
                                        onChange={(event) =>
                                            setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {errors.description && (
                                        <p className="text-xs text-destructive">
                                            {errors.description}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="reference">Reference</Label>
                                    <Input
                                        id="reference"
                                        value={data.reference}
                                        onChange={(event) =>
                                            setData(
                                                'reference',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                {errors.idempotency_key && (
                                    <p className="text-xs text-destructive">
                                        {errors.idempotency_key}
                                    </p>
                                )}
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full"
                                >
                                    Record Transaction
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
