/**
 * Private exposure record sections (approved H&S drawings pg-hsn-129b … 129e — Stephan 23 Oct 2026): reviews,
 * follow-ups and history read as logs (time first, a ring for state), evidence as a private file table whose kebab
 * and right-click open or remove a file. Overview and connections live in `exposure-overview.tsx`.
 *
 * Health information: nothing shows unless the root record is admitted with intact integrity; repaired children
 * stay withheld; every privacy, provenance, delivery and access line keeps the code's wording; every command keeps
 * its current server flag and opens the same inline form.
 */
import { RecordIdentity, RecordPerson, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { Note, Ring, Surface, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { formatDateOnly, formatDateTime, formatTime, toDateInput } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES, type ExposureAction, type ExposurePageKey, type ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureCollection, ExposureEvidence, ExposureFollowup, ExposureHistoryFacts, ExposureRepair, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { ArrowRightLeft, CheckCircle2, Download, FileText, Info, LockKeyhole, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useRef, type MouseEvent, type ReactNode, type Ref } from 'react';
import { ExposureConnections, ExposureOverview } from './exposure-overview';

const text = (value: string | number | null | undefined) => value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
const label = (value: string) => { const words = value.replaceAll('_', ' '); return words.charAt(0).toUpperCase() + words.slice(1); };
/** "2 Sep 2026" for a timestamp, on the NZ calendar. */
const dayOf = (value?: string | null) => (value && toDateInput(value) ? formatDateOnly(toDateInput(value)) : 'Not recorded');
const dot = <span aria-hidden="true"> · </span>;
function Facts({ items }: { items: [string, ReactNode][] }) {
    return <dl className="sg-kv">{items.map(([name, value]) => <Fragment key={name}><dt>{name}</dt><dd className="whitespace-pre-wrap break-words">{value}</dd></Fragment>)}</dl>;
}
/** A collection's integrity notes, its rows, and its pages — the totals stay unknown when the server says so. */
function Collection({ collection, children, pageKey, onPage, inset = true }: { collection: ExposureCollection<unknown>; children: ReactNode;
    pageKey: ExposurePageKey; onPage: (key: ExposurePageKey, page: number) => void; /** Inside a surface the notes and pages take its inner padding. */ inset?: boolean }) {
    const pad = inset ? 'px-[18px]' : '';
    if (collection.items === null) return <div role="status" className={inset ? 'px-[18px] py-3' : undefined}><Note tone="warning">These saved records cannot currently be confirmed. Details are withheld.</Note></div>;
    const page = collection.page;
    return <>
        {collection.source_integrity === 'repair_required' && <div role="status" className={inset ? 'px-[18px] pt-3' : undefined}><Note tone="warning">Some sources need review. Their details and the collection total are unconfirmed.</Note></div>}
        {collection.source_integrity === 'legacy_unknown' && <div className={inset ? 'px-[18px] pt-3' : undefined}><Note icon={Info}>Earlier provenance is unconfirmed. Later changes do not certify earlier facts.</Note></div>}
        {children}
        {page && (page.last_page === null || page.last_page > 1 || page.current_page > 1) && <nav aria-label="Recorded collection pages" className={`flex flex-wrap items-center gap-3 ${pad} ${inset ? 'pb-4' : ''}`}>
            <Button type="button" variant="outline" size="sm" disabled={page.current_page <= 1} onClick={() => onPage(pageKey, page.current_page - 1)}>Previous page</Button>
            <p className="text-caption">Page {page.current_page}{page.last_page === null ? ' · total unconfirmed' : ` of ${page.last_page}`}</p>
            <Button type="button" variant="outline" size="sm" disabled={!page.has_more} onClick={() => onPage(pageKey, page.current_page + 1)}>Next page</Button>
        </nav>}
    </>;
}
const REPAIR = 'This source needs review. Saved details and changes are withheld.';
function Repair() { return <div role="status"><Note tone="warning">{REPAIR}</Note></div>; }
const repaired = (row: { attribution_state: string }): row is ExposureRepair => row.attribution_state === 'repair_required';

/** One log row (the Log block's shape) that can carry a focus target, visible actions and a menu. */
function LogRow({ time, tone, title, sub, children, actions, menu, menuTitle, selected = false, focusRef, onContextMenu }: {
    time: ReactNode; tone?: SgTone; title: ReactNode; sub?: ReactNode; children?: ReactNode; actions?: ReactNode; menu?: MenuItem[]; menuTitle?: string;
    selected?: boolean; focusRef?: Ref<HTMLLIElement>; onContextMenu?: (event: MouseEvent<HTMLElement>) => void;
}) {
    return <li ref={focusRef} tabIndex={selected ? -1 : undefined} aria-label={selected ? 'Selected follow-up' : undefined} onContextMenu={menu?.length ? onContextMenu : undefined}
        className={selected ? 'sg-lr rounded-md bg-primary/5 ring-2 ring-ring outline-none' : 'sg-lr'}>
        <time>{time}</time>{tone ? <Ring tone={tone} /> : <span />}
        <div className="grid min-w-0 gap-1"><b>{title}</b>{sub ? <small>{sub}</small> : null}{children}</div>
        <span className="inline-flex items-center gap-3">{actions}{menu?.length ? <EntityKebab actions={menu} label={`Actions for ${menuTitle ?? 'this entry'}`} /> : null}</span>
    </li>;
}
const act = (labelText: string, onClick: () => void) => (
    // eslint-disable-next-line no-restricted-syntax -- the kit's quiet text action (.sg-act) beside a log row, not a form button.
    <button type="button" className="sg-act ml-0" onClick={onClick}>{labelText}</button>
);

const FACT_LABELS: Record<keyof ExposureHistoryFacts, string> = {
    user_id: 'Worker reference', site_id: 'Site reference', exposed_at: 'Exposure time', exposure_type: 'Exposure route',
    exposure_duration: 'Recorded duration', circumstances: 'Circumstances', symptoms: 'Reported symptoms', first_aid_given: 'First aid given',
    medical_attention_sought: 'Medical attention sought', medical_treatment: 'Recorded treatment', medical_outcome: 'Medical outcome',
    incident_reported: 'Incident reported', related_incident_id: 'Related incident reference',
};
function HistoryFacts({ facts }: { facts: ExposureHistoryFacts | null }) {
    if (facts === null) return <p className="text-subtle">No earlier snapshot was retained.</p>;
    return <Facts items={Object.entries(FACT_LABELS).filter(([key]) => Object.prototype.hasOwnProperty.call(facts, key)).map(([key, name]) => {
        const value = facts[key as keyof ExposureHistoryFacts];
        const retained = typeof value === 'boolean' ? value ? 'Yes' : 'No' : key === 'exposed_at' && typeof value === 'string'
            ? formatDateTime(value, 'Not recorded') : text(value);
        return [name, ['medical_attention_sought', 'medical_treatment', 'incident_reported'].includes(key)
            ? `Saved value: ${retained}` : retained] as [string, ReactNode];
    })} />;
}
const HISTORY_WORDS: Record<string, string> = { create_exposure: 'Exposure reported', correct_exposure: 'Facts corrected', review: 'Review added', record_review: 'Review added',
    assign_followup: 'Follow-up assigned', reassign_followup: 'Follow-up transferred', complete_followup: 'Follow-up completed', upload_evidence: 'Evidence added', remove_evidence: 'Evidence removed' };
const historyTone = (command: string): SgTone => (command.includes('complete') ? 'success' : command.includes('review') ? 'warning' : command.startsWith('create') ? 'none' : 'info');

/** The record's sections. All commands retain independent current server admission. */
export function ExposureRecordPanel({ detail, section, open, onPage, focusedFollowupId = null }: { detail: ExposureWorkspaceDetail; section: ExposureSection;
    open: (action: ExposureAction, source?: number) => void; onPage: (key: ExposurePageKey, page: number) => void; focusedFollowupId?: number | null }) {
    const admitted = detail.exposure_access === 'readable' && detail.source_integrity !== 'repair_required' && !!detail.record
        && detail.record.id === detail.id && detail.record.attribution_state !== 'repair_required';
    const selectedId = admitted && section === 'followups' && detail.followup_focus_state === 'available'
        && detail.focused_followup_id === focusedFollowupId && detail.followups.items?.some((row) => row.id === focusedFollowupId && row.attribution_state === 'recorded')
        ? focusedFollowupId : null;
    const selectedRow = useRef<HTMLLIElement>(null);
    const followupMenu = useEntityContextMenu<ExposureFollowup>();
    useEffect(() => { if (selectedId !== null) selectedRow.current?.focus(); }, [detail.id, selectedId]);
    if (!admitted) return <Repair />;
    const context = detail.command_context;
    const commandReady = context?.root_type === 'exposure' && context.root_id === detail.id
        && Number.isSafeInteger(context.expected_version) && context.expected_version >= 0;
    if (section === 'overview') return <ExposureOverview detail={detail} />;
    if (section === 'connections') return <ExposureConnections detail={detail} />;

    if (section === 'reviews') return <>
        <Surface title="Reviews" sub="A recorded review is separate from the reported facts and from any medical outcome."
            action={commandReady && detail.can.review ? { label: 'Add review', onClick: () => open('review') } : null}>
            <Collection collection={detail.reviews} pageKey="reviews_page" onPage={onPage}>
                {detail.reviews.items?.length ? <ol className="sg-log">{detail.reviews.items.map((row) => repaired(row)
                    ? <LogRow key={row.id} time="—" tone="warning" title={REPAIR} />
                    : <LogRow key={row.id} time={dayOf(row.reviewed_at)} tone={/follow/.test(row.outcome) ? 'warning' : 'success'} title={label(row.outcome)}
                        sub={<>{row.reviewed_at ? formatTime(row.reviewed_at) : 'Time not recorded'}{dot}{row.recorded_by?.name ?? 'Reviewer not recorded'}{dot}<span className="whitespace-pre-wrap">{text(row.notes)}</span>
                            {row.attribution_state === 'legacy_unknown' ? <>{dot}Earlier provenance unconfirmed</> : null}</>} />)}</ol>
                    : <p className="sg-quiet"><Ring tone="none" /><span>No admitted records are available on this page.</span></p>}
            </Collection>
        </Surface>
    </>;

    if (section === 'followups') {
        const actionsFor = (row: ExposureFollowup): MenuItem[] => [
            ...(commandReady && row.can_complete && row.completed_at === null ? [{ label: 'Record completion', icon: CheckCircle2, onClick: () => open('complete_followup', row.id) }] : []),
            ...(commandReady && row.can_reassign && row.completed_at === null ? [{ label: 'Transfer follow-up', icon: ArrowRightLeft, onClick: () => open('reassign_followup', row.id) }] : []),
        ];
        const now = Date.now();
        return <Surface title="Follow-ups" sub="The named assignee is responsible for this follow-up. Recording an assignment does not confirm that a notification was delivered."
            action={commandReady && detail.can.assign_followup ? { label: 'Assign a follow-up', onClick: () => open('assign_followup') } : null}>
            <Collection collection={detail.followups} pageKey="followups_page" onPage={onPage}>
                {detail.followups.items?.length ? <ol className="sg-log">{detail.followups.items.map((row) => {
                    if (repaired(row)) return <LogRow key={row.id} time="—" tone="warning" title={REPAIR} />;
                    const done = row.completed_at !== null; const overdue = !done && !!row.due_at && new Date(row.due_at).getTime() < now;
                    const items = actionsFor(row);
                    return <LogRow key={row.id} selected={row.id === selectedId} focusRef={row.id === selectedId ? selectedRow : undefined}
                        time={done ? dayOf(row.completed_at) : row.due_at ? dayOf(row.due_at) : 'No due date'} tone={done ? (row.completion_attribution_state === 'recorded' ? 'success' : 'warning') : overdue ? 'critical' : 'warning'}
                        title={<span className="whitespace-pre-wrap">{row.instruction}</span>}
                        sub={<><span>{row.assigned_to?.name ?? 'Not recorded'}</span>{dot}due {dateTimeWithYear(row.due_at, 'not recorded')}{dot}
                            <span>{!done ? 'Completion not recorded' : row.completion_attribution_state === 'recorded' ? 'Completion recorded' : 'Earlier completion unconfirmed'}</span>
                            {dot}assigned by {row.recorded_by?.name ?? 'not recorded'}
                            {done ? <>{dot}done {dateTimeWithYear(row.completed_at, 'not recorded')} by {row.completed_by?.name ?? 'not recorded'}</> : null}
                            {row.completion_notes ? <>{dot}<span className="whitespace-pre-wrap">{row.completion_notes}</span></> : null}
                            {row.id === selectedId ? <>{dot}Selected follow-up</> : null}</>}
                        actions={items.map((item) => <Fragment key={item.label}>{act(item.label ?? '', item.onClick!)}</Fragment>)} menu={items} menuTitle={row.instruction}
                        onContextMenu={(event) => followupMenu.open(event, row)}>
                        {row.assignment_attribution_state !== 'recorded' && <small className="text-status-warning">Earlier assignment history is unconfirmed. A recorded completion does not confirm that earlier assignment.</small>}
                    </LogRow>;
                })}</ol> : <p className="sg-quiet"><Ring tone="none" /><span>No admitted records are available on this page.</span></p>}
            </Collection>
            {followupMenu.ctx && <EntityContextMenu x={followupMenu.ctx.x} y={followupMenu.ctx.y} icon={CheckCircle2} title={followupMenu.ctx.record.instruction} items={actionsFor(followupMenu.ctx.record)} onClose={followupMenu.close} />}
        </Surface>;
    }

    if (section === 'evidence') {
        const rows = detail.attachments.items ?? [];
        const download = (row: ExposureEvidence) => row.href === `${CHEMICAL_EXPOSURES}/${detail.id}/evidence/${row.id}/download` ? row.href : null;
        const usable = (row: ExposureEvidence | ExposureRepair): row is ExposureEvidence => !repaired(row) && row.attribution_state === 'recorded';
        const actions = (row: ExposureEvidence | ExposureRepair): MenuItem[] => !usable(row) ? [] : [
            ...(row.available && download(row) ? [{ label: 'Open private evidence', icon: Download, onClick: () => window.location.assign(download(row)!) }] : []),
            ...(commandReady && row.can_remove ? [{ label: 'Remove evidence', icon: Trash2, danger: true, onClick: () => open('remove_evidence', row.id) }] : []),
        ];
        const withheld = <span className="text-muted-foreground">Withheld</span>;
        const columns: RecordTableColumn<ExposureEvidence | ExposureRepair>[] = [
            { key: 'file', label: 'File', className: 'min-w-[14rem]', cell: (row) => repaired(row) ? <span className="text-status-warning">{REPAIR}</span>
                : row.attribution_state === 'legacy_unknown' ? <RecordIdentity leading={<FileText aria-hidden="true" className="size-4" />} title="Earlier provenance unconfirmed" sub="The original evidence acceptance is unconfirmed. File details and download are withheld." />
                    : <RecordIdentity leading={<FileText aria-hidden="true" className="size-4" />}
                        title={row.available && download(row) ? <a href={download(row)!} onClick={(event) => event.stopPropagation()} className="rounded-sm break-words underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">{text(row.original_name)}</a> : <span className="break-words">{text(row.original_name)}</span>}
                        sub={row.available && download(row) ? 'Recorded source' : <span role="status" className="text-status-warning">The saved file is currently unavailable.</span>} /> },
            { key: 'description', label: 'Description', className: 'min-w-[12rem]', cell: (row) => usable(row) ? <span className="whitespace-pre-wrap break-words">{text(row.description)}</span> : withheld },
            { key: 'by', label: 'Uploaded by', nowrap: true, cell: (row) => usable(row) ? row.uploaded_by?.name ? <RecordPerson name={row.uploaded_by.name} /> : 'Not recorded' : withheld },
            { key: 'size', label: 'Size', nowrap: true, cell: (row) => usable(row) ? row.size === undefined ? 'Not recorded' : `${row.size.toLocaleString()} bytes` : withheld },
            { key: 'type', label: 'Type', nowrap: true, cell: (row) => usable(row) ? text(row.mime) : withheld },
        ];
        return <>
            <Collection collection={detail.attachments} pageKey="evidence_page" onPage={onPage} inset={false}>
                <RecordTable title="Private files" sub="Files require current private record access each time they are opened." ariaLabel="Private evidence"
                    actions={commandReady && detail.can.upload_evidence ? <Button type="button" variant="outline" size="sm" onClick={() => open('upload_evidence')}>Add evidence</Button> : undefined}
                    columns={columns} rows={rows} rowKey={(row) => row.id} actionsFor={actions} menuTitle={(row) => usable(row) ? text(row.original_name) : 'Private evidence'} menuIcon={LockKeyhole}
                    empty={<p className="sg-quiet px-4 py-6"><Ring tone="none" /><span>No admitted records are available on this page.</span></p>} />
            </Collection>
        </>;
    }

    return <>
        <Note icon={Info}><p>These snapshots show the facts retained at that revision. Reference numbers preserve the historical association; present-day names are not substituted.</p>
            <p>Saved care answers show retained values. This history view does not confirm their original reporting.</p></Note>
        <Surface title="History">
            <Collection collection={detail.history} pageKey="history_page" onPage={onPage}>
                {detail.history.items?.length ? <ol className="sg-log">{detail.history.items.map((row) => <LogRow key={row.id} time={dayOf(row.recorded_at)} tone={historyTone(row.command)}
                    title={HISTORY_WORDS[row.command] ?? label(row.command)}
                    sub={<>{formatTime(row.recorded_at)}{dot}{row.recorded_by.name}{dot}Revision {row.revision}{dot}Recorded source: {label(row.source_type).toLowerCase()} · Reference {row.source_id}
                        {row.reason ? <>{dot}<span className="whitespace-pre-wrap">{row.reason}</span></> : null}</>}>
                    <details className="text-subtle"><summary className="cursor-pointer rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">View retained facts before and after</summary>
                        <div className="mt-3 grid gap-3"><h4 className="font-semibold text-foreground">Before this change</h4><HistoryFacts facts={row.before} />
                            <h4 className="font-semibold text-foreground">After this change</h4><HistoryFacts facts={row.after} /></div></details>
                </LogRow>)}</ol> : <p className="sg-quiet"><Ring tone="none" /><span>No admitted records are available on this page.</span></p>}
            </Collection>
        </Surface>
    </>;
}

