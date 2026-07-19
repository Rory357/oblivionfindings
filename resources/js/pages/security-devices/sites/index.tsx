import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Building2, Cpu, MapPin } from 'lucide-react';

interface SiteTechnologySummary {
    total: number;
    with_devices: number;
    requiring_attention: number;
}

interface SiteTechnologyItem {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    is_active: boolean;
    device_count: number;
    attention_count: number;
    href: string;
}

export default function SiteTechnologyIndex({
    sites,
    summary,
}: {
    sites: SiteTechnologyItem[];
    summary: SiteTechnologySummary;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
            ]}
        >
            <Head title="Sites - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Building2}
                    title="Sites"
                    description="Technology estate and device health by site. Open a site to investigate its devices and operational context."
                    stats={[
                        { label: 'Sites', value: summary.total },
                        {
                            label: 'With devices',
                            value: summary.with_devices,
                        },
                        {
                            label: 'Need attention',
                            value: summary.requiring_attention,
                        },
                    ]}
                />

                {sites.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No sites available"
                        description="No active sites are available in your organisation."
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {sites.map((site) => (
                            <Card key={site.id}>
                                <CardContent className="p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link
                                                href={site.href}
                                                className="frontline-focus rounded-md text-base font-semibold hover:text-primary hover:underline"
                                            >
                                                {site.name}
                                            </Link>
                                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                {site.city ? (
                                                    <span className="flex items-center gap-1">
                                                        <MapPin
                                                            className="h-3.5 w-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {site.city}
                                                    </span>
                                                ) : null}
                                                {site.type ? (
                                                    <Badge variant="outline">
                                                        {site.type.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                site.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {site.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </div>

                                    <div className="mt-5 grid grid-cols-2 gap-3">
                                        <div className="rounded-xl border bg-muted/30 p-3">
                                            <Cpu
                                                className="h-4 w-4 text-primary"
                                                aria-hidden="true"
                                            />
                                            <p className="mt-2 text-lg font-semibold">
                                                {site.device_count}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Devices
                                            </p>
                                        </div>
                                        <div className="rounded-xl border bg-muted/30 p-3">
                                            <AlertTriangle
                                                className={`h-4 w-4 ${
                                                    site.attention_count > 0
                                                        ? 'text-status-warning'
                                                        : 'text-muted-foreground'
                                                }`}
                                                aria-hidden="true"
                                            />
                                            <p className="mt-2 text-lg font-semibold">
                                                {site.attention_count}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Need attention
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
