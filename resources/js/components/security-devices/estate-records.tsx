import { EntityChip } from '@/components/lists';
import type { EntityCardMetric } from '@/components/lists/entity-card';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import {
    deviceCategoryLabel,
    domainLabel,
} from '@/pages/security-devices/devices/shared';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Building2,
    ClipboardList,
    Cpu,
    History,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { EstateProps } from './estate-types';
import { maintenanceLabel } from './maintenance-records';
import { securityDevicesDomainHref } from './security-devices-navigation';
export type EstateView =
    | 'overview'
    | 'sites'
    | 'devices'
    | 'findings'
    | 'work'
    | 'changes';
export type EstatePermissions = {
    devices: boolean;
    events: boolean;
    maintenance: boolean;
    it: boolean;
    controlRoom: boolean;
};
type Fact = [string, ReactNode];
export type EstateRecord = {
    key: string;
    name: string;
    subline: string;
    icon: LucideIcon;
    tone: 'neutral' | 'warning' | 'critical';
    chips?: ReactNode;
    metric?: EntityCardMetric;
    footer?: ReactNode;
    destination: string | null;
    openLabel: string;
    reason: string;
    facts: Fact[];
    sections?: Array<{ name: string; facts: Fact[] }>;
};
const count = (value: number | null | undefined) =>
    value === undefined
        ? 'Not recorded'
        : value === null
          ? 'Restricted'
          : value.toLocaleString('en-NZ');
const recorded = (value: string | null | undefined) => value || 'Not recorded';
const tone = (state: string): StatusVariant =>
    ['critical', 'failed'].includes(state)
        ? 'critical'
        : ['warning', 'degraded', 'offline', 'stale'].includes(state)
          ? 'warning'
          : 'neutral';
const badge = (state: string) => (
    <StatusBadge variant={tone(state)}>{maintenanceLabel(state)}</StatusBadge>
);
const device = (id: number, name: string | null, enabled: boolean) =>
    enabled ? (
        <Link
            href={`/security-devices/devices/${id}`}
            className="text-primary hover:underline"
        >
            {name || `Device #${id}`}
        </Link>
    ) : (
        recorded(name)
    );
const valueWhen = (value: number | null | undefined, permitted: boolean) =>
    permitted ? count(value) : 'Restricted';
export function estateRecords(
    data: EstateProps,
    permission: EstatePermissions,
    view: EstateView,
): EstateRecord[] {
    const timestamp = formatDateTimeLong(data.as_of, 'Time not available');
    if (view === 'overview') {
        const deviceFacts: Fact[] = [
            [
                'All devices',
                valueWhen(data.stats.totalDevices, permission.devices),
            ],
            ['Active', valueWhen(data.stats.active, permission.devices)],
            ['Offline', valueWhen(data.stats.offline, permission.devices)],
            ['Degraded', valueWhen(data.stats.degraded, permission.devices)],
            [
                'Low battery',
                valueWhen(data.stats.lowBattery, permission.devices),
            ],
            [
                'Service overdue',
                valueWhen(data.stats.serviceDueOverdue, permission.devices),
            ],
            [
                'Service due within 30 days',
                valueWhen(data.stats.serviceDueIn30d, permission.devices),
            ],
            ['Device groups', valueWhen(data.groupCount, permission.devices)],
            ['Read time', timestamp],
        ];
        const monitoring: Fact[] = [
            [
                'Operational devices',
                valueWhen(
                    data.operations.coverage.total_devices,
                    permission.devices,
                ),
            ],
            [
                'With enabled monitoring',
                valueWhen(
                    data.operations.coverage.monitored_devices,
                    permission.events,
                ),
            ],
            [
                'Without monitoring',
                valueWhen(
                    data.operations.coverage.unmonitored_devices,
                    permission.events,
                ),
            ],
            [
                'Coverage',
                permission.events
                    ? data.operations.coverage.percent === null
                        ? 'Not measured'
                        : `${data.operations.coverage.percent}%`
                    : 'Restricted',
            ],
            [
                'Failed or degraded checks',
                valueWhen(
                    data.operations.summary.failed_monitors,
                    permission.events,
                ),
            ],
            [
                'Critical findings in 24 hours',
                valueWhen(data.stats.criticalEvents24h, permission.events),
            ],
            [
                'Warning findings in 24 hours',
                valueWhen(data.stats.warningEvents24h, permission.events),
            ],
            ['Read time', timestamp],
        ];
        const work: Fact[] = [
            [
                'Affected Sites',
                valueWhen(
                    data.operations.summary.affected_sites,
                    permission.devices,
                ),
            ],
            [
                'Active findings',
                valueWhen(
                    data.operations.summary.active_findings,
                    permission.events,
                ),
            ],
            [
                'Open IT work',
                valueWhen(data.operations.summary.open_it_work, permission.it),
            ],
            [
                'Overdue maintenance',
                valueWhen(
                    data.stats.overdueMaintenance,
                    permission.maintenance,
                ),
            ],
            [
                'Overdue work in operational summary',
                valueWhen(
                    data.operations.summary.overdue_maintenance,
                    permission.maintenance,
                ),
            ],
            ['Read time', timestamp],
        ];
        const summary: EstateRecord = {
            key: 'estate-summary',
            name: 'Estate summary',
            subline: `Read started ${timestamp}`,
            icon: Activity,
            tone: 'neutral',
            destination: null,
            openLabel: '',
            reason: '',
            chips: (
                <>
                    <EntityChip>
                        {permission.devices
                            ? `${count(data.stats.totalDevices)} devices`
                            : 'Device counts restricted'}
                    </EntityChip>
                    <EntityChip>
                        {permission.it
                            ? `${count(data.operations.summary.open_it_work)} open IT items`
                            : 'IT counts restricted'}
                    </EntityChip>
                </>
            ),
            metric: {
                label: 'With enabled monitoring',
                value: permission.events
                    ? data.operations.coverage.percent === null
                        ? 'Not measured'
                        : `${count(data.operations.coverage.monitored_devices)} of ${count(data.operations.coverage.total_devices)} · ${data.operations.coverage.percent}%`
                    : 'Monitoring access required',
                percent: permission.events
                    ? data.operations.coverage.percent
                    : null,
            },
            footer: 'Configured monitoring; device health is a separate reading.',
            facts: [],
            sections: [
                { name: 'Device totals', facts: deviceFacts },
                { name: 'Monitoring', facts: monitoring },
                { name: 'Sites and work', facts: work },
            ],
        };
        return [summary];
    }
    if (view === 'sites')
        return permission.devices
            ? data.operations.site_impact.map<EstateRecord>((site) => ({
                  key: `site-${site.id}`,
                  name: site.name,
                  subline: site.city || 'Location not recorded',
                  icon: Building2,
                  tone: site.health === 'critical' ? 'critical' : 'warning',
                  chips: (
                      <>
                          {badge(site.health)}
                          <EntityChip>{site.devices} devices</EntityChip>
                      </>
                  ),
                  metric: {
                      label: 'With enabled monitoring',
                      value: permission.events
                          ? site.monitored_devices === null
                              ? 'Restricted'
                              : site.coverage_percent === null
                                ? 'Not measured'
                                : `${count(site.monitored_devices)} of ${count(site.devices)} · ${site.coverage_percent}%`
                          : 'Monitoring access required',
                      percent:
                          permission.events && site.monitored_devices !== null
                              ? site.coverage_percent
                              : null,
                  },
                  footer: `Changed ${formatDateTimeLong(site.last_change_at, 'time not recorded')}`,
                  destination: site.href,
                  openLabel: 'Open Site technology',
                  reason: 'Device access is required to open this Site.',
                  facts: [
                      ['Site', site.name],
                      ['Site ID', site.id],
                      ['Location', recorded(site.city)],
                      [
                          'Type',
                          site.type
                              ? maintenanceLabel(site.type)
                              : 'Not recorded',
                      ],
                      [
                          'Active',
                          site.is_active === undefined
                              ? 'Not recorded'
                              : site.is_active
                                ? 'Yes'
                                : 'No',
                      ],
                      ['Recorded health', maintenanceLabel(site.health)],
                      ['Devices', count(site.devices)],
                      [
                          'Requires action',
                          site.requires_action === undefined
                              ? 'Not recorded'
                              : site.requires_action
                                ? 'Yes'
                                : 'No',
                      ],
                      ['Needing attention', count(site.attention_devices)],
                      ['Offline', count(site.offline_devices)],
                      [
                          'With monitoring',
                          valueWhen(site.monitored_devices, permission.events),
                      ],
                      [
                          'Without monitoring',
                          valueWhen(
                              site.unmonitored_devices,
                              permission.events,
                          ),
                      ],
                      [
                          'Coverage',
                          permission.events
                              ? site.coverage_percent === null
                                  ? 'Not measured'
                                  : `${site.coverage_percent}%`
                              : 'Restricted',
                      ],
                      [
                          'Failed checks',
                          valueWhen(site.failed_monitors, permission.events),
                      ],
                      [
                          'Uncertain checks',
                          valueWhen(site.uncertain_monitors, permission.events),
                      ],
                      [
                          'Findings',
                          valueWhen(site.active_findings, permission.events),
                      ],
                      [
                          'Control Room alerts',
                          valueWhen(
                              site.active_control_room_alerts,
                              permission.controlRoom,
                          ),
                      ],
                      [
                          'Open IT work',
                          valueWhen(site.open_it_work, permission.it),
                      ],
                      [
                          'Overdue work',
                          valueWhen(
                              site.overdue_maintenance,
                              permission.maintenance,
                          ),
                      ],
                      [
                          'Collector',
                          permission.events
                              ? site.collector?.label || 'Not recorded'
                              : 'Restricted',
                      ],
                      [
                          'Collector state',
                          permission.events && site.collector
                              ? maintenanceLabel(site.collector.state)
                              : 'Restricted',
                      ],
                      [
                          'Collectors',
                          valueWhen(site.collector?.count, permission.events),
                      ],
                      [
                          'Collector last contact',
                          permission.events
                              ? formatDateTimeLong(
                                    site.collector?.last_seen_at,
                                    'Not recorded',
                                )
                              : 'Restricted',
                      ],
                      [
                          'Last change',
                          formatDateTimeLong(
                              site.last_change_at,
                              'Not recorded',
                          ),
                      ],
                  ],
              }))
            : [];
    if (view === 'findings')
        return permission.events
            ? data.recentEvents.map<EstateRecord>((event) => ({
                  key: `event-${event.id}`,
                  name: maintenanceLabel(event.event_type),
                  subline: formatDateTimeLong(
                      event.occurred_at,
                      'Time not recorded',
                  ),
                  icon: Bell,
                  tone: event.severity === 'critical' ? 'critical' : 'warning',
                  chips: badge(event.severity),
                  footer: event.device_name || `Device #${event.device_id}`,
                  destination: permission.devices
                      ? `/security-devices/devices/${event.device_id}`
                      : null,
                  openLabel: 'Open device',
                  reason: 'Device access is required.',
                  facts: [
                      ['Finding', maintenanceLabel(event.event_type)],
                      ['Event ID', event.id],
                      [
                          'Device',
                          device(
                              event.device_id,
                              event.device_name,
                              permission.devices,
                          ),
                      ],
                      ['Device record ID', event.device_id],
                      ['Severity', maintenanceLabel(event.severity)],
                      ['Source', recorded(event.source)],
                      [
                          'Occurred',
                          formatDateTimeLong(event.occurred_at, 'Not recorded'),
                      ],
                  ],
              }))
            : [];
    if (view === 'work')
        return permission.maintenance
            ? data.overdueMaintenance.map<EstateRecord>((row) => ({
                  key: `work-${row.id}`,
                  name: row.description,
                  subline: `Scheduled ${formatDateOnly(row.scheduled_for?.slice(0, 10), 'date not recorded')}`,
                  icon: Wrench,
                  tone: 'warning',
                  chips: (
                      <>
                          <StatusBadge variant="warning">Overdue</StatusBadge>
                          <EntityChip>{maintenanceLabel(row.type)}</EntityChip>
                      </>
                  ),
                  footer: row.device_name || `Device #${row.device_id}`,
                  destination: permission.devices
                      ? `/security-devices/devices/${row.device_id}`
                      : null,
                  openLabel: 'Open device',
                  reason: 'Device access is required.',
                  facts: [
                      ['Work', row.description],
                      ['Work ID', row.id],
                      ['Work type', maintenanceLabel(row.type)],
                      [
                          'Device',
                          device(
                              row.device_id,
                              row.device_name,
                              permission.devices,
                          ),
                      ],
                      ['Device record ID', row.device_id],
                      [
                          'Scheduled',
                          formatDateOnly(
                              row.scheduled_for?.slice(0, 10),
                              'Not recorded',
                          ),
                      ],
                      ['Work reference', recorded(row.vendor_reference)],
                  ],
              }))
            : [];
    if (view === 'changes')
        return permission.devices
            ? data.operations.recent_changes
                  .filter(
                      (change) =>
                          change.kind !== 'device_event' || permission.events,
                  )
                  .map<EstateRecord>((change) => ({
                      key: change.key,
                      name:
                          change.kind === 'device_event'
                              ? change.summary.charAt(0).toUpperCase() +
                                change.summary.slice(1).toLowerCase()
                              : change.summary,
                      subline: formatDateTimeLong(
                          change.at,
                          'Time not recorded',
                      ),
                      icon: History,
                      tone: 'neutral',
                      footer: recorded(change.device_name),
                      destination: change.href,
                      openLabel: 'Open device',
                      reason: 'Device access is required.',
                      facts: [
                          ['Change', change.summary],
                          ['Change type', maintenanceLabel(change.kind)],
                          ['Change reference', change.key],
                          ['Device', recorded(change.device_name)],
                          [
                              'Device record ID',
                              change.device_id ?? 'Not recorded',
                          ],
                          [
                              'Recorded',
                              formatDateTimeLong(change.at, 'Not recorded'),
                          ],
                      ],
                  }))
            : [];
    return permission.devices
        ? data.attentionDevices.map<EstateRecord>((row) => ({
              key: `device-${row.id}`,
              name: row.name,
              subline: row.device_uid,
              icon: Cpu,
              tone: row.health_status === 'critical' ? 'critical' : 'warning',
              chips: (
                  <>
                      {badge(row.health_status)}
                      {badge(row.status)}
                  </>
              ),
              footer: `Last seen ${formatDateTimeLong(row.last_seen_at, 'not recorded')}`,
              destination: `/security-devices/devices/${row.id}`,
              openLabel: 'Open device',
              reason: 'Device access is required.',
              facts: [
                  ['Device', device(row.id, row.name, permission.devices)],
                  ['Device record ID', row.id],
                  ['Device ID', row.device_uid],
                  ['Domain', domainLabel(row.domain)],
                  ['Category', deviceCategoryLabel(row.category)],
                  ['Recorded status', maintenanceLabel(row.status)],
                  ['Recorded health', maintenanceLabel(row.health_status)],
                  [
                      'Battery',
                      row.battery_level === null
                          ? 'Not recorded'
                          : `${row.battery_level}%`,
                  ],
                  [
                      'Last seen',
                      formatDateTimeLong(row.last_seen_at, 'Not recorded'),
                  ],
              ],
          }))
        : [];
}
export function estateQueues(
    data: EstateProps,
    permission: EstatePermissions,
): EstateRecord[] {
    return data.operations.action_queue.map<EstateRecord>((row) => {
        const allowed =
            row.key === 'open_it_work'
                ? permission.it
                : row.key === 'overdue_maintenance'
                  ? permission.maintenance
                  : row.key === 'failed_monitors'
                    ? permission.events
                    : permission.devices;
        const shown = allowed ? row.count : null;
        const reason =
            row.restriction_reason ||
            (allowed
                ? 'This queue is not available.'
                : `${row.label} access is required. Ask an administrator.`);
        return {
            key: row.key,
            name: row.label,
            subline:
                shown === null
                    ? 'Restricted'
                    : `${count(shown)} ${shown === 1 ? 'item' : 'items'}`,
            icon: ClipboardList,
            tone: shown !== null && shown > 0 ? 'warning' : 'neutral',
            chips: (
                <StatusBadge
                    variant={
                        shown !== null && shown > 0 ? 'warning' : 'neutral'
                    }
                >
                    {shown === null
                        ? 'Restricted'
                        : shown === 0
                          ? 'No items'
                          : 'Items to review'}
                </StatusBadge>
            ),
            footer:
                allowed && row.href ? 'Open the existing work queue' : reason,
            destination: allowed ? row.href : null,
            openLabel: 'Open queue',
            reason,
            facts: [
                ['Queue', row.label],
                ['Queue reference', row.key],
                ['Items', count(shown)],
                ['Access', allowed && row.href ? 'Available' : reason],
            ],
        };
    });
}
export function EstateRecords({
    rows,
    view,
    title,
    description,
    empty,
}: {
    rows: EstateRecord[];
    view: EvidenceRecordsView;
    title: string;
    description: string;
    empty: string;
}) {
    const sectionCount = Math.max(
        1,
        ...rows.map((row) => row.sections?.length ?? 1),
    );
    const records = new Map(rows.map((row) => [row.key, row]));
    const record = (row: { key: string }) => records.get(row.key)!;
    return (
        <EvidenceRecords<{ key: string; name: string; subline: string }>
            title={title}
            description={description}
            rows={rows.map(({ key, name, subline }) => ({
                key,
                name,
                subline,
            }))}
            view={view}
            rowKey={(row) => row.key}
            icon={Cpu}
            identity={(row) => ({
                name: row.name,
                subline: row.subline,
                icon: record(row).icon,
            })}
            articleLabel={(row) => `Estate record: ${row.name}`}
            actionsFor={(row) =>
                record(row).openLabel
                    ? [
                          {
                              label: record(row).openLabel,
                              icon: Activity,
                              disabled: record(row).destination
                                  ? undefined
                                  : record(row).reason,
                              onClick: () => {
                                  const destination = record(row).destination;
                                  if (destination) router.visit(destination);
                              },
                          },
                      ]
                    : []
            }
            cardFor={(row) => ({
                name: row.name,
                subline: row.subline,
                icon: record(row).icon,
                meridian: record(row).tone,
                chips: record(row).chips,
                metric: record(row).metric,
                footer: { primary: record(row).footer },
            })}
            compactColumns={[
                {
                    key: 'detail',
                    label: 'Recorded detail',
                    width: '300px',
                    cell: (row) => record(row).footer,
                },
                {
                    key: 'state',
                    label: 'State',
                    width: '200px',
                    cell: (row) => record(row).chips,
                },
            ]}
            columns={Array.from({ length: sectionCount }, (_, index) => ({
                key: `section-${index}`,
                label: rows[0]?.sections?.[index]?.name || 'Record details',
                width: '100%',
                cell: (row: { key: string }) =>
                    (
                        record(row).sections?.[index]?.facts ||
                        record(row).facts
                    ).map(([label, value]) => (
                        <ReviewRow key={label} label={label} value={value} />
                    )),
            }))}
            empty={empty}
        />
    );
}

export function estateDistributions(
    data: EstateProps,
    permission: EstatePermissions,
): EstateRecord[] {
    if (!permission.devices) return [];
    return [
        ...data.domainSummary.map<EstateRecord>((row) => ({
            key: 'domain-' + row.domain,
            name: domainLabel(row.domain),
            subline:
                count(row.count) + (row.count === 1 ? ' device' : ' devices'),
            icon: Cpu,
            tone: 'neutral' as const,
            footer: 'Device workspace',
            destination: securityDevicesDomainHref(row.domain),
            openLabel: 'Open workspace',
            reason: 'Device access required.',
            facts: [
                ['Domain', domainLabel(row.domain)],
                ['Devices', count(row.count)],
            ] as Fact[],
        })),
        ...data.healthSummary.map<EstateRecord>((row) => ({
            key: 'health-' + row.status,
            name: 'Recorded health: ' + maintenanceLabel(row.status),
            subline:
                count(row.count) + (row.count === 1 ? ' device' : ' devices'),
            icon: Activity,
            tone: 'neutral' as const,
            footer: 'Recorded classification; check the device reading time.',
            destination: null,
            openLabel: '',
            reason: '',
            facts: [
                ['Recorded health', maintenanceLabel(row.status)],
                ['Name', row.label],
                ['Devices', count(row.count)],
            ] as Fact[],
        })),
    ];
}
