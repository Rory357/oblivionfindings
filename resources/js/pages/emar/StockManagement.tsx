import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowRightLeft, Package, Plus, ShoppingCart, Truck } from 'lucide-react';
import { useState } from 'react';

type StockItem = {
    id: number;
    medication_id: number;
    medication_name: string;
    client_name: string;
    client_id: number;
    on_hand: number;
    unit: string;
    reorder_level: number | null;
    last_counted_at: string | null;
    is_low: boolean;
    controlled: boolean;
};

type Props = {
    stockItems: StockItem[];
    lowStockCount: number;
    pharmacyOrders: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    activeMedications: { id: number; name: string; client_id: number; client?: { first_name: string; last_name: string } }[];
};

export default function StockManagement({ stockItems, lowStockCount, pharmacyOrders, clients, activeMedications }: Props) {
    const lowStock = stockItems.filter((s) => s.is_low);
    const normalStock = stockItems.filter((s) => !s.is_low);

    // New Pharmacy Order dialog
    const [orderOpen, setOrderOpen] = useState(false);
    const orderForm = useForm({
        client_id: '',
        client_medication_id: '',
        pharmacy_name: '',
        pharmacy_phone: '',
        order_type: '',
        quantity_ordered: '',
        order_notes: '',
    });

    const filteredMedications = activeMedications.filter(
        (m) => !orderForm.data.client_id || m.client_id === Number(orderForm.data.client_id),
    );

    function submitOrder(e: React.FormEvent) {
        e.preventDefault();
        orderForm.post('/emar/stock/pharmacy-orders', {
            onSuccess: () => {
                setOrderOpen(false);
                orderForm.reset();
            },
        });
    }

    // Advance Order dialog
    const [advanceOpen, setAdvanceOpen] = useState(false);
    const [advanceOrderId, setAdvanceOrderId] = useState<number | null>(null);
    const advanceForm = useForm({
        quantity_received: '',
        batch_number: '',
        batch_expiry: '',
    });

    function openAdvance(orderId: number) {
        setAdvanceOrderId(orderId);
        advanceForm.reset();
        setAdvanceOpen(true);
    }

    function submitAdvance(e: React.FormEvent) {
        e.preventDefault();
        if (advanceOrderId === null) return;
        advanceForm.post(`/emar/stock/pharmacy-orders/${advanceOrderId}/advance`, {
            onSuccess: () => {
                setAdvanceOpen(false);
                advanceForm.reset();
                setAdvanceOrderId(null);
            },
        });
    }

    // Receive Stock dialog
    const [receiveOpen, setReceiveOpen] = useState(false);
    const receiveForm = useForm({
        client_medication_id: '',
        quantity: '',
        notes: '',
    });

    function submitReceive(e: React.FormEvent) {
        e.preventDefault();
        receiveForm.post('/emar/stock/receive', {
            onSuccess: () => {
                setReceiveOpen(false);
                receiveForm.reset();
            },
        });
    }

    // Stock Adjustment dialog
    const [adjustOpen, setAdjustOpen] = useState(false);
    const adjustForm = useForm({
        client_medication_id: '',
        new_quantity: '',
        reason: '',
    });

    function submitAdjust(e: React.FormEvent) {
        e.preventDefault();
        adjustForm.post('/emar/stock/adjust', {
            onSuccess: () => {
                setAdjustOpen(false);
                adjustForm.reset();
            },
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Stock Management" />
            <PageHeader title="Stock Management" description="Medication stock levels, reorder alerts, and pharmacy orders." backHref="/emar" />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-900/40"><Package className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{stockItems.length}</p><p className="text-xs text-muted-foreground">Total Items Tracked</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-900/40"><AlertTriangle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{lowStockCount}</p><p className="text-xs text-muted-foreground">Low Stock Alerts</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/40"><Truck className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{pharmacyOrders.length}</p><p className="text-xs text-muted-foreground">Pending Orders</p></div>
                        </CardContent>
                    </Card>
                </div>

                {/* Action Buttons */}
                <div className="mb-4 flex flex-wrap gap-2 justify-end">
                    {/* New Pharmacy Order */}
                    <Dialog open={orderOpen} onOpenChange={setOrderOpen}>
                        <DialogTrigger asChild>
                            <Button><Plus className="mr-2 h-4 w-4" /> New Pharmacy Order</Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-lg">
                            <form onSubmit={submitOrder}>
                                <DialogHeader>
                                    <DialogTitle>New Pharmacy Order</DialogTitle>
                                    <DialogDescription>Place a medication order with a pharmacy.</DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Client</Label>
                                        <Select value={orderForm.data.client_id} onValueChange={(v) => { orderForm.setData('client_id', v); orderForm.setData('client_medication_id', ''); }}>
                                            <SelectTrigger><SelectValue placeholder="Select client..." /></SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>{c.last_name}, {c.first_name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {orderForm.errors.client_id && <p className="text-sm text-red-600">{orderForm.errors.client_id}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select value={orderForm.data.client_medication_id} onValueChange={(v) => orderForm.setData('client_medication_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select medication..." /></SelectTrigger>
                                            <SelectContent>
                                                {filteredMedications.map((m) => (
                                                    <SelectItem key={m.id} value={String(m.id)}>{m.name}{m.client ? ` (${m.client.first_name} ${m.client.last_name})` : ''}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {orderForm.errors.client_medication_id && <p className="text-sm text-red-600">{orderForm.errors.client_medication_id}</p>}
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Pharmacy Name</Label>
                                            <Input value={orderForm.data.pharmacy_name} onChange={(e) => orderForm.setData('pharmacy_name', e.target.value)} placeholder="Pharmacy name" />
                                            {orderForm.errors.pharmacy_name && <p className="text-sm text-red-600">{orderForm.errors.pharmacy_name}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Pharmacy Phone</Label>
                                            <Input value={orderForm.data.pharmacy_phone} onChange={(e) => orderForm.setData('pharmacy_phone', e.target.value)} placeholder="Phone number" />
                                            {orderForm.errors.pharmacy_phone && <p className="text-sm text-red-600">{orderForm.errors.pharmacy_phone}</p>}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Order Type</Label>
                                            <Select value={orderForm.data.order_type} onValueChange={(v) => orderForm.setData('order_type', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select type..." /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="routine">Routine</SelectItem>
                                                    <SelectItem value="urgent">Urgent</SelectItem>
                                                    <SelectItem value="initial">Initial</SelectItem>
                                                    <SelectItem value="repeat">Repeat</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {orderForm.errors.order_type && <p className="text-sm text-red-600">{orderForm.errors.order_type}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Quantity Ordered</Label>
                                            <Input type="number" min={1} value={orderForm.data.quantity_ordered} onChange={(e) => orderForm.setData('quantity_ordered', e.target.value)} placeholder="Qty" />
                                            {orderForm.errors.quantity_ordered && <p className="text-sm text-red-600">{orderForm.errors.quantity_ordered}</p>}
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Order Notes</Label>
                                        <Textarea value={orderForm.data.order_notes} onChange={(e) => orderForm.setData('order_notes', e.target.value)} rows={3} placeholder="Any special instructions..." />
                                        {orderForm.errors.order_notes && <p className="text-sm text-red-600">{orderForm.errors.order_notes}</p>}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="submit" disabled={orderForm.processing}>{orderForm.processing ? 'Placing Order...' : 'Place Order'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Receive Stock */}
                    <Dialog open={receiveOpen} onOpenChange={setReceiveOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline"><Truck className="mr-2 h-4 w-4" /> Receive Stock</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submitReceive}>
                                <DialogHeader>
                                    <DialogTitle>Receive Stock</DialogTitle>
                                    <DialogDescription>Record incoming medication stock.</DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select value={receiveForm.data.client_medication_id} onValueChange={(v) => receiveForm.setData('client_medication_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select medication..." /></SelectTrigger>
                                            <SelectContent>
                                                {activeMedications.map((m) => (
                                                    <SelectItem key={m.id} value={String(m.id)}>{m.name}{m.client ? ` (${m.client.first_name} ${m.client.last_name})` : ''}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {receiveForm.errors.client_medication_id && <p className="text-sm text-red-600">{receiveForm.errors.client_medication_id}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Quantity</Label>
                                        <Input type="number" min={1} value={receiveForm.data.quantity} onChange={(e) => receiveForm.setData('quantity', e.target.value)} placeholder="Quantity received" />
                                        {receiveForm.errors.quantity && <p className="text-sm text-red-600">{receiveForm.errors.quantity}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Notes</Label>
                                        <Input value={receiveForm.data.notes} onChange={(e) => receiveForm.setData('notes', e.target.value)} placeholder="Optional notes" />
                                        {receiveForm.errors.notes && <p className="text-sm text-red-600">{receiveForm.errors.notes}</p>}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="submit" disabled={receiveForm.processing}>{receiveForm.processing ? 'Recording...' : 'Receive Stock'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Stock Adjustment */}
                    <Dialog open={adjustOpen} onOpenChange={setAdjustOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline"><ArrowRightLeft className="mr-2 h-4 w-4" /> Stock Adjustment</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submitAdjust}>
                                <DialogHeader>
                                    <DialogTitle>Stock Adjustment</DialogTitle>
                                    <DialogDescription>Manually adjust stock levels with a required reason.</DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select value={adjustForm.data.client_medication_id} onValueChange={(v) => adjustForm.setData('client_medication_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select medication..." /></SelectTrigger>
                                            <SelectContent>
                                                {activeMedications.map((m) => (
                                                    <SelectItem key={m.id} value={String(m.id)}>{m.name}{m.client ? ` (${m.client.first_name} ${m.client.last_name})` : ''}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {adjustForm.errors.client_medication_id && <p className="text-sm text-red-600">{adjustForm.errors.client_medication_id}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>New Quantity</Label>
                                        <Input type="number" min={0} value={adjustForm.data.new_quantity} onChange={(e) => adjustForm.setData('new_quantity', e.target.value)} placeholder="Corrected stock count" />
                                        {adjustForm.errors.new_quantity && <p className="text-sm text-red-600">{adjustForm.errors.new_quantity}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Reason <span className="text-red-500">*</span></Label>
                                        <Textarea value={adjustForm.data.reason} onChange={(e) => adjustForm.setData('reason', e.target.value)} rows={3} placeholder="Explain why the stock count is being adjusted..." required />
                                        {adjustForm.errors.reason && <p className="text-sm text-red-600">{adjustForm.errors.reason}</p>}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="submit" disabled={adjustForm.processing}>{adjustForm.processing ? 'Adjusting...' : 'Adjust Stock'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Advance Order Dialog (not trigger-based, opened programmatically) */}
                <Dialog open={advanceOpen} onOpenChange={setAdvanceOpen}>
                    <DialogContent>
                        <form onSubmit={submitAdvance}>
                            <DialogHeader>
                                <DialogTitle>Advance Pharmacy Order</DialogTitle>
                                <DialogDescription>Record delivery details for this pharmacy order.</DialogDescription>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label>Quantity Received</Label>
                                    <Input type="number" min={1} value={advanceForm.data.quantity_received} onChange={(e) => advanceForm.setData('quantity_received', e.target.value)} placeholder="Qty received" />
                                    {advanceForm.errors.quantity_received && <p className="text-sm text-red-600">{advanceForm.errors.quantity_received}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Batch Number</Label>
                                    <Input value={advanceForm.data.batch_number} onChange={(e) => advanceForm.setData('batch_number', e.target.value)} placeholder="Batch number" />
                                    {advanceForm.errors.batch_number && <p className="text-sm text-red-600">{advanceForm.errors.batch_number}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Batch Expiry</Label>
                                    <Input type="date" value={advanceForm.data.batch_expiry} onChange={(e) => advanceForm.setData('batch_expiry', e.target.value)} />
                                    {advanceForm.errors.batch_expiry && <p className="text-sm text-red-600">{advanceForm.errors.batch_expiry}</p>}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={advanceForm.processing}>{advanceForm.processing ? 'Advancing...' : 'Advance Order'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Tabs defaultValue={lowStock.length > 0 ? 'low' : 'all'}>
                    <TabsList className="mb-4">
                        {lowStock.length > 0 && <TabsTrigger value="low"><AlertTriangle className="mr-1 h-3.5 w-3.5" /> Low Stock ({lowStock.length})</TabsTrigger>}
                        <TabsTrigger value="all"><Package className="mr-1 h-3.5 w-3.5" /> All Stock</TabsTrigger>
                        <TabsTrigger value="orders"><ShoppingCart className="mr-1 h-3.5 w-3.5" /> Pharmacy Orders</TabsTrigger>
                    </TabsList>

                    {lowStock.length > 0 && (
                        <TabsContent value="low">
                            <Card className="border-red-200 dark:border-red-800">
                                <CardContent className="p-0">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-red-50 dark:bg-red-900/10">
                                                <th className="p-3 text-left font-medium">Medication</th>
                                                <th className="p-3 text-left font-medium">Client</th>
                                                <th className="p-3 text-left font-medium">On Hand</th>
                                                <th className="p-3 text-left font-medium">Reorder Level</th>
                                                <th className="p-3 text-left font-medium">Last Counted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {lowStock.map((s) => (
                                                <tr key={s.id} className="border-b last:border-0">
                                                    <td className="p-3 font-medium">{s.medication_name} {s.controlled && <Badge variant="destructive" className="ml-1 text-[10px]">CD</Badge>}</td>
                                                    <td className="p-3">{s.client_name}</td>
                                                    <td className="p-3 font-mono text-red-600">{s.on_hand} {s.unit}</td>
                                                    <td className="p-3 font-mono">{s.reorder_level} {s.unit}</td>
                                                    <td className="p-3 text-xs">{s.last_counted_at ? new Date(s.last_counted_at).toLocaleDateString('en-NZ') : 'Never'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    )}

                    <TabsContent value="all">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">On Hand</th>
                                            <th className="p-3 text-left font-medium">Reorder</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Last Counted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {stockItems.map((s) => (
                                            <tr key={s.id} className="border-b last:border-0">
                                                <td className="p-3 font-medium">{s.medication_name} {s.controlled && <Badge variant="destructive" className="ml-1 text-[10px]">CD</Badge>}</td>
                                                <td className="p-3">{s.client_name}</td>
                                                <td className="p-3 font-mono">{s.on_hand} {s.unit}</td>
                                                <td className="p-3 font-mono">{s.reorder_level ?? '—'}</td>
                                                <td className="p-3">{s.is_low ? <Badge variant="destructive" className="text-xs">Low</Badge> : <Badge variant="outline" className="text-xs">OK</Badge>}</td>
                                                <td className="p-3 text-xs">{s.last_counted_at ? new Date(s.last_counted_at).toLocaleDateString('en-NZ') : 'Never'}</td>
                                            </tr>
                                        ))}
                                        {stockItems.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-muted-foreground">No stock records.</td></tr>}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="orders">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Pharmacy</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pharmacyOrders.map((o: any) => (
                                            <tr key={o.id} className="border-b last:border-0">
                                                <td className="p-3">{o.client?.last_name}, {o.client?.first_name}</td>
                                                <td className="p-3 font-medium">{o.medication?.name ?? '—'}</td>
                                                <td className="p-3 text-xs">{o.pharmacy_name}</td>
                                                <td className="p-3"><Badge variant="outline" className="text-xs">{o.order_type}</Badge></td>
                                                <td className="p-3"><Badge variant="secondary" className="text-xs">{o.status}</Badge></td>
                                                <td className="p-3 font-mono">{o.quantity_ordered ?? '—'}</td>
                                                <td className="p-3">
                                                    <Button size="sm" variant="outline" onClick={() => openAdvance(o.id)}>Advance</Button>
                                                </td>
                                            </tr>
                                        ))}
                                        {pharmacyOrders.length === 0 && <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No pending pharmacy orders.</td></tr>}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
