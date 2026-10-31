/**
 * Restraint record (approved H&S drawings pg-hsn-102 … 102i —
 * Stephan 23 Oct 2026): one record header — the restraint and the person,
 * its review state, the facts that need someone, and where it is up to with
 * the next step — then each section as signature blocks.
 *
 * Every recorded fact is kept: observations, debriefs and reviews are source
 * records whose fields vary, so each shows all of its facts with who recorded
 * it and the source revision. Restraint wording (plan use, authorization,
 * review state, delivery) stays word for word. Commands open the unchanged
 * RestraintEventCommandForm, gated by eventCommandAllowed; unsaved drafts still
 * ask before leaving.
 */
import {
    EVENT_COMMAND_LABELS,
    eventCommandAllowed,
    RestraintEventCommandForm,
} from '@/components/health-safety/restraints/event-command-form';
import type {
    RestraintAttachment,
    RestraintEventDetail,
    RestraintFollowup,
    RestraintPickers,
    SourceFact,
    SourcePerson,
} from '@/components/health-safety/restraints/types';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
    type PageHeaderNextStep,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
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
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTimeLong, formatTime } from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
    type RestraintEventCommand,
    type RestraintEventSection,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    ClipboardList,
    Clock,
    Download,
    FileText,
    HeartPulse,
    History,
    Link2,
    MapPin,
    MessageCircle,
    MoreHorizontal,
    Paperclip,
    Pencil,
    ShieldAlert,
    Timer,
    Trash2,
    UserCog,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';
import {
    hsBadge,
    hsLabel,
    humaniseHsValue,
} from '@/lib/health-safety/hs-labels';
import { ATTACHMENT_CATEGORY_LABEL } from '../shared';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'review', label: 'Review and owner', icon: ClipboardCheck },
    { key: 'monitoring', label: 'Monitoring', icon: HeartPulse },
    { key: 'debrief', label: 'Debrief', icon: MessageCircle },
    { key: 'followups', label: 'Follow-ups', icon: ClipboardList },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Linked records', icon: Link2 },
] as const;
const COMMAND_ICONS: Partial<Record<RestraintEventCommand, LucideIcon>> = {
    review: ClipboardCheck,
    'assign-review': UserCog,
    'add-followup': ClipboardList,
    'add-observation': HeartPulse,
    'record-debrief': MessageCircle,
    'add-evidence': Paperclip,
    correct: Pencil,
};
type Props = {
    detail: RestraintEventDetail;
    workspace: 'view' | 'review';
    pickers?: Partial<RestraintPickers>;
    return_to?: string | null;
};
const personName = (person: SourcePerson | null) =>
    person?.name ?? (person ? 'Name hidden — you don’t have access' : 'Not recorded');
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
    review_assignment: 'Review owner told',
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
const FOLLOWUP_OUTCOME_LABEL: Record<string, string> = {
    recorded: 'Done',
    contacted: 'Contact made',
    attempted: 'Tried to make contact',
    unable_to_contact: 'Couldn’t make contact',
    declined: 'Declined',
    not_required: 'Not needed',
    other: 'Done (other)',
};
const label = (map: Record<string, string>, value: string | null | undefined) =>
    value ? (map[value] ?? humaniseHsValue(value)) : 'Not recorded';
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

export default function RestraintEventShow(props: Props) {
    return <EventWorkspace key={props.detail.event.id} {...props} />;
}

function EventWorkspace({ detail, workspace, pickers, return_to }: Props) {
    const d = detail.event;
    const { url } = usePage();
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
    const interacted = useRef(false);
    const reference = d.reference_number ?? `RE-${d.id}`;
    const typeLabel = hsLabel('restraint_type', d.restraint_type);
    const severity = hsBadge('severity', d.severity);
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
        interacted.current = true;
        router.replace({ url: href(next, action, id), preserveState: true, preserveScroll: true });
    };
    const move = (next: RestraintEventSection, action: RestraintEventCommand | null = null, id?: number | null) =>
        drafts.request(() => replace(next, action, id));
    const act = (action: RestraintEventCommand, id?: number) => move(sectionForCommand(action), action, id);
    const allowed = (action: RestraintEventCommand, id?: number) => eventCommandAllowed(detail, action, id);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && location.followup) {
            const target = document.getElementById(`restraint-followup-${location.followup}`);
            target?.focus();
            target?.scrollIntoView({ block: 'nearest' });
        }
    }, [command, section, location.followup]);

    const openFollowups = d.followups.filter((item) => !item.completed_at && !item.cancelled_at);
    const owner = d.review_owner_id ? personName(d.review_owner_id) : null;

    // Header actions: the same commands and gates as before, the review first while it is needed.
    const actions = (['review', 'assign-review', 'add-followup', 'add-observation', 'record-debrief', 'add-evidence', 'correct'] as RestraintEventCommand[])
        .filter((key) => allowed(key))
        .map((key) => ({ key, label: EVENT_COMMAND_LABELS[key], icon: COMMAND_ICONS[key] ?? ClipboardCheck, run: () => act(key) }));
    const primary = (d.needs_review ? actions.find((item) => item.key === 'review') : null) ?? actions[0] ?? null;
    const rest = actions.filter((item) => item !== primary);
    const glass = rest.slice(0, 2);
    const overflow = rest.slice(2);
    const header = actions.length ? (
        <>
            {glass.map((item) => (
                <PageHeaderGlassButton key={item.key} onClick={item.run}>
                    {item.label}
                </PageHeaderGlassButton>
            ))}
            {overflow.length ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More restraint actions" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {overflow.map((item) => (
                            <DropdownMenuItem key={item.key} onSelect={item.run}>
                                <item.icon className="size-4" />
                                {item.label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
            {primary ? (
                <PageHeaderPrimaryButton icon={primary.icon} onClick={primary.run}>
                    {primary.label}
                </PageHeaderPrimaryButton>
            ) : null}
        </>
    ) : null;

    const facts: PageHeaderFact[] = [
        { value: typeLabel, label: 'Restraint type', tone: d.within_support_plan ? undefined : 'critical' },
        { value: severity.label, label: 'Severity', tone: severity.variant === 'success' || severity.variant === 'warning' || severity.variant === 'critical' ? severity.variant : undefined },
        ...(d.review_due_at && d.needs_review ? [{ value: dateTimeWithYear(d.review_due_at), label: 'Review due', tone: 'warning' as const }] : []),
        ...(openFollowups.length
            ? [{ value: String(openFollowups.length), label: openFollowups.length === 1 ? 'Open follow-up' : 'Open follow-ups' }]
            : []),
    ];
    const lastDebrief = d.debriefs.at(-1);
    const stages: PageHeaderStage[] = [
        {
            label: 'Recorded',
            meta: [d.created_by?.name, d.started_at ? formatDate(d.started_at) : null].filter(Boolean).join(' · ') || undefined,
            state: 'done',
        },
        { label: 'Review owner', meta: owner ?? 'Unassigned', state: owner ? 'done' : 'now', tone: owner ? undefined : 'warning' },
        {
            label: 'Monitoring',
            meta: d.observations.length ? `${d.observations.length} ${d.observations.length === 1 ? 'check' : 'checks'}` : 'None recorded',
            state: d.observations.length ? 'done' : 'todo',
        },
        {
            label: 'Debrief',
            meta: lastDebrief ? formatDate(lastDebrief.occurred_at ?? lastDebrief.created_at ?? '') : 'Not recorded',
            state: lastDebrief ? 'done' : 'todo',
        },
        d.needs_review
            ? { label: 'Review', meta: d.review_due_at ? `Due ${formatDate(d.review_due_at)}` : 'Due time not set', state: owner ? 'now' : 'todo', tone: 'warning' }
            : { label: 'Review', meta: d.reviewed_at ? formatDate(d.reviewed_at) : 'Recorded', state: 'done' },
    ];
    const next: PageHeaderNextStep | undefined = !d.needs_review
        ? undefined
        : owner
          ? {
                label: d.review_due_at ? `Next step · due ${formatDate(d.review_due_at)}, ${formatTime(d.review_due_at)}` : 'Next step',
                text: `${owner} records the review`,
                meta: [
                    `Review owner · ${d.reviewed_at ? 'Changed since the last review' : 'First review not recorded yet'}`,
                    allowed('review') ? null : `You can’t record this review · ask ${owner} or a health and safety manager`,
                ]
                    .filter(Boolean)
                    .join(' · '),
                tone: 'warning',
            }
          : {
                label: 'Next step',
                text: EVENT_COMMAND_LABELS['assign-review'],
                meta: allowed('assign-review')
                    ? 'Nobody owns the review yet'
                    : 'Nobody owns the review yet · ask a health and safety manager to choose one',
                tone: 'warning',
            };

    const people: PersonCardItem[] = [];
    const addPerson = (name: string | null | undefined, role: string) => {
        if (!name) return;
        const same = people.find((item) => item.name === name);
        if (same) same.sub = `${same.sub} · ${role}`;
        else people.push({ name, sub: role });
    };
    addPerson(d.client.name, `The person · ${d.site?.name ?? 'Site not recorded'}`);
    addPerson(d.review_owner_id?.name, 'Review owner');
    addPerson(d.created_by?.name, 'Recorded it');
    for (const item of d.staff_involved) addPerson('kind' in item ? item.text : item.name, 'Staff involved');
    addPerson(d.authorised_by?.name, 'Authorised by');

    const correctAction = (kind: 'monitoring' | 'debrief', item: SourceFact, superseded: boolean) => {
        const cmd = kind === 'monitoring' ? 'correct-observation' : 'correct-debrief';
        return detail.can.review && !superseded && allowed(cmd, item.id)
            ? { label: EVENT_COMMAND_LABELS[cmd], onClick: () => act(cmd, item.id) }
            : null;
    };

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
                title="Review and owner"
                rows={[
                    ['Review owner', personName(d.review_owner_id)],
                    ['Review due', when(d.review_due_at)],
                    ['First review', when(d.reviewed_at)],
                    [
                        'Version reviewed',
                        d.reviewed_source_revision === null
                            ? 'Not reviewed yet'
                            : `Version ${d.reviewed_source_revision} (latest is ${d.source_revision})`,
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
            <SourceFacts rows={d.reviews} empty="No reviews yet." title="Review" />
        </>
    ) : section === 'monitoring' ? (
        <SourceFacts rows={d.observations} empty="No checks recorded yet." title="Check" correct={(item, superseded) => correctAction('monitoring', item, superseded)} />
    ) : section === 'debrief' ? (
        <SourceFacts rows={d.debriefs} empty="No debrief recorded yet." title="Debrief" correct={(item, superseded) => correctAction('debrief', item, superseded)} />
    ) : section === 'followups' ? (
        <FollowupTable
            rows={d.followups}
            menuFor={(item) =>
                compactMenu(
                    (['complete-followup', 'reassign-followup', 'correct-followup', 'cancel-followup'] as RestraintEventCommand[]).map(
                        (cmd) =>
                            allowed(cmd, item.id) && {
                                label: EVENT_COMMAND_LABELS[cmd],
                                danger: cmd === 'cancel-followup',
                                onClick: () => act(cmd, item.id),
                            },
                    ),
                )
            }
            onAdd={allowed('add-followup') ? () => act('add-followup') : undefined}
        />
    ) : section === 'evidence' ? (
        <EvidenceTable
            rows={d.attachments}
            menuFor={(item) =>
                compactMenu([
                    item.download_url && {
                        label: 'Download file',
                        icon: Download,
                        onClick: () => window.location.assign(item.download_url!),
                    },
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
                    sub="Who was told and when. Sending an update doesn’t complete the review."
                    limit={d.deliveries.length}
                    items={d.deliveries.map((item, index) => ({
                        key: index,
                        time: item.delivered_at ? formatTime(item.delivered_at) : item.created_at ? formatTime(item.created_at) : '',
                        tone: item.failure_code ? 'warning' : item.delivered_at ? 'success' : 'info',
                        title: `${label(DELIVERY_PURPOSE_LABEL, item.purpose)} · ${label(DELIVERY_STATUS_LABEL, item.status)}`,
                        sub: [
                            item.delivered_at ? when(item.delivered_at) : item.created_at ? when(item.created_at) : null,
                            item.failure_code ? 'Didn’t go through' : null,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                    }))}
                />
            ) : (
                <QuietLine
                    title="Updates and reminders"
                    sub="Who was told and when. Sending an update doesn’t complete the review."
                    text="No updates sent yet."
                />
            )}
        </>
    ) : section === 'connections' ? (
        <RowList
            title="Linked records"
            sub="Each linked record has its own owner and steps"
            items={(
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
            ).map(([key, label, Icon]): RowItem => {
                const link = detail.connections[key];
                return {
                    key,
                    leading: <Icon aria-hidden="true" className="size-4" />,
                    title: label,
                    sub: link ? undefined : 'Not linked, or you don’t have access — ask a health and safety manager',
                    href: link
                        ? `${link.href}${link.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(restraintHref('events', d.id, { workspace, section: 'connections' }))}`
                        : undefined,
                };
            })}
        />
    ) : (
        <>
            {d.source_provenance === 'legacy_uncertainty' ? (
                <Note tone="warning">
                    Older record from before the current system. Approval and other details that weren’t recorded at the time
                    haven’t been added afterwards.
                </Note>
            ) : null}
            <Story
                title="What happened"
                facts={[
                    { icon: MapPin, text: d.site?.name ?? 'Site not recorded' },
                    {
                        icon: Clock,
                        text: (
                            <>
                                <b>{formatDate(d.started_at)}</b>, {formatTime(d.started_at)}
                                {d.ended_at ? ` – ${formatTime(d.ended_at)}` : ''}
                            </>
                        ),
                    },
                    { icon: Timer, text: d.duration_minutes === null ? 'Duration not recorded' : `${d.duration_minutes} minutes` },
                    { icon: HeartPulse, text: d.injury_occurred ? 'Injury recorded' : 'No injury recorded' },
                ]}
                paras={(
                    [
                        ['What led to it', d.trigger_description],
                        ['Alternatives attempted', d.de_escalation_attempted],
                        ['What happened', d.restraint_description],
                        ['Person’s response', d.person_response],
                        ['Support afterwards', d.post_incident_support],
                        ...(d.injury_occurred
                            ? ([['Injury', d.injury_details || 'Injury recorded — no details given']] as [string, string | null][])
                            : []),
                    ] as [string, string | null][]
                ).map(([h, t]) => ({ h, t: <span className="whitespace-pre-wrap">{t ?? 'Not recorded'}</span> }))}
            />
            <FactRows
                title="On record"
                rows={[
                    ['Started', when(d.started_at)],
                    ['Ended', when(d.ended_at)],
                    [
                        'Staff involved',
                        d.staff_involved.map((item) => ('kind' in item ? item.text : personName(item))).join(', ') || 'Not recorded',
                    ],
                    ['Authorised by', personName(d.authorised_by)],
                ]}
            />
        </>
    );

    const planTone: SgTone = d.within_support_plan ? 'success' : 'critical';
    const side = (
        <>
            {!command && section === 'overview' ? (
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
                        ...(d.deviation_reason
                            ? [{ tone: 'warning' as SgTone, text: <><b>Why it was different from the plan</b> · {d.deviation_reason}</> }]
                            : []),
                    ]}
                />
            ) : null}
            <PeopleCards title="People" cols={1} items={people} />
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(workspace === 'view'
                    ? [
                          { title: 'Health & safety', href: '/health-safety' },
                          { title: 'Injuries and care', href: '/health-safety/registers?view=care' },
                          { title: 'Restraints', href: RESTRAINT_REGISTER },
                      ]
                    : []),
                { title: `${typeLabel} restraint — ${d.client.name}`, href: href('overview') },
            ]}
        >
            <Head title={`${typeLabel} restraint · ${reference}`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            icon={ShieldAlert}
                            title={`${typeLabel} restraint — ${d.client.name}`}
                            titleChip={
                                <PageHeaderStatusChip variant={d.needs_review ? 'warning' : 'success'}>
                                    {d.needs_review ? 'Needs review' : 'Reviewed'}
                                </PageHeaderStatusChip>
                            }
                            subline={[
                                reference,
                                d.site?.name ?? 'Site not recorded',
                                dateTimeWithYear(d.started_at),
                                owner ? `Review owner ${owner}` : 'No review owner',
                            ].join(' · ')}
                            actions={command ? undefined : header}
                            facts={<PageHeaderFacts items={facts} />}
                            strip={command ? undefined : <PageHeaderStages steps={stages} next={next} ariaLabel="Restraint stage" />}
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) => move(next)}
                                    ariaLabel="Restraint sections"
                                />
                            }
                        />
                    }
                >
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                        <div
                            ref={panel}
                            tabIndex={-1}
                            role="tabpanel"
                            aria-label={command ? EVENT_COMMAND_LABELS[command] : SECTIONS.find((item) => item.key === section)?.label}
                            className="grid min-w-0 gap-5 outline-none"
                        >
                            {main}
                        </div>
                        <aside className="min-w-0" aria-label="Plan use and people">
                            <SideColumn>{side}</SideColumn>
                        </aside>
                    </div>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
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
                const facts = Object.entries(item.facts).filter(
                    ([, value]) => typeof value === 'string' || typeof value === 'number' || Array.isArray(value),
                );
                return (
                    <FactRows
                        key={item.id}
                        title={`${title} · ${dateTimeWithYear(item.occurred_at ?? item.created_at, "Time not recorded")}`}
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
    menuFor,
    onAdd,
}: {
    rows: RestraintFollowup[];
    menuFor: (row: RestraintFollowup) => MenuItem[];
    onAdd?: () => void;
}) {
    const columns: RecordTableColumn<RestraintFollowup>[] = [
        {
            key: 'followup',
            label: 'Follow-up',
            className: 'min-w-[16rem]',
            cell: (item) => (
                <RecordIdentity
                    title={<span className="whitespace-pre-wrap">{item.notes}</span>}
                    sub={item.completion_outcome?.notes ?? undefined}
                />
            ),
        },
        {
            key: 'owner',
            label: 'Owner',
            nowrap: true,
            cell: (item) =>
                item.assignee?.name ? <RecordPerson name={item.assignee.name} /> : <span className="text-muted-foreground">{personName(item.assignee)}</span>,
        },
        {
            key: 'due',
            label: 'Due',
            nowrap: true,
            cell: (item) =>
                item.due_at ? (
                    <RecordIdentity title={formatDate(item.due_at)} sub={formatTime(item.due_at)} />
                ) : (
                    <span className="text-muted-foreground">Due time not set</span>
                ),
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
                        title={<StatusBadge variant="success">{item.completion_outcome?.outcome ? label(FOLLOWUP_OUTCOME_LABEL, item.completion_outcome.outcome) : 'Done'}</StatusBadge>}
                        sub={when(item.completed_at)}
                    />
                ) : (
                    <StatusBadge variant="warning">Open</StatusBadge>
                ),
        },
    ];
    return (
        <RecordTable
            title="Follow-ups"
            sub="Each follow-up has an owner · only the owner can record the outcome"
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

function EvidenceTable({
    rows,
    menuFor,
    onAdd,
}: {
    rows: RestraintAttachment[];
    menuFor: (row: RestraintAttachment) => MenuItem[];
    onAdd?: () => void;
}) {
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
                    sub={[item.category ? label(ATTACHMENT_CATEGORY_LABEL, item.category) : 'Evidence', item.notes].filter(Boolean).join(' · ')}
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
                    <RecordIdentity
                        title={<StatusBadge variant="neutral">Removed</StatusBadge>}
                        sub={item.removal_reason ? `Removal reason: ${item.removal_reason}` : undefined}
                    />
                ) : item.availability === 'available' ? (
                    <StatusBadge variant="success">Available</StatusBadge>
                ) : (
                    <StatusBadge variant="warning">{item.availability === 'unavailable' ? 'Not available' : humaniseHsValue(item.availability)}</StatusBadge>
                ),
        },
    ];
    return (
        <RecordTable
            title="Evidence"
            sub="Files · use each file’s menu to download or remove it"
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
