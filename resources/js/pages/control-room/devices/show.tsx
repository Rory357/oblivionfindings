import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Activity,
    ArrowLeft,
    Battery,
    BatteryLow,
    BatteryWarning,
    Camera,
    Clock,
    Cpu,
    DoorOpen,
    Locate,
    MapPin,
    MonitorSmartphone,
    Network,
    Radio,
    Shield,
    Thermometer,
    Wifi,
    WifiOff,
} from 'lucide-react';

interface SignalItem {
    id: number;
    signal_type_code: string | null;
    severity_hint: string | null;
    occurred_at: string | null;
    status: string | null;
    payload: Record<string, unknown> | null;
}

interface AlertItem {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
}

interface DeviceDetail {
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
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    config: Record<string, unknown> | null;
    signal_source: {
        id: number;
        name: string;
        status: string;
        vendor: string | null;
    } | null;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
}

interface Props {
    device: DeviceDetail;
    signals: SignalItem[];
    alerts: AlertItem[];
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

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const statusBadgeColors: Record<string, string> = {
    online: 'bg-status-success-bg text-status-success border-status-success/30',
    offline: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    maintenance: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    retired: 'bg-muted text-foreground border-border',
};

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-success text-white',
};

const alertStatusColors: Record<string, string> = {
    open: 'bg-status-critical-bg text-status-critical',
    ack: 'bg-status-warning-bg text-status-warning',
    triaging: 'bg-status-info-bg text-status-info',
    resolved: 'bg-status-success-bg text-status-success',
    closed: 'bg-muted text-foreground',
};

const typeIcons: Record<string, React.ReactNode> = {
    camera: <Camera className="h-5 w-5" />,
    door: <DoorOpen className="h-5 w-5" />,
    sensor: <Radio className="h-5 w-5" />,
    alarm_panel: <Shield className="h-5 w-5" />,
    bed_sensor: <Activity className="h-5 w-5" />,
    personal_tracker: <Locate className="h-5 w-5" />,
    vehicle_tracker: <MonitorSmartphone className="h-5 w-5" />,
    environmental: <Thermometer className="h-5 w-5" />,
    network: <Network className="h-5 w-5" />,
};

function BatteryGauge({ level }: { level: number | null }) {
    if (level === null || level === undefined) {
        return (
            <div className="flex flex-col items-center justify-center">
                <div className="relative h-24 w-24 rounded-full border-8 border-muted flex items-center justify-center">
                    <Battery className="h-6 w-6 text-muted-foreground" />
                </div>
                <span className="mt-2 text-xs text-muted-foreground">No data</span>
            </div>
        );
    }

    let strokeColor = '#22c55e'; // green
    let Icon = Battery;
    if (level <= 20) {
        strokeColor = '#ef4444'; // red
        Icon = BatteryLow;
    } else if (level <= 50) {
        strokeColor = '#eab308'; // yellow
        Icon = BatteryWarning;
    }

    const circumference = 2 * Math.PI * 42;
    const offset = circumference - (level / 100) * circumference;

    return (
        <div className="flex flex-col items-center justify-center">
            <div className="relative h-24 w-24">
                <svg className="h-24 w-24 -rotate-90" viewBox="0 0 100 100">
                    <circle
                        cx="50"
                        cy="50"
                        r="42"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="8"
                        className="text-muted"
                    />
                    <circle
                        cx="50"
                        cy="50"
                        r="42"
                        fill="none"
                        stroke={strokeColor}
                        strokeWidth="8"
                        strokeDasharray={circumference}
                        strokeDashoffset={offset}
                        strokeLinecap="round"
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-lg font-bold">{level}%</span>
                </div>
            </div>
            <span className="mt-1 text-xs text-muted-foreground">Battery</span>
        </div>
    );
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between py-2 border-b last:border-0">
            <span className="text-sm text-muted-foreground flex-shrink-0">{label}</span>
            <span className="text-sm font-medium text-right ml-4">{children}</span>
        </div>
    );
}

export default function DeviceShow({ device, signals, alerts }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Control Room', href: '/control-room' },
            { title: 'Devices', href: '/control-room/devices' },
            { title: device.device_uid, href: '#' },
        ]}>
            <Head title={`Device: ${device.name || device.device_uid}`} />

            <div className="flex flex-col gap-4 p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <div className="mt-1 flex items-center justify-center h-10 w-10 rounded-lg bg-muted">
                            {typeIcons[device.type] ?? <Cpu className="h-5 w-5" />}
                        </div>
                        <div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-2xl font-bold">
                                    {device.name || device.device_uid}
                                </h1>
                                <Badge variant="outline" className={statusBadgeColors[device.status] ?? ''}>
                                    {device.status === 'online' && <Wifi className="h-3 w-3 mr-1" />}
                                    {device.status === 'offline' && <WifiOff className="h-3 w-3 mr-1" />}
                                    {device.status}
                                </Badge>
                                {device.is_stale && (
                                    <Badge variant="outline" className="bg-status-warning-bg text-status-warning border-status-warning/30">
                                        Stale
                                    </Badge>
                                )}
                            </div>
                            <div className="flex items-center gap-2 mt-1 text-sm text-muted-foreground">
                                <Badge variant="secondary" className="text-xs">
                                    {device.type_label}
                                </Badge>
                                {(device.vendor || device.model) && (
                                    <span>
                                        {[device.vendor, device.model].filter(Boolean).join(' ')}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room/devices">
                            <ArrowLeft className="h-4 w-4 mr-1" />
                            Back
                        </Link>
                    </Button>
                </div>

                {/* Info Grid */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    {/* Device Info Card */}
                    <Card className="md:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Device Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-0 sm:grid-cols-2 sm:gap-x-8">
                                <div>
                                    <InfoRow label="Device UID">
                                        <code className="text-xs bg-muted px-1.5 py-0.5 rounded">
                                            {device.device_uid}
                                        </code>
                                    </InfoRow>
                                    <InfoRow label="Last Seen">
                                        <span className="flex items-center gap-1">
                                            <Clock className="h-3 w-3" />
                                            {formatRelativeTime(device.last_seen_at)}
                                        </span>
                                    </InfoRow>
                                    <InfoRow label="Last Signal">
                                        {formatRelativeTime(device.last_signal_at)}
                                    </InfoRow>
                                    <InfoRow label="Site">
                                        {device.site?.name ?? '-'}
                                    </InfoRow>
                                </div>
                                <div>
                                    <InfoRow label="Client">
                                        {device.client?.name ?? '-'}
                                    </InfoRow>
                                    <InfoRow label="Asset">
                                        {device.asset ? (
                                            <span>
                                                {device.asset.name}
                                                {device.asset.asset_tag && (
                                                    <span className="text-muted-foreground ml-1">
                                                        ({device.asset.asset_tag})
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            '-'
                                        )}
                                    </InfoRow>
                                    <InfoRow label="Signal Source">
                                        {device.signal_source ? (
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    className={`inline-block h-2 w-2 rounded-full ${
                                                        device.signal_source.status === 'active'
                                                            ? 'bg-status-success'
                                                            : 'bg-muted'
                                                    }`}
                                                />
                                                {device.signal_source.name}
                                            </span>
                                        ) : (
                                            '-'
                                        )}
                                    </InfoRow>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Battery Gauge Card */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Battery</CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-center pt-2">
                            <BatteryGauge level={device.battery_level} />
                        </CardContent>
                    </Card>
                </div>

                {/* Location Card */}
                {(device.latitude || device.location_description) && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base flex items-center gap-2">
                                <MapPin className="h-4 w-4" />
                                Location
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-4 text-sm">
                                {device.latitude && device.longitude && (
                                    <span className="font-mono text-muted-foreground">
                                        {device.latitude.toFixed(6)}, {device.longitude.toFixed(6)}
                                    </span>
                                )}
                                {device.location_description && (
                                    <span>{device.location_description}</span>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Recent Signals */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Recent Signals ({signals.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {signals.length === 0 ? (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                No signals recorded for this device.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Signal Type</TableHead>
                                            <TableHead>Severity</TableHead>
                                            <TableHead>Occurred</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Payload</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {signals.map((signal) => (
                                            <TableRow key={signal.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {signal.signal_type_code ?? '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {signal.severity_hint ? (
                                                        <Badge
                                                            className={`text-[10px] ${severityColors[signal.severity_hint] ?? 'bg-muted text-foreground'}`}
                                                        >
                                                            {signal.severity_hint}
                                                        </Badge>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-xs whitespace-nowrap">
                                                    {formatDateTime(signal.occurred_at)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {signal.status ?? '-'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="max-w-[200px]">
                                                    {signal.payload ? (
                                                        <code className="text-[10px] text-muted-foreground truncate block">
                                                            {JSON.stringify(signal.payload).substring(0, 80)}
                                                            {JSON.stringify(signal.payload).length > 80 ? '...' : ''}
                                                        </code>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Linked Alerts */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Linked Alerts ({alerts.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {alerts.length === 0 ? (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                No alerts linked to this device.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Severity</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Triggered</TableHead>
                                            <TableHead className="w-20"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {alerts.map((alert) => (
                                            <TableRow key={alert.id}>
                                                <TableCell className="font-medium text-sm">
                                                    {alert.alert_type}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        className={`text-[10px] ${severityColors[alert.severity] ?? 'bg-muted text-foreground'}`}
                                                    >
                                                        {alert.severity}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[10px] ${alertStatusColors[alert.status] ?? ''}`}
                                                    >
                                                        {alert.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-xs whitespace-nowrap">
                                                    {formatDateTime(alert.triggered_at)}
                                                </TableCell>
                                                <TableCell>
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/control-room/alerts/${alert.id}`}>
                                                            View
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
