import { PageHeader, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { PPE_REGISTER, ppeHref, ppeLocation, ppeReturn, type PpeCommand, type PpePageKey, type PpeSection } from '@/lib/health-safety/ppe-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardCheck, FileText, HardHat, History, Paperclip, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PpeCommandForm } from './_command-form';
import { PPE_COMMAND_LABELS, ppeCommandAllowed } from './_command-state';
import { ppeDay, PpeRecordPanel } from './_record-panels';
import type { PpeRecord, PpeRepairRecord } from './_workspace-types';
import { statusLabel } from './ppe-shared';

const SECTIONS = [{ key: 'overview', label: 'Overview', icon: FileText }, { key: 'allocations', label: 'Issues', icon: UserRound },
    { key: 'inspections', label: 'Inspections', icon: ClipboardCheck }, { key: 'fit-training', label: 'Fit and training', icon: ClipboardCheck },
    { key: 'evidence', label: 'Evidence', icon: Paperclip }, { key: 'history', label: 'History', icon: History }] as const;
const COMMAND_SECTION: Record<PpeCommand, PpeSection> = { edit: 'overview', allocate: 'allocations', inspect: 'inspections', condemn: 'overview',
    dispose: 'overview', acknowledge: 'overview', return: 'overview', hold: 'overview', release: 'overview', upload_evidence: 'evidence', remove_evidence: 'evidence', relocate_evidence: 'evidence' };
export default function PpeShow(props: (PpeRecord | PpeRepairRecord) & { return_to?: string | null }) { return <PpeWorkspace key={`${props.kind}:${props.detail.id}`} {...props} />; }
function PpeWorkspace(props: (PpeRecord | PpeRepairRecord) & { return_to?: string | null }) {
    const { url, props: shared } = usePage<SharedData>();
    const previous = useRef<PpeRecord | null>(null);
    const damaged = props.detail.source_integrity === 'repair_required';
    const record: PpeRecord = damaged && previous.current
        ? { ...previous.current, detail: { ...previous.current.detail, can: Object.fromEntries(Object.keys(previous.current.detail.can).map((key) => [key, false])) } } as PpeRecord
        : props as PpeRecord;
    if (!damaged) previous.current = record;
    const location = ppeLocation(url); const returnTo = ppeReturn(props.return_to ?? location.returnTo);
    const own = record.kind === 'allocations' && record.detail.read_scope === 'own_allocation';
    const sections = damaged ? [SECTIONS[0]] : SECTIONS.filter((item) => record.kind === 'inventory' ? item.key !== 'fit-training' : record.kind === 'allocations' ?
        !['allocations', 'inspections'].includes(item.key) && (!own || !['evidence', 'history'].includes(item.key)) : ['overview', 'evidence', 'history'].includes(item.key));
    const section = sections.some((item) => item.key === location.section) ? location.section : 'overview';
    const command = location.command; const drafts = useSafetyWorkspaceDrafts(); const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const commandKey = `${record.kind}:${record.detail.id}:${command}:${location.attachment ?? ''}`;
    const permitted = !damaged && !!command && ppeCommandAllowed(record, command, location.attachment);
    const [retained, setRetained] = useState<Record<string, { command: PpeCommand; attachment: number | null }>>(() => permitted && command ? { [commandKey]: { command, attachment: location.attachment } } : {});
    useEffect(() => { if (permitted && command) setRetained((current) => current[commandKey] ? current : { ...current, [commandKey]: { command, attachment: location.attachment } }); }, [permitted, commandKey, command, location.attachment]);
    const href = (next: PpeSection, action?: PpeCommand | null, attachment?: number, pages = location.pages) => ppeHref(record.kind, record.detail.id, { section: next, command: action, attachment, returnTo, pages });
    // Sections keep the same canonical record and mounted in-memory drafts.
    const move = (next: PpeSection, action?: PpeCommand | null, attachment?: number) => { interacted.current = true; router.replace({ url: href(next, action, attachment), preserveState: true, preserveScroll: true }); };
    const open = (action: PpeCommand, attachment?: number) => move(COMMAND_SECTION[action], action, attachment);
    const page = (key: PpePageKey, next: number) => drafts.request(() => router.get(href(section, null, undefined, { ...location.pages, [key]: next }), {}, { preserveState: true, preserveScroll: true }));
    const title = damaged ? 'Equipment source needs review' : record.kind === 'inventory' ? record.detail.ppe_type?.name ?? `Equipment ${record.detail.id}` : record.kind === 'allocations' ? `PPE issue ${record.detail.id}` : `Inspection ${record.detail.id}`;
    const state = damaged ? 'Source needs review' : record.kind === 'inventory' ? statusLabel(record.detail.status) : record.kind === 'allocations' ? record.detail.returned_at ? 'Returned' : record.detail.acknowledged ? 'Issue acknowledged' : 'Awaiting acknowledgement' : record.detail.result.replaceAll('_', ' ');
    const next = (damaged ? [] : record.kind === 'inventory' ? ['inspect', 'release', 'allocate'] : record.kind === 'allocations' ? ['acknowledge', 'return'] : [])
        .find((action) => ppeCommandAllowed(record, action as PpeCommand)) as PpeCommand | undefined;
    useEffect(() => { if (interacted.current && !command) panel.current?.focus(); }, [section, command]);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'PPE & equipment', href: own ? returnTo : PPE_REGISTER }, { title, href: ppeHref(record.kind, record.detail.id) }]}>
        <Head title={`${title} · PPE & equipment`} />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" wrapTitle title={title} icon={HardHat} backHref={returnTo} titleChip={<PageHeaderStatusChip variant={record.kind === 'inventory' && ['maintenance', 'condemned'].includes(record.detail.status) ? 'warning' : 'neutral'}>{state}</PageHeaderStatusChip>}
                subline={damaged ? 'Retained record details are withheld while source history is reviewed' : record.kind === 'inventory' ? `${record.detail.site?.name ?? 'Site unavailable'} · ${record.detail.serial_number ?? 'Serial number not recorded'}` : record.kind === 'allocations' ? `${record.detail.user?.name ?? 'Worker unavailable'} · ${record.detail.inventory_item?.site?.name ?? 'Site unavailable'}` : `Equipment record ${record.detail.ppe_inventory_id}`}
                actions={<>{!damaged && !own && ppeCommandAllowed(record, 'upload_evidence') && <PageHeaderGlassButton icon={Paperclip} onClick={() => open('upload_evidence')}>Add evidence</PageHeaderGlassButton>}{next && <PageHeaderPrimaryButton onClick={() => open(next)}>{PPE_COMMAND_LABELS[next]}</PageHeaderPrimaryButton>}</>}
                meters={!damaged && record.kind === 'inventory' ? <><PageHeaderMeterBlock label="Quantity" href={href('overview')}><PageHeaderMeterBig>{record.detail.quantity}</PageHeaderMeterBig><PageHeaderMeterCaption>Recorded item or batch</PageHeaderMeterCaption></PageHeaderMeterBlock><PageHeaderMeterBlock label="Expiry" href={href('overview')}><PageHeaderMeterBig>{ppeDay(record.detail.expiry_date)}</PageHeaderMeterBig><PageHeaderMeterCaption>{record.detail.currency.expiry_state === 'unknown' ? 'Date not recorded' : record.detail.currency.expiry_state === 'expired' ? 'Expired' : 'Recorded date'}</PageHeaderMeterCaption></PageHeaderMeterBlock><PageHeaderMeterBlock label="Next inspection" href={href('inspections')}><PageHeaderMeterBig>{ppeDay(record.detail.next_inspection_due)}</PageHeaderMeterBig><PageHeaderMeterCaption>{record.detail.currency.inspection_state === 'overdue' ? 'Inspection overdue' : 'Recorded inspection date'}</PageHeaderMeterCaption></PageHeaderMeterBlock></> : undefined}
                rail={<PageHeaderRail items={sections} value={section} onSelect={(next) => move(next)} ariaLabel="Equipment record sections" />} />}>
                <Card><CardContent className="space-y-5 p-5"><div ref={panel} tabIndex={-1} className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {damaged && <p role="status" className="mb-5 text-subtle text-status-warning">Source history needs review. Changes are withheld until its retained history can be confirmed.</p>}
                    {!damaged && <PpeRecordPanel record={record} section={section} sourceHref={href(section)} open={open} page={page} />}
                </div>
                {command && !permitted && !retained[commandKey] && <div className="space-y-3"><p role="alert" className="text-subtle text-status-warning">This change is unavailable for the current record.</p><Button type="button" className="frontline-tap" variant="outline" onClick={() => move(section)}>Return to record</Button></div>}
                {Object.entries(retained).map(([key, pending]) => <PpeCommandForm key={`${shared.auth.user.id}:${key}`} record={record} command={pending.command} actorId={shared.auth.user.id} attachment={pending.attachment} active={!!command && key === commandKey}
                    onCancel={() => drafts.request(() => { setRetained((current) => Object.fromEntries(Object.entries(current).filter(([entry]) => entry !== key))); move(section); })}
                    onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                </CardContent></Card>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
