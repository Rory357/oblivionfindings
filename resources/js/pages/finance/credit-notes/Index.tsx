import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import {
    CreditNoteDialog,
    formatMoney,
    PayablesTabsFooter,
    type CreditNoteAccountOption,
    type CreditNoteClientOption,
    type CreditNoteVendorOption,
} from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { StatusBadge } from '@/components/ui/status-badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FileText, Plus, FileMinus, Download, Search } from 'lucide-react';
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
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Props extends PageProps {
    creditNotes: PaginatedCreditNotes;
    filters: Filters;
    canManage: boolean;
    vendors: CreditNoteVendorOption[];
    clients: CreditNoteClientOption[];
    accounts: CreditNoteAccountOption[];
}

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const typeConfig: Record<string, { label: string; className: string }> = {
    payable: { label: 'AP', className: 'bg-primary/10 text-primary dark:bg-primary dark:text-primary/70' },
    receivable: { label: 'AR', className: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Credit Notes', href: '/finance/credit-notes' },
];

export default function CreditNotesIndex({ auth, creditNotes, filters, canManage = false, vendors = [], clients = [], accounts = [] }: Props) {
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [search, setSearch] = useState(filters.search ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [createOpen, setCreateOpen] = useState(false);

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (type && type !== 'all') params.type = type;
        if (status && status !== 'all') params.status = status;
        if (search) params.search = search;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;

        router.get('/finance/credit-notes', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setType('');
        setStatus('');
        setSearch('');
        setDateFrom('');
        setDateTo('');
        router.get('/finance/credit-notes', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        search || (type && type !== 'all') || (status && status !== 'all') || dateFrom || dateTo,
    );

    const payableCount = creditNotes.data.filter((cn) => cn.type === 'payable').length;
    const receivableCount = creditNotes.data.filter((cn) => cn.type === 'receivable').length;

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Credit Notes" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={FileMinus}
                        title="Credit Notes"
                        description="Manage credit notes for accounts payable and receivable"
                        stats={[
                            { label: 'Total (this page)', value: creditNotes.data.length },
                            { label: 'AP', value: payableCount },
                            { label: 'AR', value: receivableCount },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a href={`/finance/credit-notes/export?${new URLSearchParams(Object.entries({ type: type !== 'all' ? type : '', status: status !== 'all' ? status : '', search, date_from: dateFrom, date_to: dateTo }).filter(([, v]) => v)).toString()}`}>
                                        <Download className="w-4 h-4 mr-1.5" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                                        <Plus className="w-4 h-4 mr-1.5" />
                                        New Credit Note
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<PayablesTabsFooter active="credit-notes" />}
                    />
                }
            >
                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-6">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search CN #, party..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                    className="pl-9"
                                />
                            </div>
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="payable">Accounts Payable</SelectItem>
                                    <SelectItem value="receivable">Accounts Receivable</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
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
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                placeholder="From"
                            />
                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                placeholder="To"
                            />
                            <div className="flex gap-2">
                                <Button onClick={applyFilters} variant="secondary" className="shrink-0">Filter</Button>
                                <Button onClick={clearFilters} variant="ghost" className="shrink-0">Clear</Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    {creditNotes.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 px-4">
                            <div className="rounded-full bg-muted p-4 mb-4">
                                <FileText className="h-8 w-8 text-muted-foreground" />
                            </div>
                            <h3 className="text-lg font-semibold text-foreground mb-1">
                                {hasFilters ? 'No credit notes match your filters' : 'No credit notes found'}
                            </h3>
                            <p className="text-sm text-muted-foreground mb-4 text-center max-w-sm">
                                {hasFilters
                                    ? 'Try adjusting or clearing the filters above.'
                                    : 'Credit notes are used to adjust invoices or bills. Create one to get started.'}
                            </p>
                            {canManage && !hasFilters && (
                                <Button onClick={() => setCreateOpen(true)}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    New Credit Note
                                </Button>
                            )}
                        </div>
                    ) : (
                        <>
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
                                    {creditNotes.data.map((creditNote) => (
                                        <TableRow
                                            key={creditNote.id}
                                            className="cursor-pointer hover:bg-muted/50"
                                            onClick={() => router.get(`/finance/credit-notes/${creditNote.id}`)}
                                        >
                                            <TableCell className="font-medium">
                                                <Link href={`/finance/credit-notes/${creditNote.id}`} className="text-primary hover:underline">
                                                    {creditNote.credit_note_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={typeConfig[creditNote.type]?.className ?? 'bg-muted text-foreground'}>
                                                    {typeConfig[creditNote.type]?.label ?? creditNote.type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{creditNote.vendor?.name ?? '-'}</TableCell>
                                            <TableCell>{formatDate(creditNote.credit_date)}</TableCell>
                                            <TableCell className="text-right font-medium">{formatMoney(creditNote.total_amount)}</TableCell>
                                            <TableCell>
                                                <StatusBadge status={creditNote.status} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
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
                        </>
                    )}
                </Card>
            </PageLayout>

            {canManage && (
                <CreditNoteDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    vendors={vendors}
                    clients={clients}
                    accounts={accounts}
                />
            )}
        </AppLayout>
    );
}
