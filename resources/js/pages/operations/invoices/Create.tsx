import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

type LineItem = {
    description: string;
    quantity: string;
    unit_price: string;
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

export default function InvoiceCreate({ clients }: Props) {
    const [lineItems, setLineItems] = useState<LineItem[]>([
        { description: '', quantity: '1', unit_price: '' },
    ]);

    const { data, setData, post, processing, errors } = useForm({
        client_id: '',
        funding_body: '',
        issue_date: '',
        due_date: '',
        payment_terms: 'net_30',
        notes: '',
        line_items: lineItems,
    });

    const addLineItem = () => {
        const updated = [...lineItems, { description: '', quantity: '1', unit_price: '' }];
        setLineItems(updated);
        setData('line_items', updated);
    };

    const removeLineItem = (index: number) => {
        const updated = lineItems.filter((_, i) => i !== index);
        setLineItems(updated);
        setData('line_items', updated);
    };

    const updateLineItem = (index: number, field: keyof LineItem, value: string) => {
        const updated = lineItems.map((item, i) => (i === index ? { ...item, [field]: value } : item));
        setLineItems(updated);
        setData('line_items', updated);
    };

    const lineItemAmount = (item: LineItem): number => {
        const qty = parseFloat(item.quantity) || 0;
        const price = parseFloat(item.unit_price) || 0;
        return qty * price;
    };

    const subtotal = lineItems.reduce((sum, item) => sum + lineItemAmount(item), 0);
    const tax = subtotal * 0.15;
    const total = subtotal + tax;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/invoices');
    };

    return (
        <AppLayout>
            <Head title="Create Invoice" />
            <PageHeader title="Create Invoice" description="Create a new invoice for a client." backHref="/operations/invoices" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    {/* Invoice Details */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Invoice Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Client *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Funding Body</Label>
                                    <Input value={data.funding_body} onChange={(e) => setData('funding_body', e.target.value)} placeholder="e.g. NDIA" />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label>Issue Date *</Label>
                                    <Input type="date" value={data.issue_date} onChange={(e) => setData('issue_date', e.target.value)} />
                                    {errors.issue_date && <p className="text-xs text-destructive">{errors.issue_date}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Due Date *</Label>
                                    <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                                    {errors.due_date && <p className="text-xs text-destructive">{errors.due_date}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Payment Terms</Label>
                                    <Select value={data.payment_terms} onValueChange={(v) => setData('payment_terms', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="due_on_receipt">Due on Receipt</SelectItem>
                                            <SelectItem value="net_7">Net 7</SelectItem>
                                            <SelectItem value="net_14">Net 14</SelectItem>
                                            <SelectItem value="net_30">Net 30</SelectItem>
                                            <SelectItem value="net_60">Net 60</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="mt-4">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Line Items</CardTitle>
                                <Button type="button" size="sm" variant="outline" onClick={addLineItem}>
                                    <Plus className="mr-1 h-3.5 w-3.5" /> Add Line
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                <div className="grid grid-cols-12 gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                    <div className="col-span-5">Description</div>
                                    <div className="col-span-2">Qty</div>
                                    <div className="col-span-2">Unit Price</div>
                                    <div className="col-span-2 text-right">Amount</div>
                                    <div className="col-span-1" />
                                </div>
                                {lineItems.map((item, index) => (
                                    <div key={index} className="grid grid-cols-12 items-center gap-2">
                                        <div className="col-span-5">
                                            <Input
                                                value={item.description}
                                                onChange={(e) => updateLineItem(index, 'description', e.target.value)}
                                                placeholder="Service description"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="col-span-2">
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={item.quantity}
                                                onChange={(e) => updateLineItem(index, 'quantity', e.target.value)}
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="col-span-2">
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={item.unit_price}
                                                onChange={(e) => updateLineItem(index, 'unit_price', e.target.value)}
                                                placeholder="0.00"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="col-span-2 text-right text-xs font-medium tabular-nums">
                                            {formatCurrency(lineItemAmount(item))}
                                        </div>
                                        <div className="col-span-1 text-center">
                                            {lineItems.length > 1 && (
                                                <Button type="button" size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={() => removeLineItem(index)}>
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Totals */}
                            <div className="mt-4 flex justify-end">
                                <div className="w-64 space-y-1 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Subtotal</span>
                                        <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">GST (15%)</span>
                                        <span className="tabular-nums">{formatCurrency(tax)}</span>
                                    </div>
                                    <div className="flex justify-between border-t pt-1 font-semibold">
                                        <span>Total (NZD)</span>
                                        <span className="tabular-nums">{formatCurrency(total)}</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card className="mt-4">
                        <CardContent className="space-y-4 pt-6">
                            <div className="space-y-1.5">
                                <Label>Notes</Label>
                                <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} placeholder="Payment instructions or additional notes..." />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/invoices')}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Create Invoice</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
