import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES, type ExposureAction, type ExposurePageKey, type ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureAttribution, ExposureCollection, ExposureHistoryFacts, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { useEffect, useRef, type ReactNode, type Ref } from 'react';
import { ExposureConnections, ExposureOverview } from './exposure-overview';

const text = (value: string | number | null | undefined) => value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
const label = (value: string) => value.replaceAll('_', ' ');
function Facts({ items }: { items: [string, ReactNode][] }) {
    return <dl className="grid gap-4 sm:grid-cols-2">{items.map(([name, value]) => <div key={name} className="min-w-0 space-y-1">
        <dt className="text-caption text-muted-foreground">{name}</dt><dd className="whitespace-pre-wrap break-words text-subtle">{value}</dd>
    </div>)}</dl>;
}
function Attribution({ state }: { state: ExposureAttribution }) {
    return <StatusBadge variant={state === 'recorded' ? 'neutral' : 'warning'}>
        {state === 'recorded' ? 'Recorded source' : state === 'legacy_unknown' ? 'Earlier provenance unconfirmed' : 'Source needs review'}
    </StatusBadge>;
}
function Collection({ collection, children, pageKey, onPage }: { collection: ExposureCollection<unknown>; children: ReactNode;
    pageKey: ExposurePageKey; onPage: (key: ExposurePageKey, page: number) => void }) {
    if (collection.items === null) return <p role="status" className="text-subtle text-status-warning">These saved records cannot currently be confirmed. Details are withheld.</p>;
    const page = collection.page;
    return <div className="space-y-4">
        {collection.source_integrity === 'repair_required' && <p role="status" className="text-subtle text-status-warning">Some sources need review. Their details and the collection total are unconfirmed.</p>}
        {collection.source_integrity === 'legacy_unknown' && <p className="text-subtle">Earlier provenance is unconfirmed. Later changes do not certify earlier facts.</p>}
        {collection.items.length ? children : <p className="text-subtle">No admitted records are available on this page.</p>}
        {page && <nav aria-label="Recorded collection pages" className="flex flex-wrap items-center gap-3">
            <Button type="button" variant="outline" className="frontline-tap" disabled={page.current_page <= 1} onClick={() => onPage(pageKey, page.current_page - 1)}>Previous page</Button>
            <p className="text-caption text-muted-foreground">Page {page.current_page}{page.last_page === null ? ' · total unconfirmed' : ` of ${page.last_page}`}</p>
            <Button type="button" variant="outline" className="frontline-tap" disabled={!page.has_more} onClick={() => onPage(pageKey, page.current_page + 1)}>Next page</Button>
        </nav>}
    </div>;
}
function Repair() { return <p role="status" className="text-subtle text-status-warning">This source needs review. Saved details and changes are withheld.</p>; }
function Row({ children, selected = false, focusRef }: { children: ReactNode; selected?: boolean; focusRef?: Ref<HTMLLIElement> }) {
    return <li ref={focusRef} tabIndex={selected ? -1 : undefined} aria-label={selected ? 'Selected follow-up' : undefined}
        className={`space-y-4 rounded-lg border border-border bg-card p-4${selected ? ' ring-2 ring-ring outline-none' : ''}`}>{children}</li>;
}
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
/** Permanent read sections, not yet mounted. All commands retain independent current server admission. */
export function ExposureRecordPanel({ detail, section, open, onPage, focusedFollowupId = null }: { detail: ExposureWorkspaceDetail; section: ExposureSection;
    open: (action: ExposureAction, source?: number) => void; onPage: (key: ExposurePageKey, page: number) => void; focusedFollowupId?: number | null }) {
    const admitted = detail.exposure_access === 'readable' && detail.source_integrity !== 'repair_required' && !!detail.record
        && detail.record.id === detail.id && detail.record.attribution_state !== 'repair_required';
    const selectedId = admitted && section === 'followups' && detail.followup_focus_state === 'available'
        && detail.focused_followup_id === focusedFollowupId && detail.followups.items?.some((row) => row.id === focusedFollowupId && row.attribution_state === 'recorded')
        ? focusedFollowupId : null;
    const selectedRow = useRef<HTMLLIElement>(null);
    useEffect(() => { if (selectedId !== null) selectedRow.current?.focus(); }, [detail.id, selectedId]);
    if (!admitted) return <Repair />;
    const context = detail.command_context;
    const commandReady = context?.root_type === 'exposure' && context.root_id === detail.id
        && Number.isSafeInteger(context.expected_version) && context.expected_version >= 0;
    if (section === 'overview') return <ExposureOverview detail={detail} onCorrect={commandReady ? () => open('correct') : undefined}
        onReview={commandReady ? () => open('review') : undefined} />;
    if (section === 'connections') return <ExposureConnections detail={detail} />;
    if (section === 'reviews') return <div className="space-y-5"><h2 className="text-section-title">Recorded reviews</h2>
        <p className="text-subtle">A recorded review is separate from the reported facts and from any medical outcome.</p>
        {commandReady && detail.can.review && <Button type="button" className="frontline-tap" onClick={() => open('review')}>Record a review</Button>}
        <Collection collection={detail.reviews} pageKey="reviews_page" onPage={onPage}><ul className="space-y-4">{detail.reviews.items?.map((row) => <Row key={row.id}>
            {row.attribution_state === 'repair_required' ? <Repair /> : <><Attribution state={row.attribution_state} /><Facts items={[
                ['Review outcome', text(label(row.outcome))], ['Reviewed at', formatDateTime(row.reviewed_at, 'Not recorded')],
                ['Recorded by', row.recorded_by?.name ?? 'Not recorded'], ['Review notes', text(row.notes)],
            ]} /></>}
        </Row>)}</ul></Collection></div>;
    if (section === 'followups') return <div className="space-y-5"><h2 className="text-section-title">Assigned follow-ups</h2>
        <p className="text-subtle">The named assignee is responsible for this follow-up. Recording an assignment does not confirm that a notification was delivered.</p>
        {commandReady && detail.can.assign_followup && <Button type="button" className="frontline-tap" onClick={() => open('assign_followup')}>Assign a follow-up</Button>}
        <Collection collection={detail.followups} pageKey="followups_page" onPage={onPage}><ul className="space-y-5">{detail.followups.items?.map((row) => <Row key={row.id}
            selected={row.id === selectedId} focusRef={row.id === selectedId ? selectedRow : undefined}>
            {row.attribution_state === 'repair_required' ? <Repair /> : <><div className="flex flex-wrap items-center gap-3"><Attribution state={row.attribution_state} />
                <StatusBadge variant={row.completed_at !== null && row.completion_attribution_state !== 'recorded' ? 'warning' : 'neutral'}>
                    {row.completed_at === null ? 'Completion not recorded' : row.completion_attribution_state === 'recorded' ? 'Completion recorded' : 'Earlier completion unconfirmed'}</StatusBadge>
                {row.id === selectedId && <span className="text-caption">Selected follow-up</span>}</div><Facts items={[
                ['Assigned to', row.assigned_to?.name ?? 'Not recorded'], ['Due at', formatDateTime(row.due_at, 'Not recorded')],
                ['Instruction', text(row.instruction)], ['Assigned by', row.recorded_by?.name ?? 'Not recorded'],
                ['Completed at', formatDateTime(row.completed_at, 'Not recorded')], ['Completed by', row.completed_by?.name ?? 'Not recorded'],
                ['Completion notes', text(row.completion_notes)],
            ]} />{row.assignment_attribution_state !== 'recorded' && <p className="text-subtle text-status-warning">Earlier assignment history is unconfirmed. A recorded completion does not confirm that earlier assignment.</p>}
                <div className="flex flex-wrap gap-3">{commandReady && row.can_complete && row.completed_at === null && <Button type="button" variant="outline" className="frontline-tap" onClick={() => open('complete_followup', row.id)}>Record completion</Button>}
                {commandReady && row.can_reassign && row.completed_at === null && <Button type="button" variant="outline" className="frontline-tap" onClick={() => open('reassign_followup', row.id)}>Transfer follow-up</Button>}</div></>}
        </Row>)}</ul></Collection></div>;
    if (section === 'evidence') return <div className="space-y-5"><h2 className="text-section-title">Private evidence</h2>
        <p className="text-subtle">Files require current private record access each time they are opened.</p>
        {commandReady && detail.can.upload_evidence && <Button type="button" className="frontline-tap" onClick={() => open('upload_evidence')}>Add evidence</Button>}
        <Collection collection={detail.attachments} pageKey="evidence_page" onPage={onPage}><ul className="space-y-4">{detail.attachments.items?.map((row) => {
            const href = row.href === `${CHEMICAL_EXPOSURES}/${detail.id}/evidence/${row.id}/download` ? row.href : null;
            return <Row key={row.id}>{row.attribution_state === 'repair_required' ? <Repair /> : row.attribution_state === 'legacy_unknown'
                ? <><Attribution state={row.attribution_state} /><p className="text-subtle">The original evidence acceptance is unconfirmed. File details and download are withheld.</p></>
                : <><Attribution state={row.attribution_state} /><Facts items={[
                    ['File name', text(row.original_name)], ['Description', text(row.description)], ['Uploaded by', row.uploaded_by?.name ?? 'Not recorded'],
                    ['Recorded size', row.size === undefined ? 'Not recorded' : `${row.size.toLocaleString()} bytes`], ['File type', text(row.mime)],
                ]} /><div className="flex flex-wrap gap-3">
                    {row.available && href ? <Button asChild variant="outline" className="frontline-tap"><a href={href}>Open private evidence</a></Button>
                        : <p role="status" className="text-subtle text-status-warning">The saved file is currently unavailable.</p>}
                    {commandReady && row.can_remove && <Button type="button" variant="outline" className="frontline-tap" onClick={() => open('remove_evidence', row.id)}>Remove evidence</Button>}
                </div></>}
            </Row>;
        })}</ul></Collection></div>;
    return <div className="space-y-5"><h2 className="text-section-title">Recorded history</h2>
        <p className="text-subtle">These snapshots show the facts retained at that revision. Reference numbers preserve the historical association; present-day names are not substituted.</p>
        <p className="text-subtle">Saved care answers show retained values. This history view does not confirm their original reporting.</p>
        <Collection collection={detail.history} pageKey="history_page" onPage={onPage}><ol className="space-y-4">{detail.history.items?.map((row) => <Row key={row.id}>
            <h3 className="text-section-title">{label(row.command)}</h3><p className="text-subtle">{row.recorded_by.name} · {formatDateTime(row.recorded_at)} · Revision {row.revision}</p>
            <p className="text-caption text-muted-foreground">Recorded source: {label(row.source_type)} · Reference {row.source_id}</p>
            {row.reason && <p className="whitespace-pre-wrap break-words text-subtle">{row.reason}</p>}
            <details className="rounded-md border border-border p-3"><summary className="frontline-tap cursor-pointer text-subtle focus-visible:ring-2 focus-visible:ring-ring">View retained facts before and after</summary>
                <div className="mt-4 space-y-5"><h4 className="text-section-title">Before this change</h4><HistoryFacts facts={row.before} />
                    <h4 className="text-section-title">After this change</h4><HistoryFacts facts={row.after} /></div></details>
        </Row>)}</ol></Collection></div>;
}
