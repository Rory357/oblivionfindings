import { OverviewTabsFooter } from '@/components/finance/overview-hub';
import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { CalendarClock, Coins, Landmark, Wallet } from 'lucide-react';

type BankAccount = {
    id: number;
    name: string;
    bank_name: string | null;
    account_type: string | null;
    current_balance: number;
    is_primary: boolean;
};

type PettyFund = { id: number; name: string; current_balance: number };

type Obligation = {
    id: string;
    source: string;
    title: string;
    start: string;
    status: string;
    amount: number | null;
    direction: 'inflow' | 'outflow' | null;
    ref: string | null;
    counterparty: string | null;
    link: string | null;
};

type Props = {
    accounts: BankAccount[];
    pettyCash: PettyFund[];
    totals: {
        bank: number;
        petty_cash: number;
        cash_on_hand: number;
        expected_in_30d: number;
        expected_out_30d: number;
        projected_30d: number;
    };
    obligations: Obligation[];
    asOf: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Cash position' },
];

const dateLabel = (iso: string) =>
    new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

export default function CashPosition({ accounts, pettyCash, totals, obligations }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash Position" />

            <PageLayout
                width="wide"
                hero={
                    <PageHero
                        category="finance"
                        icon={Wallet}
                        title="Cash Position"
                        description={`Live balances across ${accounts.length} bank ${accounts.length === 1 ? 'account' : 'accounts'} and ${pettyCash.length} petty-cash ${pettyCash.length === 1 ? 'fund' : 'funds'}, with dated obligations for the next 30 days.`}
                        stats={[
                            { label: 'Cash on hand', value: formatMoney(totals.cash_on_hand) },
                            { label: 'Expected in · 30d', value: formatMoney(totals.expected_in_30d) },
                            { label: 'Expected out · 30d', value: formatMoney(totals.expected_out_30d) },
                            { label: 'Projected · 30d', value: formatMoney(totals.projected_30d) },
                        ]}
                        footer={<OverviewTabsFooter active="cash-position" />}
                    />
                }
            >
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Landmark className="h-4 w-4 text-muted-foreground" />
                                Bank accounts
                                <span className="ml-auto text-sm font-semibold tabular-nums">{formatMoney(totals.bank)}</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {accounts.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Landmark}
                                    heading="No active bank accounts"
                                    description="Add a bank account under Banking to see live balances here."
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Account</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead className="text-right">Balance</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {accounts.map((account) => (
                                            <TableRow key={account.id}>
                                                <TableCell>
                                                    <Link href={`/finance/bank-accounts/${account.id}`} className="font-medium text-primary hover:underline">
                                                        {account.name}
                                                    </Link>
                                                    <div className="text-xs text-muted-foreground">
                                                        {account.bank_name ?? '—'}
                                                        {account.is_primary && (
                                                            <StatusBadge variant="info" size="sm" className="ml-2">
                                                                Primary
                                                            </StatusBadge>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">{account.account_type ?? '—'}</TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">{formatMoney(account.current_balance)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Coins className="h-4 w-4 text-muted-foreground" />
                                Petty cash
                                <span className="ml-auto text-sm font-semibold tabular-nums">{formatMoney(totals.petty_cash)}</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {pettyCash.length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Coins}
                                    heading="No petty-cash funds"
                                    description="Petty-cash floats appear here once a fund is created under Banking."
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Fund</TableHead>
                                            <TableHead className="text-right">Float balance</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {pettyCash.map((fund) => (
                                            <TableRow key={fund.id}>
                                                <TableCell>
                                                    <Link href={`/finance/petty-cash/${fund.id}`} className="font-medium text-primary hover:underline">
                                                        {fund.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">{formatMoney(fund.current_balance)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CalendarClock className="h-4 w-4 text-muted-foreground" />
                            Next 30 days — dated obligations
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {obligations.length === 0 ? (
                            <EmptyState
                                icon={CalendarClock}
                                heading="Nothing due in the next 30 days"
                                description="Invoice, bill, payment-run, payroll and GST due dates will appear here."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Due</TableHead>
                                        <TableHead>Obligation</TableHead>
                                        <TableHead>Counterparty</TableHead>
                                        <TableHead>Direction</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {obligations.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="whitespace-nowrap text-sm">{dateLabel(item.start)}</TableCell>
                                            <TableCell>
                                                {item.link ? (
                                                    <Link href={item.link} className="font-medium text-primary hover:underline">
                                                        {item.title}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium">{item.title}</span>
                                                )}
                                                {item.ref && <div className="text-xs text-muted-foreground">{item.ref}</div>}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{item.counterparty ?? '—'}</TableCell>
                                            <TableCell>
                                                {item.direction ? (
                                                    <StatusBadge
                                                        variant={item.direction === 'inflow' ? 'success' : 'warning'}
                                                        size="sm"
                                                        label={item.direction === 'inflow' ? 'Money in' : 'Money out'}
                                                    />
                                                ) : (
                                                    <StatusBadge variant="neutral" size="sm" label={item.status} />
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {item.amount != null ? formatMoney(item.amount) : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
