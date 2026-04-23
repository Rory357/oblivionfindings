import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Trash2 } from 'lucide-react';

interface Vendor {
    id: number;
    name: string;
}

interface ClientOption {
    id: number;
    name: string;
}

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface LineItem {
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: string;
}

interface Props extends PageProps {
    vendors: Vendor[];
    clients: ClientOption[];
    accounts: Account[];
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const emptyLine = (): LineItem => ({
    description: '',
    quantity: '1',
    unit_price: '0',
    gst_rate: '15',
    account_id: '',
});

export default function CreditNoteCreate({ auth, vendors, clients, accounts }: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        type: string;
        vendor_id: string;
        client_id: string;
        credit_date: string;
        reason: string;
        lines: LineItem[];
    }>({
        type: 'payable',
        vendor_id: '',
        client_id: '',
        credit_date: new Date().toISOString().split('T')[0],
        reason: '',
        lines: [emptyLine()],
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

    const calcLineGst = (line: LineItem) => {
        const subtotal = calcLineSubtotal(line);
        const rate = parseFloat(line.gst_rate) || 0;
        return subtotal * (rate / 100);
    };

    const calcLineTotal = (line: LineItem) => calcLineSubtotal(line) + calcLineGst(line);

    const subtotal = data.lines.reduce((sum, line) => sum + calcLineSubtotal(line), 0);
    const gstTotal = data.lines.reduce((sum, line) => sum + calcLineGst(line), 0);
    const total = subtotal + gstTotal;

    const filteredAccounts = data.type === 'payable'
        ? accounts.filter((a) => a.type === 'expense' || a.type === 'asset')
        : accounts.filter((a) => a.type === 'revenue');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/credit-notes');
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Credit Notes', href: '/finance/credit-notes' },
                { title: 'New Credit Note', href: '/finance/credit-notes/create' },
            ]}
        >
            <Head title="New Credit Note" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">New Credit Note</h1>
                        <p className="text-muted-foreground mt-1">Create a credit note</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    {/* Credit Note Details */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Credit Note Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <Label htmlFor="type">Type *</Label>
                                    <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="payable">Accounts Payable (Vendor)</SelectItem>
                                            <SelectItem value="receivable">Accounts Receivable (Client)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.type && <p className="text-sm text-red-600 mt-1">{errors.type}</p>}
                                </div>

                                {data.type === 'payable' ? (
                                    <div>
                                        <Label htmlFor="vendor_id">Vendor *</Label>
                                        <Select value={data.vendor_id} onValueChange={(v) => setData('vendor_id', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select vendor" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {vendors.map((v) => (
                                                    <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.vendor_id && <p className="text-sm text-red-600 mt-1">{errors.vendor_id}</p>}
                                    </div>
                                ) : (
                                    <div>
                                        <Label htmlFor="client_id">Client *</Label>
                                        <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select client" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.client_id && <p className="text-sm text-red-600 mt-1">{errors.client_id}</p>}
                                    </div>
                                )}

                                <div>
                                    <Label htmlFor="credit_date">Credit Date *</Label>
                                    <Input
                                        id="credit_date"
                                        type="date"
                                        value={data.credit_date}
                                        onChange={(e) => setData('credit_date', e.target.value)}
                                    />
                                    {errors.credit_date && <p className="text-sm text-red-600 mt-1">{errors.credit_date}</p>}
                                </div>
                            </div>
                            <div className="mt-4">
                                <Label htmlFor="reason">Reason</Label>
                                <Textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    rows={3}
                                    placeholder="Reason for credit note..."
                                />
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
                            {errors.lines && <p className="text-sm text-red-600 mb-2">{errors.lines}</p>}
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-[200px]">Description</TableHead>
                                            <TableHead className="w-24">Qty</TableHead>
                                            <TableHead className="w-32">Unit Price</TableHead>
                                            <TableHead className="w-24">GST %</TableHead>
                                            <TableHead className="min-w-[200px]">Account</TableHead>
                                            <TableHead className="w-24 text-right">GST</TableHead>
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
                                                    {errors[`lines.${index}.description` as keyof typeof errors] && (
                                                        <p className="text-xs text-red-600">{errors[`lines.${index}.description` as keyof typeof errors]}</p>
                                                    )}
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
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        value={line.gst_rate}
                                                        onChange={(e) => updateLine(index, 'gst_rate', e.target.value)}
                                                        className="w-20"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.account_id}
                                                        onValueChange={(v) => updateLine(index, 'account_id', v)}
                                                    >
                                                        <SelectTrigger className="min-w-[180px]">
                                                            <SelectValue placeholder="Select account" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {filteredAccounts.map((a) => (
                                                                <SelectItem key={a.id} value={String(a.id)}>
                                                                    {a.code} - {a.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell className="text-right text-sm">
                                                    {formatCurrency(calcLineGst(line))}
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
                                                        <Trash2 className="w-4 h-4 text-red-500" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Totals */}
                            <div className="flex justify-end mt-4">
                                <div className="w-64 space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">Subtotal</span>
                                        <span>{formatCurrency(subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">GST</span>
                                        <span>{formatCurrency(gstTotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-base font-bold border-t pt-2">
                                        <span>Total</span>
                                        <span>{formatCurrency(total)}</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Credit Note'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href="/finance/credit-notes">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
