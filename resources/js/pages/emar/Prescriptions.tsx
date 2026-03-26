import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, FileText, PenTool, Shield } from 'lucide-react';

type Props = {
    orders: { data: any[]; links: any };
    pendingCountersigns: number;
    covertAuthorisations: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    filters: { status?: string; client_id?: string };
};

const orderStatusColors: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-700',
    confirmed: 'bg-blue-100 text-blue-700',
    dispensed: 'bg-green-100 text-green-700',
    cancelled: 'bg-gray-100 text-gray-600',
    expired: 'bg-red-100 text-red-700',
};

export default function Prescriptions({ orders, pendingCountersigns, covertAuthorisations, clients, filters }: Props) {
    function updateFilter(key: string, value: string) {
        router.get('/emar/prescriptions', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Prescriptions" />
            <PageHeader title="Prescriptions & Orders" description="Prescriber orders, verbal/telephone orders, countersignatures, and covert administration authorisations." backHref="/emar" />
            <PageShell>
                {/* Alerts */}
                {pendingCountersigns > 0 && (
                    <Card className="mb-6 border-amber-200 dark:border-amber-800">
                        <CardContent className="flex items-center gap-3 p-4">
                            <PenTool className="h-5 w-5 text-amber-600" />
                            <span className="text-sm font-medium text-amber-700 dark:text-amber-400">{pendingCountersigns} order(s) awaiting prescriber countersignature</span>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="mb-6 flex flex-wrap gap-3">
                    <Select value={filters.status ?? ''} onValueChange={(v) => updateFilter('status', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="confirmed">Confirmed</SelectItem>
                            <SelectItem value="dispensed">Dispensed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.client_id ?? ''} onValueChange={(v) => updateFilter('client_id', v)}>
                        <SelectTrigger className="w-56"><SelectValue placeholder="All clients" /></SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Tabs defaultValue="orders">
                    <TabsList className="mb-4">
                        <TabsTrigger value="orders"><FileText className="mr-1 h-3.5 w-3.5" /> Prescriber Orders</TabsTrigger>
                        <TabsTrigger value="covert"><Shield className="mr-1 h-3.5 w-3.5" /> Covert Authorisations ({covertAuthorisations.length})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="orders">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Prescriber</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Countersign</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.data.map((o: any) => (
                                            <tr key={o.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{o.order_date ? new Date(o.order_date).toLocaleDateString('en-NZ') : '—'}</td>
                                                <td className="p-3">{o.client?.last_name}, {o.client?.first_name}</td>
                                                <td className="p-3 font-medium">{o.medication_name}</td>
                                                <td className="p-3"><Badge variant="outline" className="text-xs">{o.order_type}</Badge></td>
                                                <td className="p-3 text-xs">
                                                    {o.prescriber_name}
                                                    {o.prescriber_registration && <span className="ml-1 text-muted-foreground">({o.prescriber_registration})</span>}
                                                </td>
                                                <td className="p-3">
                                                    <Badge className={`text-xs ${orderStatusColors[o.status] ?? ''}`}>{o.status}</Badge>
                                                </td>
                                                <td className="p-3">
                                                    {o.requires_countersign && !o.countersigned_at ? (
                                                        <Badge variant="destructive" className="text-[10px]">Awaiting</Badge>
                                                    ) : o.countersigned_at ? (
                                                        <span className="text-xs text-green-600">Done</span>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">N/A</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {orders.data.length === 0 && (
                                            <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No prescriber orders found.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="covert">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Active Covert Administration Authorisations</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Authorised By</th>
                                            <th className="p-3 text-left font-medium">Method</th>
                                            <th className="p-3 text-left font-medium">Review Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {covertAuthorisations.map((c: any) => (
                                            <tr key={c.id} className="border-b last:border-0">
                                                <td className="p-3">{c.client?.last_name}, {c.client?.first_name}</td>
                                                <td className="p-3 font-medium">{c.medication?.name}</td>
                                                <td className="p-3 text-xs">{c.authorised_by_name}</td>
                                                <td className="p-3 text-xs">{c.administration_method ?? '—'}</td>
                                                <td className="p-3 text-xs">
                                                    {c.review_date ? new Date(c.review_date).toLocaleDateString('en-NZ') : '—'}
                                                    {c.review_date && new Date(c.review_date) < new Date() && (
                                                        <Badge variant="destructive" className="ml-1 text-[10px]">Overdue</Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {covertAuthorisations.length === 0 && (
                                            <tr><td colSpan={5} className="p-6 text-center text-muted-foreground">No active covert authorisations.</td></tr>
                                        )}
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
