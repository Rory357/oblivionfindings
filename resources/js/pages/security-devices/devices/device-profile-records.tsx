import {
    EntityChip,
    EntityStatusChip,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import {
    ControlRoomAlertAccessRequired,
    FleetTechnologyAccessRequired,
} from '@/components/security-devices/permission-destinations';
import { ReviewRow } from '@/components/wizard/shell';
import {
    formatDate,
    formatDateOnly,
    formatDateTime,
    toDateInput,
} from '@/lib/datetime';
import {
    Activity,
    AlertTriangle,
    Check,
    FileText,
    GitBranch,
    Link2,
    MapPin,
    Minus,
    Network,
    Trash2,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { DeviceProfile } from './device-profile';
import type { Props } from './show';

export type DeviceRecordsView = EvidenceRecordsView;
type Assignment = Props['assignmentHistory'][number];
type Asset = Props['assetLinks'][number];
type Relationship = Props['relationships']['parents'][number];
type RelationshipHistory = Props['relationshipHistory']['parents'][number];
type Document = Props['documents'][number];
type DocumentHistory = Props['documentHistory'][number];
type Event = Props['recentEvents'][number];
type Maintenance = Props['maintenanceRecords'][number];
type Alert = DeviceProfile['controlRoomAlerts'][number];
type Directed<T> = T & { direction: 'Upstream' | 'Downstream' };

type Facts<T> = {
    /** Every retained field, each labelled once. */
    labels: Record<keyof T, string>;
    /** Identifiers and raw recorded values, shown in the collapsed "Technical details". */
    technical?: ReadonlyArray<keyof T>;
    dates?: Partial<Record<keyof T, 'date' | 'time'>>;
    render?: Partial<Record<keyof T, (row: T) => ReactNode>>;
};

function factRow(key: string, label: string, value: ReactNode) {
    return (
        <ReviewRow
            key={key}
            label={label}
            value={
                <span className="[overflow-wrap:anywhere] break-words whitespace-pre-wrap">
                    {value}
                </span>
            }
        />
    );
}

function RecordFacts<T extends object>({
    row,
    labels,
    technical = [],
    dates,
    render,
}: Facts<T> & { row: T }) {
    const entries = Object.entries(labels) as Array<[keyof T & string, string]>;
    const plainEntries = entries.filter(([key]) => !technical.includes(key));
    const technicalEntries = entries.filter(([key]) => technical.includes(key));
    return (
        <div className="min-w-0 space-y-1">
            {plainEntries.map(([key, label]) => {
                const raw = row[key];
                const date = dates?.[key];
                const value =
                    render?.[key]?.(row) ??
                    (raw == null || raw === ''
                        ? 'Not recorded'
                        : typeof raw === 'boolean'
                          ? raw
                              ? 'Yes'
                              : 'No'
                          : date && typeof raw === 'string'
                            ? date === 'date'
                                ? recordedDate(raw)
                                : formatDateTime(raw)
                            : String(raw));
                return factRow(key, label, value);
            })}
            {technicalEntries.length > 0 && (
                <details className="mt-3 min-w-0 rounded-lg border border-border p-3">
                    <summary className="frontline-focus text-caption cursor-pointer font-medium">
                        Technical details
                    </summary>
                    <div className="mt-3 min-w-0">
                        {technicalEntries.map(([key, label]) => {
                            const raw = row[key];
                            return factRow(
                                key,
                                label,
                                raw == null || raw === ''
                                    ? 'Not recorded'
                                    : String(raw),
                            );
                        })}
                    </div>
                </details>
            )}
        </div>
    );
}

function RecordList<T extends { id: number | null }>({
    title,
    description,
    rows,
    view,
    icon,
    name,
    subline,
    chips,
    alerts,
    meridian,
    columns,
    facts,
    empty,
    actionsFor = () => [],
    hrefFor,
    extraFor,
    footer,
    rowKey,
}: {
    title: string;
    description: string;
    rows: T[];
    view: DeviceRecordsView;
    icon: LucideIcon;
    name: (row: T) => string;
    subline: (row: T) => ReactNode;
    chips?: (row: T) => ReactNode;
    alerts?: (row: T) => ReactNode;
    meridian?: (row: T) => 'neutral' | 'warning' | 'critical';
    columns: EntityTableColumn<T>[];
    facts: Facts<T>;
    empty: string;
    actionsFor?: (row: T) => MenuItem[];
    hrefFor?: (row: T) => string | null;
    extraFor?: (row: T) => ReactNode;
    footer?: (row: T) => string;
    rowKey?: (row: T) => string | number;
}) {
    return (
        <EvidenceRecords
            title={title}
            description={description}
            rows={rows}
            view={view}
            rowKey={rowKey ?? ((row) => row.id ?? 'record')}
            identity={(row) => ({
                icon,
                name: name(row),
                subline: subline(row),
            })}
            articleLabel={(row) => `${title}: ${name(row)}`}
            actionsFor={actionsFor}
            hrefFor={hrefFor}
            extraFor={extraFor}
            icon={icon}
            minWidth={900}
            empty={empty}
            cardFor={(row) => ({
                icon,
                name: name(row),
                subline: subline(row),
                meridian: meridian?.(row) ?? 'neutral',
                chips: chips?.(row),
                alerts: alerts?.(row),
                footer: { primary: footer?.(row) ?? 'Retained device record' },
                openLabel: hrefFor?.(row) ? 'Open record' : 'View evidence',
            })}
            compactColumns={columns}
            columns={[
                {
                    key: 'record',
                    label: 'Retained record',
                    width: '1fr',
                    cell: (row) => <RecordFacts {...facts} row={row} />,
                },
            ]}
        />
    );
}

function label(value: string | null | undefined) {
    if (!value) return 'Not recorded';
    return value.replace(/[._-]+/g, ' ');
}
function bytes(value: number) {
    return value < 1024
        ? `${value} B`
        : value < 1024 * 1024
          ? `${(value / 1024).toFixed(1)} KB`
          : `${(value / 1024 / 1024).toFixed(1)} MB`;
}
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
/**
 * Whether a recorded date or time had passed when the record was read.
 * A date-only value (an expiry date, say) is a New Zealand calendar day,
 * not UTC midnight: it passes only once the read's NZ day is after it, so
 * nothing shows as passed during its own day.
 */
function beforeRead(value: string | null, at: string) {
    if (value == null) return false;
    if (DATE_ONLY.test(value)) {
        const readDay = toDateInput(at);
        return readDay !== '' && value < readDay;
    }
    return new Date(value).getTime() < new Date(at).getTime();
}
function recordedTime(value: string | null) {
    return value ? formatDateTime(value) : 'Not recorded';
}
function recordedDate(value: string | null) {
    if (!value) return 'Not recorded';
    return DATE_ONLY.test(value)
        ? formatDateOnly(value, 'Not recorded')
        : formatDate(value);
}

export function DeviceAssignmentRecords({
    rows,
    view,
}: {
    rows: Assignment[];
    view: DeviceRecordsView;
}) {
    return (
        <RecordList
            title="Assignment history"
            description="Returned assignments, newest first. Recorded ownership is separate from device health."
            rows={rows}
            view={view}
            icon={MapPin}
            name={(row) => row.assignable_name}
            subline={(row) =>
                `${label(row.assignable_type)} · ${label(row.assignment_type)}`
            }
            chips={(row) => (
                <>
                    <EntityChip>
                        {row.is_active
                            ? 'Active assignment'
                            : 'Released assignment'}
                    </EntityChip>
                    <EntityChip>{recordedTime(row.assigned_at)}</EntityChip>
                </>
            )}
            alerts={(row) =>
                row.is_overdue ? (
                    <EntityStatusChip variant="warning">
                        Overdue return
                    </EntityStatusChip>
                ) : null
            }
            meridian={(row) => (row.is_overdue ? 'warning' : 'neutral')}
            footer={(row) => row.assigned_by ?? 'Assigning person not recorded'}
            empty="No assignment history returned"
            columns={[
                {
                    key: 'state',
                    label: 'Assignment',
                    width: '150px',
                    cell: (row) => (
                        <EntityChip>
                            {row.is_active ? 'Active' : 'Released'}
                            {row.is_overdue ? ' · overdue' : ''}
                        </EntityChip>
                    ),
                },
                {
                    key: 'dates',
                    label: 'Assigned / return due',
                    width: '1.2fr',
                    cell: (row) => (
                        <span>
                            {recordedTime(row.assigned_at)}
                            <span className="text-caption block text-muted-foreground">
                                Return: {recordedDate(row.expected_return_at)}
                            </span>
                        </span>
                    ),
                },
                {
                    key: 'released',
                    label: 'Released',
                    width: '1fr',
                    cell: (row) => recordedTime(row.released_at),
                },
            ]}
            facts={{
                labels: {
                    id: 'Assignment ID',
                    assignable_type: 'Target type',
                    assignable_name: 'Assigned to',
                    assignment_type: 'Assignment type',
                    assigned_at: 'Assigned at',
                    expected_return_at: 'Expected return',
                    released_at: 'Released at',
                    assigned_by: 'Assigned by',
                    released_by: 'Released by',
                    is_active: 'Active assignment',
                    is_overdue: 'Return overdue',
                    notes: 'Notes',
                },
                technical: ['id'],
                dates: {
                    assigned_at: 'time',
                    expected_return_at: 'time',
                    released_at: 'time',
                },
                render: {
                    assignable_type: (row) => label(row.assignable_type),
                    assignment_type: (row) => label(row.assignment_type),
                },
            }}
        />
    );
}

export function DeviceAssetRecords({
    rows,
    view,
    canUpdate,
    unlinkingId,
    onUnlink,
}: {
    rows: Asset[];
    view: DeviceRecordsView;
    canUpdate: boolean;
    unlinkingId: number | null;
    onUnlink: (row: Asset) => void;
}) {
    const destination = (row: Asset) =>
        row.access.state === 'available' ? row.href : null;
    return (
        <RecordList
            title="Linked assets"
            description="Canonical asset links. Asset destination access is checked separately from device access."
            rows={rows}
            view={view}
            icon={Link2}
            name={(row) => row.asset_name ?? `Asset #${row.asset_id}`}
            subline={(row) => row.asset_tag ?? 'Asset tag not recorded'}
            hrefFor={destination}
            chips={(row) => (
                <>
                    <EntityChip>{label(row.link_type)}</EntityChip>
                    <EntityChip>
                        {row.access.state === 'available'
                            ? 'Asset access available'
                            : row.access.label}
                    </EntityChip>
                </>
            )}
            extraFor={(row) =>
                row.access.state === 'restricted' ? (
                    <FleetTechnologyAccessRequired label={row.access.label} />
                ) : null
            }
            footer={(row) => `Linked ${recordedTime(row.linked_at)}`}
            empty="No linked assets returned"
            actionsFor={(row) =>
                canUpdate
                    ? [
                          {
                              label: 'Unlink asset',
                              icon: Minus,
                              danger: true,
                              disabled:
                                  unlinkingId === row.id
                                      ? 'Unlink request in progress'
                                      : undefined,
                              onClick: () => onUnlink(row),
                          },
                      ]
                    : []
            }
            columns={[
                {
                    key: 'type',
                    label: 'Link type',
                    width: '120px',
                    cell: (row) => (
                        <EntityChip>{label(row.link_type)}</EntityChip>
                    ),
                },
                {
                    key: 'access',
                    label: 'Destination access',
                    width: '1.2fr',
                    cell: (row) =>
                        row.access.state === 'restricted' ? (
                            <FleetTechnologyAccessRequired
                                label={row.access.label}
                            />
                        ) : (
                            <EntityChip>{row.access.label}</EntityChip>
                        ),
                },
                {
                    key: 'linked',
                    label: 'Linked',
                    width: '1fr',
                    cell: (row) => recordedTime(row.linked_at),
                },
            ]}
            facts={{
                labels: {
                    id: 'Link ID',
                    asset_id: 'Asset ID',
                    asset_name: 'Asset name',
                    asset_tag: 'Asset tag',
                    href: 'Asset destination',
                    access: 'Destination access',
                    link_type: 'Link type',
                    linked_at: 'Linked at',
                    notes: 'Notes',
                },
                technical: ['id', 'asset_id'],
                dates: { linked_at: 'time' },
                render: {
                    access: (row) => row.access.label,
                    link_type: (row) => label(row.link_type),
                    href: (row) =>
                        destination(row) ? (
                            <a
                                href={destination(row)!}
                                className="frontline-focus rounded-sm text-primary underline"
                            >
                                {destination(row)}
                            </a>
                        ) : (
                            'Not available in current access'
                        ),
                },
            }}
        />
    );
}
function directed<T>(rows: { parents: T[]; children: T[] }): Directed<T>[] {
    return [
        ...rows.parents.map((row) => ({
            ...row,
            direction: 'Upstream' as const,
        })),
        ...rows.children.map((row) => ({
            ...row,
            direction: 'Downstream' as const,
        })),
    ];
}
const relationshipLabels: Facts<Directed<Relationship>>['labels'] = {
    id: 'Relationship ID',
    device_id: 'Related device ID',
    device_name: 'Related device name',
    type: 'Relationship type',
    port: 'Port',
    direction: 'Direction from this device',
};
const relationshipTechnical = ['id', 'device_id'] as const;
function relationshipName(row: Relationship) {
    return row.device_name ?? `Device #${row.device_id}`;
}
function relationshipColumns<
    T extends Directed<Relationship>,
>(): EntityTableColumn<T>[] {
    return [
        {
            key: 'direction',
            label: 'Direction',
            width: '130px',
            cell: (row) => <EntityChip>{row.direction}</EntityChip>,
        },
        {
            key: 'type',
            label: 'Connection',
            width: '1fr',
            cell: (row) => label(row.type),
        },
        {
            key: 'port',
            label: 'Port',
            width: '100px',
            cell: (row) => row.port ?? 'Not recorded',
        },
    ];
}
export function DeviceRelationshipRecords({
    rows,
    view,
    canUpdate,
    unlinkingId,
    onUnlink,
}: {
    rows: Props['relationships'];
    view: DeviceRecordsView;
    canUpdate: boolean;
    unlinkingId: number | null;
    onUnlink: (row: Relationship) => void;
}) {
    return (
        <RecordList
            title="Device relationships"
            description="Upstream parents and downstream children retain their direction from this device."
            rows={directed(rows)}
            view={view}
            icon={Network}
            name={relationshipName}
            subline={(row) => `${row.direction} · ${label(row.type)}`}
            hrefFor={(row) => `/security-devices/devices/${row.device_id}`}
            chips={(row) => (
                <EntityChip>
                    {row.port ? `Port ${row.port}` : 'Port not recorded'}
                </EntityChip>
            )}
            empty="No active device relationships returned"
            columns={relationshipColumns()}
            actionsFor={(row) =>
                canUpdate
                    ? [
                          {
                              label: 'Remove relationship',
                              icon: Minus,
                              danger: true,
                              disabled:
                                  unlinkingId === row.id
                                      ? 'Removal request in progress'
                                      : undefined,
                              onClick: () => onUnlink(row),
                          },
                      ]
                    : []
            }
            facts={{
                labels: relationshipLabels,
                technical: relationshipTechnical,
                render: { type: (row) => label(row.type) },
            }}
        />
    );
}
export function DeviceRelationshipHistoryRecords({
    rows,
    view,
}: {
    rows: Props['relationshipHistory'];
    view: DeviceRecordsView;
}) {
    return (
        <RecordList
            title="Relationship history"
            description="Removed links retain creation, operator and removal reason evidence. Neither device is deleted by unlinking."
            rows={directed(rows)}
            view={view}
            icon={GitBranch}
            name={relationshipName}
            subline={(row) => `${row.direction} · ${label(row.type)}`}
            hrefFor={(row) => `/security-devices/devices/${row.device_id}`}
            chips={(row) => (
                <>
                    <EntityChip>Removed relationship</EntityChip>
                    <EntityChip>{recordedTime(row.unlinked_at)}</EntityChip>
                </>
            )}
            footer={(row) => row.unlinked_by ?? 'Removing person not recorded'}
            empty="No relationship history returned"
            columns={[
                ...relationshipColumns<Directed<RelationshipHistory>>().slice(
                    0,
                    2,
                ),
                {
                    key: 'removed',
                    label: 'Removed / operator',
                    width: '1.2fr',
                    cell: (row) => (
                        <span>
                            {recordedTime(row.unlinked_at)}
                            <span className="text-caption block text-muted-foreground">
                                {row.unlinked_by ?? 'Not recorded'}
                            </span>
                        </span>
                    ),
                },
            ]}
            facts={{
                labels: {
                    ...relationshipLabels,
                    created_at: 'Created at',
                    created_by: 'Created by',
                    unlinked_at: 'Removed at',
                    unlinked_by: 'Removed by',
                    unlink_reason: 'Removal reason',
                },
                technical: relationshipTechnical,
                dates: { created_at: 'time', unlinked_at: 'time' },
                render: { type: (row) => label(row.type) },
            }}
        />
    );
}

export function DeviceDocumentRecords({
    rows,
    view,
    asOf,
    canUpdate,
    deletingId,
    onRemove,
}: {
    rows: Document[];
    view: DeviceRecordsView;
    asOf: string;
    canUpdate: boolean;
    deletingId: number | null;
    onRemove: (row: Document) => void;
}) {
    return (
        <RecordList
            title="Documents"
            description="Returned active files. Read metadata and notes before opening the canonical private download."
            rows={rows}
            view={view}
            icon={FileText}
            name={(row) => row.title}
            subline={(row) => `${row.original_name} · ${bytes(row.size_bytes)}`}
            chips={(row) => (
                <>
                    <EntityChip>{label(row.category)}</EntityChip>
                    {row.version ? (
                        <EntityChip>Version {row.version}</EntityChip>
                    ) : null}
                </>
            )}
            alerts={(row) =>
                beforeRead(row.expiry_date, asOf) ? (
                    <EntityStatusChip variant="warning">
                        Expiry date passed
                    </EntityStatusChip>
                ) : null
            }
            meridian={(row) =>
                beforeRead(row.expiry_date, asOf) ? 'warning' : 'neutral'
            }
            footer={(row) => `Expiry: ${recordedDate(row.expiry_date)}`}
            empty="No active documents returned"
            actionsFor={(row) => [
                {
                    label: 'Download document',
                    icon: FileText,
                    onClick: () => {
                        window.location.assign(row.download_url);
                    },
                },
                ...(canUpdate
                    ? [
                          {
                              label: 'Remove document',
                              icon: Trash2,
                              danger: true,
                              disabled:
                                  deletingId === row.id
                                      ? 'Removal request in progress'
                                      : undefined,
                              onClick: () => onRemove(row),
                          },
                      ]
                    : []),
            ]}
            columns={[
                {
                    key: 'category',
                    label: 'Category / version',
                    width: '1fr',
                    cell: (row) => (
                        <span>
                            <EntityChip>{label(row.category)}</EntityChip>
                            <span className="text-caption block text-muted-foreground">
                                Version: {row.version ?? 'Not recorded'}
                            </span>
                        </span>
                    ),
                },
                {
                    key: 'size',
                    label: 'File size',
                    width: '100px',
                    cell: (row) => bytes(row.size_bytes),
                },
                {
                    key: 'expiry',
                    label: 'Expiry',
                    width: '1fr',
                    cell: (row) => (
                        <span>
                            {recordedDate(row.expiry_date)}
                            {beforeRead(row.expiry_date, asOf) ? (
                                <span className="text-caption block text-status-warning">
                                    Expiry date passed
                                </span>
                            ) : null}
                        </span>
                    ),
                },
            ]}
            facts={{
                labels: {
                    id: 'Document ID',
                    title: 'Title',
                    category: 'Category',
                    version: 'Version',
                    effective_date: 'Effective date',
                    expiry_date: 'Expiry date',
                    original_name: 'Original file name',
                    mime_type: 'File type',
                    size_bytes: 'File size in bytes',
                    notes: 'Notes',
                    uploaded_at: 'Uploaded at',
                    download_url: 'Private download',
                },
                technical: ['id'],
                dates: {
                    effective_date: 'date',
                    expiry_date: 'date',
                    uploaded_at: 'time',
                },
                render: {
                    category: (row) => label(row.category),
                    download_url: (row) => (
                        <a
                            href={row.download_url}
                            className="frontline-focus rounded-sm text-primary underline"
                        >
                            {row.download_url}
                        </a>
                    ),
                },
            }}
        />
    );
}
export function DeviceDocumentHistoryRecords({
    rows,
    view,
}: {
    rows: DocumentHistory[];
    view: DeviceRecordsView;
}) {
    return (
        <RecordList
            title="Document lifecycle history"
            description="Retained storage and removal evidence. A staged upload or pending removal is not a verified completed operation."
            rows={rows}
            view={view}
            icon={FileText}
            name={(row) => row.title}
            subline={(row) => `${row.original_name} · ${bytes(row.size_bytes)}`}
            chips={(row) => (
                <>
                    <EntityChip>{row.status_label}</EntityChip>
                    <EntityChip>{label(row.category)}</EntityChip>
                </>
            )}
            alerts={(row) =>
                row.needs_attention ? (
                    <EntityStatusChip variant="warning">
                        Storage needs attention
                    </EntityStatusChip>
                ) : null
            }
            meridian={(row) => (row.needs_attention ? 'warning' : 'neutral')}
            footer={(row) => row.uploaded_by ?? 'Uploading person not recorded'}
            empty="No document lifecycle history returned"
            columns={[
                {
                    key: 'state',
                    label: 'Lifecycle',
                    width: '1.1fr',
                    cell: (row) => (
                        <span>
                            <EntityChip>{row.status_label}</EntityChip>
                            {row.needs_attention ? (
                                <span className="text-caption block text-status-warning">
                                    Needs attention
                                </span>
                            ) : null}
                        </span>
                    ),
                },
                {
                    key: 'upload',
                    label: 'Uploaded',
                    width: '1fr',
                    cell: (row) => recordedTime(row.uploaded_at),
                },
                {
                    key: 'removal',
                    label: 'Removed',
                    width: '1fr',
                    cell: (row) => recordedTime(row.removed_at),
                },
            ]}
            facts={{
                labels: {
                    id: 'Document ID',
                    title: 'Title',
                    category: 'Category',
                    version: 'Version',
                    original_name: 'Original file name',
                    size_bytes: 'File size in bytes',
                    uploaded_at: 'Uploaded at',
                    uploaded_by: 'Uploaded by',
                    state: 'Recorded lifecycle state',
                    status_label: 'Lifecycle status',
                    needs_attention: 'Storage needs attention',
                    storage_verified_at: 'Storage verified at',
                    integrity_sha256: 'File checksum (SHA-256)',
                    removal_requested_at: 'Removal requested at',
                    removed_at: 'Removed at',
                    removed_by: 'Removed by',
                    removal_reason: 'Removal reason',
                    storage_deleted_at: 'Storage deleted at',
                },
                technical: ['id', 'state', 'integrity_sha256'],
                render: { category: (row) => label(row.category) },
                dates: {
                    uploaded_at: 'time',
                    storage_verified_at: 'time',
                    removal_requested_at: 'time',
                    removed_at: 'time',
                    storage_deleted_at: 'time',
                },
            }}
        />
    );
}
function severityChip(value: string) {
    return value === 'critical' ? (
        <EntityStatusChip variant="critical">Critical</EntityStatusChip>
    ) : value === 'warning' ? (
        <EntityStatusChip variant="warning">Warning</EntityStatusChip>
    ) : (
        <EntityChip>{label(value)}</EntityChip>
    );
}
export function DeviceEventRecords({
    rows,
    view,
}: {
    rows: Event[];
    view: DeviceRecordsView;
}) {
    return (
        <RecordList
            title="Recent events"
            description="Last 20 device events returned by the record read. Event observations do not establish live device health."
            rows={rows}
            view={view}
            icon={Activity}
            name={(row) => label(row.event_type)}
            subline={(row) => recordedTime(row.occurred_at)}
            chips={(row) => severityChip(row.severity)}
            meridian={(row) =>
                row.severity === 'critical'
                    ? 'critical'
                    : row.severity === 'warning'
                      ? 'warning'
                      : 'neutral'
            }
            footer={(row) => row.source ?? 'Event source not recorded'}
            empty="No recent events returned"
            columns={[
                {
                    key: 'severity',
                    label: 'Severity',
                    width: '110px',
                    cell: (row) => severityChip(row.severity),
                },
                {
                    key: 'time',
                    label: 'Occurred',
                    width: '1fr',
                    cell: (row) => recordedTime(row.occurred_at),
                },
                {
                    key: 'source',
                    label: 'Source',
                    width: '1fr',
                    cell: (row) => row.source ?? 'Not recorded',
                },
            ]}
            facts={{
                labels: {
                    id: 'Event ID',
                    event_type: 'Event type',
                    severity: 'Recorded severity',
                    occurred_at: 'Occurred at',
                    source: 'Source',
                },
                technical: ['id'],
                dates: { occurred_at: 'time' },
                render: {
                    event_type: (row) => label(row.event_type),
                    severity: (row) => label(row.severity),
                },
            }}
        />
    );
}
export function DeviceMaintenanceRecords({
    rows,
    view,
    asOf,
    canManage,
    onComplete,
}: {
    rows: Maintenance[];
    view: DeviceRecordsView;
    asOf: string;
    canManage: boolean;
    onComplete: (row: Maintenance) => void;
}) {
    const overdue = (row: Maintenance) =>
        row.status === 'scheduled' && beforeRead(row.scheduled_for, asOf);
    return (
        <RecordList
            title="Maintenance records"
            description="Last 10 maintenance records returned. Due-date notices use the record read time; completion remains a separate recorded action."
            rows={rows}
            view={view}
            icon={Wrench}
            name={(row) => label(row.type)}
            subline={(row) => row.description}
            chips={(row) => <EntityChip>{label(row.status)}</EntityChip>}
            alerts={(row) =>
                overdue(row) ? (
                    <EntityStatusChip variant="warning">
                        Overdue at record read
                    </EntityStatusChip>
                ) : null
            }
            meridian={(row) => (overdue(row) ? 'warning' : 'neutral')}
            footer={(row) => `Due: ${recordedDate(row.scheduled_for)}`}
            empty="No maintenance records returned"
            actionsFor={(row) =>
                canManage && row.status === 'scheduled'
                    ? [
                          {
                              label: 'Complete maintenance',
                              icon: Check,
                              onClick: () => onComplete(row),
                          },
                      ]
                    : []
            }
            columns={[
                {
                    key: 'state',
                    label: 'Status',
                    width: '150px',
                    cell: (row) => (
                        <span>
                            <EntityChip>{label(row.status)}</EntityChip>
                            {overdue(row) ? (
                                <span className="text-caption block text-status-warning">
                                    Overdue at read
                                </span>
                            ) : null}
                        </span>
                    ),
                },
                {
                    key: 'scheduled',
                    label: 'Scheduled',
                    width: '1fr',
                    cell: (row) => recordedDate(row.scheduled_for),
                },
                {
                    key: 'completed',
                    label: 'Completed',
                    width: '1fr',
                    cell: (row) => recordedTime(row.completed_at),
                },
            ]}
            facts={{
                labels: {
                    id: 'Maintenance ID',
                    type: 'Maintenance type',
                    status: 'Recorded status',
                    description: 'Description',
                    scheduled_for: 'Scheduled for',
                    completed_at: 'Completed at',
                },
                technical: ['id'],
                dates: { scheduled_for: 'time', completed_at: 'time' },
                render: {
                    type: (row) => label(row.type),
                    status: (row) => label(row.status),
                },
            }}
        />
    );
}
export function DeviceControlRoomRecords({
    rows,
    view,
}: {
    rows: Alert[];
    view: DeviceRecordsView;
}) {
    const available = rows.filter(
        (row) => row.access.state === 'available' && row.href,
    );
    const restricted = rows.filter(
        (row) => row.access.state !== 'available' || !row.href,
    );
    return (
        <div className="space-y-5">
            <RecordList
                title="Control Room alerts"
                description="Returned operational alerts linked through the canonical Control Room projection. Restricted alerts require separate access."
                rows={available}
                rowKey={(row) => row.id ?? row.href ?? 'alert'}
                view={view}
                icon={AlertTriangle}
                name={(row) => row.reference ?? 'Alert reference not recorded'}
                subline={(row) =>
                    row.type ? label(row.type) : 'Alert type not recorded'
                }
                hrefFor={(row) => row.href}
                chips={(row) => (
                    <>
                        {row.severity ? (
                            severityChip(row.severity)
                        ) : (
                            <EntityChip>Severity not recorded</EntityChip>
                        )}
                        <EntityChip>
                            {row.status
                                ? label(row.status)
                                : 'Status not recorded'}
                        </EntityChip>
                    </>
                )}
                meridian={(row) =>
                    row.severity === 'critical'
                        ? 'critical'
                        : row.severity === 'warning'
                          ? 'warning'
                          : 'neutral'
                }
                footer={(row) => recordedTime(row.triggeredAt)}
                empty={
                    restricted.length
                        ? 'No Control Room alerts available in your current access'
                        : 'No Control Room alerts returned'
                }
                columns={[
                    {
                        key: 'severity',
                        label: 'Severity',
                        width: '110px',
                        cell: (row) =>
                            row.severity
                                ? severityChip(row.severity)
                                : 'Not recorded',
                    },
                    {
                        key: 'status',
                        label: 'Status',
                        width: '130px',
                        cell: (row) => (
                            <EntityChip>
                                {row.status
                                    ? label(row.status)
                                    : 'Not recorded'}
                            </EntityChip>
                        ),
                    },
                    {
                        key: 'triggered',
                        label: 'Triggered',
                        width: '1fr',
                        cell: (row) => recordedTime(row.triggeredAt),
                    },
                ]}
                facts={{
                    labels: {
                        id: 'Alert ID',
                        reference: 'Reference',
                        type: 'Alert type',
                        severity: 'Recorded severity',
                        status: 'Recorded status',
                        triggeredAt: 'Triggered at',
                        href: 'Control Room destination',
                        access: 'Destination access',
                    },
                    technical: ['id'],
                    dates: { triggeredAt: 'time' },
                    render: {
                        type: (row) =>
                            row.type ? label(row.type) : 'Not recorded',
                        severity: (row) =>
                            row.severity ? label(row.severity) : 'Not recorded',
                        status: (row) =>
                            row.status ? label(row.status) : 'Not recorded',
                        href: (row) => (
                            <a
                                href={row.href!}
                                className="frontline-focus rounded-sm text-primary underline"
                            >
                                {row.href}
                            </a>
                        ),
                        access: (row) => row.access.label,
                    },
                }}
            />
            {restricted.map((row, index) => (
                <ControlRoomAlertAccessRequired
                    key={index}
                    label={row.access.label}
                />
            ))}
        </div>
    );
}
