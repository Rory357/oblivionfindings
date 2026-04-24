import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

type AlertRow = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at?: string | null;
    asset?: { id: number; name: string; asset_tag?: string | null } | null;
};

export default function AssetAlertsIndex() {
    const { alerts, archive } = usePage().props as any;
    const rows: AlertRow[] = alerts?.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Assets', href: '/assets' }, { title: 'Archived Alerts', href: '/assets/alerts' }]}>
            <Head title="Archived Asset Alerts" />
            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Archived Asset Alerts</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <div className="rounded-md border border-border bg-muted p-3 text-sm text-muted-foreground">
                            These records are retained as legacy asset alert history only. Active operational alerts now live in{' '}
                            <Link href={archive?.replacement_url ?? '/fleet-assets/alerts'} className="font-medium text-status-info hover:underline">
                                Fleet Alerts
                            </Link>
                            {' '}and Control Room.
                        </div>
                        {rows.length ? (
                            rows.map((a) => (
                                <div key={a.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.alert_type}</span>
                                        <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.severity}</span>
                                        <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.status}</span>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {a.asset ? (
                                            <Link href={`/assets/${a.asset.id}`} className="hover:underline">
                                                {a.asset.name}
                                            </Link>
                                        ) : (
                                            'Unknown asset'
                                        )}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs text-muted-foreground">{a.triggered_at ?? ''}</div>
                            </div>
                        ))
                    ) : (
                        <div className="text-sm text-muted-foreground">No archived asset alerts.</div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
