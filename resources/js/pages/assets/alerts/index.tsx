import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';

type AlertRow = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at?: string | null;
    asset?: { id: number; name: string; asset_tag?: string | null } | null;
};

export default function AssetAlertsIndex() {
    const { alerts, can } = usePage().props as any;
    const rows: AlertRow[] = alerts?.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Assets', href: '/assets' }, { title: 'Alerts', href: '/assets/alerts' }]}>
            <Head title="Asset Alerts" />
            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Active asset alerts</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {rows.length ? (
                            rows.map((a) => (
                                <div key={a.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{a.alert_type}</span>
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{a.severity}</span>
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{a.status}</span>
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {a.asset ? (
                                            <Link href={`/assets/${a.asset.id}`} className="hover:underline">
                                                {a.asset.name}
                                            </Link>
                                        ) : (
                                            'Unknown asset'
                                        )}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs text-slate-600">
                                    <div>{a.triggered_at ?? ''}</div>
                                    {can?.manage ? (
                                        <div className="mt-2 flex items-center justify-end gap-2">
                                            {a.status === 'open' ? (
                                                <button
                                                    className="text-xs text-blue-600 hover:underline"
                                                    onClick={() => router.post(`/assets/alerts/${a.id}/acknowledge`)}
                                                >
                                                    Acknowledge
                                                </button>
                                            ) : null}
                                            {a.status !== 'resolved' ? (
                                                <button
                                                    className="text-xs text-green-600 hover:underline"
                                                    onClick={() => router.post(`/assets/alerts/${a.id}/resolve`)}
                                                >
                                                    Resolve
                                                </button>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="text-sm text-slate-500">No alerts.</div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
