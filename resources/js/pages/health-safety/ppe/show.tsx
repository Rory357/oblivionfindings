/**
 * PPE record page (approved H&S drawings pg-hsn-121 … 121e equipment, 122 … 122c issue, 123 inspection — Stephan
 * 23 Oct 2026): one header per record kind — the title with its status chip and one fact line, fact chips and the
 * stage line with its next step (equipment and issues), two glass actions, the rest under More, and one primary
 * action — then the record's sections as rail tabs over main blocks and a side column (`_record-panels.tsx`).
 *
 * Every command keeps its gate (`ppeCommandAllowed`) and its form: edit, issue, inspect and add evidence open their
 * wizard pop-ups, the rest open in place above the record. Drafts survive section changes; a record whose source
 * needs repair withholds its facts and commands; a worker's own issue shows only their own facts.
 */
import { PageHeader, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderFacts, PageHeaderStages, type PageHeaderFact, type PageHeaderNextStep, type PageHeaderStage } from '@/components/page/page-header-strip';
import { Note, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { StatusVariant } from '@/components/ui/status-badge';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { PPE_REGISTER, ppeHref, ppeLocation, ppeReturn, type PpeCommand, type PpePageKey, type PpeSection } from '@/lib/health-safety/ppe-navigation';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    HardHat,
    History,
    MoreHorizontal,
    Paperclip,
    PauseCircle,
    Pencil,
    PlayCircle,
    Trash2,
    Undo2,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PpeCommandForm } from './_command-form';
import { PPE_COMMAND_LABELS, ppeCommandAllowed } from './_command-state';
import { PpeRecordPanel, ppeResultLabel } from './_record-panels';
import type { PpeAllocation, PpeItem, PpeRecord, PpeRepairRecord } from './_workspace-types';
import { statusLabel, statusTone } from './ppe-shared';

const SECTIONS = [{ key: 'overview', label: 'Overview', icon: FileText }, { key: 'allocations', label: 'Issued to', icon: UserRound },
    { key: 'inspections', label: 'Inspections', icon: ClipboardCheck }, { key: 'fit-training', label: 'Fit and training', icon: ClipboardCheck },
    { key: 'evidence', label: 'Files', icon: Paperclip }, { key: 'history', label: 'History', icon: History }] as const;
const COMMAND_SECTION: Record<PpeCommand, PpeSection> = { edit: 'overview', allocate: 'allocations', inspect: 'inspections', condemn: 'overview',
    dispose: 'overview', acknowledge: 'overview', return: 'overview', hold: 'overview', release: 'overview', upload_evidence: 'evidence', remove_evidence: 'evidence', relocate_evidence: 'evidence' };
/** These commands open as wizard pop-ups; the rest open in place above the record. */
const WIZARD_COMMANDS: PpeCommand[] = ['edit', 'allocate', 'inspect', 'upload_evidence'];
const COMMAND_ICONS: Partial<Record<PpeCommand, LucideIcon>> = { inspect: ClipboardCheck, allocate: UserRound, hold: PauseCircle, release: PlayCircle,
    condemn: Ban, dispose: Trash2, edit: Pencil, upload_evidence: Paperclip, acknowledge: CheckCircle2, return: Undo2 };
const FREQUENCY: Record<string, string> = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', quarterly: 'Quarterly', annually: 'Yearly' };
const sentence = humaniseHsValue;
const serviceLabel = (status: string | null | undefined) => statusLabel(status);
const day = (value?: string | null) => formatDateOnly(value, 'Not recorded');
/** "2 Sep 2026" for a timestamp, on the NZ calendar. */
const dayOf = (value?: string | null) => (value ? formatDateOnly(toDateInput(value), 'Not recorded') : 'Not recorded');
const joined = (...parts: (string | null | undefined | false)[]) => parts.filter(Boolean).join(' · ');

/** Equipment: bought (or added), issued, acknowledged, inspected, returned — or condemned and disposed of. */
function equipmentStages(d: PpeItem): { steps: PageHeaderStage[]; next: PageHeaderNextStep | null } {
    const bought: PageHeaderStage = d.purchase_date ? { label: 'Bought', meta: day(d.purchase_date), state: 'done' } : { label: 'Added', meta: dayOf(d.created_at), state: 'done' };
    const name = (d.ppe_type?.name ?? 'equipment').toLowerCase();
    if (d.status === 'condemned' || d.status === 'disposed') {
        return {
            steps: [bought, { label: 'Taken out of use', meta: joined(d.condemned_by?.name, d.condemned_at ? dayOf(d.condemned_at) : 'Not recorded'), state: 'done' },
                d.disposed_at ? { label: 'Disposed of', meta: joined(d.disposed_by?.name, dayOf(d.disposed_at)), state: 'done' } : { label: 'Disposed of', meta: 'Not yet', state: 'now' }],
            next: d.disposed_at ? null : { label: 'Next step · taken out of use', text: 'Record how it was disposed of', meta: d.condemned_reason ?? undefined },
        };
    }
    const a = d.active_allocation_state === 'recorded' ? d.active_allocation : null;
    const overdue = d.currency?.inspection_state === 'overdue';
    const frequency = d.ppe_type?.inspection_frequency ? FREQUENCY[d.ppe_type.inspection_frequency] ?? sentence(d.ppe_type.inspection_frequency) : null;
    const inspected: PageHeaderStage = overdue
        ? { label: 'Inspected', meta: joined(frequency, 'overdue'), state: 'now', tone: 'critical' }
        : d.status === 'maintenance'
            ? { label: 'Inspected', meta: 'On hold', state: 'now', tone: 'warning' }
            : d.last_inspected_at
                ? { label: 'Inspected', meta: joined(day(d.last_inspected_at), d.next_inspection_due ? `next ${day(d.next_inspection_due)}` : null), state: 'done' }
                : { label: 'Inspected', meta: d.next_inspection_due ? `Due ${day(d.next_inspection_due)}` : 'Not scheduled', state: 'todo' };
    const steps: PageHeaderStage[] = [bought,
        a ? { label: 'Issued', meta: joined(a.user?.name ?? 'Worker not available', dayOf(a.allocated_at)), state: 'done' } : { label: 'Issued', meta: serviceLabel(d.status), state: 'todo' },
        a ? (a.acknowledged ? { label: 'Received', meta: joined(a.user?.name, dayOf(a.acknowledged_at)), state: 'done' } : { label: 'Received', meta: 'Not yet', state: 'now', tone: 'warning' }) : { label: 'Received', state: 'todo' },
        inspected, { label: 'Returned', state: 'todo' }];
    const holder = a ? <>issued to <b>{a.user?.name ?? 'a worker'}</b> since {dayOf(a.allocated_at)}</> : null;
    const next: PageHeaderNextStep | null = overdue
        ? { label: 'Next step · inspection overdue', text: `Inspect the ${name}`, tone: 'critical', meta: <>{frequency ? `${frequency} inspection` : 'Inspection'}{holder ? <> · {holder}</> : null}</> }
        : d.status === 'maintenance'
            ? { label: 'Next step · on hold', text: 'Needs to pass an inspection, then be returned to use', tone: 'warning', meta: d.next_inspection_due ? `Next inspection ${day(d.next_inspection_due)}` : undefined }
            : a && !a.acknowledged
                ? { label: 'Next step · waiting for the worker', text: `Waiting for ${a.user?.name ?? 'the worker'} to confirm they’ve received it`, tone: 'warning', meta: `Issued ${dayOf(a.allocated_at)}` }
                : a
                    ? { label: 'Where it’s up to · issued', text: `${a.user?.name ?? 'A worker'} has the ${name}`, meta: d.next_inspection_due ? `Next inspection ${day(d.next_inspection_due)}` : undefined }
                    : null;
    return { steps, next };
}

/** An issue: issued, received, in use, returned. */
function issueStages(d: PpeAllocation): { steps: PageHeaderStage[]; next: PageHeaderNextStep | null } {
    const own = d.read_scope === 'own_allocation';
    const issuer = !own && 'issued_by' in d ? d.issued_by?.name : null;
    const acknowledger = !own && 'acknowledged_by' in d ? d.acknowledged_by?.name : null;
    const returned = Boolean(d.returned_at);
    const steps: PageHeaderStage[] = [
        { label: 'Issued', meta: joined(issuer, dayOf(d.allocated_at)), state: 'done' },
        d.acknowledged ? { label: 'Received', meta: joined(acknowledger ?? d.user?.name, dayOf(d.acknowledged_at)), state: 'done' } : { label: 'Received', meta: 'Not yet', state: returned ? 'todo' : 'now', tone: 'warning' },
        returned ? { label: 'In use', meta: `Until ${dayOf(d.returned_at)}`, state: 'done' } : d.acknowledged ? { label: 'In use', meta: `Since ${dayOf(d.allocated_at)}`, state: 'now' } : { label: 'In use', state: 'todo' },
        returned ? { label: 'Returned', meta: dayOf(d.returned_at), state: 'done' } : { label: 'Returned', state: 'todo' },
    ];
    const item = (d.ppe_type?.name ?? 'equipment').toLowerCase();
    const next: PageHeaderNextStep | null = returned ? null
        : !d.acknowledged ? (own ? { label: 'Your step', text: 'Confirm you’ve received it', tone: 'warning' }
            : { label: 'Next step · waiting for the worker', text: `Waiting for ${d.user?.name ?? 'the worker'} to confirm they’ve received it`, tone: 'warning', meta: `Issued ${dayOf(d.allocated_at)}` })
            : { label: 'Where it’s up to · still issued', text: own ? `You have the ${item}` : `${d.user?.name ?? 'The worker'} has the ${item}`,
                meta: joined([d.inventory_item?.brand, d.inventory_item?.model].filter(Boolean).join(' '), d.inventory_item?.serial_number, own ? null : 'Record return when it comes back') || undefined };
    return { steps, next };
}

export default function PpeShow(props: (PpeRecord | PpeRepairRecord) & { return_to?: string | null }) { return <PpeWorkspace key={`${props.kind}:${props.detail.id}`} {...props} />; }
function PpeWorkspace(props: (PpeRecord | PpeRepairRecord) & { return_to?: string | null }) {
    const { url, props: shared } = usePage<SharedData>();
    const damaged = props.detail.source_integrity === 'repair_required';
    // The last readable record keeps retained drafts mounted while a repair withholds the source; its commands are all off.
    const [lastReadable, setLastReadable] = useState<PpeRecord | null>(() => (damaged ? null : props as PpeRecord));
    useEffect(() => { if (!damaged) setLastReadable(props as PpeRecord); }, [damaged, props]);
    const record: PpeRecord = damaged && lastReadable
        ? { ...lastReadable, detail: { ...lastReadable.detail, can: Object.fromEntries(Object.keys(lastReadable.detail.can).map((key) => [key, false])) } } as PpeRecord
        : props as PpeRecord;
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
    useEffect(() => { if (interacted.current && !command) panel.current?.focus(); }, [section, command]);

    const typeName = record.kind === 'inventory' ? record.detail.ppe_type?.name : record.kind === 'allocations' ? record.detail.ppe_type?.name : null;
    const title = damaged ? 'This equipment record needs fixing' : record.kind === 'inventory' ? typeName ?? 'Equipment'
        : record.kind === 'allocations' ? `Equipment issued — ${record.detail.user?.name ?? 'Worker not available'}`
            : record.detail.inspected_at ? `Inspection on ${dayOf(record.detail.inspected_at)}` : 'Inspection';
    const state = damaged ? 'Needs fixing' : record.kind === 'inventory' ? serviceLabel(record.detail.status)
        : record.kind === 'allocations' ? record.detail.returned_at ? 'Returned' : record.detail.acknowledged ? 'Received' : 'Waiting for worker to confirm'
            : ppeResultLabel(record.detail.result);
    const chipTone: StatusVariant = damaged ? 'warning' : record.kind === 'inventory' ? statusTone(record.detail.status)
        : record.kind === 'allocations' ? record.detail.returned_at ? 'neutral' : record.detail.acknowledged ? 'success' : 'warning'
            : record.detail.result === 'pass' ? 'success' : record.detail.result === 'condemned' ? 'critical' : 'warning';
    const subline = damaged ? 'Details are hidden until an administrator fixes this record'
        : record.kind === 'inventory' ? joined(record.detail.serial_number ?? 'Serial number not recorded', record.detail.site?.name ?? 'Site not available', `Ref ${record.detail.id}`)
            : record.kind === 'allocations' ? joined(record.detail.inventory_item?.site?.name ?? 'Site not available', `Ref ${record.detail.id}`)
                : joined(record.detail.inspected_at ? `Inspected ${dayOf(record.detail.inspected_at)}` : null, `Equipment ref ${record.detail.ppe_inventory_id}`, `Ref ${record.detail.id}`);

    // Header actions: the same commands and gates as before. The primary is the record's next step.
    const allowed = (action: PpeCommand) => !damaged && ppeCommandAllowed(record, action);
    const primaryKey = (damaged ? [] : record.kind === 'inventory' ? ['inspect', 'release', 'allocate'] as PpeCommand[]
        : record.kind === 'allocations' ? ['acknowledge', 'return'] as PpeCommand[] : ['upload_evidence'] as PpeCommand[]).find(allowed);
    const order: PpeCommand[] = record.kind === 'inventory' ? ['upload_evidence', 'hold', 'release', 'allocate', 'inspect', 'condemn', 'dispose', 'edit']
        : record.kind === 'allocations' ? ['upload_evidence', 'acknowledge', 'return'] : ['upload_evidence'];
    const rest = order.filter((action) => action !== primaryKey && allowed(action) && !(own && action === 'upload_evidence'));
    const glass = rest.slice(0, 2); const overflow = rest.slice(2);
    const actions = damaged ? undefined : <>
        {glass.map((action) => <PageHeaderGlassButton key={action} icon={COMMAND_ICONS[action]} onClick={() => open(action)}>{PPE_COMMAND_LABELS[action]}</PageHeaderGlassButton>)}
        {overflow.length ? <DropdownMenu><DropdownMenuTrigger asChild><PageHeaderGlassButton icon={MoreHorizontal} aria-label="More equipment actions" /></DropdownMenuTrigger>
            <DropdownMenuContent align="end">{overflow.map((action) => { const Icon = COMMAND_ICONS[action] ?? ClipboardCheck; return <DropdownMenuItem key={action} onSelect={() => open(action)}><Icon className="size-4" />{PPE_COMMAND_LABELS[action]}</DropdownMenuItem>; })}</DropdownMenuContent></DropdownMenu> : null}
        {primaryKey ? <PageHeaderPrimaryButton icon={COMMAND_ICONS[primaryKey]} onClick={() => open(primaryKey)}>{PPE_COMMAND_LABELS[primaryKey]}</PageHeaderPrimaryButton> : null}
    </>;

    const facts: PageHeaderFact[] = !damaged && record.kind === 'inventory' ? [
        { value: day(record.detail.next_inspection_due), label: record.detail.currency?.inspection_state === 'overdue' ? 'Next inspection · overdue' : 'Next inspection', tone: record.detail.currency?.inspection_state === 'overdue' ? 'critical' : undefined },
        { value: day(record.detail.expiry_date), label: record.detail.currency?.expiry_state === 'expired' ? 'Expiry · expired' : 'Expiry', tone: record.detail.currency?.expiry_state === 'expired' ? 'critical' : undefined },
        { value: record.detail.quantity, label: 'Quantity' },
    ] : [];
    const stages = damaged || record.kind === 'inspections' ? null : record.kind === 'inventory' ? equipmentStages(record.detail) : issueStages(record.detail);

    const inlineActive = !!command && !WIZARD_COMMANDS.includes(command) && (permitted || !!retained[commandKey]);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' },
        ...(own ? [] : [{ title: 'Risks and prevention', href: '/health-safety/registers?view=prevention' }]),
        { title: 'PPE & equipment', href: own ? returnTo : PPE_REGISTER }, { title, href: ppeHref(record.kind, record.detail.id) }]}>
        <Head title={`${title} · PPE & equipment`} />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" wrapTitle title={title} icon={HardHat} backHref={returnTo}
                titleChip={<PageHeaderStatusChip variant={chipTone}>{state}</PageHeaderStatusChip>} subline={subline} actions={actions}
                facts={facts.length ? <PageHeaderFacts items={facts} /> : undefined}
                strip={stages ? <PageHeaderStages steps={stages.steps} next={stages.next} ariaLabel={record.kind === 'inventory' ? 'Equipment stage' : 'Issue stage'} /> : undefined}
                rail={<PageHeaderRail items={sections} value={section} onSelect={(next) => move(next)} ariaLabel="Equipment record sections" />} />}>
                <div className="grid min-w-0 gap-5">
                    {command && !permitted && !retained[commandKey] && <Surface aria-label="Change not available"><div className="space-y-3 p-5">
                        <p role="alert" className="text-subtle text-status-warning">You can’t do this to this item right now. It may have been issued, put on hold or taken out of use since, or you may not have permission. Ask your health and safety manager.</p>
                        <Button type="button" variant="outline" onClick={() => move(section)}>Back to the item</Button></div></Surface>}
                    <div className={inlineActive ? 'sg-surface p-5' : 'contents'}>
                        {Object.entries(retained).map(([key, pending]) => <PpeCommandForm key={`${shared.auth.user.id}:${key}`} record={record} command={pending.command} actorId={shared.auth.user.id} attachment={pending.attachment} active={!!command && key === commandKey}
                            onCancel={() => drafts.request(() => { setRetained((current) => Object.fromEntries(Object.entries(current).filter(([entry]) => entry !== key))); move(section); })}
                            onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />)}
                    </div>
                    <div ref={panel} tabIndex={-1} role="tabpanel" aria-label={sections.find((item) => item.key === section)?.label} className="min-w-0 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                        {damaged ? <div role="status"><Note tone="warning">This record is damaged, so it can’t be changed. An administrator needs to fix it.</Note></div>
                            : <PpeRecordPanel record={record} section={section} sourceHref={href(section)} open={open} page={page} />}
                    </div>
                </div>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
