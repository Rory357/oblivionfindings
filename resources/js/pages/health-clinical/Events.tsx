import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
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
import { Head, Link, router } from '@inertiajs/react';
import { Filter, X } from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type ClinicalEvent = {
    id: number;
    client_id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    follow_up_required: boolean;
    follow_up_completed_at: string | null;
    reviewed_at: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    reporter: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
};

type ClientOption = { id: number; first_name: string; last_name: string };

type Filters = {
    client_id?: string;
    event_type?: string;
    severity?: string;
    date_from?: string;
    date_to?: string;
};

type Props = {
    events: PaginatedData<ClinicalEvent>;
    filters: Filters;
    clients: ClientOption[];
    event_types: Record<string, string>;
};

const severityColor: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800',
    medium: 'bg-amber-100 text-amber-800',
    high: 'bg-orange-100 text-orange-800',
    critical: 'bg-red-100 text-red-800',
};

export default function Events({ events, filters, clients, event_types }: Props) {
    const [localFilters, setLocalFilters] = useState<Filters>({
        client_id: filters.client_id ?? '',
        event_type: filters.event_type ?? '',
        severity: filters.severity ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const applyFilters = () => {
        const clean = Object.fromEntries(
            Object.entries(localFilters).filter(([, v]) => v !== '' && v !== undefined),
        );
        router.get('/health-clinical/events', clean, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setLocalFilters({});
        router.get('/health-clinical/events', {}, { preserveState: true, replace: true });
    };

    const hasFilters = Object.values(localFilters).some((v) => v !== '' && v !== undefined);

    return (
        <AppLayout>
            <Head title="Clinical Event Register" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Clinical Event Register
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            All clinical events across clients
                        </p>
                    </div>
                    <Link href="/health-clinical">
                        <Button variant="outline" size="sm">Dashboard</Button>
                    </Link>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <Filter className="h-4 w-4" /> Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            <div>
                                <Label className="text-xs">Client</Label>
                                <Select value={localFilters.client_id ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, client_id: v === '__all__' ? '' : v }))}>
                                    <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="All clients" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All clients</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Type</Label>
                                <Select value={localFilters.event_type ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, event_type: v === '__all__' ? '' : v }))}>
                                    <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="All types" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All types</SelectItem>
                                        {Object.entries(event_types).map(([k, v]) => (
                                            <SelectItem key={k} value={k}>{v}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Severity</Label>
                                <Select value={localFilters.severity ?? ''} onValueChange={(v) => setLocalFilters((f) => ({ ...f, severity: v === '__all__' ? '' : v }))}>
                                    <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="All" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All</SelectItem>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">From</Label>
                                <Input type="date" className="h-8 text-xs" value={localFilters.date_from ?? ''} onChange={(e) => setLocalFilters((f) => ({ ...f, date_from: e.target.value }))} />
                            </div>
                            <div>
                                <Label className="text-xs">To</Label>
                                <Input type="date" className="h-8 text-xs" value={localFilters.date_to ?? ''} onChange={(e) => setLocalFilters((f) => ({ ...f, date_to: e.target.value }))} />
                            </div>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Button size="sm" onClick={applyFilters}>Apply</Button>
                            {hasFilters && <Button size="sm" variant="ghost" onClick={clearFilters} className="gap-1"><X className="h-3 w-3" /> Clear</Button>}
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {events.data.length === 0 ? (
                            <div className="p-8 text-center text-sm text-muted-foreground">No events match the selected filters.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40">
                                            <th className="px-4 py-3 text-left font-medium">Client</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Severity</th>
                                            <th className="px-4 py-3 text-left font-medium">Occurred</th>
                                            <th className="px-4 py-3 text-left font-medium">Reported by</th>
                                            <th className="px-4 py-3 text-left font-medium">Follow-up</th>
                                            <th className="px-4 py-3 text-left font-medium">Reviewed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {events.data.map((evt) => (
                                            <tr key={evt.id} className="border-b transition-colors hover:bg-muted/20">
                                                <td className="px-4 py-3">
                                                    {evt.client ? (
                                                        <Link href={`/operations/clients/${evt.client.id}`} className="font-medium text-blue-600 hover:underline">
                                                            {evt.client.first_name} {evt.client.last_name}
                                                        </Link>
                                                    ) : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="outline" className="text-xs">{event_types[evt.event_type] ?? evt.event_type}</Badge>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge className={`text-xs ${severityColor[evt.severity] ?? ''}`}>{evt.severity}</Badge>
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {new Date(evt.occurred_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                                </td>
                                                <td className="px-4 py-3 text-xs">{evt.reporter?.name ?? '—'}</td>
                                                <td className="px-4 py-3 text-xs">
                                                    {!evt.follow_up_required ? (
                                                        <span className="text-muted-foreground">N/A</span>
                                                    ) : evt.follow_up_completed_at ? (
                                                        <Badge variant="secondary" className="text-xs">Done</Badge>
                                                    ) : (
                                                        <Badge variant="destructive" className="text-xs">Pending</Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {evt.reviewed_at ? (
                                                        <Badge variant="secondary" className="text-xs">{evt.reviewer?.name}</Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">Unreviewed</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {events.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-xs text-muted-foreground">
                                    Page {events.current_page} of {events.last_page} ({events.total} total)
                                </p>
                                <div className="flex gap-1">
                                    {events.links.map((link, i) => (
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
