import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Activity,
    AlertTriangle,
    Battery,
    BatteryLow,
    BatteryWarning,
    Camera,
    Cctv,
    Cpu,
    DoorOpen,
    Locate,
    MonitorSmartphone,
    Network,
    Radio,
    Shield,
    Thermometer,
    Wifi,
    WifiOff,
} from 'lucide-react';

interface DeviceItem {
    id: number;
    name: string | null;
    device_uid: string;
    type: string;
    type_label: string;
    vendor: string | null;
    model: string | null;
    status: string;
    battery_level: number | null;
    last_seen_at: string | null;
    last_signal_at: string | null;
    is_stale: boolean;
    location_description: string | null;
    site_id: number | null;
    site_name: string | null;
    signal_source_name: string | null;
}

interface Props {
    devices: {
        data: DeviceItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    stats: {
        total: number;
        online: number;
        offline: number;
        low_battery: number;
    };
    filters: {
        type: string;
        status: string;
        site_id: string;
        low_battery: boolean;
    };
    sites: Array<{ id: number; name: string }>;
    device_types: Record<string, string>;
}

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return 'Never';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

const statusDotColor: Record<string, string> = {
    online: 'bg-status-success',
    offline: 'bg-status-critical',
    maintenance: 'bg-status-warning',
    retired: 'bg-muted',
};

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
    alarm_panel: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    bed_sensor: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    personal_tracker: 'bg-status-success-bg text-status-success border-status-success/30',
    vehicle_tracker: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    environmental: 'bg-status-info-bg text-status-info border-status-info/30',
    network: 'bg-muted text-foreground border-border',
};

function BatteryIndicator({ level }: { level: number | null }) {
    if (level === null || level === undefined) {
        return (
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Battery className="h-3.5 w-3.5 text-muted-foreground" />
                <span>N/A</span>
            </div>
        );
    }

    let color = 'bg-status-success';
    let textColor = 'text-status-success';
    let Icon = Battery;
    if (level <= 20) {
        color = 'bg-status-critical';
        textColor = 'text-status-critical';
        Icon = BatteryLow;
    } else if (level <= 50) {
        color = 'bg-status-warning';
        textColor = 'text-status-warning';
        Icon = BatteryWarning;
    }

    return (
        <div className="flex items-center gap-1.5">
            <Icon className={`h-3.5 w-3.5 ${textColor}`} />
            <div className="h-1.5 w-16 rounded-full bg-muted overflow-hidden">
                <div className={`h-full rounded-full ${color}`} style={{ width: `${level}%` }} />
            </div>
            <span className={`text-xs font-medium ${textColor}`}>{level}%</span>
        </div>
    );
}

function DeviceCard({ device }: { device: DeviceItem }) {
    let cardBg = '';
    if (device.status === 'offline') {
        cardBg = 'bg-status-critical-bg border-status-critical/50';
    } else if (device.is_stale) {
        cardBg = 'bg-status-warning-bg border-status-warning/50';
    }

    return (
        <Link href={`/control-room/devices/${device.id}`} className="block">
            <Card className={`hover:shadow-md transition-all cursor-pointer ${cardBg}`}>
                <CardContent className="pt-4 pb-4">
                    <div className="flex items-start justify-between gap-2 mb-3">
                        <div className="flex items-center gap-2 min-w-0 flex-1">
                            <span className={`inline-block h-2.5 w-2.5 rounded-full flex-shrink-0 ${statusDotColor[device.status] ?? 'bg-muted'}`} />
                            <h3 className="font-medium text-sm truncate">
                                {device.name || device.device_uid}
                            </h3>
                        </div>
                        <Badge
                            variant="outline"
                            className={`text-[10px] flex-shrink-0 flex items-center gap-1 ${typeBadgeColors[device.type] ?? ''}`}
                        >
                            {typeIcons[device.type]}
                            {device.type_label}
                        </Badge>
                    </div>

                    {device.name && (
                        <p className="text-xs text-muted-foreground mb-2 font-mono truncate">{device.device_uid}</p>
                    )}

                    {(device.vendor || device.model) && (
                        <p className="text-xs text-muted-foreground mb-2 truncate">
                            {[device.vendor, device.model].filter(Boolean).join(' ')}
                        </p>
                    )}

                    <div className="mb-2">
                        <BatteryIndicator level={device.battery_level} />
                    </div>

                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            {device.status === 'online' ? (
                                <Wifi className="h-3 w-3 text-status-success" />
                            ) : (
                                <WifiOff className="h-3 w-3 text-status-critical" />
                            )}
                            {formatRelativeTime(device.last_seen_at)}
                        </span>
                        {device.signal_source_name && (
                            <span className="flex items-center gap-1">
                                <Cpu className="h-3 w-3" />
                                {device.signal_source_name}
                            </span>
                        )}
                    </div>

                    {device.site_name && (
                        <p className="text-xs text-muted-foreground mt-1.5 truncate">
                            {device.site_name}
                        </p>
                    )}
                </CardContent>
            </Card>
        </Link>
    );
}

const typeTabMap: Array<{ value: string; label: string }> = [
    { value: '', label: 'All' },
    { value: 'camera', label: 'Camera' },
    { value: 'sensor', label: 'Sensor' },
    { value: 'personal_tracker', label: 'Tracker' },
    { value: 'door', label: 'Door' },
    { value: 'alarm_panel', label: 'Alarm Panel' },
    { value: 'bed_sensor', label: 'Bed Sensor' },
    { value: 'environmental', label: 'Environmental' },
    { value: 'network', label: 'Network' },
];

export default function DevicesIndex({ devices, stats, filters, sites }: Props) {
    const applyFilter = (key: string, value: string | boolean) => {
        const newFilters: Record<string, string | boolean | undefined> = {
            ...filters,
            [key]: value || undefined,
        };
        // Reset to page 1 on filter change
        router.get('/control-room/devices', newFilters as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Control Room', href: '/control-room' },
            { title: 'Devices', href: '#' },
        ]}>
            <Head title="Device Monitoring" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Cctv}
                        title="Device Monitoring"
                        description="Monitor IoT device health and connectivity status"
                        stats={[
                            { label: 'Total', value: stats.total },
                            { label: 'Online', value: stats.online },
                            { label: 'Offline', value: stats.offline },
                            { label: 'Low battery', value: stats.low_battery },
                        ]}
                        actions={
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room">Dashboard</Link>
                            </Button>
                        }
                    />
                }
            >
                {/* Stats Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Card>
                        <CardContent className="pt-4 pb-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                        Total Devices
                                    </p>
                                    <p className="text-2xl font-bold">{stats.total}</p>
                                </div>
                                <Cpu className="h-8 w-8 text-muted-foreground/30" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/50">
                        <CardContent className="pt-4 pb-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-medium text-status-success uppercase tracking-wider">
                                        Online
                                    </p>
                                    <p className="text-2xl font-bold text-status-success">{stats.online}</p>
                                </div>
                                <Wifi className="h-8 w-8 text-status-success" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-critical/50">
                        <CardContent className="pt-4 pb-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-medium text-status-critical uppercase tracking-wider">
                                        Offline
                                    </p>
                                    <p className="text-2xl font-bold text-status-critical">{stats.offline}</p>
                                </div>
                                <WifiOff className="h-8 w-8 text-status-critical" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-warning/50">
                        <CardContent className="pt-4 pb-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-medium text-status-warning uppercase tracking-wider">
                                        Low Battery
                                    </p>
                                    <p className="text-2xl font-bold text-status-warning">{stats.low_battery}</p>
                                </div>
                                <BatteryLow className="h-8 w-8 text-status-warning" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Type Tabs */}
                <Tabs
                    defaultValue={filters.type || 'all'}
                    onValueChange={(v) => applyFilter('type', v === 'all' ? '' : v)}
                    className="w-full"
                >
                    <TabsList className="flex flex-wrap h-auto gap-1">
                        {typeTabMap.map((tab) => (
                            <TabsTrigger key={tab.value} value={tab.value} className="text-xs">
                                {tab.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>

                {/* Filters Row */}
                <div className="flex flex-wrap items-center gap-3">
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) => applyFilter('status', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="online">Online</SelectItem>
                            <SelectItem value="offline">Offline</SelectItem>
                            <SelectItem value="maintenance">Maintenance</SelectItem>
                            <SelectItem value="retired">Retired</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.site_id || 'all'}
                        onValueChange={(v) => applyFilter('site_id', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Sites</SelectItem>
                            {sites.map((site) => (
                                <SelectItem key={site.id} value={String(site.id)}>
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button
                        variant={filters.low_battery ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => applyFilter('low_battery', !filters.low_battery)}
                        className="flex items-center gap-1.5"
                    >
                        <AlertTriangle className="h-3.5 w-3.5" />
                        Low Battery
                    </Button>
                </div>

                {/* Device Grid */}
                {devices.data.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="py-12 text-center text-sm text-muted-foreground">
                                <Cpu className="mx-auto mb-3 h-12 w-12 opacity-40" />
                                <p>No devices found matching your filters.</p>
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
                    <div className="flex justify-center gap-1 flex-wrap">
                        {devices.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(link.url, {}, { preserveState: true, preserveScroll: true })
                                }
                            >
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
