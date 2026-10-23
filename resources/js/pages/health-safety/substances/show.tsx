import { PageHeader, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalShowProps, ChemicalSdsRecord, ChemicalStorageLocation } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalHref, chemicalLocation, chemicalReturn, type ChemicalAction, type ChemicalSection } from '@/lib/health-safety/chemical-navigation';
import { formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FileText, FlaskConical, History, LockKeyhole, MapPin, Shield } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ChemicalCommandForm } from './_command-form';
import { ChemicalRecordPanel } from './_record-panels';

const SECTIONS = [{ key: 'overview', label: 'Overview', icon: FlaskConical }, { key: 'controls', label: 'Safe use', icon: Shield },
    { key: 'sds', label: 'Safety data sheets', icon: FileText }, { key: 'storage', label: 'Storage', icon: MapPin },
    { key: 'history', label: 'History', icon: History }, { key: 'exposures', label: 'Private exposures', icon: LockKeyhole }] as const;
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
    const title = damaged ? 'Chemical source needs review' : detail.name ?? `Chemical reference ${detail.id}`;
    const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const href = (next: ChemicalSection, nextAction?: ChemicalAction, source?: number) => chemicalHref(detail.id, { section: next, action: nextAction,
        storage: nextAction?.includes('storage') ? source : undefined, sds: nextAction?.includes('sds') ? source : undefined, returnTo });
    const move = (next: ChemicalSection, nextAction?: ChemicalAction, source?: number) => { interacted.current = true; router.replace({ url: href(next, nextAction, source), preserveState: true, preserveScroll: true }); };
    const open = (nextAction: ChemicalAction, source?: number) => move(nextAction.includes('sds') ? 'sds' : nextAction.includes('storage') ? 'storage' : 'overview', nextAction, source);
    useEffect(() => { if (interacted.current && !action) panel.current?.focus(); }, [section, action]);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Chemical register', href: CHEMICAL_REGISTER }, { title, href: chemicalHref(detail.id) }]}>
        <Head title={`${title} · Chemical register`} /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" wrapTitle title={title} icon={FlaskConical} backHref={returnTo}
                titleChip={<PageHeaderStatusChip variant={damaged ? 'warning' : 'neutral'}>{damaged ? 'Source needs review' : detail.status ?? 'Status unconfirmed'}</PageHeaderStatusChip>}
                subline={damaged ? 'Saved details and changes are withheld' : `${detail.physical_form ?? 'Physical form not recorded'} · ${detail.hsno_classification ?? 'Classification not recorded'}`}
                actions={!damaged && detail.can.manage ? <PageHeaderGlassButton icon={FlaskConical} onClick={() => open('edit')}>Edit reference</PageHeaderGlassButton> : undefined}
                meters={!damaged ? <>
                    <PageHeaderMeterBlock label="Safety data sheets" onClick={() => move('sds')}><PageHeaderMeterBig>{detail.counts.sds ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Admitted recorded sheets</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Sheet state" onClick={() => move('sds')}><PageHeaderMeterBig>{detail.sds_state.replaceAll('_', ' ')}</PageHeaderMeterBig><PageHeaderMeterCaption>Recorded currency</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Storage locations" onClick={() => move('storage')}><PageHeaderMeterBig>{detail.counts.storage ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Current admitted locations</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Exposure records" onClick={() => move('exposures')}><PageHeaderMeterBig>Restricted</PageHeaderMeterBig><PageHeaderMeterCaption>Private wellbeing access required</PageHeaderMeterCaption></PageHeaderMeterBlock>
                </> : undefined} rail={<PageHeaderRail items={damaged ? [SECTIONS[0]] : [...SECTIONS]} value={section} onSelect={(next) => move(next)} ariaLabel="Chemical record sections" />} />}>
                <Card><CardContent className="space-y-5 p-5"><div ref={panel} tabIndex={-1} className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {damaged ? <div className="space-y-3"><p role="status" className="text-subtle text-status-warning">Current access or source history cannot be confirmed. Saved details and changes are withheld.</p>
                        <Button type="button" variant="outline" className="frontline-tap" onClick={() => drafts.request(() => router.get(href('overview'), {}, { preserveState: false, preserveScroll: true }))}>Check current access</Button></div> :
                        <ChemicalRecordPanel detail={detail} section={section} sourceHref={href(section)} open={open} />}
                </div>
                    {chemical_confirmation && !damaged && <p role="status" className="text-subtle">The original submission was confirmed. Current details shown here were checked separately.</p>}
                    {action && !allowed && !retained[commandKey] && <p role="alert" className="text-subtle text-status-warning">This change is unavailable for the current record.</p>}
                    {!damaged && <p className="text-caption text-muted-foreground">Last recorded update: {formatDateTime(detail.updated_at, 'Not recorded')}</p>}
                    {Object.entries(retained).map(([key, pending]) => <ChemicalCommandForm key={`${shared.auth.user.id}:${key}`} action={pending.action} actorId={shared.auth.user.id}
                        detail={withdrawn ? { ...detail, source_integrity: 'repair_required', can: { create: false, manage: false } } as ChemicalShowProps['detail'] : detail}
                        storage={(detail.storage_locations ?? []).find((row) => row.id === pending.storage?.id) ?? archived.find((row) => row.id === pending.storage?.id) ?? pending.storage}
                        sheet={detail.sds_records?.find((row) => row.id === pending.sheet?.id) ?? pending.sheet} active={key === commandKey && !!action} onWithdraw={onWithdraw}
                        sourceAvailable={pending.storage ? [...(detail.storage_locations ?? []), ...archived].some((row) => row.id === pending.storage?.id) : pending.sheet ? (detail.sds_records ?? []).some((row) => row.id === pending.sheet?.id) : true}
                        onCancel={() => drafts.request(() => { setRetained((current) => Object.fromEntries(Object.entries(current).filter(([entry]) => entry !== key))); move(section); })}
                        onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                </CardContent></Card>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
