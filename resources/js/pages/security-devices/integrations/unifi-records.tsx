import type { EntityTableColumn, MenuItem } from '@/components/lists';
import { EntityChip, EntityStatusChip } from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { Building2, Clock, Cpu, KeyRound, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { UnifiProps } from './unifi';

export type UnifiListRow =
    | { kind: 'mapping'; record: UnifiProps['siteConfigs'][number] }
    | { kind: 'device'; record: UnifiProps['syncedDevices'][number] }
    | { kind: 'history'; record: UnifiProps['syncLogs'][number] }
    | { kind: 'credential'; record: UnifiProps['siteCredentials'][number] };
const human = (value?: string | null) =>
    value
        ? value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())
        : 'Not recorded';
const text = (value?: string | number | null) =>
    value === null || value === undefined || value === ''
        ? 'Not recorded'
        : String(value);
const time = (value?: string | null) =>
    !value
        ? 'Not recorded'
        : /(Z|[+-]\d{2}:\d{2})$/.test(value)
          ? formatDateTimeLong(value, 'Time unavailable')
          : 'Time zone not recorded';
export const unifiRowKey = (row: UnifiListRow) =>
    row.kind + ':' + row.record.id;
export const unifiRowName = (row: UnifiListRow) =>
    row.kind === 'device'
        ? row.record.name
        : row.kind === 'history'
          ? human(row.record.action) + ' · Run ' + row.record.id
          : row.record.site_name;
const iconFor = (row: UnifiListRow): LucideIcon =>
    row.kind === 'device'
        ? Cpu
        : row.kind === 'history'
          ? Clock
          : row.kind === 'credential'
            ? KeyRound
            : Building2;
export const unifiRowState = (row: UnifiListRow) =>
    row.kind === 'mapping'
        ? row.record.is_active
            ? 'Active mapping'
            : 'Inactive mapping'
        : row.kind === 'credential'
          ? row.record.state === 'untested'
              ? 'Not tested'
              : row.record.state === 'error'
                ? 'Needs attention'
                : human(row.record.state)
          : human(row.record.status);
const stateChip = (row: UnifiListRow) => (
    <EntityStatusChip
        variant={
            row.kind === 'history' && row.record.status === 'failed'
                ? 'critical'
                : row.kind === 'history' && row.record.status === 'partial'
                  ? 'warning'
                  : row.kind === 'credential' && row.record.state === 'error'
                    ? 'warning'
                    : 'neutral'
        }
    >
        {unifiRowState(row)}
    </EntityStatusChip>
);
const placement = (row: UnifiListRow) =>
    row.kind === 'device'
        ? row.record.site_name +
          ' · ' +
          (row.record.room_id
              ? row.record.room_name || 'Room name not recorded'
              : 'No room assigned')
        : row.kind === 'mapping'
          ? row.record.mapped_external_site_name || 'Provider name not recorded'
          : row.kind === 'credential'
            ? human(row.record.capability)
            : row.record.items_processed + ' processed';
const observed = (row: UnifiListRow) =>
    row.kind === 'device'
        ? time(row.record.last_seen_at)
        : row.kind === 'history'
          ? time(row.record.started_at)
          : row.kind === 'credential'
            ? time(row.record.last_tested_at)
            : 'Mapping record';
/**
 * The text a person can see for a record (its name, placement, status, time
 * and descriptive facts), lower-cased for search. Links, IDs used only as
 * references and opaque mapping tokens are deliberately left out.
 */
export const unifiRowSearchText = (row: UnifiListRow) => {
    const facts =
        row.kind === 'device'
            ? [
                  human(row.record.category),
                  human(row.record.domain),
                  row.record.subcategory ? human(row.record.subcategory) : '',
                  row.record.manufacturer,
                  row.record.model,
                  row.record.firmware_version,
              ]
            : row.kind === 'mapping'
              ? [row.record.site_name, human(row.record.status)]
              : row.kind === 'credential'
                ? [row.record.site_name, human(row.record.capability)]
                : [
                      human(row.record.action),
                      human(row.record.failure_category),
                  ];
    return [
        unifiRowName(row),
        placement(row),
        unifiRowState(row),
        observed(row),
        ...facts,
    ]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase();
};
function Rows({ facts }: { facts: Array<[string, ReactNode]> }) {
    return (
        <>
            {facts.map(([label, value]) => (
                <ReviewRow key={label} label={label} value={value} />
            ))}
        </>
    );
}
function identityFacts(row: UnifiListRow) {
    if (row.kind === 'device')
        return (
            <Rows
                facts={[
                    ['Device', row.record.name],
                    ['Device ID', row.record.id],
                    ['Type', human(row.record.category)],
                    ['Domain', human(row.record.domain)],
                    ['Subtype', human(row.record.subcategory)],
                    ['Manufacturer', text(row.record.manufacturer)],
                    ['Model', text(row.record.model)],
                    ['Firmware', text(row.record.firmware_version)],
                ]}
            />
        );
    if (row.kind === 'mapping')
        return (
            <Rows
                facts={[
                    ['Mapping ID', row.record.id],
                    ['Site', row.record.site_name],
                    ['Site ID', row.record.site_id],
                    ['Site type', human(row.record.site_type)],
                    [
                        'Provider Site',
                        text(row.record.mapped_external_site_name),
                    ],
                ]}
            />
        );
    if (row.kind === 'credential')
        return (
            <Rows
                facts={[
                    ['Credential status ID', row.record.id],
                    ['Site', row.record.site_name],
                    ['Site ID', row.record.site_id],
                    ['Capability', human(row.record.capability)],
                ]}
            />
        );
    return (
        <Rows
            facts={[
                ['Run ID', row.record.id],
                ['Action', human(row.record.action)],
                ['Processed', row.record.items_processed],
                ['Created', row.record.items_created],
                ['Updated', row.record.items_updated],
                ['Errors', row.record.items_errored],
            ]}
        />
    );
}
function statusFacts(row: UnifiListRow) {
    if (row.kind === 'device')
        return (
            <>
                <Rows
                    facts={[
                        ['Device state', unifiRowState(row)],
                        ['Recorded health', human(row.record.health_status)],
                        ['Site', row.record.site_name],
                        ['Site ID', text(row.record.site_id)],
                        ['Site type', human(row.record.site_type)],
                        [
                            'Room',
                            row.record.room_id
                                ? text(row.record.room_name)
                                : 'No room assigned',
                        ],
                        ['Room ID', text(row.record.room_id)],
                        [
                            'Last collected device observation',
                            time(row.record.last_seen_at),
                        ],
                    ]}
                />
                <p className="text-subtle">
                    These are saved device observations. Live status has not
                    been checked here.
                </p>
            </>
        );
    if (row.kind === 'mapping')
        return (
            <Rows
                facts={[
                    ['Mapping active', row.record.is_active ? 'Yes' : 'No'],
                    ['Recorded status', human(row.record.status)],
                    [
                        'Provider Site',
                        text(row.record.mapped_external_site_name),
                    ],
                ]}
            />
        );
    if (row.kind === 'credential')
        return (
            <Rows
                facts={[
                    ['Enabled', row.record.enabled ? 'Yes' : 'No'],
                    ['Credential status', unifiRowState(row)],
                    ['Last test', time(row.record.last_tested_at)],
                    ['Failure category', human(row.record.failure_category)],
                ]}
            />
        );
    return (
        <Rows
            facts={[
                ['Run status', unifiRowState(row)],
                ['Started', time(row.record.started_at)],
                ['Completed', time(row.record.completed_at)],
                ['Failure category', human(row.record.failure_category)],
            ]}
        />
    );
}
export function UnifiRecords({
    kind,
    rows,
    view,
    actionsFor,
    total,
    canViewDevices = false,
}: {
    kind: UnifiListRow['kind'];
    rows: UnifiListRow[];
    view: EvidenceRecordsView;
    actionsFor: (row: UnifiListRow) => MenuItem[];
    total?: number;
    canViewDevices?: boolean;
}) {
    const icon =
        kind === 'device'
            ? Cpu
            : kind === 'history'
              ? Clock
              : kind === 'credential'
                ? KeyRound
                : Building2;
    const columns: EntityTableColumn<UnifiListRow>[] = [
        { key: 'record', label: 'Record', width: '1fr', cell: identityFacts },
        {
            key: 'observation',
            label:
                kind === 'history'
                    ? 'Outcome and dates'
                    : 'Status and placement',
            width: '1fr',
            cell: statusFacts,
        },
    ];
    const compact: EntityTableColumn<UnifiListRow>[] = [
        {
            key: 'status',
            label: 'Recorded status',
            width: '1fr',
            cell: stateChip,
        },
        {
            key: 'placement',
            label: kind === 'history' ? 'Items' : 'Site / room',
            width: '1.2fr',
            cell: placement,
        },
        {
            key: 'observed',
            label: kind === 'history' ? 'Started' : 'Last observation',
            width: '1.2fr',
            cell: observed,
        },
    ];
    return (
        <EvidenceRecords<UnifiListRow>
            title={
                kind === 'mapping'
                    ? 'Site mappings'
                    : kind === 'device'
                      ? 'Imported devices'
                      : kind === 'history'
                        ? 'Sync history'
                        : 'Site credential status'
            }
            description={
                kind === 'history'
                    ? 'The latest retained sync runs; a completed run is separate from device health.'
                    : kind === 'mapping'
                      ? 'Approved Site bindings from this read.'
                      : kind === 'device'
                        ? 'Saved imported records in this permitted scope.'
                        : 'Saved credential status and test dates.'
            }
            rows={rows}
            view={view}
            rowKey={unifiRowKey}
            identity={(row) => ({
                name: unifiRowName(row),
                subline: placement(row),
                icon: iconFor(row),
            })}
            actionsFor={(row) => actionsFor(row)}
            hrefFor={(row) =>
                row.kind === 'device' &&
                canViewDevices &&
                row.record.detail_url ===
                    `/security-devices/devices/${row.record.id}`
                    ? row.record.detail_url
                    : null
            }
            cardFor={(row) => ({
                name: unifiRowName(row),
                icon: iconFor(row),
                meridian:
                    row.kind === 'history' && row.record.status === 'failed'
                        ? 'critical'
                        : row.kind === 'history' &&
                            row.record.status === 'partial'
                          ? 'warning'
                          : 'neutral',
                subline: placement(row),
                chips: (
                    <>
                        {stateChip(row)}
                        <EntityChip>
                            {row.kind === 'device'
                                ? human(row.record.category)
                                : row.kind === 'credential'
                                  ? human(row.record.capability)
                                  : 'Saved record'}
                        </EntityChip>
                    </>
                ),
                footer: {
                    primary: observed(row),
                    secondary:
                        row.kind === 'device'
                            ? 'Last collected observation'
                            : 'Saved record details',
                },
            })}
            columns={columns}
            compactColumns={compact}
            total={total}
            minWidth={1100}
            icon={icon}
            empty={
                kind === 'mapping'
                    ? 'No mapped locations yet.'
                    : kind === 'device'
                      ? 'No synced devices for this filter yet.'
                      : kind === 'history'
                        ? 'No sync run has been recorded.'
                        : 'No Site credential status has been recorded.'
            }
        />
    );
}

export function UnifiDiscoveredRecords({
    rows,
    view,
    total,
    onMap,
    disabled,
}: {
    rows: UnifiProps['discoveredSites'];
    view: EvidenceRecordsView;
    total: number;
    onMap: (token: string) => void;
    disabled?: string;
}) {
    const columns: EntityTableColumn<UnifiProps['discoveredSites'][number]>[] =
        [
            {
                key: 'identity',
                label: 'Provider location',
                width: '1fr',
                cell: (row) => (
                    <ReviewRow
                        label="Name"
                        value={row.name || 'Name not recorded'}
                    />
                ),
            },
            {
                key: 'catalogue',
                label: 'Collected catalogue',
                width: '1fr',
                cell: (row) => (
                    <>
                        <ReviewRow
                            label="Device count"
                            value={row.device_count ?? 'Not recorded'}
                        />
                        <p className="text-subtle">
                            This is a collected catalogue entry. It does not
                            establish live reachability.
                        </p>
                    </>
                ),
            },
        ];
    return (
        <EvidenceRecords
            title="Discovered provider locations"
            description="Locations returned by the last permitted discovery read."
            rows={rows}
            view={view}
            total={total}
            rowKey={(row) => row.mapping_token}
            identity={(row) => ({
                name: row.name || 'Name not recorded',
                icon: Building2,
                subline: 'Collected provider catalogue',
            })}
            actionsFor={(row) => [
                {
                    label: 'Map location',
                    icon: Building2,
                    disabled,
                    onClick: () => onMap(row.mapping_token),
                },
            ]}
            cardFor={(row) => ({
                name: row.name || 'Name not recorded',
                icon: Building2,
                meridian: 'neutral',
                subline: 'Collected provider catalogue',
                chips: (
                    <EntityChip>
                        {row.device_count === null ||
                        row.device_count === undefined
                            ? 'Device count not recorded'
                            : row.device_count + ' devices'}
                    </EntityChip>
                ),
                footer: { primary: 'Review provider location' },
            })}
            columns={columns}
            compactColumns={[
                {
                    key: 'count',
                    label: 'Recorded device count',
                    width: '1fr',
                    cell: (row) => row.device_count ?? 'Not recorded',
                },
            ]}
            empty="No provider locations have been collected."
            icon={Building2}
        />
    );
}
