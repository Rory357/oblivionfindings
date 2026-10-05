import {
    EmptyValue,
    EntityStatusChip,
    PersonCell,
} from '@/components/lists/entity-cells';
import { EntityContextMenu } from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    AlertTriangle,
    ClipboardCheck,
    Clock,
    Package,
    ShieldCheck,
} from 'lucide-react';
import { useState, type ComponentProps } from 'react';
import {
    PHARMACY_NEXT_LABEL,
    type ControlledRegisterRow,
    type PharmacyOrderRow,
} from './_hub-types';

const pharmacyStatus = (status: string) =>
    ({
        draft: 'Draft',
        submitted: 'Submitted',
        confirmed: 'Confirmed',
        dispensed: 'Dispensed',
        delivered: 'Delivered',
        received: 'Received',
        closed_short: 'Closed short',
        cancelled: 'Cancelled',
    })[status] ?? status.replaceAll('_', ' ');

/** Both menu entry points receive exactly the same page-owned workflow actions. */
export function StockHubTable<T extends { id: number }>(
    props: Omit<
        ComponentProps<typeof EntityTable<T>>,
        'rowKey' | 'onRowContextMenu'
    > & { contextTitle: (row: T) => string },
) {
    const { contextTitle, ...table } = props;
    const [context, setContext] = useState<{
        x: number;
        y: number;
        row: T;
    } | null>(null);
    return (
        <>
            <EntityTable
                {...table}
                rowKey={(row) => row.id}
                onRowContextMenu={(event, row) => {
                    event.preventDefault();
                    setContext({ x: event.clientX, y: event.clientY, row });
                }}
            />
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={contextTitle(context.row)}
                    icon={Package}
                    items={table.actionsFor(context.row)}
                    onClose={() => setContext(null)}
                />
            )}
        </>
    );
}

export const pharmacyColumns: EntityTableColumn<PharmacyOrderRow>[] = [
    {
        key: 'person',
        label: 'Person',
        width: '1.3fr',
        cell: (row) => <PersonCell name={row.client_name} />,
    },
    {
        key: 'pharmacy',
        label: 'Pharmacy',
        width: '1.1fr',
        cell: (row) => (
            <span className="truncate" title={row.pharmacy_name ?? undefined}>
                {row.pharmacy_name || <EmptyValue />}
            </span>
        ),
    },
    {
        key: 'ordered',
        label: 'Ordered',
        width: '1.2fr',
        cell: (row) => (
            <span className="text-xs">{formatDateTime(row.ordered_at)}</span>
        ),
    },
    {
        key: 'quantity',
        label: 'Quantity',
        width: '90px',
        cell: (row) => (
            <span className="tabular-nums">
                {row.quantity_ordered ?? <EmptyValue />}
            </span>
        ),
    },
    {
        key: 'status',
        label: 'Status',
        width: '125px',
        cell: (row) => (
            <EntityStatusChip
                variant={
                    row.status === 'delivered'
                        ? 'success'
                        : row.status === 'cancelled'
                          ? 'neutral'
                          : 'info'
                }
            >
                {pharmacyStatus(row.status)}
            </EntityStatusChip>
        ),
    },
];

export const controlledColumns: EntityTableColumn<ControlledRegisterRow>[] = [
    {
        key: 'person',
        label: 'Person',
        width: '1.25fr',
        cell: (row) => <PersonCell name={row.client_name} />,
    },
    {
        key: 'balance',
        label: 'Stock balance',
        width: '145px',
        cell: (row) => (
            <span className="min-w-0 tabular-nums">
                <span className="block">
                    {row.on_hand === null
                        ? 'On hand unknown'
                        : `${row.on_hand} ${row.unit} on hand`}
                </span>
                <span className="block text-xs text-muted-foreground">
                    Last check:{' '}
                    {row.last_check_at === null
                        ? 'Not recorded'
                        : row.register_balance === null
                          ? 'Balance unknown'
                          : `${row.register_balance} ${row.unit}`}
                </span>
            </span>
        ),
    },
    {
        key: 'checked',
        label: 'Last check',
        width: '1.4fr',
        cell: (row) => (
            <span className="min-w-0">
                <span className="block text-xs">
                    {formatDateTime(row.last_check_at, 'Not recorded')}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {row.last_check_witness
                        ? `Recorded witness: ${row.last_check_witness}`
                        : 'Witness not recorded'}
                </span>
            </span>
        ),
    },
    {
        key: 'reconciliation',
        label: 'Check status',
        width: '155px',
        cell: (row) =>
            row.discrepancy !== null && row.discrepancy !== 0 ? (
                <EntityStatusChip variant="critical" icon={AlertTriangle}>
                    Discrepancy {row.discrepancy > 0 ? '+' : ''}
                    {row.discrepancy}
                </EntityStatusChip>
            ) : row.last_check_at === null ? (
                <EntityStatusChip variant="neutral" icon={Clock}>
                    Check not recorded
                </EntityStatusChip>
            ) : row.last_check_witness === null ||
              row.register_balance === null ? (
                <EntityStatusChip variant="warning" icon={AlertTriangle}>
                    Check incomplete
                </EntityStatusChip>
            ) : (
                <EntityStatusChip variant="neutral" icon={ClipboardCheck}>
                    Check recorded
                </EntityStatusChip>
            ),
    },
];

export function PharmacyOrderDetail({
    order,
    onClose,
    onAdvance,
    busy,
}: {
    order: PharmacyOrderRow;
    onClose: () => void;
    onAdvance?: () => void;
    busy: boolean;
}) {
    const stages = [
        ['Ordered', order.ordered_at],
        ['Submitted', order.submitted_at],
        ['Confirmed', order.confirmed_at],
        ['Dispensed', order.dispensed_at],
        ['Delivered', order.delivered_at],
    ];
    return (
        <SettingsModal
            width={720}
            title={`Pharmacy order · ${order.medication_name ?? 'Medication not recorded'}`}
            description={`${order.client_name} · ${order.pharmacy_name ?? 'Pharmacy not recorded'}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {onAdvance && PHARMACY_NEXT_LABEL[order.status] && (
                        <Button onClick={onAdvance} disabled={busy}>
                            {busy
                                ? 'Saving…'
                                : PHARMACY_NEXT_LABEL[order.status]}
                        </Button>
                    )}
                </>
            }
        >
            <div className="flex items-center gap-2">
                <EntityStatusChip
                    variant={
                        order.status === 'delivered' ? 'success' : 'neutral'
                    }
                >
                    {pharmacyStatus(order.status)}
                </EntityStatusChip>
                {order.order_type && (
                    <EntityStatusChip
                        variant={
                            order.order_type === 'urgent'
                                ? 'warning'
                                : 'neutral'
                        }
                    >
                        {order.order_type}
                    </EntityStatusChip>
                )}
                {order.controlled && (
                    <EntityStatusChip variant="neutral" icon={ShieldCheck}>
                        Controlled medicine
                    </EntityStatusChip>
                )}
            </div>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Quantity ordered
                    </dt>
                    <dd>{order.quantity_ordered ?? 'Not recorded'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Quantity received
                    </dt>
                    <dd>{order.quantity_received ?? 'Not recorded'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Batch</dt>
                    <dd>{order.batch_number || 'Not recorded'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Expiry</dt>
                    <dd>
                        {formatDateOnly(order.batch_expiry, 'Not recorded')}
                    </dd>
                </div>
            </dl>
            <ol className="divide-y rounded-lg border px-4">
                {stages.map(([label, at]) => (
                    <li
                        key={label}
                        className="flex items-center justify-between gap-4 py-3 text-sm"
                    >
                        <span className="font-medium">{label}</span>
                        <span className="text-muted-foreground">
                            {formatDateTime(at, 'Not recorded')}
                        </span>
                    </li>
                ))}
            </ol>
        </SettingsModal>
    );
}
