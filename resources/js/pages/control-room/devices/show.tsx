import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertStatusChip } from '@/components/control-room/alert-worklist/alert-status';
import { PageLayout } from '@/components/page';
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
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
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
    reference_number: string | null;
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

const statusBadgeColors: Record<string, string> = {
    online: 'bg-status-success-bg text-status-success border-status-success/30',
    offline:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    maintenance:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    retired: 'bg-muted text-foreground border-border',
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
                <div className="relative flex h-24 w-24 items-center justify-center rounded-full border-8 border-muted">
                    <Battery className="h-6 w-6 text-muted-foreground" />
                </div>
                <span className="mt-2 text-xs text-muted-foreground">
                    No data
                </span>
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

function InfoRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start justify-between border-b py-2 last:border-0">
            <span className="flex-shrink-0 text-sm text-muted-foreground">
                {label}
            </span>
            <span className="ml-4 text-right text-sm font-medium">
                {children}
            </span>
        </div>
    );
}

export default function DeviceShow({ device, signals, alerts }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Devices', href: '/control-room/devices' },
                { title: device.device_uid, href: '#' },
            ]}
        >
            <Head title={`Device: ${device.name || device.device_uid}`} />

            <PageLayout>
                <CommandCentrePage
                    variant="compact"
                    current="/control-room/devices"
                    icon={Cpu}
                    title={device.name || device.device_uid}
                    description={`${device.type_label}${device.vendor || device.model ? ` · ${[device.vendor, device.model].filter(Boolean).join(' ')}` : ''}`}
                    status={`Device ${device.status}${device.is_stale ? ' · stale data' : ''}`}
                    freshness={`Last seen ${formatRelative(device.last_seen_at)}`}
                    actions={
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                        >
                            <Link href="/control-room/devices">
                                All devices
                            </Link>
                        </Button>
                    }
                >
                    {/* Info Grid */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {/* Device Info Card */}
                        <Card className="md:col-span-2">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    Device Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-1 gap-0 sm:grid-cols-2 sm:gap-x-8">
                                    <div>
                                        <InfoRow label="Device UID">
                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                {device.device_uid}
                                            </code>
                                        </InfoRow>
                                        <InfoRow label="Last Seen">
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                {formatRelative(
                                                    device.last_seen_at,
                                                )}
                                            </span>
                                        </InfoRow>
                                        <InfoRow label="Last Signal">
                                            {formatRelative(
                                                device.last_signal_at,
                                            )}
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
                                                        <span className="ml-1 text-muted-foreground">
                                                            (
                                                            {
                                                                device.asset
                                                                    .asset_tag
                                                            }
                                                            )
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
                                                            device.signal_source
                                                                .status ===
                                                            'active'
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
                                <CardTitle className="text-base">
                                    Battery
                                </CardTitle>
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
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <MapPin className="h-4 w-4" />
                                    Location
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap items-center gap-4 text-sm">
                                    {device.latitude && device.longitude && (
                                        <span className="font-mono text-muted-foreground">
                                            {device.latitude.toFixed(6)},{' '}
                                            {device.longitude.toFixed(6)}
                                        </span>
                                    )}
                                    {device.location_description && (
                                        <span>
                                            {device.location_description}
                                        </span>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Recent Signals */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Recent Signals ({signals.length})
                            </CardTitle>
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
                                                <TableHead>
                                                    Signal Type
                                                </TableHead>
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
                                                        {signal.signal_type_code ??
                                                            '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {signal.severity_hint ? (
                                                            <AlertStatusChip
                                                                kind="severity"
                                                                value={
                                                                    signal.severity_hint
                                                                }
                                                            />
                                                        ) : (
                                                            '-'
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-xs whitespace-nowrap">
                                                        {formatDateTime(
                                                            signal.occurred_at,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            {signal.status ??
                                                                '-'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="max-w-[200px]">
                                                        {signal.payload ? (
                                                            <code className="block truncate text-[10px] text-muted-foreground">
                                                                {JSON.stringify(
                                                                    signal.payload,
                                                                ).substring(
                                                                    0,
                                                                    80,
                                                                )}
                                                                {JSON.stringify(
                                                                    signal.payload,
                                                                ).length > 80
                                                                    ? '...'
                                                                    : ''}
                                                            </code>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                -
                                                            </span>
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
                            <CardTitle className="text-base">
                                Linked Alerts ({alerts.length})
                            </CardTitle>
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
                                                    <TableCell className="text-sm font-medium">
                                                        <span className="block">
                                                            {alert.alert_type}
                                                        </span>
                                                        <span className="text-xs font-normal text-muted-foreground">
                                                            {alert.reference_number ??
                                                                `Alert ${alert.id}`}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <AlertStatusChip
                                                            kind="severity"
                                                            value={
                                                                alert.severity
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <AlertStatusChip
                                                            kind="status"
                                                            value={alert.status}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-xs whitespace-nowrap">
                                                        {formatDateTime(
                                                            alert.triggered_at,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/control-room/alerts/${alert.id}`}
                                                            >
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
                </CommandCentrePage>
            </PageLayout>
        </AppLayout>
    );
}
