/**
 * One workplace injury (approved H&S drawings pg-hsn-88, 88b–88j — Stephan 23
 * Oct 2026): one band on every section shows the injury's five stages
 * (Reported › Under treatment › Return to work › Recovered › Closed), who
 * coordinates the next step and the button that does it. Sections read as
 * stories, lines and time lines with the coordinator in the side column. The
 * command forms and their guards are unchanged; they open in the main column.
 */
import { InjuryCommandForm } from '@/components/health-safety/injury-command-form';
import { injuryCommandAllowed, INJURY_COMMAND_LABELS } from '@/components/health-safety/injury-command-state';
import { SEVERITY_TONE, severityLabel, STATUS_ORDER, statusLabel } from '@/components/health-safety/injury-constants';
import { InjuryRecordPanel, InjuryRecordSide } from '@/components/health-safety/injury-record-panels';
import type { InjuryCommand, InjuryRecord, InjurySection } from '@/components/health-safety/injury-workspace-types';
import { dateTimeWithYear, dateWithYear } from '@/components/incidents/incident-stages';
import { PageHeader, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderFacts, PageHeaderStages, type PageHeaderFact, type PageHeaderNextStep, type PageHeaderStage } from '@/components/page/page-header-strip';
import { SideColumn } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryHref, injuryLocation, injuryReturn } from '@/lib/health-safety/injury-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, HeartPulse, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const SECTIONS = [
    { key: 'overview', label: 'Overview' }, { key: 'recovery', label: 'Recovery plan' },
    { key: 'capacity', label: 'Assessments' }, { key: 'scheduling', label: 'Scheduling' },
    { key: 'treatment', label: 'Treatment and claim' }, { key: 'connections', label: 'Connected work' },
    { key: 'evidence', label: 'Evidence' }, { key: 'history', label: 'History' },
] as const;
const COMMAND_SECTION: Record<InjuryCommand, InjurySection> = { start_treatment: 'treatment', begin_rtw: 'recovery', mark_recovered: 'recovery', close: 'overview', acc: 'treatment', add_rtw: 'recovery', add_capacity: 'capacity', edit: 'overview', assign: 'overview', review: 'recovery', edit_rtw: 'recovery', add_duty: 'recovery', upload_evidence: 'evidence', remove_evidence: 'evidence' };
const STAGE_LABELS: Record<string, string> = { reported: 'Reported', under_treatment: 'Under treatment', return_to_work: 'Return to work', recovered: 'Recovered', closed: 'Closed' };
/** What the coordinator does next at each stage (the transition the header button records). */
const NEXT_STEP: Record<string, string> = {
    reported: 'records that treatment has started',
    under_treatment: 'starts recovery planning',
    return_to_work: 'records the recovery',
    recovered: 'closes the injury record',
};
type Props = { detail: InjuryRecord; return_to?: string | null };
export default function InjuryShow(props: Props) { return <InjuryWorkspace key={props.detail.id} {...props} />; }

/** When the record first reached each stage, from its readable source history (none when private history is withheld). */
function stageDates(d: InjuryRecord): Record<string, string> {
    const dates: Record<string, string> = {};
    for (const entry of [...(d.history ?? [])].sort((a, b) => a.source_revision - b.source_revision)) {
        if (entry.access !== 'readable' || entry.after_access !== 'readable' || !entry.recorded_at) continue;
        const status = typeof entry.after?.status === 'string' ? entry.after.status : entry.source_revision === 1 ? 'reported' : null;
        if (status && !dates[status]) dates[status] = entry.recorded_at;
    }
    return dates;
}

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

    /* ---- header: facts, the five stages and the next step ---- */
    const owner = d.coordination.coordinator?.name ?? null;
    const unassigned = !d.coordination.coordinator;
    const severityTone = SEVERITY_TONE[d.severity];
    const facts: PageHeaderFact[] = [
        { value: severityLabel(d.severity), label: 'Severity', tone: severityTone && severityTone !== 'neutral' ? severityTone : undefined },
        ...(unassigned ? [{ value: d.coordination.attribution_state === 'unassigned' ? 'Needs an owner' : 'Assignment needs review', label: 'Owner', tone: 'warning' as const }] : []),
        { value: d.coordination.next_review_date ? dateWithYear(d.coordination.next_review_date) : 'Not set', label: 'Next review' },
    ];
    const reachedAt = stageDates(d);
    const current = Math.max(0, STATUS_ORDER.indexOf(d.status as (typeof STATUS_ORDER)[number]));
    const closed = d.status === 'closed';
    const steps: PageHeaderStage[] = STATUS_ORDER.map((status, index) => ({
        label: STAGE_LABELS[status],
        meta: index === 0 && unassigned && !closed ? 'No owner yet'
            : reachedAt[status] && index <= current ? `${index === current && index > 0 && !closed ? 'Since ' : ''}${dateWithYear(reachedAt[status])}` : undefined,
        state: closed || index < current ? 'done' : index === current ? 'now' : 'todo',
        tone: index === current && unassigned && !closed ? 'critical' : undefined,
    }));
    const next: PageHeaderNextStep | null = closed ? null : unassigned
        ? { label: 'Next step', text: 'Choose who coordinates this injury', tone: 'critical', meta: 'Recovery coordination, operational response and safety review each retain their own owner and outcome.' }
        : NEXT_STEP[d.status] ? { label: 'Next step', text: `${owner} ${NEXT_STEP[d.status]}`, meta: `Recovery coordinator${d.coordination.next_review_date ? ` · next review ${dateWithYear(d.coordination.next_review_date)}` : ''}` } : null;

    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' }, { title: 'Workplace injuries', href: INJURY_REGISTER }, { title: d.reference, href: injuryHref(d.id) }]}>
        <Head title={`${d.reference} · Workplace injury`} />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" wrapTitle backHref={returnTo} title={`${d.injury_type_label} — ${d.worker?.name ?? 'Worker unavailable'}`} icon={HeartPulse}
                titleChip={<PageHeaderStatusChip variant={closed ? 'neutral' : unassigned ? 'warning' : 'info'}>{statusLabel(d.status)}</PageHeaderStatusChip>}
                subline={[d.reference, d.site?.name ?? 'Site unavailable', d.injury_date ? dateTimeWithYear(d.injury_date) : null, owner ? `Owner ${owner}` : null].filter(Boolean).join(' · ')}
                actions={<>
                    {d.can.assign && nextAction !== 'assign' && <PageHeaderGlassButton icon={Users} onClick={() => open('assign')}>{d.coordination.coordinator ? 'Change owner' : 'Assign owner'}</PageHeaderGlassButton>}
                    {nextAction && <PageHeaderPrimaryButton onClick={() => open(nextAction)}>{nextAction === 'assign' ? 'Assign owner' : INJURY_COMMAND_LABELS[nextAction]}</PageHeaderPrimaryButton>}
                </>}
                facts={<PageHeaderFacts items={facts} />}
                strip={<PageHeaderStages steps={steps} next={next} ariaLabel="Injury stage" />}
                rail={<PageHeaderRail items={[...SECTIONS]} value={section} onSelect={(next) => move(next)} ariaLabel="Injury sections" />} />}>
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <div ref={panel} tabIndex={-1} className="grid min-w-0 gap-5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                        {d.source_integrity === 'repair_required' && <div role="status" className="flex items-start gap-2.5 rounded-[14px] border border-status-warning/40 bg-status-warning-bg px-[18px] py-3">
                            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-status-warning" />
                            <div className="grid gap-1"><p className="text-sm font-semibold text-status-warning-foreground">Source record needs review</p><p className="text-sm text-status-warning-foreground">The factual occurrence is retained. Changes and private source details are withheld until its recorded history can be confirmed.</p></div>
                        </div>}
                        {command && !retainCommand && <div className="grid justify-items-start gap-3 rounded-[14px] border border-border bg-card px-[18px] py-4"><p role="alert" className="text-sm font-medium">This change is no longer available for the current record.</p><Button type="button" variant="outline" onClick={() => move(section)}>Return to the record</Button></div>}
                        {command && retainCommand && <div className="rounded-[14px] border border-border bg-card p-5 empty:hidden"><InjuryCommandForm key={`${shared.auth.user.id}:${d.id}:${commandKey}`} detail={d} command={command} actorId={shared.auth.user.id} selected={selected} returnTo={returnTo} onCancel={() => move(section)} onDone={() => { interacted.current = true; router.get(href(section), {}, { preserveState: false, preserveScroll: true }); }} /></div>}
                        <InjuryRecordPanel detail={d} section={section} open={open} sourceHref={href(section)} />
                    </div>
                    <aside className="min-w-0" aria-label="Who coordinates this injury"><SideColumn><InjuryRecordSide detail={d} section={section} open={open} /></SideColumn></aside>
                </div>
            </PageLayout>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
