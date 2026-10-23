import { InjuryCommandForm } from '@/components/health-safety/injury-command-form';
import { injuryCommandAllowed, INJURY_COMMAND_LABELS } from '@/components/health-safety/injury-command-state';
import { severityLabel, statusLabel } from '@/components/health-safety/injury-constants';
import { injuryDay, injuryTime, InjuryRecordPanel } from '@/components/health-safety/injury-record-panels';
import type { InjuryCommand, InjuryRecord, InjurySection } from '@/components/health-safety/injury-workspace-types';
import { PageHeader, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryHref, injuryLocation, injuryReturn } from '@/lib/health-safety/injury-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarClock, ClipboardList, FileText, HeartPulse, History, Link2, Paperclip, Stethoscope, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText }, { key: 'recovery', label: 'Recovery plan', icon: HeartPulse },
    { key: 'capacity', label: 'Assessments', icon: Stethoscope }, { key: 'scheduling', label: 'Scheduling', icon: CalendarClock },
    { key: 'treatment', label: 'Treatment and claim', icon: ClipboardList }, { key: 'connections', label: 'Connected work', icon: Link2 },
    { key: 'evidence', label: 'Evidence', icon: Paperclip }, { key: 'history', label: 'History', icon: History },
] as const;
const COMMAND_SECTION: Record<InjuryCommand, InjurySection> = { start_treatment: 'treatment', begin_rtw: 'recovery', mark_recovered: 'recovery', close: 'overview', acc: 'treatment', add_rtw: 'recovery', add_capacity: 'capacity', edit: 'overview', assign: 'overview', review: 'recovery', edit_rtw: 'recovery', add_duty: 'recovery', upload_evidence: 'evidence', remove_evidence: 'evidence' };
type Props = { detail: InjuryRecord; return_to?: string | null };
export default function InjuryShow(props: Props) { return <InjuryWorkspace key={props.detail.id} {...props} />; }
function InjuryWorkspace({ detail: d, return_to }: Props) {
    const { url, props: shared } = usePage<SharedData>();
    const location = injuryLocation(url); const { section, command } = location;
    const returnTo = injuryReturn(return_to ?? location.returnTo);
    const drafts = useSafetyWorkspaceDrafts(); const panel = useRef<HTMLDivElement>(null); const interacted = useRef(false);
    const selected = { plan: location.plan, attachment: location.attachment };
    const commandKey = `${d.id}-${command}-${selected.plan ?? ''}-${selected.attachment ?? ''}`;
    const permittedCommand = !!command && injuryCommandAllowed(d, command, selected);
    const [retainedCommands, setRetainedCommands] = useState(() => new Set(permittedCommand ? [commandKey] : []));
    // A later read can withdraw a capability; retain the mounted draft while its
    // command hook rechecks saving against the current source and permissions.
    useEffect(() => {
        if (permittedCommand) setRetainedCommands((current) => current.has(commandKey) ? current : new Set([...current, commandKey]));
    }, [permittedCommand, commandKey]);
    const retainCommand = !!command && (permittedCommand || retainedCommands.has(commandKey));
    const href = (next: InjurySection, action?: InjuryCommand | null, child?: { plan?: number; attachment?: number }) => injuryHref(d.id, { section: next, command: action, returnTo, ...child });
    const move = (next: InjurySection, action?: InjuryCommand | null, child?: { plan?: number; attachment?: number }) => drafts.request(() => {
        interacted.current = true; router.replace({ url: href(next, action, child), preserveState: true, preserveScroll: true });
    });
    const open = (action: InjuryCommand, child?: { plan?: number; attachment?: number }) => move(COMMAND_SECTION[action], action, child);
    const nextAction = (['assign', 'start_treatment', 'begin_rtw', 'mark_recovered'] as const).find((action) => injuryCommandAllowed(d, action) && (action !== 'assign' || !d.coordination.coordinator));
    useEffect(() => {
        if (interacted.current && !command) panel.current?.focus();
        if (!command) {
            const child = section === 'recovery' && location.plan ? `plan-${location.plan}` : section === 'capacity' && location.assessment ? `assessment-${location.assessment}` : null;
            if (child) { const element = document.getElementById(child); element?.scrollIntoView?.({ block: 'nearest' }); element?.focus(); }
        }
    }, [section, command, location.plan, location.assessment, location.attachment]);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Workplace injuries', href: INJURY_REGISTER }, { title: d.reference, href: injuryHref(d.id) }]}>
        <Head title={`${d.reference} · Workplace injury`} />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" wrapTitle backHref={returnTo} title={d.reference} icon={HeartPulse} titleChip={<PageHeaderStatusChip variant={d.status === 'closed' ? 'neutral' : 'info'}>{statusLabel(d.status)}</PageHeaderStatusChip>} subline={`${d.worker?.name ?? 'Worker unavailable'} · ${d.site?.name ?? 'Site unavailable'}`} actions={<>
                {d.can.assign && nextAction !== 'assign' && <PageHeaderGlassButton icon={Users} onClick={() => open('assign')}>{d.coordination.coordinator ? 'Change owner' : 'Assign owner'}</PageHeaderGlassButton>}
                {nextAction && <PageHeaderPrimaryButton onClick={() => open(nextAction)}>{nextAction === 'assign' ? 'Assign owner' : INJURY_COMMAND_LABELS[nextAction]}</PageHeaderPrimaryButton>}
            </>} meters={<>
                <PageHeaderMeterBlock label="Occurred" href={href('overview')}><PageHeaderMeterBig>{injuryDay(d.injury_date)}</PageHeaderMeterBig><PageHeaderMeterCaption>{injuryTime(d.injury_date)}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Severity" href={href('overview')}><PageHeaderMeterBig>{severityLabel(d.severity)}</PageHeaderMeterBig><PageHeaderMeterCaption>{d.injury_type_label}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Incident owner" href={href('overview')}><PageHeaderMeterBig>{d.coordination.coordinator?.name ?? (d.coordination.attribution_state === 'unassigned' ? 'Unassigned' : 'Unconfirmed')}</PageHeaderMeterBig><PageHeaderMeterCaption>Accountable for the next step</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Next review" href={href('recovery')}><PageHeaderMeterBig>{injuryDay(d.coordination.next_review_date)}</PageHeaderMeterBig><PageHeaderMeterCaption>Recorded coordination date</PageHeaderMeterCaption></PageHeaderMeterBlock>
            </>} rail={<PageHeaderRail items={[...SECTIONS]} value={section} onSelect={(next) => move(next)} ariaLabel="Injury sections" />} />}>
                <Card><CardContent className="space-y-5 p-5"><div ref={panel} tabIndex={-1} className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {d.source_integrity === 'repair_required' && <div role="status" className="mb-5 space-y-2"><p className="text-subtle font-medium text-status-warning">Source record needs review</p><p className="text-subtle">The factual occurrence is retained. Changes and private source details are withheld until its recorded history can be confirmed.</p></div>}
                    <InjuryRecordPanel detail={d} section={section} open={open} sourceHref={href(section)} />
                </div>
                {command && !retainCommand && <div className="space-y-3"><p role="alert" className="text-subtle text-status-warning">This change is no longer available for the current record.</p><Button type="button" className="frontline-tap" variant="outline" onClick={() => move(section)}>Return to the record</Button></div>}
                {command && retainCommand && <InjuryCommandForm key={`${shared.auth.user.id}:${d.id}:${commandKey}`} detail={d} command={command} actorId={shared.auth.user.id} selected={selected} returnTo={returnTo} onCancel={() => move(section)} onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} />}
                </CardContent></Card>
            </PageLayout>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
