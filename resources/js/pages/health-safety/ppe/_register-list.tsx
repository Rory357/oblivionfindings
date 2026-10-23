import { EntityCard, type EntityMeridian } from '@/components/lists/entity-card';
import { EntityChip, PersonCell } from '@/components/lists/entity-cells';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable, type EntityTableColumn } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { ppeHref, type PpeSection } from '@/lib/health-safety/ppe-navigation';
import { router } from '@inertiajs/react';
import { ClipboardCheck, FileText, HardHat, History, Package, Pencil, Power, UserRound, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { CatalogueCommand, CatalogueDisplayRow, CatalogueRow } from './_catalogue-state';
import { catLabel, condLabel, statusLabel, statusTone, type AllocationRow, type InventoryRow } from './ppe-shared';

type NullableFacts<T> = { [K in keyof T]: T[K] | null };
export type InventoryDisplayRow = NullableFacts<InventoryRow> & { id: number; source_integrity?: 'intact' | 'legacy_unknown' | 'repair_required' };
export type AllocationDisplayRow = NullableFacts<AllocationRow> & { id: number; source_integrity?: 'intact' | 'legacy_unknown' | 'repair_required'; returned_at?: string | null };
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
export function PpeInventoryList({ rows, total, returnTo, view }: { rows: InventoryDisplayRow[]; total: number; returnTo: string; view: 'cards' | 'table' }) {
    const href = (row: InventoryDisplayRow, section?: PpeSection) => ppeHref('inventory', row.id, { returnTo, section });
    const fact = (row: InventoryDisplayRow, value: ReactNode) => damaged(row) ? 'Not confirmed' : value;
    const actions = (row: InventoryDisplayRow): MenuItem[] => [{ label: 'Open equipment', icon: Package, onClick: () => router.get(href(row)) },
        ...(!damaged(row) ? [{ label: 'Issues and returns', icon: UserRound, onClick: () => router.get(href(row, 'allocations')) },
            { label: 'Inspections', icon: ClipboardCheck, onClick: () => router.get(href(row, 'inspections')) },
            { label: 'Evidence', icon: FileText, onClick: () => router.get(href(row, 'evidence')) }, { label: 'History', icon: History, onClick: () => router.get(href(row, 'history')) }] : [])];
    const columns: EntityTableColumn<InventoryDisplayRow>[] = [
        { key: 'site', label: 'Site · storage', width: '1.2fr', cell: (row) => fact(row, <span className="text-subtle whitespace-normal">{row.site?.name ?? 'Site unavailable'}<br />{row.location ?? 'Storage not recorded'}</span>) },
        { key: 'state', label: 'Service state', width: '1fr', cell: (row) => fact(row, <StatusBadge variant={statusTone(row.status)}>{statusLabel(row.status)}</StatusBadge>) },
        { key: 'quantity', label: 'Recorded quantity', width: '100px', cell: (row) => fact(row, row.quantity ?? 'Not recorded') },
        { key: 'condition', label: 'Condition', width: '0.8fr', cell: (row) => fact(row, condLabel(row.condition)) },
        { key: 'expiry', label: 'Expiry', width: '1fr', cell: (row) => fact(row, formatDateOnly(row.expiry_date)) },
        { key: 'inspection', label: 'Next inspection', width: '1fr', cell: (row) => fact(row, formatDateOnly(row.next_inspection_due)) },
    ];
    return <PpeRows rows={rows} total={total} title="Matching equipment records" icon={Package} view={view} href={href} actions={actions} columns={columns}
        name={(row) => damaged(row) ? `Equipment ${row.id} · source needs review` : row.ppe_type?.name ?? `Equipment ${row.id}`}
        subline={(row) => damaged(row) ? 'Saved facts withheld pending repair' : [row.brand, row.model, row.serial_number].filter(Boolean).join(' · ') || 'No identifying details recorded'}
        chips={(row) => damaged(row) ? null : <><StatusBadge variant={statusTone(row.status)}>{statusLabel(row.status)}</StatusBadge><EntityChip>{catLabel(row.ppe_type?.category)}</EntityChip><EntityChip>{row.quantity ?? 'Unknown'} recorded quantity</EntityChip><EntityChip>{condLabel(row.condition)}</EntityChip></>}
        alerts={(row) => <>{repair(row)}{!damaged(row) && <span className="text-caption whitespace-normal">Expiry: {formatDateOnly(row.expiry_date)} · Next inspection: {formatDateOnly(row.next_inspection_due)}</span>}</>}
        footer={(row) => ({ primary: damaged(row) ? 'Facts not confirmed' : row.site?.name ?? 'Site unavailable', secondary: damaged(row) ? 'Open to review source status' : row.location ?? 'Storage not recorded' })}
        meridian={(row) => damaged(row) || row.status === 'maintenance' || row.status === 'condemned' ? 'warning' : 'neutral'} />;
}
export function PpeAllocationList({ rows, total, returnTo, view }: { rows: AllocationDisplayRow[]; total: number; returnTo: string; view: 'cards' | 'table' }) {
    const href = (row: AllocationDisplayRow, section?: PpeSection) => ppeHref('allocations', row.id, { returnTo, section });
    const fact = (row: AllocationDisplayRow, value: ReactNode) => damaged(row) ? 'Not confirmed' : value;
    const actions = (row: AllocationDisplayRow): MenuItem[] => [{ label: 'Open PPE issue', icon: UserRound, onClick: () => router.get(href(row)) },
        ...(!damaged(row) ? [{ label: 'Fit and training facts', icon: ClipboardCheck, onClick: () => router.get(href(row, 'fit-training')) },
            { label: 'Evidence', icon: FileText, onClick: () => router.get(href(row, 'evidence')) }, { label: 'History', icon: History, onClick: () => router.get(href(row, 'history')) }] : [])];
    const columns: EntityTableColumn<AllocationDisplayRow>[] = [
        { key: 'worker', label: 'Worker', width: '1.2fr', cell: (row) => fact(row, <PersonCell name={row.user?.name} />) },
        { key: 'site', label: 'Site', width: '1fr', cell: (row) => fact(row, row.inventory_item?.site?.name ?? 'Site unavailable') },
        { key: 'issued', label: 'Issued', width: '1.2fr', cell: (row) => fact(row, formatDateTimeLong(row.allocated_at)) },
        { key: 'ack', label: 'Acknowledgement', width: '1fr', cell: (row) => fact(row, <StatusBadge variant={row.acknowledged ? 'neutral' : 'warning'}>{row.acknowledged ? 'Recorded' : 'Awaiting acknowledgement'}</StatusBadge>) },
        { key: 'fit', label: 'Fit test · training', width: '1.3fr', cell: (row) => fact(row, <span className="text-caption whitespace-normal">Fit completion {row.fit_test_completed ? 'recorded' : 'not recorded'} · Training completion {row.training_completed ? 'recorded' : 'not recorded'}<br />Current suitability is not confirmed here.</span>) },
    ];
    return <PpeRows rows={rows} total={total} title="Matching PPE issues" icon={UserRound} view={view} href={href} actions={actions} columns={columns}
        name={(row) => damaged(row) ? `PPE issue ${row.id} · source needs review` : row.ppe_type?.name ?? `PPE issue ${row.id}`}
        subline={(row) => damaged(row) ? 'Saved facts withheld pending repair' : row.inventory_item?.serial_number ?? 'Serial number not recorded'}
        chips={(row) => damaged(row) ? null : <><StatusBadge variant={row.acknowledged ? 'neutral' : 'warning'}>{row.acknowledged ? 'Acknowledgement recorded' : 'Awaiting acknowledgement'}</StatusBadge><EntityChip>Fit completion {row.fit_test_completed ? 'recorded' : 'not recorded'}</EntityChip><EntityChip>Training completion {row.training_completed ? 'recorded' : 'not recorded'}</EntityChip></>}
        alerts={(row) => <>{repair(row)}{!damaged(row) && row.ppe_type?.category === 'respiratory' && !row.fit_test_completed && <StatusBadge variant="warning">Fit-test completion not recorded</StatusBadge>}</>}
        footer={(row) => ({ personName: damaged(row) ? null : row.user?.name, primary: damaged(row) ? 'Facts not confirmed' : row.user?.name ?? 'Worker unavailable', secondary: damaged(row) ? 'Open to review source status' : formatDateTimeLong(row.allocated_at) })}
        meridian={(row) => damaged(row) || !row.acknowledged || (row.ppe_type?.category === 'respiratory' && !row.fit_test_completed) ? 'warning' : 'neutral'} />;
}
export function PpeCatalogueList({ rows, view, onCommand }: { rows: CatalogueDisplayRow[]; view: 'cards' | 'table'; onCommand: (command: CatalogueCommand, type: CatalogueRow) => void }) {
    const href = (row: CatalogueDisplayRow) => `/health-safety/ppe?tab=types&ppe_type_id=${row.id}&view=cards`;
    const actions = (row: CatalogueDisplayRow): MenuItem[] => [{ label: 'View catalogue facts', icon: HardHat, onClick: () => router.get(href(row)) },
        ...(row.source_integrity !== 'repair_required' && row.can.manage ? [{ label: 'Edit catalogue type', icon: Pencil, onClick: () => onCommand('update_type', row) },
            { label: row.is_active ? 'Retire catalogue type' : 'Activate catalogue type', icon: Power, onClick: () => onCommand(row.is_active ? 'deactivate_type' : 'activate_type', row) }] : [])];
    const facts = (row: CatalogueDisplayRow) => row.source_integrity === 'repair_required' ? 'Not confirmed' : <span className="text-caption whitespace-normal">
        {row.description ?? 'Description not recorded'}<br />Hazards: {row.hazards_addressed ?? 'Not recorded'}<br />Standards reference: {row.standards_reference ?? 'Not recorded'}<br />
        Inspection frequency: {row.inspection_frequency ?? 'Not recorded'} · Typical lifespan: {row.typical_lifespan_months == null ? 'Not recorded' : `${row.typical_lifespan_months} months`}</span>;
    return <PpeRows rows={rows} total={rows.length} title="Organisation catalogue types" icon={HardHat} view={view} href={href} actions={actions}
        name={(row) => row.name ?? `Catalogue type ${row.id} · source needs review`} subline={(row) => row.source_integrity === 'repair_required' ? 'Saved facts withheld pending repair' : catLabel(row.category)}
        columns={[{ key: 'state', label: 'Catalogue state', width: '1fr', cell: (row) => row.source_integrity === 'repair_required' ? 'Not confirmed' : <StatusBadge variant={row.is_active ? 'neutral' : 'warning'}>{row.is_active ? 'Active' : 'Retired'}</StatusBadge> },
            { key: 'facts', label: 'Recorded catalogue facts', width: '3fr', cell: facts }, { key: 'stock', label: 'Inventory records', width: '1fr', cell: (row) => row.inventory_count == null ? `Not confirmed · ${row.physical_inventory_count} saved rows` : `${row.inventory_count} at approved Sites` }]}
        chips={(row) => row.source_integrity === 'repair_required' ? null : <><StatusBadge variant={row.is_active ? 'neutral' : 'warning'}>{row.is_active ? 'Active' : 'Retired'}</StatusBadge><EntityChip>{row.inventory_count == null ? `Inventory facts not confirmed · ${row.physical_inventory_count} saved rows` : `${row.inventory_count} inventory records`}</EntityChip></>}
        alerts={(row) => <>{repair(row)}{facts(row)}</>} footer={(row) => ({ primary: 'Organisation reference catalogue', secondary: row.source_integrity === 'repair_required' ? 'Source needs repair' : 'Recorded references do not certify equipment' })}
        meridian={(row) => row.source_integrity === 'repair_required' ? 'warning' : 'neutral'} />;
}
