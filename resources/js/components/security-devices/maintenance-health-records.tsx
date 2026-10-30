import { EntityChip } from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { maintenanceLabel } from '@/components/security-devices/maintenance-records';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import type {
    AttentionDevice,
    LowBatteryDevice,
    MaintenanceRecord,
} from '@/pages/security-devices/maintenance-health';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BatteryLow,
    CheckCircle2,
    ClipboardCheck,
} from 'lucide-react';
const tone = (state: string): StatusVariant =>
    state === 'critical'
        ? 'critical'
        : ['warning', 'offline', 'degraded', 'overdue'].includes(state)
          ? 'warning'
          : 'neutral';
const text = (value: string | number | null) =>
    value === null || value === '' ? 'Not recorded' : String(value);
function device(id: number, name: string | null, profiles: boolean) {
    return profiles ? (
        <Link
            href={`/security-devices/devices/${id}`}
            className="text-primary hover:underline"
        >
            {name || `Device #${id}`}
        </Link>
    ) : (
        name || `Device #${id}`
    );
}
export function MaintenanceHealthWorkRecords({
    rows,
    total,
    view,
    profiles,
    canManage,
    onComplete,
    filtered,
    financeNote,
}: {
    rows: MaintenanceRecord[];
    total: number;
    view: EvidenceRecordsView;
    profiles: boolean;
    canManage: boolean;
    onComplete: (row: MaintenanceRecord) => void;
    filtered: boolean;
    /** Server boundary note, shown word for word wherever a cost appears. */
    financeNote?: string;
}) {
    const state = (row: MaintenanceRecord) =>
        row.is_overdue ? 'overdue' : row.status;
    return (
        <EvidenceRecords<MaintenanceRecord>
            title="Maintenance work"
            description="Scheduled and completed device work. Search and filters apply to this list."
            rows={rows}
            total={total}
            view={view}
            rowKey={(row) => row.id}
            icon={ClipboardCheck}
            identity={(row) => ({
                name: row.description,
                subline: `Work #${row.id} · ${row.device_name || `Device #${row.device_id}`}`,
                icon: ClipboardCheck,
            })}
            articleLabel={(row) =>
                `Maintenance work ${row.id}: ${row.description}`
            }
            actionsFor={(row) =>
                canManage && row.status === 'scheduled'
                    ? [
                          {
                              label: 'Mark complete',
                              icon: CheckCircle2,
                              onClick: () => onComplete(row),
                          },
                      ]
                    : []
            }
            cardFor={(row) => ({
                icon: ClipboardCheck,
                meridian: row.is_overdue ? 'warning' : 'neutral',
                name: row.description,
                subline: `Work #${row.id} · ${formatDateOnly(row.scheduled_for, 'Not scheduled')}`,
                chips: (
                    <>
                        <StatusBadge variant={tone(state(row))}>
                            {maintenanceLabel(state(row))}
                        </StatusBadge>
                        <EntityChip>{maintenanceLabel(row.type)}</EntityChip>
                    </>
                ),
                footer: {
                    primary: device(row.device_id, row.device_name, profiles),
                    secondary: row.vendor_reference || 'No work reference',
                },
            })}
            compactColumns={[
                {
                    key: 'state',
                    label: 'Status',
                    width: '140px',
                    cell: (row) => (
                        <StatusBadge variant={tone(state(row))}>
                            {maintenanceLabel(state(row))}
                        </StatusBadge>
                    ),
                },
                {
                    key: 'device',
                    label: 'Device',
                    width: '190px',
                    cell: (row) =>
                        device(row.device_id, row.device_name, profiles),
                },
                {
                    key: 'due',
                    label: 'Scheduled',
                    width: '150px',
                    cell: (row) =>
                        formatDateOnly(row.scheduled_for, 'Not scheduled'),
                },
            ]}
            columns={[
                {
                    key: 'facts',
                    label: 'Work details',
                    width: '100%',
                    cell: (row) => (
                        <>
                            <ReviewRow label="Work" value={row.description} />
                            <ReviewRow label="Work ID" value={row.id} />
                            <ReviewRow
                                label="Work type"
                                value={maintenanceLabel(row.type)}
                            />
                            <ReviewRow
                                label="Status"
                                value={maintenanceLabel(row.status)}
                            />
                            <ReviewRow
                                label="Overdue"
                                value={row.is_overdue ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="Device"
                                value={device(
                                    row.device_id,
                                    row.device_name,
                                    profiles,
                                )}
                            />
                            <ReviewRow
                                label="Device record ID"
                                value={row.device_id}
                            />
                            <ReviewRow
                                label="Device ID"
                                value={text(row.device_uid)}
                            />
                            <ReviewRow
                                label="Scheduled"
                                value={formatDateOnly(
                                    row.scheduled_for,
                                    'Not scheduled',
                                )}
                            />
                            <ReviewRow
                                label="Completed"
                                value={formatDateTimeLong(
                                    row.completed_at,
                                    'Not recorded',
                                )}
                            />
                            <ReviewRow
                                label="Recorded by"
                                value={text(row.performed_by)}
                            />
                            <ReviewRow
                                label="Work reference"
                                value={text(row.vendor_reference)}
                            />
                            <ReviewRow
                                label="Recorded cost"
                                value={
                                    row.cost === null || row.cost === ''
                                        ? 'Not recorded'
                                        : `$${row.cost}`
                                }
                            />
                            {financeNote ? (
                                <p role="note" className="text-caption">
                                    {financeNote}
                                </p>
                            ) : null}
                            <ReviewRow label="Notes" value={text(row.notes)} />
                        </>
                    ),
                },
            ]}
            empty={
                filtered
                    ? 'No matching maintenance work'
                    : 'No maintenance work recorded'
            }
        />
    );
}
export function MaintenanceHealthDeviceRecords({
    rows,
    mode,
    view,
    profiles,
}: {
    rows: Array<AttentionDevice | LowBatteryDevice>;
    mode: 'attention' | 'battery';
    view: EvidenceRecordsView;
    profiles: boolean;
}) {
    const battery = mode === 'battery',
        Icon = battery ? BatteryLow : AlertTriangle;
    const state = (row: AttentionDevice | LowBatteryDevice) =>
        'health_status' in row ? row.health_status : 'warning';
    return (
        <EvidenceRecords<AttentionDevice | LowBatteryDevice>
            title={
                battery ? 'Low battery devices' : 'Devices needing attention'
            }
            description={
                battery
                    ? 'Operational devices with battery below 20%. Up to 15 devices are shown; readings may be old.'
                    : 'Devices flagged for attention in your permitted scope. Up to 20 devices are shown; the list is not a live check.'
            }
            rows={rows}
            view={view}
            rowKey={(row) => row.id}
            icon={Icon}
            identity={(row) => ({
                name: row.name,
                subline: row.device_uid,
                icon: Icon,
            })}
            articleLabel={(row) =>
                `${battery ? 'Battery' : 'Attention'} device ${row.id}: ${row.name}`
            }
            actionsFor={() => []}
            cardFor={(row) => ({
                icon: Icon,
                meridian: 'warning',
                name: row.name,
                subline: row.device_uid,
                chips: (
                    <>
                        {'status' in row ? (
                            <>
                                <StatusBadge variant={tone(row.status)}>
                                    {maintenanceLabel(row.status)}
                                </StatusBadge>
                                <StatusBadge variant={tone(row.health_status)}>
                                    {maintenanceLabel(row.health_status)}
                                </StatusBadge>
                            </>
                        ) : (
                            <StatusBadge variant="warning">
                                Low battery
                            </StatusBadge>
                        )}
                        <EntityChip>
                            Battery{' '}
                            {row.battery_level === null
                                ? 'not recorded'
                                : `${row.battery_level}%`}
                        </EntityChip>
                    </>
                ),
                footer: {
                    primary: device(row.id, row.name, profiles),
                    secondary:
                        'last_seen_at' in row
                            ? `Last seen ${formatDateTimeLong(row.last_seen_at, 'not recorded')}`
                            : `Battery reading ${formatDateTimeLong(row.battery_updated_at, 'not recorded')}`,
                },
            })}
            compactColumns={[
                {
                    key: 'state',
                    label: battery ? 'Battery' : 'Health',
                    width: '145px',
                    cell: (row) =>
                        battery ? (
                            row.battery_level === null ? (
                                'Not recorded'
                            ) : (
                                `${row.battery_level}%`
                            )
                        ) : (
                            <StatusBadge variant={tone(state(row))}>
                                {maintenanceLabel(state(row))}
                            </StatusBadge>
                        ),
                },
                {
                    key: 'seen',
                    label: battery ? 'Battery reading' : 'Last seen',
                    width: '225px',
                    cell: (row) =>
                        formatDateTimeLong(
                            'last_seen_at' in row
                                ? row.last_seen_at
                                : row.battery_updated_at,
                            'Not recorded',
                        ),
                },
            ]}
            columns={[
                {
                    key: 'facts',
                    label: 'Device details',
                    width: '100%',
                    cell: (row) => (
                        <>
                            <ReviewRow
                                label="Device"
                                value={device(row.id, row.name, profiles)}
                            />
                            <ReviewRow
                                label="Device record ID"
                                value={row.id}
                            />
                            <ReviewRow
                                label="Device ID"
                                value={text(row.device_uid)}
                            />
                            {'status' in row ? (
                                <>
                                    <ReviewRow
                                        label="Domain"
                                        value={maintenanceLabel(row.domain)}
                                    />
                                    <ReviewRow
                                        label="Category"
                                        value={maintenanceLabel(row.category)}
                                    />
                                    <ReviewRow
                                        label="Status"
                                        value={maintenanceLabel(row.status)}
                                    />
                                    <ReviewRow
                                        label="Health"
                                        value={maintenanceLabel(
                                            row.health_status,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Last seen"
                                        value={formatDateTimeLong(
                                            row.last_seen_at,
                                            'Not recorded',
                                        )}
                                    />
                                </>
                            ) : (
                                <ReviewRow
                                    label="Battery reading"
                                    value={formatDateTimeLong(
                                        row.battery_updated_at,
                                        'Not recorded',
                                    )}
                                />
                            )}
                            <ReviewRow
                                label="Battery"
                                value={
                                    row.battery_level === null
                                        ? 'Not recorded'
                                        : `${row.battery_level}%`
                                }
                            />
                        </>
                    ),
                },
            ]}
            empty={
                battery
                    ? 'No low battery devices in this result'
                    : 'No devices needing attention in this result'
            }
        />
    );
}
