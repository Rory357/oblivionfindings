import {
    EmptyValue,
    EntityStatusChip,
    PersonCell,
} from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateOnly } from '@/lib/datetime';
import type { StockRow } from '@/pages/emar/_stock-dialogs';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Package,
    Snowflake,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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

/** The parent keys this list by filters so a changed scope starts on page one. */
export function StockInventoryTable({
    rows,
    actionsFor,
    onOpen,
}: {
    rows: StockRow[];
    actionsFor: (row: StockRow) => MenuItem[];
    onOpen: (row: StockRow) => void;
}) {
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [context, setContext] = useState<{
        x: number;
        y: number;
        row: StockRow;
    } | null>(null);
    const sorted = useMemo(
        () =>
            [...rows].sort(
                (a, b) =>
                    a.client_name.localeCompare(b.client_name) ||
                    (a.medication_name ?? '').localeCompare(
                        b.medication_name ?? '',
                    ) ||
                    a.id - b.id,
            ),
        [rows],
    );
    const lastPage = Math.max(1, Math.ceil(sorted.length / pageSize));
    const currentPage = Math.min(page, lastPage);
    const start = (currentPage - 1) * pageSize;
    const shown = sorted.slice(start, start + pageSize);
    const pageControls = (
        <nav aria-label="Stock pages" className="flex items-center gap-2">
            <Button
                size="sm"
                variant="outline"
                disabled={currentPage === 1}
                onClick={() => setPage(currentPage - 1)}
                aria-label="Previous stock page"
            >
                <ChevronLeft className="size-3.5" />
                Previous
            </Button>
            <span className="text-xs text-muted-foreground tabular-nums">
                Page {currentPage} of {lastPage}
            </span>
            <Button
                size="sm"
                variant="outline"
                disabled={currentPage === lastPage}
                onClick={() => setPage(currentPage + 1)}
                aria-label="Next stock page"
            >
                Next
                <ChevronRight className="size-3.5" />
            </Button>
        </nav>
    );
    return (
        <section aria-label="Medication stock" className="grid min-w-0 gap-5">
            <ListCaption
                title="Stock"
                caption={
                    <span role="status">
                        {rows.length ? start + 1 : 0}–{start + shown.length} of{' '}
                        {rows.length} {rows.length === 1 ? 'item' : 'items'}
                    </span>
                }
                right={pageControls}
            />
            <EntityTable
                rows={shown}
                rowKey={(row) => row.id}
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
                onRowContextMenu={(event, row) => {
                    event.preventDefault();
                    setContext({ x: event.clientX, y: event.clientY, row });
                }}
            />
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span
                        id="stock-page-size-label"
                        className="text-xs text-muted-foreground"
                    >
                        Items per page
                    </span>
                    <Select
                        value={String(pageSize)}
                        onValueChange={(value) => {
                            setPageSize(Number(value));
                            setPage(1);
                        }}
                    >
                        <SelectTrigger
                            aria-labelledby="stock-page-size-label"
                            className="h-8 w-20"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {[10, 25, 50].map((size) => (
                                <SelectItem key={size} value={String(size)}>
                                    {size}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {pageControls}
            </div>
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={`${context.row.medication_name ?? 'Stock'} · ${context.row.client_name}`}
                    icon={Package}
                    items={actionsFor(context.row)}
                    onClose={() => setContext(null)}
                />
            )}
        </section>
    );
}
