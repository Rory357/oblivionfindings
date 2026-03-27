import { Head, Link, useForm } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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

interface LineItem {
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate_id: string;
    account_id: string;
}

interface ExistingInvoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    client_name: string;
    client_email: string | null;
    client_address: string | null;
    bill_id: number | null;
    currency_code: string;
    notes: string | null;
    terms: string | null;
    email_subject: string | null;
    email_body: string | null;
    lines: Array<{
        id: number;
        description: string;
        quantity: string;
        unit_price: string;
        tax_rate_id: number | null;
        account_id: number | null;
    }>;
}

interface Props extends PageProps {
    invoice: ExistingInvoice;
    accounts: Account[];
    taxRates: TaxRate[];
    bills: Bill[];
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const emptyLine = (): LineItem => ({
    description: '',
    quantity: '1',
    unit_price: '0',
    tax_rate_id: '',
    account_id: '',
});

export default function InvoiceEdit({ auth, invoice, accounts, taxRates, bills }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Invoices', href: '/finance/invoices' },
        { title: invoice.invoice_number, href: `/finance/invoices/${invoice.id}` },
        { title: 'Edit', href: `/finance/invoices/${invoice.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm<{
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
        invoice_date: invoice.invoice_date,
        due_date: invoice.due_date,
        client_name: invoice.client_name,
        client_email: invoice.client_email ?? '',
        client_address: invoice.client_address ?? '',
        bill_id: invoice.bill_id ? String(invoice.bill_id) : '',
        currency_code: invoice.currency_code,
        notes: invoice.notes ?? '',
        terms: invoice.terms ?? '',
        email_subject: invoice.email_subject ?? '',
        email_body: invoice.email_body ?? '',
        lines: invoice.lines.length > 0
            ? invoice.lines.map((l) => ({
                description: l.description,
                quantity: String(l.quantity),
                unit_price: String(l.unit_price),
                tax_rate_id: l.tax_rate_id ? String(l.tax_rate_id) : '',
                account_id: l.account_id ? String(l.account_id) : '',
            }))
            : [emptyLine()],
    });

    const addLine = () => {
        setData('lines', [...data.lines, emptyLine()]);
    };

    const removeLine = (index: number) => {
        if (data.lines.length <= 1) return;
        setData('lines', data.lines.filter((_, i) => i !== index));
    };

    const updateLine = (index: number, field: keyof LineItem, value: string) => {
        const updated = [...data.lines];
        updated[index] = { ...updated[index], [field]: value };
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
            const rate = taxRates.find((r) => r.id === Number(line.tax_rate_id));
            if (rate) return subtotal * (parseFloat(rate.rate) / 100);
        }
        return subtotal * 0.15;
    };

    const calcLineTotal = (line: LineItem) => calcLineSubtotal(line) + calcLineTax(line);

    const subtotal = data.lines.reduce((sum, line) => sum + calcLineSubtotal(line), 0);
    const taxTotal = data.lines.reduce((sum, line) => sum + calcLineTax(line), 0);
    const total = subtotal + taxTotal;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/finance/invoices/${invoice.id}`);
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title={`Edit Invoice ${invoice.invoice_number}`} />

            <div className="max-w-7xl mx-auto p-6">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Edit {invoice.invoice_number}</h1>
                        <p className="text-muted-foreground mt-1">Update invoice details</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    {/* Invoice Details */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Invoice Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <Label>Invoice Number</Label>
                                    <Input value={invoice.invoice_number} disabled className="bg-muted" />
                                </div>
                                <div>
                                    <Label htmlFor="invoice_date">Invoice Date *</Label>
                                    <Input
                                        id="invoice_date"
                                        type="date"
                                        value={data.invoice_date}
                                        onChange={(e) => setData('invoice_date', e.target.value)}
                                    />
                                    {errors.invoice_date && <p className="text-sm text-destructive mt-1">{errors.invoice_date}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="due_date">Due Date *</Label>
                                    <Input
                                        id="due_date"
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) => setData('due_date', e.target.value)}
                                    />
                                    {errors.due_date && <p className="text-sm text-destructive mt-1">{errors.due_date}</p>}
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
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="client_name">Client Name *</Label>
                                    <Input
                                        id="client_name"
                                        value={data.client_name}
                                        onChange={(e) => setData('client_name', e.target.value)}
                                    />
                                    {errors.client_name && <p className="text-sm text-destructive mt-1">{errors.client_name}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="client_email">Client Email</Label>
                                    <Input
                                        id="client_email"
                                        type="email"
                                        value={data.client_email}
                                        onChange={(e) => setData('client_email', e.target.value)}
                                    />
                                    {errors.client_email && <p className="text-sm text-destructive mt-1">{errors.client_email}</p>}
                                </div>
                                <div className="md:col-span-2">
                                    <Label htmlFor="client_address">Client Address</Label>
                                    <Textarea
                                        id="client_address"
                                        value={data.client_address}
                                        onChange={(e) => setData('client_address', e.target.value)}
                                        rows={2}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="mb-6">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Line Items</CardTitle>
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Line
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {errors.lines && <p className="text-sm text-destructive mb-2">{errors.lines}</p>}
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-[200px]">Description</TableHead>
                                            <TableHead className="w-24">Qty</TableHead>
                                            <TableHead className="w-32">Unit Price</TableHead>
                                            <TableHead className="min-w-[150px]">Tax Rate</TableHead>
                                            <TableHead className="min-w-[180px]">Account</TableHead>
                                            <TableHead className="w-24 text-right">Tax</TableHead>
                                            <TableHead className="w-28 text-right">Total</TableHead>
                                            <TableHead className="w-12"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {data.lines.map((line, index) => (
                                            <TableRow key={index}>
                                                <TableCell>
                                                    <Input
                                                        value={line.description}
                                                        onChange={(e) => updateLine(index, 'description', e.target.value)}
                                                        placeholder="Description"
                                                        className="min-w-[180px]"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        value={line.quantity}
                                                        onChange={(e) => updateLine(index, 'quantity', e.target.value)}
                                                        className="w-20"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={line.unit_price}
                                                        onChange={(e) => updateLine(index, 'unit_price', e.target.value)}
                                                        className="w-28"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.tax_rate_id}
                                                        onValueChange={(v) => updateLine(index, 'tax_rate_id', v)}
                                                    >
                                                        <SelectTrigger className="min-w-[130px]">
                                                            <SelectValue placeholder="Default 15%" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="default">Default 15% GST</SelectItem>
                                                            {taxRates.map((tr) => (
                                                                <SelectItem key={tr.id} value={String(tr.id)}>
                                                                    {tr.name} ({tr.rate}%)
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.account_id}
                                                        onValueChange={(v) => updateLine(index, 'account_id', v)}
                                                    >
                                                        <SelectTrigger className="min-w-[160px]">
                                                            <SelectValue placeholder="Select" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">None</SelectItem>
                                                            {accounts.map((a) => (
                                                                <SelectItem key={a.id} value={String(a.id)}>
                                                                    {a.code} - {a.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell className="text-right text-sm">
                                                    {formatCurrency(calcLineTax(line))}
                                                </TableCell>
                                                <TableCell className="text-right font-medium text-sm">
                                                    {formatCurrency(calcLineTotal(line))}
                                                </TableCell>
                                                <TableCell>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => removeLine(index)}
                                                        disabled={data.lines.length <= 1}
                                                    >
                                                        <Trash2 className="w-4 h-4 text-destructive" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <div className="flex justify-end mt-4">
                                <div className="w-64 space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">Subtotal</span>
                                        <span>{formatCurrency(subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">GST</span>
                                        <span>{formatCurrency(taxTotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-base font-bold border-t pt-2">
                                        <span>Total</span>
                                        <span>{formatCurrency(total)}</span>
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
                                    <Label htmlFor="email_subject">Email Subject</Label>
                                    <Input
                                        id="email_subject"
                                        value={data.email_subject}
                                        onChange={(e) => setData('email_subject', e.target.value)}
                                        placeholder="Custom email subject"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="email_body">Email Body</Label>
                                    <Textarea
                                        id="email_body"
                                        value={data.email_body}
                                        onChange={(e) => setData('email_body', e.target.value)}
                                        rows={3}
                                        placeholder="Custom email body"
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
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="notes">Notes</Label>
                                    <Textarea
                                        id="notes"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="terms">Payment Terms</Label>
                                    <Textarea
                                        id="terms"
                                        value={data.terms}
                                        onChange={(e) => setData('terms', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Invoice'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={`/finance/invoices/${invoice.id}`}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
