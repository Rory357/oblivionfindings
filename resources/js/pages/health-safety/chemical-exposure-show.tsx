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
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import { PageHeaderSteps, type PageHeaderStep } from '@/components/page/page-header-strip';
import { PaneLink, RecordProgress, type RecordProgressNext, type RecordProgressPane } from '@/components/signature/record-progress';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { Note, SideColumn, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES, exposureHref, exposureLocation, exposureReturn, type ExposureAction, type ExposurePageKey, type ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureEvidence, ExposureFollowup, ExposureHistoryItem, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, FileText, FileX2, History, Link2, ListChecks, LockKeyhole, MoreHorizontal, Paperclip, PencilLine, RefreshCw, TriangleAlert, UserPlus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ExposureCommandForm } from './components/exposure-command-form';
import { ExposureDeliveryPanel } from './components/exposure-delivery-panel';
import { ExposureEnteredInErrorPopup } from './components/exposure-entered-in-error-popup';
import { earlierLinkChip, ExposureEarlierLink, type EarlierLink } from './components/exposure-earlier-link';
import { ExposureRecordSide } from './components/exposure-overview';
import { ExposureRecordPanel } from './components/exposure-record-panels';
import type { ExposureReceipt } from './components/use-exposure-command';

const SECTIONS = [{ key: 'overview', label: 'Recorded facts', icon: LockKeyhole }, { key: 'reviews', label: 'Reviews', icon: ClipboardCheck },
    { key: 'followups', label: 'Follow-ups', icon: ListChecks }, { key: 'evidence', label: 'Evidence', icon: FileText },
    { key: 'history', label: 'History', icon: History }, { key: 'connections', label: 'Connections', icon: Link2 }] as const;
/** Exposure managers only (job 3): this exposure hasn't reached the H&S register yet. */
type DeliveryAlert = { state: 'needs_manager' | 'failed' | 'overdue'; since: string | null };
type Props = { detail: ExposureWorkspaceDetail; return_to: string | null; chemical_exposure_confirmation: ExposureReceipt | null; delivery_alert?: DeliveryAlert | null;
    /** #15: an earlier, unconfirmed link to an incident and its later reviews (null when the record has none). */
    earlier_link?: EarlierLink | null };
const DELIVERY_ALERT: Record<DeliveryAlert['state'], string> = {
    needs_manager: 'The automatic delivery stopped because the record needs checking. Check it and retry from Connections.',
    failed: 'The automatic delivery failed and is trying again. If it keeps failing, retry it from Connections.',
    overdue: 'It has been waiting more than 10 minutes. Check the delivery from Connections.',
};
type Pending = { action: ExposureAction; sourceId?: number; child?: ExposureFollowup | ExposureEvidence };
const dayOf = (value?: string | null) => (value && toDateInput(value) ? formatDateOnly(toDateInput(value)) : 'Not recorded');

type ExposureStepKey = 'reported' | 'reviewed' | 'followups' | 'outcome';
type ExposureReviewRow = Extract<NonNullable<ExposureWorkspaceDetail['reviews']['items']>[number], { outcome: string }>;

/**
 * Decision 26: reported, reviewed, follow-ups, medical outcome as round status steps, from the record and the loaded
 * first pages of its reviews and follow-ups; the next-step bar and the picked step's pane read the same facts.
 */
function exposureStages(detail: ExposureWorkspaceDetail) {
    const record = detail.record!;
    // The Entered in error entry is not a review of the exposure, so it never marks the Reviewed step.
    const reviews = (detail.reviews.items ?? []).filter((row) => row.attribution_state !== 'repair_required' && (row as ExposureReviewRow).outcome !== 'entered_in_error') as ExposureReviewRow[];
    const followups = (detail.followups.items ?? []).filter((row) => row.attribution_state !== 'repair_required') as ExposureFollowup[];
    const latest = reviews[0]; const reviewed = latest !== undefined || (detail.reviews.page?.total ?? 0) > (detail.entered_in_error ? 1 : 0);
    const open = followups.filter((row) => row.completed_at === null).sort((a, b) => (a.due_at ?? '9999').localeCompare(b.due_at ?? '9999'));
    const first = open[0]; const now = Date.now();
    const overdue = !!first?.due_at && new Date(first.due_at).getTime() < now;
    const careReview = record.response?.care_review_needed === true && record.response.harm_assessment === 'unassessed';
    const steps: PageHeaderStep[] = [
        { key: 'reported', label: 'Reported', meta: [record.recorded_by?.name, record.created_at ? dayOf(record.created_at) : null].filter(Boolean).join(' · ') || undefined, state: 'done' },
        reviewed ? { key: 'reviewed', label: 'Reviewed', meta: latest ? [latest.recorded_by?.name, latest.reviewed_at ? dayOf(latest.reviewed_at) : null].filter(Boolean).join(' · ') || undefined : undefined, state: 'done' }
            : { key: 'reviewed', label: 'Reviewed', meta: careReview ? 'Care answers need review' : 'Not yet', state: 'now' },
        first ? { key: 'followups', label: 'Follow-ups', meta: first.due_at ? `Next due ${dayOf(first.due_at)}` : 'Open', state: reviewed ? 'now' : 'todo' }
            : { key: 'followups', label: 'Follow-ups', meta: followups.length ? 'Done on this page' : 'None on this page', state: followups.length ? 'done' : 'todo' },
        record.medical_outcome ? { key: 'outcome', label: 'Medical outcome', meta: 'Recorded', state: 'done' } : { key: 'outcome', label: 'Medical outcome', meta: 'No outcome yet', state: 'todo' },
    ];
    const current: ExposureStepKey = !reviewed ? 'reviewed' : first ? 'followups' : !record.medical_outcome ? 'outcome' : 'reported';
    return { steps, current, reviewed, latest, reviews, followups, open, first, overdue, careReview };
}
type ExposureStages = ReturnType<typeof exposureStages>;

/** Which step a history entry belongs to: reviews, follow-ups, a medical outcome change, else the report itself. */
function exposureStepOf(entry: ExposureHistoryItem): ExposureStepKey {
    if (/review/.test(entry.command)) return 'reviewed';
    if (/followup/.test(entry.command)) return 'followups';
    if (entry.after.medical_outcome !== undefined && entry.after.medical_outcome !== (entry.before?.medical_outcome ?? null)) return 'outcome';
    return 'reported';
}

/** The picked step's pane: what was recorded there, then its activity from the loaded history page. */
function exposureStepPane(detail: ExposureWorkspaceDetail, stages: ExposureStages, shown: ExposureStepKey,
    go: (section: ExposureSection) => void, onBack?: () => void): RecordProgressPane {
    const record = detail.record!;
    const step = stages.steps.find((item) => item.key === shown);
    const body: Record<ExposureStepKey, Omit<RecordProgressPane, 'title' | 'meta' | 'onBack' | 'activity'>> = {
        reported: { items: [
            { key: 'by', label: 'Reported by', value: record.recorded_by?.name ?? 'Not recorded', meta: record.created_at ? dateTimeWithYear(record.created_at) : undefined },
            { key: 'chemical', label: 'Chemical', value: record.substance.name },
            { key: 'site', label: 'Site', value: record.site.name },
        ] },
        reviewed: {
            items: stages.latest ? [{ key: 'latest', label: 'Latest review', value: stages.latest.outcome,
                meta: [stages.latest.recorded_by?.name, stages.latest.reviewed_at ? dateTimeWithYear(stages.latest.reviewed_at) : null].filter(Boolean).join(' · ') || undefined,
                action: <PaneLink onClick={() => go('reviews')}>All reviews</PaneLink> }] : [],
            empty: stages.careReview ? 'The reported care answers need a review.' : 'Not reviewed yet.',
        },
        followups: {
            items: stages.open.length ? [{ key: 'open', label: 'Open follow-ups', value: `${stages.open.length} on this page`,
                action: <PaneLink onClick={() => go('followups')}>All follow-ups</PaneLink> }] : [],
            children: stages.open.length ? (
                <ul className="grid gap-1 px-[18px] pb-3 text-sm">
                    {stages.open.map((item) => (
                        <li key={item.id}>
                            <b className="font-medium">{item.instruction}</b>
                            <span className="text-muted-foreground"> · {item.assigned_to?.name ?? 'a named person'}{item.due_at ? ` · due ${dayOf(item.due_at)}` : ''}</span>
                        </li>
                    ))}
                </ul>
            ) : undefined,
            empty: stages.followups.length ? 'The follow-ups on this page are done.' : 'No follow-ups on this page.',
        },
        outcome: {
            items: record.medical_outcome ? [{ key: 'outcome', label: 'Medical outcome', value: record.medical_outcome }] : [],
            empty: 'No medical outcome recorded yet. Correct the recorded facts when it is known.',
        },
    };
    return {
        title: step?.label ?? 'Step', meta: step?.meta, onBack, ...body[shown],
        activity: {
            items: (detail.history.items ?? []).filter((entry) => exposureStepOf(entry) === shown).map((entry) => ({
                key: entry.id, time: dateTimeWithYear(entry.recorded_at), who: entry.recorded_by?.name,
                what: `${entry.command.replace(/_/g, ' ')}${entry.reason ? `: ${entry.reason}` : ''}`,
            })),
            onAll: () => go('history'),
        },
    };
}

/** The Progress card for an exposure: the fixed next-step bar, then the picked step's pane. */
function ExposureProgress({ next, detail, stages, shown, onGo, onBack }: { next: RecordProgressNext | null; detail: ExposureWorkspaceDetail;
    stages: ExposureStages; shown: ExposureStepKey; onGo: (section: ExposureSection) => void; onBack?: () => void }) {
    return <RecordProgress next={next} pane={exposureStepPane(detail, stages, shown, onGo, onBack)} />;
}

export default function ChemicalExposureShow(props: Props) {
    const { props: shared } = usePage<SharedData>();
    return <ExposureWorkspace key={`${shared.auth.user.id}:${props.detail.id}`} {...props} />;
}
function ExposureWorkspace({ detail, return_to, chemical_exposure_confirmation, delivery_alert = null, earlier_link = null }: Props) {
    const { url, props: shared } = usePage<SharedData>(); const location = exposureLocation(url); const drafts = useSafetyWorkspaceDrafts();
    const [picked, setPicked] = useState<ExposureStepKey | null>(null);
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
    // Entered in error (6 Nov 2026): kept as it is; the server turns every change off and offers the mark to exposure managers.
    const inError = admitted ? detail.entered_in_error ?? null : null;
    const markAllowed = commandReady && detail.can.mark_entered_in_error === true && !inError;
    const [marking, setMarking] = useState(false);
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
    const markedDone = useCallback(() => { setMarking(false); interacted.current = true; router.get(exposureHref(detail.id, { section: 'overview', returnTo }), {}, { preserveState: false, preserveScroll: true }); }, [detail.id, returnTo]);
    const checkAccess = () => drafts.request(() => router.get(href('overview'), {}, { preserveState: false, preserveScroll: true }));
    useEffect(() => { if (!action && focusedFollowupId === null && (interacted.current || requestedFocus !== null)) panel.current?.focus(); }, [section, action, focusedFollowupId, requestedFocus]);

    // Decision 26: the Progress card under the header holds the record's one filled button in its next-step bar.
    const stages = admitted ? exposureStages(detail) : null;
    const commandShown = !!action && !!retained[commandKey];
    const shownStep: ExposureStepKey | null = stages ? picked ?? stages.current : null;
    const progressShown = !!stages && section === 'overview' && !commandShown;
    const completeNext = commandReady && stages?.first?.can_complete ? stages.first : null;
    const barUsesReview = !completeNext && commandReady && detail.can.review;
    const nextButton = completeNext ? (
        <Button onClick={() => open('complete_followup', completeNext.id)}><CheckCircle2 aria-hidden="true" />Complete the follow-up</Button>
    ) : barUsesReview ? (
        <Button onClick={() => open('review')}><ClipboardCheck aria-hidden="true" />Record a review</Button>
    ) : undefined;
    const next: RecordProgressNext | null = !stages ? null : inError ? {
        kicker: 'Entered in error', title: 'Kept for the record',
        text: [inError.by?.name, inError.at ? dateTimeWithYear(inError.at) : null].filter(Boolean).join(' · ') || 'Marked as entered in error', tone: 'done',
    } : stages.first ? {
        kicker: stages.first.due_at ? `Next step · ${stages.overdue ? 'was due' : 'due'} ${dateTimeWithYear(stages.first.due_at)}` : 'Next step', title: stages.first.instruction,
        text: <>Follow-up for <b>{stages.first.assigned_to?.name ?? 'a named person'}</b>{stages.first.recorded_by ? <> · assigned by {stages.first.recorded_by.name}</> : null}</>, action: nextButton,
    } : !stages.reviewed ? {
        title: stages.careReview ? 'Review the reported care answers' : 'Record a review', text: stages.careReview ? 'Care answers need review' : 'Nobody has reviewed this exposure yet.', action: nextButton,
    } : !detail.record!.medical_outcome ? {
        title: 'Record the medical outcome when it is known', text: 'Correct the recorded facts to add it.', action: nextButton,
    } : { kicker: 'Up to date', title: 'Nothing waiting', text: 'Reviewed, with no open follow-ups on this page.', tone: 'done' };

    // Header actions: the same commands and current flags as before; Record a review sits in the next-step bar while it shows.
    const header = [
        commandReady && detail.can.correct ? { key: 'correct', label: 'Correct recorded facts', icon: PencilLine } : null,
        commandReady && detail.can.assign_followup ? { key: 'assign_followup', label: 'Assign a follow-up', icon: UserPlus } : null,
        commandReady && detail.can.upload_evidence ? { key: 'upload_evidence', label: 'Add evidence', icon: Paperclip } : null,
    ].filter(Boolean) as { key: ExposureAction; label: string; icon: typeof PencilLine }[];
    const glass = header.slice(0, 1); const overflow = header.slice(1);
    const actions = admitted ? <>
        {glass.map((item) => <PageHeaderGlassButton key={item.key} icon={item.icon} onClick={() => open(item.key)}>{item.label}</PageHeaderGlassButton>)}
        {overflow.length || markAllowed ? <DropdownMenu><DropdownMenuTrigger asChild><PageHeaderGlassButton icon={MoreHorizontal} aria-label="More exposure actions" /></DropdownMenuTrigger>
            <DropdownMenuContent align="end">{overflow.map((item) => <DropdownMenuItem key={item.key} onSelect={() => open(item.key)}><item.icon className="size-4" />{item.label}</DropdownMenuItem>)}
                {markAllowed ? <DropdownMenuItem onSelect={() => drafts.request(() => setMarking(true))}><FileX2 className="size-4" />Mark as entered in error</DropdownMenuItem> : null}</DropdownMenuContent></DropdownMenu> : null}
        {commandReady && detail.can.review && !(progressShown && barUsesReview) ? <PageHeaderGlassButton icon={ClipboardCheck} onClick={() => open('review')}>Record a review</PageHeaderGlassButton> : null}
    </> : <PageHeaderPrimaryButton icon={RefreshCw} onClick={checkAccess}>Check current access</PageHeaderPrimaryButton>;
    const counted = (total: number | null | undefined) => (admitted && typeof total === 'number' ? { count: total } : {});
    const rail = admitted ? [SECTIONS[0], { ...SECTIONS[1], ...counted(detail.reviews.page?.total) }, { ...SECTIONS[2], ...counted(detail.followups.page?.total) },
        { ...SECTIONS[3], ...counted(detail.attachments.page?.total) }, { ...SECTIONS[4], ...counted(detail.history.page?.total) }, SECTIONS[5]] : [SECTIONS[0]];
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, ...safetyHubCrumbs('care', shared.auth.can),
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title, href: exposureHref(detail.id) }]}>
        <Head title={title} /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" wrapTitle title={title} icon={LockKeyhole} backHref={returnTo}
                subline={admitted ? `${detail.record!.substance.name} · ${detail.record!.site.name}` : earlier_link ? 'An older record saved with an unconfirmed link to an incident · its private details stay withheld' : 'Current access or source history could not be confirmed'}
                actions={actions}
                titleChip={<PageHeaderStatusChip variant={admitted && (inError || detail.source_integrity === 'recorded' || earlier_link?.state === 'reviewed') ? 'neutral' : 'warning'}>
                    {admitted ? inError ? 'Entered in error' : detail.source_integrity === 'legacy_unknown' ? earlierLinkChip(earlier_link) ?? 'Earlier provenance unconfirmed' : 'Private recorded source' : earlierLinkChip(earlier_link) ?? 'Details withheld'}</PageHeaderStatusChip>}
                strip={stages ? <PageHeaderSteps steps={stages.steps} selected={progressShown ? shownStep : null} onSelect={(key) => { setPicked(key === stages.current ? null : key as ExposureStepKey); if (section !== 'overview' || action) drafts.request(() => move('overview')); }} ariaLabel="Exposure steps" /> : undefined}
                rail={<PageHeaderRail items={rail} value={section} onSelect={(next) => move(next)} ariaLabel="Private exposure sections" />} />}>
                <div className="grid min-w-0 gap-5">
                    {inError ? (
                        <Note icon={FileX2}>
                            <p><b>Marked as entered in error</b>{inError.by ? ` by ${inError.by.name}` : ''}{inError.at ? ` on ${dateTimeWithYear(inError.at)}` : ''}. It’s kept for the record, left out of the lists and can’t be changed.</p>
                            {inError.reason ? <p className="mt-1">Reason: {inError.reason}</p> : null}
                        </Note>
                    ) : null}
                    {admitted && delivery_alert ? (
                        <Note tone={delivery_alert.state === 'needs_manager' ? 'critical' : 'warning'} icon={TriangleAlert}>
                            <p><b>This exposure isn’t on the H&amp;S register yet.</b> {DELIVERY_ALERT[delivery_alert.state]}</p>
                            {section !== 'connections' ? (
                                <Button type="button" variant="link" size="sm" className="mt-1 h-auto px-0" onClick={() => move('connections')}>
                                    Open delivery checks
                                </Button>
                            ) : null}
                        </Note>
                    ) : null}
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
                                {progressShown && stages && shownStep ? <ExposureProgress next={next} detail={detail} stages={stages} shown={shownStep} onGo={(target) => drafts.request(() => move(target))} onBack={shownStep !== stages.current ? () => setPicked(null) : undefined} /> : null}
                                <ExposureRecordPanel detail={current} section={section} open={open} onPage={onPage} focusedFollowupId={focusedFollowupId} /></>
                                : earlier_link ? (
                                    // #15: the private details of an older record with an unconfirmed link stay sealed; its link can still be reviewed.
                                    <ExposureEarlierLink key={`${shared.auth.user.id}:${earlier_link.expected_version ?? 'r'}`} link={earlier_link} exposureId={detail.id} />
                                ) : <div role="status"><Note tone="warning">Saved private details and changes are withheld. Retained entries stay in memory until you leave or discard them.</Note></div>}
                            {admitted && section === 'connections' && earlier_link ? (
                                <ExposureEarlierLink key={`${shared.auth.user.id}:${earlier_link.expected_version ?? 'r'}`} link={earlier_link} exposureId={detail.id} />
                            ) : null}
                            <ExposureDeliveryPanel key={shared.auth.user.id} actorId={shared.auth.user.id} detail={current} active={admitted && section === 'connections'} autoCheck={!!delivery_alert} onWithdraw={onWithdraw} />
                        </div>
                        {admitted ? <aside className="min-w-0" aria-label="Care answers and record"><SideColumn><ExposureRecordSide detail={current} /></SideColumn></aside> : null}
                    </div>
                </div>
            </PageLayout>
            {markAllowed ? <ExposureEnteredInErrorPopup open={marking} detail={detail} actorId={shared.auth.user.id} allowed={markAllowed}
                onClose={() => setMarking(false)} onDone={markedDone} /> : null}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
