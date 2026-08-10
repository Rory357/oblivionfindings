import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    CoverageIndicator,
    OperationalStateBadge,
} from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    Building2,
    Clock3,
    Cpu,
    MapPin,
    RadioTower,
    TicketCheck,
    Wrench,
} from 'lucide-react';

interface SiteTechnologySummary {
    total: number;
    with_devices: number;
    requiring_attention: number;
    unknown: number;
}

interface SiteTechnologyItem {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    is_active: boolean;
    health: 'critical' | 'warning' | 'healthy' | 'unknown';
    devices: number;
    attention_devices: number;
    offline_devices: number;
    monitored_devices: number;
    unmonitored_devices: number;
    coverage_percent: number | null;
    failed_monitors: number;
    active_findings: number;
    active_control_room_alerts: number | null;
    open_it_work: number | null;
    overdue_maintenance: number;
    collector: {
        state: 'online' | 'stale' | 'not_configured';
        label: string;
        count: number;
        last_seen_at: string | null;
    };
    last_change_at: string | null;
    requires_action: boolean;
    href: string;
}

function CountItem({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Cpu;
    label: string;
    value: number | null;
}) {
    return (
        <div className="rounded-xl border bg-muted/20 p-3">
            <Icon
                className="h-4 w-4 text-muted-foreground"
                aria-hidden="true"
            />
            <p className="mt-2 text-lg font-semibold">
                {value === null ? 'Restricted' : value}
            </p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
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
                    description="Technology health, monitoring coverage, active findings, service work and collector state for every site you can access."
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
                        { label: 'Unknown', value: summary.unknown },
                    ]}
                />

                {sites.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No accessible sites"
                        description="No active Sites are available within your approved Site access."
                    />
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {sites.map((site) => (
                            <Card key={site.id}>
                                <CardContent className="p-5">
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0">
                                            <Link
                                                href={site.href}
                                                className="frontline-focus frontline-tap -ml-2 inline-flex items-center rounded-lg px-2 text-base font-semibold hover:text-primary hover:underline"
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
                                        <OperationalStateBadge
                                            state={site.health}
                                        />
                                    </div>

                                    <CoverageIndicator
                                        className="mt-5"
                                        percent={site.coverage_percent}
                                        monitored={site.monitored_devices}
                                        total={site.devices}
                                    />

                                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <CountItem
                                            icon={Cpu}
                                            label="Devices"
                                            value={site.devices}
                                        />
                                        <CountItem
                                            icon={Activity}
                                            label="Findings"
                                            value={site.active_findings}
                                        />
                                        <CountItem
                                            icon={TicketCheck}
                                            label="Open IT work"
                                            value={site.open_it_work}
                                        />
                                        <CountItem
                                            icon={Wrench}
                                            label="Overdue"
                                            value={site.overdue_maintenance}
                                        />
                                    </div>

                                    <div className="mt-4 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                        <div className="flex min-h-11 items-center gap-2 rounded-xl border px-3">
                                            <RadioTower
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                            <span>{site.collector.label}</span>
                                            <OperationalStateBadge
                                                state={site.collector.state}
                                                className="ml-auto"
                                            />
                                        </div>
                                        <div className="flex min-h-11 items-center gap-2 rounded-xl border px-3">
                                            <Clock3
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                            <span>
                                                {site.last_change_at
                                                    ? `Changed ${formatRelative(site.last_change_at)}`
                                                    : 'No change recorded'}
                                            </span>
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
