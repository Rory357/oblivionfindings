import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Filter } from 'lucide-react';
import { FormEvent, useState } from 'react';

type LedgerLine = {
    id: number;
    date: string;
    journal_number: string;
    journal_id: number;
    description: string;
    debit: number;
    credit: number;
    running_balance: number;
};

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    sub_type: string | null;
    is_system: boolean;
    is_active: boolean;
    gst_applicable: boolean;
    description: string | null;
    balance: number;
};

type Ledger = {
    opening_balance: number;
    lines: LedgerLine[];
    closing_balance: number;
};

type PageProps = {
    account: Account;
    ledger: Ledger;
    filters: {
        start_date: string;
        end_date: string;
    };
};

const typeLabels: Record<string, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expense',
};

const typeColors: Record<string, string> = {
    asset: 'bg-status-info-bg text-status-info border-status-info/30',
    liability:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    equity: 'bg-primary/10 text-primary border-primary/30',
    revenue:
        'bg-status-success-bg text-status-success border-status-success/30',
    expense:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
};

export default function AccountShow({ account, ledger, filters }: PageProps) {
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Chart of Accounts', href: '/finance/accounts' },
        {
            title: `${account.code} - ${account.name}`,
            href: `/finance/accounts/${account.id}`,
        },
    ];

    function handleFilter(e: FormEvent) {
        e.preventDefault();
        router.get(
            `/finance/accounts/${account.id}`,
            {
                start_date: startDate,
                end_date: endDate,
            },
            { preserveState: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${account.code} - ${account.name}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/accounts"
                        title={`${account.code} - ${account.name}`}
                        description={account.description ?? undefined}
                        actions={
                            <div className="flex items-center gap-3">
                                <Badge
                                    variant="outline"
                                    className={typeColors[account.type]}
                                >
                                    {typeLabels[account.type]}
                                </Badge>
                                {account.is_system && (
                                    <Badge variant="outline">System</Badge>
                                )}
                                {!account.is_active && (
                                    <Badge variant="secondary">Inactive</Badge>
                                )}
                                <div className="text-right">
                                    <p className="text-xs text-muted-foreground">
                                        Current Balance
                                    </p>
                                    <p className="font-mono text-xl font-bold tabular-nums">
                                        {formatMoney(account.balance)}
                                    </p>
                                </div>
                            </div>
                        }
                    />
                }
            >
                {/* Date Filter */}
                <Card>
                    <CardContent className="pt-6">
                        <form
                            onSubmit={handleFilter}
                            className="flex items-end gap-4"
                        >
                            <div className="space-y-1.5">
                                <Label htmlFor="start_date">From</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={startDate}
                                    onChange={(e) =>
                                        setStartDate(e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="end_date">To</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Ledger Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Account Ledger</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Journal #</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Debit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Credit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Balance
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {/* Opening Balance Row */}
                                <TableRow className="bg-muted/30 font-medium">
                                    <TableCell colSpan={5}>
                                        Opening Balance
                                    </TableCell>
                                    <TableCell className="text-right font-mono tabular-nums">
                                        {formatMoney(ledger.opening_balance)}
                                    </TableCell>
                                </TableRow>

                                {ledger.lines.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No journal entries found for this
                                            period.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    ledger.lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="text-sm">
                                                {line.date}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/journals/${line.journal_id}`}
                                                    className="font-mono text-sm text-primary hover:underline"
                                                >
                                                    {line.journal_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate text-sm">
                                                {line.description}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {line.debit > 0
                                                    ? formatMoney(line.debit)
                                                    : ''}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {line.credit > 0
                                                    ? formatMoney(line.credit)
                                                    : ''}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm font-medium tabular-nums">
                                                {formatMoney(
                                                    line.running_balance,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}

                                {/* Closing Balance Row */}
                                <TableRow className="bg-muted/30 font-semibold">
                                    <TableCell colSpan={5}>
                                        Closing Balance
                                    </TableCell>
                                    <TableCell className="text-right font-mono tabular-nums">
                                        {formatMoney(ledger.closing_balance)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
