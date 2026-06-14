import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { PayablesTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FileText, Plus, FileMinus } from 'lucide-react';
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
    draft: { label: 'Draft', className: 'bg-muted text-foreground dark:bg-muted dark:text-muted-foreground' },
    approved: { label: 'Approved', className: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success' },
    applied: { label: 'Applied', className: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
    cancelled: { label: 'Cancelled', className: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical' },
};

const typeConfig: Record<string, { label: string; className: string }> = {
    payable: { label: 'AP', className: 'bg-primary/10 text-primary dark:bg-primary dark:text-primary/70' },
    receivable: { label: 'AR', className: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Credit Notes', href: '/finance/credit-notes' },
];

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
                            <Button asChild size="sm">
                                <Link href="/finance/credit-notes/create">
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    New Credit Note
                                </Link>
                            </Button>
                        }
                        footer={<PayablesTabsFooter active="credit-notes" />}
                    />
                }
            >
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
                    {creditNotes.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 px-4">
                            <div className="rounded-full bg-muted p-4 mb-4">
                                <FileText className="h-8 w-8 text-muted-foreground" />
                            </div>
                            <h3 className="text-lg font-semibold text-foreground mb-1">No credit notes found</h3>
                            <p className="text-sm text-muted-foreground mb-4 text-center max-w-sm">
                                Credit notes are used to adjust invoices or bills. Create one to get started.
                            </p>
                            <Button asChild>
                                <Link href="/finance/credit-notes/create">
                                    <Plus className="w-4 h-4 mr-2" />
                                    New Credit Note
                                </Link>
                            </Button>
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
                                            <TableCell className="text-right font-medium">{formatCurrency(creditNote.total_amount)}</TableCell>
                                            <TableCell>
                                                <Badge className={statusConfig[creditNote.status]?.className ?? 'bg-muted text-foreground'}>
                                                    {statusConfig[creditNote.status]?.label ?? creditNote.status}
                                                </Badge>
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
        </AppLayout>
    );
}
