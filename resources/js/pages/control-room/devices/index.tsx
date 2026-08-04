import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Camera,
    Cctv,
    Cpu,
    DoorOpen,
    Link2,
    Locate,
    LockKeyhole,
    MonitorSmartphone,
    Network,
    Radio,
    Shield,
    ShieldCheck,
    Thermometer,
    Unlink,
} from 'lucide-react';

interface DeviceItem {
    id: number;
    name: string | null;
    device_uid: string;
    type: string;
    type_label: string;
    vendor: string | null;
    model: string | null;
    reported_battery_level: number | null;
    last_signal_at: string | null;
    signal_activity: {
        state: 'recent' | 'quiet' | 'never';
        label: string;
        tone: 'success' | 'muted';
    };
    location_description: string | null;
    site_id: number | null;
    site_name: string | null;
    signal_source_name: string | null;
    identity_source: 'canonical' | 'signal_projection';
    canonical_health_status: string | null;
    canonical_status: string | null;
    canonical_battery_level: number | null;
    canonical_last_seen_at: string | null;
    canonical_detail_url: string | null;
}

interface Props {
    devices: {
        data: DeviceItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    stats: {
        signal_sources: number;
        active_24h: number;
        canonical_linked: number | null;
        reconciliation_needed: number | null;
    };
    filters: {
        type: string;
        activity: string;
        site_id: string;
        linkage: string;
    };
    sites: Array<{ id: number; name: string }>;
    device_types: Record<string, string>;
    can: { view_canonical_devices: boolean };
    canonicalIndexUrl: string | null;
}

const typeIcons: Record<string, React.ReactNode> = {
    camera: <Camera className="h-4 w-4" />,
    door: <DoorOpen className="h-4 w-4" />,
    sensor: <Radio className="h-4 w-4" />,
    alarm_panel: <Shield className="h-4 w-4" />,
    bed_sensor: <Activity className="h-4 w-4" />,
    personal_tracker: <Locate className="h-4 w-4" />,
    vehicle_tracker: <MonitorSmartphone className="h-4 w-4" />,
    environmental: <Thermometer className="h-4 w-4" />,
    network: <Network className="h-4 w-4" />,
};

const typeBadgeColors: Record<string, string> = {
    camera: 'bg-status-info-bg text-status-info border-status-info/30',
    door: 'bg-primary/10 text-primary border-primary',
    sensor: 'bg-status-info-bg text-status-info border-status-info/30',
    alarm_panel:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    bed_sensor:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    personal_tracker:
        'bg-status-success-bg text-status-success border-status-success/30',
    vehicle_tracker:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    environmental: 'bg-status-info-bg text-status-info border-status-info/30',
    network: 'bg-muted text-foreground border-border',
};

function humanise(value: string | null): string {
    return value ? value.replaceAll('_', ' ') : 'Unknown';
}

function DeviceCard({ device }: { device: DeviceItem }) {
    const activityClass =
        device.signal_activity.tone === 'success'
            ? 'border-status-success/30 bg-status-success-bg text-status-success'
            : 'border-border bg-muted text-muted-foreground';

    return (
        <Link
            href={`/control-room/devices/${device.id}`}
            className="frontline-focus block rounded-xl"
        >
            <Card className="h-full cursor-pointer transition-all hover:shadow-md">
                <CardContent className="pt-4 pb-4">
                    <div className="mb-3 flex items-start justify-between gap-2">
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <h3 className="truncate text-sm font-medium">
                                {device.name || device.device_uid}
                            </h3>
                        </div>
                        <Badge
                            variant="outline"
                            className={`flex flex-shrink-0 items-center gap-1 text-[10px] ${typeBadgeColors[device.type] ?? ''}`}
                        >
                            {typeIcons[device.type]}
                            {device.type_label}
                        </Badge>
                    </div>

                    {device.name && (
                        <p className="mb-2 truncate font-mono text-xs text-muted-foreground">
                            {device.device_uid}
                        </p>
                    )}

                    {(device.vendor || device.model) && (
                        <p className="mb-2 truncate text-xs text-muted-foreground">
                            {[device.vendor, device.model]
                                .filter(Boolean)
                                .join(' ')}
                        </p>
                    )}

                    <div className="mb-3 space-y-2">
                        {device.identity_source === 'canonical' ? (
                            <div className="space-y-1">
                                <span className="inline-flex items-center gap-1 text-xs font-medium text-status-success">
                                    <ShieldCheck
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                    Linked managed Device
                                </span>
                                <p className="text-xs text-muted-foreground capitalize">
                                    Device health:{' '}
                                    {humanise(
                                        device.canonical_health_status ??
                                            device.canonical_status,
                                    )}
                                </p>
                            </div>
                        ) : (
                            <span className="inline-flex items-center gap-1 text-xs font-medium text-status-warning">
                                <Radio
                                    className="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                                Needs Device reconciliation
                            </span>
                        )}
                        <Badge
                            variant="outline"
                            className={`inline-flex items-center gap-1.5 ${activityClass}`}
                        >
                            <Activity
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            {device.signal_activity.label}
                        </Badge>
                    </div>

                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            <Radio className="h-3 w-3" aria-hidden="true" />
                            Last signal {formatRelative(device.last_signal_at)}
                        </span>
                        {device.signal_source_name && (
                            <span className="flex items-center gap-1">
                                <Cpu className="h-3 w-3" />
                                {device.signal_source_name}
                            </span>
                        )}
                    </div>

                    {device.site_name && (
                        <p className="mt-1.5 truncate text-xs text-muted-foreground">
                            Signal Site: {device.site_name}
                        </p>
                    )}
                </CardContent>
            </Card>
        </Link>
    );
}

export default function DevicesIndex({
    devices,
    stats,
    filters,
    sites,
    device_types: deviceTypes,
    can,
    canonicalIndexUrl,
}: Props) {
    const typeTabs = [
        { value: 'all', label: 'All' },
        ...Object.entries(deviceTypes).map(([value, label]) => ({
            value,
            label,
        })),
    ];
    const applyFilter = (key: string, value: string) => {
        const newFilters: Record<string, string | undefined> = {
            ...filters,
            [key]: value || undefined,
        };
        // Reset to page 1 on filter change
        router.get(
            '/control-room/devices',
            newFilters as Record<string, string>,
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Device signals', href: '/control-room/devices' },
            ]}
        >
            <Head title="Device signals" />

            <PageLayout>
                <CommandCentrePage
                    variant="compact"
                    current="/control-room/devices"
                    icon={Cctv}
                    title="Device signals"
                    description="Review Control Room signal activity and reconcile each source with the canonical Security & Devices registry. Device health and management remain in Security & Devices."
                    status="Control Room signal projection"
                    freshness={`${stats.active_24h} active in the last 24 hours`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {canonicalIndexUrl ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    asChild
                                    className="frontline-tap border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href={canonicalIndexUrl}>
                                        <ShieldCheck
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Open Device registry
                                    </Link>
                                </Button>
                            ) : null}
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="frontline-tap border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room/map">
                                    Open live map
                                </Link>
                            </Button>
                        </div>
                    }
                >
                    {/* Stats Cards */}
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Card>
                            <CardContent className="pt-4 pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                            Signal sources
                                        </p>
                                        <p className="text-2xl font-bold">
                                            {stats.signal_sources}
                                        </p>
                                    </div>
                                    <Cpu className="h-8 w-8 text-muted-foreground/30" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-status-success/50">
                            <CardContent className="pt-4 pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium tracking-wider text-status-success uppercase">
                                            Active in 24h
                                        </p>
                                        <p className="text-2xl font-bold text-status-success">
                                            {stats.active_24h}
                                        </p>
                                    </div>
                                    <Activity className="h-8 w-8 text-status-success" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="pt-4 pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                            Linked Devices
                                        </p>
                                        {stats.canonical_linked === null ? (
                                            <p className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground">
                                                <LockKeyhole
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
                                                Restricted
                                            </p>
                                        ) : (
                                            <p className="text-2xl font-bold">
                                                {stats.canonical_linked}
                                            </p>
                                        )}
                                    </div>
                                    <Link2 className="h-8 w-8 text-muted-foreground/30" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-status-warning/50">
                            <CardContent className="pt-4 pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium tracking-wider text-status-warning uppercase">
                                            Needs reconciliation
                                        </p>
                                        {stats.reconciliation_needed ===
                                        null ? (
                                            <p className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground">
                                                <LockKeyhole
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
                                                Restricted
                                            </p>
                                        ) : (
                                            <p className="text-2xl font-bold text-status-warning">
                                                {stats.reconciliation_needed}
                                            </p>
                                        )}
                                    </div>
                                    <Unlink className="h-8 w-8 text-status-warning" />
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Type Tabs */}
                    <Tabs
                        defaultValue={filters.type || 'all'}
                        onValueChange={(v) =>
                            applyFilter('type', v === 'all' ? '' : v)
                        }
                        className="w-full"
                    >
                        <TabsList className="flex h-auto flex-wrap gap-1">
                            {typeTabs.map((tab) => (
                                <TabsTrigger
                                    key={tab.value}
                                    value={tab.value}
                                    className="text-xs"
                                >
                                    {tab.label}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>

                    {/* Filters Row */}
                    <div className="flex flex-wrap items-center gap-3">
                        <Select
                            value={filters.activity || 'all'}
                            onValueChange={(v) =>
                                applyFilter('activity', v === 'all' ? '' : v)
                            }
                        >
                            <SelectTrigger
                                className="w-52"
                                aria-label="Signal activity"
                            >
                                <SelectValue placeholder="Signal activity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All signal activity
                                </SelectItem>
                                <SelectItem value="recent">
                                    Active in last 24 hours
                                </SelectItem>
                                <SelectItem value="quiet">
                                    No signal in last 24 hours
                                </SelectItem>
                                <SelectItem value="never">
                                    No signal recorded
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.site_id || 'all'}
                            onValueChange={(v) =>
                                applyFilter('site_id', v === 'all' ? '' : v)
                            }
                        >
                            <SelectTrigger
                                className="w-48"
                                aria-label="Signal Site"
                            >
                                <SelectValue placeholder="Site" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {sites.map((site) => (
                                    <SelectItem
                                        key={site.id}
                                        value={String(site.id)}
                                    >
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {can.view_canonical_devices ? (
                            <Select
                                value={filters.linkage || 'all'}
                                onValueChange={(v) =>
                                    applyFilter('linkage', v === 'all' ? '' : v)
                                }
                            >
                                <SelectTrigger
                                    className="w-52"
                                    aria-label="Device reconciliation"
                                >
                                    <SelectValue placeholder="Device reconciliation" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All reconciliation states
                                    </SelectItem>
                                    <SelectItem value="linked">
                                        Linked managed Devices
                                    </SelectItem>
                                    <SelectItem value="unlinked">
                                        Needs reconciliation
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        ) : null}
                    </div>

                    {/* Device Grid */}
                    {devices.data.length === 0 ? (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="py-12 text-center text-sm text-muted-foreground">
                                    <Cpu className="mx-auto mb-3 h-12 w-12 opacity-40" />
                                    <p>
                                        No signal sources match these filters.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {devices.data.map((device) => (
                                <DeviceCard key={device.id} device={device} />
                            ))}
                        </div>
                    )}

                    {/* Pagination */}
                    {devices.links?.length > 3 && (
                        <div className="flex flex-wrap justify-center gap-1">
                            {devices.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                </Button>
                            ))}
                        </div>
                    )}
                </CommandCentrePage>
            </PageLayout>
        </AppLayout>
    );
}
