import {
    EntityChip,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { ControlRoomAlertAccessRequired } from '@/components/security-devices/permission-destinations';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import {
    DeviceCard,
    deviceHealthMeridian,
    type DeviceListItem,
} from '@/pages/security-devices/devices/shared';
import type {
    Alert,
    Collector,
    ContactItem,
    DeviceGroup,
    ItWork,
    Maintenance,
    Monitor,
    SiteTechnologyProps,
} from '@/pages/security-devices/sites/_types';
import { router } from '@inertiajs/react';
import {
    Activity,
    BellRing,
    Clock3,
    Contact,
    Cpu,
    Eye,
    FolderTree,
    Network,
    Pencil,
    RadioTower,
    TicketCheck,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';

export function technologyLabel(value: string | null | undefined) {
    return value ? value.replaceAll('_', ' ') : 'Not recorded';
}
export function SiteEvidenceFields({
    items,
}: {
    items: Array<[string, ReactNode]>;
}) {
    return (
        <div className="space-y-1">
            {items.map(([label, value]) => (
                <ReviewRow
                    key={label}
                    label={label}
                    value={value ?? 'Restricted'}
                />
            ))}
        </div>
    );
}
export function SiteEvidenceTime({
    value,
    empty = 'No time recorded',
}: {
    value: string | null;
    empty?: string;
}) {
    return value ? (
        <span title={formatDateTime(value)}>{formatRelative(value)}</span>
    ) : (
        <span>{empty}</span>
    );
}
const open = (label: string, href: string) => ({
    label,
    icon: Eye,
    onClick: () => router.visit(href),
});
const state = (value: string | null) => (
    <OperationalStateBadge state={value ?? 'unknown'} />
);

function ProfileDeviceCard({
    row,
    actions,
}: {
    row: DeviceListItem;
    actions: MenuItem[];
}) {
    return <DeviceCard device={row} actions={actions} />;
}
export function SiteDevices({
    rows,
    view,
    title = 'Devices at this Site',
    total,
}: {
    rows: DeviceListItem[];
    view: EvidenceRecordsView;
    title?: string;
    total?: number;
}) {
    const columns: EntityTableColumn<DeviceListItem>[] = [
        {
            key: 'state',
            label: 'State and health',
            width: '1fr',
            cell: (r) => (
                <SiteEvidenceFields
                    items={[
                        ['Lifecycle', state(r.status)],
                        ['Technical health', state(r.health_status)],
                    ]}
                />
            ),
        },
        {
            key: 'identity',
            label: 'Equipment and identifiers',
            width: '1fr',
            cell: (r) => (
                <SiteEvidenceFields
                    items={[
                        ['Device identifier', r.device_uid],
                        ['Domain', technologyLabel(r.domain)],
                        ['Category', technologyLabel(r.category)],
                        ['Subcategory', technologyLabel(r.subcategory)],
                        ['Manufacturer', r.manufacturer ?? 'Not recorded'],
                        ['Model', r.model ?? 'Not recorded'],
                        ['Provider', r.provider ?? 'Not recorded'],
                        [
                            'Battery',
                            r.battery_level === null
                                ? 'Not recorded'
                                : r.battery_level + '%',
                        ],
                    ]}
                />
            ),
        },
        {
            key: 'monitoring',
            label: 'Monitoring',
            width: '1fr',
            cell: (r) => (
                <SiteEvidenceFields
                    items={[
                        [
                            'Coverage',
                            r.monitoring_state
                                ? technologyLabel(r.monitoring_state)
                                : 'Not recorded',
                        ],
                        ['Configured checks', r.monitor_count ?? 'Restricted'],
                    ]}
                />
            ),
        },
        {
            key: 'ownership',
            label: 'Assignment and observation',
            width: '1fr',
            cell: (r) => (
                <SiteEvidenceFields
                    items={[
                        ['Assigned to', r.assigned_to ?? 'Not assigned'],
                        ['Assignment', technologyLabel(r.assignment_type)],
                        [
                            'Last observation',
                            r.last_seen_at
                                ? formatDateTime(r.last_seen_at)
                                : 'Never observed',
                        ],
                        [
                            'Last change',
                            r.last_changed_at
                                ? formatDateTime(r.last_changed_at)
                                : 'No change recorded',
                        ],
                    ]}
                />
            ),
        },
    ];
    return (
        <EvidenceRecords<DeviceListItem>
            title={title}
            description="Devices assigned within this approved Site scope."
            rows={rows}
            view={view}
            icon={Cpu}
            total={total}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: Cpu,
                name: r.name,
                subline: r.device_uid,
            })}
            hrefFor={(r) => '/security-devices/devices/' + r.id}
            actionsFor={(r) => [
                open('Open device', '/security-devices/devices/' + r.id),
            ]}
            cardFor={(r) => ({
                icon: Cpu,
                name: r.name,
                meridian: deviceHealthMeridian(r),
            })}
            cardComponent={ProfileDeviceCard}
            columns={columns}
            compactColumns={[
                {
                    key: 'status',
                    label: 'Lifecycle',
                    width: '100px',
                    cell: (r) => state(r.status),
                },
                {
                    key: 'health',
                    label: 'Health',
                    width: '110px',
                    cell: (r) => state(r.health_status),
                },
                {
                    key: 'equipment',
                    label: 'Equipment',
                    width: '1.3fr',
                    cell: (r) => (
                        <EntityChip>
                            {[r.manufacturer, r.model]
                                .filter(Boolean)
                                .join(' ') || technologyLabel(r.category)}
                        </EntityChip>
                    ),
                },
                {
                    key: 'monitoring',
                    label: 'Monitoring',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>
                            {r.monitoring_state
                                ? technologyLabel(r.monitoring_state)
                                : 'Not recorded'}
                        </EntityChip>
                    ),
                },
                {
                    key: 'observed',
                    label: 'Last observation',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceTime
                            value={r.last_seen_at}
                            empty="Never observed"
                        />
                    ),
                },
            ]}
            empty="No devices assigned in this view."
        />
    );
}
const siteMonitorRunsFrom = (r: Monitor): string =>
    r.runs_from ??
    (r.collector_name ? `Runs from: hub ${r.collector_name}` : 'Runs from: server');

export function SiteMonitors({
    rows,
    view,
}: {
    rows: Monitor[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<Monitor>
            title="Monitor checks"
            description="Latest retained results; a missing observation stays unknown."
            rows={rows}
            view={view}
            icon={Activity}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: Activity,
                name: r.name,
                subline: `${r.device_name} · ${siteMonitorRunsFrom(r)}`,
            })}
            hrefFor={(r) => '/security-devices/devices/' + r.device_id}
            actionsFor={(r) => [
                open('Open device', '/security-devices/devices/' + r.device_id),
            ]}
            cardFor={(r) => ({
                icon: Activity,
                name: r.name,
                subline: r.device_name,
                meridian: ['failed', 'offline'].includes(r.state)
                    ? 'critical'
                    : ['degraded', 'stale'].includes(r.state)
                      ? 'warning'
                      : r.state === 'healthy'
                        ? 'success'
                        : 'neutral',
                chips: (
                    <>
                        {state(r.state)}
                        <EntityChip>{technologyLabel(r.kind)}</EntityChip>
                        <EntityChip>{siteMonitorRunsFrom(r)}</EntityChip>
                    </>
                ),
                footer: {
                    personIcon: Clock3,
                    primary: (
                        <SiteEvidenceTime
                            value={r.last_observation_at}
                            empty="No observation received"
                        />
                    ),
                },
            })}
            compactColumns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '110px',
                    cell: (r) => state(r.state),
                },
                {
                    key: 'kind',
                    label: 'Check',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.kind)}</EntityChip>
                    ),
                },
                {
                    key: 'runs_from',
                    label: 'Runs from',
                    width: '1.2fr',
                    cell: (r) => siteMonitorRunsFrom(r),
                },
                {
                    key: 'observed',
                    label: 'Observed',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceTime
                            value={r.last_observation_at}
                            empty="No observation received"
                        />
                    ),
                },
            ]}
            columns={[
                {
                    key: 'result',
                    label: 'Result',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['State', state(r.state)],
                                ['Check', technologyLabel(r.kind)],
                                ['Runs from', siteMonitorRunsFrom(r)],
                                [
                                    'Observed',
                                    r.last_observation_at
                                        ? formatDateTime(r.last_observation_at)
                                        : 'No observation received',
                                ],
                            ]}
                        />
                    ),
                },
                {
                    key: 'source',
                    label: 'Owning device',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Device', r.device_name],
                                ['Device identifier', r.device_id],
                                ['Monitor identifier', r.id],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No monitor results in this view."
        />
    );
}
export function SiteCollectors({
    rows,
    view,
    onEditHub,
}: {
    rows: Collector[];
    view: EvidenceRecordsView;
    onEditHub?: (collector: Collector) => void;
}) {
    const collectorSubline = (r: Collector): string => {
        const parts: string[] = [
            r.is_home_site === false
                ? 'Covers this Site over SD-WAN'
                : 'Site hub',
        ];
        if (r.software_version) {
            parts.push(`v${r.software_version}`);
        }
        if (r.monitor_load !== undefined || r.scope_load !== undefined) {
            parts.push(
                `${r.monitor_load ?? 0} checks · ${r.scope_load ?? 0} scans`,
            );
        }
        return parts.join(' · ');
    };
    return (
        <EvidenceRecords<Collector>
            title="Site hubs and collectors"
            description="Reported hub health, software version, and last heartbeat. A local hub is optional when the main server can reach this Site directly."
            rows={rows}
            view={view}
            icon={RadioTower}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: RadioTower,
                name: r.name,
                subline: collectorSubline(r),
            })}
            actionsFor={(r) =>
                onEditHub &&
                r.update_url === `/security-devices/discovery/collectors/${r.id}`
                    ? [
                          {
                              label: 'Edit hub',
                              icon: Pencil,
                              onClick: () => onEditHub(r),
                          },
                      ]
                    : []
            }
            cardFor={(r) => ({
                icon: RadioTower,
                name: r.name,
                subline: collectorSubline(r),
                meridian:
                    r.state === 'online'
                        ? 'success'
                        : r.state === 'stale'
                          ? 'warning'
                          : 'neutral',
                chips: (
                    <>
                        {state(r.state)}
                        {r.software_version ? (
                            <EntityChip>v{r.software_version}</EntityChip>
                        ) : null}
                        {r.is_home_site === false ? (
                            <EntityChip>Covers over SD-WAN</EntityChip>
                        ) : null}
                    </>
                ),
                footer: {
                    personIcon: Clock3,
                    primary: (
                        <SiteEvidenceTime
                            value={r.last_heartbeat_at ?? r.last_seen_at}
                            empty="No contact recorded"
                        />
                    ),
                },
            })}
            compactColumns={[
                {
                    key: 'state',
                    label: 'State',
                    width: '110px',
                    cell: (r) => state(r.state),
                },
                {
                    key: 'version',
                    label: 'Version',
                    width: '110px',
                    cell: (r) =>
                        r.software_version ? (
                            <EntityChip>v{r.software_version}</EntityChip>
                        ) : (
                            'Not reported'
                        ),
                },
                {
                    key: 'status',
                    label: 'Reported status',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                    ),
                },
                {
                    key: 'seen',
                    label: 'Last heartbeat',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceTime
                            value={r.last_heartbeat_at ?? r.last_seen_at}
                            empty="No contact recorded"
                        />
                    ),
                },
            ]}
            columns={[
                {
                    key: 'state',
                    label: 'Hub health',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['State', state(r.state)],
                                ['Reported status', technologyLabel(r.status)],
                                [
                                    'Software version',
                                    r.software_version ?? 'Not reported',
                                ],
                                [
                                    'Last heartbeat',
                                    (r.last_heartbeat_at ?? r.last_seen_at)
                                        ? formatDateTime(
                                              r.last_heartbeat_at ??
                                                  r.last_seen_at,
                                          )
                                        : 'No contact recorded',
                                ],
                                [
                                    'Assigned checks',
                                    r.monitor_load ?? 'Not reported',
                                ],
                                [
                                    'Assigned scans',
                                    r.scope_load ?? 'Not reported',
                                ],
                            ]}
                        />
                    ),
                },
                {
                    key: 'identity',
                    label: 'Hub coverage',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Name', r.name],
                                [
                                    'Placement',
                                    r.is_home_site === false
                                        ? `Covers over SD-WAN${r.home_site ? ` from ${r.home_site.name}` : ''}`
                                        : 'Installed at this Site',
                                ],
                                [
                                    'Additional SD-WAN Sites',
                                    (r.covered_sites ?? []).length > 0
                                        ? (r.covered_sites ?? [])
                                              .map((covered) => covered.name)
                                              .join(', ')
                                        : 'None',
                                ],
                                ['Hub identifier', r.uuid],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No local hub configured."
        />
    );
}
export function SiteContacts({
    rows,
    view,
}: {
    rows: ContactItem[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<ContactItem>
            title="Technology contacts"
            description="Contacts recorded for this approved Site."
            rows={rows}
            view={view}
            icon={Contact}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: Contact,
                name: r.name,
                subline: r.role || technologyLabel(r.type),
            })}
            actionsFor={() => []}
            cardFor={(r) => ({
                icon: Contact,
                name: r.name,
                subline: r.role || technologyLabel(r.type),
                meridian: 'neutral',
                chips: (
                    <>
                        {r.is_primary ? (
                            <EntityChip>Primary contact</EntityChip>
                        ) : null}
                        <EntityChip>
                            {r.phone ?? 'No phone recorded'}
                        </EntityChip>
                        <EntityChip>
                            {r.email ?? 'No email recorded'}
                        </EntityChip>
                    </>
                ),
            })}
            compactColumns={[
                {
                    key: 'primary',
                    label: 'Contact',
                    width: '110px',
                    cell: (r) => (
                        <EntityChip>
                            {r.is_primary ? 'Primary' : 'Additional'}
                        </EntityChip>
                    ),
                },
                {
                    key: 'phone',
                    label: 'Phone',
                    width: '1fr',
                    cell: (r) => r.phone ?? 'Not recorded',
                },
                {
                    key: 'email',
                    label: 'Email',
                    width: '1.4fr',
                    cell: (r) => r.email ?? 'Not recorded',
                },
            ]}
            columns={[
                {
                    key: 'contact',
                    label: 'Contact details',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Name', r.name],
                                ['Role', r.role ?? 'Not recorded'],
                                ['Type', technologyLabel(r.type)],
                                [
                                    'Primary contact',
                                    r.is_primary ? 'Yes' : 'No',
                                ],
                                ['Phone', r.phone ?? 'Not recorded'],
                                ['Email', r.email ?? 'Not recorded'],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No technology contact recorded."
        />
    );
}
export function SiteGroups({
    rows,
    view,
}: {
    rows: DeviceGroup[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<DeviceGroup>
            title="Device groups"
            description="Groups containing devices within this Site scope."
            rows={rows}
            view={view}
            icon={FolderTree}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: FolderTree,
                name: r.name,
                subline: technologyLabel(r.type),
            })}
            hrefFor={(r) => r.href}
            actionsFor={(r) => [open('Open group', r.href)]}
            cardFor={(r) => ({
                icon: FolderTree,
                name: r.name,
                subline: technologyLabel(r.type),
                meridian: 'neutral',
                chips: <EntityChip>{r.device_count} Site devices</EntityChip>,
            })}
            compactColumns={[
                {
                    key: 'type',
                    label: 'Group type',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.type)}</EntityChip>
                    ),
                },
                {
                    key: 'count',
                    label: 'Site devices',
                    width: '110px',
                    cell: (r) => <EntityChip>{r.device_count}</EntityChip>,
                },
            ]}
            columns={[
                {
                    key: 'group',
                    label: 'Group details',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Name', r.name],
                                ['Type', technologyLabel(r.type)],
                                ['Devices in this Site scope', r.device_count],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No device groups in this Site scope."
        />
    );
}
type Edge = SiteTechnologyProps['topology']['edges'][number];
export function SiteEdges({
    rows,
    view,
}: {
    rows: Edge[];
    view: EvidenceRecordsView;
}) {
    const name = (r: Edge) =>
        (r.parent_name ?? 'Unknown parent') +
        ' → ' +
        (r.child_name ?? 'Unknown child');
    return (
        <EvidenceRecords<Edge>
            title="Known topology links"
            description="Retained relationship evidence. Missing links are not healthy-path evidence."
            rows={rows}
            view={view}
            icon={Network}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: Network,
                name: name(r),
                subline: technologyLabel(r.type),
            })}
            actionsFor={() => []}
            cardFor={(r) => ({
                icon: Network,
                name: name(r),
                subline: technologyLabel(r.type),
                meridian: 'neutral',
                chips: (
                    <EntityChip>Port: {r.port ?? 'Not recorded'}</EntityChip>
                ),
            })}
            compactColumns={[
                {
                    key: 'type',
                    label: 'Relationship',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.type)}</EntityChip>
                    ),
                },
                {
                    key: 'port',
                    label: 'Port',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{r.port ?? 'Not recorded'}</EntityChip>
                    ),
                },
            ]}
            columns={[
                {
                    key: 'link',
                    label: 'Relationship details',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Parent', r.parent_name ?? 'Unknown'],
                                ['Child', r.child_name ?? 'Unknown'],
                                ['Relationship', technologyLabel(r.type)],
                                ['Port', r.port ?? 'Not recorded'],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No topology links recorded."
        />
    );
}
const readable = (r: Alert) => r.access.state === 'available' && !!r.href;
export function SiteAlerts({
    rows,
    view,
}: {
    rows: Alert[];
    view: EvidenceRecordsView;
}) {
    const safeRows = rows.map((r) =>
        readable(r)
            ? r
            : {
                  ...r,
                  title: null,
                  reference: null,
                  severity: null,
                  status: null,
                  triggered_at: null,
                  href: null,
              },
    );
    return (
        <EvidenceRecords<Alert>
            title="Control Room alerts"
            description="Up to 20 current alerts. Each alert keeps its own access requirement."
            rows={safeRows}
            view={view}
            icon={BellRing}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: BellRing,
                name: readable(r)
                    ? r.title || r.reference || 'Alert'
                    : r.access.label,
                subline: readable(r) ? r.reference : undefined,
            })}
            hrefFor={(r) => (readable(r) ? r.href : null)}
            actionsFor={(r) =>
                readable(r)
                    ? [open('Open alert', r.href!)]
                    : [
                          {
                              label: 'Open alert',
                              icon: Eye,
                              disabled: r.access.label,
                          },
                      ]
            }
            cardFor={(r) => ({
                icon: BellRing,
                name: readable(r) ? r.title || 'Alert' : r.access.label,
                subline: readable(r) ? r.reference : undefined,
                meridian:
                    readable(r) && r.severity === 'critical'
                        ? 'critical'
                        : readable(r)
                          ? 'warning'
                          : 'neutral',
                chips: readable(r) ? (
                    <>
                        {state(r.severity ?? 'unknown')}
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                    </>
                ) : (
                    <ControlRoomAlertAccessRequired label={r.access.label} />
                ),
                footer: readable(r)
                    ? {
                          personIcon: Clock3,
                          primary: <SiteEvidenceTime value={r.triggered_at} />,
                      }
                    : undefined,
            })}
            compactColumns={[
                {
                    key: 'severity',
                    label: 'Severity',
                    width: '110px',
                    cell: (r) =>
                        readable(r) ? state(r.severity) : 'Restricted',
                },
                {
                    key: 'status',
                    label: 'Status',
                    width: '1fr',
                    cell: (r) =>
                        readable(r) ? (
                            <EntityChip>{technologyLabel(r.status)}</EntityChip>
                        ) : (
                            'Restricted'
                        ),
                },
                {
                    key: 'triggered',
                    label: 'Triggered',
                    width: '1fr',
                    cell: (r) =>
                        readable(r) ? (
                            <SiteEvidenceTime value={r.triggered_at} />
                        ) : (
                            'Restricted'
                        ),
                },
            ]}
            columns={[
                {
                    key: 'alert',
                    label: 'Alert details',
                    width: '1fr',
                    cell: (r) =>
                        readable(r) ? (
                            <SiteEvidenceFields
                                items={[
                                    [
                                        'Reference',
                                        r.reference ?? 'Not recorded',
                                    ],
                                    ['Title', r.title ?? 'Not recorded'],
                                    ['Severity', state(r.severity)],
                                    ['Status', technologyLabel(r.status)],
                                    [
                                        'Triggered',
                                        r.triggered_at
                                            ? formatDateTime(r.triggered_at)
                                            : 'No time recorded',
                                    ],
                                ]}
                            />
                        ) : (
                            <ControlRoomAlertAccessRequired
                                label={r.access.label}
                            />
                        ),
                },
            ]}
            empty="No active alerts for this Site."
        />
    );
}
export function SiteItWork({
    rows,
    view,
}: {
    rows: ItWork[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<ItWork>
            title="Open IT work"
            description="Up to 20 recent open records, owned by IT & Support."
            rows={rows}
            view={view}
            icon={TicketCheck}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: TicketCheck,
                name: r.title,
                subline: r.reference ?? technologyLabel(r.work_type),
            })}
            hrefFor={(r) => r.href}
            actionsFor={(r) => [open('Open IT work', r.href)]}
            cardFor={(r) => ({
                icon: TicketCheck,
                name: r.title,
                subline: r.reference ?? technologyLabel(r.work_type),
                meridian: ['critical', 'urgent'].includes(r.priority)
                    ? 'critical'
                    : r.priority === 'high'
                      ? 'warning'
                      : 'neutral',
                chips: (
                    <>
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                        <EntityChip>
                            Priority: {technologyLabel(r.priority)}
                        </EntityChip>
                    </>
                ),
                alerts: r.next_action ? (
                    <EntityChip>{r.next_action}</EntityChip>
                ) : undefined,
                footer: {
                    personIcon: Clock3,
                    primary: <SiteEvidenceTime value={r.updated_at} />,
                },
            })}
            compactColumns={[
                {
                    key: 'status',
                    label: 'Status',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                    ),
                },
                {
                    key: 'priority',
                    label: 'Priority',
                    width: '110px',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.priority)}</EntityChip>
                    ),
                },
                {
                    key: 'updated',
                    label: 'Updated',
                    width: '1fr',
                    cell: (r) => <SiteEvidenceTime value={r.updated_at} />,
                },
            ]}
            columns={[
                {
                    key: 'work',
                    label: 'Work details',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Reference', r.reference ?? 'Not recorded'],
                                ['Title', r.title],
                                ['Work type', technologyLabel(r.work_type)],
                                ['Status', technologyLabel(r.status)],
                                ['Priority', technologyLabel(r.priority)],
                            ]}
                        />
                    ),
                },
                {
                    key: 'next',
                    label: 'Next action and update',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                [
                                    'Next action',
                                    r.next_action ?? 'Not recorded',
                                ],
                                [
                                    'Updated',
                                    r.updated_at
                                        ? formatDateTime(r.updated_at)
                                        : 'No time recorded',
                                ],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No open IT work for this Site."
        />
    );
}
export function SiteMaintenance({
    rows,
    view,
}: {
    rows: Maintenance[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<Maintenance>
            title="Maintenance"
            description="Up to 20 open maintenance records within this Site scope."
            rows={rows}
            view={view}
            icon={Wrench}
            rowKey={(r) => r.id}
            identity={(r) => ({
                icon: Wrench,
                name: r.description,
                subline: r.device_name ?? 'Device',
            })}
            hrefFor={(r) => '/security-devices/devices/' + r.device_id}
            actionsFor={(r) => [
                open('Open device', '/security-devices/devices/' + r.device_id),
            ]}
            cardFor={(r) => ({
                icon: Wrench,
                name: r.description,
                subline: r.device_name ?? 'Device',
                meridian: r.is_overdue ? 'warning' : 'neutral',
                chips: (
                    <>
                        {state(r.is_overdue ? 'warning' : 'pending')}
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                    </>
                ),
                footer: {
                    personIcon: Clock3,
                    primary: r.scheduled_for
                        ? formatDateTime(r.scheduled_for)
                        : 'Not scheduled',
                },
            })}
            compactColumns={[
                {
                    key: 'status',
                    label: 'Status',
                    width: '1fr',
                    cell: (r) => (
                        <EntityChip>{technologyLabel(r.status)}</EntityChip>
                    ),
                },
                {
                    key: 'due',
                    label: 'Schedule',
                    width: '110px',
                    cell: (r) => state(r.is_overdue ? 'warning' : 'pending'),
                },
                {
                    key: 'scheduled',
                    label: 'Scheduled for',
                    width: '1fr',
                    cell: (r) =>
                        r.scheduled_for
                            ? formatDateTime(r.scheduled_for)
                            : 'Not scheduled',
                },
            ]}
            columns={[
                {
                    key: 'maintenance',
                    label: 'Maintenance record',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Description', r.description],
                                ['Device', r.device_name ?? 'Device'],
                                ['Type', technologyLabel(r.type)],
                                ['Status', technologyLabel(r.status)],
                                [
                                    'Scheduled for',
                                    r.scheduled_for
                                        ? formatDateTime(r.scheduled_for)
                                        : 'Not scheduled',
                                ],
                                ['Overdue', r.is_overdue ? 'Yes' : 'No'],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No open maintenance for this Site."
        />
    );
}
type Change = SiteTechnologyProps['changes'][number];
export function SiteChanges({
    rows,
    view,
}: {
    rows: Change[];
    view: EvidenceRecordsView;
}) {
    return (
        <EvidenceRecords<Change>
            title="Recent changes"
            description="Recorded technology changes and their owning records."
            rows={rows}
            view={view}
            icon={Clock3}
            rowKey={(r) => r.key}
            identity={(r) => ({
                icon: Clock3,
                name: r.device_name ?? 'Device',
                subline: r.summary,
            })}
            hrefFor={(r) => r.href}
            actionsFor={(r) => [open('Open record', r.href)]}
            cardFor={(r) => ({
                icon: Clock3,
                name: r.device_name ?? 'Device',
                subline: r.summary,
                meridian: 'neutral',
                footer: {
                    personIcon: Clock3,
                    primary: <SiteEvidenceTime value={r.at} />,
                },
            })}
            compactColumns={[
                {
                    key: 'summary',
                    label: 'Change',
                    width: '2fr',
                    cell: (r) => r.summary,
                },
                {
                    key: 'at',
                    label: 'Recorded',
                    width: '1fr',
                    cell: (r) => <SiteEvidenceTime value={r.at} />,
                },
            ]}
            columns={[
                {
                    key: 'change',
                    label: 'Change details',
                    width: '1fr',
                    cell: (r) => (
                        <SiteEvidenceFields
                            items={[
                                ['Device', r.device_name ?? 'Device'],
                                ['Change', r.summary],
                                ['Recorded', formatDateTime(r.at)],
                            ]}
                        />
                    ),
                },
            ]}
            empty="No recent technology changes recorded."
        />
    );
}
