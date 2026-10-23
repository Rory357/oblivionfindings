import { EntityCard } from '@/components/lists/entity-card';
import { EntityChip, PersonCell } from '@/components/lists/entity-cells';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable, type EntityTableColumn } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { injuryHref } from '@/lib/health-safety/injury-navigation';
import { router } from '@inertiajs/react';
import { FileText, HeartPulse, History, Link2, Paperclip, Stethoscope } from 'lucide-react';
import { severityLabel, statusLabel } from './injury-constants';
import type { InjuryRow } from './injury-types';

export type InjuryRegisterRow = Omit<InjuryRow, 'rtw_count' | 'capacity_count' | 'attachment_count'> & {
    rtw_count: number | null; capacity_count: number | null; attachment_count: number | null;
    count_integrity?: Record<string, string>;
    source_integrity?: 'valid' | 'unknown' | 'repair_required';
};

/** Both list views share one canonical case identity and one permitted action menu. */
export function InjuryRegisterList({ rows, total, returnTo, medical }: {
    rows: InjuryRegisterRow[]; total: number; returnTo: string; medical: boolean;
}) {
    const context = useEntityContextMenu<InjuryRegisterRow>();
    const open = (row: InjuryRegisterRow, section: 'overview' | 'recovery' | 'capacity' | 'connections' | 'evidence' | 'history' = 'overview') => router.get(injuryHref(row.id, { section, returnTo }));
    const actions = (row: InjuryRegisterRow): MenuItem[] => [
        { label: 'Open injury', icon: FileText, onClick: () => open(row) },
        { label: 'Connected work', icon: Link2, onClick: () => open(row, 'connections') },
        ...(medical ? [
            { label: 'Recovery plans', icon: HeartPulse, onClick: () => open(row, 'recovery') },
            { label: 'Assessments', icon: Stethoscope, onClick: () => open(row, 'capacity') },
            { label: 'Evidence', icon: Paperclip, onClick: () => open(row, 'evidence') },
        ] : []),
        { label: 'History', icon: History, onClick: () => open(row, 'history') },
    ];
    const count = (value: number | null) => value === null ? 'Not confirmed' : String(value);
    const columns: EntityTableColumn<InjuryRegisterRow>[] = [
        { key: 'worker', label: 'Worker', width: '1.2fr', cell: (row) => <PersonCell name={row.worker?.name} /> },
        { key: 'site', label: 'Recorded Site', width: '1fr', cell: (row) => <EntityChip>{row.site?.name ?? 'Site unavailable'}</EntityChip> },
        { key: 'occurred', label: 'Occurred', width: '1.3fr', cell: (row) => <span className="text-subtle whitespace-normal">{formatDateTimeLong(row.injury_date)}</span> },
        { key: 'severity', label: 'Severity', width: '0.9fr', cell: (row) => <StatusBadge status={row.severity}>{severityLabel(row.severity)}</StatusBadge> },
        { key: 'status', label: 'Status', width: '1fr', cell: (row) => <StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge> },
        { key: 'lost', label: 'Days lost', width: '72px', cell: (row) => <EntityChip>{row.lost_time_days}</EntityChip> },
        ...(medical ? [{ key: 'recovery', label: 'Supporting records', width: '1.3fr', cell: (row: InjuryRegisterRow) => <span className="text-caption whitespace-normal">{count(row.rtw_count)} plans · {count(row.capacity_count)} assessments · {count(row.attachment_count)} files</span> }] : []),
    ];
    return <div className="space-y-5">
        <ListCaption title="Matching injuries" caption={`${rows.length} of ${total} shown`} />
        {rows.length === 0 ? <div className="rounded-xl border border-border bg-card p-5"><h2 className="text-section-title">No injuries match these filters</h2><p className="text-subtle mt-2">Change the filters to find another record.</p></div> : <>
            <ul className="grid gap-5 md:hidden" aria-label="Workplace injuries">{rows.map((row) => <li key={row.id}>
                <EntityCard meridian={row.source_integrity === 'repair_required' ? 'warning' : 'neutral'} icon={HeartPulse} name={row.reference} subline={`${row.injury_type_label} · ${row.site?.name ?? 'Site unavailable'}`}
                    href={injuryHref(row.id, { returnTo })} linkLabel={`Open injury ${row.reference}`} onOpen={() => open(row)}
                    className="[&_a]:inline-flex [&_a]:min-h-[44px] [&_a]:items-center [&_a]:focus-visible:ring-2 [&_a]:focus-visible:ring-ring"
                    actions={actions(row)} onContextMenu={(event) => context.open(event, row)}
                    chips={<><StatusBadge status={row.status}>{statusLabel(row.status)}</StatusBadge><StatusBadge status={row.severity}>{severityLabel(row.severity)}</StatusBadge><EntityChip>{row.lost_time_days} recorded days lost</EntityChip></>}
                    alerts={<>{row.worksafe_notifiable && <StatusBadge variant="warning">WorkSafe flag recorded</StatusBadge>}{row.source_integrity === 'repair_required' && <StatusBadge variant="warning">Source needs review</StatusBadge>}</>}
                    footer={{ personName: row.worker?.name, primary: row.worker?.name ?? 'Worker unavailable', secondary: formatDateTimeLong(row.injury_date) }} />
            </li>)}</ul>
            <div className="hidden md:block"><EntityTable rows={rows} rowKey={(row) => row.id} identityLabel="Injury" identityWidth="1.3fr" wrapIdentity minWidth={medical ? 1200 : 1000} rowHeight="content"
                identity={(row) => ({ icon: HeartPulse, name: row.reference, subline: row.injury_type_label, linkLabel: `Open injury ${row.reference}`, extra: <>{row.worksafe_notifiable && <StatusBadge variant="warning">WorkSafe flag</StatusBadge>}{row.source_integrity === 'repair_required' && <StatusBadge variant="warning">Source needs review</StatusBadge>}</> })}
                columns={columns} actionsFor={actions} onOpen={(row) => open(row)} hrefFor={(row) => injuryHref(row.id, { returnTo })} onRowContextMenu={(event, row) => context.open(event, row)} />
            </div>
        </>}
        {context.ctx && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} icon={HeartPulse} title={context.ctx.record.reference} items={actions(context.ctx.record)} onClose={context.close} />}
    </div>;
}
