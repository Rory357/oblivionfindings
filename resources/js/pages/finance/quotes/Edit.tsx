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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type PriceBookItem = {
    id: number;
    service_code: string | null;
    name: string;
    unit: string;
    rate: number;
};

type ExistingLineItem = {
    id: number;
    description: string;
    quantity: number;
    unit: string;
    unit_price: number;
    amount: number;
};

type Props = {
    quote: {
        id: number;
        title: string;
        client_id: number | null;
        client_name: string | null;
        client_email: string | null;
        client_phone: string | null;
        valid_until: string | null;
        notes: string | null;
        terms: string | null;
        line_items: ExistingLineItem[];
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    price_book_items: PriceBookItem[];
};

type LineItem = {
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

export default function QuoteEdit({ quote, clients, price_book_items }: Props) {
    const initialLineItems: LineItem[] = quote.line_items.map((li) => ({
        description: li.description,
        quantity: String(li.quantity),
        unit: li.unit,
        unit_price: String(li.unit_price),
    }));

    const [lineItems, setLineItems] = useState<LineItem[]>(
        initialLineItems.length > 0 ? initialLineItems : [{ description: '', quantity: '1', unit: 'hour', unit_price: '' }],
    );

    const { data, setData, put, processing, errors } = useForm({
        client_id: quote.client_id != null ? String(quote.client_id) : '',
        client_name: quote.client_name ?? '',
        client_email: quote.client_email ?? '',
        client_phone: quote.client_phone ?? '',
        title: quote.title,
        valid_until: quote.valid_until ?? '',
        notes: quote.notes ?? '',
        terms: quote.terms ?? '',
        line_items: lineItems,
    });

    const addLineItem = () => {
        const updated = [...lineItems, { description: '', quantity: '1', unit: 'hour', unit_price: '' }];
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

    const addFromPriceBook = (pbItem: PriceBookItem) => {
        const newItem: LineItem = {
            description: pbItem.name,
            quantity: '1',
            unit: pbItem.unit,
            unit_price: String(pbItem.rate),
        };
        const updated = [...lineItems, newItem];
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
        put(`/finance/quotes/${quote.id}`);
    };

    return (
        <AppLayout>
            <Head title={`Edit: ${quote.title}`} />
            <PageHero variant="compact" title={`Edit: ${quote.title}`} backHref={`/finance/quotes/${quote.id}`} />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    {/* Client Details */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Client Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Existing Client</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select client (optional)" /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Title *</Label>
                                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. Support Services Quote" />
                                    {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label>Client Name</Label>
                                    <Input value={data.client_name} onChange={(e) => setData('client_name', e.target.value)} placeholder="For prospects" />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Client Email</Label>
                                    <Input type="email" value={data.client_email} onChange={(e) => setData('client_email', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Client Phone</Label>
                                    <Input value={data.client_phone} onChange={(e) => setData('client_phone', e.target.value)} />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Valid Until</Label>
                                <Input type="date" value={data.valid_until} onChange={(e) => setData('valid_until', e.target.value)} className="w-[200px]" />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="mt-4">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Line Items</CardTitle>
                                <div className="flex gap-2">
                                    {price_book_items && price_book_items.length > 0 && (
                                        <Select onValueChange={(v) => {
                                            const item = price_book_items.find((i) => String(i.id) === v);
                                            if (item) addFromPriceBook(item);
                                        }}>
                                            <SelectTrigger className="h-8 w-[200px] text-xs">
                                                <SelectValue placeholder="Add from price book" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {price_book_items.map((item) => (
                                                    <SelectItem key={item.id} value={String(item.id)}>
                                                        {item.name} ({formatCurrency(item.rate)}/{item.unit})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                    <Button type="button" size="sm" variant="outline" onClick={addLineItem}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Add Line
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                <div className="grid grid-cols-12 gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                    <div className="col-span-4">Description</div>
                                    <div className="col-span-2">Qty</div>
                                    <div className="col-span-2">Unit</div>
                                    <div className="col-span-2">Unit Price</div>
                                    <div className="col-span-1 text-right">Amount</div>
                                    <div className="col-span-1" />
                                </div>
                                {lineItems.map((item, index) => (
                                    <div key={index} className="grid grid-cols-12 items-center gap-2">
                                        <div className="col-span-4">
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
                                            <Select value={item.unit} onValueChange={(v) => updateLineItem(index, 'unit', v)}>
                                                <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {['hour', 'day', 'each', 'km', 'week'].map((u) => (
                                                        <SelectItem key={u} value={u} className="capitalize">{u}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
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
                                        <div className="col-span-1 text-right text-xs font-medium tabular-nums">
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

                    {/* Notes & Terms */}
                    <Card className="mt-4">
                        <CardContent className="space-y-4 pt-6">
                            <div className="space-y-1.5">
                                <Label>Notes</Label>
                                <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} placeholder="Additional notes for the client..." />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Terms & Conditions</Label>
                                <Textarea value={data.terms} onChange={(e) => setData('terms', e.target.value)} rows={3} />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(`/finance/quotes/${quote.id}`)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
