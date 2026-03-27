import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface CreditNote {
    id: number;
    credit_note_number: string;
    type: string;
    vendor: { id: number; name: string } | null;
    credit_date: string;
    total_amount: string;
    status: string;
}

interface PaginatedCreditNotes {
    data: CreditNote[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Filters {
    type?: string;
    status?: string;
}

interface Props extends PageProps {
    creditNotes: PaginatedCreditNotes;
    filters: Filters;
}

const formatCurrency = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-800' },
    approved: { label: 'Approved', className: 'bg-green-100 text-green-800' },
    applied: { label: 'Applied', className: 'bg-blue-100 text-blue-800' },
    cancelled: { label: 'Cancelled', className: 'bg-red-100 text-red-800' },
};

const typeConfig: Record<string, { label: string; className: string }> = {
    payable: { label: 'AP', className: 'bg-purple-100 text-purple-800' },
    receivable: { label: 'AR', className: 'bg-teal-100 text-teal-800' },
};

export default function CreditNotesIndex({ auth, creditNotes, filters }: Props) {
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (type) params.type = type;
        if (status) params.status = status;

        router.get('/finance/credit-notes', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setType('');
        setStatus('');
        router.get('/finance/credit-notes', {}, { preserveState: true });
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Credit Notes', href: '/finance/credit-notes' },
            ]}
        >
            <Head title="Credit Notes" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Credit Notes</h1>
                        <p className="text-gray-500 mt-1">Manage credit notes for accounts payable and receivable</p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/credit-notes/create">
                            <Plus className="w-4 h-4 mr-2" />
                            New Credit Note
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="flex items-center gap-4">
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="payable">Accounts Payable</SelectItem>
                                    <SelectItem value="receivable">Accounts Receivable</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="applied">Applied</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button onClick={applyFilters} variant="secondary">Filter</Button>
                            <Button onClick={clearFilters} variant="ghost">Clear</Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>CN Number</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Vendor / Client</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {creditNotes.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-gray-500 py-8">
                                        No credit notes found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                creditNotes.data.map((cn) => (
                                    <TableRow
                                        key={cn.id}
                                        className="cursor-pointer hover:bg-gray-50"
                                        onClick={() => router.get(`/finance/credit-notes/${cn.id}`)}
                                    >
                                        <TableCell className="font-medium">
                                            <Link href={`/finance/credit-notes/${cn.id}`} className="text-blue-600 hover:underline">
                                                {cn.credit_note_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={typeConfig[cn.type]?.className ?? 'bg-gray-100 text-gray-800'}>
                                                {typeConfig[cn.type]?.label ?? cn.type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{cn.vendor?.name ?? '-'}</TableCell>
                                        <TableCell>{formatDate(cn.credit_date)}</TableCell>
                                        <TableCell className="text-right font-medium">{formatCurrency(cn.total_amount)}</TableCell>
                                        <TableCell>
                                            <Badge className={statusConfig[cn.status]?.className ?? 'bg-gray-100 text-gray-800'}>
                                                {statusConfig[cn.status]?.label ?? cn.status}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {creditNotes.last_page > 1 && (
                        <div className="flex items-center justify-center gap-1 p-4 border-t">
                            {creditNotes.links.map((link, i) => (
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
                </Card>
            </div>
        </AppLayout>
    );
}
