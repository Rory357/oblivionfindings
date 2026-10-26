/**
 * Chemical record bodies (approved H&S drawings pg-hsn-126 … 126f — Stephan 23 Oct 2026): hazards as a story with
 * the signal word and classes as chips above a short identification list; safe use as a story with the PPE as a
 * chip and first aid in a box; sheets as a table; each storage location as a card read by its rings (labelled,
 * segregated, stock check); history as a log; private exposures as one box. The side column carries the storage
 * checks as a ring and who added the record.
 *
 * Substance safety wording (SDS, hazard statements, PPE, first aid, spill and fire measures, HSNO) and every caveat
 * keep the code's words; every command keeps its gate and opens the same form.
 */
import { RecordIdentity, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { FactRows, Log, Note, PeopleCards, QuietLine, Ring, RingCard, SectionHead, SideBySide, Story, Tally, type CheckItem, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import type { ChemicalArchivedStorageLocation, ChemicalDetail, ChemicalHistory, ChemicalHistoryItem, ChemicalSdsRecord, ChemicalStorageLocation } from '@/lib/health-safety/chemical-contract';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import type { ChemicalAction, ChemicalSection } from '@/lib/health-safety/chemical-navigation';
import { Archive, CircleAlert, ClipboardCheck, Edit, Eye, FileText, Hand, MapPin, Plus, TriangleAlert, Undo2 } from 'lucide-react';
import { useEffect, type CSSProperties, type ReactNode } from 'react';
import { chemicalSheetLabel, chemicalSheetTone } from './constants';

const text = (value: string | number | null | undefined) => value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
const bool = (value: boolean | null) => value === null ? 'Not recorded' : value ? 'Yes' : 'No';
const pre = (value: string | null | undefined) => <span className="whitespace-pre-wrap break-words">{text(value)}</span>;
/** "2 Sep 2026" for a timestamp, on the NZ calendar. */
const dayOf = (value?: string | null) => (value && toDateInput(value) ? formatDateOnly(toDateInput(value)) : 'Not recorded');
const quantity = (value: number | null, unit: string | null) => value === null ? null : `${value} ${unit ?? ''}`.trim();
type Open = (action: ChemicalAction, source?: number) => void;

const COMMAND_WORDS: Record<string, string> = {
    create_catalogue: 'Chemical added', update_catalogue: 'Chemical edited', change_status: 'Status changed', upload_sds: 'Safety data sheet added',
    correct_sds_metadata: 'Sheet details corrected', set_current_sds: 'Current sheet chosen', create_storage: 'Storage location added', update_storage: 'Storage location edited',
    audit_inventory: 'Stock check recorded', archive_storage: 'Storage location archived', restore_storage: 'Storage location restored',
};
const commandTone = (command: string): SgTone => (command.startsWith('create') || command === 'upload_sds' ? 'none' : command === 'set_current_sds' || command === 'audit_inventory' ? 'success' : command === 'archive_storage' ? 'warning' : 'info');
const historySub = (item: ChemicalHistoryItem) => [formatTime(item.recorded_at), item.actor.name, `Revision ${item.version}`, item.reason].filter(Boolean).join(' · ');

/** Recorded history as a log (the record's History tab and each storage location's own history). */
export function ChemicalHistoryPanel({ history, title = 'History', compact = false }: { history: ChemicalHistory | null; title?: string; compact?: boolean }) {
    if (!history || history.items === null) return <p role="status" className="text-subtle text-status-warning">Recorded history cannot currently be confirmed.</p>;
    const legacy = history.source_integrity === 'legacy_unknown' ? 'Earlier provenance is unconfirmed. Later recorded changes do not certify earlier facts.' : undefined;
    if (!history.items.length) return compact ? <p className="text-subtle">No accepted changes are available in this history.</p> : <QuietLine title={title} sub={legacy} text="No accepted changes are available in this history." />;
    if (compact) return <div className="grid gap-2">{legacy && <p className="text-subtle">{legacy}</p>}<ol className="grid gap-1.5">{history.items.map((item) => <li key={item.id} className="text-subtle">
        <b className="font-semibold text-foreground">{COMMAND_WORDS[item.command] ?? item.command.replaceAll('_', ' ')}</b> — {dayOf(item.recorded_at)} · {historySub(item)}</li>)}</ol></div>;
    return <Log title={title} sub={legacy} limit={history.items.length} items={history.items.map((item) => ({ key: item.id, time: dayOf(item.recorded_at), tone: commandTone(item.command),
        title: COMMAND_WORDS[item.command] ?? item.command.replaceAll('_', ' '), sub: historySub(item) }))} />;
}

/** Safety data sheets (pg-hsn-126c): one table; its kebab and right-click open, correct or choose the current sheet. */
export function ChemicalSdsPanel({ detail, open }: { detail: ChemicalDetail; open: Open }) {
    const rows = detail.sds_records ?? [];
    const damaged = (row: ChemicalSdsRecord) => row.source_integrity === 'repair_required';
    const name = (row: ChemicalSdsRecord) => damaged(row) ? 'Sheet source needs review' : `Sheet ${text(row.version)}`;
    const actions = (row: ChemicalSdsRecord): MenuItem[] => [
        ...(row.download_url && row.available ? [{ label: 'Open safety data sheet', icon: Eye, onClick: () => { window.location.assign(row.download_url!); } }] : []),
        ...(detail.can.manage && !damaged(row) && row.expected_version !== null ? [
            { label: 'Correct sheet details', icon: Edit, onClick: () => open('edit_sds', row.id) },
            { label: 'Select current sheet', icon: ClipboardCheck, onClick: () => open('current_sds', row.id) },
        ] : []),
    ];
    const columns: RecordTableColumn<ChemicalSdsRecord>[] = [
        { key: 'sheet', label: 'Safety data sheet', className: 'min-w-[16rem]', cell: (row) => <RecordIdentity title={name(row)} sub={damaged(row) ? 'Details withheld' : <>
            <span className="block">{[text(row.supplier_name), row.supplier_contact].filter(Boolean).join(' · ')}</span>
            <span className="block">{row.download_url && row.available
                ? <a href={row.download_url} onClick={(event) => event.stopPropagation()} className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">{text(row.file_name)}</a>
                : <>{text(row.file_name)}{row.available ? null : ' · file unavailable'}</>}{` · recorded by ${text(row.uploaded_by)}, ${dateTimeWithYear(row.created_at, 'time not recorded')}`}</span>
        </>} /> },
        { key: 'issued', label: 'Issued', nowrap: true, cell: (row) => damaged(row) ? <span className="text-muted-foreground">Withheld</span> : formatDateOnly(row.issue_date, 'Not recorded') },
        { key: 'review', label: 'Review date', nowrap: true, cell: (row) => damaged(row) ? <span className="text-muted-foreground">Withheld</span> : formatDateOnly(row.review_date, 'Not recorded') },
        { key: 'state', label: 'Status', nowrap: true, cell: (row) => <StatusBadge variant={chemicalSheetTone(row.state)}>{chemicalSheetLabel(row.state)}</StatusBadge> },
    ];
    return <div className="grid min-w-0 gap-5">
        {detail.truncated?.sds && <p role="status" className="text-subtle">Showing the first 100 admitted sheets, with records needing review first. The collection is larger.</p>}
        <RecordTable title="Safety data sheets" sub="A recorded sheet and review date support safe work. They do not certify suitability or compliance." ariaLabel="Safety data sheets"
            actions={detail.can.create ? <Button type="button" variant="outline" size="sm" onClick={() => open('add_sds')}><Plus className="size-4" /> Add safety data sheet</Button> : undefined}
            columns={columns} rows={rows} rowKey={(row) => row.id} actionsFor={actions} menuTitle={name} menuIcon={FileText}
            empty={<p className="sg-quiet px-4 py-6"><Ring tone="none" /><span>No admitted sheets are available in this record.</span></p>} />
    </div>;
}

type AnyStorage = ChemicalStorageLocation | ChemicalArchivedStorageLocation;
const checkTone = (value: boolean | null): SgTone => value === null ? 'warning' : value ? 'success' : 'critical';
/** Each storage location as a card read by its rings; the kebab and right-click carry its commands. */
export function ChemicalStoragePanel({ rows, archived = false, truncated = false, open }: { rows: AnyStorage[]; archived?: boolean; truncated?: boolean; open: Open }) {
    const context = useEntityContextMenu<AnyStorage>(); const { ctx, close } = context;
    const current = ctx ? rows.find((row) => row.id === ctx.record.id) : null;
    // A location that left the list (archived, restored, withdrawn) closes its open menu.
    useEffect(() => { if (ctx && !current) close(); }, [ctx, current, close]);
    const damaged = (row: AnyStorage) => row.source_integrity === 'repair_required';
    const name = (row: AnyStorage) => damaged(row) ? 'Storage source needs review' : text(row.location_description);
    const actions = (row: AnyStorage): MenuItem[] => row.can.manage && !damaged(row) && row.expected_version !== null ? archived ?
        [{ label: 'Restore storage location', icon: Undo2, onClick: () => open('restore_storage', row.id) }] :
        [{ label: 'Edit storage location', icon: Edit, onClick: () => open('edit_storage', row.id) }, { label: 'Record inventory check', icon: ClipboardCheck, onClick: () => open('audit_storage', row.id) },
            { label: 'Archive storage location', icon: Archive, onClick: () => open('archive_storage', row.id) }] : [];
    const facts = (row: AnyStorage): { tone: SgTone; text: ReactNode }[] => {
        const held = quantity(row.current_quantity, row.quantity_unit); const max = quantity(row.maximum_quantity, row.quantity_unit);
        return [
            held ? { tone: 'brand', text: <><b>{held}</b>{max ? ` of ${max} maximum` : ' · maximum not recorded'}</> } : { tone: 'warning', text: `Quantity not known${max ? ` · ${max} maximum` : ''}` },
            { tone: checkTone(row.properly_labelled), text: row.properly_labelled === null ? 'Labelling not checked' : row.properly_labelled ? 'Labelled correctly' : 'Not labelled correctly' },
            { tone: checkTone(row.segregation_compliant), text: row.segregation_compliant === null ? 'Segregation not checked' : row.segregation_compliant ? 'Segregated correctly' : 'Not segregated correctly' },
            row.last_audit_date ? { tone: 'none', text: `Stock checked ${formatDateOnly(row.last_audit_date)}` } : { tone: 'warning', text: 'No stock check yet' },
            ...(row.storage_notes ? [{ tone: 'none' as SgTone, text: <span className="whitespace-pre-wrap break-words">{row.storage_notes}</span> }] : []),
            ...(archived ? [{ tone: 'none' as SgTone, text: `Archived ${'archived_at' in row ? dateTimeWithYear(row.archived_at, 'not confirmed') : 'not confirmed'}` }] : []),
        ];
    };
    return <div className="grid min-w-0 gap-3">
        {truncated && <p role="status" className="text-subtle">Showing the first 100 admitted locations, with records needing review first. The collection is larger.</p>}
        {!rows.length ? <p className="sg-quiet"><Ring tone="none" /><span>No admitted {archived ? 'archived ' : ''}storage locations are available.</span></p> :
            <ul className="sg-cards" style={{ '--cols': 3 } as CSSProperties}>{rows.map((row) => {
                const items = actions(row);
                return <li key={row.id} className="sg-surface sg-place relative pr-10" onContextMenu={items.length ? (event) => context.open(event, row) : undefined}>
                    <div className="sg-ph"><div>
                        <b>{name(row)}</b>
                        <small>{[row.site.name, damaged(row) ? null : row.container_type].filter(Boolean).join(' · ')}</small>
                        {damaged(row) || archived ? <span className="sg-flag"><StatusBadge variant={damaged(row) ? 'warning' : 'neutral'}>{damaged(row) ? 'Source needs review' : 'Archived'}</StatusBadge></span> : null}
                    </div></div>
                    {!damaged(row) && <div className="sg-facts2 sg-facts2--lines">{facts(row).map((fact, index) => <span key={index}><Ring tone={fact.tone} /><span>{fact.text}</span></span>)}</div>}
                    {!damaged(row) && <details className="text-subtle"><summary className="cursor-pointer rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">Location history</summary>
                        <div className="mt-2"><ChemicalHistoryPanel history={row.history} compact /></div></details>}
                    {items.length ? <EntityKebab actions={items} label={`Actions for ${name(row)}`} className="absolute top-3 right-2" /> : null}
                </li>;
            })}</ul>}
        {ctx && current && <EntityContextMenu x={ctx.x} y={ctx.y} title={name(current)} icon={MapPin} items={actions(current)} onClose={close} />}
    </div>;
}

/** Side column: the storage checks as a ring (as last recorded), then who added the record. */
export function ChemicalRecordSide({ detail, section, onStorage }: { detail: ChemicalDetail; section: ChemicalSection; onStorage: () => void }) {
    if (detail.source_integrity === 'repair_required') return null;
    const rows = (detail.storage_locations ?? []).filter((row) => row.source_integrity !== 'repair_required');
    const checks: CheckItem[] = rows.map((row) => {
        const flags = [row.properly_labelled, row.segregation_compliant];
        const state: SgTone = flags.includes(false) ? 'critical' : flags.includes(null) ? 'warning' : 'success';
        const note = flags.every((flag) => flag === true) ? 'Labelled and segregated'
            : [row.properly_labelled === false ? 'Not labelled correctly' : null, row.segregation_compliant === false ? 'Not segregated correctly' : null].filter(Boolean).join(' · ') || 'Not checked';
        return { label: `${text(row.location_description)} · ${row.site.name}`, state, note };
    });
    const ring = !['storage', 'exposures'].includes(section)
        ? rows.length ? <RingCard value={checks.filter((check) => check.state === 'success').length} of={rows.length} label="Storage checks" sub="As last recorded" action={{ label: 'Storage', onClick: onStorage }} checks={checks} />
            : <QuietLine title="Storage checks" text="No storage locations are recorded." action={{ label: 'Storage', onClick: onStorage }} />
        : null;
    return <>
        {ring}
        <PeopleCards title="Record" cols={1} items={[{ name: detail.created_by ?? 'Recorder not confirmed', sub: 'Added it',
            foot: `Last updated ${dateTimeWithYear(detail.updated_at, 'not recorded')} · status reason: ${detail.status_reason ?? 'none given'}` }]} />
    </>;
}

export function ChemicalRecordPanel({ detail, section, open }: { detail: ChemicalDetail; section: ChemicalSection; sourceHref?: string; open: Open }) {
    if (detail.source_integrity === 'repair_required') return <div role="status"><Note tone="warning">Source history needs review. Saved details and changes are withheld until they can be confirmed.</Note></div>;
    if (section === 'exposures') return <Note icon={CircleAlert}><b>Private worker exposures</b> — Identifiable exposure records require current wellbeing and safety access. This public reference does not include exposure records or counts.</Note>;
    if (section === 'sds') return <ChemicalSdsPanel detail={detail} open={open} />;
    if (section === 'storage') return <>
        <section className="grid min-w-0 gap-3"><SectionHead title="Storage locations" sub="Quantities and checks are recorded facts. Unknown quantities remain unconfirmed."
            action={detail.can.create ? { label: 'Add storage location', onClick: () => open('add_storage') } : null} />
            <ChemicalStoragePanel rows={detail.storage_locations} truncated={detail.truncated.storage} open={open} /></section>
        <section className="grid min-w-0 gap-3"><SectionHead title="Archived storage locations" />
            {detail.archived_storage.items === null ? <p role="status" className="text-subtle text-status-warning">Archived source history cannot be confirmed.</p> :
                <ChemicalStoragePanel archived rows={detail.archived_storage.items} truncated={detail.archived_storage.truncated === true} open={open} />}</section>
    </>;
    if (section === 'history') return <ChemicalHistoryPanel history={detail.history} />;
    if (section === 'controls') return <>
        <Story title="Safe use" facts={[{ icon: Hand, text: detail.ppe_required ? <span className="whitespace-pre-wrap">PPE required: {detail.ppe_required}</span> : 'PPE required: not recorded' }]}
            paras={[{ h: 'Precautionary statements', t: pre(detail.precautionary_statements) }, { h: 'Handling precautions', t: pre(detail.handling_precautions) }, { h: 'Storage requirements', t: pre(detail.storage_requirements) }]} />
        <div role="note" className="sg-note sg-note--critical"><TriangleAlert aria-hidden="true" className="size-4" /><div className="min-w-0"><b>First aid measures</b> — {pre(detail.first_aid_measures)}</div></div>
        <SideBySide cols="minmax(0, 1.5fr) minmax(0, 1fr)">
            <Story title="If it spills or there’s a fire" paras={[{ h: 'Spill procedures', t: pre(detail.spill_procedures) }, { h: 'Firefighting measures', t: pre(detail.firefighting_measures) }]} />
            {detail.exposure_limit_value || detail.exposure_limit_type
                ? <Tally title="Exposure limit" keepZeros items={[{ label: text(detail.exposure_limit_type), value: text(detail.exposure_limit_value) }]} />
                : <QuietLine title="Exposure limit" text="Exposure limit type and value not recorded." />}
        </SideBySide>
    </>;
    return <>
        {detail.source_integrity === 'legacy_unknown' && <div role="status"><Note tone="warning">Earlier provenance is unconfirmed. A later change does not certify the original reference.</Note></div>}
        <Story title="Hazards" sub="Reference information does not certify that a substance is safe for a particular use."
            facts={[{ icon: TriangleAlert, text: detail.signal_word ? <>Signal word <b>{detail.signal_word}</b></> : 'Signal word not recorded' },
                ...(detail.hazard_classifications?.length ? detail.hazard_classifications.map((item) => ({ icon: CircleAlert, text: item })) : [{ icon: CircleAlert, text: 'Hazard classifications not recorded' }])]}
            paras={[{ h: 'Hazard statements', t: pre(detail.hazard_statements) }, { h: 'Hazard pictograms (GHS)', t: detail.ghs_pictograms?.join(' · ') || 'Not recorded' }]} />
        <FactRows title="Identification" rows={[['Common name', text(detail.common_name)], ['UN number', text(detail.un_number)], ['HSNO approval', text(detail.hsno_approval)],
            ['HSNO classification', text(detail.hsno_classification)], ['Controlled substance', bool(detail.is_controlled_substance)], ['Tracking required', bool(detail.requires_tracking)]]} />
    </>;
}
