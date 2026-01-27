import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

type AssetRow = {
    id: number;
    name: string;
    asset_tag?: string | null;
    status: string;
    risk_level: string;
    site?: { id: number; name: string } | null;
    client?: { id: number; name: string } | null;
    inspection_due_at?: string | null;
    maintenance_due_at?: string | null;
    warranty_expires_at?: string | null;
};

function Row({ a, right }: { a: AssetRow; right?: string | null }) {
    return (
        <div className="flex items-start justify-between gap-3 rounded-md border p-3">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/assets/${a.id}`} className="truncate text-sm font-medium hover:underline">
                        {a.name}
                    </Link>
                    {a.asset_tag ? (
                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">#{a.asset_tag}</span>
                    ) : null}
                    <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{a.status}</span>
                    <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{a.risk_level}</span>
                </div>
                <div className="mt-1 text-xs text-slate-500">
                    {a.site ? `Site: ${a.site.name}` : 'Site: —'}
                    {a.client ? ` • Client: ${a.client.name}` : ''}
                </div>
            </div>
            <div className="shrink-0 text-right text-xs text-slate-600">{right ?? ''}</div>
        </div>
    );
}

export default function AssetsReport() {
    const { overdueInspections, overdueMaintenance, expiringWarranties } = usePage().props as any;

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }, { title: 'Assets', href: '/reports/assets' }]}>
            <Head title="Asset Reports" />
            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Overdue inspections</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overdueInspections?.length ? (
                            overdueInspections.map((a: AssetRow) => <Row key={a.id} a={a} right={a.inspection_due_at ?? ''} />)
                        ) : (
                            <div className="text-sm text-slate-500">None.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Overdue maintenance</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overdueMaintenance?.length ? (
                            overdueMaintenance.map((a: AssetRow) => <Row key={a.id} a={a} right={a.maintenance_due_at ?? ''} />)
                        ) : (
                            <div className="text-sm text-slate-500">None.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Expiring warranties (next 60 days)</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {expiringWarranties?.length ? (
                            expiringWarranties.map((a: AssetRow) => <Row key={a.id} a={a} right={a.warranty_expires_at ?? ''} />)
                        ) : (
                            <div className="text-sm text-slate-500">None.</div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
