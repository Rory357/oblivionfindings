import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface TaxRate {
    id: number;
    name: string;
    code: string;
    rate: string;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_id: number;
    total_amount: string;
    vendor?: { id: number; name: string } | null;
}

interface Client {
    id: number;
    first_name: string;
    last_name: string;
}

interface BillingEntry {
    id: number;
    service_date: string | null;
    hours: string | number | null;
    rate: string | number | null;
    amount: string | number;
    rate_type?: string | null;
    notes?: string | null;
    client?: Client | null;
}

interface LineItem {
    billing_entry_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate_id: string;
    account_id: string;
    service_date: string;
    category: string;
}

interface Props extends PageProps {
    accounts: Account[];
    taxRates: TaxRate[];
    bills: Bill[];
    clients?: Client[];
    billingEntries?: BillingEntry[];
}

const emptyLine = (): LineItem => ({
    billing_entry_id: '',
    description: '',
    quantity: '1',
    unit_price: '0',
    tax_rate_id: '',
    account_id: '',
    service_date: '',
    category: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Invoices', href: '/finance/invoices' },
    { title: 'New Invoice', href: '/finance/invoices/create' },
];

const clientName = (client: Client) =>
    `${client.first_name} ${client.last_name}`.trim();

const billingEntryLabel = (entry: BillingEntry) => {
    const client = entry.client ? clientName(entry.client) : 'No client';
    const date = entry.service_date ?? 'No date';

    return `${date} - ${client} - ${formatMoney(Number(entry.amount) || 0)}`;
};

export default function InvoiceCreate({
    auth,
    accounts,
    taxRates,
    bills,
    clients = [],
    billingEntries = [],
}: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        invoice_number: string;
        client_id: string;
        funding_body: string;
        invoice_date: string;
        due_date: string;
        client_name: string;
        client_email: string;
        client_address: string;
        bill_id: string;
        currency_code: string;
        notes: string;
        terms: string;
        email_subject: string;
        email_body: string;
        lines: LineItem[];
    }>({
        invoice_number: '',
        client_id: '',
        funding_body: '',
        invoice_date: new Date().toISOString().split('T')[0],
        due_date: '',
        client_name: '',
        client_email: '',
        client_address: '',
        bill_id: '',
        currency_code: 'NZD',
        notes: '',
        terms: 'Payment due within 20 days of invoice date.',
        email_subject: '',
        email_body: '',
        lines: [emptyLine()],
    });

    const addLine = () => {
        setData('lines', [...data.lines, emptyLine()]);
    };

    const removeLine = (index: number) => {
        if (data.lines.length <= 1) return;
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );
    };

    const updateLine = (
        index: number,
        field: keyof LineItem,
        value: string,
    ) => {
        const updated = [...data.lines];
        updated[index] = { ...updated[index], [field]: value };
        setData('lines', updated);
    };

    const updateClient = (value: string) => {
        const selectedValue = value === 'none' ? '' : value;
        setData('client_id', selectedValue);

        const selected = clients.find(
            (client) => client.id === Number(selectedValue),
        );
        if (selected && !data.client_name) {
            setData('client_name', clientName(selected));
        }
    };

    const applyBillingEntryToLine = (index: number, value: string) => {
        if (value === 'none') {
            updateLine(index, 'billing_entry_id', '');
            return;
        }

        const entry = billingEntries.find(
            (billingEntry) => billingEntry.id === Number(value),
        );
        if (!entry) return;

        const updated = [...data.lines];
        const line = updated[index];
        const amount = Number(entry.amount) || 0;
        const hours = Number(entry.hours) || 0;
        const rate = Number(entry.rate) || 0;

        updated[index] = {
            ...line,
            billing_entry_id: String(entry.id),
            description:
                line.description || entry.notes || `Billing entry ${entry.id}`,
            quantity: hours > 0 ? String(hours) : line.quantity,
            unit_price: rate > 0 ? String(rate) : String(amount),
            service_date: entry.service_date ?? line.service_date,
            category: entry.rate_type ?? line.category,
        };
        setData('lines', updated);
    };

    const calcLineSubtotal = (line: LineItem) => {
        const qty = parseFloat(line.quantity) || 0;
        const price = parseFloat(line.unit_price) || 0;
        return qty * price;
    };

    const calcLineTax = (line: LineItem) => {
        const subtotal = calcLineSubtotal(line);
        if (line.tax_rate_id) {
            const rate = taxRates.find(
                (r) => r.id === Number(line.tax_rate_id),
            );
            if (rate) return subtotal * (parseFloat(rate.rate) / 100);
        }
        return subtotal * 0.15; // Default 15% GST
    };

    const calcLineTotal = (line: LineItem) =>
        calcLineSubtotal(line) + calcLineTax(line);

    const subtotal = data.lines.reduce(
        (sum, line) => sum + calcLineSubtotal(line),
        0,
    );
    const taxTotal = data.lines.reduce(
        (sum, line) => sum + calcLineTax(line),
        0,
    );
    const total = subtotal + taxTotal;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/invoices');
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="New Invoice" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/invoices"
                        title="New Invoice"
                        description="Create and send a new invoice"
                    />
                }
            >
                <form onSubmit={handleSubmit}>
                    {/* Invoice Details */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Invoice Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div>
                                    <Label htmlFor="invoice_number">
                                        Invoice Number (auto if blank)
                                    </Label>
                                    <Input
                                        id="invoice_number"
                                        value={data.invoice_number}
                                        onChange={(e) =>
                                            setData(
                                                'invoice_number',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Auto-generated"
                                    />
                                    {errors.invoice_number && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.invoice_number}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="invoice_date">
                                        Invoice Date *
                                    </Label>
                                    <Input
                                        id="invoice_date"
                                        type="date"
                                        value={data.invoice_date}
                                        onChange={(e) =>
                                            setData(
                                                'invoice_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.invoice_date && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.invoice_date}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="due_date">Due Date *</Label>
                                    <Input
                                        id="due_date"
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) =>
                                            setData('due_date', e.target.value)
                                        }
                                    />
                                    {errors.due_date && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.due_date}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Client Details */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Client Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="client_id">Client</Label>
                                    <Select
                                        value={data.client_id || 'none'}
                                        onValueChange={updateClient}
                                    >
                                        <SelectTrigger id="client_id">
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                No linked client
                                            </SelectItem>
                                            {clients.map((client) => (
                                                <SelectItem
                                                    key={client.id}
                                                    value={String(client.id)}
                                                >
                                                    {clientName(client)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.client_id}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="funding_body">
                                        Funding Body
                                    </Label>
                                    <Input
                                        id="funding_body"
                                        value={data.funding_body}
                                        onChange={(e) =>
                                            setData(
                                                'funding_body',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="ACC, MSD, private, or other funder"
                                    />
                                    {errors.funding_body && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.funding_body}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="client_name">
                                        Client Name *
                                    </Label>
                                    <Input
                                        id="client_name"
                                        value={data.client_name}
                                        onChange={(e) =>
                                            setData(
                                                'client_name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Client or company name"
                                    />
                                    {errors.client_name && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.client_name}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="client_email">
                                        Client Email
                                    </Label>
                                    <Input
                                        id="client_email"
                                        type="email"
                                        value={data.client_email}
                                        onChange={(e) =>
                                            setData(
                                                'client_email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="client@example.com"
                                    />
                                    {errors.client_email && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.client_email}
                                        </p>
                                    )}
                                </div>
                                <div className="md:col-span-2">
                                    <Label htmlFor="client_address">
                                        Client Address
                                    </Label>
                                    <Textarea
                                        id="client_address"
                                        value={data.client_address}
                                        onChange={(e) =>
                                            setData(
                                                'client_address',
                                                e.target.value,
                                            )
                                        }
                                        rows={2}
                                        placeholder="Postal address..."
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="mb-6">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Line Items</CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLine}
                            >
                                <Plus className="mr-1 h-4 w-4" />
                                Add Line
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {errors.lines && (
                                <p className="mb-2 text-sm text-destructive">
                                    {errors.lines}
                                </p>
                            )}
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-[220px]">
                                                Billing Entry
                                            </TableHead>
                                            <TableHead className="min-w-[200px]">
                                                Description
                                            </TableHead>
                                            <TableHead className="w-24">
                                                Qty
                                            </TableHead>
                                            <TableHead className="w-32">
                                                Unit Price
                                            </TableHead>
                                            <TableHead className="min-w-[150px]">
                                                Tax Rate
                                            </TableHead>
                                            <TableHead className="min-w-[180px]">
                                                Account
                                            </TableHead>
                                            <TableHead className="w-24 text-right">
                                                Tax
                                            </TableHead>
                                            <TableHead className="w-28 text-right">
                                                Total
                                            </TableHead>
                                            <TableHead className="w-12"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {data.lines.map((line, index) => (
                                            <TableRow key={index}>
                                                <TableCell>
                                                    <Select
                                                        value={
                                                            line.billing_entry_id ||
                                                            'none'
                                                        }
                                                        onValueChange={(v) =>
                                                            applyBillingEntryToLine(
                                                                index,
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="min-w-[200px]">
                                                            <SelectValue placeholder="Optional" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                None
                                                            </SelectItem>
                                                            {billingEntries.map(
                                                                (entry) => (
                                                                    <SelectItem
                                                                        key={
                                                                            entry.id
                                                                        }
                                                                        value={String(
                                                                            entry.id,
                                                                        )}
                                                                    >
                                                                        {billingEntryLabel(
                                                                            entry,
                                                                        )}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {errors[
                                                        `lines.${index}.billing_entry_id` as keyof typeof errors
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors[
                                                                    `lines.${index}.billing_entry_id` as keyof typeof errors
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        value={line.description}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                index,
                                                                'description',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Description"
                                                        className="min-w-[180px]"
                                                    />
                                                    {errors[
                                                        `lines.${index}.description` as keyof typeof errors
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors[
                                                                    `lines.${index}.description` as keyof typeof errors
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        value={line.quantity}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                index,
                                                                'quantity',
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-20"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={line.unit_price}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                index,
                                                                'unit_price',
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-28"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.tax_rate_id}
                                                        onValueChange={(v) =>
                                                            updateLine(
                                                                index,
                                                                'tax_rate_id',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="min-w-[130px]">
                                                            <SelectValue placeholder="Default 15%" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="default">
                                                                Default 15% GST
                                                            </SelectItem>
                                                            {taxRates.map(
                                                                (tr) => (
                                                                    <SelectItem
                                                                        key={
                                                                            tr.id
                                                                        }
                                                                        value={String(
                                                                            tr.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            tr.name
                                                                        }{' '}
                                                                        (
                                                                        {
                                                                            tr.rate
                                                                        }
                                                                        %)
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.account_id}
                                                        onValueChange={(v) =>
                                                            updateLine(
                                                                index,
                                                                'account_id',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="min-w-[160px]">
                                                            <SelectValue placeholder="Select" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                None
                                                            </SelectItem>
                                                            {accounts.map(
                                                                (a) => (
                                                                    <SelectItem
                                                                        key={
                                                                            a.id
                                                                        }
                                                                        value={String(
                                                                            a.id,
                                                                        )}
                                                                    >
                                                                        {a.code}{' '}
                                                                        -{' '}
                                                                        {a.name}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell className="text-right text-sm">
                                                    {formatMoney(
                                                        calcLineTax(line),
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm font-medium">
                                                    {formatMoney(
                                                        calcLineTotal(line),
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            removeLine(index)
                                                        }
                                                        disabled={
                                                            data.lines.length <=
                                                            1
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Totals */}
                            <div className="mt-4 flex justify-end">
                                <div className="w-64 space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Subtotal
                                        </span>
                                        <span>{formatMoney(subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            GST
                                        </span>
                                        <span>{formatMoney(taxTotal)}</span>
                                    </div>
                                    <div className="flex justify-between border-t pt-2 text-base font-bold">
                                        <span>Total</span>
                                        <span>{formatMoney(total)}</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Email Settings */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Email Settings (Optional)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4">
                                <div>
                                    <Label htmlFor="email_subject">
                                        Email Subject
                                    </Label>
                                    <Input
                                        id="email_subject"
                                        value={data.email_subject}
                                        onChange={(e) =>
                                            setData(
                                                'email_subject',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Custom email subject (auto-generated if blank)"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="email_body">
                                        Email Body
                                    </Label>
                                    <Textarea
                                        id="email_body"
                                        value={data.email_body}
                                        onChange={(e) =>
                                            setData(
                                                'email_body',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                        placeholder="Custom email body (auto-generated if blank)"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notes & Terms */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Notes & Terms</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="notes">Notes</Label>
                                    <Textarea
                                        id="notes"
                                        value={data.notes}
                                        onChange={(e) =>
                                            setData('notes', e.target.value)
                                        }
                                        rows={3}
                                        placeholder="Notes visible on the invoice..."
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="terms">Payment Terms</Label>
                                    <Textarea
                                        id="terms"
                                        value={data.terms}
                                        onChange={(e) =>
                                            setData('terms', e.target.value)
                                        }
                                        rows={3}
                                        placeholder="Payment terms..."
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Invoice'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href="/finance/invoices">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
