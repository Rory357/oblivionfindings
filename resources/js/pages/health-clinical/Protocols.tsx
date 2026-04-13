import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type Protocol = {
    id: number;
    client_id: number;
    observation_type: string;
    frequency: string;
    custom_interval_days: number | null;
    next_due_at: string | null;
    last_recorded_at: string | null;
    status: string;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
};

type ClientOption = { id: number; first_name: string; last_name: string };

type Filters = {
    client_id?: string;
    status?: string;
    observation_type?: string;
};

type Props = {
    protocols: PaginatedData<Protocol>;
    filters: Filters;
    clients: ClientOption[];
    observation_types: Record<string, string>;
};

const statusColor: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800',
    paused: 'bg-amber-100 text-amber-800',
    completed: 'bg-gray-100 text-gray-600',
};

export default function Protocols({ protocols, filters, clients, observation_types }: Props) {
    const [localFilters, setLocalFilters] = useState<Filters>({
        client_id: filters.client_id ?? '',
        status: filters.status ?? '',
        observation_type: filters.observation_type ?? '',
    });

    const applyFilters = () => {
        const clean = Object.fromEntries(
            Object.entries(localFilters).filter(([, v]) => v !== '' && v !== undefined),
        );
        router.get('/health-clinical/protocols', clean, { preserveState: true, replace: true });
    };

    const isOverdue = (p: Protocol) =>
        p.status === 'active' && p.next_due_at && new Date(p.next_due_at) < new Date();

    return (
        <AppLayout>
            <Head title="Clinical Protocols" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Clinical Protocols</h1>
                        <p className="mt-1 text-sm text-gray-500">Observation protocols and adherence</p>
                    </div>
                    <Link href="/health-clinical">
                        <Button variant="outline" size="sm">Dashboard</Button>
                    </Link>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    <div>
                        <Label className="text-xs">Client</Label>
                        <Select value={localFilters.client_id ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, client_id: v === '__all__' ? '' : v }))}>
                            <SelectTrigger className="h-8 w-[180px] text-xs"><SelectValue placeholder="All clients" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All clients</SelectItem>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label className="text-xs">Status</Label>
                        <Select value={localFilters.status ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, status: v === '__all__' ? '' : v }))}>
                            <SelectTrigger className="h-8 w-[140px] text-xs"><SelectValue placeholder="All" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="paused">Paused</SelectItem>
                                <SelectItem value="completed">Completed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label className="text-xs">Type</Label>
                        <Select value={localFilters.observation_type ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, observation_type: v === '__all__' ? '' : v }))}>
                            <SelectTrigger className="h-8 w-[160px] text-xs"><SelectValue placeholder="All types" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All types</SelectItem>
                                {Object.entries(observation_types).map(([k, v]) => (
                                    <SelectItem key={k} value={k}>{v}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-end">
                        <Button size="sm" onClick={applyFilters}>Apply</Button>
                    </div>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {protocols.data.length === 0 ? (
                            <div className="p-8 text-center text-sm text-muted-foreground">No protocols found.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40">
                                            <th className="px-4 py-3 text-left font-medium">Client</th>
                                            <th className="px-4 py-3 text-left font-medium">Observation Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Frequency</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-left font-medium">Next Due</th>
                                            <th className="px-4 py-3 text-left font-medium">Last Recorded</th>
                                            <th className="px-4 py-3 text-left font-medium">Created by</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {protocols.data.map((p) => (
                                            <tr key={p.id} className={`border-b transition-colors hover:bg-muted/20 ${isOverdue(p) ? 'bg-red-50/40' : ''}`}>
                                                <td className="px-4 py-3">
                                                    {p.client ? (
                                                        <Link href={`/operations/clients/${p.client.id}`} className="font-medium text-blue-600 hover:underline">
                                                            {p.client.first_name} {p.client.last_name}
                                                        </Link>
                                                    ) : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="secondary" className="text-xs">{observation_types[p.observation_type] ?? p.observation_type}</Badge>
                                                </td>
                                                <td className="px-4 py-3 text-xs capitalize">
                                                    {p.frequency.replace('_', ' ')}
                                                    {p.custom_interval_days ? ` (${p.custom_interval_days}d)` : ''}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge className={`text-xs ${statusColor[p.status] ?? ''}`}>{p.status}</Badge>
                                                    {isOverdue(p) && <Badge variant="destructive" className="ml-1 text-xs">Overdue</Badge>}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {p.next_due_at ? new Date(p.next_due_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {p.last_recorded_at ? new Date(p.last_recorded_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Never'}
                                                </td>
                                                <td className="px-4 py-3 text-xs">{p.creator?.name ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {protocols.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-xs text-muted-foreground">Page {protocols.current_page} of {protocols.last_page}</p>
                                <div className="flex gap-1">
                                    {protocols.links.map((link, i) => (
                                        <Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
