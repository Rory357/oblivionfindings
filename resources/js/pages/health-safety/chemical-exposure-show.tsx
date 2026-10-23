import { PageHeader, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { CHEMICAL_EXPOSURES, exposureHref, exposureLocation, exposureReturn, type ExposureAction, type ExposurePageKey, type ExposureSection } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureEvidence, ExposureFollowup, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardCheck, FileText, History, Link2, ListChecks, LockKeyhole } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ExposureCommandForm } from './components/exposure-command-form';
import { ExposureRecordPanel } from './components/exposure-record-panels';
import { ExposureDeliveryPanel } from './components/exposure-delivery-panel';
import type { ExposureReceipt } from './components/use-exposure-command';

const SECTIONS = [{ key: 'overview', label: 'Recorded facts', icon: LockKeyhole }, { key: 'reviews', label: 'Reviews', icon: ClipboardCheck },
    { key: 'followups', label: 'Follow-ups', icon: ListChecks }, { key: 'evidence', label: 'Evidence', icon: FileText },
    { key: 'history', label: 'History', icon: History }, { key: 'connections', label: 'Connections', icon: Link2 }] as const;
type Props = { detail: ExposureWorkspaceDetail; return_to: string | null; chemical_exposure_confirmation: ExposureReceipt | null };
type Pending = { action: ExposureAction; sourceId?: number; child?: ExposureFollowup | ExposureEvidence };
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
    const title = admitted ? `Exposure · ${detail.record!.worker.name}` : 'Private exposure unavailable';
    const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const href = (next: ExposureSection, nextAction?: ExposureAction, source?: number, targetPages = pages) => exposureHref(detail.id, {
        section: next, action: nextAction, followup: nextAction === 'complete_followup' || nextAction === 'reassign_followup' ? source : undefined,
        evidence: nextAction === 'remove_evidence' ? source : undefined, pages: targetPages, returnTo });
    const move = (next: ExposureSection, nextAction?: ExposureAction, source?: number) => {
        interacted.current = true; router.replace({ url: href(next, nextAction, source), preserveState: true, preserveScroll: true });
    };
    const open = (next: ExposureAction, source?: number) => move(next === 'review' ? 'reviews' : next.includes('followup') ? 'followups' : next.includes('evidence') ? 'evidence' : 'overview', next, source);
    const onPage = (key: ExposurePageKey, page: number) => drafts.request(() => router.get(href(section, undefined, undefined, { ...pages, [key]: page }), {}, { preserveState: true, preserveScroll: true }));
    useEffect(() => { if (!action && focusedFollowupId === null && (interacted.current || requestedFocus !== null)) panel.current?.focus(); }, [section, action, focusedFollowupId, requestedFocus]);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title, href: exposureHref(detail.id) }]}>
        <Head title={title} /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" wrapTitle title={title} icon={LockKeyhole} backHref={returnTo}
                subline={admitted ? `${detail.record!.substance.name} · ${detail.record!.site.name}` : 'Current access or source history could not be confirmed'}
                actions={admitted && detail.can.correct ? <PageHeaderGlassButton icon={FileText} onClick={() => drafts.request(() => router.get(`${CHEMICAL_EXPOSURES}/recovery`))}>Evidence recovery</PageHeaderGlassButton> : undefined}
                titleChip={<PageHeaderStatusChip variant={admitted && detail.source_integrity === 'recorded' ? 'neutral' : 'warning'}>
                    {admitted ? detail.source_integrity === 'legacy_unknown' ? 'Earlier provenance unconfirmed' : 'Private recorded source' : 'Details withheld'}</PageHeaderStatusChip>}
                meters={admitted ? <>
                    <PageHeaderMeterBlock label="Reviews" onClick={() => move('reviews')}><PageHeaderMeterBig>{detail.reviews.page?.total ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Recorded reviews</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Follow-ups" onClick={() => move('followups')}><PageHeaderMeterBig>{detail.followups.page?.total ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Assigned records · view completion</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Evidence" onClick={() => move('evidence')}><PageHeaderMeterBig>{detail.attachments.page?.total ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Recorded attachments · access checked separately</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="History" onClick={() => move('history')}><PageHeaderMeterBig>{detail.history.page?.total ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Admitted accepted changes</PageHeaderMeterCaption></PageHeaderMeterBlock>
                </> : undefined} rail={<PageHeaderRail items={admitted ? [...SECTIONS] : [SECTIONS[0]]} value={section} onSelect={(next) => move(next)} ariaLabel="Private exposure sections" />} />}>
                <Card><CardContent className="space-y-5 p-5"><div ref={panel} tabIndex={-1} className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {admitted ? <>{requestedFocus !== null && focusedFollowupId === null && <p role="status" className="mb-5 text-subtle text-status-warning">The linked follow-up cannot currently be confirmed. No follow-up has been selected.</p>}
                        <ExposureRecordPanel detail={current} section={section} open={open} onPage={onPage} focusedFollowupId={focusedFollowupId} /></> : <div className="space-y-3">
                        <p role="status" className="text-subtle text-status-warning">Saved private details and changes are withheld. Retained entries stay in memory until you leave or discard them.</p>
                        <Button type="button" variant="outline" className="frontline-tap" onClick={() => drafts.request(() => router.get(href('overview'), {}, { preserveState: false, preserveScroll: true }))}>Check current access</Button></div>}
                </div><ExposureDeliveryPanel key={shared.auth.user.id} actorId={shared.auth.user.id} detail={current} active={admitted && section === 'connections'} onWithdraw={onWithdraw} />
                    {chemical_exposure_confirmation && admitted && <p role="status" className="text-subtle">The original submission was confirmed. Current facts and source access were checked separately.</p>}
                    {action && !allowed && !retained[commandKey] && <p role="alert" className="text-subtle text-status-warning">This change is unavailable on the current source. Open the recorded section to review it.</p>}
                    {Object.entries(retained).map(([key, pending]) => <ExposureCommandForm key={key} action={pending.action} actorId={shared.auth.user.id} detail={current}
                        sourceId={pending.sourceId} retainedChild={pending.child} active={!!action && key === commandKey} onWithdraw={onWithdraw}
                        onCancel={() => drafts.request(() => { setRetained((entries) => Object.fromEntries(Object.entries(entries).filter(([entry]) => entry !== key))); move(section); })}
                        onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                </CardContent></Card>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
