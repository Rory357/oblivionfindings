import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { formatMoney, ReceivablesTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ArrowLeft, FileText, Printer } from 'lucide-react';
import { useRef } from 'react';

type ClientOption = {
    id: number;
    name: string;
    email: string | null;
};

type StatementInvoice = {
    invoice_number: string;
    issue_date: string;
    due_date: string;
    total: number;
    amount_paid: number;
    amount_due: number;
};

type Statement = {
    client: {
        id: number;
        name: string;
        email: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        suburb: string | null;
        city: string | null;
        postcode: string | null;
    };
    invoices: StatementInvoice[];
    total_outstanding: number;
    as_of_date: string;
};

type Filters = {
    client_id: number | null;
    as_of_date: string;
};

type PageProps = {
    clients: ClientOption[];
    statement: Statement | null;
    filters: Filters;
};

function ClientAddress({ client }: { client: Statement['client'] }) {
    const parts = [
        client.address_line_1,
        client.address_line_2,
        [client.suburb, client.city, client.postcode].filter(Boolean).join(', '),
    ].filter(Boolean);

    if (parts.length === 0) return null;

    return (
        <div className="text-sm text-muted-foreground">
            {parts.map((part, i) => (
                <div key={i}>{part}</div>
            ))}
        </div>
    );
}

export default function Statements({ clients, statement, filters }: PageProps) {
    const statementRef = useRef<HTMLDivElement>(null);

    function handleClientChange(clientId: string) {
        const params: Record<string, string> = {
            as_of_date: filters.as_of_date,
        };
        if (clientId !== 'none') {
            params.client_id = clientId;
        }
        router.get('/finance/receivables/statements', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function handleDateChange(date: string) {
        const params: Record<string, string> = { as_of_date: date };
        if (filters.client_id) {
            params.client_id = String(filters.client_id);
        }
        router.get('/finance/receivables/statements', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function handlePrint() {
        window.print();
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Accounts Receivable', href: '/finance/receivables' },
                { title: 'Statements', href: '/finance/receivables/statements' },
            ]}
        >
            <Head title="Client Statements" />
            <PageLayout
                hero={
                    <div className="print:hidden">
                        <PageHero category="finance"
                            icon={FileText}
                            title="Client Statements"
                            description="Generate and view outstanding invoice statements by client."
                            stats={
                                statement
                                    ? [
                                          { label: 'Client', value: statement.client.name },
                                          { label: 'Invoices', value: statement.invoices.length },
                                          { label: 'Outstanding', value: formatMoney(statement.total_outstanding) },
                                      ]
                                    : undefined
                            }
                            actions={
                                <Link href="/finance/receivables">
                                    <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <ArrowLeft className="mr-2 h-4 w-4" />
                                        Back to Receivables
                                    </Button>
                                </Link>
                            }
                            footer={<ReceivablesTabsFooter active="statements" />}
                        />
                    </div>
                }
            >
                {/* Filters */}
                <Card className="print:hidden">
                    <CardHeader>
                        <CardTitle className="text-base">Select Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Client</Label>
                                <Select
                                    value={filters.client_id ? String(filters.client_id) : 'none'}
                                    onValueChange={handleClientChange}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Select a client...</SelectItem>
                                        {clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>As of Date</Label>
                                <Input
                                    type="date"
                                    value={filters.as_of_date}
                                    onChange={(e) => handleDateChange(e.target.value)}
                                />
                            </div>
                            <div className="flex items-end">
                                {statement && (
                                    <Button variant="outline" onClick={handlePrint}>
                                        <Printer className="mr-2 h-4 w-4" />
                                        Print / Download
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Statement Display */}
                {!filters.client_id && (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <p className="text-muted-foreground">
                                Select a client above to generate their statement.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {statement && (
                    <div ref={statementRef}>
                        <Card>
                            <CardHeader>
                                <div className="flex items-start justify-between">
                                    <div>
                                        <CardTitle className="text-lg">
                                            Statement of Account
                                        </CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            As at {statement.as_of_date}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-lg font-bold">
                                            {statement.client.name}
                                        </p>
                                        <ClientAddress client={statement.client} />
                                        {statement.client.email && (
                                            <p className="text-sm text-muted-foreground">
                                                {statement.client.email}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {statement.invoices.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No outstanding invoices as at {statement.as_of_date}.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Invoice #</TableHead>
                                                <TableHead>Issue Date</TableHead>
                                                <TableHead>Due Date</TableHead>
                                                <TableHead className="text-right">
                                                    Invoice Total
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Amount Paid
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Amount Due
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {statement.invoices.map((inv) => (
                                                <TableRow key={inv.invoice_number}>
                                                    <TableCell className="font-medium">
                                                        {inv.invoice_number}
                                                    </TableCell>
                                                    <TableCell>{inv.issue_date}</TableCell>
                                                    <TableCell>{inv.due_date}</TableCell>
                                                    <TableCell className="text-right">
                                                        {formatMoney(inv.total)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatMoney(inv.amount_paid)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium">
                                                        {formatMoney(inv.amount_due)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                        <TableFooter>
                                            <TableRow className="font-bold">
                                                <TableCell colSpan={5} className="text-right">
                                                    Total Outstanding
                                                </TableCell>
                                                <TableCell className="text-right text-lg">
                                                    {formatMoney(statement.total_outstanding)}
                                                </TableCell>
                                            </TableRow>
                                        </TableFooter>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
