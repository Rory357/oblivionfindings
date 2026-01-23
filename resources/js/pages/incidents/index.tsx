import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    filters: { q: string; status: string | null; severity: string | null };
    incidents: any;
};

export default function IncidentsIndex({ filters, incidents }: Props) {
    // Radix SelectItem values cannot be an empty string.
    // Use a sentinel value for "Any" and translate to null when applying filters.
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }]}>
            <Head title="Incidents" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Incidents</h1>
                        <div className="mt-1 text-sm text-slate-500">All incidents (manager view)</div>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <Input
                            placeholder="Search title / type / text"
                            value={filters.q || ''}
                            onChange={(e) => onFilter({ q: e.target.value })}
                        />
                        <Select
                            value={filters.status ?? ANY}
                            onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                        >
                            <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any status</SelectItem>
                                {['draft', 'submitted', 'reviewed', 'closed'].map((s) => (
                                    <SelectItem key={s} value={s}>{s}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.severity ?? ANY}
                            onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}
                        >
                            <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any severity</SelectItem>
                                {['low', 'medium', 'high', 'critical'].map((s) => (
                                    <SelectItem key={s} value={s}>{s}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {incidents.data.map((i: any) => (
                        <Card key={i.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">{i.title}</div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {i.client?.first_name} {i.client?.last_name} • {i.type} • {i.severity} • {i.status}
                                                {i.occurred_at ? <span className="ml-2">• {i.occurred_at}</span> : null}
                                            </div>
                                        </div>
                                        <Link href={`/incidents/${i.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Open
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!incidents.data.length && <div className="text-sm text-slate-500">No incidents found.</div>}
                </div>
            </div>
        </AppLayout>
    );
}
