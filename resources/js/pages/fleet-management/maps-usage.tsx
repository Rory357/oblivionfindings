import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { PageHero } from '@/components/page';
import { Head } from '@inertiajs/react';
import { Map } from 'lucide-react';

export default function FleetMapsUsage({ rows, reverse_geocode }) {
    const totalUsage =
        rows?.reduce((sum: number, r: any) => sum + (r.total ?? 0), 0) ?? 0;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Map Usage', href: '/fleet-management/maps-usage' },
            ]}
        >
            <Head title="Fleet Map Usage" />
            <PageShell>
                <PageHero
                    icon={Map}
                    backHref="/fleet-assets"
                    title="Fleet Map Usage"
                    description="Basic counts for Google Maps usage by context."
                    stats={[
                        { label: 'Total calls', value: totalUsage },
                        { label: 'Contexts', value: rows?.length ?? 0 },
                        {
                            label: 'Reverse geocode',
                            value: reverse_geocode?.enabled ? 'On' : 'Off',
                        },
                    ]}
                />
                <div className="rounded-md border p-4 text-sm">
                    <div className="mb-2 text-sm font-medium">
                        Reverse geocoding
                    </div>
                    {reverse_geocode?.enabled ? (
                        <div className="flex flex-wrap items-center gap-2 text-muted-foreground">
                            <Badge>Enabled</Badge>
                            <span>
                                Cache TTL: {reverse_geocode.cache_ttl_days} days
                            </span>
                            <span>
                                Rate limit: {reverse_geocode.rate_limit_per_minute}/min
                            </span>
                        </div>
                    ) : (
                        <div className="text-muted-foreground">
                            Not enabled. Reverse geocoding is currently disabled
                            to control cost. Enable FLEET_REVERSE_GEOCODE_ENABLED
                            when ready.
                        </div>
                    )}
                </div>
                <div className="rounded-md border p-4">
                    <div className="mb-3 text-sm font-medium">Usage</div>
                    <div className="grid gap-2 text-sm">
                        {rows?.length ? (
                            rows.map((row) => (
                                <div
                                    key={row.context}
                                    className="flex items-center justify-between rounded-md border p-2"
                                >
                                    <div>{row.context}</div>
                                    <div className="font-medium">
                                        {row.total}
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="text-muted-foreground">
                                No usage recorded.
                            </div>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
