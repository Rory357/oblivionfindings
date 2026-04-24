import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import ScheduledStockCounts from '@/components/medications/ScheduledStockCounts';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { submitEmarMutation } from '@/lib/emar-offline';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    Calendar,
    Clock,
    Package,
    Pencil,
    Plus,
    ShoppingCart,
    Truck,
} from 'lucide-react';
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
    reorder_quantity: number | null;
    last_counted_at: string | null;
    is_low: boolean;
    controlled: boolean;
    expiry_date: string | null;
    batch_number: string | null;
    supplier_name: string | null;
    is_expired: boolean;
    is_expiring_soon: boolean;
    is_expiring_90: boolean;
    scan_verification?: MedicationScanVerification | null;
};

type Props = {
    stockItems: StockItem[];
    lowStockCount: number;
    expiringCount: number;
    expiredCount: number;
    pharmacyOrders: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    activeMedications: {
        id: number;
        name: string;
        client_id: number;
        client?: { first_name: string; last_name: string };
        scan_verification?: MedicationScanVerification | null;
    }[];
    witnesses: Array<{ id: number; name: string }>;
};

function ExpiryBadge({ item }: { item: StockItem }) {
    if (!item.expiry_date) return null;
    if (item.is_expired) {
        return (
            <Badge variant="destructive" className="text-[10px]">
                Expired
            </Badge>
        );
    }
    if (item.is_expiring_soon) {
        return (
            <Badge className="bg-status-warning text-[10px] text-white">
                Expires &lt;30d
            </Badge>
        );
    }
    if (item.is_expiring_90) {
        return (
            <Badge className="bg-status-warning-bg text-[10px] text-status-warning">
                Expires &lt;90d
            </Badge>
        );
    }
    return null;
}

function formatDate(dateStr: string | null) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-NZ');
}

export default function StockManagement({
    stockItems,
    lowStockCount,
    expiringCount,
    expiredCount,
    pharmacyOrders,
    clients,
    activeMedications,
    witnesses,
}: Props) {
    const [activeTab, setActiveTab] = useState<string>('all');
    const lowStock = stockItems.filter((s) => s.is_low);
    const expiringSoon = stockItems.filter((s) => s.is_expiring_soon);
    const expiredItems = stockItems.filter((s) => s.is_expired);

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
        batch_number: '',
        batch_expiry: '',
    });

    const filteredMedications = activeMedications.filter(
        (m) =>
            !orderForm.data.client_id ||
            m.client_id === Number(orderForm.data.client_id),
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
        advanceForm.post(
            `/emar/stock/pharmacy-orders/${advanceOrderId}/advance`,
            {
                onSuccess: () => {
                    setAdvanceOpen(false);
                    advanceForm.reset();
                    setAdvanceOrderId(null);
                },
            },
        );
    }

    // Receive Stock dialog
    const [receiveOpen, setReceiveOpen] = useState(false);
    const receiveForm = useForm({
        client_medication_id: '',
        quantity: '',
        notes: '',
        batch_number: '',
        expiry_date: '',
        scan_code: '',
        scan_source: 'manual' as 'manual' | 'scanner',
        scan_verified: false,
        scan_match_source: '',
    });
    const [receiveScanCapture, setReceiveScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [receivingStock, setReceivingStock] = useState(false);
    const selectedReceiveMedication =
        activeMedications.find(
            (medication) =>
                String(medication.id) === receiveForm.data.client_medication_id,
        ) ?? null;

    async function submitReceive(e: React.FormEvent) {
        e.preventDefault();
        receiveForm.clearErrors();

        if (
            selectedReceiveMedication?.scan_verification &&
            !hasVerifiedMedicationScan(receiveScanCapture)
        ) {
            receiveForm.setError(
                'scan_code',
                'Verify the medication code before receiving stock.',
            );
            return;
        }

        setReceivingStock(true);

        try {
            const result = await submitEmarMutation(
                '/emar/stock/receive',
                {
                    ...receiveForm.data,
                    quantity: Number(receiveForm.data.quantity),
                    expiry_date: receiveForm.data.expiry_date || null,
                    notes: receiveForm.data.notes || null,
                    batch_number: receiveForm.data.batch_number || null,
                    ...toMedicationScanPayload(receiveScanCapture),
                },
                {
                    successMessage: 'Stock receipt recorded.',
                    queuedMessage:
                        'Stock receipt saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            setReceiveOpen(false);
            receiveForm.reset();
            setReceiveScanCapture(emptyMedicationScanCapture());

            if (result.status !== 'queued') {
                router.reload({
                    only: [
                        'stockItems',
                        'lowStockCount',
                        'expiringCount',
                        'expiredCount',
                        'pharmacyOrders',
                    ],
                });
            }
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (
                        receiveForm.setError as (
                            field: string,
                            value: string,
                        ) => void
                    )(field, value),
                'Failed to record stock receipt.',
            );
        } finally {
            setReceivingStock(false);
        }
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

    // Edit Stock dialog
    const [editOpen, setEditOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<StockItem | null>(null);
    const editForm = useForm({
        reorder_level: '',
        reorder_quantity: '',
        expiry_date: '',
        batch_number: '',
        supplier_name: '',
    });

    function openEdit(item: StockItem) {
        setEditingItem(item);
        editForm.setData({
            reorder_level:
                item.reorder_level !== null ? String(item.reorder_level) : '',
            reorder_quantity:
                item.reorder_quantity !== null
                    ? String(item.reorder_quantity)
                    : '',
            expiry_date: item.expiry_date ?? '',
            batch_number: item.batch_number ?? '',
            supplier_name: item.supplier_name ?? '',
        });
        setEditOpen(true);
    }

    function submitEdit(e: React.FormEvent) {
        e.preventDefault();
        if (!editingItem) return;
        editForm.patch(`/emar/stock/${editingItem.id}`, {
            onSuccess: () => {
                setEditOpen(false);
                setEditingItem(null);
                editForm.reset();
            },
        });
    }

    // Filtered items for tabs
    function getFilteredItems() {
        switch (activeTab) {
            case 'low':
                return lowStock;
            case 'expiring':
                return expiringSoon;
            case 'expired':
                return expiredItems;
            default:
                return stockItems;
        }
    }

    const displayItems = getFilteredItems();
    const refreshStockPanels = () =>
        router.reload({
            only: [
                'stockItems',
                'lowStockCount',
                'expiringCount',
                'expiredCount',
                'pharmacyOrders',
            ],
        });

    return (
        <AppLayout>
            <Head title="eMAR - Stock Management" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Stock Management"
                    description="Medication stock levels, reorder alerts, and pharmacy orders"
                    icon={<Package className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Alert Summary Cards */}
                <div className="mb-6 grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-info-bg text-status-info dark:bg-status-info">
                                <Package className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stockItems.length}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Total Items Tracked
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning dark:bg-status-warning">
                                <AlertTriangle className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {lowStockCount}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Low Stock Items
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning dark:bg-status-warning">
                                <Clock className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {expiringCount}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Expiring in 30 Days
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-critical-bg text-status-critical dark:bg-status-critical">
                                <Calendar className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {expiredCount}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Expired Items
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Action Buttons */}
                <div className="mb-4 flex flex-wrap justify-end gap-2">
                    {/* New Pharmacy Order */}
                    <Dialog open={orderOpen} onOpenChange={setOrderOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> New Pharmacy
                                Order
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-lg">
                            <form onSubmit={submitOrder}>
                                <DialogHeader>
                                    <DialogTitle>
                                        New Pharmacy Order
                                    </DialogTitle>
                                    <DialogDescription>
                                        Place a medication order with a
                                        pharmacy.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Client</Label>
                                        <Select
                                            value={orderForm.data.client_id}
                                            onValueChange={(v) => {
                                                orderForm.setData(
                                                    'client_id',
                                                    v,
                                                );
                                                orderForm.setData(
                                                    'client_medication_id',
                                                    '',
                                                );
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select client..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem
                                                        key={c.id}
                                                        value={String(c.id)}
                                                    >
                                                        {c.last_name},{' '}
                                                        {c.first_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {orderForm.errors.client_id && (
                                            <p className="text-sm text-status-critical">
                                                {orderForm.errors.client_id}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select
                                            value={
                                                orderForm.data
                                                    .client_medication_id
                                            }
                                            onValueChange={(v) =>
                                                orderForm.setData(
                                                    'client_medication_id',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select medication..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {filteredMedications.map(
                                                    (m) => (
                                                        <SelectItem
                                                            key={m.id}
                                                            value={String(m.id)}
                                                        >
                                                            {m.name}
                                                            {m.client
                                                                ? ` (${m.client.first_name} ${m.client.last_name})`
                                                                : ''}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {orderForm.errors
                                            .client_medication_id && (
                                            <p className="text-sm text-status-critical">
                                                {
                                                    orderForm.errors
                                                        .client_medication_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Pharmacy Name</Label>
                                            <Input
                                                value={
                                                    orderForm.data.pharmacy_name
                                                }
                                                onChange={(e) =>
                                                    orderForm.setData(
                                                        'pharmacy_name',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Pharmacy name"
                                            />
                                            {orderForm.errors.pharmacy_name && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .pharmacy_name
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Pharmacy Phone</Label>
                                            <Input
                                                value={
                                                    orderForm.data
                                                        .pharmacy_phone
                                                }
                                                onChange={(e) =>
                                                    orderForm.setData(
                                                        'pharmacy_phone',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Phone number"
                                            />
                                            {orderForm.errors
                                                .pharmacy_phone && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .pharmacy_phone
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Order Type</Label>
                                            <Select
                                                value={
                                                    orderForm.data.order_type
                                                }
                                                onValueChange={(v) =>
                                                    orderForm.setData(
                                                        'order_type',
                                                        v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select type..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="routine">
                                                        Routine
                                                    </SelectItem>
                                                    <SelectItem value="urgent">
                                                        Urgent
                                                    </SelectItem>
                                                    <SelectItem value="initial">
                                                        Initial
                                                    </SelectItem>
                                                    <SelectItem value="repeat">
                                                        Repeat
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {orderForm.errors.order_type && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .order_type
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Quantity Ordered</Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={
                                                    orderForm.data
                                                        .quantity_ordered
                                                }
                                                onChange={(e) =>
                                                    orderForm.setData(
                                                        'quantity_ordered',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Qty"
                                            />
                                            {orderForm.errors
                                                .quantity_ordered && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .quantity_ordered
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Batch Number</Label>
                                            <Input
                                                value={
                                                    orderForm.data.batch_number
                                                }
                                                onChange={(e) =>
                                                    orderForm.setData(
                                                        'batch_number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Batch number"
                                            />
                                            {orderForm.errors.batch_number && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .batch_number
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Expiry Date</Label>
                                            <Input
                                                type="date"
                                                value={
                                                    orderForm.data.batch_expiry
                                                }
                                                onChange={(e) =>
                                                    orderForm.setData(
                                                        'batch_expiry',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {orderForm.errors.batch_expiry && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        orderForm.errors
                                                            .batch_expiry
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Order Notes</Label>
                                        <Textarea
                                            value={orderForm.data.order_notes}
                                            onChange={(e) =>
                                                orderForm.setData(
                                                    'order_notes',
                                                    e.target.value,
                                                )
                                            }
                                            rows={3}
                                            placeholder="Any special instructions..."
                                        />
                                        {orderForm.errors.order_notes && (
                                            <p className="text-sm text-status-critical">
                                                {orderForm.errors.order_notes}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={orderForm.processing}
                                    >
                                        {orderForm.processing
                                            ? 'Placing Order...'
                                            : 'Place Order'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Receive Stock */}
                    <Dialog
                        open={receiveOpen}
                        onOpenChange={(open) => {
                            setReceiveOpen(open);
                            if (!open) {
                                receiveForm.reset();
                                receiveForm.clearErrors();
                                setReceiveScanCapture(
                                    emptyMedicationScanCapture(),
                                );
                            }
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                <Truck className="mr-2 h-4 w-4" /> Receive Stock
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submitReceive}>
                                <DialogHeader>
                                    <DialogTitle>Receive Stock</DialogTitle>
                                    <DialogDescription>
                                        Record incoming medication stock.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select
                                            value={
                                                receiveForm.data
                                                    .client_medication_id
                                            }
                                            onValueChange={(v) => {
                                                receiveForm.setData(
                                                    'client_medication_id',
                                                    v,
                                                );
                                                receiveForm.clearErrors(
                                                    'client_medication_id',
                                                    'scan_code',
                                                );
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select medication..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {activeMedications.map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={String(m.id)}
                                                    >
                                                        {m.name}
                                                        {m.client
                                                            ? ` (${m.client.first_name} ${m.client.last_name})`
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {receiveForm.errors
                                            .client_medication_id && (
                                            <p className="text-sm text-status-critical">
                                                {
                                                    receiveForm.errors
                                                        .client_medication_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Quantity</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={receiveForm.data.quantity}
                                            onChange={(e) =>
                                                receiveForm.setData(
                                                    'quantity',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Quantity received"
                                        />
                                        {receiveForm.errors.quantity && (
                                            <p className="text-sm text-status-critical">
                                                {receiveForm.errors.quantity}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Batch Number</Label>
                                            <Input
                                                value={
                                                    receiveForm.data
                                                        .batch_number
                                                }
                                                onChange={(e) =>
                                                    receiveForm.setData(
                                                        'batch_number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Batch number"
                                            />
                                            {receiveForm.errors
                                                .batch_number && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        receiveForm.errors
                                                            .batch_number
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Expiry Date</Label>
                                            <Input
                                                type="date"
                                                value={
                                                    receiveForm.data.expiry_date
                                                }
                                                onChange={(e) =>
                                                    receiveForm.setData(
                                                        'expiry_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {receiveForm.errors.expiry_date && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        receiveForm.errors
                                                            .expiry_date
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Notes</Label>
                                        <Input
                                            value={receiveForm.data.notes}
                                            onChange={(e) =>
                                                receiveForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Optional notes"
                                        />
                                        {receiveForm.errors.notes && (
                                            <p className="text-sm text-status-critical">
                                                {receiveForm.errors.notes}
                                            </p>
                                        )}
                                    </div>
                                    <MedicationScanVerificationPanel
                                        clientId={
                                            selectedReceiveMedication
                                                ? selectedReceiveMedication.client_id
                                                : null
                                        }
                                        medicationId={
                                            selectedReceiveMedication?.id ??
                                            null
                                        }
                                        scanVerification={
                                            selectedReceiveMedication?.scan_verification
                                        }
                                        resetKey={`${receiveOpen}-${receiveForm.data.client_medication_id}`}
                                        requirementText="Verification is required before receiving stock."
                                        onChange={(capture) => {
                                            receiveForm.clearErrors(
                                                'scan_code',
                                            );
                                            setReceiveScanCapture(capture);
                                        }}
                                    />
                                    {receiveForm.errors.scan_code && (
                                        <p className="text-sm text-status-critical">
                                            {receiveForm.errors.scan_code}
                                        </p>
                                    )}
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={
                                            receivingStock ||
                                            (!!selectedReceiveMedication?.scan_verification &&
                                                !hasVerifiedMedicationScan(
                                                    receiveScanCapture,
                                                ))
                                        }
                                    >
                                        {receivingStock
                                            ? 'Recording...'
                                            : 'Receive Stock'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Stock Adjustment */}
                    <Dialog open={adjustOpen} onOpenChange={setAdjustOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                <ArrowRightLeft className="mr-2 h-4 w-4" />{' '}
                                Stock Adjustment
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submitAdjust}>
                                <DialogHeader>
                                    <DialogTitle>Stock Adjustment</DialogTitle>
                                    <DialogDescription>
                                        Manually adjust stock levels with a
                                        required reason.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>Medication</Label>
                                        <Select
                                            value={
                                                adjustForm.data
                                                    .client_medication_id
                                            }
                                            onValueChange={(v) =>
                                                adjustForm.setData(
                                                    'client_medication_id',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select medication..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {activeMedications.map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={String(m.id)}
                                                    >
                                                        {m.name}
                                                        {m.client
                                                            ? ` (${m.client.first_name} ${m.client.last_name})`
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {adjustForm.errors
                                            .client_medication_id && (
                                            <p className="text-sm text-status-critical">
                                                {
                                                    adjustForm.errors
                                                        .client_medication_id
                                                }
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>New Quantity</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            value={adjustForm.data.new_quantity}
                                            onChange={(e) =>
                                                adjustForm.setData(
                                                    'new_quantity',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Corrected stock count"
                                        />
                                        {adjustForm.errors.new_quantity && (
                                            <p className="text-sm text-status-critical">
                                                {adjustForm.errors.new_quantity}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>
                                            Reason{' '}
                                            <span className="text-status-critical">
                                                *
                                            </span>
                                        </Label>
                                        <Textarea
                                            value={adjustForm.data.reason}
                                            onChange={(e) =>
                                                adjustForm.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            rows={3}
                                            placeholder="Explain why the stock count is being adjusted..."
                                            required
                                        />
                                        {adjustForm.errors.reason && (
                                            <p className="text-sm text-status-critical">
                                                {adjustForm.errors.reason}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={adjustForm.processing}
                                    >
                                        {adjustForm.processing
                                            ? 'Adjusting...'
                                            : 'Adjust Stock'}
                                    </Button>
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
                                <DialogTitle>
                                    Advance Pharmacy Order
                                </DialogTitle>
                                <DialogDescription>
                                    Record delivery details for this pharmacy
                                    order.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label>Quantity Received</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={
                                            advanceForm.data.quantity_received
                                        }
                                        onChange={(e) =>
                                            advanceForm.setData(
                                                'quantity_received',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Qty received"
                                    />
                                    {advanceForm.errors.quantity_received && (
                                        <p className="text-sm text-status-critical">
                                            {
                                                advanceForm.errors
                                                    .quantity_received
                                            }
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label>Batch Number</Label>
                                    <Input
                                        value={advanceForm.data.batch_number}
                                        onChange={(e) =>
                                            advanceForm.setData(
                                                'batch_number',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Batch number"
                                    />
                                    {advanceForm.errors.batch_number && (
                                        <p className="text-sm text-status-critical">
                                            {advanceForm.errors.batch_number}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label>Batch Expiry</Label>
                                    <Input
                                        type="date"
                                        value={advanceForm.data.batch_expiry}
                                        onChange={(e) =>
                                            advanceForm.setData(
                                                'batch_expiry',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {advanceForm.errors.batch_expiry && (
                                        <p className="text-sm text-status-critical">
                                            {advanceForm.errors.batch_expiry}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={advanceForm.processing}
                                >
                                    {advanceForm.processing
                                        ? 'Advancing...'
                                        : 'Advance Order'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Edit Stock Dialog */}
                <Dialog open={editOpen} onOpenChange={setEditOpen}>
                    <DialogContent>
                        <form onSubmit={submitEdit}>
                            <DialogHeader>
                                <DialogTitle>Edit Stock Details</DialogTitle>
                                <DialogDescription>
                                    Update reorder level, expiry date, batch
                                    number, and supplier for{' '}
                                    {editingItem?.medication_name}.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>Reorder Level</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            value={editForm.data.reorder_level}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'reorder_level',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Reorder when at or below"
                                        />
                                        {editForm.errors.reorder_level && (
                                            <p className="text-sm text-status-critical">
                                                {editForm.errors.reorder_level}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Reorder Quantity</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={
                                                editForm.data.reorder_quantity
                                            }
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'reorder_quantity',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Suggested qty to order"
                                        />
                                        {editForm.errors.reorder_quantity && (
                                            <p className="text-sm text-status-critical">
                                                {
                                                    editForm.errors
                                                        .reorder_quantity
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>Expiry Date</Label>
                                        <Input
                                            type="date"
                                            value={editForm.data.expiry_date}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'expiry_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {editForm.errors.expiry_date && (
                                            <p className="text-sm text-status-critical">
                                                {editForm.errors.expiry_date}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Batch Number</Label>
                                        <Input
                                            value={editForm.data.batch_number}
                                            onChange={(e) =>
                                                editForm.setData(
                                                    'batch_number',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Batch number"
                                        />
                                        {editForm.errors.batch_number && (
                                            <p className="text-sm text-status-critical">
                                                {editForm.errors.batch_number}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label>Supplier Name</Label>
                                    <Input
                                        value={editForm.data.supplier_name}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'supplier_name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Supplier / pharmacy name"
                                    />
                                    {editForm.errors.supplier_name && (
                                        <p className="text-sm text-status-critical">
                                            {editForm.errors.supplier_name}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={editForm.processing}
                                >
                                    {editForm.processing
                                        ? 'Saving...'
                                        : 'Save Changes'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Tabs value={activeTab} onValueChange={setActiveTab}>
                    <TabsList className="mb-4">
                        <TabsTrigger value="all">
                            <Package className="mr-1 h-3.5 w-3.5" /> All Stock
                        </TabsTrigger>
                        {lowStock.length > 0 && (
                            <TabsTrigger value="low">
                                <AlertTriangle className="mr-1 h-3.5 w-3.5" />{' '}
                                Low Stock ({lowStock.length})
                            </TabsTrigger>
                        )}
                        {expiringSoon.length > 0 && (
                            <TabsTrigger value="expiring">
                                <Clock className="mr-1 h-3.5 w-3.5" /> Expiring
                                Soon ({expiringSoon.length})
                            </TabsTrigger>
                        )}
                        {expiredItems.length > 0 && (
                            <TabsTrigger value="expired">
                                <Calendar className="mr-1 h-3.5 w-3.5" />{' '}
                                Expired ({expiredItems.length})
                            </TabsTrigger>
                        )}
                        <TabsTrigger value="orders">
                            <ShoppingCart className="mr-1 h-3.5 w-3.5" />{' '}
                            Pharmacy Orders
                        </TabsTrigger>
                    </TabsList>

                    {/* Stock Table (shared by all/low/expiring/expired tabs) */}
                    {['all', 'low', 'expiring', 'expired'].map((tab) => (
                        <TabsContent key={tab} value={tab}>
                            <Card
                                className={
                                    tab === 'low'
                                        ? 'border-status-warning/30 dark:border-status-warning/30'
                                        : tab === 'expired'
                                          ? 'border-status-critical/30 dark:border-status-critical/30'
                                          : ''
                                }
                            >
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr
                                                    className={`border-b ${tab === 'low' ? 'bg-status-warning-bg dark:bg-status-warning' : tab === 'expired' ? 'bg-status-critical-bg dark:bg-status-critical' : 'bg-muted/50'}`}
                                                >
                                                    <th className="p-3 text-left font-medium">
                                                        Medication
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Client
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Batch #
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Expiry Date
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        On Hand
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Reorder Level
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Status
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Last Counted
                                                    </th>
                                                    <th className="p-3 text-left font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {displayItems.map((s) => (
                                                    <tr
                                                        key={s.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="p-3 font-medium">
                                                            {s.medication_name}
                                                            {s.controlled && (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="ml-1 text-[10px]"
                                                                >
                                                                    CD
                                                                </Badge>
                                                            )}
                                                        </td>
                                                        <td className="p-3">
                                                            {s.client_name}
                                                        </td>
                                                        <td className="p-3 font-mono text-xs">
                                                            {s.batch_number ??
                                                                '—'}
                                                        </td>
                                                        <td className="p-3">
                                                            <span className="text-xs">
                                                                {formatDate(
                                                                    s.expiry_date,
                                                                )}
                                                            </span>
                                                            {s.expiry_date && (
                                                                <span className="ml-1">
                                                                    <ExpiryBadge
                                                                        item={s}
                                                                    />
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td
                                                            className={`p-3 font-mono ${s.is_low ? 'font-semibold text-status-critical' : ''}`}
                                                        >
                                                            {s.on_hand} {s.unit}
                                                        </td>
                                                        <td className="p-3 font-mono">
                                                            {s.reorder_level ??
                                                                '—'}
                                                        </td>
                                                        <td className="space-x-1 p-3">
                                                            {s.is_low && (
                                                                <Badge className="bg-status-warning text-[10px] text-white">
                                                                    Low Stock
                                                                </Badge>
                                                            )}
                                                            {s.is_expired && (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="text-[10px]"
                                                                >
                                                                    Expired
                                                                </Badge>
                                                            )}
                                                            {!s.is_low &&
                                                                !s.is_expired &&
                                                                !s.is_expiring_soon && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        OK
                                                                    </Badge>
                                                                )}
                                                        </td>
                                                        <td className="p-3 text-xs">
                                                            {s.last_counted_at
                                                                ? new Date(
                                                                      s.last_counted_at,
                                                                  ).toLocaleDateString(
                                                                      'en-NZ',
                                                                  )
                                                                : 'Never'}
                                                        </td>
                                                        <td className="p-3">
                                                            <div className="flex items-center gap-1">
                                                                <ScheduledStockCounts
                                                                    clientId={
                                                                        s.client_id
                                                                    }
                                                                    medicationId={
                                                                        s.medication_id
                                                                    }
                                                                    medicationName={
                                                                        s.medication_name
                                                                    }
                                                                    controlledDrug={
                                                                        s.controlled
                                                                    }
                                                                    scanVerification={
                                                                        s.scan_verification
                                                                    }
                                                                    witnesses={
                                                                        witnesses
                                                                    }
                                                                    onUpdate={
                                                                        refreshStockPanels
                                                                    }
                                                                />
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() =>
                                                                        openEdit(
                                                                            s,
                                                                        )
                                                                    }
                                                                >
                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {displayItems.length === 0 && (
                                                    <tr>
                                                        <td
                                                            colSpan={9}
                                                            className="p-6 text-center text-muted-foreground"
                                                        >
                                                            No stock records
                                                            found.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    ))}

                    <TabsContent value="orders">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">
                                                Client
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Medication
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Pharmacy
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Type
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Qty
                                            </th>
                                            <th className="p-3 text-left font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pharmacyOrders.map((o: any) => (
                                            <tr
                                                key={o.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="p-3">
                                                    {o.client?.last_name},{' '}
                                                    {o.client?.first_name}
                                                </td>
                                                <td className="p-3 font-medium">
                                                    {o.medication?.name ?? '—'}
                                                </td>
                                                <td className="p-3 text-xs">
                                                    {o.pharmacy_name}
                                                </td>
                                                <td className="p-3">
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs"
                                                    >
                                                        {o.order_type}
                                                    </Badge>
                                                </td>
                                                <td className="p-3">
                                                    <Badge
                                                        variant="secondary"
                                                        className="text-xs"
                                                    >
                                                        {o.status}
                                                    </Badge>
                                                </td>
                                                <td className="p-3 font-mono">
                                                    {o.quantity_ordered ?? '—'}
                                                </td>
                                                <td className="p-3">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            openAdvance(o.id)
                                                        }
                                                    >
                                                        Advance
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                        {pharmacyOrders.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={7}
                                                    className="p-6 text-center text-muted-foreground"
                                                >
                                                    No pending pharmacy orders.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
