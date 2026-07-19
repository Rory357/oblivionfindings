import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Cpu,
    MapPin,
    MonitorOff,
} from 'lucide-react';

import { DeviceCard, type DeviceListItem, StatCard } from '../devices/shared';

interface SiteTechnology {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    address: string;
    is_active: boolean;
}

export default function SiteTechnologyShow({
    site,
    devices,
    summary,
}: {
    site: SiteTechnology;
    devices: DeviceListItem[];
    summary: { devices: number; attention: number; offline: number };
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
                {
                    title: site.name,
                    href: `/security-devices/sites/${site.id}`,
                },
            ]}
        >
            <Head title={`${site.name} - Security & Devices`} />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Building2}
                    title={site.name}
                    description="Technology devices and current health for this site. Use Monitoring for active signals and IT & Support for service work."
                    stats={[
                        { label: 'Devices', value: summary.devices },
                        { label: 'Need attention', value: summary.attention },
                        { label: 'Offline', value: summary.offline },
                    ]}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {site.type ? (
                                <Badge variant="outline">
                                    {site.type.replace(/_/g, ' ')}
                                </Badge>
                            ) : null}
                            <Badge
                                variant={
                                    site.is_active ? 'secondary' : 'outline'
                                }
                            >
                                {site.is_active
                                    ? 'Active site'
                                    : 'Inactive site'}
                            </Badge>
                        </div>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Devices"
                        value={summary.devices}
                        icon={Cpu}
                    />
                    <StatCard
                        label="Need attention"
                        value={summary.attention}
                        icon={AlertTriangle}
                        variant={summary.attention > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Offline"
                        value={summary.offline}
                        icon={MonitorOff}
                        variant={summary.offline > 0 ? 'warning' : 'default'}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="h-4 w-4" aria-hidden="true" />
                            Site context
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        {site.address ||
                            site.city ||
                            'No site address recorded.'}
                    </CardContent>
                </Card>

                <section aria-labelledby="site-devices-heading">
                    <h2
                        id="site-devices-heading"
                        className="mb-3 text-lg font-semibold"
                    >
                        Devices at this site
                    </h2>
                    {devices.length === 0 ? (
                        <EmptyState
                            icon={Cpu}
                            title="No devices assigned"
                            description="No canonical devices are currently assigned to this site or one of its rooms."
                        />
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {devices.map((device) => (
                                <DeviceCard key={device.id} device={device} />
                            ))}
                        </div>
                    )}
                </section>
            </PageShell>
        </AppLayout>
    );
}
