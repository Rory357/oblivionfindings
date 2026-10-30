import {
    EntityChip,
    type EntityMeridian,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import type { MonitorHistoryTarget } from '@/components/security-devices/monitor-history-dialog';
import { ControlRoomAlertAccessRequired } from '@/components/security-devices/permission-destinations';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { readableKey, readablePhrase } from '@/lib/security-devices-labels';
import type {
    CentralSiteReadiness,
    CollectionPath,
    MonitoringWorkspace,
    MonitorRow,
} from '@/pages/security-devices/monitoring';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    Building2,
    Cable,
    Clock3,
    Eye,
    Gauge,
    Network,
    Pencil,
    ShieldX,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type MonitoringView = EvidenceRecordsView;
export type MonitoringOwners = { profiles: boolean };
type Fact = [string, ReactNode];
/** Sentence-case words for a monitoring key ("Collection unavailable"). */
export function monitoringLabel(value: string | null | undefined) {
    return readableKey(value);
}
/** The same words mid-sentence ("Runtime collection unavailable"). */
export function monitoringPhrase(value: string | null | undefined) {
    return readablePhrase(value);
}
export function monitoringTone(value: string): StatusVariant {
    if (
        [
            'healthy',
            'fresh',
            'available',
            'ready',
            'verified',
            'current',
            'sent',
        ].includes(value)
    )
        return 'success';
    if (
        [
            'failed',
            'critical',
            'collection_unavailable',
            'unavailable',
            'revoked',
        ].includes(value)
    )
        return 'critical';
    if (
        [
            'degraded',
            'stale',
            'paused',
            'attention',
            'runtime_unavailable',
            'evidence_incomplete',
            'suppressed',
        ].includes(value)
    )
        return 'warning';
    return 'neutral';
}
export function MonitoringState({
    value,
    label,
}: {
    value: string;
    label?: string;
}) {
    return (
        <StatusBadge variant={monitoringTone(value)}>
            {label ?? monitoringLabel(value)}
        </StatusBadge>
    );
}
const stamp = (value: string | null) =>
    value ? formatDateTime(value) : 'Not observed';
const age = (value: number | null) =>
    value === null ? 'Not observed' : `${value}s`;
const yes = (value: boolean) => (value ? 'Yes' : 'No');
const percent = (value: number) => `${Math.round(value * 100)}%`;
export function MonitoringFields({ items }: { items: Fact[] }) {
    return (
        <div className="space-y-1">
            {items.map(([label, value]) => (
                <ReviewRow
                    key={label}
                    label={label}
                    value={value ?? 'Not recorded'}
                />
            ))}
        </div>
    );
}
function Owner({
    name,
    href,
    allowed,
}: {
    name: string;
    href: string | null;
    allowed: boolean;
}) {
    return allowed && href ? (
        <Link
            href={href}
            className="frontline-focus rounded-sm text-primary underline underline-offset-2"
            onClick={(event) => event.stopPropagation()}
        >
            {name}
        </Link>
    ) : (
        <span>{name}</span>
    );
}
function deviceHref(id: number, href: string | null, owners: MonitoringOwners) {
    return owners.profiles && href === `/security-devices/devices/${id}`
        ? href
        : null;
}
function siteHref(
    site: { id: number; href?: string } | null,
    owners: MonitoringOwners,
) {
    return owners.profiles &&
        site &&
        (!site.href || site.href === `/security-devices/sites/${site.id}`)
        ? `/security-devices/sites/${site.id}`
        : null;
}
function ownerAction(label: string, href: string | null): MenuItem {
    return {
        label,
        icon: Eye,
        onClick: () => {
            if (href) router.visit(href);
        },
        ...(href
            ? {}
            : { disabled: 'Device and Site profile permission required' }),
    };
}
function RecordList<T>({
    rows,
    view,
    title,
    description,
    keyFor,
    name,
    subline,
    icon: Icon,
    state,
    summary,
    footer,
    fields,
    columns,
    actionsFor,
    total,
    empty,
}: {
    rows: T[];
    view: MonitoringView;
    title: string;
    description: string;
    keyFor: (row: T) => string | number;
    name: (row: T) => string;
    subline: (row: T) => string;
    icon: LucideIcon;
    state: (row: T) => string;
    summary: (row: T) => ReactNode;
    footer?: (row: T) => ReactNode;
    fields: (row: T) => Fact[];
    columns: EntityTableColumn<T>[];
    actionsFor?: (row: T) => MenuItem[];
    total?: number;
    empty: string;
}) {
    return (
        <EvidenceRecords
            rows={rows}
            view={view}
            title={title}
            description={description}
            rowKey={keyFor}
            identity={(row) => ({
                icon: Icon,
                name: name(row),
                subline: subline(row),
            })}
            articleLabel={(row) => `${title}: ${name(row)} #${keyFor(row)}`}
            actionsFor={actionsFor ?? (() => [])}
            cardFor={(row) => ({
                icon: Icon,
                name: name(row),
                subline: subline(row),
                meridian: (['success', 'warning', 'critical'].includes(
                    monitoringTone(state(row)),
                )
                    ? monitoringTone(state(row))
                    : 'neutral') as EntityMeridian,
                chips: (
                    <div className="space-y-2 text-sm">
                        <MonitoringState value={state(row)} />
                        <div className="space-y-2">{summary(row)}</div>
                    </div>
                ),
                footer: {
                    personIcon: Clock3,
                    primary: footer?.(row) ?? 'Retained evidence',
                },
            })}
            compactColumns={columns}
            columns={[
                {
                    key: 'details',
                    label: 'Retained details',
                    width: '1fr',
                    cell: (row) => <MonitoringFields items={fields(row)} />,
                },
            ]}
            total={total}
            empty={empty}
            icon={Icon}
        />
    );
}
function correlation(row: MonitorRow) {
    const alert = row.correlation?.control_room,
        ticket = row.correlation?.it_incident;
    const alertHref =
        alert?.access.state === 'available' &&
        alert.href === `/control-room/alerts/${alert.id}`
            ? alert.href
            : null;
    const ticketHref =
        ticket && ticket.href === `/it/tickets/${ticket.id}`
            ? ticket.href
            : null;
    return (
        <div className="flex flex-wrap items-center gap-2">
            {alert ? (
                alertHref ? (
                    <Owner
                        name={`Review Control Room correlation ${alert.reference ?? `#${alert.id}`}`}
                        href={alertHref}
                        allowed
                    />
                ) : (
                    <ControlRoomAlertAccessRequired
                        label={alert.access.label}
                        className="min-h-0"
                    />
                )
            ) : null}
            {ticket ? (
                <Owner
                    name={`IT incident ${ticket.reference ?? `#${ticket.id}`}${ticket.monitoring_recovered_at ? ' · monitoring recovered, technician closure pending' : ''}`}
                    href={ticketHref}
                    allowed={Boolean(ticketHref)}
                />
            ) : null}
        </div>
    );
}
const monitorRunsFrom = (r: MonitorRow): string =>
    r.runs_from ??
    (r.collection.mode === 'remote_collector'
        ? `Runs from: hub ${r.collection.collector_name}`
        : 'Runs from: server');

export function MonitorRecords({
    rows,
    view,
    owners,
    total,
    onEdit,
    onDeactivate,
    onViewHistory,
    title = 'Monitor states',
    empty = 'No monitors match these filters',
    description = 'Collection failures are shown as uncertainty, not duplicated device failures.',
}: {
    rows: MonitorRow[];
    view: MonitoringView;
    owners: MonitoringOwners;
    total?: number;
    onEdit?: (row: MonitorRow) => void;
    onDeactivate?: (row: MonitorRow) => void;
    onViewHistory?: (target: MonitorHistoryTarget) => void;
    title?: string;
    description?: string;
    empty?: string;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title={title}
            description={description}
            total={total}
            keyFor={(r) => r.id}
            name={(r) => r.name}
            subline={(r) => `Monitor #${r.id} · ${r.device.name}`}
            icon={Activity}
            state={(r) => r.effective_state}
            summary={(r) => (
                <>
                    <p>
                        <Owner
                            name={r.device.name}
                            href={deviceHref(
                                r.device.id,
                                r.device.href,
                                owners,
                            )}
                            allowed={owners.profiles}
                        />
                        {r.site ? (
                            <>
                                {' '}
                                ·{' '}
                                <Owner
                                    name={r.site.name}
                                    href={siteHref(r.site, owners)}
                                    allowed={owners.profiles}
                                />
                            </>
                        ) : null}
                    </p>
                    <p>
                        {monitoringLabel(r.kind)} · {monitorRunsFrom(r)}
                    </p>
                    {r.reported_state !== r.effective_state ? (
                        <EntityChip>
                            Reported {monitoringPhrase(r.reported_state)}
                        </EntityChip>
                    ) : null}
                    {r.maintenance?.active_window ? (
                        <StatusBadge variant="warning">
                            Active maintenance:{' '}
                            {r.maintenance.active_window.name}
                        </StatusBadge>
                    ) : r.maintenance?.upcoming_window ? (
                        <StatusBadge variant="info">
                            Upcoming maintenance:{' '}
                            {r.maintenance.upcoming_window.name}
                        </StatusBadge>
                    ) : null}
                    {r.latest_observation ? (
                        <p>
                            Latest value{' '}
                            {r.latest_observation.value ?? 'Not numeric'}
                            {r.latest_observation.unit
                                ? ` ${r.latest_observation.unit}`
                                : ''}
                        </p>
                    ) : null}
                    {r.root_cause ? (
                        <p>
                            Suppressed by root cause{' '}
                            <Owner
                                name={r.root_cause.monitor_name}
                                href={deviceHref(
                                    r.root_cause.device_id,
                                    r.root_cause.href,
                                    owners,
                                )}
                                allowed={owners.profiles}
                            />
                            {r.suppression_reason
                                ? ` · ${monitoringPhrase(r.suppression_reason)}`
                                : ''}
                        </p>
                    ) : null}
                    {correlation(r)}
                </>
            )}
            footer={(r) => (
                <span
                    title={
                        r.last_observation_at
                            ? formatDateTime(r.last_observation_at)
                            : undefined
                    }
                >
                    Last observation{' '}
                    {r.last_observation_at
                        ? formatRelative(r.last_observation_at)
                        : 'Not observed'}
                </span>
            )}
            columns={[
                {
                    key: 'state',
                    label: 'Effective state',
                    width: '155px',
                    cell: (r) => <MonitoringState value={r.effective_state} />,
                },
                {
                    key: 'reported',
                    label: 'Reported',
                    width: '115px',
                    cell: (r) => <MonitoringState value={r.reported_state} />,
                },
                {
                    key: 'collection',
                    label: 'Runs from',
                    width: '175px',
                    cell: (r) => monitorRunsFrom(r),
                },
                {
                    key: 'freshness',
                    label: 'Freshness',
                    width: '110px',
                    cell: (r) => <MonitoringState value={r.freshness_state} />,
                },
                {
                    key: 'value',
                    label: 'Latest value',
                    width: '110px',
                    cell: (r) =>
                        r.latest_observation
                            ? `${r.latest_observation.value ?? 'Not numeric'} ${r.latest_observation.unit ?? ''}`
                            : 'Not observed',
                },
            ]}
            actionsFor={(r) => [
                ...(onViewHistory
                    ? [
                          {
                              label: 'View history',
                              icon: Activity,
                              onClick: () =>
                                  onViewHistory({
                                      monitorId: r.id,
                                      monitorName: r.name,
                                      deviceName: r.device.name,
                                  }),
                          },
                      ]
                    : []),
                ownerAction(
                    'Open device profile',
                    deviceHref(r.device.id, r.device.href, owners),
                ),
                ...(r.site
                    ? [
                          ownerAction(
                              'Open Site technology',
                              siteHref(r.site, owners),
                          ),
                      ]
                    : []),
                ...(r.actions?.can_manage &&
                r.actions.update_url ===
                    `/security-devices/monitoring/native-monitors/${r.id}` &&
                onEdit
                    ? [
                          {
                              label: 'Update monitor',
                              icon: Pencil,
                              onClick: () => onEdit(r),
                          },
                      ]
                    : []),
                ...(r.actions?.can_manage &&
                r.actions.deactivate_url ===
                    `/security-devices/monitoring/native-monitors/${r.id}/deactivate` &&
                onDeactivate
                    ? [
                          {
                              label: 'Deactivate',
                              icon: ShieldX,
                              onClick: () => onDeactivate(r),
                          },
                      ]
                    : []),
            ]}
            fields={(r) => [
                ['Monitor reference', `#${r.id}`],
                ['Name', r.name],
                ['Check type', monitoringLabel(r.kind)],
                [
                    'Reported state',
                    <MonitoringState value={r.reported_state} />,
                ],
                [
                    'Effective state',
                    <MonitoringState value={r.effective_state} />,
                ],
                ['Affects availability', yes(r.affects_availability)],
                ['Enabled', yes(r.enabled)],
                ['Operational', yes(r.operational)],
                [
                    'Profile',
                    r.profile
                        ? `${r.profile.name} (#${r.profile.id})`
                        : 'Not assigned',
                ],
                ['Suppressed until', stamp(r.suppressed_until)],
                ['Suppression reason', monitoringLabel(r.suppression_reason)],
                [
                    'Maintenance window',
                    r.maintenance?.active_window
                        ? `Active: ${r.maintenance.active_window.name} (until ${stamp(r.maintenance.active_window.ends_at)})`
                        : r.maintenance?.upcoming_window
                          ? `Upcoming: ${r.maintenance.upcoming_window.name} (starts ${stamp(r.maintenance.upcoming_window.starts_at)})`
                          : 'None active or scheduled in next 24h',
                ],
                [
                    'Root cause',
                    r.root_cause
                        ? `${r.root_cause.monitor_name} (monitor #${r.root_cause.monitor_id}, device #${r.root_cause.device_id})`
                        : 'No root cause recorded',
                ],
                [
                    'Root device',
                    r.root_cause ? (
                        <Owner
                            name={r.root_cause.monitor_name}
                            href={deviceHref(
                                r.root_cause.device_id,
                                r.root_cause.href,
                                owners,
                            )}
                            allowed={owners.profiles}
                        />
                    ) : (
                        'Not recorded'
                    ),
                ],
                ['Last observation', stamp(r.last_observation_at)],
                ['Last state change', stamp(r.last_state_changed_at ?? null)],
                ['Finding scope', monitoringLabel(r.finding_scope)],
                ['Freshness', <MonitoringState value={r.freshness_state} />],
                [
                    'Device',
                    <Owner
                        name={`${r.device.name} (#${r.device.id})`}
                        href={deviceHref(r.device.id, r.device.href, owners)}
                        allowed={owners.profiles}
                    />,
                ],
                ['Device area', monitoringLabel(r.device.domain)],
                ['Device category', monitoringLabel(r.device.category)],
                [
                    'Site',
                    r.site ? (
                        <Owner
                            name={`${r.site.name} (#${r.site.id})`}
                            href={siteHref(r.site, owners)}
                            allowed={owners.profiles}
                        />
                    ) : (
                        'No Site assigned'
                    ),
                ],
                ['Runs from', monitorRunsFrom(r)],
                ['Collection mode', monitoringLabel(r.collection.mode)],
                [
                    'Collection state',
                    <MonitoringState value={r.collection.state} />,
                ],
                [
                    'Collector',
                    r.collection.mode === 'remote_collector'
                        ? `${r.collection.collector_name} (#${r.collection.collector_id})`
                        : r.collection.label,
                ],
                [
                    'Collector last seen',
                    r.collection.mode === 'remote_collector'
                        ? stamp(r.collection.last_seen_at)
                        : 'Direct collection',
                ],
                [
                    'Latest observed state',
                    r.latest_observation ? (
                        <MonitoringState value={r.latest_observation.state} />
                    ) : (
                        'Not observed'
                    ),
                ],
                [
                    'Latest value',
                    r.latest_observation?.value ?? 'No numeric value',
                ],
                ['Unit', r.latest_observation?.unit ?? 'Not recorded'],
                [
                    'Latency',
                    r.latest_observation?.latency_ms === null ||
                    !r.latest_observation
                        ? 'Not observed'
                        : `${r.latest_observation.latency_ms}ms`,
                ],
                [
                    'Observed at',
                    stamp(r.latest_observation?.observed_at ?? null),
                ],
                ['Operational correlation', correlation(r)],
                [
                    'Control Room status',
                    r.correlation?.control_room?.access.state === 'available'
                        ? monitoringLabel(r.correlation.control_room.status)
                        : (r.correlation?.control_room?.access.label ??
                          'No correlation recorded'),
                ],
                [
                    'IT incident status',
                    r.correlation?.it_incident
                        ? monitoringLabel(r.correlation.it_incident.status)
                        : 'No correlation recorded',
                ],
                [
                    'IT incident title',
                    r.correlation?.it_incident?.title ??
                        'No correlation recorded',
                ],
                [
                    'Monitoring recovered at',
                    stamp(
                        r.correlation?.it_incident?.monitoring_recovered_at ??
                            null,
                    ),
                ],
                [
                    'Profile access',
                    owners.profiles
                        ? 'Device and Site profile links permitted'
                        : 'Device and Site profile permission required',
                ],
            ]}
            empty={empty}
        />
    );
}
export function CollectionRecords({
    rows,
    view,
    owners,
    title = 'Remote collection paths',
    description = 'Collection availability is separate from device health.',
}: {
    rows: CollectionPath[];
    view: MonitoringView;
    owners: MonitoringOwners;
    title?: string;
    description?: string;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title={title}
            description={description}
            keyFor={(r) => r.collector_id ?? r.collector_name}
            name={(r) => r.collector_name}
            subline={(r) => r.site?.name ?? 'No Site assigned'}
            icon={Cable}
            state={(r) => r.state}
            summary={(r) => (
                <>
                    <p>
                        {r.site ? (
                            <Owner
                                name={r.site.name}
                                href={siteHref(r.site, owners)}
                                allowed={owners.profiles}
                            />
                        ) : (
                            'No site assigned'
                        )}{' '}
                        · {r.affected_devices} affected devices ·{' '}
                        {r.affected_monitors} affected monitors
                    </p>
                    <p>Reported {monitoringPhrase(r.reported_status)}</p>
                </>
            )}
            footer={(r) => `Last heartbeat ${stamp(r.last_seen_at)}`}
            columns={[
                {
                    key: 'state',
                    label: 'Availability',
                    width: '145px',
                    cell: (r) => <MonitoringState value={r.state} />,
                },
                {
                    key: 'devices',
                    label: 'Devices',
                    width: '90px',
                    cell: (r) => r.affected_devices,
                },
                {
                    key: 'checks',
                    label: 'Checks',
                    width: '90px',
                    cell: (r) => r.affected_monitors,
                },
                {
                    key: 'seen',
                    label: 'Last heartbeat',
                    width: '180px',
                    cell: (r) => stamp(r.last_seen_at),
                },
            ]}
            actionsFor={(r) =>
                r.site
                    ? [
                          ownerAction(
                              'Open Site technology',
                              siteHref(r.site, owners),
                          ),
                      ]
                    : []
            }
            fields={(r) => [
                [
                    'Collector reference',
                    r.collector_id ? `#${r.collector_id}` : 'Not recorded',
                ],
                ['Collector', r.collector_name],
                ['Availability', <MonitoringState value={r.state} />],
                ['Reported status', monitoringLabel(r.reported_status)],
                ['Last heartbeat', stamp(r.last_seen_at)],
                ['Heartbeat lag', age(r.heartbeat_lag_seconds)],
                [
                    'Site',
                    r.site ? (
                        <Owner
                            name={r.site.name}
                            href={siteHref(r.site, owners)}
                            allowed={owners.profiles}
                        />
                    ) : (
                        'No Site assigned'
                    ),
                ],
                ['Affected checks', r.affected_monitors],
                ['Affected devices', r.affected_devices],
            ]}
            empty="No collection paths in this view"
        />
    );
}
export function ReadinessRecords({
    rows,
    view,
    owners,
}: {
    rows: CentralSiteReadiness[];
    view: MonitoringView;
    owners: MonitoringOwners;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Central Site readiness"
            description="Retained Site evidence for direct monitoring, runtime, topology and discovery. A collector is needed only where direct collection cannot reach approved equipment."
            keyFor={(r) => r.site.id}
            name={(r) => r.site.name}
            subline={(r) => r.label}
            icon={Building2}
            state={(r) => r.state}
            summary={(r) => (
                <>
                    <Owner
                        name={r.site.name}
                        href={siteHref(r.site, owners)}
                        allowed={owners.profiles}
                    />
                    <p>{r.note}</p>
                    <div className="flex flex-wrap gap-2">
                        <MonitoringState
                            value={r.proof_state}
                            label={
                                r.proof_state === 'verified'
                                    ? 'Central path verified'
                                    : 'Central path not verified'
                            }
                        />
                        <MonitoringState
                            value={r.runtime.state}
                            label={`Runtime ${monitoringPhrase(r.runtime.state)}`}
                        />
                        <MonitoringState
                            value={r.topology.state}
                            label={`Topology ${monitoringPhrase(r.topology.state)}`}
                        />
                        <MonitoringState
                            value={r.discovery.state}
                            label={`Discovery ${monitoringPhrase(r.discovery.state)}`}
                        />
                        {r.unmonitored_devices > 0 ? (
                            <EntityChip>
                                {r.unmonitored_devices}{' '}
                                {r.unmonitored_devices === 1
                                    ? 'Device needs'
                                    : 'Devices need'}{' '}
                                coverage
                            </EntityChip>
                        ) : null}
                    </div>
                    <p>
                        {r.fresh}/{r.direct_monitors} checks with fresh central
                        evidence · {r.runtime.available}/{r.runtime.required}{' '}
                        required workers
                    </p>
                </>
            )}
            footer={(r) =>
                `Latest durable central evidence ${stamp(r.evidence_at)}`
            }
            columns={[
                {
                    key: 'proof',
                    label: 'Proof',
                    width: '180px',
                    cell: (r) => (
                        <MonitoringState
                            value={r.proof_state}
                            label={
                                r.proof_state === 'verified'
                                    ? 'Central path verified'
                                    : 'Central path not verified'
                            }
                        />
                    ),
                },
                {
                    key: 'fresh',
                    label: 'Fresh/direct checks',
                    width: '120px',
                    cell: (r) => `${r.fresh}/${r.direct_monitors}`,
                },
                {
                    key: 'workers',
                    label: 'Required workers',
                    width: '125px',
                    cell: (r) => `${r.runtime.available}/${r.runtime.required}`,
                },
                {
                    key: 'evidence',
                    label: 'Evidence time',
                    width: '185px',
                    cell: (r) => stamp(r.evidence_at),
                },
            ]}
            actionsFor={(r) => [
                ownerAction('Open Site technology', siteHref(r.site, owners)),
            ]}
            fields={(r) => [
                [
                    'Site',
                    <Owner
                        name={`${r.site.name} (#${r.site.id})`}
                        href={siteHref(r.site, owners)}
                        allowed={owners.profiles}
                    />,
                ],
                ['Readiness', r.label],
                ['State', <MonitoringState value={r.state} />],
                ['Proof', <MonitoringState value={r.proof_state} />],
                ['Explanation', r.note],
                ['Devices', r.devices],
                ['Monitored devices', r.monitored_devices],
                ['Devices missing coverage', r.unmonitored_devices],
                ['Direct checks', r.direct_monitors],
                ['Direct devices', r.direct_devices],
                ['Collector-backed checks', r.remote_monitors],
                ['Disabled checks', r.disabled_monitors],
                ['Durable direct observations', r.durable_direct_evidence],
                ['Fresh', r.fresh],
                ['Stale', r.stale],
                ['Never observed', r.never_observed],
                ['Attention', r.attention],
                ['Evidence time', stamp(r.evidence_at)],
                ['Evidence age', age(r.evidence_age_seconds)],
                ['Runtime', <MonitoringState value={r.runtime.state} />],
                [
                    'Workers available/required',
                    `${r.runtime.available}/${r.runtime.required}`,
                ],
                ...Object.entries(r.runtime.components).map(
                    ([name, state]): Fact => [
                        `Runtime: ${monitoringLabel(name)}`,
                        <MonitoringState value={state} />,
                    ],
                ),
                ['Topology', <MonitoringState value={r.topology.state} />],
                ['Topology source', monitoringLabel(r.topology.source)],
                ['Topology capture', stamp(r.topology.captured_at)],
                ['Topology nodes', r.topology.node_count],
                ['Topology edges', r.topology.edge_count],
                ['Topology changes', r.topology.change_count],
                ['Discovery', <MonitoringState value={r.discovery.state} />],
                ['Discovery scopes', r.discovery.scopes],
                ['Discovery completed', stamp(r.discovery.completed_at)],
            ]}
            empty="No accessible Sites"
        />
    );
}
export function CoverageRecords({
    rows,
    view,
    owners,
}: {
    rows: MonitoringWorkspace['coverage']['by_site'];
    view: MonitoringView;
    owners: MonitoringOwners;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Monitoring coverage by site"
            description="Enabled monitoring coverage across the current permitted device scope."
            keyFor={(r) => r.site?.id ?? 'unassigned'}
            name={(r) => r.site?.name ?? 'No site assignment'}
            subline={(r) => `${r.devices} devices`}
            icon={Building2}
            state={(r) =>
                r.devices === 0
                    ? 'no_devices'
                    : r.missing_devices
                      ? 'coverage_incomplete'
                      : 'coverage_configured'
            }
            summary={(r) => (
                <>
                    {r.site ? (
                        <Owner
                            name={r.site.name}
                            href={siteHref(r.site, owners)}
                            allowed={owners.profiles}
                        />
                    ) : null}
                    <p>
                        {r.monitored_devices} monitored · {r.missing_devices}{' '}
                        missing
                    </p>
                </>
            )}
            columns={[
                {
                    key: 'devices',
                    label: 'Devices',
                    width: '95px',
                    cell: (r) => r.devices,
                },
                {
                    key: 'monitored',
                    label: 'Monitored',
                    width: '105px',
                    cell: (r) => r.monitored_devices,
                },
                {
                    key: 'missing',
                    label: 'Missing coverage',
                    width: '140px',
                    cell: (r) => r.missing_devices,
                },
            ]}
            actionsFor={(r) =>
                r.site
                    ? [
                          ownerAction(
                              'Open Site technology',
                              siteHref(r.site, owners),
                          ),
                      ]
                    : []
            }
            fields={(r) => [
                [
                    'Site',
                    r.site ? (
                        <Owner
                            name={r.site.name}
                            href={siteHref(r.site, owners)}
                            allowed={owners.profiles}
                        />
                    ) : (
                        'No Site assignment'
                    ),
                ],
                ['Site reference', r.site ? `#${r.site.id}` : 'Not assigned'],
                ['Devices', r.devices],
                ['Monitored devices', r.monitored_devices],
                ['Devices missing coverage', r.missing_devices],
            ]}
            empty="No Site coverage records"
        />
    );
}
export function DependencyRecords({
    rows,
    view,
    owners,
}: {
    rows: MonitoringWorkspace['dependencies']['records'];
    view: MonitoringView;
    owners: MonitoringOwners;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Dependency evidence"
            description="Upstream and downstream evidence does not grant device actions."
            keyFor={(r) => r.id}
            name={(r) =>
                `${r.upstream.name ?? 'Unknown upstream'} → ${r.downstream.name ?? 'Unknown downstream'}`
            }
            subline={(r) =>
                `Dependency #${r.id} · ${r.site?.name ?? 'No Site assigned'}`
            }
            icon={Network}
            state={(r) => r.review_state}
            summary={(r) => (
                <>
                    <p>{percent(r.confidence)} confidence</p>
                    <p>
                        {monitoringLabel(r.source)} evidence ·{' '}
                        {monitoringPhrase(r.policy)}
                    </p>
                </>
            )}
            columns={[
                {
                    key: 'review',
                    label: 'Review',
                    width: '130px',
                    cell: (r) => <MonitoringState value={r.review_state} />,
                },
                {
                    key: 'confidence',
                    label: 'Confidence',
                    width: '100px',
                    cell: (r) => percent(r.confidence),
                },
                {
                    key: 'source',
                    label: 'Source',
                    width: '130px',
                    cell: (r) => monitoringLabel(r.source),
                },
                {
                    key: 'policy',
                    label: 'Policy',
                    width: '220px',
                    cell: (r) => monitoringLabel(r.policy),
                },
            ]}
            actionsFor={(r) => [
                ...(r.upstream.id
                    ? [
                          ownerAction(
                              'Open upstream device',
                              deviceHref(
                                  Number(
                                      r.upstream.device_href?.split('/').pop(),
                                  ),
                                  r.upstream.device_href,
                                  owners,
                              ),
                          ),
                      ]
                    : []),
                ...(r.downstream.id
                    ? [
                          ownerAction(
                              'Open downstream device',
                              deviceHref(
                                  Number(
                                      r.downstream.device_href
                                          ?.split('/')
                                          .pop(),
                                  ),
                                  r.downstream.device_href,
                                  owners,
                              ),
                          ),
                      ]
                    : []),
            ]}
            fields={(r) => [
                ['Dependency reference', `#${r.id}`],
                ['Policy', monitoringLabel(r.policy)],
                ['Source', monitoringLabel(r.source)],
                ['Confidence', percent(r.confidence)],
                ['Review state', monitoringLabel(r.review_state)],
                [
                    'Site',
                    r.site ? (
                        <Owner
                            name={r.site.name}
                            href={siteHref(r.site, owners)}
                            allowed={owners.profiles}
                        />
                    ) : (
                        'No Site assigned'
                    ),
                ],
                ['Upstream check', r.upstream.name ?? 'Not recorded'],
                [
                    'Upstream monitor reference',
                    r.upstream.id ? `#${r.upstream.id}` : 'Not recorded',
                ],
                ['Upstream state', monitoringLabel(r.upstream.state)],
                ['Downstream check', r.downstream.name ?? 'Not recorded'],
                [
                    'Downstream monitor reference',
                    r.downstream.id ? `#${r.downstream.id}` : 'Not recorded',
                ],
                ['Downstream state', monitoringLabel(r.downstream.state)],
                [
                    'Suppression reason',
                    monitoringLabel(r.downstream.suppression_reason),
                ],
            ]}
            empty="No dependency records"
        />
    );
}
export function TrendRecords({
    rows,
    view,
    owners,
    onViewHistory,
}: {
    rows: MonitoringWorkspace['trends'];
    view: MonitoringView;
    owners: MonitoringOwners;
    onViewHistory?: (target: MonitorHistoryTarget) => void;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Retained trends"
            description="Safe summaries from retained native observations; raw probe messages and metrics stay private."
            keyFor={(r) => r.monitor_id}
            name={(r) => r.monitor_name}
            subline={(r) => `Monitor #${r.monitor_id} · ${r.device.name}`}
            icon={Gauge}
            state={(r) =>
                r.samples > 0 ? 'retained_samples' : 'no_retained_samples'
            }
            summary={(r) => (
                <>
                    <Owner
                        name={r.device.name}
                        href={deviceHref(r.device.id, r.device.href, owners)}
                        allowed={owners.profiles}
                    />
                    <p>
                        {r.latest_value ?? 'No numeric value'} {r.unit ?? ''}
                    </p>
                    <p>
                        {r.samples} samples · {monitoringPhrase(r.direction)} ·{' '}
                        {r.state_changes} state changes
                    </p>
                </>
            )}
            footer={(r) =>
                `Retained ${stamp(r.retained_from)} to ${stamp(r.retained_to)}`
            }
            columns={[
                {
                    key: 'latest',
                    label: 'Latest value',
                    width: '125px',
                    cell: (r) =>
                        `${r.latest_value ?? 'Not numeric'} ${r.unit ?? ''}`,
                },
                {
                    key: 'samples',
                    label: 'Samples',
                    width: '85px',
                    cell: (r) => r.samples,
                },
                {
                    key: 'direction',
                    label: 'Direction',
                    width: '120px',
                    cell: (r) => monitoringLabel(r.direction),
                },
                {
                    key: 'changes',
                    label: 'State changes',
                    width: '115px',
                    cell: (r) => r.state_changes,
                },
            ]}
            actionsFor={(r) => [
                ...(onViewHistory
                    ? [
                          {
                              label: 'View history',
                              icon: Activity,
                              onClick: () =>
                                  onViewHistory({
                                      monitorId: r.monitor_id,
                                      monitorName: r.monitor_name,
                                      deviceName: r.device.name,
                                  }),
                          },
                      ]
                    : []),
                ownerAction(
                    'Open device profile',
                    deviceHref(r.device.id, r.device.href, owners),
                ),
            ]}
            fields={(r) => [
                ['Monitor reference', `#${r.monitor_id}`],
                ['Monitor', r.monitor_name],
                [
                    'Device',
                    <Owner
                        name={`${r.device.name} (#${r.device.id})`}
                        href={deviceHref(r.device.id, r.device.href, owners)}
                        allowed={owners.profiles}
                    />,
                ],
                ['Samples', r.samples],
                ['Latest value', r.latest_value ?? 'No numeric value'],
                ['Previous value', r.previous_value ?? 'No numeric value'],
                ['Unit', r.unit ?? 'Not recorded'],
                ['Direction', monitoringLabel(r.direction)],
                ['State changes', r.state_changes],
                ['Retained from', stamp(r.retained_from)],
                ['Retained to', stamp(r.retained_to)],
            ]}
            empty="No retained trend samples"
        />
    );
}
export function CapacityRecords({
    rows,
    view,
    owners,
    total,
}: {
    rows: MonitoringWorkspace['storage']['time_series']['capacity_evidence'];
    view: MonitoringView;
    owners: MonitoringOwners;
    total?: number;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="External history and retention"
            description="Retained series evidence and measured capacity trends."
            total={total}
            keyFor={(r) => r.series_id}
            name={(r) => `${r.device.name} · ${monitoringLabel(r.metric)}`}
            subline={(r) =>
                `Series #${r.series_id} · ${monitoringLabel(r.tier)}`
            }
            icon={Gauge}
            state={(r) => r.storage_state}
            summary={(r) => (
                <>
                    <Owner
                        name={r.device.name}
                        href={deviceHref(r.device.id, r.device.href, owners)}
                        allowed={owners.profiles}
                    />
                    <p>
                        {monitoringLabel(r.metric)} · latest{' '}
                        {r.value ?? 'not available'} {r.unit} · p95{' '}
                        {r.p95 ?? 'insufficient data'} · {r.sample_count}{' '}
                        samples
                    </p>
                    <p>
                        Trend {monitoringPhrase(r.projection.state)}
                        {r.projection.threshold_at
                            ? ` · threshold ${stamp(r.projection.threshold_at)}`
                            : ''}
                        {r.projection.sample_count > 0
                            ? ` · ${r.projection.sample_count} history points · ${percent(r.projection.confidence)} confidence`
                            : ''}
                    </p>
                    {r.projection.measured_from && r.projection.measured_to ? (
                        <p>
                            Measured history:{' '}
                            {stamp(r.projection.measured_from)} to{' '}
                            {stamp(r.projection.measured_to)}
                        </p>
                    ) : null}
                </>
            )}
            columns={[
                {
                    key: 'state',
                    label: 'Storage',
                    width: '125px',
                    cell: (r) => <MonitoringState value={r.storage_state} />,
                },
                {
                    key: 'latest',
                    label: 'Latest/p95',
                    width: '155px',
                    cell: (r) =>
                        `${r.value ?? 'Not numeric'} / ${r.p95 ?? 'Insufficient data'}`,
                },
                {
                    key: 'projection',
                    label: 'Trend',
                    width: '195px',
                    cell: (r) => <MonitoringState value={r.projection.state} />,
                },
                {
                    key: 'samples',
                    label: 'Samples',
                    width: '90px',
                    cell: (r) => r.sample_count,
                },
            ]}
            actionsFor={(r) => [
                ownerAction(
                    'Open device profile',
                    deviceHref(r.device.id, r.device.href, owners),
                ),
            ]}
            fields={(r) => [
                ['Series reference', `#${r.series_id}`],
                ['Metric', monitoringLabel(r.metric)],
                ['Unit', r.unit],
                ['Tier', monitoringLabel(r.tier)],
                [
                    'Device',
                    <Owner
                        name={`${r.device.name} (#${r.device.id})`}
                        href={deviceHref(r.device.id, r.device.href, owners)}
                        allowed={owners.profiles}
                    />,
                ],
                ['Latest value', r.value ?? 'Not available'],
                ['95th percentile', r.p95 ?? 'Insufficient data'],
                ['Samples', r.sample_count],
                ['Observed at', stamp(r.observed_at)],
                ['Storage', <MonitoringState value={r.storage_state} />],
                ['Trend', <MonitoringState value={r.projection.state} />],
                ['Threshold', r.projection.threshold ?? 'Not configured'],
                ['Measured from', stamp(r.projection.measured_from)],
                ['Measured to', stamp(r.projection.measured_to)],
                ['History points', r.projection.sample_count],
                ['Trend p95', r.projection.p95 ?? 'Insufficient data'],
                [
                    'Slope per day',
                    r.projection.slope_per_day ?? 'Insufficient data',
                ],
                ['Confidence', percent(r.projection.confidence)],
                ['Projected threshold time', stamp(r.projection.threshold_at)],
            ]}
            empty="No retained capacity series"
        />
    );
}
export function RetentionRecords({
    rows,
    view,
}: {
    rows: MonitoringWorkspace['storage']['retention']['policies'];
    view: MonitoringView;
}) {
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Retention policies"
            description="Current projected retention scope; legal hold remains explicit."
            keyFor={(r) => r.id}
            name={(r) => r.name}
            subline={(r) =>
                `${monitoringLabel(r.scope)} · ${monitoringPhrase(r.data_class)}`
            }
            icon={Clock3}
            state={(r) => (r.legal_hold ? 'legal_hold' : 'retention_policy')}
            summary={(r) => (
                <p>
                    {r.name}: raw {r.raw_days}d · hourly {r.hourly_days}d ·
                    daily {r.daily_days}d{r.legal_hold ? ' · legal hold' : ''}
                </p>
            )}
            columns={[
                {
                    key: 'raw',
                    label: 'Raw days',
                    width: '100px',
                    cell: (r) => r.raw_days,
                },
                {
                    key: 'hourly',
                    label: 'Hourly days',
                    width: '110px',
                    cell: (r) => r.hourly_days,
                },
                {
                    key: 'daily',
                    label: 'Daily days',
                    width: '105px',
                    cell: (r) => r.daily_days,
                },
                {
                    key: 'hold',
                    label: 'Legal hold',
                    width: '110px',
                    cell: (r) => yes(r.legal_hold),
                },
            ]}
            fields={(r) => [
                ['Policy reference', `#${r.id}`],
                ['Name', r.name],
                ['Scope', monitoringLabel(r.scope)],
                ['Data class', monitoringLabel(r.data_class)],
                ['Privacy class', monitoringLabel(r.privacy_class)],
                ['Raw days', r.raw_days],
                ['Hourly days', r.hourly_days],
                ['Daily days', r.daily_days],
                ['Legal hold', yes(r.legal_hold)],
            ]}
            empty="No retention policies projected"
        />
    );
}
export function QueueRecords({
    queues,
    view,
}: {
    queues: MonitoringWorkspace['runtime']['queues'];
    view: MonitoringView;
}) {
    const rows = Object.entries(queues).map(([name, value]) => ({
        name,
        ...value,
    }));
    return (
        <RecordList
            rows={rows}
            view={view}
            title="Runtime queues"
            description="A dispatched heartbeat alone does not prove a worker consumed it."
            keyFor={(r) => r.name}
            name={(r) => monitoringLabel(r.name)}
            subline={(r) => `Queue ${monitoringPhrase(r.name)}`}
            icon={Activity}
            state={(r) => r.state}
            summary={(r) => (
                <>
                    <p>
                        Worker {monitoringPhrase(r.worker_state)}
                        {r.heartbeat_age_seconds === null
                            ? ' · no heartbeat consumed'
                            : ` · heartbeat ${r.heartbeat_age_seconds}s ago`}
                    </p>
                    <p>
                        {r.pending === null
                            ? 'Counts restricted'
                            : `${r.pending} pending · ${r.dead_letters === null ? 'dead-letter count unavailable' : `${r.dead_letters} dead letters`}`}
                    </p>
                </>
            )}
            columns={[
                {
                    key: 'worker',
                    label: 'Worker',
                    width: '155px',
                    cell: (r) => <MonitoringState value={r.worker_state} />,
                },
                {
                    key: 'pending',
                    label: 'Pending',
                    width: '130px',
                    cell: (r) => r.pending ?? 'Counts restricted',
                },
                {
                    key: 'dead',
                    label: 'Dead letters',
                    width: '140px',
                    cell: (r) => r.dead_letters ?? 'Count unavailable',
                },
                {
                    key: 'heartbeat',
                    label: 'Heartbeat age',
                    width: '130px',
                    cell: (r) => age(r.heartbeat_age_seconds),
                },
            ]}
            fields={(r) => [
                ['Queue', monitoringLabel(r.name)],
                ['State', <MonitoringState value={r.state} />],
                ['Pending', r.pending ?? 'Counts restricted'],
                ['Oldest pending age', age(r.oldest_age_seconds)],
                ['Dead letters', r.dead_letters ?? 'Count unavailable'],
                ['Worker', <MonitoringState value={r.worker_state} />],
                ['Heartbeat age', age(r.heartbeat_age_seconds)],
                ['Dispatch lag', age(r.dispatch_lag_seconds)],
            ]}
            empty="No runtime queues projected"
        />
    );
}

export function RuntimeRecords({
    runtime,
    view,
}: {
    runtime: MonitoringWorkspace['runtime'];
    view: MonitoringView;
}) {
    return (
        <RecordList
            rows={[runtime]}
            view={view}
            title="Runtime health"
            description="Retained worker, watchdog, storage and collector evidence. Open details for the full snapshot."
            keyFor={() => 'runtime'}
            name={() => 'Runtime health'}
            subline={(r) => 'Snapshot ' + formatDateTime(r.observed_at)}
            icon={Activity}
            state={(r) => r.state}
            summary={(r) => (
                <>
                    <p>{r.workers.note}</p>
                    <p>
                        {r.workers.available}/{r.workers.total} workers
                        available · {r.workers.attention} need attention
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <span>Independent outage watchdog</span>
                        <MonitoringState value={r.external_heartbeat.state} />
                    </div>
                    <p>{r.external_heartbeat.note}</p>
                    <p>
                        {r.collectors.available}/{r.collectors.total} collectors
                        available · {r.collectors.gaps} collection gaps
                    </p>
                </>
            )}
            footer={(r) => 'Observed ' + stamp(r.observed_at)}
            columns={[
                {
                    key: 'workers',
                    label: 'Workers',
                    width: '130px',
                    cell: (r) => r.workers.available + '/' + r.workers.total,
                },
                {
                    key: 'watchdog',
                    label: 'Watchdog',
                    width: '135px',
                    cell: (r) => (
                        <MonitoringState value={r.external_heartbeat.state} />
                    ),
                },
                {
                    key: 'collectors',
                    label: 'Collectors',
                    width: '130px',
                    cell: (r) =>
                        r.collectors.available + '/' + r.collectors.total,
                },
                {
                    key: 'observed',
                    label: 'Snapshot time',
                    width: '180px',
                    cell: (r) => stamp(r.observed_at),
                },
            ]}
            fields={(r) => [
                ['Runtime', <MonitoringState value={r.state} />],
                ['Observed at', formatDateTime(r.observed_at)],
                ['Workers', <MonitoringState value={r.workers.state} />],
                ['Available workers', r.workers.available],
                ['Worker total', r.workers.total],
                ['Workers needing attention', r.workers.attention],
                ['Workers not observed', r.workers.not_observed],
                [
                    'Independent outage watchdog',
                    <MonitoringState value={r.external_heartbeat.state} />,
                ],
                ['Watchdog evidence', r.external_heartbeat.note],
                [
                    'Watchdog reason',
                    monitoringLabel(r.external_heartbeat.reason_code),
                ],
                [
                    'Last delivered age',
                    r.external_heartbeat.last_sent_age_seconds === null
                        ? 'Not observed'
                        : `${r.external_heartbeat.last_sent_age_seconds}s`,
                ],
                [
                    'Last evaluated age',
                    r.external_heartbeat.last_evaluated_age_seconds === null
                        ? 'Not observed'
                        : `${r.external_heartbeat.last_evaluated_age_seconds}s`,
                ],
                [
                    'Time-series storage',
                    <MonitoringState value={r.storage.time_series.state} />,
                ],
                ['Stored series', r.storage.time_series.series],
                [
                    'Snapshot storage',
                    <MonitoringState value={r.storage.snapshots.state} />,
                ],
                [
                    'Snapshots available/retained',
                    `${r.storage.snapshots.available}/${r.storage.snapshots.records}`,
                ],
                [
                    'Collectors available/total',
                    `${r.collectors.available}/${r.collectors.total}`,
                ],
                ['Collectors degraded', r.collectors.degraded],
                ['Collectors unavailable', r.collectors.unavailable],
                ['Collectors revoked', r.collectors.revoked],
                ['Buffered items', r.collectors.backlog_items],
                ['Collection gaps', r.collectors.gaps],
                ...Object.entries(r.listeners).flatMap(
                    ([name, listener]): Array<[string, React.ReactNode]> => [
                        [
                            `Listener: ${monitoringLabel(name)}`,
                            <MonitoringState value={listener.state} />,
                        ],
                        [
                            `Listener heartbeat: ${monitoringLabel(name)}`,
                            listener.heartbeat_age_seconds === null
                                ? 'Not observed'
                                : `${listener.heartbeat_age_seconds}s`,
                        ],
                    ],
                ),
            ]}
            empty="No runtime snapshot projected"
        />
    );
}
