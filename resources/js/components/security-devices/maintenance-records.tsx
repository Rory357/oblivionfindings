import {
    EntityCard,
    EntityChip,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import type { MaintenanceRow } from '@/pages/security-devices/maintenance';
import { Link } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck } from 'lucide-react';
const maintenanceLabels: Record<string, string> = {
    due_soon: 'Due soon',
    in_progress: 'In progress',
    planned_unscheduled: 'Planned without a date',
    scheduled_service: 'Scheduled service',
    firmware_update: 'Firmware update',
    configuration_change: 'Configuration change',
    battery_replacement: 'Battery replacement',
    connectivity_check: 'Connectivity check',
    iot_healthcare: 'IoT healthcare',
    it_infrastructure: 'IT infrastructure',
    medical_device: 'Medical device',
};
export const maintenanceLabel = (value: string) => {
    const known = maintenanceLabels[value];
    if (known) return known;
    const text = value.replace(/[_-]/g, ' ');
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Not recorded';
};
const tone = (value: string): StatusVariant =>
    value === 'completed'
        ? 'success'
        : value === 'overdue'
          ? 'critical'
          : ['due_soon', 'in_progress', 'planned_unscheduled'].includes(value)
            ? 'warning'
            : 'neutral';
const deviceLink = (row: MaintenanceRow, profiles: boolean) =>
    profiles &&
    row.device.href === `/security-devices/devices/${row.device.id}` ? (
        <Link href={row.device.href} className="font-medium hover:underline">
            {row.device.name}
        </Link>
    ) : (
        row.device.name
    );
const siteLink = (row: MaintenanceRow, profiles: boolean) =>
    row.site ? (
        profiles &&
        row.site.href === `/security-devices/sites/${row.site.id}` ? (
            <Link href={row.site.href} className="hover:underline">
                {row.site.name}
            </Link>
        ) : (
            row.site.name
        )
    ) : (
        'No Site assigned'
    );
function cardProps(row: MaintenanceRow, profiles: boolean) {
    return {
        meridian:
            row.schedule_state === 'overdue' ||
            row.schedule_state === 'due_soon'
                ? ('warning' as const)
                : ('neutral' as const),
        icon: ClipboardCheck,
        name: row.description,
        subline: `Work #${row.id} · ${row.status === 'completed' ? `Completed ${formatDateTimeLong(row.completed_at, 'Time not recorded')}` : formatDateOnly(row.scheduled_for, 'Not scheduled')}`,
        chips: (
            <>
                <StatusBadge variant={tone(row.schedule_state)}>
                    {maintenanceLabel(row.schedule_state)}
                </StatusBadge>
                <EntityChip>{maintenanceLabel(row.type)}</EntityChip>
                {row.vendor_reference ? (
                    <EntityChip>
                        Work reference {row.vendor_reference}
                    </EntityChip>
                ) : null}
                {row.performed_by ? (
                    <EntityChip>Recorded by {row.performed_by}</EntityChip>
                ) : null}
            </>
        ),
        footer: {
            primary: deviceLink(row, profiles),
            secondary: siteLink(row, profiles),
        },
    };
}
export function MaintenanceCard({
    row,
    canManage,
    profiles = true,
    onComplete,
    actions,
}: {
    row: MaintenanceRow;
    canManage: boolean;
    profiles?: boolean;
    onComplete?: () => void;
    actions?: MenuItem[];
}) {
    const context = useEntityContextMenu<MaintenanceRow>();
    const menu =
        actions ??
        (canManage && !['completed', 'cancelled'].includes(row.status)
            ? [
                  {
                      label: 'Mark complete',
                      icon: CheckCircle2,
                      disabled: onComplete
                          ? undefined
                          : 'Open Maintenance to review this record.',
                      onClick: onComplete,
                  },
              ]
            : []);
    return (
        <>
            <EntityCard
                {...cardProps(row, profiles)}
                actions={menu}
                onOpen={() =>
                    menu.find((a) => a.label === 'View evidence')?.onClick?.()
                }
                onContextMenu={(event) => context.open(event, row)}
            />
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={row.description}
                    items={menu}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}
export function MaintenanceRecords({
    rows,
    view,
    profiles,
    canManage,
    onComplete,
    total,
    truncated,
    shown,
}: {
    rows: MaintenanceRow[];
    view: EvidenceRecordsView;
    profiles: boolean;
    canManage: boolean;
    onComplete: (row: MaintenanceRow) => void;
    total: number;
    truncated: boolean;
    shown: number;
}) {
    return (
        <EvidenceRecords<MaintenanceRow>
            title="Maintenance work"
            description="Scheduled and completed device work in your permitted scope."
            rows={rows}
            total={total}
            view={view}
            rowKey={(row) => row.id}
            identity={(row) => ({
                name: row.description,
                subline: `Work #${row.id} · ${row.device.name}`,
                icon: ClipboardCheck,
            })}
            articleLabel={(row) =>
                `Maintenance work ${row.id}: ${row.description}`
            }
            icon={ClipboardCheck}
            actionsFor={(row) =>
                canManage && !['completed', 'cancelled'].includes(row.status)
                    ? [
                          {
                              label: 'Mark complete',
                              icon: CheckCircle2,
                              onClick: () => onComplete(row),
                          },
                      ]
                    : []
            }
            cardFor={(row) => cardProps(row, profiles)}
            compactColumns={[
                {
                    key: 'state',
                    label: 'Schedule',
                    width: '135px',
                    cell: (row) => (
                        <StatusBadge variant={tone(row.schedule_state)}>
                            {maintenanceLabel(row.schedule_state)}
                        </StatusBadge>
                    ),
                },
                {
                    key: 'device',
                    label: 'Device',
                    width: '1fr',
                    cell: (row) => deviceLink(row, profiles),
                },
                {
                    key: 'scheduled',
                    label: 'Scheduled',
                    width: '135px',
                    cell: (row) =>
                        formatDateOnly(row.scheduled_for, 'Not scheduled'),
                },
            ]}
            columns={[
                {
                    key: 'work',
                    label: 'Work details',
                    width: '1fr',
                    cell: (row) => (
                        <>
                            <ReviewRow label="Work" value={`#${row.id}`} />
                            <ReviewRow
                                label="Description"
                                value={row.description}
                            />
                            <ReviewRow
                                label="Work type"
                                value={maintenanceLabel(row.type)}
                            />
                            <ReviewRow
                                label="Status"
                                value={maintenanceLabel(row.status)}
                            />
                            <ReviewRow
                                label="Schedule state"
                                value={maintenanceLabel(row.schedule_state)}
                            />
                            <ReviewRow
                                label="Device"
                                value={deviceLink(row, profiles)}
                            />
                            <ReviewRow
                                label="Device ID"
                                value={row.device.id}
                            />
                            <ReviewRow
                                label="Domain"
                                value={
                                    row.device.domain
                                        ? maintenanceLabel(row.device.domain)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Category"
                                value={
                                    row.device.category
                                        ? maintenanceLabel(row.device.category)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Site"
                                value={siteLink(row, profiles)}
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
                                value={row.performed_by ?? 'Not recorded'}
                            />
                            <ReviewRow
                                label="Work reference"
                                value={row.vendor_reference ?? 'Not recorded'}
                            />
                        </>
                    ),
                },
            ]}
            empty="No maintenance work matches this view"
            gap={
                truncated ? (
                    <p role="note" className="text-sm text-muted-foreground">
                        Showing the first {shown} of {total} matching records.
                        Narrow the filters to reconcile the full set.
                    </p>
                ) : null
            }
            minWidth={1100}
        />
    );
}
