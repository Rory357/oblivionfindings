import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, DollarSign, Eye, Pencil, Plus, Search, Wallet } from 'lucide-react';

const ANY = '__ANY__';

const nzd = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' });

type ClientFund = {
    id: number;
    name: string;
    fund_type: string;
    balance: number;
    low_balance_threshold: number | null;
    transaction_count: number;
    client: { id: number; first_name: string; last_name: string } | null;
};

type Props = {
    funds: {
        data: ClientFund[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        fund_type?: string;
    };
    stats: {
        total: number;
        total_balance: number;
        low_balance_alerts: number;
    };
};

const FUND_TYPES: Record<string, string> = {
    trust: 'Trust',
    petty_cash: 'Petty Cash',
    personal: 'Personal',
    activity: 'Activity',
};

export default function ClientFundsIndex({ funds = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/client-funds', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title={`${clientSingular} Funds`} />
            <PageHeader
                title={`${clientSingular} Funds`}
                description={`Manage ${clientSingular.toLowerCase()} trust funds, petty cash, and personal funds.`}
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard label="Total Funds" value={stats?.total ?? 0} icon={Wallet} color="indigo" />
                    <OpsStatCard label="Total Balance" value={nzd.format(stats?.total_balance ?? 0)} icon={DollarSign} color="emerald" />
                    <OpsStatCard label="Low Balance Alerts" value={stats?.low_balance_alerts ?? 0} icon={AlertTriangle} color={stats?.low_balance_alerts > 0 ? 'amber' : 'slate'} />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder={`Search ${clientSingular.toLowerCase()} funds...`}
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.fund_type ?? ANY} onValueChange={(v) => updateFilters('fund_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[140px] text-xs">
                            <SelectValue placeholder="Fund Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(FUND_TYPES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/client-funds/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Fund
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(funds?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Wallet className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No {clientSingular} Funds Found</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first {clientSingular.toLowerCase()} fund to get started.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/client-funds/create">Create Fund</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(funds?.data ?? []).map((fund) => {
                        const isLow = fund.low_balance_threshold !== null && fund.balance <= fund.low_balance_threshold;
                        return (
                            <Card key={fund.id} className="transition-all hover:border-border hover:shadow-sm">
                                <CardContent className="flex items-center gap-4 p-4">
                                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${isLow ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'}`}>
                                        {isLow ? <AlertTriangle className="h-5 w-5" /> : <Wallet className="h-5 w-5" />}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <Link href={`/operations/client-funds/${fund.id}`} className="text-sm font-semibold hover:underline">
                                                {fund.name}
                                            </Link>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                                {FUND_TYPES[fund.fund_type] ?? fund.fund_type}
                                            </Badge>
                                            {isLow && (
                                                <Badge variant="destructive" className="h-4 px-1.5 text-[9px]">
                                                    Low Balance
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                            {fund.client && (
                                                <span>{fund.client.first_name} {fund.client.last_name}</span>
                                            )}
                                            <span className={`font-semibold tabular-nums ${isLow ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'}`}>
                                                {nzd.format(fund.balance)}
                                            </span>
                                            <span>{fund.transaction_count} transactions</span>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/client-funds/${fund.id}`}>
                                                <Eye className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/client-funds/${fund.id}/edit`}>
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(funds?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(funds?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
