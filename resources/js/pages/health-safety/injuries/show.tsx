/**
 * The workplace injury and return-to-work record (boards G4-8 and G4-10, approved by Stephan 1 Nov 2026; DESIGN.md
 * "Records: status steps in the header, the Progress card under it").
 *
 * The header carries the injury's own five steps (Reported › Under treatment › Return to work › Recovered › Closed),
 * which are the step picker; the Progress card under it holds the record's only filled button and the picked step's
 * pane, with the next review date said once. The safety review is a card in the side column, shown only to someone who
 * can open it (decision 6: otherwise the page says nothing about it). The title is the same plain title as on the
 * safety review and in every list (decision 15).
 *
 * Without wellbeing access the medical tabs are hidden (Recovery plan, Fitness-for-work checks, Treatment and ACC claim,
 * Files) and one quiet line says what's private. Decision 10: the coordinating team lead sees the duty restrictions,
 * never the medical reason. The command forms and their guards are unchanged; they open in the main column.
 */
import { InjuryCommandForm } from '@/components/health-safety/injury-command-form';
import {
    ASSESSOR_TYPE_LABELS,
    CAPACITY_STATUS_LABELS,
    injuryCommandAllowed,
    INJURY_COMMAND_LABELS,
    RTW_PLAN_STATUS_LABELS,
} from '@/components/health-safety/injury-command-state';
import { statusLabel } from '@/components/health-safety/injury-constants';
import {
    currentInjuryStep,
    firstName,
    injuryNextBar,
    injuryRoundSteps,
    injuryStepPane,
    INJURY_TO_REVIEW_SEVERITY,
    type InjuryStepKey,
} from '@/components/health-safety/injury-progress';
import { InjuryRecordPanel, InjuryRecordSide } from '@/components/health-safety/injury-record-panels';
import type { InjuryCommand, InjuryRecord, InjurySection } from '@/components/health-safety/injury-workspace-types';
import { LinkedSafetyReviewCard, reviewCloserSentence, WhoFinishesCard } from '@/components/health-safety/restraints/linked-safety-review-card';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { PageHeader, PageHeaderGlassButton, PageHeaderRail, PageHeaderStatusChip, PageLayout, type PageHeaderRailItem } from '@/components/page';
import { TabSearchPalette, useGroupedProfileSearchShortcut, type GroupedProfileNavGroup } from '@/components/page/grouped-profile-nav';
import { PageHeaderFacts, PageHeaderSteps, type PageHeaderFact } from '@/components/page/page-header-strip';
import { FactRows, Note, PeopleCards, QuietLine, SideColumn, Story, type PersonCardItem } from '@/components/signature/blocks';
import { RecordProgress } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/datetime';
import { hsBadge } from '@/lib/health-safety/hs-labels';
import { INJURY_REGISTER, injuryHref, injuryLocation, injuryReturn } from '@/lib/health-safety/injury-navigation';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    ClipboardList,
    FileText,
    HeartPulse,
    History,
    Link2,
    MoreHorizontal,
    Paperclip,
    Stethoscope,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const SECTIONS: { key: InjurySection; label: string; icon: LucideIcon; medical?: boolean }[] = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'recovery', label: 'Recovery plan', icon: ClipboardList, medical: true },
    { key: 'capacity', label: 'Fitness-for-work checks', icon: Stethoscope, medical: true },
    { key: 'scheduling', label: 'Hours and roster', icon: CalendarClock },
    { key: 'treatment', label: 'Treatment and ACC claim', icon: HeartPulse, medical: true },
    { key: 'connections', label: 'Linked records', icon: Link2 },
    { key: 'evidence', label: 'Files', icon: Paperclip, medical: true },
    { key: 'history', label: 'History', icon: History },
];
const COMMAND_SECTION: Record<InjuryCommand, InjurySection> = {
    start_treatment: 'treatment',
    begin_rtw: 'recovery',
    mark_recovered: 'recovery',
    close: 'overview',
    acc: 'treatment',
    add_rtw: 'recovery',
    add_capacity: 'capacity',
    edit: 'overview',
    assign: 'overview',
    review: 'recovery',
    edit_rtw: 'recovery',
    add_duty: 'recovery',
    upload_evidence: 'evidence',
    remove_evidence: 'evidence',
};
/** Header "More": every other command the server allows now (the bar's own command is never offered twice). */
const MORE: InjuryCommand[] = ['start_treatment', 'begin_rtw', 'mark_recovered', 'close', 'edit', 'acc', 'review', 'add_rtw', 'add_capacity', 'upload_evidence'];
const factTone = (variant: string): PageHeaderFact['tone'] => (variant === 'critical' || variant === 'warning' || variant === 'success' ? variant : 'neutral');

type Props = { detail: InjuryRecord; return_to?: string | null };
export default function InjuryShow(props: Props) {
    return <InjuryWorkspace key={props.detail.id} {...props} />;
}

function InjuryWorkspace({ detail: d, return_to }: Props) {
    const { url, props: shared } = usePage<SharedData>();
    const viewerId = shared.auth?.user?.id ?? null;
    const viewerName = shared.auth?.user?.name ?? null;
    const location = injuryLocation(url);
    const { command } = location;
    const medical = d.medical_access === 'readable';
    const visible = SECTIONS.filter((item) => (!item.medical || medical) && (item.key !== 'history' || d.history != null));
    const section: InjurySection = visible.some((item) => item.key === location.section) ? location.section : 'overview';
    const returnTo = injuryReturn(return_to ?? location.returnTo);
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const [interacted, setInteracted] = useState(false);
    const [picked, setPicked] = useState<InjuryStepKey | null>(null);
    const [findOpen, setFindOpen] = useState(false);
    const selected = { plan: location.plan, attachment: location.attachment };
    const commandKey = `${d.id}-${command}-${selected.plan ?? ''}-${selected.attachment ?? ''}`;
    const permittedCommand = !!command && injuryCommandAllowed(d, command, selected);
    const [retainedCommands, setRetainedCommands] = useState(() => new Set(permittedCommand ? [commandKey] : []));
    // A later read can withdraw a capability; retain the mounted draft while its command hook rechecks saving against
    // the current source and permissions.
    useEffect(() => {
        if (permittedCommand) setRetainedCommands((current) => (current.has(commandKey) ? current : new Set([...current, commandKey])));
    }, [permittedCommand, commandKey]);
    const retainCommand = !!command && (permittedCommand || retainedCommands.has(commandKey));
    const href = (next: InjurySection, action?: InjuryCommand | null, child?: { plan?: number; attachment?: number }) =>
        injuryHref(d.id, { section: next, command: action, returnTo, ...child });
    const move = (next: InjurySection, action?: InjuryCommand | null, child?: { plan?: number; attachment?: number }) =>
        drafts.request(() => {
            setInteracted(true);
            router.replace({ url: href(next, action, child), preserveState: true, preserveScroll: true });
        });
    const open = (action: InjuryCommand, child?: { plan?: number; attachment?: number }) => move(COMMAND_SECTION[action], action, child);
    const openFind = useCallback(() => setFindOpen(true), []);
    useGroupedProfileSearchShortcut(openFind);
    useEffect(() => {
        if (interacted && !command) panel.current?.focus();
        if (!command) {
            const child = section === 'recovery' && location.plan ? `plan-${location.plan}` : section === 'capacity' && location.assessment ? `assessment-${location.assessment}` : null;
            if (child) {
                const element = document.getElementById(child);
                element?.scrollIntoView?.({ block: 'nearest' });
                element?.focus();
            }
        }
    }, [section, command, location.plan, location.assessment, location.attachment, interacted]);

    const coordinator = d.coordination?.coordinator ?? null;
    const youCoordinate = viewerId != null && coordinator?.id === viewerId;
    const worker = d.worker?.name ?? null;
    const site = d.site?.name ?? null;
    const title = d.title ?? `${d.injury_type_label} — ${worker ?? 'Name hidden'}`;
    const closed = d.status === 'closed';

    // The Progress card's next-step bar holds the only filled button; the rest sit under "More".
    const nextBar = injuryNextBar(d, viewerId, (action) => open(action));
    const currentStep = currentInjuryStep(d);
    const selectedStep = picked ?? currentStep;
    const pickStep = (key: string) => {
        const step = injuryRoundSteps(d).find((item) => item.key === key)?.key as InjuryStepKey | undefined;
        if (step) setPicked(step === currentStep ? null : step);
    };
    const stepPane = injuryStepPane(d, selectedStep, viewerId, {
        onBack: selectedStep !== currentStep ? () => setPicked(null) : undefined,
        onHistory: d.history != null ? () => move('history') : undefined,
    });
    // "Change coordinator" lives once, in People (G4-8); the rest of the allowed commands sit under "More".
    const more = MORE.filter((action) => action !== nextBar.owns && injuryCommandAllowed(d, action)).map((action) => ({
        action,
        label: INJURY_COMMAND_LABELS[action],
    }));
    const actsOnRecord = Boolean(nextBar.action) || more.length > 0 || injuryCommandAllowed(d, 'assign');

    const severity = hsBadge('severity', INJURY_TO_REVIEW_SEVERITY[d.severity] ?? d.severity);
    const worksafe = d.safety_review?.worksafe;
    const facts: PageHeaderFact[] = [
        { value: `${severity.label} severity`, tone: factTone(severity.variant) },
        { value: `${d.injury_words ?? d.injury_type_label} · injury` },
        { value: d.lost_time_days ? `${d.lost_time_days} ${d.lost_time_days === 1 ? 'day' : 'days'} off work` : 'No time off work' },
        ...(worksafe?.decided
            ? [
                  {
                      value: `${worksafe.notifiable ? 'Notifiable event' : 'Not a notifiable event'}${worksafe.decided_at ? ` · decided ${formatDate(worksafe.decided_at)}` : ''}`,
                      tone: worksafe.notifiable ? ('warning' as const) : ('neutral' as const),
                  },
              ]
            : []),
    ];
    const railItems: PageHeaderRailItem<InjurySection>[] = visible.map((item) => ({
        key: item.key,
        label: item.label,
        ...(item.key === 'evidence' && d.attachments?.length ? { count: d.attachments.length } : {}),
    }));
    const findGroups: GroupedProfileNavGroup[] = [{ key: 'injury', label: 'Workplace injury', icon: HeartPulse, tabs: visible }];

    const people: PersonCardItem[] = [
        ...(worker
            ? [
                  {
                      name: worker,
                      sub: [
                          'Injured worker',
                          d.reported_by && d.reported_by === worker ? `reported it${d.created_at ? ` ${formatDate(d.created_at)}` : ''}` : null,
                      ]
                          .filter(Boolean)
                          .join(' · '),
                  },
              ]
            : []),
        ...(d.reported_by && d.reported_by !== worker ? [{ name: d.reported_by, sub: 'Reported it' }] : []),
        ...(coordinator ? [{ name: youCoordinate ? `${coordinator.name} (you)` : coordinator.name, sub: 'Coordinator' }] : []),
    ];
    const recordCloser = `This injury record closes after ${firstName(worker)} has recovered: by ${
        coordinator ? (youCoordinate ? 'you' : coordinator.name) : 'its coordinator'
    }, or anyone else who manages health and safety${site ? ` at ${site}` : ''}.`;

    const latestPlan = d.rtw_plans?.[0];
    const latestCheck = d.capacity_assessments?.[0];
    const overview = (
        <>
            {coordinator && !actsOnRecord ? (
                <Note icon={ClipboardList}>
                    {youCoordinate ? 'You coordinate this injury.' : `${coordinator.name} coordinates this injury and records changes.`}
                </Note>
            ) : null}
            <Story
                title="What happened"
                sub={worker ? `As ${firstName(worker)} reported it` : undefined}
                action={injuryCommandAllowed(d, 'edit') ? { label: INJURY_COMMAND_LABELS.edit, onClick: () => open('edit') } : null}
                paras={[
                    { h: 'Injury', t: d.injury_words ?? d.injury_type_label },
                    { h: 'What happened', t: <span className="whitespace-pre-wrap">{d.description || 'Not recorded'}</span> },
                    { h: 'Where', t: site ?? 'Site not recorded' },
                    { h: 'Time off work', t: d.lost_time_days ? `${d.lost_time_days} ${d.lost_time_days === 1 ? 'day' : 'days'}` : 'None' },
                ]}
            />
            {medical && latestPlan ? (
                <FactRows
                    title={`Recovery plan from ${latestPlan.plan_start_date ? formatDate(latestPlan.plan_start_date) : 'an unknown date'}`}
                    sub={latestPlan.status ? (RTW_PLAN_STATUS_LABELS[latestPlan.status] ?? latestPlan.status) : undefined}
                    rows={[
                        [
                            'Modified duties',
                            latestPlan.modified_duties[0]?.modified_duties_description ||
                                latestPlan.stages[0]?.duties_description ||
                                'Not recorded',
                        ],
                        ['Restrictions', latestPlan.modified_duties[0]?.restrictions || 'Not recorded'],
                        [
                            'Fitness for work',
                            latestCheck
                                ? [
                                      CAPACITY_STATUS_LABELS[latestCheck.capacity_status] ?? latestCheck.capacity_status,
                                      latestCheck.assessor_type ? (ASSESSOR_TYPE_LABELS[latestCheck.assessor_type] ?? latestCheck.assessor_type) : null,
                                      latestCheck.assessment_date ? formatDate(latestCheck.assessment_date) : null,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ')
                                : 'No checks yet',
                        ],
                        ['ACC claim', d.acc_claim_number ? `Lodged · ${d.acc_claim_number}` : d.claim_delivery?.internally_recorded ? 'Lodged' : 'Not lodged'],
                    ]}
                />
            ) : null}
            {!medical ? (
                <QuietLine
                    title="Medical details are private"
                    text="People with wellbeing access (HR) can see treatment, fitness-for-work checks and the ACC claim. To change the recovery plan, ask a provider manager or an administrator."
                />
            ) : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('care', shared.auth?.can),
                { title: 'Workplace injuries', href: INJURY_REGISTER },
                { title, href: injuryHref(d.id) },
            ]}
        >
            <Head title={`${title} · ${d.reference}`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            title={title}
                            icon={HeartPulse}
                            titleChip={<PageHeaderStatusChip variant={closed ? 'neutral' : !coordinator ? 'warning' : 'info'}>{statusLabel(d.status)}</PageHeaderStatusChip>}
                            subline={['Workplace injury', site ?? 'Site not recorded', d.injury_date ? dateTimeWithYear(d.injury_date) : null, `Workplace injury ${d.reference}`]
                                .filter(Boolean)
                                .join(' · ')}
                            actions={
                                more.length ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More injury actions">
                                                More
                                            </PageHeaderGlassButton>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {more.map((item) => (
                                                <DropdownMenuItem key={item.action} onSelect={() => open(item.action)}>
                                                    {item.label}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : undefined
                            }
                            facts={<PageHeaderFacts items={facts} />}
                            strip={<PageHeaderSteps steps={injuryRoundSteps(d)} selected={selectedStep} onSelect={pickStep} ariaLabel="Where the injury is up to" />}
                            rail={<PageHeaderRail items={railItems} value={section} onSelect={(next) => move(next)} onFind={openFind} maxVisible={6} ariaLabel="Injury sections" />}
                        />
                    }
                >
                    <div className="grid min-w-0 gap-5">
                        <RecordProgress next={nextBar} pane={stepPane} />
                        <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                            <div ref={panel} tabIndex={-1} className="grid min-w-0 gap-5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                {d.source_integrity === 'repair_required' && (
                                    <div role="status">
                                        <Note tone="warning" icon={AlertTriangle}>
                                            <p className="font-semibold">This record needs fixing</p>
                                            <p>
                                                The injury is still on record, but nobody can change it or see its private details until an administrator fixes its history. Ask an
                                                administrator.
                                            </p>
                                        </Note>
                                    </div>
                                )}
                                {command && !retainCommand && (
                                    <div className="grid justify-items-start gap-3 rounded-[14px] border border-border bg-card px-[18px] py-4">
                                        <p role="alert" className="text-sm font-medium">
                                            This change is no longer available for the current record — it may have moved on or your access changed. Ask the injury’s
                                            coordinator or a health and safety manager.
                                        </p>
                                        <Button type="button" variant="outline" onClick={() => move(section)}>
                                            Return to the record
                                        </Button>
                                    </div>
                                )}
                                {command && retainCommand && (
                                    <div className="rounded-[14px] border border-border bg-card p-5 empty:hidden">
                                        <InjuryCommandForm
                                            key={`${shared.auth?.user?.id}:${d.id}:${commandKey}`}
                                            detail={d}
                                            command={command}
                                            actorId={shared.auth?.user?.id}
                                            selected={selected}
                                            returnTo={returnTo}
                                            onCancel={() => move(section)}
                                            onDone={() => {
                                                setInteracted(true);
                                                router.get(href(section), {}, { preserveState: false, preserveScroll: true });
                                            }}
                                        />
                                    </div>
                                )}
                                {section === 'overview' ? overview : <InjuryRecordPanel detail={d} section={section} open={open} sourceHref={href(section)} />}
                            </div>
                            <aside className="min-w-0" aria-label="Who finishes what, the safety review and people">
                                <SideColumn>
                                    <WhoFinishesCard record={recordCloser} review={d.safety_review ? reviewCloserSentence(d.safety_review, viewerName) : null} />
                                    <LinkedSafetyReviewCard review={d.safety_review} viewerName={viewerName} />
                                    <InjuryRecordSide detail={d} section={section} open={open} />
                                    {people.length ? (
                                        <PeopleCards
                                            title="People"
                                            cols={1}
                                            action={coordinator && nextBar.owns !== 'assign' && injuryCommandAllowed(d, 'assign') ? { label: 'Change coordinator', onClick: () => open('assign') } : null}
                                            items={people}
                                        />
                                    ) : null}
                                </SideColumn>
                            </aside>
                        </div>
                    </div>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
            <TabSearchPalette
                open={findOpen}
                onClose={() => setFindOpen(false)}
                groups={findGroups}
                onTab={(key) => {
                    const target = visible.find((item) => item.key === key);
                    if (target) move(target.key);
                }}
                testIdPrefix="injury-workspace"
                searchLabel="Find an injury section"
            />
        </AppLayout>
    );
}
