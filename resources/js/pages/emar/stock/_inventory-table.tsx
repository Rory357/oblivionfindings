import {
    EmptyValue,
    EntityStatusChip,
    PersonCell,
} from '@/components/lists/entity-cells';
import type { MenuItem } from '@/components/lists/entity-menu';
import { type EntityTableColumn } from '@/components/lists/entity-table';
import { formatDateOnly } from '@/lib/datetime';
import type { StockRow } from '@/pages/emar/_stock-dialogs';
import { AlertTriangle, Check, Clock, Package, Snowflake } from 'lucide-react';
import { StockHubTable } from './_hub-tables';

const columns: EntityTableColumn<StockRow>[] = [
    {
        key: 'person',
        label: 'Person',
        width: '1.4fr',
        cell: (row) => <PersonCell name={row.client_name} />,
    },
    {
        key: 'house',
        label: 'House',
        width: '1fr',
        cell: (row) => (
            <span className="truncate" title={row.site_name ?? undefined}>
                {row.site_name || <EmptyValue />}
            </span>
        ),
    },
    {
        key: 'batch',
        label: 'Batch · expiry',
        width: '1.1fr',
        cell: (row) => (
            <span className="min-w-0">
                <span
                    className="block truncate"
                    title={row.batch_number ?? undefined}
                >
                    {row.batch_number || <EmptyValue />}
                </span>
                <span
                    className={`block text-xs ${row.is_expired ? 'text-status-critical' : row.is_expiring_soon ? 'text-status-warning' : 'text-muted-foreground'}`}
                >
                    {formatDateOnly(row.expiry_date, 'Expiry unknown')}
                </span>
            </span>
        ),
    },
    {
        key: 'balance',
        label: 'On hand',
        width: '104px',
        cell: (row) => (
            <span
                className={`tabular-nums ${row.is_low ? 'font-semibold text-status-critical' : ''}`}
            >
                {row.on_hand === null
                    ? 'Unknown'
                    : `${row.on_hand} ${row.unit}`}
            </span>
        ),
    },
    {
        key: 'reorder',
        label: 'Reorder at',
        width: '76px',
        cell: (row) => (
            <span className="tabular-nums">
                {row.reorder_level ?? <EmptyValue />}
            </span>
        ),
    },
    {
        key: 'status',
        label: 'Status',
        width: '132px',
        cell: (row) =>
            row.is_expired ? (
                <EntityStatusChip variant="critical" icon={AlertTriangle}>
                    Expired
                </EntityStatusChip>
            ) : row.on_hand === null ? (
                <EntityStatusChip variant="neutral">Unknown</EntityStatusChip>
            ) : row.is_low ? (
                <EntityStatusChip variant="warning" icon={AlertTriangle}>
                    Reorder now
                </EntityStatusChip>
            ) : row.is_expiring_soon ? (
                <EntityStatusChip variant="warning" icon={Clock}>
                    Expiring
                </EntityStatusChip>
            ) : (
                <EntityStatusChip variant="success" icon={Check}>
                    In stock
                </EntityStatusChip>
            ),
    },
];

/** Rows are one authoritative server page, already scoped and ordered. */
export function StockInventoryTable({
    rows,
    actionsFor,
    onOpen,
}: {
    rows: StockRow[];
    actionsFor: (row: StockRow) => MenuItem[];
    onOpen: (row: StockRow) => void;
}) {
    return (
        <StockHubTable
            rows={rows}
            identityLabel="Medication"
            identityWidth="1.65fr"
            identity={(row) => ({
                icon: Package,
                name: row.medication_name ?? 'Medication not recorded',
                subline: row.medication_dose,
                extra: (
                    <>
                        {row.controlled && (
                            <EntityStatusChip variant="neutral">
                                CD
                            </EntityStatusChip>
                        )}
                        {row.requires_cold_chain && (
                            <Snowflake
                                className="size-3.5 shrink-0 text-status-info"
                                aria-label="Cold chain"
                            />
                        )}
                    </>
                ),
            })}
            columns={columns}
            actionsFor={actionsFor}
            onOpen={onOpen}
            minWidth={960}
            contextTitle={(row) =>
                (row.medication_name ?? 'Stock') + ' · ' + row.client_name
            }
        />
    );
}
