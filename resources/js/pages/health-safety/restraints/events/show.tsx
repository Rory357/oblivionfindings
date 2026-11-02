/**
 * The restraint record (boards G4-5 and G4-7, approved by Stephan 1 Nov 2026; DESIGN.md "Records: status steps in the
 * header, the Progress card under it").
 *
 * The header carries only the restraint's own track — Recorded › Restraint review owner › Monitoring › Debrief ›
 * Restraint review — and those round steps are the step picker. Under it the Progress card holds the record's only
 * filled button (the next step, from the server's own command gates) and the picked step's pane. The safety review is its
 * own record: a card in the side column, shown only to someone who can open it (decision 6), and never offered here —
 * taking it on happens on the safety review itself, where its authoriser isn't offered it (decision 12). The title is the
 * same plain title as on its safety review and in every list (decision 15).
 *
 * Every recorded fact is kept: observations, debriefs and reviews are source records whose fields vary, so their own
 * sections still show all of their facts with who recorded them and the version. Commands open the unchanged
 * RestraintEventCommandForm, gated by eventCommandAllowed; unsaved drafts still ask before leaving.
 */
import {
    EVENT_COMMAND_LABELS,
    eventCommandAllowed,
    RestraintEventCommandForm,
} from '@/components/health-safety/restraints/event-command-form';
import { FOLLOWUP_OUTCOME_LABEL } from '@/components/health-safety/restraints/followup-outcomes';
import {
    LinkedSafetyReviewCard,
    reviewCloserSentence,
    WhoFinishesCard,
} from '@/components/health-safety/restraints/linked-safety-review-card';
import {
    currentRestraintStep,
    restraintNextBar,
    restraintRoundSteps,
    restraintStepPane,
    RESTRAINT_STEP_ORDER,
    type RestraintStepKey,
} from '@/components/health-safety/restraints/restraint-progress';
import type {
    RestraintAttachment,
    RestraintEventDetail,
    RestraintFollowup,
    RestraintPickers,
    SourceFact,
    SourcePerson,
} from '@/components/health-safety/restraints/types';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { RecordIdentity, RecordPerson, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { TabSearchPalette, useGroupedProfileSearchShortcut, type GroupedProfileNavGroup } from '@/components/page/grouped-profile-nav';
import { PageHeaderFacts, PageHeaderSteps, type PageHeaderFact } from '@/components/page/page-header-strip';
import {
    FactRows,
    KnowList,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    RowList,
    SideColumn,
    Story,
    Surface,
    type PersonCardItem,
    type RowItem,
    type SgTone,
} from '@/components/signature/blocks';
import { RecordProgress } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTimeLong, formatDurationMinutes, formatTime } from '@/lib/datetime';
import { hsBadge, hsLabel, humaniseHsValue } from '@/lib/health-safety/hs-labels';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
    type RestraintEventCommand,
    type RestraintEventSection,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    ClipboardList,
    Download,
    FileText,
    HeartPulse,
    History,
    Link2,
    MessageCircle,
    MoreHorizontal,
    Paperclip,
    Pencil,
    ShieldAlert,
    Trash2,
    UserCog,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { ATTACHMENT_CATEGORY_LABEL } from '../shared';

const SECTIONS: { key: RestraintEventSection; label: string; icon: LucideIcon }[] = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'review', label: 'Restraint review', icon: ClipboardCheck },
    { key: 'monitoring', label: 'Monitoring', icon: HeartPulse },
    { key: 'debrief', label: 'Debrief', icon: MessageCircle },
    { key: 'followups', label: 'Follow-ups', icon: ClipboardList },
    { key: 'evidence', label: 'Files', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Linked records', icon: Link2 },
];
const COMMAND_ICONS: Partial<Record<RestraintEventCommand, LucideIcon>> = {
    review: ClipboardCheck,
    'assign-review': UserCog,
    'add-followup': ClipboardList,
    'add-observation': HeartPulse,
    'record-debrief': MessageCircle,
    'add-evidence': Paperclip,
    correct: Pencil,
    'link-incident': Link2,
};
type Props = {
    detail: RestraintEventDetail;
    workspace: 'view' | 'review';
    pickers?: Partial<RestraintPickers>;
    return_to?: string | null;
};
const personName = (person: SourcePerson | null) => person?.name ?? (person ? 'Name hidden — you don’t have access' : 'Not recorded');
const when = (value: string | null | undefined) => (value ? formatDateTimeLong(value) : 'Not recorded');
/** Plain words for the plan-approval check when the record has no explanation of its own. */
const PLAN_AUTHORIZATION_LABEL: Record<string, string> = {
    verified: 'An approved plan version was in use at the time',
    ambiguous: 'More than one approved plan version could apply',
    asserted_out_of_plan: 'Recorded as outside the plan',
    approval_unknown: 'We couldn’t check the plan was approved',
    currency_unknown: 'We couldn’t check the plan was current',
    missing: 'No behaviour support plan on record',
    legacy_unknown: 'Older record — some details weren’t kept',
};
/** What each update in the history was for. */
const DELIVERY_PURPOSE_LABEL: Record<string, string> = {
    source_review: 'Sent to health and safety for a safety review',
    source_reconcile: 'Linked incident checked',
    operational_bridge: 'Control room told',
    review_assignment: 'Restraint review owner told',
    followup_assignment: 'Follow-up owner told',
    evidence_removal: 'File removed',
    due_reminder: 'Reminder sent',
};
const DELIVERY_STATUS_LABEL: Record<string, string> = {
    pending: 'Waiting to send',
    failed: 'Didn’t go through — trying again',
    delivered: 'Done',
    skipped: 'Not needed',
};
const label = (map: Record<string, string>, value: string | null | undefined) => (value ? (map[value] ?? humaniseHsValue(value)) : 'Not recorded');
/** A recorded value made readable: dates through the house format, stored codes as words. */
const readable = (value: string | number) => {
    if (typeof value === 'number') return String(value);
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)) return formatDateTimeLong(value);
    if (/^[a-z]+(_[a-z]+)+$/.test(value)) return humaniseHsValue(value);
    return value;
};
const sectionForCommand = (command: RestraintEventCommand): RestraintEventSection =>
    command.includes('observation')
        ? 'monitoring'
        : command.includes('debrief')
          ? 'debrief'
          : command.includes('followup')
            ? 'followups'
            : command.includes('evidence')
              ? 'evidence'
              : command === 'correct'
                ? 'overview'
                : 'review';
const factTone = (variant: string): PageHeaderFact['tone'] =>
    variant === 'critical' || variant === 'warning' || variant === 'success' ? variant : 'neutral';

export default function RestraintEventShow(props: Props) {
    return <EventWorkspace key={props.detail.event.id} {...props} />;
}

function EventWorkspace({ detail, workspace, pickers, return_to }: Props) {
    const d = detail.event;
    const { url, props: shared } = usePage<SharedData>();
    const viewerId = shared.auth.user?.id ?? null;
    const viewerName = shared.auth.user?.name ?? null;
    const location = restraintLocation(url, 'events');
    const section = location.section as RestraintEventSection;
    const command = location.command as RestraintEventCommand | null;
    const child = command?.includes('observation')
        ? location.observation
        : command?.includes('debrief')
          ? location.debrief
          : command?.includes('evidence')
            ? location.attachment
            : location.followup;
    const returnTo = return_to ? restraintReturn(return_to) : workspace === 'view' ? RESTRAINT_REGISTER : '/my-day';
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    // Focus moves to the panel only after the person has moved around the page (state, so render never reads a ref).
    const [interacted, setInteracted] = useState(false);
    const [picked, setPicked] = useState<RestraintStepKey | null>(null);
    const [findOpen, setFindOpen] = useState(false);
    const reference = d.reference_number ?? `RE-${d.id}`;
    const typeLabel = hsLabel('restraint_type', d.restraint_type);
    const severity = hsBadge('severity', d.severity);
    const title = d.title ?? `Restraint — ${d.client.name}`;
    const href = (next: RestraintEventSection, action: RestraintEventCommand | null = null, id?: number | null) =>
        restraintHref('events', d.id, {
            workspace: workspace as RestraintWorkspace,
            section: next,
            command: action,
            returnTo,
            child: id,
            childKey: action?.includes('observation')
                ? 'observation'
                : action?.includes('debrief')
                  ? 'debrief'
                  : action?.includes('evidence')
                    ? 'attachment'
                    : 'followup',
        });
    const replace = (next: RestraintEventSection, action: RestraintEventCommand | null = null, id?: number | null) => {
        setInteracted(true);
        router.replace({ url: href(next, action, id), preserveState: true, preserveScroll: true });
    };
    const move = (next: RestraintEventSection, action: RestraintEventCommand | null = null, id?: number | null) =>
        drafts.request(() => replace(next, action, id));
    const act = (action: RestraintEventCommand, id?: number) => move(sectionForCommand(action), action, id);
    const allowed = (action: RestraintEventCommand, id?: number) => eventCommandAllowed(detail, action, id);
    const openFind = useCallback(() => setFindOpen(true), []);
    useGroupedProfileSearchShortcut(openFind);
    useEffect(() => {
        if (interacted) panel.current?.focus();
        if (!command && location.followup) {
            const target = document.getElementById(`restraint-followup-${location.followup}`);
            target?.focus();
            target?.scrollIntoView({ block: 'nearest' });
        }
    }, [command, section, location.followup, interacted]);

    const openFollowups = d.followups.filter((item) => !item.completed_at && !item.cancelled_at);
    const files = d.attachments.filter((item) => !item.removed_at);
    const owner = d.review_owner_id;

    // The Progress card's next-step bar holds the only filled button; the header keeps "Add follow-up" and "More".
    const nextBar = restraintNextBar(detail, viewerId, act);
    const currentStep = currentRestraintStep(d);
    const selectedStep = picked ?? currentStep;
    const pickStep = (key: string) => {
        const step = RESTRAINT_STEP_ORDER.find((item) => item === key);
        if (step) setPicked(step === currentStep ? null : step);
    };
    const stepPane = restraintStepPane(detail, selectedStep, viewerId, {
        onBack: selectedStep !== currentStep ? () => setPicked(null) : undefined,
        onHistory: () => move('history'),
        run: act,
    });

    const more = (['review', 'record-debrief', 'add-observation', 'assign-review', 'add-evidence', 'link-incident', 'correct'] as RestraintEventCommand[])
        .filter((key) => key !== nextBar.owns && allowed(key))
        .filter((key) => key !== 'link-incident' || !detail.connections.incident)
        .map((key) => ({ key, label: EVENT_COMMAND_LABELS[key], icon: COMMAND_ICONS[key] ?? ClipboardCheck, run: () => act(key) }));
    const header =
        allowed('add-followup') || more.length ? (
            <>
                {allowed('add-followup') && nextBar.owns !== 'add-followup' ? (
                    <PageHeaderGlassButton icon={ClipboardList} onClick={() => act('add-followup')}>
                        {EVENT_COMMAND_LABELS['add-followup']}
                    </PageHeaderGlassButton>
                ) : null}
                {more.length ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More restraint actions">
                                More
                            </PageHeaderGlassButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {more.map((item) => (
                                <DropdownMenuItem key={item.key} onSelect={item.run}>
                                    <item.icon className="size-4" />
                                    {item.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </>
        ) : null;

    const facts: PageHeaderFact[] = [
        { value: `${severity.label} severity`, tone: factTone(severity.variant) },
        {
            value: d.within_support_plan ? 'In the plan (as reported)' : 'Outside the plan (as reported)',
            tone: d.within_support_plan ? 'neutral' : 'critical',
        },
        { value: d.injury_occurred ? 'Injury recorded' : 'No injury recorded', tone: d.injury_occurred ? 'warning' : 'neutral' },
    ];

    const railItems: PageHeaderRailItem<RestraintEventSection>[] = SECTIONS.map((item) => ({
        key: item.key,
        label: item.label,
        ...(item.key === 'followups' && openFollowups.length ? { count: openFollowups.length } : {}),
        ...(item.key === 'evidence' && files.length ? { count: files.length } : {}),
    }));
    const findGroups: GroupedProfileNavGroup[] = [{ key: 'restraint', label: 'Restraint', icon: ShieldAlert, tabs: SECTIONS }];

    const people: PersonCardItem[] = [];
    const addPerson = (name: string | null | undefined, role: string) => {
        if (!name) return;
        const same = people.find((item) => item.name === name);
        if (same) same.sub = `${same.sub} · ${role.charAt(0).toLowerCase()}${role.slice(1)}`;
        else people.push({ name, sub: role });
    };
    addPerson(d.client.name, `The person · ${d.site?.name ?? 'Site not recorded'}`);
    addPerson(owner?.name, 'Restraint review owner');
    addPerson(d.authorised_by?.name, 'Authorised it');
    addPerson(d.created_by?.name, 'Recorded it');
    for (const item of d.staff_involved) addPerson('kind' in item ? item.text : item.name, 'Staff involved');

    const correctAction = (kind: 'monitoring' | 'debrief', item: SourceFact, superseded: boolean) => {
        const cmd = kind === 'monitoring' ? 'correct-observation' : 'correct-debrief';
        return detail.can.review && !superseded && allowed(cmd, item.id) ? { label: EVENT_COMMAND_LABELS[cmd], onClick: () => act(cmd, item.id) } : null;
    };
    const followupMenu = (item: RestraintFollowup) =>
        compactMenu(
            (['complete-followup', 'reassign-followup', 'correct-followup', 'cancel-followup'] as RestraintEventCommand[]).map(
                (cmd) =>
                    allowed(cmd, item.id) && {
                        label: EVENT_COMMAND_LABELS[cmd],
                        danger: cmd === 'cancel-followup',
                        onClick: () => act(cmd, item.id),
                    },
            ),
        );
    const followupTable = (
        <FollowupTable
            rows={d.followups}
            viewerId={viewerId}
            menuFor={followupMenu}
            onComplete={(item) => act('complete-followup', item.id)}
            canComplete={(item) => allowed('complete-followup', item.id)}
            onAdd={allowed('add-followup') ? () => act('add-followup') : undefined}
        />
    );

    const minutes = d.duration_minutes;
    const main = command ? (
        <Surface aria-label="Current restraint action">
            <div className="p-5">
                <RestraintEventCommandForm
                    key={`${d.id}-${command}-${child ?? ''}`}
                    detail={detail}
                    command={command}
                    childId={child}
                    workspace={workspace}
                    pickers={pickers}
                    returnTo={returnTo}
                    onCancel={() => move(section)}
                    onDone={() => router.get(href(section), {}, { preserveScroll: true, preserveState: false })}
                />
            </div>
        </Surface>
    ) : section === 'review' ? (
        <>
            <FactRows
                title="Restraint review and owner"
                rows={[
                    ['Restraint review owner', personName(owner)],
                    ['Restraint review due', when(d.review_due_at)],
                    ['First restraint review', when(d.reviewed_at)],
                    [
                        'Version reviewed',
                        d.reviewed_source_revision === null ? 'Not reviewed yet' : `Version ${d.reviewed_source_revision} (latest is ${d.source_revision})`,
                    ],
                ]}
                action={
                    detail.connections.incident
                        ? allowed('unlink-incident')
                            ? { label: EVENT_COMMAND_LABELS['unlink-incident'], onClick: () => act('unlink-incident') }
                            : null
                        : allowed('link-incident')
                          ? { label: EVENT_COMMAND_LABELS['link-incident'], onClick: () => act('link-incident') }
                          : null
                }
            />
            <SourceFacts rows={d.reviews} empty="No restraint reviews yet." title="Restraint review" />
        </>
    ) : section === 'monitoring' ? (
        <SourceFacts rows={d.observations} empty="No checks recorded yet." title="Check" correct={(item, superseded) => correctAction('monitoring', item, superseded)} />
    ) : section === 'debrief' ? (
        <SourceFacts rows={d.debriefs} empty="No debrief recorded yet." title="Debrief" correct={(item, superseded) => correctAction('debrief', item, superseded)} />
    ) : section === 'followups' ? (
        followupTable
    ) : section === 'evidence' ? (
        <EvidenceTable
            rows={d.attachments}
            menuFor={(item) =>
                compactMenu([
                    item.download_url && { label: 'Download file', icon: Download, onClick: () => window.location.assign(item.download_url!) },
                    allowed('remove-evidence', item.id) && {
                        label: EVENT_COMMAND_LABELS['remove-evidence'],
                        icon: Trash2,
                        danger: true,
                        onClick: () => act('remove-evidence', item.id),
                    },
                ])
            }
            onAdd={allowed('add-evidence') ? () => act('add-evidence') : undefined}
        />
    ) : section === 'history' ? (
        <>
            <SourceFacts rows={d.reviews} empty="No history yet." title="History" />
            {d.deliveries.length ? (
                <Log
                    title="Updates and reminders"
                    sub="Who was told and when. Sending an update doesn’t record the restraint review."
                    limit={d.deliveries.length}
                    items={d.deliveries.map((item, index) => ({
                        key: index,
                        time: item.delivered_at ? formatTime(item.delivered_at) : item.created_at ? formatTime(item.created_at) : '',
                        tone: item.failure_code ? 'warning' : item.delivered_at ? 'success' : 'info',
                        title: `${label(DELIVERY_PURPOSE_LABEL, item.purpose)} · ${label(DELIVERY_STATUS_LABEL, item.status)}`,
                        sub: [item.delivered_at ? when(item.delivered_at) : item.created_at ? when(item.created_at) : null, item.failure_code ? 'Didn’t go through' : null]
                            .filter(Boolean)
                            .join(' · '),
                    }))}
                />
            ) : (
                <QuietLine title="Updates and reminders" sub="Who was told and when. Sending an update doesn’t record the restraint review." text="No updates sent yet." />
            )}
        </>
    ) : section === 'connections' ? (
        <LinkedRecords detail={detail} back={restraintHref('events', d.id, { workspace, section: 'connections' })} />
    ) : (
        <>
            {d.source_provenance === 'legacy_uncertainty' ? (
                <Note tone="warning">
                    Older record from before the current system. Approval and other details that weren’t recorded at the time haven’t been added
                    afterwards.
                </Note>
            ) : null}
            <Story
                title="What happened"
                sub={[
                    d.created_by?.name ? `In ${d.created_by.name}’s words` : null,
                    `${formatTime(d.started_at)}${d.ended_at ? ` – ${formatTime(d.ended_at)}` : ''}`,
                    minutes === null ? null : minutes < 1 ? 'under a minute' : minutes === 1 ? '1 minute' : formatDurationMinutes(minutes),
                ]
                    .filter(Boolean)
                    .join(' · ')}
                paras={(
                    [
                        ['What led to it', d.trigger_description],
                        ['Alternatives attempted', d.de_escalation_attempted],
                        ['What happened', d.restraint_description],
                        ['Person’s response', d.person_response],
                        ['Support afterwards', d.post_incident_support],
                        ['Injury', d.injury_occurred ? d.injury_details || 'Injury recorded — no details given' : 'No injury recorded.'],
                    ] as [string, string | null][]
                ).map(([h, t]) => ({ h, t: <span className="whitespace-pre-wrap">{t ?? 'Not recorded'}</span> }))}
            />
            {followupTable}
        </>
    );

    const planTone: SgTone = d.within_support_plan ? 'success' : 'critical';
    const recordCloser = (
        <>
            This restraint is finished when its restraint review is recorded: by {owner?.name ?? 'its owner once one is chosen'}, or anyone who reviews
            restraints{d.site ? ` at ${d.site.name}` : ''}
            {detail.can.review ? ' (you included)' : ''}.
        </>
    );
    const side = (
        <>
            <WhoFinishesCard record={recordCloser} review={detail.safety_review ? reviewCloserSentence(detail.safety_review, viewerName) : null} />
            <LinkedSafetyReviewCard review={detail.safety_review} viewerName={viewerName} />
            <KnowList
                title="Compared with the plan"
                items={[
                    { tone: planTone, text: <b>{d.within_support_plan ? 'In the plan (as reported)' : 'Outside the plan (as reported)'}</b> },
                    {
                        tone: d.within_support_plan ? 'info' : 'warning',
                        text: (
                            <>
                                <b>Plan approval</b> · {d.plan_authorization.explanation ?? label(PLAN_AUTHORIZATION_LABEL, d.plan_authorization.state)}
                            </>
                        ),
                    },
                    ...(detail.connections.plan
                        ? [
                              {
                                  tone: 'info' as SgTone,
                                  text: (
                                      <>
                                          <b>Plan</b> ·{' '}
                                          <Link href={detail.connections.plan.href} className="sg-act ml-0">
                                              Open the behaviour support plan
                                          </Link>
                                      </>
                                  ),
                              },
                          ]
                        : []),
                    ...(d.deviation_reason ? [{ tone: 'warning' as SgTone, text: <><b>Why it was different from the plan</b> · {d.deviation_reason}</> }] : []),
                ]}
            />
            <PeopleCards
                title="People"
                cols={1}
                action={d.needs_review && allowed('assign-review') ? { label: owner ? 'Change owner' : 'Choose owner', onClick: () => act('assign-review') } : null}
                items={people}
            />
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(workspace === 'view' ? [...safetyHubCrumbs('care', shared.auth.can), { title: 'Restraints', href: RESTRAINT_REGISTER }] : []),
                { title, href: href('overview') },
            ]}
        >
            <Head title={`${title} · ${reference}`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            icon={ShieldAlert}
                            title={title}
                            titleChip={
                                <PageHeaderStatusChip variant={d.needs_review ? 'warning' : 'success'}>
                                    {d.needs_review ? 'Restraint review needed' : 'Restraint review recorded'}
                                </PageHeaderStatusChip>
                            }
                            subline={['Restraint', typeLabel.toLowerCase(), d.site?.name ?? 'Site not recorded', dateTimeWithYear(d.started_at), `Restraint ${reference}`].join(' · ')}
                            actions={command ? undefined : header}
                            facts={<PageHeaderFacts items={facts} />}
                            strip={
                                <PageHeaderSteps
                                    steps={restraintRoundSteps(d)}
                                    selected={selectedStep}
                                    onSelect={pickStep}
                                    ariaLabel="Where the restraint is up to"
                                />
                            }
                            rail={
                                <PageHeaderRail
                                    items={railItems}
                                    value={section}
                                    onSelect={(next) => move(next)}
                                    onFind={openFind}
                                    maxVisible={6}
                                    ariaLabel="Restraint sections"
                                />
                            }
                        />
                    }
                >
                    <div className="grid min-w-0 gap-5">
                        <RecordProgress next={nextBar} pane={stepPane} />
                        <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                            <div
                                ref={panel}
                                tabIndex={-1}
                                role="tabpanel"
                                aria-label={command ? EVENT_COMMAND_LABELS[command] : SECTIONS.find((item) => item.key === section)?.label}
                                className="grid min-w-0 gap-5 outline-none"
                            >
                                {main}
                            </div>
                            <aside className="min-w-0" aria-label="Who finishes what, the safety review and people">
                                <SideColumn>{side}</SideColumn>
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
                    const target = SECTIONS.find((item) => item.key === key);
                    if (target) move(target.key);
                }}
                testIdPrefix="restraint-workspace"
                searchLabel="Find a restraint section"
            />
        </AppLayout>
    );
}

/** Decision 6: only links this viewer can open are listed; nothing is said about the rest. */
function LinkedRecords({ detail, back }: { detail: RestraintEventDetail; back: string }) {
    const items = (
        [
            ['incident', 'Incident', Link2],
            ['plan', 'Behaviour support plan', FileText],
            ['plan_version', 'Plan version used', FileText],
            ['plan_approval', 'Plan approval', ClipboardCheck],
            ['health_safety', 'Safety review', ClipboardCheck],
            ['control_room', 'Control room response', ShieldAlert],
            ['stay', 'Respite stay', Link2],
            ['client', 'Person', UserRound],
        ] as const
    ).flatMap(([key, title, Icon]): RowItem[] => {
        const link = detail.connections[key];
        if (!link) return [];
        return [
            {
                key,
                leading: <Icon aria-hidden="true" className="size-4" />,
                title,
                href: `${link.href}${link.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(back)}`,
            },
        ];
    });
    return items.length ? (
        <RowList title="Linked records" sub="Each linked record has its own owner and steps" items={items} />
    ) : (
        <QuietLine title="Linked records" text="No linked records you can open." />
    );
}

/** Reviews, checks and debriefs: newest first, every detail kept, with who recorded it and the version. */
function SourceFacts({
    rows,
    empty,
    title,
    correct,
}: {
    rows: SourceFact[];
    empty: string;
    title: string;
    correct?: (item: SourceFact, superseded: boolean) => { label: string; onClick: () => void } | null;
}) {
    if (!rows.length) return <QuietLine title={title} text={empty} />;
    return (
        <>
            {[...rows].reverse().map((item) => {
                const superseded = rows.some((row) => row.supersedes_id === item.id);
                const facts = Object.entries(item.facts).filter(([, value]) => typeof value === 'string' || typeof value === 'number' || Array.isArray(value));
                return (
                    <FactRows
                        key={item.id}
                        title={`${title} · ${dateTimeWithYear(item.occurred_at ?? item.created_at, 'Time not recorded')}`}
                        sub={[
                            `Recorded by ${personName(item.recorder)}`,
                            item.actual_actor ? `Done by ${personName(item.actual_actor)}` : null,
                            `Version ${item.source_revision}`,
                            superseded ? 'Replaced by a later entry' : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        action={correct?.(item, superseded) ?? null}
                        rows={
                            facts.length
                                ? facts.map(([key, value]): [ReactNode, ReactNode] => [
                                      humaniseHsValue(key),
                                      <span className="whitespace-pre-wrap">
                                          {Array.isArray(value) ? value.map((part) => readable(part)).join(', ') : readable(value as string | number)}
                                      </span>,
                                  ])
                                : [['Details', 'Not recorded']]
                        }
                    />
                );
            })}
        </>
    );
}

function FollowupTable({
    rows,
    viewerId,
    menuFor,
    onComplete,
    canComplete,
    onAdd,
}: {
    rows: RestraintFollowup[];
    viewerId: number | null;
    menuFor: (row: RestraintFollowup) => MenuItem[];
    onComplete: (row: RestraintFollowup) => void;
    canComplete: (row: RestraintFollowup) => boolean;
    onAdd?: () => void;
}) {
    const ownerName = (item: RestraintFollowup) => (item.assignee?.id != null && item.assignee.id === viewerId ? 'You' : item.assignee?.name);
    const columns: RecordTableColumn<RestraintFollowup>[] = [
        {
            key: 'followup',
            label: 'Follow-up',
            className: 'min-w-[16rem]',
            cell: (item) => <RecordIdentity title={<span className="whitespace-pre-wrap">{item.notes}</span>} sub={item.completion_outcome?.notes ?? undefined} />,
        },
        {
            key: 'owner',
            label: 'Owner',
            nowrap: true,
            cell: (item) => {
                const name = ownerName(item);
                return name ? <RecordPerson name={name} /> : <span className="text-muted-foreground">{personName(item.assignee)}</span>;
            },
        },
        {
            key: 'due',
            label: 'Due',
            nowrap: true,
            cell: (item) => (item.due_at ? formatDate(item.due_at) : <span className="text-muted-foreground">Due time not set</span>),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (item) =>
                item.cancelled_at ? (
                    <RecordIdentity title={<StatusBadge variant="neutral">Cancelled</StatusBadge>} sub={item.cancellation_reason ?? undefined} />
                ) : item.completed_at ? (
                    <RecordIdentity
                        title={
                            <StatusBadge variant="success">
                                {item.completion_outcome?.outcome ? label(FOLLOWUP_OUTCOME_LABEL, item.completion_outcome.outcome) : 'Done'}
                            </StatusBadge>
                        }
                        sub={formatDate(item.completed_at)}
                    />
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant="warning">Open</StatusBadge>
                        {canComplete(item) ? (
                            <Button type="button" variant="outline" size="sm" onClick={() => onComplete(item)}>
                                {EVENT_COMMAND_LABELS['complete-followup']}
                            </Button>
                        ) : item.assignee?.name ? (
                            <span className="text-caption">{item.assignee.name} records it</span>
                        ) : null}
                    </div>
                ),
        },
    ];
    return (
        <RecordTable
            title="Follow-ups"
            sub="Only the owner records the outcome"
            actions={
                onAdd ? (
                    <Button variant="outline" size="sm" onClick={onAdd}>
                        <ClipboardList className="size-4" /> {EVENT_COMMAND_LABELS['add-followup']}
                    </Button>
                ) : undefined
            }
            columns={columns}
            rows={rows}
            rowKey={(item) => item.id}
            rowId={(item) => `restraint-followup-${item.id}`}
            actionsFor={menuFor}
            menuTitle={(item) => item.notes}
            menuIcon={ClipboardList}
            empty={
                <p className="sg-quiet px-4 py-5">
                    <span>No follow-ups recorded.</span>
                </p>
            }
        />
    );
}

function EvidenceTable({ rows, menuFor, onAdd }: { rows: RestraintAttachment[]; menuFor: (row: RestraintAttachment) => MenuItem[]; onAdd?: () => void }) {
    const columns: RecordTableColumn<RestraintAttachment>[] = [
        {
            key: 'file',
            label: 'File',
            className: 'min-w-[16rem]',
            cell: (item) => (
                <RecordIdentity
                    title={
                        item.download_url ? (
                            <a
                                href={item.download_url}
                                className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {item.original_name}
                            </a>
                        ) : (
                            item.original_name
                        )
                    }
                    sub={[item.category ? label(ATTACHMENT_CATEGORY_LABEL, item.category) : 'File', item.notes].filter(Boolean).join(' · ')}
                />
            ),
        },
        { key: 'added', label: 'Added', nowrap: true, cell: (item) => when(item.created_at) },
        {
            key: 'availability',
            label: 'Status',
            nowrap: true,
            cell: (item) =>
                item.removed_at ? (
                    <RecordIdentity title={<StatusBadge variant="neutral">Removed</StatusBadge>} sub={item.removal_reason ? `Removal reason: ${item.removal_reason}` : undefined} />
                ) : item.availability === 'available' ? (
                    <StatusBadge variant="success">Available</StatusBadge>
                ) : (
                    <StatusBadge variant="warning">{item.availability === 'unavailable' ? 'Not available' : humaniseHsValue(item.availability)}</StatusBadge>
                ),
        },
    ];
    return (
        <RecordTable
            title="Files"
            sub="Use each file’s menu to download or remove it"
            actions={
                onAdd ? (
                    <Button variant="outline" size="sm" onClick={onAdd}>
                        <Paperclip className="size-4" /> {EVENT_COMMAND_LABELS['add-evidence']}
                    </Button>
                ) : undefined
            }
            columns={columns}
            rows={rows}
            rowKey={(item) => item.id}
            actionsFor={menuFor}
            menuTitle={(item) => item.original_name}
            menuIcon={Paperclip}
            empty={
                <p className="sg-quiet px-4 py-5">
                    <span>No files added yet.</span>
                </p>
            }
        />
    );
}
