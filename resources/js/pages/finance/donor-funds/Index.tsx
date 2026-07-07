import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import {
    DonorFundDialog,
    formatMoney,
    type DonorFundFundingStream,
    type DonorFundGlAccount,
} from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Plus, Heart, AlertTriangle, HandHeart, Download, Search } from 'lucide-react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';
import { useState } from 'react';

interface Fund {
    id: number;
    fund_code: string;
    fund_name: string;
    donor_name: string | null;
    fund_type: string;
    total_received: number;
    total_spent: number;
    available_balance: number;
    budget_amount: number | null;
    status: string;
    is_restricted: boolean;
    start_date: string | null;
    end_date: string | null;
    next_report_due: string | null;
    gl_account_name: string | null;
    funding_stream_name: string | null;
}

interface Summary {
    total_funds: number;
    total_received: number;
    total_spent: number;
    total_available: number;
    restricted_balance: number;
    unrestricted_balance: number;
    expiring_soon: number;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginatorLink[];
    last_page: number;
}

interface Filters {
    search?: string;
    status?: string;
    restricted?: string;
}

interface Props extends PageProps {
    funds: Paginated<Fund>;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    glAccounts: DonorFundGlAccount[];
    fundingStreams: DonorFundFundingStream[];
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const fundTypeLabels: Record<string, string> = {
    grant: 'Grant',
    donation: 'Donation',
    bequest: 'Bequest',
    trust: 'Trust',
    government: 'Government',
    sponsorship: 'Sponsorship',
};

const statusBadge: Record<string, { label: string; variant: StatusVariant }> = {
    active: { label: 'Active', variant: 'success' },
    fully_spent: { label: 'Fully Spent', variant: 'warning' },
    expired: { label: 'Expired', variant: 'critical' },
    returned: { label: 'Returned', variant: 'neutral' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Donor Funds', href: '/finance/donor-funds' },
];

export default function DonorFundsIndex({ funds, filters, summary, canManage = false, glAccounts = [], fundingStreams = [] }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [restricted, setRestricted] = useState(filters.restricted ?? '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (status && status !== 'all') params.status = status;
        if (restricted && restricted !== 'all') params.restricted = restricted;
        router.get('/finance/donor-funds', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setRestricted('');
        router.get('/finance/donor-funds', {}, { preserveState: true });
    };

    const hasFilters = Boolean(search || (status && status !== 'all') || (restricted && restricted !== 'all'));
    const pieData = [
        { name: 'Restricted', value: summary.restricted_balance },
        { name: 'Unrestricted', value: summary.unrestricted_balance },
    ].filter((d) => d.value > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Donor Funds" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={HandHeart}
                        title="Donor Funds"
                        description="Track donations, grants, and restricted funding"
                        stats={[
                            { label: 'Total funds', value: summary.total_funds },
                            { label: 'Received', value: formatMoney(summary.total_received) },
                            { label: 'Available', value: formatMoney(summary.total_available) },
                            { label: 'Expiring soon', value: summary.expiring_soon },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/finance/donor-funds/export?${new URLSearchParams(Object.entries({ search, status: status !== 'all' ? status : '', restricted: restricted !== 'all' ? restricted : '' }).filter(([, v]) => v)).toString()}`}>
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Fund
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
                {/* Summary Cards + PieChart */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2 grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Total Funds</p>
                                <p className="text-2xl font-bold">{summary.total_funds}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Total Received</p>
                                <p className="text-xl font-bold">{formatMoney(summary.total_received)}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Total Spent</p>
                                <p className="text-xl font-bold">{formatMoney(summary.total_spent)}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Available</p>
                                <p className="text-xl font-bold text-status-success">{formatMoney(summary.total_available)}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Restricted</p>
                                <p className="text-xl font-bold">{formatMoney(summary.restricted_balance)}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">Expiring Soon</p>
                                <p className={`text-2xl font-bold ${summary.expiring_soon > 0 ? 'text-status-warning' : ''}`}>
                                    {summary.expiring_soon}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Restricted vs Unrestricted Pie Chart */}
                    {pieData.length > 0 && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Restricted vs Unrestricted
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ResponsiveContainer width="100%" height={180}>
                                    <PieChart>
                                        <Pie
                                            data={pieData}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={45}
                                            outerRadius={70}
                                            paddingAngle={3}
                                            dataKey="value"
                                            nameKey="name"
                                        >
                                            {pieData.map((_, index) => (
                                                <Cell key={`cell-${index}`} fill={chartColor(index)} />
                                            ))}
                                        </Pie>
                                        <Tooltip
                                            formatter={((value: number) => formatMoney(value)) as any}
                                        />
                                    </PieChart>
                                </ResponsiveContainer>
                                <div className="flex justify-center gap-4 text-xs">
                                    {pieData.map((entry, index) => (
                                        <div key={entry.name} className="flex items-center gap-1.5">
                                            <div
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: chartColor(index) }}
                                            />
                                            <span className="text-muted-foreground">{entry.name}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search fund, code, donor..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                    className="pl-9"
                                />
                            </div>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All statuses</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="fully_spent">Fully spent</SelectItem>
                                    <SelectItem value="expired">Expired</SelectItem>
                                    <SelectItem value="returned">Returned</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={restricted} onValueChange={setRestricted}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All funds" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All funds</SelectItem>
                                    <SelectItem value="restricted">Restricted</SelectItem>
                                    <SelectItem value="unrestricted">Unrestricted</SelectItem>
                                </SelectContent>
                            </Select>
                            <div className="flex gap-2">
                                <Button onClick={applyFilters} variant="secondary" className="shrink-0">
                                    Filter
                                </Button>
                                <Button onClick={clearFilters} variant="ghost" className="shrink-0">
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Funds Table */}
                {funds.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Heart className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">
                                {hasFilters ? 'No funds match your filters.' : 'No donor funds yet.'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {hasFilters
                                    ? 'Try adjusting or clearing the filters.'
                                    : 'Create your first fund to start tracking donations and grants.'}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Code</TableHead>
                                        <TableHead>Fund Name</TableHead>
                                        <TableHead>Donor</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead className="text-right">Received</TableHead>
                                        <TableHead className="text-right">Spent</TableHead>
                                        <TableHead className="text-right">Available</TableHead>
                                        <TableHead>Restricted</TableHead>
                                        <TableHead>End Date</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {funds.data.map((fund) => {
                                        const badge = statusBadge[fund.status] ?? statusBadge.active;
                                        const utilisation = fund.budget_amount
                                            ? Math.round((fund.total_spent / fund.budget_amount) * 100)
                                            : null;
                                        return (
                                            <TableRow key={fund.id}>
                                                <TableCell className="font-mono text-sm">
                                                    <Link href={`/finance/donor-funds/${fund.id}`} className="text-primary hover:underline">
                                                        {fund.fund_code}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    <Link href={`/finance/donor-funds/${fund.id}`} className="hover:underline">
                                                        {fund.fund_name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-sm">{fund.donor_name ?? '-'}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">{fundTypeLabels[fund.fund_type] ?? fund.fund_type}</Badge>
                                                </TableCell>
                                                <TableCell className="text-right">{formatMoney(fund.total_received)}</TableCell>
                                                <TableCell className="text-right">{formatMoney(fund.total_spent)}</TableCell>
                                                <TableCell className="text-right font-medium text-status-success">
                                                    {formatMoney(fund.available_balance)}
                                                    {utilisation !== null && (
                                                        <span className="ml-1 text-xs text-muted-foreground">({utilisation}%)</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {fund.is_restricted ? (
                                                        <Badge variant="outline" className="border-status-warning/30 text-status-warning">
                                                            Restricted
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="border-border text-muted-foreground">
                                                            Unrestricted
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {fund.end_date ? (
                                                        <span className={new Date(fund.end_date) < new Date() ? 'text-destructive' : ''}>
                                                            {formatDate(fund.end_date)}
                                                        </span>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge variant={badge.variant} label={badge.label} />
                                                    {fund.next_report_due && new Date(fund.next_report_due) <= new Date() && (
                                                        <AlertTriangle className="ml-1 inline h-4 w-4 text-status-warning" />
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>

                            {funds.last_page > 1 && (
                                <div className="flex items-center justify-center gap-1 border-t p-4">
                                    {funds.links.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={link.active ? 'default' : 'ghost'}
                                            size="sm"
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            {canManage && (
                <DonorFundDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    glAccounts={glAccounts}
                    fundingStreams={fundingStreams}
                />
            )}
        </AppLayout>
    );
}
