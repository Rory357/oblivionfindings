import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Cable,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Cpu,
    FileClock,
    HardDrive,
    MapPin,
    RadioTower,
    Settings2,
    ShieldCheck,
    TicketCheck,
} from 'lucide-react';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';

function stateBadgeVariant(
    state: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (['critical', 'failed', 'offline'].includes(state ?? '')) {
        return 'destructive';
    }
    if (['healthy', 'active', 'fresh', 'aligned'].includes(state ?? '')) {
        return 'default';
    }
    if (
        [
            'warning',
            'degraded',
            'stale',
            'update_available',
            'drifted',
        ].includes(state ?? '')
    ) {
        return 'outline';
    }
    return 'secondary';
}

function humanise(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    return value.replace(/[._-]+/g, ' ');
}

function Metric({
    label,
    value,
    detail,
}: {
    label: string;
    value: string | number | null | undefined;
    detail?: string | null;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact metric tile is a definition-list-like atom, not a standalone card.
        <div className="min-w-0 rounded-lg border bg-card p-3">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 truncate text-sm font-semibold">
                {value ?? 'Not recorded'}
            </p>
            {detail && (
                <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
            )}
        </div>
    );
}

export function DeviceProfileHeader({
    profile,
    onOpenSection,
}: {
    profile: DeviceProfile;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const { header } = profile;
    const ActionIcon =
        header.requiredAction.state === 'none'
            ? CheckCircle2
            : header.requiredAction.state === 'critical'
              ? AlertTriangle
              : Clock3;

    return (
        <Card data-testid="device-profile-header" className="overflow-hidden">
            <CardContent className="space-y-4 p-4 md:p-5">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div className="sm:col-span-2 xl:col-span-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Identity
                        </p>
                        <p className="mt-1 font-semibold">
                            {header.identity.type || 'Registered device'}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground">
                            {header.identity.uid}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Location
                        </p>
                        {header.location?.href ? (
                            <Link
                                href={header.location.href}
                                className="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                            >
                                <MapPin className="h-3.5 w-3.5" />
                                {header.location.name}
                            </Link>
                        ) : (
                            <p className="mt-1 text-sm font-semibold">
                                {header.location?.name ?? 'Unassigned'}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {header.assignment
                                ? `${humanise(header.assignment.type)} assignment`
                                : 'No current assignment'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Health
                        </p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            <Badge
                                variant={stateBadgeVariant(header.health.state)}
                            >
                                {header.health.label}
                            </Badge>
                            <Badge variant="outline">
                                {humanise(header.health.deviceState)}
                            </Badge>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Freshness
                        </p>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge
                                variant={stateBadgeVariant(
                                    header.freshness.state,
                                )}
                            >
                                {humanise(header.freshness.state)}
                            </Badge>
                            <span
                                className="text-xs text-muted-foreground"
                                title={formatDateTime(
                                    header.freshness.observedAt,
                                )}
                            >
                                {formatRelative(
                                    header.freshness.observedAt,
                                    Date.now(),
                                    'Never observed',
                                )}
                            </span>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Observation source
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            {header.providerObservation.label}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {humanise(header.providerObservation.source)}
                        </p>
                    </div>
                </div>

                <div
                    className={`flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:items-center sm:justify-between ${
                        header.requiredAction.state === 'critical'
                            ? 'border-destructive/30 bg-destructive/5'
                            : 'bg-muted/30'
                    }`}
                >
                    <div className="flex min-w-0 gap-3">
                        <ActionIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p className="text-sm font-semibold">
                                {header.requiredAction.label}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {header.requiredAction.description}
                            </p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="shrink-0"
                        onClick={() =>
                            onOpenSection(header.requiredAction.section)
                        }
                    >
                        Open details <ArrowRight className="ml-1 h-3.5 w-3.5" />
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export function DeviceHealthSection({ profile }: { profile: DeviceProfile }) {
    const monitoring = profile.health.monitoring;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Metric
                    label="Device state"
                    value={humanise(profile.health.deviceState)}
                    detail={`Health: ${humanise(profile.health.state)}`}
                />
                <Metric
                    label="Last seen"
                    value={formatRelative(
                        profile.health.lastSeenAt,
                        Date.now(),
                        'Never observed',
                    )}
                    detail={formatDateTime(
                        profile.health.lastSeenAt,
                        'No timestamp',
                    )}
                />
                <Metric
                    label="Last signal"
                    value={formatRelative(
                        profile.health.lastSignalAt,
                        Date.now(),
                        'Not collected',
                    )}
                    detail={formatDateTime(
                        profile.health.lastSignalAt,
                        'No signal timestamp',
                    )}
                />
                <Metric
                    label="Battery"
                    value={
                        profile.health.batteryLevel === null
                            ? 'Not collected'
                            : `${profile.health.batteryLevel}%`
                    }
                    detail={
                        profile.health.batteryUpdatedAt
                            ? `Updated ${formatRelative(profile.health.batteryUpdatedAt)}`
                            : null
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Activity className="h-4 w-4" /> Native monitoring
                        summary
                    </CardTitle>
                    <CardDescription>
                        Current retained checks for this device. Missing
                        evidence is shown honestly as not collected.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {monitoring ? (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Metric
                                label="Enabled"
                                value={monitoring.enabled}
                            />
                            <Metric
                                label="Healthy"
                                value={monitoring.healthy}
                            />
                            <Metric
                                label="Needs attention"
                                value={monitoring.attention}
                            />
                            <Metric
                                label="Uncertain"
                                value={monitoring.uncertain}
                            />
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            You can see this device record, but your role cannot
                            open monitoring evidence.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card className="border-dashed">
                <CardContent className="flex gap-3 p-4">
                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                    <div>
                        <p className="text-sm font-semibold">
                            Governed management boundary
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {profile.capabilities.control.reason}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

export function DeviceMonitorsSection({ profile }: { profile: DeviceProfile }) {
    if (profile.monitors.length === 0) {
        return (
            <EmptyState
                icon={RadioTower}
                title="No monitoring coverage"
                description="No native checks are assigned to this device. Add coverage from Monitoring when the device capability and your role allow it."
                variant="compact"
            />
        );
    }

    return (
        <div className="grid gap-3 lg:grid-cols-2">
            {profile.monitors.map((monitor) => (
                <Card key={monitor.id}>
                    <CardContent className="space-y-3 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate font-semibold">
                                    {monitor.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {monitor.kindLabel}
                                    {monitor.affectsAvailability
                                        ? ' · affects availability'
                                        : ''}
                                </p>
                            </div>
                            <Badge variant={stateBadgeVariant(monitor.state)}>
                                {monitor.enabled
                                    ? humanise(monitor.state)
                                    : 'disabled'}
                            </Badge>
                        </div>
                        <div className="grid grid-cols-2 gap-2 text-xs">
                            <Metric
                                label="Last observation"
                                value={formatRelative(
                                    monitor.lastObservationAt,
                                    Date.now(),
                                    'Never observed',
                                )}
                            />
                            <Metric
                                label="Monitoring profile"
                                value={monitor.profile?.name ?? 'Not assigned'}
                            />
                        </div>
                        {monitor.collector && (
                            <div className="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2 text-xs">
                                <span>{monitor.collector.name}</span>
                                <span className="text-muted-foreground">
                                    Collector{' '}
                                    {humanise(monitor.collector.status)}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function formatRate(value: number | null): string {
    if (value === null) return 'Not collected';
    if (value >= 1_000_000_000)
        return `${(value / 1_000_000_000).toFixed(1)} Gbps`;
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)} Mbps`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)} Kbps`;
    return `${value} bps`;
}

export function DeviceInterfacesSensorsSection({
    profile,
}: {
    profile: DeviceProfile;
}) {
    if (profile.interfacesSensors.length === 0) {
        return (
            <EmptyState
                icon={Cable}
                title="No interface or sensor evidence"
                description="Native collection has not retained interface or sensor observations for this device."
                variant="compact"
            />
        );
    }

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            {profile.interfacesSensors.map((sensor) => (
                <Card key={sensor.monitorId}>
                    <CardHeader className="pb-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <CardTitle className="text-base">
                                    {sensor.name}
                                </CardTitle>
                                <CardDescription>
                                    {humanise(sensor.kind)}
                                    {sensor.index !== null
                                        ? ` · interface ${sensor.index}`
                                        : ''}
                                </CardDescription>
                            </div>
                            <Badge variant={stateBadgeVariant(sensor.state)}>
                                {humanise(sensor.state)}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <Metric
                            label="Reading"
                            value={
                                sensor.value === null
                                    ? 'Not collected'
                                    : `${sensor.value}${sensor.unit ?? ''}`
                            }
                        />
                        <Metric
                            label="In / out"
                            value={`${formatRate(sensor.inBps)} / ${formatRate(sensor.outBps)}`}
                        />
                        <Metric
                            label="Utilisation"
                            value={`${sensor.inUtilisation ?? '—'}% / ${sensor.outUtilisation ?? '—'}%`}
                        />
                        <Metric
                            label="Link state"
                            value={
                                sensor.operationalStatus ??
                                sensor.adminStatus ??
                                'Not collected'
                            }
                        />
                        <Metric
                            label="Errors / discards"
                            value={`${sensor.errors ?? '—'} / ${sensor.discards ?? '—'}`}
                        />
                        <Metric
                            label="Observed"
                            value={formatRelative(
                                sensor.observedAt,
                                Date.now(),
                                'Never',
                            )}
                        />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export function DeviceConfigurationSection({
    profile,
    editHref,
    onEditServiceDue,
}: {
    profile: DeviceProfile;
    editHref: string;
    onEditServiceDue?: () => void;
}) {
    const { registry, configuration, firmware } = profile.configuration;

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <HardDrive className="h-4 w-4" /> Registry identity
                        </CardTitle>
                        <CardDescription>
                            Deliberately allowlisted device fields. Provider
                            payloads and credential-shaped configuration are not
                            exposed here.
                        </CardDescription>
                    </div>
                    {profile.capabilities.registry.available && (
                        <div className="flex flex-wrap justify-end gap-2">
                            {onEditServiceDue && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={onEditServiceDue}
                                >
                                    Update service date
                                </Button>
                            )}
                            <Button size="sm" variant="outline" asChild>
                                <Link href={editHref}>Edit registry</Link>
                            </Button>
                        </div>
                    )}
                </CardHeader>
                <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric
                        label="Manufacturer"
                        value={registry.manufacturer}
                    />
                    <Metric label="Model" value={registry.model} />
                    <Metric label="Serial" value={registry.serialNumber} />
                    <Metric label="Asset tag" value={registry.assetTag} />
                    <Metric label="IP address" value={registry.ipAddress} />
                    <Metric label="MAC address" value={registry.macAddress} />
                    <Metric label="IMEI" value={registry.imei} />
                    <Metric
                        label="Next service due"
                        value={formatDate(registry.nextServiceDue)}
                    />
                    <Metric
                        label="Commissioned"
                        value={formatDate(registry.commissionedAt)}
                    />
                    <Metric
                        label="Warranty expires"
                        value={formatDate(registry.warrantyExpiresAt)}
                    />
                    <Metric
                        label="Expected lifespan"
                        value={
                            registry.expectedLifespanMonths === null
                                ? null
                                : `${registry.expectedLifespanMonths} months`
                        }
                    />
                    <Metric
                        label="Purchase price"
                        value={
                            registry.purchasePrice === null
                                ? null
                                : new Intl.NumberFormat('en-NZ', {
                                      style: 'currency',
                                      currency: 'NZD',
                                  }).format(Number(registry.purchasePrice))
                        }
                    />
                    <Metric
                        label="Groups"
                        value={
                            registry.groups.length > 0
                                ? registry.groups
                                      .map((group) => group.name)
                                      .join(', ')
                                : null
                        }
                    />
                    <Metric
                        label="Registered by"
                        value={registry.createdBy?.name}
                        detail={formatDateTime(registry.createdAt)}
                    />
                </CardContent>
                {registry.notes && (
                    <CardContent className="pt-0">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Registry notes
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">
                            {registry.notes}
                        </p>
                    </CardContent>
                )}
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Settings2 className="h-4 w-4" /> Configuration
                            evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Badge variant={stateBadgeVariant(configuration.state)}>
                            {humanise(configuration.state)}
                        </Badge>
                        <Metric
                            label="Observed hash"
                            value={configuration.observedHash}
                            detail={formatDateTime(
                                configuration.observedAt,
                                'Observation time not collected',
                            )}
                        />
                        <Metric
                            label="Desired hash"
                            value={configuration.desiredHash}
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Cpu className="h-4 w-4" /> Firmware evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Badge variant={stateBadgeVariant(firmware.state)}>
                            {humanise(firmware.state)}
                        </Badge>
                        <Metric
                            label="Current version"
                            value={firmware.currentVersion}
                            detail={formatDateTime(
                                firmware.observedAt,
                                'Observation time not collected',
                            )}
                        />
                        <Metric
                            label="Desired version"
                            value={firmware.desiredVersion}
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

export function DeviceTicketsSection({ profile }: { profile: DeviceProfile }) {
    if (profile.tickets.length === 0) {
        return (
            <EmptyState
                icon={TicketCheck}
                title="No linked IT work"
                description="Tickets linked with this device as an affected device will appear here."
                variant="compact"
            />
        );
    }

    return (
        <div className="space-y-2">
            {profile.tickets.map((ticket) => (
                <Link
                    key={ticket.id}
                    href={ticket.href}
                    className="flex min-h-16 flex-col gap-2 rounded-xl border bg-card p-4 transition-colors hover:border-primary/40 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-xs font-semibold text-primary">
                                {ticket.reference}
                            </span>
                            <Badge variant={stateBadgeVariant(ticket.status)}>
                                {humanise(ticket.status)}
                            </Badge>
                            <Badge variant="outline">
                                {humanise(ticket.priority)}
                            </Badge>
                        </div>
                        <p className="mt-1 truncate text-sm font-semibold">
                            {ticket.title}
                        </p>
                        {ticket.nextAction && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Next: {ticket.nextAction}
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        {humanise(ticket.workType)}
                        <ArrowRight className="h-4 w-4" />
                    </div>
                </Link>
            ))}
        </div>
    );
}

export function DeviceAuditSection({ profile }: { profile: DeviceProfile }) {
    if (profile.audit.length === 0) {
        return (
            <EmptyState
                icon={FileClock}
                title="No audit entries"
                description="Canonical device changes will appear here without exposing raw before-and-after payloads."
                variant="compact"
            />
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ClipboardList className="h-4 w-4" /> Device audit
                </CardTitle>
                <CardDescription>
                    Who changed the canonical device record and which fields
                    were involved. Raw values remain protected.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
                {profile.audit.map((entry) => (
                    <div
                        key={entry.id}
                        className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                {humanise(entry.action)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {entry.actor ?? 'System'}
                                {entry.fields.length > 0
                                    ? ` · ${entry.fields.map(humanise).join(', ')}`
                                    : ''}
                            </p>
                        </div>
                        <span
                            className="shrink-0 text-xs text-muted-foreground"
                            title={formatDateTime(entry.createdAt)}
                        >
                            {formatRelative(entry.createdAt)}
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
