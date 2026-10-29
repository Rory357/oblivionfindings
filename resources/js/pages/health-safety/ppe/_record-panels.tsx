/**
 * PPE record bodies (approved H&S drawings pg-hsn-121 … 121e equipment, 122 … 122c issue, 123 inspection —
 * Stephan 23 Oct 2026). One component per record kind and section, each as signature blocks with a side column:
 * equipment details as a story, condition and inspections as a ring, who has it as a person card with the
 * blocked-action rules under it, issues as person cards, inspections as a log, evidence as a table whose kebab
 * and right-click carry preview, download, move and remove.
 *
 * Every recorded fact, damaged-record state, caveat and command gate stays as it was (in plain words); own-issue
 * pages still show only the worker's own details.
 */
import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { RecordIdentity, RecordPerson, RecordTable } from '@/components/incidents/record-table';
import { compactMenu, EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { FactRows, Log, Note, PeopleCards, PlaceCards, QuietLine, RingCard, RowList, SideColumn, Story, type CheckItem, type PersonCardItem, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { ppeHref, type PpeCommand, type PpePageKey, type PpeSection } from '@/lib/health-safety/ppe-navigation';
import { Link, router } from '@inertiajs/react';
import { CalendarDays, CircleCheck, Clock, Download, Eye, FileText, MapPin, MoveRight, Paperclip, ShieldCheck, Tag, Trash2, UserRound } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { PPE_COMMAND_LABELS, ppeCommandAllowed } from './_command-state';
import type { PpeAllocation, PpeInspection, PpeItem, PpePage, PpeRecord } from './_workspace-types';
import { catIcon, catLabel, condLabel, formatBytes, statusLabel, type PpeAttachment } from './ppe-shared';

export const ppeDay = (value?: string | null) => formatDateOnly(value, 'Not recorded');
/** "2 Sep 2026" for a timestamp, on the NZ calendar. */
const dayOf = (value?: string | null) => (value ? formatDateOnly(toDateInput(value), 'Not recorded') : 'Not recorded');
/** "2 Sep 2026, 9:15 am" — the register's short date with its time. */
export const ppeTime = (value?: string | null) => (value && toDateInput(value) ? `${dayOf(value)}, ${formatTime(value)}` : 'Not recorded');
const sentence = humaniseHsValue;
export const PPE_RESULT_LABELS: Record<string, string> = { pass: 'Pass', fail: 'Fail', needs_repair: 'Needs repair', condemned: 'Taken out of use' };
/** What a history entry did, in plain words (the stored command names never show). */
const HISTORY_LABELS: Record<string, string> = { create_inventory: 'Equipment added', update_inventory: 'Details edited', allocate: 'Issued', acknowledge: 'Received',
    acknowledge_own: 'Received', return: 'Returned', inspect: 'Inspected', condemn: 'Taken out of use', dispose: 'Disposed of', hold: 'Placed on hold',
    release: 'Returned to use', upload_attachment: 'File added', remove_attachment: 'File removed', relocate_attachment: 'File moved to secure storage' };
/** Types of file people see. */
const FILE_KINDS: Record<string, string> = { document: 'Document', photo: 'Photo', inspection_photo: 'Inspection photo', inspection_report: 'Inspection report' };
export const ppeResultLabel = (result: string) => PPE_RESULT_LABELS[result] ?? sentence(result);
const resultTone = (result: string): SgTone => (result === 'pass' ? 'success' : result === 'condemned' ? 'critical' : 'warning');
const LAST_INSPECTION: Record<string, string> = { pass: 'Last inspection passed', fail: 'Last inspection failed' };
/** History rows read by what the change did: issues blue, returns quiet, holds amber, condemn and disposal red. */
const historyTone = (command: string): SgTone => (command === 'allocate' ? 'info' : command === 'return' ? 'none' : command === 'hold' ? 'warning' : ['condemn', 'dispose'].includes(command) ? 'critical' : 'success');
export const ppeServiceLabel = (status: string | null | undefined) => statusLabel(status);
export const PPE_FREQUENCY_WORDS: Record<string, string> = { daily: 'daily', weekly: 'weekly', monthly: 'monthly', quarterly: 'quarterly', annually: 'yearly' };

type Props = { record: PpeRecord; section: PpeSection; sourceHref: string; open: (command: PpeCommand, attachment?: number) => void; page: (key: PpePageKey, page: number) => void };
function Pages({ meta, name, change }: { meta?: PpePage; name: string; change: (page: number) => void }) {
    if (!meta || meta.last_page <= 1) return null;
    return <nav aria-label={`${name} pages`} className="flex flex-wrap items-center gap-3"><Button type="button" size="sm" variant="outline" disabled={meta.current_page <= 1} onClick={() => change(meta.current_page - 1)}>Previous</Button><span className="text-caption">Page {meta.current_page} of {meta.last_page} · {meta.total} records</span><Button type="button" size="sm" variant="outline" disabled={meta.current_page >= meta.last_page} onClick={() => change(meta.current_page + 1)}>Next</Button></nav>;
}
/** A block-head action for a command, only when the record allows it. */
const commandAction = (record: PpeRecord, command: PpeCommand, open: Props['open']) => ppeCommandAllowed(record, command) ? { label: PPE_COMMAND_LABELS[command], onClick: () => open(command) } : null;
/** A damaged-record note, read out when it appears. */
const Integrity = ({ children }: { children: ReactNode }) => <div role="status"><Note tone="warning">{children}</Note></div>;

/* ------------------------------------------------------------------ */
/*  Equipment (inventory)                                              */
/* ------------------------------------------------------------------ */

function conditionTone(condition: string | null): SgTone {
    if (condition === 'new' || condition === 'good') return 'success';
    if (condition === 'fair' || condition === 'poor') return 'warning';
    return condition === 'condemned' ? 'critical' : 'none';
}
function EquipmentOverview({ record, sourceHref }: Props & { record: { kind: 'inventory'; detail: PpeItem } }) {
    const d = record.detail;
    const CategoryIcon = catIcon(d.ppe_type?.category);
    const frequency = d.ppe_type?.inspection_frequency;
    const latest = (d.pages?.inspections?.current_page ?? 1) === 1 ? d.inspections[0] : undefined;
    const checks: CheckItem[] = [
        { label: d.condition ? `Condition ${condLabel(d.condition).toLowerCase()}` : 'Condition not recorded', state: conditionTone(d.condition) },
        latest
            ? { label: LAST_INSPECTION[latest.result] ?? `Last inspection: ${ppeResultLabel(latest.result).toLowerCase()}`, state: resultTone(latest.result), note: `${dayOf(latest.inspected_at)} · ${latest.inspector?.name ?? 'Inspector not recorded'}` }
            : d.last_inspected_at ? { label: 'Last inspection', state: 'none', note: ppeDay(d.last_inspected_at) } : { label: 'No inspection recorded', state: 'none' },
        {
            label: frequency ? `${sentence(PPE_FREQUENCY_WORDS[frequency] ?? frequency)} inspection` : 'Next inspection',
            state: d.currency?.inspection_state === 'overdue' ? 'critical' : d.currency?.inspection_state === 'scheduled' ? 'success' : 'none',
            note: d.currency?.inspection_state === 'overdue' ? 'Overdue' : d.next_inspection_due ? `Due ${ppeDay(d.next_inspection_due)}` : 'Not scheduled',
        },
    ];
    return <>
        <Story title="Equipment details" sub="Quantity is how many are in this batch. It doesn’t tell you how many are on the shelf or whether they fit."
            facts={[
                { icon: MapPin, text: <>{d.site ? <Link className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" href={`/sites/${d.site.id}?tab=ppe&return_to=${encodeURIComponent(sourceHref)}`}>{d.site.name}</Link> : 'Site not available'} · {d.location ?? 'Storage not recorded'}</> },
                { icon: CategoryIcon, text: d.ppe_type?.category ? catLabel(d.ppe_type.category) : 'Category not recorded' },
                { icon: Tag, text: [d.brand, d.model].filter(Boolean).join(' · ') || 'Brand and model not recorded' },
                { icon: ShieldCheck, text: d.ppe_type?.standards_reference ?? 'Standard not recorded' },
                { icon: CalendarDays, text: d.purchase_date ? `Bought ${ppeDay(d.purchase_date)}` : 'Purchase date not recorded' },
            ]}
            paras={[{ h: 'Hazards covered', t: <span className="whitespace-pre-wrap">{d.ppe_type?.hazards_addressed ?? 'Not recorded'}</span> }]} />
        <RingCard value={checks.filter((item) => item.state === 'success').length} of={checks.length} label="Condition and inspections"
            sub={frequency ? `Inspected ${PPE_FREQUENCY_WORDS[frequency] ?? frequency}` : 'Inspection frequency not recorded'} checks={checks} />
        {(d.condemned_at || d.disposed_at) && <FactRows title="Taken out of use and disposed of" rows={[
            ['Taken out of use', ppeTime(d.condemned_at)], ['Taken out of use by', d.condemned_by?.name ?? 'Not recorded'], ['Why it was taken out of use', d.condemned_reason ?? 'Not recorded'],
            ['Disposed of', ppeTime(d.disposed_at)], ['Disposed by', d.disposed_by?.name ?? 'Not recorded'], ['Disposal method', d.disposal_method ?? 'Not recorded'],
        ]} />}
    </>;
}
function EquipmentSide({ record, sourceHref, issues }: { record: { kind: 'inventory'; detail: PpeItem }; sourceHref: string; issues: boolean }) {
    const d = record.detail; const a = d.active_allocation;
    const blocked = d.can.manage ? Object.entries(d.blocked_reasons ?? {}).filter(([, reason]) => reason) : [];
    return <>
        {issues ? null : d.active_allocation_state === 'ambiguous'
            ? <Integrity>This item shows as issued to more than one person. Check who really has it and record the returns before issuing it again or returning it to use.</Integrity>
            : a ? <PeopleCards title="Issued to" cols={1} action={{ label: 'View issue', href: ppeHref('allocations', a.id, { returnTo: sourceHref }) }}
                items={[{ id: a.id, name: a.user?.name ?? 'Worker not available', sub: `Since ${ppeTime(a.allocated_at)} · Ref ${a.id}`,
                    flag: <StatusBadge variant={a.acknowledged ? 'success' : 'warning'}>{a.acknowledged ? 'Received' : 'Not yet received'}</StatusBadge> }]} />
                : <QuietLine title="Issued to" text="Not issued to anyone at the moment." />}
        <PlaceCards title="Status now" cols={1} items={[{ name: ppeServiceLabel(d.status), sub: 'Check training and fit before use.',
            facts: [
                ...(d.status === 'maintenance' ? [{ tone: 'warning' as SgTone, text: 'On hold. It must pass an inspection and then be returned to use before anyone can have it.' }] : []),
                ...(!d.can.manage ? [{ tone: 'none' as SgTone, text: 'Only people who manage PPE at this site can issue, inspect or change this item. Ask your health and safety manager.' }] : []),
                ...blocked.map(([command, reason]) => ({ tone: 'none' as SgTone, text: <><b>{PPE_COMMAND_LABELS[command as PpeCommand] ?? sentence(command)}</b> — {reason}</> })),
            ] }]} />
    </>;
}
function EquipmentIssues({ record, sourceHref, open, page }: Props & { record: { kind: 'inventory'; detail: PpeItem } }) {
    const d = record.detail;
    const menu = useEntityContextMenu<PpeItem['allocations'][number]>();
    const href = (id: number) => ppeHref('allocations', id, { returnTo: sourceHref });
    const actions = (item: PpeItem['allocations'][number]): MenuItem[] => [{ label: 'Open', icon: UserRound, onClick: () => router.visit(href(item.id)) }];
    const add = commandAction(record, 'allocate', open);
    return <>
        {d.child_integrity?.allocations === 'repair_required' && <Integrity>Some records are damaged and hidden until an administrator fixes them. Page counts include those records.</Integrity>}
        {d.allocations.length
            ? <PeopleCards title="Who’s had it" sub="Newest first" cols={3} action={add} items={d.allocations.map((item): PersonCardItem => ({
                id: item.id, name: item.user?.name ?? 'Worker not available', sub: `${item.returned_at ? 'Returned' : 'Has it now'} · Ref ${item.id}`,
                flag: <StatusBadge variant={item.acknowledged ? 'success' : 'warning'}>{item.acknowledged ? 'Received' : 'Not yet received'}</StatusBadge>,
                foot: item.returned_at ? `${ppeTime(item.allocated_at)} – ${ppeTime(item.returned_at)}` : `Issued ${ppeTime(item.allocated_at)} · still issued`,
                href: href(item.id), menu: actions(item), onContextMenu: (event) => menu.open(event, item) }))} />
            : d.child_integrity?.allocations !== 'repair_required' && <QuietLine title="Who’s had it" text="Not issued to anyone yet." action={add} />}
        <Pages name="Who’s had it" meta={d.pages?.allocations} change={(next) => page('allocations_page', next)} />
        {menu.ctx && <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={UserRound} title={menu.ctx.record.user?.name ?? `Issued · Ref ${menu.ctx.record.id}`} items={actions(menu.ctx.record)} onClose={menu.close} />}
    </>;
}
function EquipmentInspections({ record, sourceHref, open, page }: Props & { record: { kind: 'inventory'; detail: PpeItem } }) {
    const d = record.detail;
    const menu = useEntityContextMenu<PpeItem['inspections'][number]>();
    const href = (id: number, section?: PpeSection) => ppeHref('inspections', id, { section, returnTo: sourceHref });
    const actions = (item: PpeItem['inspections'][number]): MenuItem[] => [{ label: 'Open inspection', icon: FileText, onClick: () => router.visit(href(item.id)) },
        { label: 'Open inspection files', icon: Paperclip, onClick: () => router.visit(href(item.id, 'evidence')) }];
    const add = commandAction(record, 'inspect', open);
    return <>
        {d.child_integrity?.inspections === 'repair_required' && <Integrity>Some records are damaged and hidden until an administrator fixes them. Page counts include those records.</Integrity>}
        {d.inspections.length
            ? <Log title="Inspections" sub="Newest first · open one to see it and its files" action={add} limit={d.inspections.length} items={d.inspections.map((item) => ({
                key: item.id, time: dayOf(item.inspected_at), tone: resultTone(item.result),
                title: `${ppeResultLabel(item.result)} — ${item.findings ?? 'No findings recorded'}`,
                sub: [item.inspector?.name ?? 'Inspector not recorded', item.inspected_at ? formatTime(item.inspected_at) : null,
                    item.action_taken ?? 'No action recorded', `next due ${ppeDay(item.next_inspection_due)}`, `Ref ${item.id}`].filter(Boolean).join(' · '),
                action: { label: 'Open', href: href(item.id) }, menu: actions(item), onContextMenu: (event) => menu.open(event, item) }))} />
            : d.child_integrity?.inspections !== 'repair_required' && <QuietLine title="Inspections" text="No inspections yet." action={add} />}
        <Pages name="Inspections" meta={d.pages?.inspections} change={(next) => page('inspections_page', next)} />
        {menu.ctx && <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={FileText} title={menu.ctx.record.inspected_at ? `Inspection on ${dayOf(menu.ctx.record.inspected_at)}` : 'Inspection'} items={actions(menu.ctx.record)} onClose={menu.close} />}
    </>;
}

/* ------------------------------------------------------------------ */
/*  Issue (allocation)                                                 */
/* ------------------------------------------------------------------ */

function IssueOverview({ record }: Props & { record: { kind: 'allocations'; detail: PpeAllocation } }) {
    const d = record.detail; const operational = d.read_scope === 'site_operational';
    const detail = operational && 'issued_by' in d ? d : null;
    const item = d.inventory_item;
    const people: PersonCardItem[] = [];
    if (detail) {
        people.push({ name: d.user?.name ?? 'Worker not available', sub: d.acknowledged ? 'Worker · confirmed they received it' : 'Worker',
            flag: <StatusBadge variant={d.acknowledged ? 'success' : 'warning'}>{d.acknowledged ? 'Received' : 'Not yet received'}</StatusBadge>,
            foot: d.acknowledged ? ppeTime(d.acknowledged_at) : undefined });
        if (detail.issued_by) people.push({ name: detail.issued_by.name, sub: 'Issued it', foot: ppeTime(d.allocated_at) });
        if (detail.acknowledged_by && detail.acknowledged_by.id !== d.user?.id) people.push({ name: detail.acknowledged_by.name, sub: 'Recorded that it was received', foot: ppeTime(d.acknowledged_at) });
    }
    const CategoryIcon = catIcon(d.ppe_type?.category);
    return <>
        {d.catalogue_integrity === 'repair_required' && <Integrity>The equipment type linked to this item needs fixing, so its name may be missing. Your issue details are fine.</Integrity>}
        {people.length ? <PeopleCards title="People" cols={2} items={people} /> : null}
        <Story title={operational ? 'The equipment' : 'Your equipment'}
            sub={operational ? undefined : 'This page only shows your own record for this item.'}
            facts={[
                { icon: CategoryIcon, text: d.ppe_type?.name ?? 'Equipment type not available' },
                { icon: Tag, text: [item?.brand, item?.model, item?.serial_number].filter(Boolean).join(' · ') || 'Brand, model and serial number not recorded' },
                { icon: MapPin, text: item?.site?.name ?? 'Site not available' },
                ...(detail && item && 'condition' in item ? [{ icon: CircleCheck, text: `Condition ${condLabel(item.condition).toLowerCase()}` }, { icon: ShieldCheck, text: `Equipment status: ${ppeServiceLabel(item.status).toLowerCase()}` }] : []),
                { icon: Clock, text: d.returned_at ? `Returned ${ppeTime(d.returned_at)}` : 'Still issued' },
            ]}
            paras={detail ? [{ h: 'Issue or return notes', t: <span className="whitespace-pre-wrap">{detail.notes ?? 'Not recorded'}</span> }] : []} />
    </>;
}
function IssueSide({ record, sourceHref }: { record: { kind: 'allocations'; detail: PpeAllocation }; sourceHref: string }) {
    const d = record.detail;
    if (!d.inventory_item) return null;
    return <RowList title="Equipment record" items={[{ key: d.inventory_item.id, title: d.ppe_type?.name ?? 'Equipment',
        sub: `${d.inventory_item.serial_number ?? 'Serial number not recorded'} · Open the equipment · Ref ${d.inventory_item.id}`,
        href: ppeHref('inventory', d.inventory_item.id, { section: 'allocations', returnTo: sourceHref }) }]} />;
}
function FitTraining({ record }: { record: { kind: 'allocations'; detail: PpeAllocation } }) {
    const d = record.detail;
    const result = d.read_scope === 'site_operational' && 'fit_test_result' in d ? d.fit_test_result : null;
    return <>
        <RingCard value={[d.fit_test_completed, d.training_completed].filter(Boolean).length} of={2} label="Fit test and training on record"
            sub={result ? `Fit test result: ${result}` : undefined}
            checks={[
                { label: 'Fit test done', state: d.fit_test_completed ? 'success' : 'none', note: d.fit_test_completed ? ppeDay(d.fit_test_date) : 'Not recorded' },
                { label: 'Training done', state: d.training_completed ? 'success' : 'none', note: d.training_completed ? ppeDay(d.training_date) : 'Not recorded' },
            ]} />
        <Note tone="warning"><b>Check before use</b> — these dates show a fit test and training happened, not that they’re still current or that the worker is fit for work. Follow the procedure before letting someone use this equipment.</Note>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Inspection                                                         */
/* ------------------------------------------------------------------ */

function InspectionOverview({ record }: { record: { kind: 'inspections'; detail: PpeInspection } }) {
    const d = record.detail;
    return <Story title="Inspection" sub="This shows the item as it was at this inspection, not whether it can be used now."
        facts={[
            { icon: Clock, text: d.inspected_at ? <><b>{dayOf(d.inspected_at)}</b>, {formatTime(d.inspected_at)}</> : 'Inspection time not recorded' },
            { icon: CircleCheck, text: d.condition_after ? `Condition after: ${condLabel(d.condition_after).toLowerCase()}` : 'Condition after not recorded' },
            { icon: CalendarDays, text: d.next_inspection_due ? `Next due ${ppeDay(d.next_inspection_due)}` : 'Next inspection not recorded' },
        ]}
        paras={[{ h: 'Findings', t: <span className="whitespace-pre-wrap">{d.findings ?? 'Not recorded'}</span> }, { h: 'Action taken', t: <span className="whitespace-pre-wrap">{d.action_taken ?? 'Not recorded'}</span> }]} />;
}
function InspectionSide({ record, sourceHref }: { record: { kind: 'inspections'; detail: PpeInspection }; sourceHref: string }) {
    const d = record.detail;
    return <>
        {d.inspector ? <PeopleCards title="Inspector" cols={1} items={[{ id: d.inspector.id, name: d.inspector.name, sub: 'Inspected it' }]} /> : <QuietLine title="Inspector" text="Inspector not recorded." />}
        <RowList title="Equipment" items={[{ key: d.ppe_inventory_id, title: 'Open the equipment', sub: `Ref ${d.ppe_inventory_id}`,
            href: ppeHref('inventory', d.ppe_inventory_id, { section: 'inspections', returnTo: sourceHref }) }]} />
    </>;
}

/* ------------------------------------------------------------------ */
/*  Evidence and history (every kind)                                  */
/* ------------------------------------------------------------------ */

function Evidence({ record, open, page }: Props) {
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    if (!('attachments' in record.detail) || (record.kind === 'allocations' && record.detail.read_scope === 'own_allocation')) return <QuietLine title="Files" text="You don’t have access to this record’s files." />;
    const files: PpeAttachment[] = record.detail.attachments; const manage = record.detail.can.manage === true;
    const add = ppeCommandAllowed(record, 'upload_evidence') ? <Button type="button" variant="outline" size="sm" onClick={() => open('upload_evidence')}><Paperclip className="size-4" /> {PPE_COMMAND_LABELS.upload_evidence}</Button> : undefined;
    const actions = (file: PpeAttachment): MenuItem[] => compactMenu([
        file.download_url && { label: 'Preview', icon: Eye, onClick: () => setPreview({ id: file.id, name: file.original_name, mime: file.mime, bytes: file.size, previewUrl: file.url, downloadUrl: file.download_url }) },
        file.download_url && { label: 'Download', icon: Download, onClick: () => window.location.assign(file.download_url) },
        ppeCommandAllowed(record, 'relocate_evidence', file.id) && { label: PPE_COMMAND_LABELS.relocate_evidence, icon: MoveRight, onClick: () => open('relocate_evidence', file.id) },
        ppeCommandAllowed(record, 'remove_evidence', file.id) && { separator: true },
        ppeCommandAllowed(record, 'remove_evidence', file.id) && { label: PPE_COMMAND_LABELS.remove_evidence, icon: Trash2, danger: true, onClick: () => open('remove_evidence', file.id) },
    ]);
    return <>
        {files.length ? <RecordTable title="Files" sub={`${files.length} ${files.length === 1 ? 'file' : 'files'} · right-click to preview, download or remove`} actions={add} ariaLabel="Files"
            rows={files} rowKey={(file) => file.id} rowId={(file) => `attachment-${file.id}`} menuTitle={(file) => file.original_name} menuIcon={FileText} actionsFor={actions}
            columns={[
                { key: 'file', label: 'File', className: 'min-w-[16rem] max-w-[28rem]', cell: (file) => <RecordIdentity leading={<FileText aria-hidden="true" className="size-4" />}
                    title={file.download_url ? <a href={file.download_url} onClick={(event) => event.stopPropagation()} className="rounded-sm break-words text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">{file.original_name}</a> : <span className="break-words">{file.original_name}</span>}
                    sub={<>
                        {file.notes ? <span className="block whitespace-pre-wrap">{file.notes}</span> : null}
                        {file.alt_text ? <span className="block">Image description: {file.alt_text}</span> : null}
                        {!file.download_url ? <span className="block text-status-warning">This file can’t be opened — it may be missing. Tell your health and safety manager.</span> : null}
                        {file.can_relocate !== true && file.relocate_blocked_reason && manage ? <span className="block">{file.relocate_blocked_reason}</span> : null}
                    </>} /> },
                { key: 'kind', label: 'Type', nowrap: true, cell: (file) => file.kind ? FILE_KINDS[file.kind] ?? sentence(file.kind) : 'File' },
                { key: 'size', label: 'Size', nowrap: true, cell: (file) => formatBytes(file.size) },
                { key: 'added', label: 'Added', nowrap: true, cell: (file) => file.created_at ? <RecordIdentity title={dayOf(file.created_at)} sub={formatTime(file.created_at)} /> : <span className="text-muted-foreground">Not recorded</span> },
                { key: 'by', label: 'Added by', nowrap: true, cell: (file) => file.uploaded_by ? <RecordPerson name={file.uploaded_by.name} /> : <span className="text-muted-foreground">Not recorded</span> },
            ]} />
            : <QuietLine title="Files" text="No files yet." action={commandAction(record, 'upload_evidence', open)} />}
        <Pages name="Files" meta={record.detail.pages?.attachments} change={(next) => page('attachments_page', next)} />
        <FilePreviewDialog file={preview} onClose={() => setPreview(null)} />
    </>;
}
const HISTORY_NOTE = 'Changes made here are listed first. Older entries were pieced together from the record and may be incomplete.';
function History({ record, page }: Props) {
    const d = record.detail; const rows = d.command_history ?? [];
    return <>
        {d.command_history_integrity === 'repair_required' && <Integrity>Some history is damaged and hidden. The page count still includes it.</Integrity>}
        {rows.length
            ? <Log title="History" sub={HISTORY_NOTE} limit={rows.length} items={rows.map((entry) => ({ key: entry.id, time: dayOf(entry.recorded_at), tone: historyTone(entry.command),
                title: HISTORY_LABELS[entry.command] ?? sentence(entry.command), sub: `${entry.recorded_at ? formatTime(entry.recorded_at) : 'Time not recorded'} · ${entry.actor?.name ?? 'Not recorded'}` }))} />
            : <QuietLine title="History" sub={HISTORY_NOTE} text="No changes recorded yet." />}
        <Pages name="History" meta={d.pages?.history} change={(next) => page('history_page', next)} />
        {record.kind === 'inventory' && record.detail.history.length
            ? <Log title="Older entries" sub="See the Issued to and Inspections tabs for the full details." limit={record.detail.history.length}
                items={record.detail.history.map((entry, index) => ({ key: `${entry.type}-${entry.at}-${index}`, time: dayOf(entry.at), title: entry.label,
                    sub: `${entry.at ? formatTime(entry.at) : 'Time not recorded'} · ${entry.actor ?? 'Not recorded'}` }))} />
            : null}
    </>;
}

/* ------------------------------------------------------------------ */
/*  The body: main blocks and the side column                          */
/* ------------------------------------------------------------------ */

export function PpeRecordPanel(props: Props) {
    const { record, section, sourceHref } = props;
    let main: ReactNode; let side: ReactNode = null;
    if (record.kind === 'inventory') {
        main = section === 'allocations' ? <EquipmentIssues {...props} record={record} /> : section === 'inspections' ? <EquipmentInspections {...props} record={record} />
            : section === 'evidence' ? <Evidence {...props} /> : section === 'history' ? <History {...props} /> : <EquipmentOverview {...props} record={record} />;
        side = <EquipmentSide record={record} sourceHref={sourceHref} issues={section === 'allocations'} />;
    } else if (record.kind === 'allocations') {
        main = section === 'fit-training' ? <FitTraining record={record} /> : section === 'evidence' ? <Evidence {...props} /> : section === 'history' ? <History {...props} /> : <IssueOverview {...props} record={record} />;
        side = record.detail.read_scope === 'site_operational' && record.detail.inventory_item ? <IssueSide record={record} sourceHref={sourceHref} /> : null;
    } else {
        main = section === 'evidence' ? <Evidence {...props} /> : section === 'history' ? <History {...props} /> : <InspectionOverview record={record} />;
        side = <InspectionSide record={record} sourceHref={sourceHref} />;
    }
    return <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
        <div className="grid min-w-0 gap-5">{main}</div>
        {side ? <aside className="min-w-0" aria-label="Who has it and what it allows"><SideColumn>{side}</SideColumn></aside> : null}
    </div>;
}
