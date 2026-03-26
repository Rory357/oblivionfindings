import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Lock, Package, Trash2 } from 'lucide-react';

type Props = {
    medications: any[];
    recentEntries: any[];
    discrepancies: any[];
    destructions: any[];
};

export default function ControlledDrugs({ medications, recentEntries, discrepancies, destructions }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - Controlled Drugs" />
            <PageHeader title="Controlled Drug Register" description="Controlled substance registers, balance tracking, and discrepancy management." backHref="/emar" />
            <PageShell>
                {/* Discrepancy Alert */}
                {discrepancies.length > 0 && (
                    <Card className="mb-6 border-red-200 dark:border-red-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-red-700 dark:text-red-400">
                                <AlertTriangle className="h-4 w-4" /> Active Discrepancies ({discrepancies.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {discrepancies.map((d: any) => (
                                    <div key={d.id} className="flex items-center justify-between p-3">
                                        <div>
                                            <span className="font-medium">{d.client?.last_name}, {d.client?.first_name}</span>
                                            <span className="mx-2 text-muted-foreground">—</span>
                                            <span className="text-sm">{d.medication?.name}</span>
                                        </div>
                                        <Badge variant="destructive">{d.status}</Badge>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Tabs defaultValue="register">
                    <TabsList className="mb-4">
                        <TabsTrigger value="register"><Lock className="mr-1 h-3.5 w-3.5" /> Register</TabsTrigger>
                        <TabsTrigger value="entries"><Package className="mr-1 h-3.5 w-3.5" /> Recent Entries</TabsTrigger>
                        <TabsTrigger value="destructions"><Trash2 className="mr-1 h-3.5 w-3.5" /> Destructions</TabsTrigger>
                    </TabsList>

                    {/* Register Tab */}
                    <TabsContent value="register">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">On Hand</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {medications.map((m: any) => (
                                            <tr key={m.id} className="border-b last:border-0">
                                                <td className="p-3">{m.client?.last_name}, {m.client?.first_name}</td>
                                                <td className="p-3 font-medium">{m.name}</td>
                                                <td className="p-3">
                                                    <span className="font-mono text-sm">{m.stock?.on_hand ?? '—'}</span>
                                                    {m.stock?.unit && <span className="ml-1 text-xs text-muted-foreground">{m.stock.unit}</span>}
                                                </td>
                                                <td className="p-3">
                                                    {m.stock?.reorder_level && m.stock?.on_hand <= m.stock.reorder_level ? (
                                                        <Badge variant="destructive" className="text-xs">Low Stock</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-xs">OK</Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {medications.length === 0 && (
                                            <tr><td colSpan={4} className="p-6 text-center text-muted-foreground">No active controlled drugs.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Recent Entries Tab */}
                    <TabsContent value="entries">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date/Time</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Balance</th>
                                            <th className="p-3 text-left font-medium">Recorded By</th>
                                            <th className="p-3 text-left font-medium">Witness</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentEntries.map((e: any) => (
                                            <tr key={e.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { dateStyle: 'short', timeStyle: 'short' }) : '—'}</td>
                                                <td className="p-3">{e.client?.last_name}, {e.client?.first_name}</td>
                                                <td className="p-3 font-medium">{e.medication?.name}</td>
                                                <td className="p-3"><Badge variant="outline" className="text-xs">{e.entry_type}</Badge></td>
                                                <td className="p-3 font-mono">{e.quantity}</td>
                                                <td className="p-3 font-mono">{e.on_hand_after}</td>
                                                <td className="p-3 text-xs">{e.recorded_by?.name ?? '—'}</td>
                                                <td className="p-3 text-xs">{e.witnessed_by?.name ?? '—'}</td>
                                            </tr>
                                        ))}
                                        {recentEntries.length === 0 && (
                                            <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No recent entries.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Destructions Tab */}
                    <TabsContent value="destructions">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Reason</th>
                                            <th className="p-3 text-left font-medium">Destroyed By</th>
                                            <th className="p-3 text-left font-medium">Witness</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {destructions.map((d: any) => (
                                            <tr key={d.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                                <td className="p-3">{d.client?.last_name}, {d.client?.first_name}</td>
                                                <td className="p-3 font-medium">{d.medication_name}</td>
                                                <td className="p-3 font-mono">{d.quantity} {d.unit}</td>
                                                <td className="p-3 text-xs">{d.reason}</td>
                                                <td className="p-3 text-xs">{d.destroyed_by_user?.name ?? '—'}</td>
                                                <td className="p-3 text-xs">{d.witness_1?.name ?? '—'}</td>
                                            </tr>
                                        ))}
                                        {destructions.length === 0 && (
                                            <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No controlled drug destructions recorded.</td></tr>
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
