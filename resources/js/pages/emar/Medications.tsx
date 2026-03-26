import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Pill } from 'lucide-react';

type Props = {
    medications: { data: any[]; links: any };
    clients: { id: number; first_name: string; last_name: string }[];
    filters: { search?: string; status?: string; type?: string; client_id?: string };
};

export default function Medications({ medications, clients, filters }: Props) {
    function updateFilter(key: string, value: string) {
        router.get('/emar/medications', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medications" />
            <PageHeader title="Medications Database" description="Central medication directory with search, filtering, and status tracking." backHref="/emar" />
            <PageShell>
                {/* Filters */}
                <div className="mb-6 flex flex-wrap gap-3">
                    <Input placeholder="Search medications..." value={filters.search ?? ''} onChange={(e) => updateFilter('search', e.target.value)} className="w-64" />
                    <Select value={filters.status ?? ''} onValueChange={(v) => updateFilter('status', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="ceased">Ceased</SelectItem>
                            <SelectItem value="paused">Paused</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.type ?? ''} onValueChange={(v) => updateFilter('type', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All types" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="prn">PRN Only</SelectItem>
                            <SelectItem value="controlled">Controlled</SelectItem>
                            <SelectItem value="high_risk">High Risk</SelectItem>
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

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Medication</th>
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Dose</th>
                                    <th className="p-3 text-left font-medium">Frequency</th>
                                    <th className="p-3 text-left font-medium">Route</th>
                                    <th className="p-3 text-left font-medium">Flags</th>
                                    <th className="p-3 text-left font-medium">State</th>
                                    <th className="p-3 text-left font-medium">Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                {medications.data.map((m: any) => (
                                    <tr key={m.id} className="border-b last:border-0">
                                        <td className="p-3">
                                            <span className="font-medium">{m.name}</span>
                                            {m.instructions && <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">{m.instructions}</p>}
                                        </td>
                                        <td className="p-3">{m.client?.last_name}, {m.client?.first_name}</td>
                                        <td className="p-3 text-xs">{m.dosage ?? `${m.dose_amount} ${m.dose_unit}`}</td>
                                        <td className="p-3 text-xs">{m.frequency}</td>
                                        <td className="p-3 text-xs">{m.route ?? '—'}</td>
                                        <td className="p-3">
                                            <div className="flex gap-1">
                                                {m.is_prn && <Badge variant="outline" className="text-[10px]">PRN</Badge>}
                                                {m.controlled_drug && <Badge variant="destructive" className="text-[10px]">CD</Badge>}
                                                {m.high_risk && <Badge className="bg-amber-100 text-amber-700 text-[10px]">HR</Badge>}
                                                {m.witness_required && <Badge variant="secondary" className="text-[10px]">W</Badge>}
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={m.state === 'active' ? 'default' : m.state === 'paused' ? 'secondary' : 'outline'} className="text-xs">
                                                {m.state}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-xs font-mono">{m.stock?.on_hand ?? '—'}</td>
                                    </tr>
                                ))}
                                {medications.data.length === 0 && (
                                    <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No medications found.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
