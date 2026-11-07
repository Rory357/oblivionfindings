import { EntityTable, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import type { MouseEvent } from 'react';
import type { ColumnChoice } from './_parts';
import type { DeviceRowData } from './_types';

export const LIST_COLUMNS: ColumnChoice[] = [
    { key: 'site', label: 'Site' },
    { key: 'status', label: 'Status' },
    { key: 'watching', label: 'Watching' },
    { key: 'seen', label: 'Last seen' },
    { key: 'kind', label: 'Kind' },
    { key: 'address', label: 'Address' },
    { key: 'battery', label: 'Battery' },
];

export const DEFAULT_LIST_COLUMNS = ['site', 'status', 'watching', 'seen'];

/** List (drawing 2a): rows for going through many at once, problems first then by name. */
export function DevicesList({
    rows,
    shown,
    actionsFor,
    onOpen,
    onContextMenu,
    selectable,
    selected,
    onToggle,
    now,
}: {
    rows: DeviceRowData[];
    shown: string[];
    actionsFor: (row: DeviceRowData) => MenuItem[];
    onOpen: (row: DeviceRowData) => void;
    onContextMenu: (event: MouseEvent, row: DeviceRowData) => void;
    selectable: boolean;
    selected: ReadonlySet<number>;
    onToggle: (row: DeviceRowData, checked: boolean) => void;
    now: Date;
}) {
    const all: Record<string, EntityTableColumn<DeviceRowData>> = {
        site: { key: 'site', label: 'Site', width: '1fr', cell: (row) => row.site ?? 'No Site' },
        status: {
            key: 'status',
            label: 'Status',
            width: '1.5fr',
            cell: (row) => (
                <span className="flex min-w-0 flex-col items-start gap-1 py-1">
                    <StatusBadge variant={row.badge.tone} size="sm">
                        {row.badge.label}
                    </StatusBadge>
                    <span className="max-w-full truncate text-xs text-muted-foreground">{timedText(row.note, now)}</span>
                </span>
            ),
        },
        watching: { key: 'watching', label: 'Watching', width: '1.1fr', cell: (row) => row.watching },
        seen: {
            key: 'seen',
            label: 'Last seen',
            width: '120px',
            cell: (row) => <span className="whitespace-nowrap">{row.seen_at ? seenTime(row.seen_at, now) : '—'}</span>,
        },
        kind: { key: 'kind', label: 'Kind', width: '1fr', cell: (row) => row.kind_label },
        address: { key: 'address', label: 'Address', width: '1fr', cell: (row) => row.address ?? '—' },
        battery: {
            key: 'battery',
            label: 'Battery',
            width: '90px',
            cell: (row) => (row.battery === null ? '—' : `${row.battery}%`),
        },
    };

    return (
        <EntityTable<DeviceRowData>
            className="rounded-none border-0 shadow-none"
            rows={rows}
            rowKey={(row) => row.id}
            identityLabel="Device"
            identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.sub, linkLabel: `Open ${row.name}` })}
            hrefFor={(row) => row.href}
            columns={shown.map((key) => all[key]).filter(Boolean)}
            actionsFor={actionsFor}
            onOpen={onOpen}
            onRowContextMenu={onContextMenu}
            rowHeight="content"
            minWidth={880}
            selection={
                selectable
                    ? {
                          keys: selected,
                          onToggle,
                          labelFor: (row) => `Select ${row.name}`,
                      }
                    : undefined
            }
        />
    );
}
