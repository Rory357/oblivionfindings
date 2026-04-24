import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';

type Props = {
    filters: {
        date_from: string;
        date_to: string;
        client_id: number | null;
        service_context_id: number | null;
        status: string | null;
        discrepancy_status: string | null;
    };
    clients: Array<{ id: number; name: string }>;
    service_contexts: Array<{ id: number; name: string; type: string }>;
    administrations: Array<any>;
    discrepancies: Array<any>;
};

export default function MedicationsReport(props: Props) {
    const { filters, clients, service_contexts, administrations, discrepancies } = props;
    const { auth } = usePage().props as any;

    const setFilter = (key: string, value: any) => {
        router.get(
            '/reports/medications',
            {
                ...filters,
                [key]: value === '' ? null : value,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const buildQuery = (extra: Record<string, any> = {}) => {
        const merged = { ...filters, ...extra };
        const params = new URLSearchParams();
        Object.entries(merged).forEach(([k, v]) => {
            if (v === null || v === undefined || v === '') return;
            params.set(k, String(v));
        });
        return params.toString();
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }, { title: 'Medications', href: '/reports/medications' }]}>
            <Head title="Medication Reports" />

            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <Label>Date from</Label>
                            <Input
                                type="date"
                                value={filters.date_from}
                                onChange={(e) => setFilter('date_from', e.target.value)}
                            />
                        </div>
                        <div>
                            <Label>Date to</Label>
                            <Input
                                type="date"
                                value={filters.date_to}
                                onChange={(e) => setFilter('date_to', e.target.value)}
                            />
                        </div>
                        <div>
                            <Label>Client</Label>
                            <Select
                                value={filters.client_id ? String(filters.client_id) : 'all'}
                                onValueChange={(v) => setFilter('client_id', v === 'all' ? null : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All clients" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All clients</SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Service context</Label>
                            <Select
                                value={filters.service_context_id ? String(filters.service_context_id) : 'all'}
                                onValueChange={(v) => setFilter('service_context_id', v === 'all' ? null : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All contexts" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All contexts</SelectItem>
                                    {service_contexts.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>MAR outcome</Label>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(v) => setFilter('status', v === 'all' ? null : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="given">Given</SelectItem>
                                    <SelectItem value="refused">Refused</SelectItem>
                                    <SelectItem value="withheld">Withheld</SelectItem>
                                    <SelectItem value="missed">Missed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Controlled discrepancy status</Label>
                            <Select
                                value={filters.discrepancy_status ?? 'all'}
                                onValueChange={(v) => setFilter('discrepancy_status', v === 'all' ? null : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => {
                                    window.location.href = `/reports/medications/export-mar?${buildQuery()}`;
                                }}
                            >
                                Export MAR (CSV)
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    window.location.href = `/reports/medications/export-controlled-discrepancies?${buildQuery()}`;
                                }}
                            >
                                Export discrepancies (CSV)
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Medication administrations (MAR)</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {administrations.length === 0 && (
                                <div className="text-sm text-muted-foreground">No administrations found for the selected filters.</div>
                            )}
                            {administrations.map((a) => (
                                <div key={a.id} className="rounded-md border p-3">
                                    <div className="flex items-center justify-between">
                                        <div className="text-sm font-medium">
                                            {a.client?.first_name} {a.client?.last_name} — {a.medication?.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">{a.status}</div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {a.administered_at} · {a.administeredBy?.name ?? 'Unknown'}
                                        {a.serviceContext?.name ? ` · ${a.serviceContext.name}` : ''}
                                    </div>
                                    {(a.reason || a.dose_given) && (
                                        <div className="mt-2 text-sm">
                                            {a.dose_given ? (
                                                <div><span className="font-medium">Dose:</span> {a.dose_given}</div>
                                            ) : null}
                                            {a.reason ? (
                                                <div><span className="font-medium">Reason:</span> {a.reason}</div>
                                            ) : null}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Controlled drug discrepancies</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {discrepancies.length === 0 && (
                                <div className="text-sm text-muted-foreground">No discrepancies found for the selected filters.</div>
                            )}
                            {discrepancies.map((d) => (
                                <div key={d.id} className="rounded-md border p-3">
                                    <div className="flex items-center justify-between">
                                        <div className="text-sm font-medium">
                                            {d.client?.first_name} {d.client?.last_name} — {d.medication?.name}
                                        </div>
                                        <div className={`text-xs ${d.status === 'open' ? 'text-amber-600' : 'text-muted-foreground'}`}>
                                            {d.status}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {d.reported_at} · {d.reportedBy?.name ?? 'Unknown'}
                                        {d.serviceContext?.name ? ` · ${d.serviceContext.name}` : ''}
                                    </div>
                                    <Separator className="my-2" />
                                    <div className="text-sm">
                                        <div>
                                            <span className="font-medium">Before:</span> {d.on_hand_before ?? '-'} ·{' '}
                                            <span className="font-medium">After:</span> {d.on_hand_after ?? '-'} ·{' '}
                                            <span className="font-medium">Diff:</span> {d.difference ?? '-'}
                                        </div>
                                        {d.reason ? (
                                            <div className="mt-1"><span className="font-medium">Reason:</span> {d.reason}</div>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
