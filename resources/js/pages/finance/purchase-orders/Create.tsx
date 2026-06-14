import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { usePage } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { useState, useCallback } from 'react';

type Option = { id: number; name: string; code?: string };

type LineItem = {
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: string;
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

function emptyLine(): LineItem {
    return { description: '', quantity: '1', unit_price: '0', gst_rate: '15', account_id: '' };
}

function calcLine(line: LineItem) {
    const qty = parseFloat(line.quantity) || 0;
    const price = parseFloat(line.unit_price) || 0;
    const gstRate = parseFloat(line.gst_rate) || 0;
    const subtotal = qty * price;
    const gst = subtotal * gstRate / 100;
    return { subtotal, gst, total: subtotal + gst };
}

export default function PurchaseOrderCreate() {
    const { vendors, accounts, costCentres, fundingStreams } = usePage().props as any;

    const vendorList: Option[] = vendors ?? [];
    const accountList: Option[] = accounts ?? [];
    const costCentreList: Option[] = costCentres ?? [];
    const fundingStreamList: Option[] = fundingStreams ?? [];

    const [lines, setLines] = useState<LineItem[]>([emptyLine()]);

    const { data, setData, post, processing, errors } = useForm({
        vendor_id: '',
        order_date: new Date().toISOString().split('T')[0],
        expected_date: '',
        notes: '',
        cost_centre_id: '',
        funding_stream_id: '',
        lines: [emptyLine()],
    });

    const updateLine = useCallback((index: number, field: keyof LineItem, value: string) => {
        setLines((prev) => {
            const updated = [...prev];
            updated[index] = { ...updated[index], [field]: value };
            return updated;
        });
    }, []);

    const addLine = useCallback(() => {
        setLines((prev) => [...prev, emptyLine()]);
    }, []);

    const removeLine = useCallback((index: number) => {
        setLines((prev) => {
            if (prev.length <= 1) return prev;
            return prev.filter((_, i) => i !== index);
        });
    }, []);

    const totals = lines.reduce(
        (acc, line) => {
            const c = calcLine(line);
            return { subtotal: acc.subtotal + c.subtotal, gst: acc.gst + c.gst, total: acc.total + c.total };
        },
        { subtotal: 0, gst: 0, total: 0 }
    );

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const payload = {
            ...data,
            vendor_id: data.vendor_id,
            cost_centre_id: data.cost_centre_id || null,
            funding_stream_id: data.funding_stream_id || null,
            lines: lines.map((l) => ({
                description: l.description,
                quantity: parseFloat(l.quantity) || 0,
                unit_price: parseFloat(l.unit_price) || 0,
                gst_rate: parseFloat(l.gst_rate) || 15,
                account_id: l.account_id || null,
            })),
        };
        router.post('/finance/purchase-orders', payload);
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Finance', href: '/finance/dashboard' }, { title: 'Purchase Orders', href: '/finance/purchase-orders' }, { title: 'Create', href: '#' }]}>
            <Head title="Create Purchase Order" />
            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/purchase-orders"
                        title="New Purchase Order"
                        description="Raise a purchase order against a vendor"
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Order Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Vendor *</Label>
                                <Select value={data.vendor_id} onValueChange={(v) => setData('vendor_id', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select vendor" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {vendorList.map((v) => (
                                            <SelectItem key={v.id} value={String(v.id)}>
                                                {v.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.vendor_id && <p className="text-sm text-status-critical">{errors.vendor_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label>Order Date *</Label>
                                <Input
                                    type="date"
                                    value={data.order_date}
                                    onChange={(e) => setData('order_date', e.target.value)}
                                />
                                {errors.order_date && <p className="text-sm text-status-critical">{errors.order_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label>Expected Date</Label>
                                <Input
                                    type="date"
                                    value={data.expected_date}
                                    onChange={(e) => setData('expected_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Cost Centre</Label>
                                <Select value={data.cost_centre_id || 'none'} onValueChange={(v) => setData('cost_centre_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {costCentreList.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.code} - {c.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Funding Stream</Label>
                                <Select value={data.funding_stream_id || 'none'} onValueChange={(v) => setData('funding_stream_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {fundingStreamList.map((f) => (
                                            <SelectItem key={f.id} value={String(f.id)}>
                                                {f.code} - {f.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1 md:col-span-2 lg:col-span-3">
                                <Label>Notes</Label>
                                <Textarea
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows={3}
                                    placeholder="Optional notes..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base">Line Items</CardTitle>
                            <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                Add Line
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {errors.lines && <p className="text-sm text-status-critical">{errors.lines}</p>}

                            <div className="hidden md:grid md:grid-cols-12 md:gap-2 md:text-xs md:font-medium md:text-muted-foreground">
                                <div className="col-span-3">Description</div>
                                <div className="col-span-1">Qty</div>
                                <div className="col-span-2">Unit Price</div>
                                <div className="col-span-1">GST %</div>
                                <div className="col-span-2">Account</div>
                                <div className="col-span-2 text-right">Line Total</div>
                                <div className="col-span-1"></div>
                            </div>

                            {lines.map((line, idx) => {
                                const c = calcLine(line);
                                return (
                                    <div key={idx} className="grid grid-cols-1 gap-2 rounded border p-3 md:grid-cols-12 md:items-center md:border-0 md:p-0">
                                        <div className="md:col-span-3">
                                            <Label className="md:hidden">Description</Label>
                                            <Input
                                                value={line.description}
                                                onChange={(e) => updateLine(idx, 'description', e.target.value)}
                                                placeholder="Description"
                                            />
                                            {(errors as any)[`lines.${idx}.description`] && (
                                                <p className="text-xs text-status-critical">{(errors as any)[`lines.${idx}.description`]}</p>
                                            )}
                                        </div>
                                        <div className="md:col-span-1">
                                            <Label className="md:hidden">Qty</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                value={line.quantity}
                                                onChange={(e) => updateLine(idx, 'quantity', e.target.value)}
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label className="md:hidden">Unit Price</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={line.unit_price}
                                                onChange={(e) => updateLine(idx, 'unit_price', e.target.value)}
                                            />
                                        </div>
                                        <div className="md:col-span-1">
                                            <Label className="md:hidden">GST %</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={line.gst_rate}
                                                onChange={(e) => updateLine(idx, 'gst_rate', e.target.value)}
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label className="md:hidden">Account</Label>
                                            <Select value={line.account_id || 'none'} onValueChange={(v) => updateLine(idx, 'account_id', v === 'none' ? '' : v)}>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">None</SelectItem>
                                                    {accountList.map((a) => (
                                                        <SelectItem key={a.id} value={String(a.id)}>
                                                            {a.code} - {a.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="md:col-span-2 md:text-right">
                                            <Label className="md:hidden">Line Total</Label>
                                            <div className="text-sm font-medium">{formatNZD(c.total)}</div>
                                            <div className="text-xs text-muted-foreground">GST: {formatNZD(c.gst)}</div>
                                        </div>
                                        <div className="md:col-span-1 md:text-center">
                                            {lines.length > 1 && (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(idx)} className="text-status-critical hover:text-status-critical">
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}

                            <div className="border-t pt-3">
                                <div className="flex justify-end">
                                    <div className="w-64 space-y-1 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Subtotal</span>
                                            <span>{formatNZD(totals.subtotal)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">GST</span>
                                            <span>{formatNZD(totals.gst)}</span>
                                        </div>
                                        <div className="flex justify-between border-t pt-1 font-semibold">
                                            <span>Total</span>
                                            <span>{formatNZD(totals.total)}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/finance/purchase-orders')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save Purchase Order
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
