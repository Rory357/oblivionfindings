import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head } from '@inertiajs/react';
import { AlertOctagon } from 'lucide-react';
import { useState } from 'react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    filters: { from?: string; to?: string; client_id?: string; severity?: string; reviewed?: string };
};

export default function IncidentReports({ clients, filters }: Props) {
    const ANY = '__any__';
    const [from, setFrom] = useState(filters.from || '');
    const [to, setTo] = useState(filters.to || '');
    const [clientId, setClientId] = useState(filters.client_id || '');
    const [severity, setSeverity] = useState(filters.severity || '');
    const [reviewed, setReviewed] = useState(filters.reviewed || '');

    const buildUrl = () => {
        const params = new URLSearchParams();
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        if (clientId) params.set('client_id', clientId);
        if (severity) params.set('severity', severity);
        if (reviewed) params.set('reviewed', reviewed);
        return `/reports/incidents/export?${params.toString()}`;
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }, { title: 'Incidents', href: '/reports/incidents' }]}>
            <Head title="Incident reports" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertOctagon}
                        title="Incident reports"
                        description="Export CSV with filters"
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                        <div className="space-y-1">
                            <Label>From</Label>
                            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <Label>To</Label>
                            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                        </div>

                        <div className="space-y-1">
                            <Label>Client</Label>
                            <Select value={clientId || ANY} onValueChange={(v) => setClientId(v === ANY ? '' : v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Severity</Label>
                            <Select value={severity || ANY} onValueChange={(v) => setSeverity(v === ANY ? '' : v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['low','medium','high'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Reviewed</Label>
                            <Select value={reviewed || ANY} onValueChange={(v) => setReviewed(v === ANY ? '' : v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="yes">Yes</SelectItem>
                                    <SelectItem value="no">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="sm:col-span-5 flex justify-end">
                            <a href={buildUrl()}>
                                <Button>Download CSV</Button>
                            </a>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
