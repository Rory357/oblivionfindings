import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ArrowLeftRight, CheckCircle2, XCircle, Zap, Settings } from 'lucide-react';
import { useCallback } from 'react';

interface BankTransaction {
    id: number;
    transaction_date: string;
    description: string;
    reference: string | null;
    amount: number;
    bank_account_name: string | null;
}

interface Matchable {
    id: number;
    type: string;
    number: string;
    amount_due: number;
    total_amount: number;
    vendor_name: string | null;
    due_date: string | null;
}

interface PaymentMatch {
    id: number;
    bank_transaction: BankTransaction | null;
    matchable: Matchable | null;
    confidence_score: number;
    match_reasons: string[];
    status: string;
    confirmed_by_name: string | null;
    confirmed_at: string | null;
    created_at: string;
}

interface PaginatedMatches {
    data: PaymentMatch[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Filters {
    status: string;
    min_confidence: string;
}

interface Props {
    matches: PaginatedMatches;
    filters: Filters;
}

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

function confidenceBadge(score: number) {
    if (score >= 80) {
        return <Badge className="bg-green-100 text-green-800">{score}%</Badge>;
    }
    if (score >= 50) {
        return <Badge className="bg-yellow-100 text-yellow-800">{score}%</Badge>;
    }
    return <Badge className="bg-red-100 text-red-800">{score}%</Badge>;
}

function statusBadge(status: string) {
    switch (status) {
        case 'confirmed':
            return <Badge className="bg-green-100 text-green-800">Confirmed</Badge>;
        case 'auto_confirmed':
            return <Badge className="bg-blue-100 text-blue-800">Auto-confirmed</Badge>;
        case 'rejected':
            return <Badge className="bg-red-100 text-red-800">Rejected</Badge>;
        case 'suggested':
            return <Badge className="bg-amber-100 text-amber-800">Suggested</Badge>;
        default:
            return <Badge variant="secondary">{status}</Badge>;
    }
}

export default function PaymentMatchingIndex({ matches, filters }: Props) {
    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/payment-matching',
                { ...filters, ...newFilters, page: 1 },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    function handleMatchAll() {
        router.post('/finance/payment-matching/match-all', {}, {
            preserveState: false,
        });
    }

    function handleConfirm(matchId: number) {
        router.post(`/finance/payment-matching/${matchId}/confirm`, {}, {
            preserveScroll: true,
        });
    }

    function handleReject(matchId: number) {
        router.post(`/finance/payment-matching/${matchId}/reject`, {}, {
            preserveScroll: true,
        });
    }

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Payment Matching', href: '/finance/payment-matching' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment Matching" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Payment Matching</h1>
                        <p className="text-gray-500 mt-1">
                            Automatically match bank transactions to bills and invoices
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/finance/match-rules">
                                <Settings className="w-4 h-4 mr-2" />
                                Match Rules
                            </Link>
                        </Button>
                        <Button onClick={handleMatchAll}>
                            <Zap className="w-4 h-4 mr-2" />
                            Run Auto-Match
                        </Button>
                    </div>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="flex flex-col sm:flex-row gap-4">
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ status: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[200px]">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="suggested">Suggested</SelectItem>
                                    <SelectItem value="all_confirmed">Confirmed</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.min_confidence || 'all'}
                                onValueChange={(value) =>
                                    applyFilters({ min_confidence: value === 'all' ? '' : value })
                                }
                            >
                                <SelectTrigger className="w-[200px]">
                                    <SelectValue placeholder="Min Confidence" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Any Confidence</SelectItem>
                                    <SelectItem value="80">80%+</SelectItem>
                                    <SelectItem value="60">60%+</SelectItem>
                                    <SelectItem value="40">40%+</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {matches.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <ArrowLeftRight className="h-12 w-12 text-gray-300 mb-4" />
                                <h3 className="text-lg font-medium text-gray-900 mb-1">No matches found</h3>
                                <p className="text-gray-500 mb-4">
                                    Run auto-match to find matches between bank transactions and bills.
                                </p>
                                <Button onClick={handleMatchAll}>
                                    <Zap className="w-4 h-4 mr-2" />
                                    Run Auto-Match
                                </Button>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Transaction</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Matched To</TableHead>
                                        <TableHead className="text-right">Amount Due</TableHead>
                                        <TableHead>Confidence</TableHead>
                                        <TableHead>Reasons</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {matches.data.map((match) => (
                                        <TableRow key={match.id}>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    <p className="font-medium text-sm">
                                                        {match.bank_transaction?.description || '-'}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {match.bank_transaction?.transaction_date}
                                                        {match.bank_transaction?.reference && (
                                                            <> &middot; Ref: {match.bank_transaction.reference}</>
                                                        )}
                                                    </p>
                                                    {match.bank_transaction?.bank_account_name && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {match.bank_transaction.bank_account_name}
                                                        </p>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {match.bank_transaction ? formatNZD(match.bank_transaction.amount) : '-'}
                                            </TableCell>
                                            <TableCell>
                                                {match.matchable ? (
                                                    <div className="space-y-0.5">
                                                        <p className="font-medium text-sm">
                                                            {match.matchable.type} #{match.matchable.number}
                                                        </p>
                                                        {match.matchable.vendor_name && (
                                                            <p className="text-xs text-muted-foreground">
                                                                {match.matchable.vendor_name}
                                                            </p>
                                                        )}
                                                        {match.matchable.due_date && (
                                                            <p className="text-xs text-muted-foreground">
                                                                Due: {match.matchable.due_date}
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {match.matchable ? formatNZD(match.matchable.amount_due) : '-'}
                                            </TableCell>
                                            <TableCell>
                                                {confidenceBadge(match.confidence_score)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-0.5">
                                                    {match.match_reasons.map((reason, i) => (
                                                        <p key={i} className="text-xs text-muted-foreground">
                                                            {reason}
                                                        </p>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {statusBadge(match.status)}
                                                {match.confirmed_by_name && (
                                                    <p className="text-xs text-muted-foreground mt-0.5">
                                                        by {match.confirmed_by_name}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {match.status === 'suggested' && (
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-green-600 hover:text-green-700"
                                                            onClick={() => handleConfirm(match.id)}
                                                        >
                                                            <CheckCircle2 className="w-4 h-4 mr-1" />
                                                            Confirm
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-red-600 hover:text-red-700"
                                                            onClick={() => handleReject(match.id)}
                                                        >
                                                            <XCircle className="w-4 h-4 mr-1" />
                                                            Reject
                                                        </Button>
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {matches.last_page > 1 && (
                    <div className="flex items-center justify-between mt-4">
                        <p className="text-sm text-gray-500">
                            Showing {(matches.current_page - 1) * matches.per_page + 1} to{' '}
                            {Math.min(matches.current_page * matches.per_page, matches.total)} of{' '}
                            {matches.total} matches
                        </p>
                        <div className="flex gap-1">
                            {matches.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
