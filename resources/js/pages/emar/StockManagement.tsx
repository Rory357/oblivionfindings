import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Package, ShoppingCart, Truck } from 'lucide-react';

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
};

export default function StockManagement({ stockItems, lowStockCount, pharmacyOrders }: Props) {
    const lowStock = stockItems.filter((s) => s.is_low);
    const normalStock = stockItems.filter((s) => !s.is_low);

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
                                            </tr>
                                        ))}
                                        {pharmacyOrders.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-muted-foreground">No pending pharmacy orders.</td></tr>}
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
