/**
 * Private exposure record (approved H&S drawings pg-hsn-129 … 129g — Stephan 23 Oct 2026): one header on every tab —
 * the worker's name in the title with the provenance chip, the chemical and Site, Correct recorded facts and Record a
 * review — and the stage line (reported, reviewed, follow-ups, medical outcome) naming the next follow-up and who
 * owns it; then the six sections as rail tabs over main blocks and the care-answers side column.
 *
 * Exposure records are health information. Nothing private shows unless the server admits wellbeing access with an
 * intact source; a withdrawn or unconfirmed record keeps only its unavailable line, and retained entries stay in
 * memory. Every command keeps its gate and opens the same inline form above the record.
 */
import { PageHeader, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderStages, type PageHeaderNextStep, type PageHeaderStage } from '@/components/page/page-header-strip';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { Note, SideColumn, Surface } from '@/components/signature/blocks';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES, exposureHref, exposureLocation, exposureReturn, type ExposureAction, type ExposurePageKey, type ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureEvidence, ExposureFollowup, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardCheck, FileText, History, Link2, ListChecks, LockKeyhole, MoreHorizontal, Paperclip, PencilLine, RefreshCw, UserPlus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ExposureCommandForm } from './components/exposure-command-form';
import { ExposureDeliveryPanel } from './components/exposure-delivery-panel';
import { ExposureRecordSide } from './components/exposure-overview';
import { ExposureRecordPanel } from './components/exposure-record-panels';
import type { ExposureReceipt } from './components/use-exposure-command';

const SECTIONS = [{ key: 'overview', label: 'Recorded facts', icon: LockKeyhole }, { key: 'reviews', label: 'Reviews', icon: ClipboardCheck },
    { key: 'followups', label: 'Follow-ups', icon: ListChecks }, { key: 'evidence', label: 'Evidence', icon: FileText },
    { key: 'history', label: 'History', icon: History }, { key: 'connections', label: 'Connections', icon: Link2 }] as const;
type Props = { detail: ExposureWorkspaceDetail; return_to: string | null; chemical_exposure_confirmation: ExposureReceipt | null };
type Pending = { action: ExposureAction; sourceId?: number; child?: ExposureFollowup | ExposureEvidence };
const dayOf = (value?: string | null) => (value && toDateInput(value) ? formatDateOnly(toDateInput(value)) : 'Not recorded');

/** Reported, reviewed, follow-ups, medical outcome — from the record and the loaded first pages of its reviews and follow-ups. */
function exposureStages(detail: ExposureWorkspaceDetail): { steps: PageHeaderStage[]; next: PageHeaderNextStep | null } {
    const record = detail.record!;
    const reviews = (detail.reviews.items ?? []).filter((row) => row.attribution_state !== 'repair_required') as Extract<NonNullable<typeof detail.reviews.items>[number], { outcome: string }>[];
    const followups = (detail.followups.items ?? []).filter((row) => row.attribution_state !== 'repair_required') as ExposureFollowup[];
    const latest = reviews[0]; const reviewed = latest !== undefined || (detail.reviews.page?.total ?? 0) > 0;
    const open = followups.filter((row) => row.completed_at === null).sort((a, b) => (a.due_at ?? '9999').localeCompare(b.due_at ?? '9999'));
    const first = open[0]; const now = Date.now();
    const overdue = !!first?.due_at && new Date(first.due_at).getTime() < now;
    const today = !!first?.due_at && toDateInput(first.due_at) === toDateInput(new Date().toISOString());
    const careReview = record.response?.care_review_needed === true && record.response.harm_assessment === 'unassessed';
    const steps: PageHeaderStage[] = [
        { label: 'Reported', meta: [record.recorded_by?.name, record.created_at ? dayOf(record.created_at) : null].filter(Boolean).join(' · ') || undefined, state: 'done' },
        reviewed ? { label: 'Reviewed', meta: latest ? [latest.recorded_by?.name, latest.reviewed_at ? dayOf(latest.reviewed_at) : null].filter(Boolean).join(' · ') || undefined : undefined, state: 'done' }
            : { label: 'Reviewed', meta: careReview ? 'Care answers need review' : 'Not yet', state: 'now', tone: careReview ? 'warning' : undefined },
        first ? { label: 'Follow-ups', meta: first.due_at ? `Next due ${dayOf(first.due_at)}` : 'Open', state: 'now', tone: overdue ? 'critical' : today ? 'warning' : undefined }
            : { label: 'Follow-ups', meta: followups.length ? 'Done on this page' : 'None on this page', state: followups.length ? 'done' : 'todo' },
        record.medical_outcome ? { label: 'Medical outcome', meta: 'Recorded', state: 'done' } : { label: 'Medical outcome', meta: 'No outcome yet', state: 'todo' },
    ];
    const next: PageHeaderNextStep | null = first
        ? { label: first.due_at ? `Next step · ${overdue ? 'was due' : 'due'} ${dateTimeWithYear(first.due_at)}` : 'Next step', text: first.instruction, tone: overdue ? 'critical' : today ? 'warning' : undefined,
            meta: <>Follow-up for <b>{first.assigned_to?.name ?? 'a named person'}</b>{first.recorded_by ? <> · assigned by {first.recorded_by.name}</> : null}</> }
        : !reviewed && careReview ? { label: 'Next step', text: 'Review the reported care answers', tone: 'warning', meta: 'Care answers need review' }
            : null;
    return { steps, next };
}

export default function ChemicalExposureShow(props: Props) {
    const { props: shared } = usePage<SharedData>();
    return <ExposureWorkspace key={`${shared.auth.user.id}:${props.detail.id}`} {...props} />;
}
function ExposureWorkspace({ detail, return_to, chemical_exposure_confirmation }: Props) {
    const { url, props: shared } = usePage<SharedData>(); const location = exposureLocation(url); const drafts = useSafetyWorkspaceDrafts();
    const [withdrawn, setWithdrawn] = useState(false); const onWithdraw = useCallback(() => setWithdrawn(true), []);
    const admitted = !withdrawn && detail.exposure_access === 'readable' && detail.record?.id === detail.id
        && detail.record.attribution_state !== 'repair_required' && detail.source_integrity !== 'repair_required';
    const current = withdrawn ? { ...detail, record: null, source_integrity: 'repair_required', command_context: null,
        can: { correct: false, review: false, assign_followup: false, upload_evidence: false } } as ExposureWorkspaceDetail : detail;
    const action = location.action; const sourceId = action === 'complete_followup' || action === 'reassign_followup' ? location.followup ?? undefined : action === 'remove_evidence' ? location.evidence ?? undefined : undefined;
    const row = action === 'complete_followup' || action === 'reassign_followup' ? detail.followups.items?.find((item) => item.id === sourceId)
        : action === 'remove_evidence' ? detail.attachments.items?.find((item) => item.id === sourceId) : undefined;
    const child = row && row.attribution_state !== 'repair_required' ? row as ExposureFollowup | ExposureEvidence : undefined;
    const context = detail.command_context;
    const commandReady = admitted && context?.root_type === 'exposure' && context.root_id === detail.id
        && Number.isSafeInteger(context.expected_version) && context.expected_version >= 0;
    const allowed = commandReady && !!action && (action === 'correct' ? detail.can.correct : action === 'review' ? detail.can.review
        : action === 'assign_followup' ? detail.can.assign_followup : action === 'upload_evidence' ? detail.can.upload_evidence
        : action === 'complete_followup' ? !!child && 'can_complete' in child && child.can_complete && child.completed_at === null
        : action === 'reassign_followup' ? !!child && 'can_reassign' in child && child.can_reassign && child.completed_at === null
        : !!child && 'can_remove' in child && child.can_remove && child.attribution_state === 'recorded');
    const commandKey = `${action}:${sourceId ?? ''}`;
    const [retained, setRetained] = useState<Record<string, Pending>>(() => allowed && action ? { [commandKey]: { action, sourceId, child } } : {});
    useEffect(() => { if (allowed && action) setRetained((entries) => entries[commandKey] ? entries : { ...entries, [commandKey]: { action, sourceId, child } }); }, [allowed, action, commandKey, sourceId, child]);
    const returnTo = exposureReturn(return_to); const section = admitted ? location.section : 'overview';
    const requestedFocus = admitted && section === 'followups' && action === null ? location.followup : null;
    const focusedFollowupId = requestedFocus !== null && detail.followup_focus_state === 'available'
        && detail.focused_followup_id === requestedFocus && detail.followups.items?.some((item) => item.id === requestedFocus && item.attribution_state === 'recorded')
        ? requestedFocus : null;
    const pages = { ...location.pages };
    for (const [key, collection] of [['reviews_page', detail.reviews], ['followups_page', detail.followups],
        ['evidence_page', detail.attachments], ['history_page', detail.history]] as const) {
        const page = collection.page?.current_page;
        if (admitted && page !== undefined && Number.isSafeInteger(page) && page >= 1 && page <= 2147483647) pages[key] = page;
    }
    const title = admitted ? `Chemical exposure — ${detail.record!.worker.name}` : 'Private exposure unavailable';
    const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const href = (next: ExposureSection, nextAction?: ExposureAction, source?: number, targetPages = pages) => exposureHref(detail.id, {
        section: next, action: nextAction, followup: nextAction === 'complete_followup' || nextAction === 'reassign_followup' ? source : undefined,
        evidence: nextAction === 'remove_evidence' ? source : undefined, pages: targetPages, returnTo });
    const move = (next: ExposureSection, nextAction?: ExposureAction, source?: number) => {
        interacted.current = true; router.replace({ url: href(next, nextAction, source), preserveState: true, preserveScroll: true });
    };
    const open = (next: ExposureAction, source?: number) => move(next === 'review' ? 'reviews' : next.includes('followup') ? 'followups' : next.includes('evidence') ? 'evidence' : 'overview', next, source);
    const onPage = (key: ExposurePageKey, page: number) => drafts.request(() => router.get(href(section, undefined, undefined, { ...pages, [key]: page }), {}, { preserveState: true, preserveScroll: true }));
    const checkAccess = () => drafts.request(() => router.get(href('overview'), {}, { preserveState: false, preserveScroll: true }));
    useEffect(() => { if (!action && focusedFollowupId === null && (interacted.current || requestedFocus !== null)) panel.current?.focus(); }, [section, action, focusedFollowupId, requestedFocus]);

    // Header actions: the same commands and current flags as before; Record a review leads.
    const header = [
        commandReady && detail.can.correct ? { key: 'correct', label: 'Correct recorded facts', icon: PencilLine } : null,
        commandReady && detail.can.assign_followup ? { key: 'assign_followup', label: 'Assign a follow-up', icon: UserPlus } : null,
        commandReady && detail.can.upload_evidence ? { key: 'upload_evidence', label: 'Add evidence', icon: Paperclip } : null,
    ].filter(Boolean) as { key: ExposureAction; label: string; icon: typeof PencilLine }[];
    const glass = header.slice(0, 1); const overflow = header.slice(1);
    const actions = admitted ? <>
        {glass.map((item) => <PageHeaderGlassButton key={item.key} icon={item.icon} onClick={() => open(item.key)}>{item.label}</PageHeaderGlassButton>)}
        {overflow.length ? <DropdownMenu><DropdownMenuTrigger asChild><PageHeaderGlassButton icon={MoreHorizontal} aria-label="More exposure actions" /></DropdownMenuTrigger>
            <DropdownMenuContent align="end">{overflow.map((item) => <DropdownMenuItem key={item.key} onSelect={() => open(item.key)}><item.icon className="size-4" />{item.label}</DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu> : null}
        {commandReady && detail.can.review ? <PageHeaderPrimaryButton icon={ClipboardCheck} onClick={() => open('review')}>Record a review</PageHeaderPrimaryButton> : null}
    </> : <PageHeaderPrimaryButton icon={RefreshCw} onClick={checkAccess}>Check current access</PageHeaderPrimaryButton>;
    const counted = (total: number | null | undefined) => (admitted && typeof total === 'number' ? { count: total } : {});
    const rail = admitted ? [SECTIONS[0], { ...SECTIONS[1], ...counted(detail.reviews.page?.total) }, { ...SECTIONS[2], ...counted(detail.followups.page?.total) },
        { ...SECTIONS[3], ...counted(detail.attachments.page?.total) }, { ...SECTIONS[4], ...counted(detail.history.page?.total) }, SECTIONS[5]] : [SECTIONS[0]];
    const stages = admitted ? exposureStages(detail) : null;
    const commandShown = !!action && !!retained[commandKey];
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' },
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title, href: exposureHref(detail.id) }]}>
        <Head title={title} /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" wrapTitle title={title} icon={LockKeyhole} backHref={returnTo}
                subline={admitted ? `${detail.record!.substance.name} · ${detail.record!.site.name}` : 'Current access or source history could not be confirmed'}
                actions={actions}
                titleChip={<PageHeaderStatusChip variant={admitted && detail.source_integrity === 'recorded' ? 'neutral' : 'warning'}>
                    {admitted ? detail.source_integrity === 'legacy_unknown' ? 'Earlier provenance unconfirmed' : 'Private recorded source' : 'Details withheld'}</PageHeaderStatusChip>}
                strip={stages ? <PageHeaderStages steps={stages.steps} next={stages.next} ariaLabel="Exposure stage" /> : undefined}
                rail={<PageHeaderRail items={rail} value={section} onSelect={(next) => move(next)} ariaLabel="Private exposure sections" />} />}>
                <div className="grid min-w-0 gap-5">
                    {chemical_exposure_confirmation && admitted && <p role="status" className="text-subtle">The original submission was confirmed. Current facts and source access were checked separately.</p>}
                    {action && !allowed && !retained[commandKey] && <Surface aria-label="Change unavailable"><p role="alert" className="p-5 text-subtle text-status-warning">This change is unavailable on the current source. Open the recorded section to review it.</p></Surface>}
                    <div className={commandShown ? 'sg-surface p-5' : 'contents'}>
                        {Object.entries(retained).map(([key, pending]) => <ExposureCommandForm key={key} action={pending.action} actorId={shared.auth.user.id} detail={current}
                            sourceId={pending.sourceId} retainedChild={pending.child} active={!!action && key === commandKey} onWithdraw={onWithdraw}
                            onCancel={() => drafts.request(() => { setRetained((entries) => Object.fromEntries(Object.entries(entries).filter(([entry]) => entry !== key))); move(section); })}
                            onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                    </div>
                    <div className={admitted ? 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]' : 'grid min-w-0 gap-5'}>
                        <div ref={panel} tabIndex={-1} role="tabpanel" aria-label={SECTIONS.find((item) => item.key === section)?.label} className="grid min-w-0 gap-5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            {admitted ? <>{requestedFocus !== null && focusedFollowupId === null && <p role="status" className="text-subtle text-status-warning">The linked follow-up cannot currently be confirmed. No follow-up has been selected.</p>}
                                <ExposureRecordPanel detail={current} section={section} open={open} onPage={onPage} focusedFollowupId={focusedFollowupId} /></>
                                : <div role="status"><Note tone="warning">Saved private details and changes are withheld. Retained entries stay in memory until you leave or discard them.</Note></div>}
                            <ExposureDeliveryPanel key={shared.auth.user.id} actorId={shared.auth.user.id} detail={current} active={admitted && section === 'connections'} onWithdraw={onWithdraw} />
                        </div>
                        {admitted ? <aside className="min-w-0" aria-label="Care answers and record"><SideColumn><ExposureRecordSide detail={current} /></SideColumn></aside> : null}
                    </div>
                </div>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
