import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Heart, AlertTriangle } from 'lucide-react';

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

interface Props extends PageProps {
    funds: Fund[];
    summary: Summary;
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

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

const statusConfig: Record<string, { label: string; className: string }> = {
    active: { label: 'Active', className: 'border-green-300 text-green-600' },
    fully_spent: { label: 'Fully Spent', className: 'border-amber-300 text-amber-600' },
    expired: { label: 'Expired', className: 'border-red-300 text-red-600' },
    returned: { label: 'Returned', className: 'border-gray-300 text-gray-600' },
};

export default function DonorFundsIndex({ funds, summary }: Props) {
    return (
        <AppLayout>
            <Head title="Donor Funds" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Donor Funds</h1>
                    <Button asChild>
                        <Link href="/finance/donor-funds/create">
                            <Plus className="mr-1 h-4 w-4" />
                            New Fund
                        </Link>
                    </Button>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Funds</p>
                            <p className="text-2xl font-bold">{summary.total_funds}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Received</p>
                            <p className="text-xl font-bold">{formatCurrency(summary.total_received)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Total Spent</p>
                            <p className="text-xl font-bold">{formatCurrency(summary.total_spent)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Available</p>
                            <p className="text-xl font-bold text-green-600">{formatCurrency(summary.total_available)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Restricted</p>
                            <p className="text-xl font-bold">{formatCurrency(summary.restricted_balance)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-sm text-muted-foreground">Expiring Soon</p>
                            <p className={`text-2xl font-bold ${summary.expiring_soon > 0 ? 'text-amber-600' : ''}`}>
                                {summary.expiring_soon}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Funds Table */}
                {funds.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Heart className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No donor funds yet.</p>
                            <p className="text-sm text-muted-foreground">Create your first fund to start tracking donations and grants.</p>
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
                                    {funds.map((fund) => {
                                        const config = statusConfig[fund.status] ?? statusConfig.active;
                                        const utilisation = fund.budget_amount
                                            ? Math.round((fund.total_spent / fund.budget_amount) * 100)
                                            : null;
                                        return (
                                            <TableRow key={fund.id}>
                                                <TableCell className="font-mono text-sm">
                                                    <Link href={`/finance/donor-funds/${fund.id}`} className="text-blue-600 hover:underline">
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
                                                <TableCell className="text-right">{formatCurrency(fund.total_received)}</TableCell>
                                                <TableCell className="text-right">{formatCurrency(fund.total_spent)}</TableCell>
                                                <TableCell className="text-right font-medium text-green-600">
                                                    {formatCurrency(fund.available_balance)}
                                                    {utilisation !== null && (
                                                        <span className="ml-1 text-xs text-muted-foreground">({utilisation}%)</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {fund.is_restricted ? (
                                                        <Badge variant="outline" className="border-amber-300 text-amber-600">
                                                            Restricted
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="border-gray-300 text-gray-500">
                                                            Unrestricted
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {fund.end_date ? (
                                                        <span className={new Date(fund.end_date) < new Date() ? 'text-red-600' : ''}>
                                                            {formatDate(fund.end_date)}
                                                        </span>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={config.className}>
                                                        {config.label}
                                                    </Badge>
                                                    {fund.next_report_due && new Date(fund.next_report_due) <= new Date() && (
                                                        <AlertTriangle className="ml-1 inline h-4 w-4 text-amber-500" title="Report overdue" />
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
