import { EntityCard, EntityContextMenu, EntityTable, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import type { ChemicalDetail, ChemicalHistory, ChemicalSdsRecord, ChemicalStorageLocation } from '@/lib/health-safety/chemical-contract';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { ChemicalAction, ChemicalSection } from '@/lib/health-safety/chemical-navigation';
import { Archive, ClipboardCheck, Edit, Eye, FileText, LockKeyhole, MapPin, Shield, Undo2 } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';

const text = (value: string | number | null | undefined) => value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
const bool = (value: boolean | null) => value === null ? 'Not recorded' : value ? 'Yes' : 'No';
export function ChemicalFacts({ items }: { items: [string, ReactNode][] }) {
    return <dl className="grid gap-4 sm:grid-cols-2">{items.map(([label, value]) => <div key={label} className="min-w-0 space-y-1"><dt className="text-caption text-muted-foreground">{label}</dt><dd className="whitespace-pre-wrap break-words text-subtle">{value ?? 'Not recorded'}</dd></div>)}</dl>;
}
export function ChemicalHistoryPanel({ history }: { history: ChemicalHistory | null }) {
    if (!history || history.items === null) return <p role="status" className="text-subtle text-status-warning">Recorded history cannot currently be confirmed.</p>;
    return <div className="space-y-4">{history.source_integrity === 'legacy_unknown' && <p className="text-subtle">Earlier provenance is unconfirmed. Later recorded changes do not certify earlier facts.</p>}
        {!history.items.length ? <p className="text-subtle">No accepted changes are available in this history.</p> : <ol className="space-y-4">{history.items.map((item) => <li key={item.id} className="space-y-1 border-b border-border pb-4 last:border-0">
            <h3 className="text-section-title">{item.command.replaceAll('_', ' ')}</h3><p className="text-subtle">{item.actor.name} · {formatDateTime(item.recorded_at)} · Revision {item.version}</p>
            {item.reason && <p className="whitespace-pre-wrap break-words text-subtle">{item.reason}</p>}
        </li>)}</ol>}</div>;
}
export function ChemicalSdsPanel({ detail, sourceHref, open }: { detail: ChemicalDetail; sourceHref: string; open: (action: ChemicalAction, source?: number) => void }) {
    const rows = detail.sds_records ?? []; const context = useEntityContextMenu<ChemicalSdsRecord>();
    const current = context.ctx ? rows.find((row) => row.id === context.ctx?.record.id) : null;
    useEffect(() => { if (context.ctx && !current) context.close(); }, [context.ctx, current, context.close]);
    const actions = (row: ChemicalSdsRecord): MenuItem[] => [
        ...(row.download_url && row.available ? [{ label: 'Open safety data sheet', icon: Eye, onClick: () => { window.location.assign(row.download_url!); } }] : []),
        ...(detail.can.manage && row.source_integrity !== 'repair_required' && row.expected_version !== null ? [
            { label: 'Correct sheet details', icon: Edit, onClick: () => open('edit_sds', row.id) },
            { label: 'Select current sheet', icon: ClipboardCheck, onClick: () => open('current_sds', row.id) },
        ] : []),
    ];
    const state = (row: ChemicalSdsRecord) => <StatusBadge variant={['current', 'superseded'].includes(row.state) ? 'neutral' : 'warning'}>{row.state.replaceAll('_', ' ')}</StatusBadge>;
    return <div className="space-y-4"><p className="text-subtle">A recorded sheet and review date support safe work. They do not certify suitability or compliance.</p>
        {detail.can.create && <Button type="button" className="frontline-tap" onClick={() => open('add_sds')}>Add safety data sheet</Button>}
        {detail.truncated?.sds && <p role="status" className="text-subtle">Showing the first 100 admitted sheets, with records needing review first. The collection is larger.</p>}
        {!rows.length ? <p className="text-subtle">No admitted sheets are available in this record.</p> : <>
            <ul className="space-y-3 md:hidden">{rows.map((row) => <li key={row.id}><EntityCard icon={FileText} name={row.source_integrity === 'repair_required' ? 'Sheet source needs review' : `Sheet ${text(row.version)}`}
                href={sourceHref} subline={row.source_integrity === 'repair_required' ? 'Details withheld' : `${text(row.supplier_name)} · review ${formatDateOnly(row.review_date, 'Not recorded')}`}
                actions={actions(row)} alerts={state(row)} onContextMenu={(event) => context.open(event, row)} /></li>)}</ul>
            <div className="hidden md:block"><EntityTable rows={rows} rowKey={(row) => row.id} identityLabel="Safety data sheet" identity={(row) => ({ icon: FileText,
                name: row.source_integrity === 'repair_required' ? 'Sheet source needs review' : `Sheet ${text(row.version)}`, subline: row.source_integrity === 'repair_required' ? 'Details withheld' : text(row.supplier_name) })}
                columns={[{ key: 'issue', label: 'Issued', width: '1fr', cell: (row) => formatDateOnly(row.issue_date, 'Not recorded') },
                    { key: 'review', label: 'Review date', width: '1fr', cell: (row) => formatDateOnly(row.review_date, 'Not recorded') },
                    { key: 'state', label: 'Recorded state', width: '1fr', cell: state }]} actionsFor={actions} onRowContextMenu={(event, row) => context.open(event, row)} /></div>
        </>}{context.ctx && current && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} title={current.source_integrity === 'repair_required' ? 'Sheet source needs review' : `Sheet ${text(current.version)}`}
            icon={FileText} items={actions(current)} onClose={context.close} />}
        {rows.filter((row) => row.source_integrity !== 'repair_required').map((row) => <details key={row.id} className="rounded-lg border border-border bg-card p-4">
            <summary className="frontline-tap cursor-pointer text-subtle focus-visible:ring-2 focus-visible:ring-ring">Sheet {text(row.version)} details</summary>
            <div className="mt-4"><ChemicalFacts items={[
                ['Sheet version', text(row.version)], ['Issue date', formatDateOnly(row.issue_date, 'Not recorded')], ['Review date', formatDateOnly(row.review_date, 'Not recorded')],
                ['Supplier name', text(row.supplier_name)], ['Supplier contact', text(row.supplier_contact)], ['File', text(row.file_name)],
                ['Recorded by', text(row.uploaded_by)], ['Recorded at', formatDateTime(row.created_at, 'Not recorded')],
            ]} />{row.download_url && row.available && <Button asChild variant="outline" className="mt-4 frontline-tap"><a href={row.download_url}>Open safety data sheet</a></Button>}</div>
        </details>)}
    </div>;
}
type ArchivedStorage = ChemicalStorageLocation & { archived_at: string | null };
export function ChemicalStoragePanel({ rows, archived = false, truncated = false, sourceHref, open }: {
    rows: (ChemicalStorageLocation | ArchivedStorage)[]; archived?: boolean; truncated?: boolean; sourceHref: string; open: (action: ChemicalAction, source?: number) => void;
}) {
    const context = useEntityContextMenu<ChemicalStorageLocation>(); const current = context.ctx ? rows.find((row) => row.id === context.ctx?.record.id) : null;
    useEffect(() => { if (context.ctx && !current) context.close(); }, [context.ctx, current, context.close]);
    const actions = (row: ChemicalStorageLocation): MenuItem[] => row.can.manage && row.source_integrity !== 'repair_required' && row.expected_version !== null ? archived ?
        [{ label: 'Restore storage location', icon: Undo2, onClick: () => open('restore_storage', row.id) }] :
        [{ label: 'Edit storage location', icon: Edit, onClick: () => open('edit_storage', row.id) }, { label: 'Record inventory check', icon: ClipboardCheck, onClick: () => open('audit_storage', row.id) },
            { label: 'Archive storage location', icon: Archive, onClick: () => open('archive_storage', row.id) }] : [];
    return <div className="space-y-4">{truncated && <p role="status" className="text-subtle">Showing the first 100 admitted locations, with records needing review first. The collection is larger.</p>}
        {!rows.length ? <p className="text-subtle">No admitted {archived ? 'archived ' : ''}storage locations are available.</p> : <ul className="space-y-4">{rows.map((row) => <li key={row.id} className="space-y-3">
            <EntityCard icon={MapPin} name={row.source_integrity === 'repair_required' ? 'Storage source needs review' : text(row.location_description)} href={sourceHref} subline={row.site.name}
                actions={actions(row)} alerts={<StatusBadge variant={row.source_integrity === 'repair_required' ? 'warning' : 'neutral'}>{row.source_integrity === 'repair_required' ? 'Source needs review' : archived ? 'Archived' : 'Recorded inventory'}</StatusBadge>}
                onContextMenu={(event) => context.open(event, row)} />
            {row.source_integrity !== 'repair_required' && <div className="rounded-lg border border-border bg-card p-4"><ChemicalFacts items={[
                ['Recorded quantity', row.current_quantity === null ? 'Not recorded' : `${row.current_quantity} ${row.quantity_unit ?? ''}`], ['Maximum quantity', row.maximum_quantity === null ? 'Not recorded' : `${row.maximum_quantity} ${row.quantity_unit ?? ''}`],
                ['Container type', text(row.container_type)], ['Last inventory check', formatDateOnly(row.last_audit_date, 'Not recorded')], ['Properly labelled', bool(row.properly_labelled)], ['Segregation compliant', bool(row.segregation_compliant)],
                ['Storage notes', text(row.storage_notes)], ...(archived ? [['Archived', formatDateTime((row as ArchivedStorage).archived_at, 'Not confirmed')] as [string, ReactNode]] : []),
            ]} /><details className="mt-4"><summary className="frontline-tap cursor-pointer text-subtle focus-visible:ring-2 focus-visible:ring-ring">Location history</summary><div className="mt-3"><ChemicalHistoryPanel history={row.history} /></div></details></div>}
        </li>)}</ul>}{context.ctx && current && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} title={current.source_integrity === 'repair_required' ? 'Storage source needs review' : text(current.location_description)} icon={MapPin} items={actions(current)} onClose={context.close} />}</div>;
}
export function ChemicalRecordPanel({ detail, section, sourceHref, open }: { detail: ChemicalDetail; section: ChemicalSection; sourceHref: string; open: (action: ChemicalAction, source?: number) => void }) {
    if (detail.source_integrity === 'repair_required') return <p role="status" className="text-subtle text-status-warning">Source history needs review. Saved details and changes are withheld until they can be confirmed.</p>;
    if (section === 'exposures') return <div className="space-y-3"><LockKeyhole aria-hidden className="size-6 text-muted-foreground" /><h2 className="text-section-title">Private worker exposures</h2>
        <p className="text-subtle">Identifiable exposure records require current wellbeing and safety access. This public reference does not include exposure records or counts.</p></div>;
    if (section === 'sds') return <ChemicalSdsPanel detail={detail} sourceHref={sourceHref} open={open} />;
    if (section === 'storage') return <div className="space-y-5"><p className="text-subtle">Quantities and checks are recorded facts. Unknown quantities remain unconfirmed.</p>
        {detail.can.create && <Button type="button" className="frontline-tap" onClick={() => open('add_storage')}>Add storage location</Button>}
        <ChemicalStoragePanel rows={detail.storage_locations} sourceHref={sourceHref} truncated={detail.truncated.storage} open={open} />
        <section className="space-y-4"><h2 className="text-section-title">Archived storage locations</h2>
            {detail.archived_storage.items === null ? <p role="status" className="text-subtle text-status-warning">Archived source history cannot be confirmed.</p> :
                <ChemicalStoragePanel archived rows={detail.archived_storage.items} truncated={detail.archived_storage.truncated === true} sourceHref={sourceHref} open={open} />}</section>
    </div>;
    if (section === 'history') return <ChemicalHistoryPanel history={detail.history} />;
    if (section === 'controls') return <ChemicalFacts items={[
        ['Precautionary statements', text(detail.precautionary_statements)], ['PPE required', text(detail.ppe_required)], ['Storage requirements', text(detail.storage_requirements)], ['Handling precautions', text(detail.handling_precautions)],
        ['First aid measures', text(detail.first_aid_measures)], ['Firefighting measures', text(detail.firefighting_measures)], ['Spill procedures', text(detail.spill_procedures)], ['Exposure limit type', text(detail.exposure_limit_type)], ['Exposure limit value', text(detail.exposure_limit_value)],
    ]} />;
    return <div className="space-y-5">{detail.source_integrity === 'legacy_unknown' && <p role="status" className="text-subtle text-status-warning">Earlier provenance is unconfirmed. A later change does not certify the original reference.</p>}
        <ChemicalFacts items={[
            ['Chemical name', text(detail.name)], ['Common name', text(detail.common_name)], ['Physical form', text(detail.physical_form)], ['UN number', text(detail.un_number)],
            ['HSNO approval', text(detail.hsno_approval)], ['HSNO classification', text(detail.hsno_classification)], ['Signal word', text(detail.signal_word)], ['Hazard classifications', detail.hazard_classifications?.join(', ') || 'Not recorded'],
            ['GHS pictograms', detail.ghs_pictograms?.join(', ') || 'Not recorded'], ['Hazard statements', text(detail.hazard_statements)], ['Controlled substance', bool(detail.is_controlled_substance)], ['Tracking required', bool(detail.requires_tracking)],
            ['Reference status', text(detail.status)], ['Status reason', text(detail.status_reason)], ['Recorded by', text(detail.created_by)], ['Last updated', formatDateTime(detail.updated_at, 'Not recorded')],
        ]} />
        <p className="text-subtle"><Shield aria-hidden className="mr-1 inline size-4" />Reference information does not certify that a substance is safe for a particular use.</p>
        {detail.can.manage && <div className="flex flex-wrap gap-2"><Button type="button" className="frontline-tap" variant="outline" onClick={() => open('edit')}>Edit chemical reference</Button><Button type="button" className="frontline-tap" variant="outline" onClick={() => open('status')}>Change reference status</Button></div>}
    </div>;
}
