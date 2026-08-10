import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    ChevronRight,
    CircleHelp,
    CloudCog,
    Download,
    Gauge,
    History,
    RefreshCcw,
    Settings2,
    ShieldCheck,
    Thermometer,
    Zap,
} from 'lucide-react';
import type { ReactNode } from 'react';

type FacilityAction = {
    key: string;
    label: string;
    count: number;
    description: string;
    href: string;
};

type FacilityEvent = {
    id: number;
    type: string;
    label: string | null;
    severity: string | null;
    source: string | null;
    occurredAt: string | null;
    processed: boolean;
};

type FacilityObservation = {
    value: string | null;
    unit: string | null;
    state: string | null;
    observedAt: string | null;
    source: string;
};

type FacilityIntegration = {
    provider: string | null;
    name: string | null;
    state: string;
    capabilities: string[];
    lastTestedAt: string | null;
    lastSync: {
        action: string;
        status: string;
        itemsProcessed: number;
        completedAt: string | null;
    } | null;
};

type FacilityDevice = {
    id: number;
    name: string;
    href: string;
    group: string;
    category: string;
    categoryLabel: string | null;
    subcategory: string | null;
    subcategoryLabel: string | null;
    status: string | null;
    health: string | null;
    technicalState: string;
    site: { id: number; name: string; href: string } | null;
    provider: string | null;
    monitoring: { enabled: number; attention: number; uncertain: number };
    unmonitored: boolean;
    observation: FacilityObservation | null;
    freshness: { state: string; observedAt: string | null };
    thresholdEvent: FacilityEvent | null;
    activeEventCount: number | null;
    maintenance: {
        openCount: number;
        overdueCount: number;
        nextDue: string | null;
        href: string;
        source: string;
    } | null;
    integration: FacilityIntegration | null;
    automation: {
        name: string | null;
        enabled: boolean | null;
        status: string;
        lastExecutedAt: string | null;
        source: string;
    };
};

type HistoryEvent = FacilityEvent & {
    deviceId: number;
    deviceName: string | null;
    deviceHref: string;
};

type HistoryObservation = {
    id: number;
    deviceId: number | null;
    deviceName: string | null;
    deviceHref: string | null;
    monitorName: string | null;
    state: string | null;
    value: string | null;
    unit: string | null;
    observedAt: string | null;
    source: string;
};

export type FacilitiesWorkspaceData = {
    permissions: {
        events: boolean;
        maintenance: boolean;
        integrations: boolean;
        export: boolean;
    };
    boundary: {
        title: string;
        description: string;
        evidenceNote: string;
        managementNote: string;
    };
    overview: {
        inventory: {
            devices: number;
            environment: number;
            building_systems: number;
            utilities: number;
            automations: number;
            sites: number;
        };
        attention: {
            devices: number;
            monitoring: number;
            active_events: number | null;
            unmonitored: number;
            stale: number;
            overdue_maintenance: number | null;
            integration: number | null;
        };
        freshness: { fresh: number; stale: number; not_collected: number };
        requiredActions: FacilityAction[];
        sites: Array<{
            id: number;
            name: string;
            href: string;
            devices: number;
            attention: number;
            activeEvents: number | null;
        }>;
    };
    activeTab: {
        key:
            | 'overview'
            | 'environment'
            | 'building-systems'
            | 'utilities'
            | 'automations'
            | 'history';
        label: string;
        description: string;
        inventoryTruncated: boolean;
        devices: FacilityDevice[];
        environment: FacilityDevice[];
        buildingSystems: FacilityDevice[];
        utilities: FacilityDevice[];
        automations: FacilityDevice[];
        history: {
            events: HistoryEvent[];
            observations: HistoryObservation[];
            filters: {
                kind: string;
                deviceId: number | null;
                severity: string | null;
                eventType: string | null;
                source: string | null;
            };
            filterOptions: {
                devices: Array<{ value: number; label: string }>;
                severities: string[];
                eventTypes: string[];
                sources: string[];
            };
            exportHref: string | null;
            eventAccessRestricted: boolean;
            deviceCount: number;
        };
        gaps: {
            environmentWithoutReadings: number;
            buildingSystemsUnmonitored: number;
            utilitiesWithoutIntegrations: number;
            automationsWithoutExecutionEvidence: number;
        };
    };
};

function title(value: string | null | undefined): string {
    if (!value) return 'Not observed';
    return value
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function tone(state: string | null | undefined): StatusVariant {
    if (
        ['healthy', 'success', 'fresh', 'active', 'aligned'].includes(
            state ?? '',
        )
    )
        return 'success';
    if (
        [
            'warning',
            'degraded',
            'stale',
            'partial',
            'pending',
            'unmonitored',
        ].includes(state ?? '')
    )
        return 'warning';
    if (['critical', 'failed', 'offline', 'error'].includes(state ?? ''))
        return 'critical';
    if (['running', 'info'].includes(state ?? '')) return 'info';
    return 'neutral';
}

function State({ value, label }: { value: string | null; label?: string }) {
    return (
        <StatusBadge variant={tone(value)}>{label ?? title(value)}</StatusBadge>
    );
}

function Metric({
    icon,
    value,
    label,
    note,
}: {
    icon: ReactNode;
    value: number;
    label: string;
    note: string;
}) {
    return (
        <Card className="min-w-0 shadow-xs">
            <CardContent className="p-4">
                <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    {icon}
                </div>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-sm font-medium break-words">{label}</p>
                <p className="mt-1 text-xs text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
    );
}

function Boundary({ data }: { data: FacilitiesWorkspaceData['boundary'] }) {
    return (
        <section className="rounded-xl border border-status-info/30 bg-status-info-bg p-4">
            <div className="flex items-start gap-3">
                <ShieldCheck
                    className="mt-0.5 h-5 w-5 shrink-0 text-status-info"
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <h2 className="font-semibold">{data.title}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {data.description}
                    </p>
                    <div className="mt-3 grid gap-2 text-xs text-muted-foreground md:grid-cols-2">
                        <p>{data.evidenceNote}</p>
                        <p>{data.managementNote}</p>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Overview({ data }: { data: FacilitiesWorkspaceData }) {
    const { overview } = data;
    return (
        <div className="space-y-5">
            <section>
                <h2 className="text-lg font-semibold">
                    Facilities operations at a glance
                </h2>
                <p className="mb-3 text-sm text-muted-foreground">
                    Technical condition, evidence freshness, site impact, and
                    required operational follow-up.
                </p>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <Metric
                        icon={<Gauge className="h-5 w-5" />}
                        value={overview.inventory.devices}
                        label={`${overview.inventory.devices} facility devices`}
                        note={`${overview.inventory.sites} authorised sites`}
                    />
                    <Metric
                        icon={<Thermometer className="h-5 w-5" />}
                        value={overview.inventory.environment}
                        label={`${overview.inventory.environment} environmental devices`}
                        note="Leak, gas, cold-chain, and environmental sensors"
                    />
                    <Metric
                        icon={<Building2 className="h-5 w-5" />}
                        value={overview.inventory.building_systems}
                        label={`${overview.inventory.building_systems} building systems`}
                        note="Mechanical, access, and safety equipment"
                    />
                    <Metric
                        icon={<Zap className="h-5 w-5" />}
                        value={overview.inventory.utilities}
                        label={`${overview.inventory.utilities} utilities`}
                        note="Metering and utility-service devices"
                    />
                    <Metric
                        icon={<CloudCog className="h-5 w-5" />}
                        value={overview.inventory.automations}
                        label={`${overview.inventory.automations} automations`}
                        note="Explicit execution evidence only"
                    />
                    <Metric
                        icon={<AlertTriangle className="h-5 w-5" />}
                        value={
                            overview.attention.devices +
                            overview.attention.monitoring
                        }
                        label="Technical attention"
                        note={`${overview.attention.unmonitored} unmonitored · ${overview.attention.stale} stale`}
                    />
                </div>
            </section>

            <Boundary data={data.boundary} />

            <section className="grid min-w-0 gap-4 lg:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader>
                        <h3 className="font-semibold">Required action</h3>
                        <p className="text-sm text-muted-foreground">
                            Evidence-backed items only.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {overview.requiredActions.length === 0 ? (
                            <Empty text="No current evidence-backed actions." />
                        ) : (
                            overview.requiredActions.map((action) => (
                                <Link
                                    key={action.key}
                                    href={action.href}
                                    className="flex min-h-12 min-w-0 items-center gap-3 rounded-lg border p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium break-words">
                                            {action.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {action.count} ·{' '}
                                            {action.description}
                                        </span>
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0" />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <h3 className="font-semibold">Evidence freshness</h3>
                    </CardHeader>
                    <CardContent className="grid grid-cols-3 gap-2">
                        <Small value={overview.freshness.fresh} label="Fresh" />
                        <Small value={overview.freshness.stale} label="Stale" />
                        <Small
                            value={overview.freshness.not_collected}
                            label="Not collected"
                        />
                    </CardContent>
                </Card>
            </section>

            <section>
                <h2 className="mb-3 text-lg font-semibold">Site impact</h2>
                {overview.sites.length === 0 ? (
                    <Empty text="No facility devices are assigned to an authorised site." />
                ) : (
                    <div className="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {overview.sites.map((site) => (
                            <Link
                                key={site.id}
                                href={site.href}
                                className="min-w-0 rounded-xl border bg-card p-4 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            {site.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {site.devices} devices ·{' '}
                                            {site.attention} need attention
                                        </span>
                                    </span>
                                    <State
                                        value={
                                            site.attention > 0
                                                ? 'warning'
                                                : 'healthy'
                                        }
                                    />
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}

function Small({ value, label }: { value: number; label: string }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-3 text-center">
            <p className="text-xl font-semibold tabular-nums">{value}</p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

function Empty({ text }: { text: string }) {
    return (
        <div className="flex min-h-16 items-center gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            <CircleHelp className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{text}</span>
        </div>
    );
}

function Gap({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-start gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
            <span>{children}</span>
        </div>
    );
}

function DeviceHeading({ device }: { device: FacilityDevice }) {
    return (
        <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
                <Link
                    href={device.href}
                    className="block truncate font-semibold text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {device.name}
                </Link>
                <p className="text-sm text-muted-foreground">
                    {device.subcategoryLabel ?? device.categoryLabel} ·{' '}
                    {device.site ? (
                        <Link
                            href={device.site.href}
                            className="frontline-focus rounded-sm hover:text-primary hover:underline"
                        >
                            {device.site.name}
                        </Link>
                    ) : (
                        'Site not assigned'
                    )}
                </p>
            </div>
            <State value={device.technicalState} />
        </div>
    );
}

function EvidenceDetails({ device }: { device: FacilityDevice }) {
    return (
        <div className="grid gap-2 text-sm sm:grid-cols-3">
            <Detail
                label="Monitoring"
                value={
                    device.unmonitored
                        ? 'Unmonitored'
                        : `${device.monitoring.enabled} enabled checks`
                }
            />
            <Detail label="Freshness" value={title(device.freshness.state)} />
            <Detail
                label="Latest evidence"
                value={
                    device.freshness.observedAt
                        ? formatRelative(device.freshness.observedAt)
                        : 'Not collected'
                }
            />
        </div>
    );
}

function EnvironmentPanel({
    rows,
    gap,
}: {
    rows: FacilityDevice[];
    gap: number;
}) {
    return (
        <Panel
            title="Environmental sensor evidence"
            description="Technical state, retained readings, freshness, and threshold-event evidence."
            icon={<Thermometer className="h-5 w-5" />}
        >
            {gap > 0 ? (
                <Gap>{gap} environmental devices have no retained reading.</Gap>
            ) : null}
            {rows.length === 0 ? (
                <Empty text="No environmental devices are visible." />
            ) : (
                <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                    {rows.map((device) => (
                        <article
                            key={device.id}
                            aria-label={device.name}
                            className="min-w-0 space-y-4 rounded-xl border bg-card p-4"
                        >
                            <DeviceHeading device={device} />
                            <div className="flex flex-wrap items-center gap-2">
                                <State value={device.freshness.state} />
                                <span className="text-lg font-semibold tabular-nums">
                                    {device.observation?.value !== null &&
                                    device.observation?.value !== undefined
                                        ? `${device.observation.value}${device.observation.unit ? ` ${device.observation.unit}` : ''}`
                                        : 'No retained reading'}
                                </span>
                            </div>
                            <EvidenceDetails device={device} />
                            {device.thresholdEvent ? (
                                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-sm font-medium">
                                            {device.thresholdEvent.label}
                                        </span>
                                        <State
                                            value={
                                                device.thresholdEvent.severity
                                            }
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {formatDateTime(
                                            device.thresholdEvent.occurredAt,
                                        )}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    No threshold-event evidence is retained.
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function BuildingPanel({ rows, gap }: { rows: FacilityDevice[]; gap: number }) {
    return (
        <Panel
            title="Building systems and safety equipment"
            description="Canonical device condition with maintenance kept in the shared maintenance register."
            icon={<Building2 className="h-5 w-5" />}
        >
            {gap > 0 ? (
                <Gap>{gap} building systems have no enabled monitor.</Gap>
            ) : null}
            {rows.length === 0 ? (
                <Empty text="No building systems are visible." />
            ) : (
                <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                    {rows.map((device) => (
                        <article
                            key={device.id}
                            aria-label={device.name}
                            className="min-w-0 space-y-4 rounded-xl border bg-card p-4"
                        >
                            <DeviceHeading device={device} />
                            <EvidenceDetails device={device} />
                            {device.maintenance ? (
                                <div className="rounded-lg border bg-muted/20 p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-sm font-medium">
                                            {device.maintenance.openCount}{' '}
                                            {device.maintenance.openCount === 1
                                                ? 'open maintenance item'
                                                : 'open maintenance items'}
                                        </span>
                                        <State
                                            value={
                                                device.maintenance
                                                    .overdueCount > 0
                                                    ? 'warning'
                                                    : 'healthy'
                                            }
                                            label={
                                                device.maintenance
                                                    .overdueCount > 0
                                                    ? `${device.maintenance.overdueCount} overdue`
                                                    : 'No overdue work'
                                            }
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Next due:{' '}
                                        {device.maintenance.nextDue
                                            ? formatDate(
                                                  device.maintenance.nextDue,
                                              )
                                            : 'Not scheduled'}
                                    </p>
                                    <Link
                                        href={device.maintenance.href}
                                        className="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        Open maintenance
                                        <ChevronRight className="ml-1 h-4 w-4" />
                                    </Link>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Maintenance context is restricted.
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function IntegrationEvidence({
    integration,
}: {
    integration: FacilityIntegration | null;
}) {
    if (!integration) {
        return (
            <p className="text-sm text-muted-foreground">
                Integration status is restricted.
            </p>
        );
    }

    return (
        <div className="space-y-3 rounded-lg border bg-muted/20 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">
                    {integration.name ?? 'No integration configured'}
                </span>
                <State value={integration.state} />
            </div>
            {integration.capabilities.length > 0 ? (
                <p className="text-xs text-muted-foreground">
                    Supported evidence:{' '}
                    {integration.capabilities.map(title).join(', ')}
                </p>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No supported capability evidence is recorded.
                </p>
            )}
            {integration.lastSync ? (
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span>Last sync: {title(integration.lastSync.status)}</span>
                    <span className="text-xs text-muted-foreground">
                        {formatRelative(integration.lastSync.completedAt)}
                    </span>
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No retained integration sync evidence.
                </p>
            )}
        </div>
    );
}

function UtilitiesPanel({
    rows,
    gap,
}: {
    rows: FacilityDevice[];
    gap: number;
}) {
    return (
        <Panel
            title="Utilities and metering evidence"
            description="Utility devices, retained measurements, and safe integration state."
            icon={<Zap className="h-5 w-5" />}
        >
            {gap > 0 ? (
                <Gap>{gap} utility devices have no configured integration.</Gap>
            ) : null}
            {rows.length === 0 ? (
                <Empty text="No utility or metering devices are visible." />
            ) : (
                <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                    {rows.map((device) => (
                        <article
                            key={device.id}
                            aria-label={device.name}
                            className="min-w-0 space-y-4 rounded-xl border bg-card p-4"
                        >
                            <DeviceHeading device={device} />
                            <EvidenceDetails device={device} />
                            {device.observation ? (
                                <p className="text-sm">
                                    Latest retained measurement:{' '}
                                    <strong>
                                        {device.observation.value ?? 'No value'}{' '}
                                        {device.observation.unit}
                                    </strong>
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No retained utility measurement.
                                </p>
                            )}
                            <IntegrationEvidence
                                integration={device.integration}
                            />
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function AutomationsPanel({
    rows,
    gap,
}: {
    rows: FacilityDevice[];
    gap: number;
}) {
    return (
        <Panel
            title="Facility automation evidence"
            description="Observed execution state only. No automation or building-control command can be issued here."
            icon={<Settings2 className="h-5 w-5" />}
        >
            <div className="rounded-lg border border-status-info/30 bg-status-info-bg p-3 text-sm">
                Automation controls remain read-only until governed command
                workflows are available.
            </div>
            {gap > 0 ? (
                <Gap>{gap} automations have no execution evidence.</Gap>
            ) : null}
            {rows.length === 0 ? (
                <Empty text="No facility automation devices are visible." />
            ) : (
                <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                    {rows.map((device) => (
                        <article
                            key={device.id}
                            aria-label={device.name}
                            className="min-w-0 space-y-4 rounded-xl border bg-card p-4"
                        >
                            <DeviceHeading device={device} />
                            <div className="rounded-lg border bg-muted/20 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {device.automation.name ??
                                            'No automation definition observed'}
                                    </span>
                                    <State value={device.automation.status} />
                                </div>
                                <p className="mt-2 text-sm">
                                    Last execution:{' '}
                                    {title(device.automation.status)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {device.automation.lastExecutedAt
                                        ? formatDateTime(
                                              device.automation.lastExecutedAt,
                                          )
                                        : 'No execution time retained'}
                                </p>
                            </div>
                            <IntegrationEvidence
                                integration={device.integration}
                            />
                        </article>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function HistoryFilters({
    history,
}: {
    history: FacilitiesWorkspaceData['activeTab']['history'];
}) {
    return (
        <form
            method="get"
            action="/security-devices/facilities-iot"
            className="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-2 xl:grid-cols-5"
        >
            <input type="hidden" name="tab" value="history" />
            <FilterSelect
                name="history_kind"
                label="Evidence"
                value={history.filters.kind}
                options={[
                    ['all', 'Events and observations'],
                    ['events', 'Events only'],
                    ['observations', 'Observations only'],
                ]}
            />
            <FilterSelect
                name="device_id"
                label="Device"
                value={history.filters.deviceId?.toString() ?? ''}
                options={history.filterOptions.devices.map((option) => [
                    option.value.toString(),
                    option.label,
                ])}
                blank="All devices"
            />
            <FilterSelect
                name="severity"
                label="Severity"
                value={history.filters.severity ?? ''}
                options={history.filterOptions.severities.map((value) => [
                    value,
                    title(value),
                ])}
                blank="All severities"
            />
            <FilterSelect
                name="event_type"
                label="Event type"
                value={history.filters.eventType ?? ''}
                options={history.filterOptions.eventTypes.map((value) => [
                    value,
                    title(value),
                ])}
                blank="All event types"
            />
            <div className="flex items-end gap-2">
                <button
                    type="submit"
                    className="frontline-focus min-h-11 flex-1 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground"
                >
                    Apply filters
                </button>
                <Link
                    href="/security-devices/facilities-iot?tab=history"
                    aria-label="Clear history filters"
                    className="frontline-focus inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border bg-card"
                >
                    <RefreshCcw className="h-4 w-4" />
                </Link>
            </div>
        </form>
    );
}

function FilterSelect({
    name,
    label,
    value,
    options,
    blank,
}: {
    name: string;
    label: string;
    value: string;
    options: Array<[string, string]>;
    blank?: string;
}) {
    return (
        <label className="space-y-1 text-sm font-medium">
            <span>{label}</span>
            <select
                name={name}
                defaultValue={value}
                className="min-h-11 w-full rounded-lg border bg-background px-3 text-sm"
            >
                {blank ? <option value="">{blank}</option> : null}
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </select>
        </label>
    );
}

function HistoryPanel({
    history,
}: {
    history: FacilitiesWorkspaceData['activeTab']['history'];
}) {
    return (
        <Panel
            title="Canonical facility history"
            description="Filtered append-only device events and retained native observations."
            icon={<History className="h-5 w-5" />}
            action={
                history.exportHref ? (
                    <a
                        href={history.exportHref}
                        className="frontline-focus inline-flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm font-medium"
                    >
                        <Download className="h-4 w-4" /> Export events
                    </a>
                ) : null
            }
        >
            <HistoryFilters history={history} />
            {history.eventAccessRestricted ? (
                <div className="rounded-lg border border-status-info/30 bg-status-info-bg p-3 text-sm">
                    Event history is restricted by your role. Retained technical
                    observations remain visible for authorised devices.
                </div>
            ) : null}
            <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <section className="min-w-0 space-y-2">
                    <h3 className="font-semibold">Device events</h3>
                    {history.events.length === 0 ? (
                        <Empty text="No events match the current filters." />
                    ) : (
                        history.events.map((event) => (
                            <article
                                key={event.id}
                                className="min-w-0 rounded-lg border bg-card p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <span className="min-w-0">
                                        <Link
                                            href={event.deviceHref}
                                            className="block truncate text-sm font-medium text-primary hover:underline"
                                        >
                                            {event.deviceName}
                                        </Link>
                                        <span className="text-sm">
                                            {event.label}
                                        </span>
                                    </span>
                                    <State value={event.severity} />
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {formatDateTime(event.occurredAt)} ·{' '}
                                    {event.processed ? 'Processed' : 'Active'}
                                </p>
                            </article>
                        ))
                    )}
                </section>
                <section className="min-w-0 space-y-2">
                    <h3 className="font-semibold">Retained observations</h3>
                    {history.observations.length === 0 ? (
                        <Empty text="No observations match the current filters." />
                    ) : (
                        history.observations.map((observation) => (
                            <article
                                key={observation.id}
                                className="min-w-0 rounded-lg border bg-card p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <span className="min-w-0">
                                        {observation.deviceHref ? (
                                            <Link
                                                href={observation.deviceHref}
                                                className="block truncate text-sm font-medium text-primary hover:underline"
                                            >
                                                {observation.deviceName}
                                            </Link>
                                        ) : (
                                            <span className="text-sm font-medium">
                                                Device unavailable
                                            </span>
                                        )}
                                        <span className="text-sm">
                                            {observation.monitorName}
                                        </span>
                                    </span>
                                    <State value={observation.state} />
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {observation.value ?? 'No value'}{' '}
                                    {observation.unit} ·{' '}
                                    {formatDateTime(observation.observedAt)}
                                </p>
                            </article>
                        ))
                    )}
                </section>
            </div>
        </Panel>
    );
}

function Panel({
    title: panelTitle,
    description,
    icon,
    action,
    children,
}: {
    title: string;
    description: string;
    icon: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        {icon}
                    </span>
                    <span className="min-w-0">
                        <h2 className="text-lg font-semibold">{panelTitle}</h2>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </span>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="font-medium break-words">{value}</p>
        </div>
    );
}

export function FacilitiesWorkspacePanels({
    data,
}: {
    data: FacilitiesWorkspaceData;
}) {
    const { activeTab } = data;
    if (activeTab.key === 'overview') return <Overview data={data} />;

    return (
        <div className="space-y-5">
            <Boundary data={data.boundary} />
            {activeTab.inventoryTruncated ? (
                <Gap>
                    This workspace shows the first 100 authorised facility
                    devices. Narrow the canonical inventory before relying on
                    counts.
                </Gap>
            ) : null}
            {activeTab.key === 'environment' ? (
                <EnvironmentPanel
                    rows={activeTab.environment}
                    gap={activeTab.gaps.environmentWithoutReadings}
                />
            ) : null}
            {activeTab.key === 'building-systems' ? (
                <BuildingPanel
                    rows={activeTab.buildingSystems}
                    gap={activeTab.gaps.buildingSystemsUnmonitored}
                />
            ) : null}
            {activeTab.key === 'utilities' ? (
                <UtilitiesPanel
                    rows={activeTab.utilities}
                    gap={activeTab.gaps.utilitiesWithoutIntegrations}
                />
            ) : null}
            {activeTab.key === 'automations' ? (
                <AutomationsPanel
                    rows={activeTab.automations}
                    gap={activeTab.gaps.automationsWithoutExecutionEvidence}
                />
            ) : null}
            {activeTab.key === 'history' ? (
                <HistoryPanel history={activeTab.history} />
            ) : null}
        </div>
    );
}
