/**
 * Behaviour-support plan (approved H&S drawings pg-hsn-101 … 101i — Stephan
 * 23 Oct 2026): one record header — the plan's title and status, the current
 * candidate, published version and review date as facts, and where the plan
 * is up to with its next step — then each section as signature blocks.
 *
 * Approval is never inferred: stages and facts read only what the plan's own
 * records say (candidate, publication, decisions), and every approval,
 * reviewer and integrity statement keeps the code's wording. Commands open the
 * unchanged BehaviourSupportPlanCommandForm; unsaved drafts still ask before
 * leaving.
 */
import {
    BehaviourSupportPlanCommandForm,
    PLAN_COMMAND_LABELS,
} from '@/components/health-safety/restraints/plan-command-form';
import type {
    PlanApproval,
    PlanContent,
    PlanDetail,
    PlanVersion,
    RestraintPickers,
} from '@/components/health-safety/restraints/types';
import {
    APPROVAL_MODES,
    approvalModeLabel,
} from '@/components/health-safety/settings/approval-policy';
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
    Journey,
    Log,
    Note,
    PeopleCards,
    PlaceCards,
    QuietLine,
    RowList,
    SideColumn,
    Story,
    Surface,
    type LogItem,
    type PersonCardItem,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    formatDate,
    formatDateOnly,
    formatDateTimeLong,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
    type RestraintPlanCommand,
    type RestraintPlanSection,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BookOpen,
    CalendarDays,
    ClipboardCheck,
    FileText,
    GitBranch,
    History,
    Link2,
    MoreHorizontal,
    Pencil,
    Send,
    ShieldAlert,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, useEffect, useRef, type ReactNode } from 'react';
import { PLAN_STATUS_META, titleCase } from '../shared';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: BookOpen },
    { key: 'content', label: 'Plan content', icon: FileText },
    { key: 'review', label: 'Reviews', icon: ClipboardCheck },
    { key: 'approval', label: 'Approval', icon: ShieldCheck },
    { key: 'versions', label: 'Versions', icon: GitBranch },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
const COMMAND_ICONS: Record<RestraintPlanCommand, LucideIcon> = {
    edit: Pencil,
    'submit-review': Send,
    review: ClipboardCheck,
    decision: ShieldCheck,
    activate: ShieldCheck,
    archive: Archive,
};
type Props = {
    detail: PlanDetail;
    workspace: RestraintWorkspace;
    pickers?: Partial<RestraintPickers>;
    return_to?: string | null;
    section?: RestraintPlanSection;
};
const time = (value: string | null | undefined) =>
    value ? formatDateTimeLong(value) : 'Not recorded';
const words = (value: string | null | undefined) =>
    value ? value.replaceAll('_', ' ') : null;
const lines = (value: string | null | undefined) =>
    (value ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

function versionLabel(versions: PlanVersion[], id: number | null | undefined): string {
    if (!id) return 'Version not retained';
    const found = versions.find((item) => item.id === id);
    return found ? `Version ${found.sequence}` : `Candidate ${id}`;
}

/** A version's content as the drawing reads it: a story, then what may and may not be used. */
function ContentBlocks({ value, sub }: { value: PlanContent; sub?: string }) {
    const proposed = lines(value.approved_interventions);
    const prohibited = lines(value.prohibited_interventions);
    return (
        <>
            <Story
                title="Plan content"
                sub={sub}
                facts={[
                    {
                        icon: ShieldAlert,
                        text: (
                            <>
                                Restrictive practice type:{' '}
                                <b>
                                    {value.restrictive_practice_type
                                        ? titleCase(value.restrictive_practice_type)
                                        : 'Not recorded'}
                                </b>
                            </>
                        ),
                    },
                    {
                        icon: CalendarDays,
                        text: `Developed ${value.developed_at ? formatDateOnly(value.developed_at) : 'date not recorded'}`,
                    },
                    {
                        icon: CalendarDays,
                        text: `Next review ${value.review_date ? formatDateOnly(value.review_date) : 'not set'}`,
                    },
                ]}
                paras={[
                    { h: 'Known triggers', t: <span className="whitespace-pre-wrap">{value.triggers ?? 'Not recorded'}</span> },
                    {
                        h: 'Alternatives and de-escalation',
                        t: <span className="whitespace-pre-wrap">{value.de_escalation_strategies ?? 'Not recorded'}</span>,
                    },
                    { h: 'Notes', t: <span className="whitespace-pre-wrap">{value.notes ?? 'Not recorded'}</span> },
                ]}
            />
            <PlaceCards
                cols={2}
                lines
                items={[
                    {
                        name: 'Proposed interventions',
                        facts: proposed.length
                            ? proposed.map((text) => ({ tone: 'brand' as SgTone, text }))
                            : [{ tone: 'none' as SgTone, text: 'Not recorded' }],
                    },
                    {
                        name: 'Interventions that must not be used',
                        facts: prohibited.length
                            ? prohibited.map((text) => ({ tone: 'critical' as SgTone, text }))
                            : [{ tone: 'none' as SgTone, text: 'Not recorded' }],
                    },
                ]}
            />
        </>
    );
}

/** Where the plan is up to — read from its status and records only; an unknown approval is never shown as done. */
function planStages(d: PlanDetail): PageHeaderStage[] | null {
    if (d.status === 'archived') return null;
    const candidate = d.candidate;
    const submitted = [...d.history].reverse().find((item) => item.command.includes('submit'));
    const decided = [...d.approvals]
        .reverse()
        .find((item) => item.decision.toLowerCase().startsWith('approv') && (!candidate || item.plan_version_id === candidate.id || item.plan_version_id === d.published_version?.candidate_version_id));
    const decisionLabel = d.current_policy?.mode === 'authorised_reviewer' ? 'Reviewer decision' : d.current_policy ? 'Specialist decision' : 'Approval decision';
    if (d.status === 'draft') {
        return [
            { label: 'Proposed', meta: candidate?.recorded_at ? formatDate(candidate.recorded_at) : undefined, state: 'now' },
            { label: 'Sent for review', state: 'todo' },
            { label: decisionLabel, state: 'todo' },
            { label: 'In use', meta: 'After approval', state: 'todo' },
        ];
    }
    if (d.status === 'under_review') {
        return [
            { label: 'Proposed', meta: candidate?.recorded_at ? formatDate(candidate.recorded_at) : undefined, state: 'done' },
            { label: 'Sent for review', meta: submitted ? formatDate(submitted.recorded_at) : undefined, state: 'done' },
            { label: decisionLabel, meta: 'Waiting', state: 'now', tone: 'warning' },
            { label: 'In use', meta: 'After approval', state: 'todo' },
        ];
    }
    // active: in use. The decision stage is done only when a decision is on record.
    return [
        { label: 'Proposed', state: 'done' },
        { label: 'Sent for review', state: 'done' },
        decided
            ? { label: decisionLabel, meta: decided.approved_at ? formatDate(decided.approved_at) : undefined, state: 'done' }
            : { label: decisionLabel, meta: 'Not on record', state: 'todo' },
        {
            label: 'In use',
            meta: d.published_version?.published_at
                ? `Since ${formatDate(d.published_version.published_at)}`
                : d.published_version
                  ? `Version ${d.published_version.sequence}`
                  : 'No retained publication',
            state: 'done',
        },
    ];
}

function planNextStep(d: PlanDetail, reviewOverdue: boolean): PageHeaderNextStep | undefined {
    const mode = APPROVAL_MODES.find((item) => item.key === d.current_policy?.mode);
    if (d.status === 'under_review') {
        return {
            label: 'Next step · approval',
            text: `${mode ? mode.label : 'Approval decision'}${d.candidate ? ` on version ${d.candidate.sequence}` : ''}`,
            meta: mode?.description ?? d.approval_explanation,
            tone: 'warning',
        };
    }
    if (d.status === 'draft') {
        return { label: 'Next step', text: PLAN_COMMAND_LABELS['submit-review'], meta: d.approval_explanation };
    }
    if (d.status === 'active' && d.content.review_date) {
        return {
            label: reviewOverdue ? 'Next step · review overdue' : 'Next step',
            text: PLAN_COMMAND_LABELS.review,
            meta: `Review due ${formatDateOnly(d.content.review_date)}`,
            tone: reviewOverdue ? 'critical' : undefined,
        };
    }
    return undefined;
}

export default function BehaviourSupportPlanShow(props: Props) {
    return <PlanWorkspace key={props.detail.id} {...props} />;
}

function PlanWorkspace({ detail: d, workspace, pickers, return_to, section: providedSection }: Props) {
    const { url } = usePage();
    const location = restraintLocation(url, 'plans');
    const section = (
        new URLSearchParams(url.split('?')[1]).has('section')
            ? location.section
            : location.approval
              ? 'approval'
              : location.version
                ? 'versions'
                : (providedSection ?? 'overview')
    ) as RestraintPlanSection;
    const command = location.command as RestraintPlanCommand | null;
    const returnTo = return_to ? restraintReturn(return_to) : d.can.view ? RESTRAINT_REGISTER + '?lens=plans' : '/my-day';
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    const reference = d.reference_number ?? `BSP-${d.id}`;
    const href = (
        next: RestraintPlanSection,
        action: RestraintPlanCommand | null = null,
        id?: number,
        key: 'version' | 'approval' = 'version',
        mode = workspace,
    ) => restraintHref('plans', d.id, { workspace: mode, section: next, command: action, returnTo, child: id, childKey: key });
    const replace = (next: RestraintPlanSection, action: RestraintPlanCommand | null = null, id?: number, key: 'version' | 'approval' = 'version') => {
        interacted.current = true;
        router.replace({ url: href(next, action, id, key), preserveState: true, preserveScroll: true });
    };
    const move = (next: RestraintPlanSection, action: RestraintPlanCommand | null = null, id?: number, key: 'version' | 'approval' = 'version') =>
        drafts.request(() => replace(next, action, id, key));
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && section === 'approval' && location.approval) {
            const target = document.getElementById(`plan-approval-${location.approval}`);
            target?.focus();
            target?.scrollIntoView({ block: 'nearest' });
        }
    }, [section, command, location.approval]);

    /** A command the viewer may run, routed through its own workspace (review / approval) when that differs. */
    const commandAction = (key: RestraintPlanCommand) => {
        const canKey = key === 'decision' ? 'approve' : (key.replaceAll('-', '_') as keyof PlanDetail['can']);
        if (!d.can[canKey]) return null;
        const target = key === 'decision' ? 'approval' : key === 'review' ? 'review' : key === 'edit' ? 'content' : 'overview';
        const mode = key === 'decision' ? 'approval' : key === 'review' ? 'review' : workspace;
        return {
            key,
            label: PLAN_COMMAND_LABELS[key],
            icon: COMMAND_ICONS[key],
            run: () => {
                if (mode !== workspace) drafts.request(() => router.get(href(target, key, undefined, 'version', mode)));
                else move(target, key);
            },
        };
    };
    const actions = (['decision', 'review', 'submit-review', 'edit', 'activate', 'archive'] as RestraintPlanCommand[])
        .map(commandAction)
        .filter((item): item is NonNullable<ReturnType<typeof commandAction>> => item !== null);
    const today = toDateInput(new Date());
    const reviewOverdue = !!d.content.review_date && d.content.review_date.slice(0, 10) < today;
    const reviewDueSoon = !!d.content.review_date && !reviewOverdue;
    const primaryKey =
        d.status === 'under_review'
            ? 'decision'
            : d.status === 'active' && (reviewOverdue || reviewDueSoon)
              ? 'review'
              : d.status === 'draft'
                ? 'submit-review'
                : null;
    const primary = actions.find((item) => item.key === primaryKey) ?? null;
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
                        <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More plan actions" />
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

    const statusMeta = PLAN_STATUS_META[d.status];
    const facts: PageHeaderFact[] = [
        {
            value: d.candidate ? `Version ${d.candidate.sequence}` : 'Not retained',
            label: 'Current candidate',
            tone: d.status === 'under_review' ? 'warning' : undefined,
        },
        {
            value: d.published_version ? `Version ${d.published_version.sequence}` : 'Not retained',
            label: d.legacy_approval_unknown ? 'Published · approval provenance unknown' : 'Published version',
        },
        {
            value: d.content.review_date ? formatDateOnly(d.content.review_date) : 'Not set',
            label: reviewOverdue ? 'Review overdue' : 'Review due',
            tone: reviewOverdue ? 'critical' : undefined,
        },
    ];
    const stages = planStages(d);
    const selected = d.selected_version;

    const people: PersonCardItem[] = [{ name: d.client.name, sub: `The person · ${d.site?.name ?? 'Site not recorded'}` }];
    if (d.developer?.name) people.push({ name: d.developer.name, sub: 'Recorded developer' });
    const latestApproval = d.approvals.at(-1);
    if (latestApproval?.approver?.name && !people.some((item) => item.name === latestApproval.approver!.name)) {
        people.push({
            name: latestApproval.approver.name,
            sub: [latestApproval.approver.role, latestApproval.approver.organisation].filter(Boolean).join(' · ') || 'Recorded approver',
            foot: `${titleCase(latestApproval.decision)} · ${versionLabel(d.versions, latestApproval.plan_version_id)}${latestApproval.recorder?.name ? ` · entered by ${latestApproval.recorder.name}` : ''}`,
        });
    }

    const main = command ? (
        <Surface aria-label="Current plan action">
            <div className="p-5">
                <BehaviourSupportPlanCommandForm
                    key={`${d.id}-${command}`}
                    detail={d}
                    command={command}
                    workspace={workspace}
                    pickers={pickers}
                    returnTo={returnTo}
                    onCancel={() => move(section)}
                    onDone={() => router.get(href(section), {}, { preserveScroll: true, preserveState: false })}
                />
            </div>
        </Surface>
    ) : section === 'content' ? (
        <>
            {d.candidate && !d.candidate.integrity_valid ? (
                <Note tone="critical">The retained candidate needs an integrity review before signoff.</Note>
            ) : null}
            <ContentBlocks
                value={d.candidate?.content ?? d.content}
                sub={d.candidate ? `Version ${d.candidate.sequence} · candidate` : 'Current content'}
            />
        </>
    ) : section === 'review' ? (
        d.reviews.length ? (
            <Log
                title="Reviews"
                sub="Newest first · each review shows who did it and which version they looked at"
                limit={d.reviews.length}
                items={[...d.reviews].reverse().map((item) => ({
                    key: item.id,
                    time: formatDate(item.reviewed_at),
                    tone: 'success',
                    title: `${titleCase(item.outcome)} · ${versionLabel(d.versions, item.plan_version_id)}`,
                    sub: [
                        formatTime(item.reviewed_at),
                        `Actual reviewer: ${item.reviewer?.name ?? 'Identity not available'}`,
                        `Recorded status: ${words(item.resulting_status) ?? 'Unchanged'}${item.requested_resulting_status && item.requested_resulting_status !== item.resulting_status ? ` · Requested: ${words(item.requested_resulting_status)}` : ''}`,
                        words(item.approval_disposition),
                        item.notes,
                    ]
                        .filter(Boolean)
                        .join(' · '),
                }))}
            />
        ) : (
            <QuietLine title="Reviews" text="No reviews recorded." />
        )
    ) : section === 'approval' ? (
        <>
            {d.approvals.length ? (
                [...d.approvals].reverse().map((item) => <ApprovalCard key={item.id} item={item} versions={d.versions} />)
            ) : (
                <QuietLine
                    title="Approval decisions"
                    sub={d.current_policy ? approvalModeLabel(d.current_policy.mode) : undefined}
                    text="No approval decisions retained."
                />
            )}
            <Note>A recorded decision does not establish verified professional competence, consent or medication authority.</Note>
        </>
    ) : section === 'versions' ? (
        <>
            {d.versions.length ? (
                <>
                    <Journey
                        title="Versions"
                        sub="Versions can’t be changed · open one to read it"
                        steps={d.versions.map((item) => ({
                            label: `Version ${item.sequence} · ${item.kind}`,
                            meta: item.recorded_at ? formatDate(item.recorded_at) : undefined,
                            state: item.id === d.candidate?.id && d.status === 'under_review' ? 'now' : 'done',
                        }))}
                    />
                    <Log
                        title="Open a version"
                        limit={d.versions.length}
                        items={[...d.versions].reverse().map((item) => ({
                            key: item.id,
                            time: item.recorded_at ? formatDate(item.recorded_at) : '',
                            tone: item.id === d.published_version?.id ? 'success' : item.id === d.candidate?.id ? 'warning' : 'none',
                            title: `Version ${item.sequence} · ${item.kind}${item.id === selected?.id ? ' · open below' : ''}`,
                            sub: [time(item.recorded_at), item.integrity_valid ? null : 'Needs an integrity review'].filter(Boolean).join(' · '),
                            action: { label: 'Open', onClick: () => drafts.request(() => router.get(href('versions', null, item.id))) },
                        }))}
                    />
                </>
            ) : (
                <QuietLine title="Versions" text="No immutable versions retained." />
            )}
            {selected ? <ContentBlocks value={selected.content} sub={`Version ${selected.sequence} · ${selected.kind}`} /> : null}
        </>
    ) : section === 'history' ? (
        d.history.length ? (
            <Log
                title="History"
                sub="Every change to this plan, newest first"
                limit={d.history.length}
                items={[...d.history].reverse().map(
                    (item): LogItem => ({
                        key: item.id,
                        time: formatDate(item.recorded_at),
                        tone: 'info',
                        title: titleCase(item.command),
                        sub: `${formatTime(item.recorded_at)} · ${item.actor?.name ?? 'Identity retained'} · source version ${item.version}`,
                    }),
                )}
            />
        ) : (
            <QuietLine title="History" text="No command history retained." />
        )
    ) : section === 'connections' ? (
        <>
            <RowList
                title="Connected work"
                items={(
                    [
                        ['client', 'Person'],
                        ['site', 'Site'],
                        ['stay', 'Respite stay'],
                    ] as const
                )
                    .filter(([key]) => d.connections[key])
                    .map(([key, label]) => ({ key, title: label, href: d.connections[key]!.href }))}
                empty="No connected records you can open."
            />
            {d.connections.events.length ? (
                <Log
                    title="Connected interventions"
                    sub="Interventions keep their own review owners, follow-ups and completion steps."
                    limit={d.connections.events.length}
                    items={d.connections.events.map((item) => ({
                        key: item.id,
                        tone: 'none',
                        title: `Intervention ${item.id}`,
                        action: {
                            label: 'Open',
                            href: `${item.href}${item.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(restraintHref('plans', d.id, { workspace, section: 'connections' }))}`,
                        },
                    }))}
                />
            ) : (
                <QuietLine title="Connected interventions" text="Interventions keep their own review owners, follow-ups and completion steps." />
            )}
        </>
    ) : (
        <>
            {d.legacy_approval_unknown ? (
                <Note tone="warning">
                    Historical status does not establish current approval. No missing specialist decision has been invented.
                </Note>
            ) : null}
            {d.current_policy ? (
                <PlaceCards
                    title="Who approves this plan"
                    sub="Set in Health & safety settings"
                    cols={2}
                    items={APPROVAL_MODES.map((mode) => ({
                        name: mode.label,
                        sub: mode.key === d.current_policy?.mode ? undefined : 'The other option',
                        flag:
                            mode.key === d.current_policy?.mode ? (
                                <StatusBadge variant="info" size="sm">
                                    This plan’s process
                                </StatusBadge>
                            ) : undefined,
                        facts: [{ tone: (mode.key === d.current_policy?.mode ? 'brand' : 'none') as SgTone, text: mode.description }],
                    }))}
                />
            ) : null}
            <FactRows
                title="On record"
                rows={[
                    ['Plan status', statusMeta?.label ?? words(d.status)],
                    [
                        'Recorded developer',
                        d.developer?.name ?? (d.developer ? 'Identity retained; current directory access unavailable' : 'Not recorded'),
                    ],
                    [
                        'Current approval workflow',
                        d.current_policy ? approvalModeLabel(d.current_policy.mode) : 'Shown in the authorised review or approval workspace',
                    ],
                    [
                        'Current publication',
                        d.published_version ? `Version ${d.published_version.sequence}` : 'No retained publication provenance',
                    ],
                ]}
            />
            {d.approval_explanation ? <Note>{d.approval_explanation}</Note> : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(d.can.view
                    ? [
                          { title: 'Health & safety', href: '/health-safety' },
                          { title: 'Safety registers', href: '/health-safety/registers' },
                          { title: 'Restrictive interventions', href: RESTRAINT_REGISTER + '?lens=plans' },
                      ]
                    : []),
                { title: d.title, href: href('overview') },
            ]}
        >
            <Head title={`${reference} · Behaviour-support plan`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            icon={BookOpen}
                            title={d.title}
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={d.status === 'under_review' ? 'warning' : d.status === 'active' ? 'success' : 'neutral'}
                                >
                                    {statusMeta?.label ?? words(d.status)}
                                </PageHeaderStatusChip>
                            }
                            subline={`${reference} · ${d.site?.name ?? 'Site not recorded'} · ${d.client.name}`}
                            actions={command ? undefined : header}
                            facts={<PageHeaderFacts items={facts} />}
                            strip={
                                !command && stages ? (
                                    <PageHeaderStages steps={stages} next={planNextStep(d, reviewOverdue)} ariaLabel="Plan stage" />
                                ) : undefined
                            }
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) => move(next)}
                                    ariaLabel="Behaviour-support plan sections"
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
                            aria-label={command ? PLAN_COMMAND_LABELS[command] : SECTIONS.find((item) => item.key === section)?.label}
                            className="grid min-w-0 gap-5 outline-none"
                        >
                            {main}
                        </div>
                        <aside className="min-w-0" aria-label="People">
                            <SideColumn>
                                <PeopleCards title="People" cols={1} items={people} />
                            </SideColumn>
                        </aside>
                    </div>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}

/** One approval decision with everything on record: the exact version, who, under which workflow, when it applies, why, and its evidence. */
function ApprovalCard({ item, versions }: { item: PlanApproval; versions: PlanVersion[] }) {
    return (
        <section id={`plan-approval-${item.id}`} tabIndex={-1} className="outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <FactRows
                title={`${titleCase(item.decision)} · ${versionLabel(versions, item.plan_version_id)}`}
                sub={time(item.approved_at)}
                rows={[
                    ['Exact candidate', item.plan_version_id],
                    ['Recorded approver', item.approver?.name ?? 'Protected identity'],
                    ...(item.approver?.role ? ([['Approver’s role', item.approver.role]] as [ReactNode, ReactNode][]) : []),
                    ...(item.approver?.organisation ? ([['Organisation', item.approver.organisation]] as [ReactNode, ReactNode][]) : []),
                    ...(item.approver?.decision_reference
                        ? ([['Their decision reference', item.approver.decision_reference]] as [ReactNode, ReactNode][])
                        : []),
                    ['Decision origin', words(item.origin)],
                    ['Workflow at decision', item.applied_policy ? approvalModeLabel(item.applied_policy.mode) : 'Not retained'],
                    ['Effective from', time(item.effective_from)],
                    ['Effective until', item.effective_to ? time(item.effective_to) : 'No end date recorded'],
                    ...(item.recorder?.name ? ([['Entered by', item.recorder.name]] as [ReactNode, ReactNode][]) : []),
                    ...(item.basis ? ([['Decision basis', <span className="whitespace-pre-wrap">{item.basis}</span>]] as [ReactNode, ReactNode][]) : []),
                    ...(item.evidence.length
                        ? ([
                              [
                                  'Evidence',
                                  <span className="flex flex-wrap gap-2">
                                      {item.evidence.map((evidence, index) => (
                                          <Fragment key={index}>
                                              {evidence.href ? (
                                                  <Button asChild variant="outline" size="sm">
                                                      <a href={evidence.href}>{evidence.label ?? 'Open decision evidence'}</a>
                                                  </Button>
                                              ) : (
                                                  <span className="text-muted-foreground">
                                                      {evidence.label ?? 'Evidence identity concealed'} · {evidence.availability ?? 'Unavailable'}
                                                  </span>
                                              )}
                                          </Fragment>
                                      ))}
                                  </span>,
                              ],
                          ] as [ReactNode, ReactNode][])
                        : []),
                ]}
            />
        </section>
    );
}
