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
import { PageHero, PageLayout } from '@/components/page';

interface Vendor {
    id: number;
    name: string;
    payment_terms_days: number | null;
    default_expense_account_id: number | null;
}

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface CostCentre {
    id: number;
    code: string;
    name: string;
}

interface FundingStream {
    id: number;
    code: string;
    name: string;
}

interface TaxRate {
    id: number;
    name: string;
    code: string;
    rate: string;
}

interface PurchaseOrderLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: number;
    account: Account | null;
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    vendor_id: number;
    total_amount: string;
    lines: PurchaseOrderLine[];
    vendor: { id: number; name: string } | null;
}

interface BillLine {
    id?: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: number;
    cost_centre_id: number | null;
    funding_stream_id: number | null;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_id: number;
    vendor_reference: string | null;
    bill_date: string;
    due_date: string;
    notes: string | null;
    purchase_order_id: number | null;
    lines: BillLine[];
}

interface LineItem {
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: string;
    cost_centre_id: string;
    funding_stream_id: string;
}

interface Props extends PageProps {
    bill: Bill;
    vendors: Vendor[];
    accounts: Account[];
    costCentres: CostCentre[];
    fundingStreams: FundingStream[];
    taxRates: TaxRate[];
    purchaseOrders: PurchaseOrder[];
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const emptyLine = (): LineItem => ({
    description: '',
    quantity: '1',
    unit_price: '0',
    gst_rate: '15',
    account_id: '',
    cost_centre_id: '',
    funding_stream_id: '',
});

export default function BillEdit({ auth, bill, vendors, accounts, costCentres, fundingStreams, taxRates, purchaseOrders }: Props) {
    const { data, setData, put, processing, errors } = useForm<{
        vendor_id: string;
        vendor_reference: string;
        bill_date: string;
        due_date: string;
        notes: string;
        purchase_order_id: string;
        lines: LineItem[];
    }>({
        vendor_id: String(bill.vendor_id),
        vendor_reference: bill.vendor_reference ?? '',
        bill_date: bill.bill_date?.split('T')[0] ?? '',
        due_date: bill.due_date?.split('T')[0] ?? '',
        notes: bill.notes ?? '',
        purchase_order_id: bill.purchase_order_id ? String(bill.purchase_order_id) : '',
        lines: bill.lines.map((line) => ({
            description: line.description,
            quantity: String(line.quantity),
            unit_price: String(line.unit_price),
            gst_rate: String(line.gst_rate),
            account_id: String(line.account_id),
            cost_centre_id: line.cost_centre_id ? String(line.cost_centre_id) : '',
            funding_stream_id: line.funding_stream_id ? String(line.funding_stream_id) : '',
        })),
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

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/finance/bills/${bill.id}`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Bills', href: '/finance/bills' },
                { title: bill.bill_number, href: `/finance/bills/${bill.id}` },
                { title: 'Edit', href: `/finance/bills/${bill.id}/edit` },
            ]}
        >
            <Head title={`Edit Bill ${bill.bill_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/finance/bills/${bill.id}`}
                        title={`Edit Bill ${bill.bill_number}`}
                        description="Modify draft bill details"
                    />
                }
            >
                <form onSubmit={handleSubmit}>
                    {/* Bill Details */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Bill Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                                    {errors.vendor_id && <p className="text-sm text-status-critical mt-1">{errors.vendor_id}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="vendor_reference">Vendor Reference</Label>
                                    <Input
                                        id="vendor_reference"
                                        value={data.vendor_reference}
                                        onChange={(e) => setData('vendor_reference', e.target.value)}
                                        placeholder="Invoice # from vendor"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="purchase_order_id">Purchase Order</Label>
                                    <Select value={data.purchase_order_id} onValueChange={(v) => setData('purchase_order_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="None" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            {purchaseOrders.map((po) => (
                                                <SelectItem key={po.id} value={String(po.id)}>
                                                    {po.po_number} - {po.vendor?.name ?? 'Unknown'} ({formatCurrency(Number(po.total_amount))})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label htmlFor="bill_date">Bill Date *</Label>
                                    <Input
                                        id="bill_date"
                                        type="date"
                                        value={data.bill_date}
                                        onChange={(e) => setData('bill_date', e.target.value)}
                                    />
                                    {errors.bill_date && <p className="text-sm text-status-critical mt-1">{errors.bill_date}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="due_date">Due Date *</Label>
                                    <Input
                                        id="due_date"
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) => setData('due_date', e.target.value)}
                                    />
                                    {errors.due_date && <p className="text-sm text-status-critical mt-1">{errors.due_date}</p>}
                                </div>
                            </div>
                            <div className="mt-4">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows={3}
                                    placeholder="Optional notes..."
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
                            {errors.lines && <p className="text-sm text-status-critical mb-2">{errors.lines}</p>}
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-[200px]">Description</TableHead>
                                            <TableHead className="w-24">Qty</TableHead>
                                            <TableHead className="w-32">Unit Price</TableHead>
                                            <TableHead className="w-24">GST %</TableHead>
                                            <TableHead className="min-w-[180px]">Account</TableHead>
                                            <TableHead className="min-w-[150px]">Cost Centre</TableHead>
                                            <TableHead className="min-w-[150px]">Funding Stream</TableHead>
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
                                                        <p className="text-xs text-status-critical">{errors[`lines.${index}.description` as keyof typeof errors]}</p>
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
                                                        <SelectTrigger className="min-w-[160px]">
                                                            <SelectValue placeholder="Select" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {accounts.map((a) => (
                                                                <SelectItem key={a.id} value={String(a.id)}>
                                                                    {a.code} - {a.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.cost_centre_id}
                                                        onValueChange={(v) => updateLine(index, 'cost_centre_id', v)}
                                                    >
                                                        <SelectTrigger className="min-w-[130px]">
                                                            <SelectValue placeholder="None" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">None</SelectItem>
                                                            {costCentres.map((cc) => (
                                                                <SelectItem key={cc.id} value={String(cc.id)}>
                                                                    {cc.code} - {cc.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={line.funding_stream_id}
                                                        onValueChange={(v) => updateLine(index, 'funding_stream_id', v)}
                                                    >
                                                        <SelectTrigger className="min-w-[130px]">
                                                            <SelectValue placeholder="None" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">None</SelectItem>
                                                            {fundingStreams.map((fs) => (
                                                                <SelectItem key={fs.id} value={String(fs.id)}>
                                                                    {fs.code} - {fs.name}
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
                                                        <Trash2 className="w-4 h-4 text-status-critical" />
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
                            {processing ? 'Saving...' : 'Update Bill'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={`/finance/bills/${bill.id}`}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
