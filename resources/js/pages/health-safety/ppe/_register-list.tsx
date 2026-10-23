/**
 * PPE register tables (approved H&S drawings pg-hsn-120, 120b, 120c — Stephan 23 Oct 2026): equipment, equipment
 * issued and the catalogue, each as the drawn table block (`RecordTable`). Every row opens its record; its kebab and
 * right-click carry the same sections. Rows whose saved source needs repair stay listed with their facts withheld
 * and their actions limited to opening the record.
 *
 * `PpeRows` (card or table list) stays for the Site profile's PPE tab.
 */
import { EntityCard, type EntityMeridian } from '@/components/lists/entity-card';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable, type EntityTableColumn } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { ppeHref, type PpeSection } from '@/lib/health-safety/ppe-navigation';
import { router } from '@inertiajs/react';
import { ClipboardCheck, FileText, HardHat, History, Package, Pencil, Plus, Power, UserRound, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { CatalogueCommand, CatalogueDisplayRow, CatalogueRow } from './_catalogue-state';
import { catIcon, catLabel, condLabel, statusLabel, statusTone, type AllocationRow, type InventoryRow } from './ppe-shared';

type NullableFacts<T> = { [K in keyof T]: T[K] | null };
type Currency = { expiry_state: 'unknown' | 'expired' | 'in_date'; inspection_state: 'unknown' | 'overdue' | 'scheduled' };
export type InventoryDisplayRow = NullableFacts<InventoryRow> & { id: number; source_integrity?: 'intact' | 'legacy_unknown' | 'repair_required'; currency?: Currency | null };
export type AllocationDisplayRow = NullableFacts<AllocationRow> & { id: number; source_integrity?: 'intact' | 'legacy_unknown' | 'repair_required'; returned_at?: string | null };

/** Card or table list — kept for the Site profile's PPE tab (`pages/sites/tabs/ppe.tsx`). */
export function PpeRows<T extends { id: number }>({ rows, total, title, caption, icon: Icon, view, name, subline, href, actions, columns, chips, alerts, footer, meridian }: {
    rows: T[]; total: number; title: string; caption?: string; icon: LucideIcon; view: 'cards' | 'table'; name: (row: T) => string; subline: (row: T) => ReactNode;
    href: (row: T) => string; actions: (row: T) => MenuItem[]; columns: EntityTableColumn<T>[];
    chips: (row: T) => ReactNode; alerts: (row: T) => ReactNode; footer: (row: T) => { personName?: string | null; primary: ReactNode; secondary?: ReactNode };
    meridian: (row: T) => EntityMeridian;
}) {
    const context = useEntityContextMenu<T>();
    const open = (row: T) => router.get(href(row));
    return <div className="space-y-5"><ListCaption title={title} caption={caption ?? `${rows.length} of ${total} shown`} />
        {rows.length === 0 ? <div className="rounded-xl border border-border bg-card p-5"><h2 className="text-section-title">No records match these filters</h2><p className="text-subtle mt-2">Change the filters to find another record.</p></div> : <>
            <ul className={view === 'cards' ? 'grid gap-5 md:grid-cols-2 xl:grid-cols-3' : 'grid gap-5 md:hidden'} aria-label={title}>{rows.map((row) => <li key={row.id}><EntityCard icon={Icon} meridian={meridian(row)} name={name(row)} subline={subline(row)}
                href={href(row)} linkLabel={`Open ${name(row)}`} onOpen={() => open(row)} actions={actions(row)} onContextMenu={(event) => context.open(event, row)}
                chips={chips(row)} alerts={alerts(row)} footer={footer(row)} className="[&_a]:inline-flex [&_a]:min-h-[44px] [&_a]:items-center [&_a]:focus-visible:ring-2 [&_a]:focus-visible:ring-ring" /></li>)}</ul>
            {view === 'table' && <div className="hidden md:block"><EntityTable rows={rows} rowKey={(row) => row.id} identityLabel="Record" identityWidth="1.5fr" wrapIdentity rowHeight="content" minWidth={1100}
                identity={(row) => ({ icon: Icon, name: name(row), subline: subline(row), extra: alerts(row), linkLabel: `Open ${name(row)}` })} columns={columns}
                actionsFor={actions} onOpen={open} hrefFor={href} onRowContextMenu={(event, row) => context.open(event, row)} /></div>}
        </>}{context.ctx && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} icon={Icon} title={name(context.ctx.record)} items={actions(context.ctx.record)} onClose={context.close} />}
    </div>;
}

const damaged = (row: { source_integrity?: string }) => row.source_integrity === 'repair_required';
const repair = (row: { source_integrity?: string }) => damaged(row) ? <StatusBadge variant="warning">Source needs review</StatusBadge> : row.source_integrity === 'legacy_unknown' ? <StatusBadge variant="neutral">Earlier history not confirmed</StatusBadge> : null;
const withheld = <span className="text-muted-foreground">Not confirmed</span>;
const none = <span className="text-muted-foreground">—</span>;
const EMPTY = <p role="status" className="sg-quiet px-4 py-6"><span>No records match these filters. Change the filters to find another record.</span></p>;
/** The register says "Issued" for equipment out with a worker (the tab is "Issued equipment"). */
const serviceLabel = (status: string | null) => (status === 'allocated' ? 'Issued' : statusLabel(status));
const tone = (status: string | null): StatusVariant => statusTone(status);
/** Whole NZ calendar days from today to a saved date (YYYY-MM-DD); null when there is no valid date. */
function daysAhead(day: string | null): number | null {
    if (!day || !/^\d{4}-\d{2}-\d{2}$/.test(day)) return null;
    const today = toDateInput(new Date());
    if (!today) return null;
    const days = Math.round((Date.parse(`${day}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86_400_000);
    return Number.isFinite(days) ? days : null;
}
/** A date that needs someone: the date in its status badge, a short note under it. */
const dueDate = (day: string, variant: StatusVariant, note: string) => (
    <span className="inline-grid justify-items-start gap-0.5">
        <StatusBadge variant={variant}>{formatDateOnly(day)}</StatusBadge>
        <span className="text-caption text-muted-foreground">{note}</span>
    </span>
);
/** Expired (server) reads red; in date but within the Expiring view's 60 days reads amber. */
function expiryCell(row: InventoryDisplayRow): ReactNode {
    if (!row.expiry_date) return none;
    if (row.currency?.expiry_state === 'expired') return dueDate(row.expiry_date, 'critical', 'Expired');
    const ahead = daysAhead(row.expiry_date);
    if (ahead !== null && ahead <= 60) return dueDate(row.expiry_date, 'warning', 'Expires soon');
    return formatDateOnly(row.expiry_date);
}
function inspectionCell(row: InventoryDisplayRow): ReactNode {
    if (!row.next_inspection_due) return none;
    if (row.currency?.inspection_state === 'overdue') return dueDate(row.next_inspection_due, 'critical', 'Overdue');
    return formatDateOnly(row.next_inspection_due);
}
const icon = (category: string | null | undefined) => {
    const Icon = catIcon(category);
    return <Icon aria-hidden="true" className="size-4" />;
};

/** Equipment (pg-hsn-120): one row per inventory record. */
export function PpeInventoryTable({ rows, total, returnTo, footer }: { rows: InventoryDisplayRow[]; total: number; returnTo: string; footer: ReactNode }) {
    const href = (row: InventoryDisplayRow, section?: PpeSection) => ppeHref('inventory', row.id, { returnTo, section });
    const name = (row: InventoryDisplayRow) => damaged(row) ? `Equipment ${row.id} · source needs review` : row.ppe_type?.name ?? `Equipment ${row.id}`;
    const actions = (row: InventoryDisplayRow): MenuItem[] => [{ label: 'Open equipment', icon: Package, onClick: () => router.get(href(row)) },
        ...(!damaged(row) ? [{ label: 'Issues and returns', icon: UserRound, onClick: () => router.get(href(row, 'allocations')) },
            { label: 'Inspections', icon: ClipboardCheck, onClick: () => router.get(href(row, 'inspections')) },
            { label: 'Evidence', icon: FileText, onClick: () => router.get(href(row, 'evidence')) }, { label: 'History', icon: History, onClick: () => router.get(href(row, 'history')) }] : [])];
    const fact = (row: InventoryDisplayRow, value: () => ReactNode) => damaged(row) ? withheld : value();
    const columns: RecordTableColumn<InventoryDisplayRow>[] = [
        { key: 'item', label: 'Item', className: 'min-w-[15rem]', cell: (row) => <div className="grid gap-1">
            <RecordIdentity leading={icon(damaged(row) ? null : row.ppe_type?.category)} title={name(row)}
                sub={damaged(row) ? 'Saved facts withheld pending repair' : [row.brand, row.model, row.serial_number].filter(Boolean).join(' · ') || 'No identifying details recorded'} />
            {repair(row) ? <span className="pl-10">{repair(row)}</span> : null}</div> },
        { key: 'site', label: 'Site and storage', nowrap: true, cell: (row) => fact(row, () => <RecordIdentity title={row.site?.name ?? 'Site unavailable'} sub={row.location ?? 'Storage not recorded'} />) },
        { key: 'status', label: 'Status', nowrap: true, cell: (row) => fact(row, () => <StatusBadge variant={tone(row.status)}>{serviceLabel(row.status)}</StatusBadge>) },
        { key: 'quantity', label: 'Quantity', nowrap: true, cell: (row) => fact(row, () => row.quantity ?? 'Not recorded') },
        { key: 'condition', label: 'Condition', nowrap: true, cell: (row) => fact(row, () => condLabel(row.condition)) },
        { key: 'expiry', label: 'Expiry', nowrap: true, cell: (row) => fact(row, () => expiryCell(row)) },
        { key: 'inspection', label: 'Next inspection', nowrap: true, cell: (row) => fact(row, () => inspectionCell(row)) },
    ];
    return <RecordTable title="Equipment" sub={`Showing ${rows.length} of ${total} · right-click a row for issues, returns, inspections, files or history`}
        ariaLabel="Matching equipment records" columns={columns} rows={rows} rowKey={(row) => row.id} onOpen={(row) => router.get(href(row))}
        actionsFor={actions} menuTitle={name} menuIcon={Package} empty={EMPTY} footer={footer} />;
}

/** Equipment issued (pg-hsn-120b): open issues, oldest acknowledgement gaps visible. */
export function PpeAllocationTable({ rows, total, returnTo, footer }: { rows: AllocationDisplayRow[]; total: number; returnTo: string; footer: ReactNode }) {
    const href = (row: AllocationDisplayRow, section?: PpeSection) => ppeHref('allocations', row.id, { returnTo, section });
    const name = (row: AllocationDisplayRow) => damaged(row) ? `PPE issue ${row.id} · source needs review` : row.ppe_type?.name ?? `PPE issue ${row.id}`;
    const actions = (row: AllocationDisplayRow): MenuItem[] => [{ label: 'Open PPE issue', icon: UserRound, onClick: () => router.get(href(row)) },
        ...(!damaged(row) ? [{ label: 'Fit and training facts', icon: ClipboardCheck, onClick: () => router.get(href(row, 'fit-training')) },
            { label: 'Evidence', icon: FileText, onClick: () => router.get(href(row, 'evidence')) }, { label: 'History', icon: History, onClick: () => router.get(href(row, 'history')) }] : [])];
    const fact = (row: AllocationDisplayRow, value: () => ReactNode) => damaged(row) ? withheld : value();
    const fitTraining = (row: AllocationDisplayRow) => {
        if (row.ppe_type?.category === 'respiratory' && !row.fit_test_completed) {
            return <span className="inline-flex flex-wrap items-center gap-1.5"><StatusBadge variant="warning">No fit test on record</StatusBadge>
                <span className="text-caption text-muted-foreground">{row.training_completed ? 'Training recorded' : 'No training on record'}</span></span>;
        }
        if (!row.fit_test_completed && !row.training_completed) return 'No fit test or training on record';
        return `${row.fit_test_completed ? 'Fit test recorded' : 'No fit test on record'} · ${row.training_completed ? 'Training recorded' : 'No training on record'}`;
    };
    const columns: RecordTableColumn<AllocationDisplayRow>[] = [
        { key: 'item', label: 'Item', className: 'min-w-[14rem]', cell: (row) => <div className="grid gap-1">
            <RecordIdentity leading={icon(damaged(row) ? null : row.ppe_type?.category)} title={name(row)}
                sub={damaged(row) ? 'Saved facts withheld pending repair' : row.inventory_item?.serial_number ?? 'Serial number not recorded'} />
            {repair(row) ? <span className="pl-10">{repair(row)}</span> : null}</div> },
        { key: 'worker', label: 'Worker', nowrap: true, cell: (row) => fact(row, () => row.user?.name ? <RecordPerson name={row.user.name} /> : <span className="text-muted-foreground">Worker unavailable</span>) },
        { key: 'site', label: 'Site', nowrap: true, cell: (row) => fact(row, () => row.inventory_item?.site?.name ?? 'Site unavailable') },
        { key: 'issued', label: 'Issued', nowrap: true, cell: (row) => fact(row, () => row.allocated_at ? <RecordIdentity title={formatDateOnly(toDateInput(row.allocated_at))} sub={formatTime(row.allocated_at)} /> : 'Not recorded') },
        { key: 'ack', label: 'Acknowledged', nowrap: true, cell: (row) => fact(row, () => <StatusBadge variant={row.acknowledged ? 'success' : 'warning'}>{row.acknowledged ? 'Yes' : 'Not yet'}</StatusBadge>) },
        { key: 'fit', label: 'Fit test · training', cell: (row) => fact(row, () => fitTraining(row)) },
    ];
    return <RecordTable title="Equipment issued" sub={`Showing ${rows.length} of ${total} · Current suitability is not confirmed here · right-click a row for fit, training, files or history`}
        ariaLabel="Matching PPE issues" columns={columns} rows={rows} rowKey={(row) => row.id} onOpen={(row) => router.get(href(row))}
        actionsFor={actions} menuTitle={name} menuIcon={UserRound} empty={EMPTY} footer={footer} />;
}

const FREQUENCY: Record<string, string> = { daily: 'Inspect daily', weekly: 'Inspect weekly', monthly: 'Inspect monthly', quarterly: 'Inspect quarterly', annually: 'Inspect yearly' };

/** Equipment types (pg-hsn-120c): the organisation catalogue. */
export function PpeCatalogueTable({ rows, canAdd, onCommand, onAdd }: { rows: CatalogueDisplayRow[]; canAdd: boolean; onCommand: (command: CatalogueCommand, type: CatalogueRow) => void; onAdd: () => void }) {
    const href = (row: CatalogueDisplayRow) => `/health-safety/ppe?tab=types&ppe_type_id=${row.id}`;
    const broken = (row: CatalogueDisplayRow): row is Extract<CatalogueDisplayRow, { source_integrity: 'repair_required' }> => row.source_integrity === 'repair_required';
    const name = (row: CatalogueDisplayRow) => row.name ?? `Catalogue type ${row.id} · source needs review`;
    const actions = (row: CatalogueDisplayRow): MenuItem[] => [{ label: 'View catalogue facts', icon: HardHat, onClick: () => router.get(href(row)) },
        ...(!broken(row) && row.can.manage ? [{ label: 'Edit catalogue type', icon: Pencil, onClick: () => onCommand('update_type', row) },
            { label: row.is_active ? 'Retire catalogue type' : 'Activate catalogue type', icon: Power, onClick: () => onCommand(row.is_active ? 'deactivate_type' : 'activate_type', row) }] : [])];
    const details = (row: CatalogueRow) => <RecordIdentity title={row.description ?? 'Description not recorded'}
        sub={[`Hazards: ${row.hazards_addressed ?? 'not recorded'}`, row.standards_reference ?? 'Standards reference not recorded',
            row.inspection_frequency ? FREQUENCY[row.inspection_frequency] ?? `Inspect ${row.inspection_frequency}` : 'Inspection interval not recorded',
            row.typical_lifespan_months == null ? 'Lifespan not recorded' : `Lasts about ${row.typical_lifespan_months} months`].join(' · ')} />;
    const columns: RecordTableColumn<CatalogueDisplayRow>[] = [
        { key: 'type', label: 'Type', className: 'min-w-[13rem]', cell: (row) => <div className="grid gap-1">
            <RecordIdentity leading={icon(broken(row) ? null : row.category)} title={name(row)} sub={broken(row) ? 'Saved facts withheld pending repair' : catLabel(row.category)} />
            {repair(row) ? <span className="pl-10">{repair(row)}</span> : null}</div> },
        { key: 'status', label: 'Status', nowrap: true, cell: (row) => broken(row) ? withheld : <StatusBadge variant={row.is_active ? 'neutral' : 'warning'}>{row.is_active ? 'Active' : 'Retired'}</StatusBadge> },
        { key: 'details', label: 'Details', className: 'min-w-[20rem]', cell: (row) => broken(row) ? withheld : details(row) },
        { key: 'stock', label: 'In stock', nowrap: true, cell: (row) => row.inventory_count == null ? `Not confirmed · ${row.physical_inventory_count} saved rows` : `${row.inventory_count} at your sites` },
    ];
    return <RecordTable title="Equipment types" sub={`${rows.length} ${rows.length === 1 ? 'type' : 'types'} · Shared across the organisation · Recorded references do not certify equipment`}
        ariaLabel="Organisation catalogue types" columns={columns} rows={rows} rowKey={(row) => row.id} onOpen={(row) => router.get(href(row))}
        actions={canAdd ? <Button variant="outline" size="sm" onClick={onAdd}><Plus className="size-4" /> Add type</Button> : undefined}
        actionsFor={actions} menuTitle={name} menuIcon={HardHat} empty={EMPTY} />;
}
