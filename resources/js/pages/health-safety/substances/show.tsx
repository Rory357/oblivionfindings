/**
 * Chemical record (approved H&S drawings pg-hsn-126 … 126f — Stephan 23 Oct 2026): one header — the name with its
 * status chip and one fact line (form and HSNO classification), the sheet and exposure-access facts, Change status
 * and Edit chemical — and the six sections as rail tabs over main blocks and a side column (`_record-panels.tsx`).
 *
 * Every command keeps its gate and opens the same pop-up; drafts survive section changes; a damaged record hides
 * its details and changes. Exposure records are never shown or counted here.
 */
import { PageHeader, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderFacts } from '@/components/page/page-header-strip';
import { Note, SideColumn, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalShowProps, ChemicalSdsRecord, ChemicalStorageLocation } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalHref, chemicalLocation, chemicalReturn, type ChemicalAction, type ChemicalSection } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FileText, FlaskConical, History, LockKeyhole, MapPin, Pencil, Shield, ToggleLeft } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ChemicalCommandForm } from './_command-form';
import { ChemicalRecordPanel, ChemicalRecordSide } from './_record-panels';
import { PHYSICAL_FORMS, STATUS_OPTIONS, chemicalSheetLabel, optionLabel } from './constants';

const SECTIONS = [{ key: 'overview', label: 'Overview', icon: FlaskConical }, { key: 'controls', label: 'Safe use', icon: Shield },
    { key: 'sds', label: 'Safety data sheets', icon: FileText }, { key: 'storage', label: 'Storage', icon: MapPin },
    { key: 'history', label: 'History', icon: History }, { key: 'exposures', label: 'Exposures (private)', icon: LockKeyhole }] as const;
type Pending = { action: ChemicalAction; storage?: ChemicalStorageLocation; sheet?: ChemicalSdsRecord };
export default function ChemicalShow(props: ChemicalShowProps) { return <ChemicalWorkspace key={props.detail.id} {...props} />; }
function ChemicalWorkspace({ detail, return_to, chemical_confirmation }: ChemicalShowProps) {
    const { url, props: shared } = usePage<SharedData>(); const location = chemicalLocation(url); const drafts = useSafetyWorkspaceDrafts();
    const [withdrawn, setWithdrawn] = useState(false); const onWithdraw = useCallback(() => setWithdrawn(true), []);
    const damaged = detail.source_integrity === 'repair_required' || withdrawn;
    const archived = detail.archived_storage.items ?? [];
    const storage = [...(detail.storage_locations ?? []), ...archived].find((row) => row.id === location.storage);
    const sheet = detail.sds_records?.find((row) => row.id === location.sds);
    const action = location.action;
    const child = action?.includes('storage') && action !== 'add_storage' ? storage : action?.includes('sds') && action !== 'add_sds' ? sheet : null;
    const needsChild = !!action && action !== 'add_storage' && action !== 'add_sds' && (action.includes('storage') || action.includes('sds'));
    const allowed = !damaged && !!action && (!needsChild || !!child && child.source_integrity !== 'repair_required') &&
        (['add_sds', 'add_storage'].includes(action) ? detail.can.create : detail.can.manage && (child && 'can' in child ? child.can.manage : true));
    const commandKey = `${action}:${location.storage ?? ''}:${location.sds ?? ''}`;
    const [retained, setRetained] = useState<Record<string, Pending>>(() => allowed && action ? { [commandKey]: { action, storage, sheet } } : {});
    useEffect(() => { if (allowed && action) setRetained((current) => current[commandKey] ? current : { ...current, [commandKey]: { action, storage, sheet } }); }, [allowed, action, commandKey, storage, sheet]);
    const returnTo = chemicalReturn(return_to); const section: ChemicalSection = damaged ? 'overview' : location.section;
    const title = damaged ? `This chemical record needs fixing · Ref ${detail.id}` : detail.name ?? `Chemical · Ref ${detail.id}`;
    const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const href = (next: ChemicalSection, nextAction?: ChemicalAction, source?: number) => chemicalHref(detail.id, { section: next, action: nextAction,
        storage: nextAction?.includes('storage') ? source : undefined, sds: nextAction?.includes('sds') ? source : undefined, returnTo });
    const move = (next: ChemicalSection, nextAction?: ChemicalAction, source?: number) => { interacted.current = true; router.replace({ url: href(next, nextAction, source), preserveState: true, preserveScroll: true }); };
    const open = (nextAction: ChemicalAction, source?: number) => move(nextAction.includes('sds') ? 'sds' : nextAction.includes('storage') ? 'storage' : 'overview', nextAction, source);
    useEffect(() => { if (interacted.current && !action) panel.current?.focus(); }, [section, action]);
    const counted = (count: number | null | undefined) => (count === null || count === undefined ? {} : { count });
    const rail = damaged ? [SECTIONS[0]] : SECTIONS.map((item) => item.key === 'sds' ? { ...item, ...counted(detail.counts.sds) } : item.key === 'storage' ? { ...item, ...counted(detail.counts.storage) } : item);
    const side = damaged ? null : <ChemicalRecordSide detail={detail} section={section} onStorage={() => move('storage')} />;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Risks and prevention', href: '/health-safety/registers?view=prevention' },
        { title: 'Chemical register', href: CHEMICAL_REGISTER }, { title, href: chemicalHref(detail.id) }]}>
        <Head title={`${title} · Chemical register`} /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" wrapTitle title={title} icon={FlaskConical} backHref={returnTo}
                titleChip={<PageHeaderStatusChip variant={damaged ? 'warning' : 'neutral'}>{damaged ? 'Needs fixing' : optionLabel(STATUS_OPTIONS, detail.status, 'Status unknown')}</PageHeaderStatusChip>}
                subline={damaged ? 'Details hidden until an administrator fixes this record' : `${optionLabel(PHYSICAL_FORMS, detail.physical_form, 'Physical form not recorded')} · ${detail.hsno_classification ?? 'Classification not recorded'}`}
                actions={!damaged && detail.can.manage ? <>
                    <PageHeaderGlassButton icon={ToggleLeft} onClick={() => open('status')}>Change status</PageHeaderGlassButton>
                    <PageHeaderPrimaryButton icon={Pencil} onClick={() => open('edit')}>Edit chemical</PageHeaderPrimaryButton></> : undefined}
                facts={damaged ? undefined : <PageHeaderFacts items={[
                    { value: chemicalSheetLabel(detail.sds_state), label: 'Safety data sheet', tone: detail.sds_state === 'current' ? 'success' : 'warning' },
                    { value: 'Private', label: 'Exposures' },
                ]} />}
                rail={<PageHeaderRail items={rail} value={section} onSelect={(next) => move(next)} ariaLabel="Chemical record sections" />} />}>
                <div className="grid min-w-0 gap-5">
                    {chemical_confirmation && !damaged && <p role="status" className="text-subtle">Saved.</p>}
                    {action && !allowed && !retained[commandKey] && <Surface aria-label="Change not available"><p role="alert" className="p-5 text-subtle text-status-warning">You can’t make this change. It may not apply to this chemical any more, or only people who manage the chemical register can make it — ask your health and safety manager.</p></Surface>}
                    <div className={side ? 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]' : 'grid min-w-0 gap-5'}>
                        <div ref={panel} tabIndex={-1} role="tabpanel" aria-label={SECTIONS.find((item) => item.key === section)?.label} className="grid min-w-0 gap-5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            {damaged ? <Surface aria-label="Details hidden"><div className="grid gap-3 p-5">
                                <div role="status"><Note tone="warning">We can’t show this chemical — you may not have access, or the record needs fixing by an administrator.</Note></div>
                                <div><Button type="button" variant="outline" onClick={() => drafts.request(() => router.get(href('overview'), {}, { preserveState: false, preserveScroll: true }))}>Try again</Button></div>
                            </div></Surface> : <ChemicalRecordPanel detail={detail} section={section} sourceHref={href(section)} open={open} />}
                        </div>
                        {side ? <aside className="min-w-0" aria-label="Storage checks and record"><SideColumn>{side}</SideColumn></aside> : null}
                    </div>
                    {Object.entries(retained).map(([key, pending]) => <ChemicalCommandForm key={`${shared.auth.user.id}:${key}`} action={pending.action} actorId={shared.auth.user.id}
                        detail={withdrawn ? { ...detail, source_integrity: 'repair_required', can: { create: false, manage: false } } as ChemicalShowProps['detail'] : detail}
                        storage={(detail.storage_locations ?? []).find((row) => row.id === pending.storage?.id) ?? archived.find((row) => row.id === pending.storage?.id) ?? pending.storage}
                        sheet={detail.sds_records?.find((row) => row.id === pending.sheet?.id) ?? pending.sheet} active={key === commandKey && !!action} onWithdraw={onWithdraw}
                        sourceAvailable={pending.storage ? [...(detail.storage_locations ?? []), ...archived].some((row) => row.id === pending.storage?.id) : pending.sheet ? (detail.sds_records ?? []).some((row) => row.id === pending.sheet?.id) : true}
                        onCancel={() => drafts.request(() => { setRetained((current) => Object.fromEntries(Object.entries(current).filter(([entry]) => entry !== key))); move(section); })}
                        onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                </div>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
