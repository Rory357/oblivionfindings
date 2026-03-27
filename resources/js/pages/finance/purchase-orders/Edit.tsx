import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useState, useCallback } from 'react';

type Option = { id: number; name: string; code?: string };

type LineItem = {
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: string;
};

type ExistingLine = {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: number | null;
};

type PurchaseOrder = {
    id: number;
    po_number: string;
    vendor_id: number;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    cost_centre_id: number | null;
    funding_stream_id: number | null;
    lines: ExistingLine[];
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

function existingToLineItem(line: ExistingLine): LineItem {
    return {
        description: line.description,
        quantity: String(Number(line.quantity)),
        unit_price: String(Number(line.unit_price)),
        gst_rate: String((Number(line.gst_rate) * 100).toFixed(0)),
        account_id: line.account_id ? String(line.account_id) : '',
    };
}

export default function PurchaseOrderEdit() {
    const { purchaseOrder, vendors, accounts, costCentres, fundingStreams, errors } = usePage().props as any;
    const po: PurchaseOrder = purchaseOrder;

    const vendorList: Option[] = vendors ?? [];
    const accountList: Option[] = accounts ?? [];
    const costCentreList: Option[] = costCentres ?? [];
    const fundingStreamList: Option[] = fundingStreams ?? [];

    const [vendorId, setVendorId] = useState(String(po.vendor_id));
    const [orderDate, setOrderDate] = useState(po.order_date);
    const [expectedDate, setExpectedDate] = useState(po.expected_date ?? '');
    const [notes, setNotes] = useState(po.notes ?? '');
    const [costCentreId, setCostCentreId] = useState(po.cost_centre_id ? String(po.cost_centre_id) : '');
    const [fundingStreamId, setFundingStreamId] = useState(po.funding_stream_id ? String(po.funding_stream_id) : '');
    const [lines, setLines] = useState<LineItem[]>(
        po.lines && po.lines.length > 0 ? po.lines.map(existingToLineItem) : [emptyLine()]
    );
    const [processing, setProcessing] = useState(false);

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
        setProcessing(true);
        router.put(`/finance/purchase-orders/${po.id}`, {
            vendor_id: vendorId,
            order_date: orderDate,
            expected_date: expectedDate || null,
            notes: notes || null,
            cost_centre_id: costCentreId || null,
            funding_stream_id: fundingStreamId || null,
            lines: lines.map((l) => ({
                description: l.description,
                quantity: parseFloat(l.quantity) || 0,
                unit_price: parseFloat(l.unit_price) || 0,
                gst_rate: parseFloat(l.gst_rate) || 15,
                account_id: l.account_id || null,
            })),
        }, {
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Finance', href: '/finance/dashboard' }, { title: 'Purchase Orders', href: '/finance/purchase-orders' }, { title: po.po_number, href: `/finance/purchase-orders/${po.id}` }, { title: 'Edit', href: '#' }]}>
            <Head title={`Edit ${po.po_number}`} />
            <div className="space-y-4 p-4">
                <h1 className="text-xl font-semibold">Edit {po.po_number}</h1>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Order Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Vendor *</Label>
                                <Select value={vendorId} onValueChange={setVendorId}>
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
                                {errors?.vendor_id && <p className="text-sm text-red-600">{errors.vendor_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label>Order Date *</Label>
                                <Input type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} />
                                {errors?.order_date && <p className="text-sm text-red-600">{errors.order_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label>Expected Date</Label>
                                <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
                            </div>

                            <div className="space-y-1">
                                <Label>Cost Centre</Label>
                                <Select value={costCentreId || 'none'} onValueChange={(v) => setCostCentreId(v === 'none' ? '' : v)}>
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
                                <Select value={fundingStreamId || 'none'} onValueChange={(v) => setFundingStreamId(v === 'none' ? '' : v)}>
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
                                <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} placeholder="Optional notes..." />
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
                            {errors?.lines && <p className="text-sm text-red-600">{errors.lines}</p>}

                            <div className="hidden md:grid md:grid-cols-12 md:gap-2 md:text-xs md:font-medium md:text-slate-500">
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
                                            {errors?.[`lines.${idx}.description`] && (
                                                <p className="text-xs text-red-600">{errors[`lines.${idx}.description`]}</p>
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
                                            <div className="text-xs text-slate-500">GST: {formatNZD(c.gst)}</div>
                                        </div>
                                        <div className="md:col-span-1 md:text-center">
                                            {lines.length > 1 && (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(idx)} className="text-red-600 hover:text-red-700">
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
                                            <span className="text-slate-500">Subtotal</span>
                                            <span>{formatNZD(totals.subtotal)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">GST</span>
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
                        <Button type="button" variant="outline" onClick={() => router.get(`/finance/purchase-orders/${po.id}`)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Update Purchase Order
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
