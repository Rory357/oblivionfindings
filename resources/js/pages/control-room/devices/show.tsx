import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertStatusChip } from '@/components/control-room/alert-worklist/alert-status';
import { PageLayout } from '@/components/page';
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
    AlertTriangle,
    Battery,
    BatteryLow,
    BatteryWarning,
    Camera,
    CircleCheck,
    CircleX,
    Clock,
    Cpu,
    DoorOpen,
    Locate,
    MapPin,
    MonitorSmartphone,
    Network,
    Radio,
    Shield,
    ShieldCheck,
    Thermometer,
} from 'lucide-react';

interface SignalItem {
    id: number;
    signal_type_code: string | null;
    severity_hint: string | null;
    occurred_at: string | null;
    status: string | null;
    outcome: {
        label: string;
        tone: 'success' | 'critical' | 'warning' | 'muted';
        alert_reference: string | null;
        href: string | null;
    };
}

interface AlertItem {
    id: number;
    reference_number: string | null;
    alert_type: string;
    severity: string;
    status: string;
    health_status: string | null;
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
    reported_battery_level: number | null;
    last_signal_at: string | null;
    signal_activity: {
        state: 'recent' | 'quiet' | 'never';
        label: string;
        tone: 'success' | 'muted';
    };
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    identity_source: 'canonical' | 'signal_projection';
    canonical: {
        id: number;
        domain: string;
        category: string;
        subcategory: string | null;
        status: string | null;
        health_status: string | null;
        battery_level: number | null;
        last_seen_at: string | null;
        detail_url: string;
    } | null;
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

function BatteryGauge({
    level,
    label,
}: {
    level: number | null;
    label: string;
}) {
    if (level === null || level === undefined) {
        return (
            <div className="flex flex-col items-center justify-center">
                <div className="relative flex h-24 w-24 items-center justify-center rounded-full border-8 border-muted">
                    <Battery className="h-6 w-6 text-muted-foreground" />
                </div>
                <span className="mt-2 text-xs text-muted-foreground">
                    {label}: no data
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
            <span className="mt-1 text-xs text-muted-foreground">{label}</span>
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

const outcomeToneClasses: Record<SignalItem['outcome']['tone'], string> = {
    success:
        'border-status-success/30 bg-status-success-bg text-status-success',
    critical:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    muted: 'border-border bg-muted text-muted-foreground',
};

function SignalOutcome({ outcome }: { outcome: SignalItem['outcome'] }) {
    const Icon =
        outcome.tone === 'success'
            ? CircleCheck
            : outcome.tone === 'critical'
              ? CircleX
              : outcome.tone === 'warning'
                ? AlertTriangle
                : Shield;
    const content = (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-xs font-medium ${outcomeToneClasses[outcome.tone]}`}
        >
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            <span>{outcome.label}</span>
            {outcome.alert_reference ? (
                <span className="font-mono">{outcome.alert_reference}</span>
            ) : null}
        </span>
    );

    return outcome.href ? <Link href={outcome.href}>{content}</Link> : content;
}

export default function DeviceShow({ device, signals, alerts }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Device signals', href: '/control-room/devices' },
                {
                    title: device.device_uid,
                    href: `/control-room/devices/${device.id}`,
                },
            ]}
        >
            <Head
                title={`Signal source: ${device.name || device.device_uid}`}
            />

            <PageLayout>
                <CommandCentrePage
                    variant="compact"
                    current="/control-room/devices"
                    icon={Cpu}
                    title={device.name || device.device_uid}
                    description={`${device.type_label}${device.vendor || device.model ? ` · ${[device.vendor, device.model].filter(Boolean).join(' ')}` : ''}`}
                    status={device.signal_activity.label}
                    freshness={`Last signal ${formatRelative(device.last_signal_at)}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="frontline-tap border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room/devices">
                                    All signal sources
                                </Link>
                            </Button>
                            {device.canonical ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    asChild
                                    className="frontline-tap border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href={device.canonical.detail_url}>
                                        <ShieldCheck
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Open in Security &amp; Devices
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                >
                    <Card
                        className={
                            device.canonical
                                ? 'border-status-success/40 bg-status-success-bg'
                                : 'border-status-warning/40 bg-status-warning-bg'
                        }
                    >
                        <CardContent className="flex items-start gap-3 pt-4 pb-4">
                            {device.canonical ? (
                                <ShieldCheck
                                    className="mt-0.5 h-5 w-5 flex-none text-status-success"
                                    aria-hidden="true"
                                />
                            ) : (
                                <AlertTriangle
                                    className="mt-0.5 h-5 w-5 flex-none text-status-warning"
                                    aria-hidden="true"
                                />
                            )}
                            <div className="space-y-1">
                                <p className="text-sm font-semibold">
                                    {device.canonical
                                        ? 'Canonical Security & Devices identity'
                                        : 'Signal-only Control Room projection'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {device.canonical
                                        ? `Identity, assignment, monitoring and management remain owned by Security & Devices. Device status is ${device.canonical.status ?? 'unknown'} and health is ${device.canonical.health_status ?? 'unknown'}. This page shows the Control Room signal journey.`
                                        : 'No authorised canonical Device is linked. Reconcile this signal source in Security & Devices before treating it as managed inventory.'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Info Grid */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {/* Signal context card */}
                        <Card className="md:col-span-2">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    Signal source context
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-1 gap-0 sm:grid-cols-2 sm:gap-x-8">
                                    <div>
                                        <InfoRow label="Projection UID">
                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                {device.device_uid}
                                            </code>
                                        </InfoRow>
                                        <InfoRow label="Last Signal">
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                {formatRelative(
                                                    device.last_signal_at,
                                                )}
                                            </span>
                                        </InfoRow>
                                        <InfoRow label="Signal Site">
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

                        {/* Explicitly sourced battery context */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    {device.canonical
                                        ? 'Canonical Device battery'
                                        : 'Reported battery'}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex items-center justify-center pt-2">
                                <BatteryGauge
                                    level={
                                        device.canonical
                                            ? device.canonical.battery_level
                                            : device.reported_battery_level
                                    }
                                    label={
                                        device.canonical
                                            ? 'Security & Devices'
                                            : 'Signal report'
                                    }
                                />
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
                                                <TableHead>Outcome</TableHead>
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
                                                        <SignalOutcome
                                                            outcome={
                                                                signal.outcome
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                            <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                                Raw provider payloads are kept out of this
                                operational view. Use the linked alert or the
                                canonical Device profile for governed evidence.
                            </p>
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
