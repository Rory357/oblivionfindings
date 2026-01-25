import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { useMemo, useState } from 'react';

type Props = {
    filters: { client_id?: any; user_id?: any; from?: string; to?: string };
    logs: Array<{
        id: number;
        created_at: string;
        action: string;
        auditable_type: string;
        auditable_id: number;
        client?: { id: number; name: string } | null;
        user?: { id: number; name: string } | null;
        meta?: any;
    }>;
};

function pill(action: string) {
    const a = action?.toLowerCase?.() ?? '';
    const cls =
        a === 'created'
            ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
            : a === 'updated'
              ? 'bg-amber-100 text-amber-800 border-amber-200'
              : a === 'deleted'
                ? 'bg-rose-100 text-rose-800 border-rose-200'
                : 'bg-slate-100 text-slate-700 border-slate-200';
    return (
        <Badge variant="outline" className={cls}>
            {action}
        </Badge>
    );
}

export default function MedicationsAudit({ filters, logs }: Props) {
    const [clientId, setClientId] = useState(filters.client_id ?? '');
    const [userId, setUserId] = useState(filters.user_id ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const query = useMemo(() => {
        const q: any = {};
        if (clientId) q.client_id = clientId;
        if (userId) q.user_id = userId;
        if (from) q.from = from;
        if (to) q.to = to;
        return q;
    }, [clientId, userId, from, to]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }, { title: 'Audit', href: '/medications/audit' }]}>
            <Head title="Medications audit" />

            <div className="space-y-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="text-lg font-semibold">Medication audit</div>
                        <div className="text-xs text-slate-500">Filter medication orders, administrations, controlled register, discrepancies, and break-glass access.</div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            onClick={() => (window.location.href = `/medications/audit/export?${new URLSearchParams(query).toString()}`)}
                        >
                            Export CSV
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label>Client ID</Label>
                                <Input value={clientId} onChange={(e) => setClientId(e.target.value)} placeholder="e.g. 12" />
                            </div>
                            <div className="grid gap-2">
                                <Label>User ID</Label>
                                <Input value={userId} onChange={(e) => setUserId(e.target.value)} placeholder="e.g. 7" />
                            </div>
                            <div className="grid gap-2">
                                <Label>From</Label>
                                <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label>To</Label>
                                <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Button onClick={() => router.get('/medications/audit', query, { preserveState: true, preserveScroll: true })}>Apply</Button>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setClientId('');
                                    setUserId('');
                                    setFrom('');
                                    setTo('');
                                    router.get('/medications/audit', {}, { preserveState: true, preserveScroll: true });
                                }}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Latest entries (max 200)</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {logs.map((l) => (
                            <div key={l.id} className="rounded-md border p-3">
                                <div className="flex flex-col gap-1 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {pill(l.action)}
                                            <div className="text-sm font-medium">{l.auditable_type} #{l.auditable_id}</div>
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {l.created_at ? new Date(l.created_at).toLocaleString() : ''}
                                            {l.client?.name ? ` • Client: ${l.client.name} (#${l.client.id})` : ''}
                                            {l.user?.name ? ` • User: ${l.user.name} (#${l.user.id})` : ''}
                                        </div>
                                    </div>
                                </div>
                                {l.meta && (
                                    <pre className="mt-2 max-h-56 overflow-auto rounded-md bg-slate-50 p-2 text-xs text-slate-700">
                                        {JSON.stringify(l.meta, null, 2)}
                                    </pre>
                                )}
                            </div>
                        ))}
                        {!logs.length && <div className="text-sm text-slate-500">No audit entries found.</div>}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
