import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateTime } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import {
    Battery,
    Camera,
    Cpu,
    ExternalLink,
    Network,
    Radar,
    ShieldCheck,
    TicketCheck,
    Wrench,
} from 'lucide-react';

type TechnologyDevice = {
    id: number;
    name: string;
    domain: string;
    category: string | null;
    subcategory: string | null;
    provider: string | null;
    status: string | null;
    health: string | null;
    battery: number | null;
    last_seen_at: string | null;
    href: string;
    installation: { type: string; installed_at: string | null };
    connectivity: { state: string; label: string };
    monitoring: {
        enabled: number;
        attention: number;
        uncertain: number;
        states: Array<{
            id: number;
            name: string;
            kind: string | null;
            state: string | null;
            last_observation_at: string | null;
        }>;
    } | null;
    configuration: { state: string; observed_at: string | null };
    firmware: {
        state: string;
        current_version: string | null;
        desired_version: string | null;
        observed_at: string | null;
    };
    maintenance: {
        open: number;
        overdue: number;
        next: {
            type: string;
            status: string;
            scheduled_for: string | null;
        } | null;
        href: string;
    } | null;
    it_work: {
        open: number;
        items: Array<{
            id: number;
            reference: string;
            title: string;
            status: string;
            priority: string;
            href: string;
        }>;
    } | null;
};

export type VehicleTechnologyProjection = {
    boundary: { title: string; description: string; management: string };
    summary: {
        total: number;
        offline: number;
        attention: number;
        unmonitored: number | null;
        monitor_alerts: number | null;
        configuration_drift: number;
        firmware_updates: number;
        overdue_maintenance: number | null;
        open_it_work: number | null;
    };
    devices: TechnologyDevice[];
    truncated: boolean;
    permissions: {
        monitoring: boolean;
        maintenance: boolean;
        it_work: boolean;
    };
    links: {
        tracking: string;
        devices: string;
        maintenance: string | null;
        it_work: string | null;
    };
};

function label(value: string | null | undefined): string {
    if (!value) return 'Not observed';
    return value
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function stateTone(state: string | null | undefined): string {
    if (['offline', 'critical', 'failed', 'drifted'].includes(state ?? '')) {
        return 'border-status-critical/30 bg-status-critical-bg text-status-critical';
    }
    if (
        [
            'attention',
            'warning',
            'degraded',
            'stale',
            'update_available',
        ].includes(state ?? '')
    ) {
        return 'border-status-warning/30 bg-status-warning-bg text-status-warning';
    }
    if (
        ['online', 'active', 'healthy', 'aligned', 'observed'].includes(
            state ?? '',
        )
    ) {
        return 'border-status-success/30 bg-status-success-bg text-status-success';
    }
    return 'border-border bg-muted/40 text-muted-foreground';
}

function Metric({
    label: metricLabel,
    value,
}: {
    label: string;
    value: number | null;
}) {
    return (
        <Card>
            <CardContent className="px-4 py-3.5">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {metricLabel}
                </p>
                <p className="mt-1 text-2xl font-bold tabular-nums">
                    {value === null ? '—' : value}
                </p>
            </CardContent>
        </Card>
    );
}

export function VehicleTechnologyProjectionPanel({
    projection,
    loading,
    failed,
}: {
    projection: VehicleTechnologyProjection | null | undefined;
    loading: boolean;
    failed: boolean;
}) {
    if (failed) {
        return (
            <Card>
                <CardContent className="py-10">
                    <EmptyState
                        icon={Radar}
                        title="Vehicle technology could not be loaded"
                        description="Refresh this tab to try again."
                    />
                </CardContent>
            </Card>
        );
    }

    if (loading || projection === undefined) {
        return (
            <div className="space-y-4" aria-busy="true">
                <Card>
                    <CardContent className="py-8 text-sm text-muted-foreground">
                        Loading canonical device, monitoring, maintenance, and
                        IT context…
                    </CardContent>
                </Card>
            </div>
        );
    }

    if (projection === null) {
        return (
            <Card>
                <CardContent className="py-10">
                    <EmptyState
                        icon={ShieldCheck}
                        title="Security & Devices access required"
                        description="Vehicle operations remain available, but your current Site and Security & Devices permissions do not allow this technology projection."
                    />
                </CardContent>
            </Card>
        );
    }

    const { boundary, summary, devices, links } = projection;

    return (
        <div className="space-y-5">
            <Card className="border-primary/25 bg-primary/5">
                <CardContent className="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="rounded-xl bg-primary/10 p-2 text-primary">
                            <Network className="h-5 w-5" />
                        </span>
                        <div>
                            <p className="font-semibold">{boundary.title}</p>
                            <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                {boundary.description}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {boundary.management}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href={links.tracking}>Fleet tracking</Link>
                        </Button>
                        <Button asChild size="sm">
                            <Link href={links.devices}>
                                Security &amp; Devices
                                <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Metric label="Installed technology" value={summary.total} />
                <Metric label="Needs attention" value={summary.attention} />
                <Metric label="Monitor alerts" value={summary.monitor_alerts} />
                <Metric label="Open IT work" value={summary.open_it_work} />
            </div>

            {devices.length === 0 ? (
                <Card>
                    <CardContent className="py-10">
                        <EmptyState
                            icon={Cpu}
                            title="No access-approved technology is installed"
                            description="Link this vehicle's devices in Security & Devices."
                        />
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {devices.map((device) => (
                        <Card key={device.id} className="overflow-hidden">
                            <CardHeader className="border-b bg-muted/20 pb-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span className="rounded-xl bg-primary/10 p-2 text-primary">
                                            {device.category?.includes(
                                                'camera',
                                            ) ? (
                                                <Camera className="h-5 w-5" />
                                            ) : (
                                                <Cpu className="h-5 w-5" />
                                            )}
                                        </span>
                                        <div className="min-w-0">
                                            <CardTitle className="truncate text-base">
                                                <Link
                                                    href={device.href}
                                                    className="hover:text-primary hover:underline"
                                                >
                                                    {device.name}
                                                </Link>
                                            </CardTitle>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {[
                                                    device.category,
                                                    device.subcategory,
                                                    device.provider,
                                                ]
                                                    .filter(Boolean)
                                                    .map(label)
                                                    .join(' · ') ||
                                                    'Technology device'}
                                            </p>
                                        </div>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className={stateTone(
                                            device.connectivity.state,
                                        )}
                                    >
                                        {device.connectivity.label}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4 p-5">
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-lg border p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Last contact
                                        </p>
                                        <p className="mt-1 text-sm font-medium">
                                            {device.last_seen_at
                                                ? formatDateTime(
                                                      device.last_seen_at,
                                                  )
                                                : 'Never observed'}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border p-3">
                                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <Battery className="h-3.5 w-3.5" />{' '}
                                            Battery
                                        </p>
                                        <p className="mt-1 text-sm font-medium">
                                            {device.battery === null
                                                ? 'Not reported'
                                                : `${device.battery}%`}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Installed as
                                        </p>
                                        <p className="mt-1 text-sm font-medium">
                                            {label(device.installation.type)}
                                        </p>
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-lg bg-muted/35 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Monitoring
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            {device.monitoring
                                                ? `${device.monitoring.enabled} checks · ${device.monitoring.attention} alert`
                                                : 'Restricted'}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/35 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Configuration
                                        </p>
                                        <Badge
                                            variant="outline"
                                            className={`mt-1 ${stateTone(device.configuration.state)}`}
                                        >
                                            {label(device.configuration.state)}
                                        </Badge>
                                    </div>
                                    <div className="rounded-lg bg-muted/35 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Firmware
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            {device.firmware.current_version ||
                                                'Not observed'}
                                        </p>
                                        {device.firmware.state ===
                                        'update_available' ? (
                                            <p className="mt-0.5 text-xs text-status-warning">
                                                Desired{' '}
                                                {
                                                    device.firmware
                                                        .desired_version
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1.5">
                                            <Wrench className="h-3.5 w-3.5" />
                                            {device.maintenance
                                                ? `${device.maintenance.open} technical maintenance · ${device.maintenance.overdue} overdue`
                                                : 'Maintenance restricted'}
                                        </span>
                                        <span className="flex items-center gap-1.5">
                                            <TicketCheck className="h-3.5 w-3.5" />
                                            {device.it_work
                                                ? `${device.it_work.open} open IT work item${device.it_work.open === 1 ? '' : 's'}`
                                                : 'IT work restricted'}
                                        </span>
                                    </div>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={device.href}>
                                            Open device
                                        </Link>
                                    </Button>
                                </div>

                                {device.it_work &&
                                device.it_work.items.length > 0 ? (
                                    <div className="space-y-2 border-t pt-4">
                                        {device.it_work.items.map((item) => (
                                            <Link
                                                key={item.id}
                                                href={item.href}
                                                className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-muted/40"
                                            >
                                                <span className="min-w-0 truncate">
                                                    {item.reference} ·{' '}
                                                    {item.title}
                                                </span>
                                                <Badge variant="outline">
                                                    {label(item.status)}
                                                </Badge>
                                            </Link>
                                        ))}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {projection.truncated ? (
                <p className="text-xs text-muted-foreground">
                    Showing the first 50 access-approved devices. Continue in
                    Security &amp; Devices for the complete register.
                </p>
            ) : null}
        </div>
    );
}
