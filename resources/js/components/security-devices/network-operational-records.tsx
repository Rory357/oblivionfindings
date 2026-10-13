import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    ListCaption,
    useEntityContextMenu,
    type EntityMeridian,
    type MenuItem,
} from '@/components/lists';
import { EvidenceRecords as Records } from '@/components/lists/evidence-records';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Activity,
    Building2,
    Cable,
    Clock3,
    Download,
    Eye,
    FileDiff,
    Gauge,
    Server,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    StateBadge,
    capacityLabel,
    formatRate,
    formatUtilisation,
    shortHash,
    snapshotDiffLabel,
    stateLabel,
    stateVariant,
} from './network-evidence-display';
import type {
    ConfigurationRow,
    ConfigurationSnapshotRow,
    NetworkDevice,
    NetworkInterface,
    NetworkItWorkspaceData,
    NetworkService,
    TrafficRow,
} from './network-it-workspace';

export type NetworkRecordsView = 'cards' | 'table';

function Gap({ text }: { text: string }) {
    return (
        <p className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm text-status-warning">
            {text}
        </p>
    );
}
function meridian(...states: Array<string | null | undefined>): EntityMeridian {
    const tones = states
        .filter((state) => state != null)
        .map((state) => stateVariant(state!));
    if (tones.includes('critical')) return 'critical';
    if (tones.includes('warning')) return 'warning';
    if (tones.includes('neutral')) return 'neutral';
    return tones.includes('success') ? 'success' : 'neutral';
}
function deviceMeridian(row: NetworkDevice): EntityMeridian {
    if (row.health === 'critical') return 'critical';
    if (
        row.health === 'warning' ||
        row.monitoring.attention > 0 ||
        row.monitoring.enabled === 0
    )
        return 'warning';
    if (row.monitoring.uncertain > 0) return 'neutral';
    return row.health === 'healthy' ? 'success' : 'neutral';
}
function OpenDevice(href: string): MenuItem {
    return {
        label: 'Open device',
        icon: Eye,
        onClick: () => router.visit(href),
    };
}
function Observation({
    time,
    empty = 'No retained observation',
}: {
    time: string | null;
    empty?: string;
}) {
    return (
        <span title={time ? formatDateTime(time) : undefined}>
            {time ? `Observed ${formatRelative(time)}` : empty}
        </span>
    );
}
function Facts({ items }: { items: Array<[string, ReactNode]> }) {
    return (
        <div className="flex flex-col items-start gap-1">
            {items.map(([label, value]) => (
                <EntityChip key={label} outline>
                    {label}: <span>{value ?? 'Not recorded'}</span>
                </EntityChip>
            ))}
        </div>
    );
}
function Hash({ value }: { value: string | null }) {
    return <span title={value ?? undefined}>{shortHash(value)}</span>;
}
function MonitoringFacts({ row }: { row: NetworkDevice }) {
    return (
        <>
            <EntityChip>{row.monitoring.enabled} enabled checks</EntityChip>
            {row.monitoring.attention > 0 ? (
                <StatusBadge variant="warning">
                    {row.monitoring.attention} need attention
                </StatusBadge>
            ) : null}
            {row.monitoring.uncertain > 0 ? (
                <StatusBadge variant="neutral">
                    {row.monitoring.uncertain} uncertain
                </StatusBadge>
            ) : null}
            {row.wanPath ? <EntityChip>WAN path</EntityChip> : null}
        </>
    );
}
function DeviceRecords({
    data,
    view,
}: {
    data: NetworkItWorkspaceData;
    view: NetworkRecordsView;
}) {
    const rows = data.activeTab.devices;
    const actionsFor = (row: NetworkDevice): MenuItem[] => [
        OpenDevice(row.href),
        ...(row.site
            ? [
                  {
                      label: 'Open site',
                      icon: Building2,
                      onClick: () => router.visit(row.site!.href),
                  },
              ]
            : []),
    ];
    const identifiers = (row: NetworkDevice): Array<[string, ReactNode]> => [
        ['IP', row.identifiers.ipAddress ?? 'Not recorded'],
        ['MAC', row.identifiers.macAddress ?? 'Not recorded'],
        ['Serial', row.identifiers.serialNumber ?? 'Not recorded'],
        ['Firmware', row.firmwareVersion ?? 'Not observed'],
    ];
    return (
        <Records
            compactColumns={[
                {
                    key: 'state',
                    label: 'State and health',
                    width: '205px',
                    cell: (row) => (
                        <div className="flex items-center gap-1">
                            <StateBadge state={row.status} />
                            <StateBadge
                                state={row.health}
                                label={'Health: ' + stateLabel(row.health)}
                            />
                        </div>
                    ),
                },
                {
                    key: 'site',
                    label: 'Site',
                    width: '1fr',
                    cell: (row) =>
                        row.site ? (
                            <a
                                href={row.site.href}
                                onClick={(event) => event.stopPropagation()}
                                className="text-primary hover:underline"
                            >
                                {row.site.name}
                            </a>
                        ) : (
                            'Site not assigned'
                        ),
                },
                {
                    key: 'ip',
                    label: 'IP address',
                    width: '1fr',
                    cell: (row) => (
                        <EntityChip>
                            {row.identifiers.ipAddress ?? 'Not recorded'}
                        </EntityChip>
                    ),
                },
                {
                    key: 'monitoring',
                    label: 'Monitoring',
                    width: '1fr',
                    cell: (row) => (
                        <EntityChip>
                            {row.monitoring.enabled} enabled checks
                        </EntityChip>
                    ),
                },
                {
                    key: 'observed',
                    label: 'Last contact',
                    width: '1fr',
                    cell: (row) => (
                        <Observation
                            time={row.lastSeenAt}
                            empty="Never observed"
                        />
                    ),
                },
            ]}
            title="Network and IT device details"
            description="Network identifiers, monitoring coverage and last contact."
            rows={rows}
            total={data.activeTab.inventoryTotal}
            view={view}
            icon={Server}
            rowKey={(row) => row.id}
            identity={(row) => ({
                icon: Server,
                name: row.name,
                subline: stateLabel(row.subcategory ?? row.category),
            })}
            hrefFor={(row) => row.href}
            actionsFor={actionsFor}
            empty="No matching network or IT devices."
            gap={
                data.activeTab.inventoryTruncated ? (
                    <Gap text="Only the first 100 matching devices are shown. Narrow the workspace filters to review the rest." />
                ) : undefined
            }
            cardFor={(row) => ({
                icon: Server,
                name: row.name,
                subline: stateLabel(row.subcategory ?? row.category),
                meridian: deviceMeridian(row),
                chips: (
                    <>
                        <StateBadge state={row.status} />
                        <StateBadge
                            state={row.health}
                            label={`Health: ${stateLabel(row.health)}`}
                        />
                        {identifiers(row).map(([label, value]) => (
                            <EntityChip key={label} outline>
                                {label}: <span>{value}</span>
                            </EntityChip>
                        ))}
                    </>
                ),
                alerts: <MonitoringFacts row={row} />,
                footer: {
                    personIcon: Building2,
                    primary: row.site ? (
                        <a
                            onClick={(event) => event.stopPropagation()}
                            href={row.site.href}
                            className="hover:text-primary hover:underline"
                        >
                            {row.site.name}
                        </a>
                    ) : (
                        'Site not assigned'
                    ),
                    secondary: (
                        <Observation
                            time={row.lastSeenAt}
                            empty="Never observed"
                        />
                    ),
                },
            })}
            columns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '140px',
                    cell: (row) => (
                        <div className="flex flex-col items-start gap-1">
                            <StateBadge state={row.status} />
                            <StateBadge
                                state={row.health}
                                label={`Health: ${stateLabel(row.health)}`}
                            />
                        </div>
                    ),
                },
                {
                    key: 'site',
                    label: 'Site',
                    width: '1fr',
                    cell: (row) =>
                        row.site ? (
                            <a
                                onClick={(event) => event.stopPropagation()}
                                href={row.site.href}
                                className="text-primary hover:underline"
                            >
                                {row.site.name}
                            </a>
                        ) : (
                            'Site not assigned'
                        ),
                },
                {
                    key: 'identifiers',
                    label: 'Identifiers',
                    width: '1.4fr',
                    cell: (row) => <Facts items={identifiers(row)} />,
                },
                {
                    key: 'monitoring',
                    label: 'Monitoring',
                    width: '1.2fr',
                    cell: (row) => (
                        <div className="flex flex-wrap gap-1">
                            <MonitoringFacts row={row} />
                        </div>
                    ),
                },
                {
                    key: 'contact',
                    label: 'Last contact',
                    width: '1fr',
                    cell: (row) => (
                        <Observation
                            time={row.lastSeenAt}
                            empty="Never observed"
                        />
                    ),
                },
            ]}
        />
    );
}
function InterfaceRecords({
    data,
    view,
}: {
    data: NetworkItWorkspaceData;
    view: NetworkRecordsView;
}) {
    const rows = data.activeTab.interfaces;
    const gap = data.activeTab.gaps.devicesWithoutInterfaceEvidence;
    const values = (row: NetworkInterface): Array<[string, ReactNode]> => [
        ['Link speed', formatRate(row.speedBps)],
        [
            'Interface index',
            row.index === null ? 'Not collected' : String(row.index),
        ],
        ['Inbound', formatUtilisation(row.inUtilisation, 'inbound')],
        ['Outbound', formatUtilisation(row.outUtilisation, 'outbound')],
        ['Inbound rate', formatRate(row.inBps)],
        ['Outbound rate', formatRate(row.outBps)],
        ['Errors', row.errors === null ? 'Not collected' : String(row.errors)],
        [
            'Discards',
            row.discards === null ? 'Not collected' : String(row.discards),
        ],
    ];
    const alerts = (row: NetworkInterface) => (
        <>
            <StateBadge
                state={row.capacityState}
                label={capacityLabel(row.capacityState)}
            />
            <EntityChip>Admin {row.adminStatus ?? 'unknown'}</EntityChip>
            <EntityChip>
                Operational {row.operationalStatus ?? 'unknown'}
            </EntityChip>
        </>
    );
    return (
        <Records
            compactColumns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '110px',
                    cell: (row) => (
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                    ),
                },
                {
                    key: 'inbound',
                    label: 'Inbound',
                    width: '1fr',
                    cell: (row) => (
                        <div>
                            <EntityChip>{formatRate(row.inBps)}</EntityChip>
                            <span className="text-caption block">
                                {formatUtilisation(
                                    row.inUtilisation,
                                    'inbound',
                                )}
                            </span>
                        </div>
                    ),
                },
                {
                    key: 'outbound',
                    label: 'Outbound',
                    width: '1fr',
                    cell: (row) => (
                        <div>
                            <EntityChip>{formatRate(row.outBps)}</EntityChip>
                            <span className="text-caption block">
                                {formatUtilisation(
                                    row.outUtilisation,
                                    'outbound',
                                )}
                            </span>
                        </div>
                    ),
                },
                {
                    key: 'speed',
                    label: 'Link speed',
                    width: '1fr',
                    cell: (row) => (
                        <EntityChip>{formatRate(row.speedBps)}</EntityChip>
                    ),
                },
                {
                    key: 'capacity',
                    label: 'Capacity',
                    width: '150px',
                    cell: (row) => (
                        <StateBadge
                            state={row.capacityState}
                            label={capacityLabel(row.capacityState)}
                        />
                    ),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (row) => <Observation time={row.observedAt} />,
                },
            ]}
            title="Observed interfaces"
            articleLabel={(row) => `${row.name} on ${row.deviceName}`}
            description="Latest interface readings, with missing counters kept visible."
            rows={rows}
            view={view}
            icon={Cable}
            rowKey={(row) => row.monitorId}
            identity={(row) => ({
                icon: Cable,
                name: row.name,
                subline: row.deviceName,
            })}
            hrefFor={(row) => row.deviceHref}
            actionsFor={(row) => [OpenDevice(row.deviceHref)]}
            empty="No interface observations are retained for the current scope."
            gap={
                gap > 0 ? (
                    <Gap
                        text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no interface evidence`}
                    />
                ) : undefined
            }
            cardFor={(row) => ({
                icon: Cable,
                name: row.name,
                subline: row.deviceName,
                meridian: row.enabled
                    ? meridian(row.state, row.capacityState)
                    : 'neutral',
                chips: (
                    <>
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                        {values(row).map(([label, value]) => (
                            <EntityChip key={label} outline>
                                {label}: <span>{value}</span>
                            </EntityChip>
                        ))}
                    </>
                ),
                alerts: alerts(row),
                footer: {
                    personIcon: Clock3,
                    primary: (
                        <a
                            onClick={(event) => event.stopPropagation()}
                            href={row.deviceHref}
                            className="hover:text-primary hover:underline"
                        >
                            {row.deviceName}
                        </a>
                    ),
                    secondary: <Observation time={row.observedAt} />,
                },
            })}
            columns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '120px',
                    cell: (row) => (
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                    ),
                },
                {
                    key: 'rates',
                    label: 'Link and rates',
                    width: '1.4fr',
                    cell: (row) => (
                        <Facts
                            items={values(row).filter(([label]) =>
                                [
                                    'Link speed',
                                    'Inbound rate',
                                    'Outbound rate',
                                ].includes(label),
                            )}
                        />
                    ),
                },
                {
                    key: 'counters',
                    label: 'Counters',
                    width: '1.2fr',
                    cell: (row) => (
                        <Facts
                            items={values(row).filter(([label]) =>
                                [
                                    'Interface index',
                                    'Errors',
                                    'Discards',
                                ].includes(label),
                            )}
                        />
                    ),
                },
                {
                    key: 'utilisation',
                    label: 'Utilisation',
                    width: '1.2fr',
                    cell: (row) => (
                        <Facts
                            items={values(row).filter(([label]) =>
                                ['Inbound', 'Outbound'].includes(label),
                            )}
                        />
                    ),
                },
                {
                    key: 'status',
                    label: 'Capacity and link',
                    width: '1.2fr',
                    cell: (row) => (
                        <div className="flex flex-wrap gap-1">
                            {alerts(row)}
                        </div>
                    ),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (row) => <Observation time={row.observedAt} />,
                },
            ]}
        />
    );
}
function ServiceRecords({
    data,
    view,
}: {
    data: NetworkItWorkspaceData;
    view: NetworkRecordsView;
}) {
    const rows = data.activeTab.services,
        gap = data.activeTab.gaps.devicesWithoutServiceChecks;
    const facts = (row: NetworkService) => (
        <>
            <EntityChip>{row.kindLabel}</EntityChip>
            {row.affectsAvailability ? (
                <EntityChip>Affects availability</EntityChip>
            ) : null}
            <EntityChip>
                {row.dependentCount} known{' '}
                {row.dependentCount === 1 ? 'dependant' : 'dependants'}
            </EntityChip>
        </>
    );
    const collector = (row: NetworkService) => (
        <div className="space-y-1">
            <span>
                {row.collector
                    ? `${row.collector.name} · ${stateLabel(row.collector.status)}`
                    : 'Central runtime path'}
            </span>
            {row.collector ? (
                <span className="text-caption block">
                    <Observation
                        time={row.collector.lastSeenAt}
                        empty="Collector not observed"
                    />
                </span>
            ) : null}
        </div>
    );
    return (
        <Records
            compactColumns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '110px',
                    cell: (row) => (
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                    ),
                },
                {
                    key: 'check',
                    label: 'Check',
                    width: '1fr',
                    cell: (row) => <EntityChip>{row.kindLabel}</EntityChip>,
                },
                {
                    key: 'dependants',
                    label: 'Dependants',
                    width: '100px',
                    cell: (row) => (
                        <EntityChip>{row.dependentCount}</EntityChip>
                    ),
                },
                {
                    key: 'source',
                    label: 'Collection source',
                    width: '1.5fr',
                    cell: (row) =>
                        row.collector
                            ? row.collector.name +
                              ' · ' +
                              stateLabel(row.collector.status)
                            : 'Central runtime path',
                },
                {
                    key: 'observed',
                    label: 'Last result',
                    width: '1fr',
                    cell: (row) => <Observation time={row.lastObservationAt} />,
                },
            ]}
            title="Service checks"
            description="Reported service state, availability and known dependencies."
            rows={rows}
            view={view}
            icon={Activity}
            rowKey={(row) => row.id}
            identity={(row) => ({
                icon: Activity,
                name: row.name,
                subline: row.deviceName,
            })}
            hrefFor={(row) => row.deviceHref}
            actionsFor={(row) => [OpenDevice(row.deviceHref)]}
            empty="No service checks are configured for the current scope."
            gap={
                gap > 0 ? (
                    <Gap
                        text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no service checks`}
                    />
                ) : undefined
            }
            cardFor={(row) => ({
                icon: Activity,
                name: row.name,
                subline: row.deviceName,
                meridian: row.enabled ? meridian(row.state) : 'neutral',
                chips: (
                    <>
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                        {facts(row)}
                    </>
                ),
                footer: {
                    personIcon: Activity,
                    primary: collector(row),
                    secondary: <Observation time={row.lastObservationAt} />,
                },
            })}
            columns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '130px',
                    cell: (row) => (
                        <StateBadge
                            state={row.enabled ? row.state : 'disabled'}
                        />
                    ),
                },
                {
                    key: 'check',
                    label: 'Check and dependencies',
                    width: '1.6fr',
                    cell: (row) => (
                        <div className="flex flex-wrap gap-1">{facts(row)}</div>
                    ),
                },
                {
                    key: 'collector',
                    label: 'Collection source',
                    width: '1.4fr',
                    cell: collector,
                },
                {
                    key: 'observed',
                    label: 'Last result',
                    width: '1fr',
                    cell: (row) => <Observation time={row.lastObservationAt} />,
                },
            ]}
        />
    );
}
function TrafficRecords({
    data,
    view,
}: {
    data: NetworkItWorkspaceData;
    view: NetworkRecordsView;
}) {
    const rows = data.activeTab.traffic,
        gap = data.activeTab.gaps.devicesWithoutCapacityEvidence;
    const rates = (row: TrafficRow): Array<[string, ReactNode]> => [
        ['Inbound', formatRate(row.inBps)],
        [
            'Inbound utilisation',
            row.inUtilisation === null
                ? 'Utilisation not collected'
                : `${row.inUtilisation}% utilisation`,
        ],
        ['Outbound', formatRate(row.outBps)],
        [
            'Outbound utilisation',
            row.outUtilisation === null
                ? 'Utilisation not collected'
                : `${row.outUtilisation}% utilisation`,
        ],
        ['Link speed', formatRate(row.speedBps)],
    ];
    return (
        <Records
            compactColumns={[
                {
                    key: 'state',
                    label: 'Capacity',
                    width: '150px',
                    cell: (row) => (
                        <StateBadge
                            state={row.state}
                            label={capacityLabel(row.state)}
                        />
                    ),
                },
                {
                    key: 'inbound',
                    label: 'Inbound',
                    width: '1fr',
                    cell: (row) => (
                        <div>
                            <EntityChip>{formatRate(row.inBps)}</EntityChip>
                            <span className="text-caption block">
                                {row.inUtilisation === null
                                    ? 'Utilisation not collected'
                                    : row.inUtilisation + '% utilisation'}
                            </span>
                        </div>
                    ),
                },
                {
                    key: 'outbound',
                    label: 'Outbound',
                    width: '1fr',
                    cell: (row) => (
                        <div>
                            <EntityChip>{formatRate(row.outBps)}</EntityChip>
                            <span className="text-caption block">
                                {row.outUtilisation === null
                                    ? 'Utilisation not collected'
                                    : row.outUtilisation + '% utilisation'}
                            </span>
                        </div>
                    ),
                },
                {
                    key: 'source',
                    label: 'Source',
                    width: '1.2fr',
                    cell: (row) => stateLabel(row.source),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (row) => <Observation time={row.observedAt} />,
                },
            ]}
            title="Traffic and capacity evidence"
            description="Retained readings and their collection source."
            rows={rows}
            view={view}
            icon={Gauge}
            rowKey={(row) => row.monitorId}
            identity={(row) => ({
                icon: Gauge,
                name: row.interface,
                subline: row.deviceName,
            })}
            hrefFor={(row) => row.deviceHref}
            actionsFor={(row) => [OpenDevice(row.deviceHref)]}
            empty="No retained traffic or capacity metrics are available."
            gap={
                gap > 0 ? (
                    <Gap
                        text={`${gap} ${gap === 1 ? 'device has' : 'devices have'} no retained capacity evidence`}
                    />
                ) : undefined
            }
            cardFor={(row) => ({
                icon: Gauge,
                name: row.interface,
                subline: row.deviceName,
                meridian: meridian(row.state),
                chips: (
                    <>
                        <StateBadge
                            state={row.state}
                            label={capacityLabel(row.state)}
                        />
                        {rates(row).map(([label, value]) => (
                            <EntityChip key={label} outline>
                                {label}: <span>{value}</span>
                            </EntityChip>
                        ))}
                    </>
                ),
                alerts: <EntityChip>{stateLabel(row.source)}</EntityChip>,
                footer: {
                    personIcon: Clock3,
                    primary: (
                        <a
                            onClick={(event) => event.stopPropagation()}
                            href={row.deviceHref}
                            className="hover:text-primary hover:underline"
                        >
                            {row.deviceName}
                        </a>
                    ),
                    secondary: <Observation time={row.observedAt} />,
                },
            })}
            columns={[
                {
                    key: 'state',
                    label: 'Capacity',
                    width: '140px',
                    cell: (row) => (
                        <StateBadge
                            state={row.state}
                            label={capacityLabel(row.state)}
                        />
                    ),
                },
                {
                    key: 'rates',
                    label: 'Traffic',
                    width: '1.6fr',
                    cell: (row) => <Facts items={rates(row)} />,
                },
                {
                    key: 'source',
                    label: 'Source',
                    width: '1.1fr',
                    cell: (row) => (
                        <EntityChip>{stateLabel(row.source)}</EntityChip>
                    ),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (row) => <Observation time={row.observedAt} />,
                },
            ]}
        />
    );
}
function snapshotActions(
    snapshot: ConfigurationSnapshotRow,
    deviceHref: string,
): MenuItem[] {
    return [
        OpenDevice(deviceHref),
        ...(snapshot.downloadHref
            ? [
                  {
                      label: 'Download snapshot',
                      icon: Download,
                      onClick: () =>
                          window.location.assign(snapshot.downloadHref!),
                  },
              ]
            : []),
    ];
}
function SnapshotDetails({ row }: { row: ConfigurationRow }) {
    const latest = row.latestSnapshot;
    return (
        <div
            className="space-y-5"
            onClick={(event) => event.stopPropagation()}
            onContextMenu={(event) => event.stopPropagation()}
        >
            {!latest && !row.snapshotHistory?.length ? (
                <p className="text-sm text-muted-foreground">
                    No saved snapshot
                </p>
            ) : null}
            {latest ? (
                <ReviewCard icon={FileDiff} title="Latest governed snapshot">
                    <ReviewRow
                        label="Storage"
                        value={<StateBadge state={latest.storageState} />}
                    />
                    <ReviewRow
                        label="Source"
                        value={`${stateLabel(latest.sourceKind)} · ${stateLabel(latest.source)}`}
                    />
                    <ReviewRow
                        label="Captured"
                        value={
                            latest.capturedAt
                                ? formatDateTime(latest.capturedAt)
                                : 'Capture time unavailable'
                        }
                    />
                    <ReviewRow
                        label="Configuration fingerprint"
                        value={<Hash value={latest.configurationHash} />}
                    />
                    <ReviewRow
                        label="Content fingerprint"
                        value={<Hash value={latest.contentHash} />}
                    />
                    <ReviewRow
                        label="Content"
                        value={`${latest.contentSize} bytes · ${latest.mimeType}`}
                    />
                    <ReviewRow
                        label="Firmware"
                        value={latest.firmwareVersion ?? 'not recorded'}
                    />
                    <ReviewRow
                        label="Comparison"
                        value={snapshotDiffLabel(latest)}
                    />
                    {latest.downloadHref ? (
                        <a
                            onClick={(event) => event.stopPropagation()}
                            href={latest.downloadHref}
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Download className="size-4" />
                            Download governed snapshot
                        </a>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Download unavailable
                        </p>
                    )}
                </ReviewCard>
            ) : null}
            {(row.snapshotHistory?.length ?? 0) > 1 ? (
                <details className="rounded-lg border border-border bg-card">
                    <summary className="cursor-pointer rounded-lg px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring">
                        View previous governed snapshots (
                        {(row.snapshotHistory?.length ?? 1) - 1})
                    </summary>
                    <div className="space-y-3 p-3">
                        <SnapshotHistory
                            rows={row.snapshotHistory!.slice(1)}
                            deviceHref={row.deviceHref}
                        />
                    </div>
                </details>
            ) : null}
            {row.snapshotHistoryTruncated ? (
                <p className="text-caption">
                    Additional retained snapshots exist outside this bounded
                    Site history.
                </p>
            ) : null}
        </div>
    );
}
function SnapshotHistory({
    rows,
    deviceHref,
}: {
    rows: ConfigurationSnapshotRow[];
    deviceHref: string;
}) {
    const context = useEntityContextMenu<ConfigurationSnapshotRow>();
    return (
        <>
            <ListCaption
                title="Previous snapshots"
                caption={
                    rows.length +
                    ' loaded ' +
                    (rows.length === 1 ? 'snapshot' : 'snapshots') +
                    ' shown'
                }
            />
            <EntityCardGrid className="grid-cols-1">
                {rows.map((row) => (
                    <EntityCard
                        key={row.id}
                        icon={FileDiff}
                        name={'Snapshot #' + row.id}
                        subline={
                            stateLabel(row.sourceKind) +
                            ' · ' +
                            stateLabel(row.source)
                        }
                        meridian="neutral"
                        actions={snapshotActions(row, deviceHref)}
                        onContextMenu={(event) => context.open(event, row)}
                        chips={
                            <>
                                <StateBadge state={row.storageState} />
                                <EntityChip>
                                    Firmware:{' '}
                                    {row.firmwareVersion ?? 'Not recorded'}
                                </EntityChip>
                                <EntityChip outline>
                                    Configuration:{' '}
                                    <Hash value={row.configurationHash} />
                                </EntityChip>
                                <EntityChip outline>
                                    Content: <Hash value={row.contentHash} />
                                </EntityChip>
                                <EntityChip>
                                    {row.contentSize} bytes · {row.mimeType}
                                </EntityChip>
                            </>
                        }
                        alerts={
                            <EntityChip>{snapshotDiffLabel(row)}</EntityChip>
                        }
                        footer={{
                            personIcon: Clock3,
                            primary: row.capturedAt ? (
                                <span
                                    className="block whitespace-normal"
                                    title={formatDateTime(row.capturedAt)}
                                >
                                    {formatDateTime(row.capturedAt)}
                                </span>
                            ) : (
                                'Capture time unavailable'
                            ),
                            trailing: row.downloadHref ? (
                                <a
                                    onClick={(event) => event.stopPropagation()}
                                    href={row.downloadHref}
                                    className="text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    Download this snapshot
                                </a>
                            ) : (
                                <span className="text-caption">
                                    Unavailable
                                </span>
                            ),
                        }}
                    />
                ))}
            </EntityCardGrid>
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={'Snapshot #' + context.ctx.record.id}
                    icon={FileDiff}
                    items={snapshotActions(context.ctx.record, deviceHref)}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}
function ConfigurationRecords({
    data,
    view,
}: {
    data: NetworkItWorkspaceData;
    view: NetworkRecordsView;
}) {
    const rows = data.activeTab.configuration,
        gaps = data.activeTab.gaps;
    const configuration = (
        row: ConfigurationRow,
    ): Array<[string, ReactNode]> => [
        [
            'Observed fingerprint',
            <Hash key="observed" value={row.configuration.observedHash} />,
        ],
        [
            'Desired fingerprint',
            <Hash key="desired" value={row.configuration.desiredHash} />,
        ],
        [
            'Observed',
            row.configuration.observedAt
                ? formatDateTime(row.configuration.observedAt)
                : 'Not observed',
        ],
    ];
    const firmware = (row: ConfigurationRow): Array<[string, ReactNode]> => [
        ['Current', row.firmware.currentVersion ?? 'Not observed'],
        ['Desired', row.firmware.desiredVersion ?? 'Not configured'],
        [
            'Observed',
            row.firmware.observedAt
                ? formatDateTime(row.firmware.observedAt)
                : 'Not observed',
        ],
    ];
    const state = (row: ConfigurationRow) => (
        <>
            <StateBadge
                state={row.configuration.state}
                label={
                    row.configuration.state === 'drifted'
                        ? 'Configuration drift'
                        : undefined
                }
            />
            <StateBadge state={row.firmware.state} />
        </>
    );
    const actions = (row: ConfigurationRow): MenuItem[] => [
        OpenDevice(row.deviceHref),
        ...(row.latestSnapshot?.downloadHref
            ? [
                  {
                      label: 'Download latest snapshot',
                      icon: Download,
                      onClick: () =>
                          window.location.assign(
                              row.latestSnapshot!.downloadHref!,
                          ),
                  },
              ]
            : []),
    ];
    return (
        <Records
            initialDetailSection="snapshots"
            compactColumns={[
                {
                    key: 'configuration',
                    label: 'Configuration',
                    width: '145px',
                    cell: (row) => (
                        <StateBadge state={row.configuration.state} />
                    ),
                },
                {
                    key: 'firmware',
                    label: 'Firmware',
                    width: '145px',
                    cell: (row) => <StateBadge state={row.firmware.state} />,
                },
                {
                    key: 'version',
                    label: 'Current version',
                    width: '1fr',
                    cell: (row) => (
                        <EntityChip>
                            {row.firmware.currentVersion ?? 'Not observed'}
                        </EntityChip>
                    ),
                },
                {
                    key: 'snapshot',
                    label: 'Latest snapshot',
                    width: '1.2fr',
                    cell: (row) =>
                        row.latestSnapshot ? (
                            <div>
                                <EntityChip>
                                    Snapshot #{row.latestSnapshot.id}
                                </EntityChip>
                                <span className="text-caption block">
                                    {stateLabel(
                                        row.latestSnapshot.storageState,
                                    )}
                                </span>
                            </div>
                        ) : (
                            'No saved snapshot'
                        ),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (row) => (
                        <Observation
                            time={
                                row.configuration.observedAt ??
                                row.firmware.observedAt
                            }
                            empty="Not observed"
                        />
                    ),
                },
            ]}
            title="Configuration and firmware evidence"
            description="Observed and desired state; changes are managed through the owning controls."
            rows={rows}
            view={view}
            icon={FileDiff}
            rowKey={(row) => row.deviceId}
            identity={(row) => ({
                icon: FileDiff,
                name: row.deviceName,
                subline: 'Configuration and firmware',
            })}
            hrefFor={(row) => row.deviceHref}
            actionsFor={actions}
            empty="No configuration or firmware evidence is visible."
            gap={
                gaps.devicesWithoutConfigurationEvidence > 0 ||
                gaps.devicesWithoutFirmwareEvidence > 0 ? (
                    <Gap
                        text={`${gaps.devicesWithoutConfigurationEvidence} without configuration evidence · ${gaps.devicesWithoutFirmwareEvidence} without firmware evidence`}
                    />
                ) : undefined
            }
            cardFor={(row) => ({
                icon: FileDiff,
                name: row.deviceName,
                subline: 'Reported configuration and firmware',
                meridian: meridian(row.configuration.state, row.firmware.state),
                chips: (
                    <>
                        {state(row)}
                        {configuration(row).map(([label, value]) => (
                            <EntityChip key={'configuration-' + label} outline>
                                Configuration {label}: <span>{value}</span>
                            </EntityChip>
                        ))}
                        {firmware(row).map(([label, value]) => (
                            <EntityChip key={'firmware-' + label} outline>
                                Firmware {label}: <span>{value}</span>
                            </EntityChip>
                        ))}
                    </>
                ),
                footer: {
                    personIcon: FileDiff,
                    primary: row.latestSnapshot
                        ? `Snapshot #${row.latestSnapshot.id}`
                        : 'No saved snapshot',
                    secondary: (
                        <Observation
                            time={
                                row.configuration.observedAt ??
                                row.firmware.observedAt
                            }
                            empty="Not observed"
                        />
                    ),
                },
            })}
            extraFor={(row) => <SnapshotDetails row={row} />}
            columns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '160px',
                    cell: (row) => (
                        <div className="flex flex-wrap gap-1">{state(row)}</div>
                    ),
                },
                {
                    key: 'configuration',
                    label: 'Configuration',
                    width: '1.5fr',
                    cell: (row) => <Facts items={configuration(row)} />,
                },
                {
                    key: 'firmware',
                    label: 'Firmware',
                    width: '1.1fr',
                    cell: (row) => <Facts items={firmware(row)} />,
                },
                {
                    key: 'snapshots',
                    label: 'Saved snapshots',
                    width: '2fr',
                    cell: (row) => <SnapshotDetails row={row} />,
                },
            ]}
            minWidth={1250}
        />
    );
}
export function NetworkOperationalRecords({
    data,
    view = 'cards',
}: {
    data: NetworkItWorkspaceData;
    view?: NetworkRecordsView;
}) {
    switch (data.activeTab.key) {
        case 'devices':
            return <DeviceRecords data={data} view={view} />;
        case 'interfaces':
            return <InterfaceRecords data={data} view={view} />;
        case 'services':
            return <ServiceRecords data={data} view={view} />;
        case 'traffic-capacity':
            return <TrafficRecords data={data} view={view} />;
        case 'configuration-firmware':
            return <ConfigurationRecords data={data} view={view} />;
        default:
            return null;
    }
}
