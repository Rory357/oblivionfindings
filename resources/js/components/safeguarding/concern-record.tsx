/**
 * Safeguarding concern record — approved H&S drawings pg-hsn-22 … 22i
 * (Stephan, 23 Oct 2026).
 *
 * One record header: the concern and its subject, the status, the facts that
 * need someone (severity, next risk review, overdue actions), the six stages
 * with the next step, and the record's sections as rail tabs. The body is the
 * drawn signature blocks. Every workflow action opens the same pane the
 * concern dialog uses (exported from concern-dialog.tsx), so the commands,
 * permission guards and wording are unchanged.
 *
 * Need-to-know: a restricted concern shows only its locked shell, and the
 * "viewing is logged" cue stays. Each server load of a concern records a
 * viewing in its access log, so switching tabs only changes the address on
 * the client (onSectionChange) and never reloads the record.
 */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    dateTimeWithYear,
    dateWithYear,
    plural,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
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
    ActionItemPane,
    AssignPane,
    ClosePane,
    DeclassificationRequestPane,
    DeclassificationReviewPane,
    InvestigationCompleteForm,
    InvestigationPane,
    ReportAckForm,
    ReportPane,
    RiskPane,
    TriagePane,
    type ActionKey,
    type ConcernDetail,
    type SectionKey,
} from '@/components/safeguarding/concern-dialog';
import {
    CONCERN_STAGES,
    SEVERITY_LABELS,
    SEVERITY_VARIANT,
    concernKind,
    isTerminal,
    riskVariant,
    titleCase,
} from '@/components/safeguarding/concern-stages';
import {
    FactRows,
    Journey,
    KnowList,
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
    type RowItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AttachmentUploader, formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDate, formatTime, toDateInput } from '@/lib/datetime';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BadgeCheck,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Download,
    FileText,
    Landmark,
    ListTodo,
    Lock,
    MapPin,
    MoreHorizontal,
    RadioTower,
    Search,
    Shield,
    ShieldAlert,
    Tag,
    Trash2,
    User as UserIcon,
    UserCog,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Sections and links                                                 */
/* ------------------------------------------------------------------ */

const SECTIONS: { key: SectionKey; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'timeline', label: 'Timeline' },
    { key: 'risk', label: 'Risk' },
    { key: 'investigation', label: 'Investigation' },
    { key: 'reports', label: 'External reports' },
    { key: 'actions', label: 'Action plan' },
    { key: 'evidence', label: 'Evidence' },
    { key: 'linked', label: 'Linked records' },
];

const ACTION_KEYS: ActionKey[] = [
    'triage',
    'assign',
    'investigation',
    'report',
    'risk',
    'action',
    'close',
    'declassification',
    'declassification_review',
];

function query(url: string): URLSearchParams {
    return new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '');
}

/** `?section=risk` opens a section; anything else opens the overview. */
export function concernSectionFromUrl(url: string): SectionKey | null {
    const section = query(url).get('section');
    return SECTIONS.some((item) => item.key === section)
        ? (section as SectionKey)
        : null;
}

/** `?action=triage` asks for a pane; the record still checks the viewer may take it. */
export function concernActionFromUrl(url: string): ActionKey | null {
    const action = query(url).get('action');
    return ACTION_KEYS.includes(action as ActionKey)
        ? (action as ActionKey)
        : null;
}

/** The record's address: the section and pane it opens on, and where Back returns to. */
export function concernRecordHref(
    id: number,
    options: {
        section?: SectionKey | null;
        action?: ActionKey | null;
        returnTo?: string | null;
    } = {},
): string {
    const params = new URLSearchParams();
    if (options.section && options.section !== 'overview')
        params.set('section', options.section);
    if (options.action) params.set('action', options.action);
    if (options.returnTo) params.set('return_to', options.returnTo);
    const search = params.toString();
    return `/safeguarding/${id}${search ? `?${search}` : ''}`;
}

/* ------------------------------------------------------------------ */
/*  Reading the concern                                                */
/* ------------------------------------------------------------------ */

const TRIAGE_FIRST = 'Triage the concern first.';

const toneOf = (variant: StatusVariant): SgTone =>
    variant === 'neutral' ? 'none' : variant;

function shortDay(value: string | null | undefined): string | undefined {
    return value ? formatDate(value) : undefined;
}

function byTime<T>(items: T[], at: (item: T) => string | null | undefined): T[] {
    return [...items].sort(
        (a, b) =>
            new Date(at(a) ?? 0).getTime() - new Date(at(b) ?? 0).getTime(),
    );
}

function isOpenInvestigation(status: string): boolean {
    return !['completed', 'abandoned'].includes(status);
}

function isOpenAction(status: string): boolean {
    return !['completed', 'cancelled'].includes(status);
}

interface ConcernFacts {
    can: NonNullable<ConcernDetail['can']>;
    terminal: boolean;
    reported: boolean;
    subjectName: string;
    firstName: string | null;
    lead: string | null;
    latestRisk: NonNullable<ConcernDetail['risk_assessments']>[number] | null;
    nextReview: string | null;
    /** The review date is a calendar day: due today is not yet overdue. */
    reviewOverdue: boolean;
    reviewToday: boolean;
    openInvestigation: NonNullable<ConcernDetail['investigations']>[number] | null;
    openActions: NonNullable<ConcernDetail['action_plans']>;
    overdueActions: NonNullable<ConcernDetail['action_plans']>;
    referralMissing: boolean;
    awaitingReplies: NonNullable<ConcernDetail['external_reports']>;
    activeAlerts: NonNullable<ConcernDetail['alerts']>;
}

function readConcern(d: ConcernDetail): ConcernFacts {
    const subject = d.people?.subject ?? null;
    const risks = byTime(d.risk_assessments ?? [], (r) => r.assessed_at);
    const latestRisk = risks.at(-1) ?? null;
    const nextReview = latestRisk?.next_review_date ?? null;
    const openActions = (d.action_plans ?? []).filter((a) => isOpenAction(a.status));
    return {
        can: d.can ?? {
            update: false,
            investigate: false,
            report_external: false,
            request_declassification: false,
            approve_declassification: false,
        },
        terminal: isTerminal(d.status),
        reported: d.status === 'reported',
        subjectName: subject?.name ?? 'Subject withheld',
        firstName: subject?.name ? subject.name.split(/\s+/)[0] : null,
        lead: d.people?.assigned_to ?? null,
        latestRisk,
        nextReview,
        reviewOverdue: !!nextReview && toDateInput(nextReview) < toDateInput(new Date()),
        reviewToday: !!nextReview && toDateInput(nextReview) === toDateInput(new Date()),
        openInvestigation:
            (d.investigations ?? []).find((i) => isOpenInvestigation(i.status)) ?? null,
        openActions,
        overdueActions: openActions.filter((a) => a.overdue),
        referralMissing:
            !!d.requires_external_referral && !(d.external_reports ?? []).length,
        awaitingReplies: (d.external_reports ?? []).filter((r) => !r.ack_received),
        activeAlerts: (d.alerts ?? []).filter((a) => a.active),
    };
}

function statusVariant(status: string): StatusVariant {
    if (status === 'reported') return 'warning';
    if (status === 'closed') return 'success';
    if (status === 'no_action_required') return 'neutral';
    if (status === 'referred_external') return 'critical';
    return 'info';
}

/** The six stages, filled to where the concern is, with who and when under each. */
function concernStages(d: ConcernDetail, f: ConcernFacts): PageHeaderStage[] {
    const index = d.stage_index;
    const noAction = d.status === 'no_action_required';
    const closed = d.status === 'closed';
    const firstInvestigation = byTime(d.investigations ?? [], (i) => i.started_at)[0];
    const metas: (ReactNode | undefined)[] = [
        shortDay(d.reported_at),
        noAction ? 'No further action' : shortDay(d.triage?.at),
        d.status === 'referred_external'
            ? 'Referred external'
            : firstInvestigation?.started_at
              ? index === 2
                  ? `Since ${formatDate(firstInvestigation.started_at)}`
                  : formatDate(firstInvestigation.started_at)
              : undefined,
        index >= 3 && (d.action_plans ?? []).length
            ? f.openActions.length
                ? `${f.openActions.length} open`
                : 'All done'
            : undefined,
        undefined,
        d.closure?.at ? formatDate(d.closure.at) : undefined,
    ];
    const last = CONCERN_STAGES.length - 1;
    return CONCERN_STAGES.map((label, i) => {
        const state: PageHeaderStage['state'] = closed
            ? 'done'
            : noAction
              ? i <= index || i === last
                  ? 'done'
                  : 'todo'
              : i < index
                ? 'done'
                : i === index
                  ? 'now'
                  : 'todo';
        const tone =
            state === 'now'
                ? f.referralMissing || f.overdueActions.length
                    ? ('critical' as const)
                    : f.reported || f.reviewOverdue
                      ? ('warning' as const)
                      : undefined
                : undefined;
        return { label, meta: metas[i], state, tone };
    });
}

/** Who does what next, in one line, with what it unlocks underneath. */
function concernNextStep(d: ConcernDetail, f: ConcernFacts): PageHeaderNextStep | undefined {
    if (f.terminal) return undefined;
    const who = f.lead;
    const review = f.nextReview
        ? f.reviewOverdue
            ? ` · risk review overdue since ${formatDate(f.nextReview)}`
            : f.reviewToday
              ? ' · risk review due today'
              : ` · risk review due ${formatDate(f.nextReview)}`
        : '';
    const told =
        d.people?.subject && !d.subject_informed
            ? `${f.firstName ?? 'The subject'} hasn’t been told yet`
            : null;
    let text: string;
    let meta: string | null = null;
    let tone: PageHeaderNextStep['tone'] = 'warning';
    if (f.reported) {
        text = who ? `${who} triages the concern` : 'Triage the concern';
        meta = 'Triage decides the path — investigate, refer externally, or no further action.';
    } else if (!who) {
        text = 'Assign a lead';
        meta = 'Nobody leads this concern yet';
    } else if (f.referralMissing) {
        text = `${who} logs the external referral`;
        meta = 'Referral indicated at triage — no report logged yet.';
        tone = 'critical';
    } else if (d.status === 'investigating') {
        text = `${f.openInvestigation?.lead ?? who} completes the investigation`;
        meta = 'Lead · then the action plan';
    } else if (d.status === 'triaged') {
        text =
            d.triage?.decision === 'refer_external'
                ? `${who} logs the referral`
                : `${who} starts the investigation`;
        meta = 'Lead';
    } else if (d.status === 'referred_external') {
        text = f.awaitingReplies.length
            ? `${who} records the authority’s reply`
            : `${who} agrees the action plan`;
        meta = 'Lead';
    } else if (d.status === 'action_plan') {
        text = f.openActions.length
            ? `${who} sees the action plan through`
            : `${who} moves the concern to monitoring`;
        meta = f.openActions.length ? plural(f.openActions.length, 'open action') : 'Every action is done';
        if (f.overdueActions.length) tone = 'critical';
    } else {
        text = `${who} closes the concern once it’s safe to`;
        meta = 'Lead · monitoring';
    }
    return {
        label: `Next step${review}`,
        text,
        meta: [meta, told].filter(Boolean).join(' · ') || undefined,
        tone: f.reviewOverdue ? 'critical' : tone,
    };
}

/* ------------------------------------------------------------------ */
/*  Header actions                                                     */
/* ------------------------------------------------------------------ */

interface HeaderAction {
    key: string;
    label: string;
    icon: LucideIcon;
    run: () => void;
    /** Why it can't be taken yet — it stays listed with the reason. */
    disabled?: string;
}

/** The same actions, guards and reasons as the concern dialog's options bar, plus "Complete investigation". */
function headerActions(
    d: ConcernDetail,
    f: ConcernFacts,
    open: (action: ActionKey) => void,
    completeInvestigation: () => void,
): { actions: HeaderAction[]; primaryKey: string | null } {
    const { can, terminal, reported } = f;
    const pending = d.declassification?.pending_request ?? null;
    const canComplete = can.investigate && !terminal && !!f.openInvestigation;
    const actions = [
        can.update &&
            reported && {
                key: 'triage',
                label: 'Triage',
                icon: ClipboardCheck,
                run: () => open('triage'),
            },
        canComplete && {
            key: 'complete_investigation',
            label: 'Complete investigation',
            icon: CheckCircle2,
            run: completeInvestigation,
        },
        can.update &&
            !terminal &&
            !d.subject_informed && {
                key: 'informed',
                label: 'Mark informed',
                icon: BadgeCheck,
                run: () =>
                    router.post(
                        `/safeguarding/${d.id}/subject-informed`,
                        {},
                        { preserveScroll: true },
                    ),
            },
        can.update &&
            !terminal && {
                key: 'assign',
                label: 'Assign',
                icon: UserCog,
                run: () => open('assign'),
            },
        can.update &&
            !terminal && {
                key: 'risk',
                label: 'Add risk',
                icon: Activity,
                run: () => open('risk'),
            },
        can.report_external &&
            !terminal && {
                key: 'report',
                label: 'Log referral',
                icon: Landmark,
                run: () => open('report'),
                disabled: reported ? TRIAGE_FIRST : undefined,
            },
        can.investigate &&
            !terminal && {
                key: 'investigation',
                label: 'Start investigation',
                icon: Search,
                run: () => open('investigation'),
                disabled: reported ? TRIAGE_FIRST : undefined,
            },
        can.update &&
            !terminal && {
                key: 'action',
                label: 'Add action',
                icon: ListTodo,
                run: () => open('action'),
                disabled: reported ? TRIAGE_FIRST : undefined,
            },
        can.update &&
            !terminal &&
            !d.is_sensitive && {
                key: 'sensitive',
                label: 'Mark sensitive',
                icon: Lock,
                run: () =>
                    router.post(
                        `/safeguarding/${d.id}/sensitivity`,
                        { is_sensitive: true },
                        { preserveScroll: true },
                    ),
            },
        can.request_declassification &&
            !terminal &&
            d.is_sensitive &&
            !pending && {
                key: 'declassification',
                label: 'Remove restriction',
                icon: Lock,
                run: () => open('declassification'),
            },
        !terminal &&
            d.is_sensitive &&
            pending?.can_approve && {
                key: 'declassification_review',
                label: 'Review declassification',
                icon: Shield,
                run: () => open('declassification_review'),
            },
        !terminal &&
            d.is_sensitive &&
            pending &&
            !pending.can_approve && {
                key: 'declassification_pending',
                label: 'Declassification pending',
                icon: Clock,
                run: () => {},
                disabled: 'Awaiting independent review.',
            },
        can.update &&
            d.status === 'action_plan' && {
                key: 'monitoring',
                label: 'Move to monitoring',
                icon: Activity,
                run: () =>
                    router.patch(
                        `/safeguarding/${d.id}/status`,
                        { status: 'monitoring' },
                        { preserveScroll: true },
                    ),
            },
        can.update &&
            !terminal &&
            !reported && {
                key: 'close',
                label: 'Close',
                icon: CheckCircle2,
                run: () => open('close'),
            },
    ].filter(Boolean) as HeaderAction[];

    const primaryKey = reported
        ? 'triage'
        : canComplete
          ? 'complete_investigation'
          : f.referralMissing
            ? 'report'
            : d.status === 'action_plan' && !f.openActions.length
              ? 'monitoring'
              : d.status === 'monitoring'
                ? 'close'
                : null;
    return { actions, primaryKey };
}

/** Is this pane one the viewer may open right now? (A link never grants an action.) */
function paneAllowed(action: ActionKey, actions: HeaderAction[]): boolean {
    const found = actions.find((item) => item.key === action);
    return !!found && !found.disabled;
}

function PaneRenderer({ action, d, onDone }: { action: ActionKey; d: ConcernDetail; onDone: () => void }) {
    switch (action) {
        case 'triage':
            return <TriagePane d={d} onDone={onDone} />;
        case 'close':
            return <ClosePane d={d} onDone={onDone} />;
        case 'assign':
            return <AssignPane d={d} onDone={onDone} />;
        case 'investigation':
            return <InvestigationPane d={d} onDone={onDone} />;
        case 'report':
            return <ReportPane d={d} onDone={onDone} />;
        case 'risk':
            return <RiskPane d={d} onDone={onDone} />;
        case 'action':
            return <ActionItemPane d={d} onDone={onDone} />;
        case 'declassification':
            return <DeclassificationRequestPane d={d} onDone={onDone} />;
        case 'declassification_review':
            return <DeclassificationReviewPane d={d} onDone={onDone} />;
    }
}

/* ------------------------------------------------------------------ */
/*  Record                                                             */
/* ------------------------------------------------------------------ */

export function SafeguardingConcernRecord({
    detail: d,
    returnUrl,
    initialSection = 'overview',
    initialAction = null,
    onSectionChange,
}: {
    detail: ConcernDetail;
    returnUrl: string;
    initialSection?: SectionKey;
    initialAction?: ActionKey | null;
    /** Keep the open section in the address — on the client only (see the file note). */
    onSectionChange?: (section: SectionKey) => void;
}) {
    if (d.restricted) return <RestrictedConcern d={d} returnUrl={returnUrl} />;
    return (
        <ConcernWorkspace
            d={d}
            returnUrl={returnUrl}
            initialSection={initialSection}
            initialAction={initialAction}
            onSectionChange={onSectionChange}
        />
    );
}

function RestrictedConcern({ d, returnUrl }: { d: ConcernDetail; returnUrl: string }) {
    return (
        <PageLayout
            hero={
                <PageHeader
                    variant="profile"
                    wrapTitle
                    backHref={returnUrl}
                    icon={Lock}
                    title="Restricted concern"
                    titleChip={
                        <PageHeaderStatusChip variant="neutral" icon={Lock}>
                            Restricted
                        </PageHeaderStatusChip>
                    }
                    subline={`${d.reference_number} · Restricted · need-to-know`}
                />
            }
        >
            <Surface aria-label="Restricted · need-to-know">
                <div className="flex flex-col items-center px-6 py-16 text-center">
                    <Lock aria-hidden="true" className="mb-3 size-10 text-muted-foreground" />
                    <p className="text-section-title">Restricted · need-to-know</p>
                    <p className="text-subtle mt-1 max-w-md">
                        This is a sensitive safeguarding allegation. It is
                        visible only to the assigned lead, the reporter, and
                        staff cleared to view sensitive concerns.
                    </p>
                </div>
            </Surface>
        </PageLayout>
    );
}

function ConcernWorkspace({
    d,
    returnUrl,
    initialSection,
    initialAction,
    onSectionChange,
}: {
    d: ConcernDetail;
    returnUrl: string;
    initialSection: SectionKey;
    initialAction: ActionKey | null;
    onSectionChange?: (section: SectionKey) => void;
}) {
    const f = readConcern(d);
    const [section, setSection] = useState<SectionKey>(initialSection);
    // A pane asked for in the address opens only when the viewer may take it now.
    const [action, setAction] = useState<ActionKey | null>(() => {
        const allowed = headerActions(d, f, () => {}, () => {}).actions;
        return initialAction && paneAllowed(initialAction, allowed) ? initialAction : null;
    });
    const changeSection = (next: SectionKey) => {
        setAction(null);
        setSection(next);
        onSectionChange?.(next);
    };
    const openPane = (next: ActionKey) => setAction(next);
    // "Complete investigation" lands on the Investigation tab, where the outcome form is open.
    const { actions, primaryKey } = headerActions(d, f, openPane, () =>
        changeSection('investigation'),
    );
    const primary = actions.find((item) => item.key === primaryKey && !item.disabled) ?? null;
    const rest = actions.filter((item) => item !== primary);
    const glass = rest.filter((item) => !item.disabled).slice(0, 2);
    const overflow = rest.filter((item) => !glass.includes(item));

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
                        <PageHeaderGlassButton
                            icon={MoreHorizontal}
                            aria-label="More concern actions"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {overflow.map((item) => (
                            <DropdownMenuItem
                                key={item.key}
                                disabled={!!item.disabled}
                                onSelect={item.disabled ? undefined : item.run}
                                className={item.disabled ? 'items-start' : undefined}
                            >
                                <item.icon className="size-4" />
                                {item.disabled ? (
                                    <span className="grid">
                                        <span>{item.label}</span>
                                        <span className="text-xs text-muted-foreground">{item.disabled}</span>
                                    </span>
                                ) : (
                                    item.label
                                )}
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
        {
            value: SEVERITY_LABELS[d.severity] ?? titleCase(d.severity),
            label: 'Severity',
            tone: (SEVERITY_VARIANT[d.severity] ?? 'neutral') as PageHeaderFact['tone'],
        },
        ...(f.nextReview && !f.terminal
            ? [
                  {
                      value: dateWithYear(f.nextReview),
                      label: f.reviewOverdue
                          ? 'Risk review overdue'
                          : f.reviewToday
                            ? 'Risk review due today'
                            : 'Next risk review',
                      tone: f.reviewOverdue ? ('critical' as const) : ('warning' as const),
                  },
              ]
            : []),
        ...(f.overdueActions.length
            ? [
                  {
                      value: String(f.overdueActions.length),
                      label: f.overdueActions.length === 1 ? 'Overdue action' : 'Overdue actions',
                      tone: 'critical' as const,
                  },
              ]
            : []),
        ...(d.is_sensitive ? [{ value: 'Sensitive' }] : []),
    ];

    const counts: Partial<Record<SectionKey, number>> = {
        risk: d.risk_assessments?.length,
        investigation: d.investigations?.length,
        reports: d.external_reports?.length,
        actions: d.action_plans?.length,
        evidence: d.attachments?.length,
    };
    const rail = SECTIONS.map((item) => ({
        key: item.key,
        label: item.label,
        count: counts[item.key] || undefined,
        alert:
            (item.key === 'actions' && f.overdueActions.length > 0) ||
            (item.key === 'reports' && f.referralMissing) ||
            undefined,
    }));

    const sectionLabel = SECTIONS.find((item) => item.key === section)?.label ?? 'Overview';
    const main = action ? (
        <Surface aria-label="Current safeguarding action">
            <div className="p-5">
                <PaneRenderer action={action} d={d} onDone={() => setAction(null)} />
            </div>
        </Surface>
    ) : section === 'timeline' ? (
        <TimelineSection d={d} />
    ) : section === 'risk' ? (
        <RiskSection d={d} f={f} onAdd={paneAllowed('risk', actions) ? () => openPane('risk') : undefined} />
    ) : section === 'investigation' ? (
        <InvestigationSection
            d={d}
            f={f}
            onStart={paneAllowed('investigation', actions) ? () => openPane('investigation') : undefined}
        />
    ) : section === 'reports' ? (
        <ReportsSection d={d} f={f} onLog={paneAllowed('report', actions) ? () => openPane('report') : undefined} />
    ) : section === 'actions' ? (
        <ActionsSection d={d} f={f} onAdd={actions.find((item) => item.key === 'action')} />
    ) : section === 'evidence' ? (
        <EvidenceSection d={d} />
    ) : section === 'linked' ? (
        <LinkedSection d={d} />
    ) : (
        <OverviewSection
            d={d}
            f={f}
            onTriage={paneAllowed('triage', actions) ? () => openPane('triage') : undefined}
            onLinked={() => changeSection('linked')}
        />
    );

    return (
        <PageLayout
            hero={
                <PageHeader
                    variant="profile"
                    wrapTitle
                    backHref={returnUrl}
                    icon={d.severity === 'critical' ? ShieldAlert : Shield}
                    title={`${titleCase(d.concern_type)} — ${f.subjectName}`}
                    titleChip={
                        <PageHeaderStatusChip variant={statusVariant(d.status)}>
                            {d.status_label}
                        </PageHeaderStatusChip>
                    }
                    subline={[
                        d.reference_number,
                        d.location,
                        f.lead ? `Lead ${f.lead}` : 'No lead yet',
                        'viewing is logged',
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={action ? undefined : header}
                    facts={<PageHeaderFacts items={facts} />}
                    strip={
                        action ? undefined : (
                            <PageHeaderStages
                                steps={concernStages(d, f)}
                                next={concernNextStep(d, f)}
                                ariaLabel="Concern stage"
                            />
                        )
                    }
                    rail={
                        <PageHeaderRail
                            items={rail}
                            value={section}
                            onSelect={changeSection}
                            ariaLabel="Concern sections"
                        />
                    }
                />
            }
        >
            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                <div
                    id="safeguarding-concern-panel"
                    role="tabpanel"
                    aria-label={action ? 'Current action' : sectionLabel}
                    className="grid min-w-0 gap-5"
                >
                    {main}
                </div>
                <aside className="min-w-0" aria-label="People">
                    <SideColumn>
                        <ConcernPeople d={d} f={f} />
                    </SideColumn>
                </aside>
            </div>
        </PageLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Side: people                                                       */
/* ------------------------------------------------------------------ */

/** Everyone the concern names, one card each; one person in two roles gets one card. */
function ConcernPeople({ d, f }: { d: ConcernDetail; f: ConcernFacts }) {
    const subject = d.people?.subject ?? null;
    const cards: PersonCardItem[] = [];
    const add = (name: string | null | undefined, role: string) => {
        if (!name) return;
        const same = cards.find((card) => card.name === name);
        if (same) same.sub = `${same.sub} · ${role}`;
        else cards.push({ name, sub: role });
    };
    cards.push({
        name: f.subjectName,
        sub: ['Subject', d.location].filter(Boolean).join(' · '),
        flag: d.current_risk_level ? (
            <StatusBadge variant={riskVariant(d.current_risk_level)} size="sm">
                {titleCase(d.current_risk_level)} risk
            </StatusBadge>
        ) : undefined,
        href: subject?.href ?? undefined,
    });
    add(f.lead, 'Lead');
    add(d.people?.reported_by, 'Reported it');
    add(d.people?.alleged_perpetrator, 'Alleged person');
    return <PeopleCards title="People" sub="Viewing is logged" cols={1} items={cards} />;
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({
    d,
    f,
    onTriage,
    onLinked,
}: {
    d: ConcernDetail;
    f: ConcernFacts;
    onTriage?: () => void;
    onLinked: () => void;
}) {
    const reports = byTime(d.external_reports ?? [], (r) => r.reported_at);
    const authorities = [...new Set(reports.map((r) => r.authority_name))].join(' and ');
    const stand: { tone: SgTone; text: ReactNode }[] = [];
    if (d.people?.subject) {
        stand.push(
            d.subject_informed
                ? {
                      tone: 'success',
                      text: (
                          <>
                              <b>{f.firstName} has been told</b>
                              {d.subject_informed_at ? ` · ${dateTimeWithYear(d.subject_informed_at)}` : ''}
                          </>
                      ),
                  }
                : {
                      tone: 'warning',
                      text: (
                          <>
                              <b>{f.firstName} hasn’t been told yet</b> · mark them informed once they have been
                          </>
                      ),
                  },
        );
    }
    if (reports.length) {
        stand.push({
            tone: f.awaitingReplies.length ? 'warning' : 'success',
            text: (
                <>
                    <b>Referred to {authorities}</b> · {dateWithYear(reports[0].reported_at)};{' '}
                    {f.awaitingReplies.length ? 'not acknowledged yet' : 'acknowledged'}
                </>
            ),
        });
    } else if (f.referralMissing) {
        stand.push({
            tone: 'critical',
            text: (
                <>
                    <b>External referral indicated</b> · Referral indicated at triage — no report logged yet.
                </>
            ),
        });
    } else {
        stand.push({ tone: 'none', text: 'External referral not indicated' });
    }
    for (const alert of f.activeAlerts) {
        stand.push({
            tone: 'success',
            text: (
                <>
                    <b>Protective alert active</b> · {titleCase(alert.alert_type)} — {alert.summary}
                </>
            ),
        });
    }
    if (d.current_risk_level) {
        stand.push({
            tone: toneOf(riskVariant(d.current_risk_level)),
            text: (
                <>
                    <b>Current risk {titleCase(d.current_risk_level).toLowerCase()}</b>
                    {f.nextReview ? ` · next review ${dateWithYear(f.nextReview)}` : ''}
                </>
            ),
        });
    }
    if (!f.lead && !f.terminal) {
        stand.push({
            tone: 'warning',
            text: (
                <>
                    <b>No lead yet</b> · assign someone to lead the concern
                </>
            ),
        });
    }

    return (
        <>
            {d.status === 'reported' ? (
                <Note tone="warning" icon={ClipboardCheck}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <b>Awaiting triage.</b> Triage decides the path — investigate, refer
                            externally, or no further action.
                        </span>
                        {onTriage ? (
                            <Button size="sm" onClick={onTriage}>
                                <ClipboardCheck className="size-4" /> Triage now
                            </Button>
                        ) : null}
                    </div>
                </Note>
            ) : null}
            {f.activeAlerts.length ? (
                <Note tone="critical" icon={RadioTower}>
                    <b>Active protective alert</b> on the subject — see{' '}
                    {/* eslint-disable-next-line no-restricted-syntax -- an inline text link inside the alert note that switches to the Linked records section. */}
                    <button
                        type="button"
                        onClick={onLinked}
                        className="rounded-sm font-semibold underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Linked records
                    </button>
                    .
                </Note>
            ) : null}
            <Story
                title="What was raised"
                sub={[
                    d.people?.reported_by ? `Raised by ${d.people.reported_by}` : null,
                    d.reported_at ? dateTimeWithYear(d.reported_at) : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                facts={[
                    d.occurred_at
                        ? {
                              icon: Clock,
                              text: (
                                  <>
                                      <b>{formatDate(d.occurred_at)}</b>, {formatTime(d.occurred_at)}
                                  </>
                              ),
                          }
                        : null,
                    d.location ? { icon: MapPin, text: d.location } : null,
                    { icon: Tag, text: concernKind(d.concern_type, d.abuse_category) },
                ].filter((fact) => fact !== null)}
                paras={[
                    {
                        h: 'What was raised',
                        t: <span className="whitespace-pre-wrap">{d.description || '—'}</span>,
                    },
                    {
                        h: 'Immediate response',
                        t: <span className="whitespace-pre-wrap">{d.immediate_actions || '—'}</span>,
                    },
                ]}
            />
            <KnowList title="Where things stand" items={stand} />
            {d.closure ? (
                <FactRows
                    title="Closure"
                    rows={[
                        ['Closed by', d.closure.by ?? '—'],
                        ['Summary', d.closure.summary ?? '—'],
                        ['Lessons learned', d.closure.lessons ?? '—'],
                    ]}
                />
            ) : null}
        </>
    );
}

function TimelineSection({ d }: { d: ConcernDetail }) {
    type Event = { at: string; tone: SgTone; title: string; who?: string | null };
    const events: Event[] = [];
    if (d.reported_at)
        events.push({ at: d.reported_at, tone: 'warning', title: 'Concern raised — waiting for triage', who: d.people?.reported_by });
    if (d.triage?.at)
        events.push({
            at: d.triage.at,
            tone: 'info',
            title: ['Triaged', [d.triage.substantiation, d.triage.decision].filter(Boolean).map((v) => titleCase(v).toLowerCase()).join(' · ')]
                .filter(Boolean)
                .join(' — '),
            who: d.triage.by,
        });
    for (const report of d.external_reports ?? []) {
        if (report.reported_at)
            events.push({ at: report.reported_at, tone: 'critical', title: `Referred to ${report.authority_name}` });
        if (report.acknowledged_at)
            events.push({ at: report.acknowledged_at, tone: 'success', title: `${report.authority_name} acknowledged` });
    }
    for (const risk of d.risk_assessments ?? []) {
        if (risk.assessed_at)
            events.push({
                at: risk.assessed_at,
                tone: 'info',
                title: `Risk assessment added${risk.overall_risk_level ? ` — ${titleCase(risk.overall_risk_level).toLowerCase()} overall` : ''}`,
                who: risk.assessor,
            });
    }
    for (const investigation of d.investigations ?? []) {
        if (investigation.started_at)
            events.push({ at: investigation.started_at, tone: 'info', title: 'Investigation opened', who: investigation.lead });
        if (investigation.completed_at)
            events.push({
                at: investigation.completed_at,
                tone: 'success',
                title: `Investigation completed${investigation.outcome ? ` · ${titleCase(investigation.outcome).toLowerCase()}` : ''}`,
                who: investigation.lead,
            });
    }
    for (const item of d.action_plans ?? []) {
        if (item.completed_at)
            events.push({ at: item.completed_at, tone: 'success', title: `Action completed — ${item.description}` });
    }
    if (d.closure?.at) events.push({ at: d.closure.at, tone: 'success', title: 'Closed', who: d.closure.by });

    const items: LogItem[] = byTime(events, (event) => event.at).map((event, n) => ({
        key: n,
        time: formatTime(event.at),
        tone: event.tone,
        title: event.title,
        sub: [dateWithYear(event.at), event.who].filter(Boolean).join(' · '),
    }));
    const access = d.access_log ?? [];

    return (
        <>
            {items.length ? (
                <Log title="Timeline" sub="Every step is kept · viewing is logged" items={items} limit={items.length} />
            ) : (
                <QuietLine title="Timeline" text="No timeline events yet." />
            )}
            {access.length ? (
                <Log
                    title="Accessed by · need-to-know"
                    items={access.map((entry, n) => ({
                        key: n,
                        time: entry.at ? formatTime(entry.at) : '',
                        tone: 'none',
                        title: (
                            <span className="inline-flex items-center gap-1.5">
                                <UserIcon aria-hidden="true" className="size-3.5" /> {entry.by}
                            </span>
                        ),
                        sub: entry.at ? dateWithYear(entry.at) : undefined,
                    }))}
                    limit={access.length}
                />
            ) : null}
        </>
    );
}

function riskFlag(level: string | null | undefined): ReactNode {
    return (
        <StatusBadge variant={riskVariant(level)} size="sm">
            {level ? titleCase(level) : 'Not recorded'}
        </StatusBadge>
    );
}

function RiskSection({ d, f, onAdd }: { d: ConcernDetail; f: ConcernFacts; onAdd?: () => void }) {
    const risk = f.latestRisk;
    if (!risk) {
        return (
            <QuietLine
                title="Risk assessment"
                text="No risk assessment recorded yet."
                action={onAdd ? { label: 'Add risk', onClick: onAdd } : null}
            />
        );
    }
    const measures = (risk.protective_measures ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
    const earlier = byTime(d.risk_assessments ?? [], (r) => r.assessed_at)
        .filter((r) => r.id !== risk.id)
        .reverse();
    return (
        <>
            <PlaceCards
                title="Risk assessment"
                sub={[
                    risk.assessed_at ? dateWithYear(risk.assessed_at) : null,
                    risk.assessor,
                    risk.next_review_date ? `next review ${dateWithYear(risk.next_review_date)}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                action={onAdd ? { label: 'Add risk', onClick: onAdd } : null}
                cols={4}
                items={[
                    { name: 'Overall', flag: riskFlag(risk.overall_risk_level) },
                    { name: 'Risk from others', flag: riskFlag(risk.risk_from_others) },
                    { name: 'Risk to self', flag: riskFlag(risk.risk_to_self) },
                    { name: 'Risk to others', flag: riskFlag(risk.risk_to_others) },
                ]}
            />
            {measures.length ? (
                <KnowList
                    title="Protective measures"
                    sub={risk.mental_capacity ? `Mental capacity: ${titleCase(risk.mental_capacity).toLowerCase()}` : undefined}
                    items={measures.map((text) => ({ tone: 'success', text }))}
                />
            ) : (
                <QuietLine
                    title="Protective measures"
                    sub={risk.mental_capacity ? `Mental capacity: ${titleCase(risk.mental_capacity).toLowerCase()}` : undefined}
                    text="No protective measures recorded."
                />
            )}
            {earlier.length ? (
                <Log
                    title="Earlier assessments"
                    items={earlier.map((r) => ({
                        key: r.id,
                        time: r.assessed_at ? formatDate(r.assessed_at) : '',
                        tone: toneOf(riskVariant(r.overall_risk_level)),
                        title: r.overall_risk_level ? `${titleCase(r.overall_risk_level)} overall` : 'Overall risk not recorded',
                        sub: r.assessor ?? undefined,
                    }))}
                    limit={earlier.length}
                />
            ) : null}
        </>
    );
}

function InvestigationSection({
    d,
    f,
    onStart,
}: {
    d: ConcernDetail;
    f: ConcernFacts;
    onStart?: () => void;
}) {
    const canInvestigate = !!d.can?.investigate;
    const investigations = byTime(d.investigations ?? [], (i) => i.started_at).reverse();
    return (
        <>
            {d.hs_event ? (
                <RowList
                    title="Safety review"
                    items={[
                        {
                            key: d.hs_event.id,
                            leading: <Shield aria-hidden="true" className="size-4" />,
                            title: d.hs_event.reference_number,
                            sub: `Safety review · ${titleCase(d.hs_event.status)}`,
                            href: `/health-safety/events/${d.hs_event.id}`,
                        },
                    ]}
                />
            ) : null}
            {investigations.length ? (
                investigations.map((i) => {
                    const open = isOpenInvestigation(i.status);
                    const findings: [ReactNode, ReactNode][] = [
                        ['Outcome', i.outcome ? titleCase(i.outcome) : null],
                        ['Findings', i.findings],
                        ['Recommendations', i.recommendations],
                    ].filter(([, value]) => !!value) as [ReactNode, ReactNode][];
                    return (
                        <Fragment key={i.id}>
                            <Journey
                                title="Investigation"
                                sub={[titleCase(i.type), i.lead ? `lead ${i.lead}` : 'no lead recorded'].join(' · ')}
                                steps={[
                                    { label: 'Started', meta: shortDay(i.started_at), state: 'done' },
                                    {
                                        label: open ? 'Under way' : titleCase(i.status),
                                        meta: i.lead ?? undefined,
                                        state: open ? 'now' : 'done',
                                    },
                                    {
                                        label: 'Completed',
                                        meta: i.completed_at ? shortDay(i.completed_at) : 'Outcome moves it to the action plan',
                                        state: i.completed_at ? 'done' : 'todo',
                                    },
                                ]}
                                caption={
                                    findings.length ? (
                                        <dl className="sg-kv">
                                            {findings.map(([label, value], n) => (
                                                <Fragment key={n}>
                                                    <dt>{label}</dt>
                                                    <dd className="whitespace-pre-wrap">{value}</dd>
                                                </Fragment>
                                            ))}
                                        </dl>
                                    ) : undefined
                                }
                            />
                            {canInvestigate && open && !f.terminal ? (
                                <Surface aria-label="Complete investigation">
                                    <div className="px-[18px] py-4">
                                        <InvestigationCompleteForm
                                            concernId={d.id}
                                            investigationId={i.id}
                                            startOpen
                                        />
                                    </div>
                                </Surface>
                            ) : null}
                        </Fragment>
                    );
                })
            ) : (
                <QuietLine
                    title="Investigation"
                    text="No investigation opened. Completing an investigation auto-advances the concern."
                    action={onStart ? { label: 'Start investigation', onClick: onStart } : null}
                />
            )}
        </>
    );
}

function ReportsSection({ d, f, onLog }: { d: ConcernDetail; f: ConcernFacts; onLog?: () => void }) {
    const reports = byTime(d.external_reports ?? [], (r) => r.reported_at);
    const canReport = !!d.can?.report_external;
    if (!reports.length) {
        return (
            <QuietLine
                title="External reports"
                text={
                    f.referralMissing
                        ? 'Referral indicated at triage — no report logged yet.'
                        : 'No external reports logged.'
                }
                action={onLog ? { label: 'Log referral', onClick: onLog } : null}
            />
        );
    }
    return (
        <>
            {reports.map((r, n) => (
                <Fragment key={r.id}>
                    <Log
                        title={r.authority_name}
                        sub={r.summary ?? undefined}
                        action={
                            n === 0 && onLog
                                ? { label: 'Log another referral', onClick: onLog }
                                : null
                        }
                        items={[
                            {
                                key: 'reported',
                                time: r.reported_at ? formatDate(r.reported_at) : '',
                                tone: 'info',
                                title: `Reported by ${titleCase(r.method).toLowerCase()}`,
                                sub: r.reported_at ? dateTimeWithYear(r.reported_at) : undefined,
                            },
                            r.ack_received
                                ? {
                                      key: 'reply',
                                      time: r.acknowledged_at ? formatDate(r.acknowledged_at) : '',
                                      tone: 'success',
                                      title: `Acknowledged${r.ack_reference ? ` · ${r.ack_reference}` : ''}`,
                                      sub: `What the authority did: ${r.authority_action || '—'}`,
                                  }
                                : {
                                      key: 'reply',
                                      time: 'Waiting',
                                      tone: 'warning',
                                      title: 'Not acknowledged yet',
                                      sub: `What the authority did: ${r.authority_action || '—'}`,
                                  },
                        ]}
                    />
                    {canReport && !r.ack_received ? (
                        <Surface aria-label={`Record ${r.authority_name}’s reply`}>
                            <div className="px-[18px] py-4">
                                <ReportAckForm concernId={d.id} reportId={r.id} startOpen />
                            </div>
                        </Surface>
                    ) : null}
                </Fragment>
            ))}
        </>
    );
}

type ActionPlan = NonNullable<ConcernDetail['action_plans']>[number];

function ActionsSection({ d, f, onAdd }: { d: ConcernDetail; f: ConcernFacts; onAdd?: HeaderAction }) {
    const rows = d.action_plans ?? [];
    const canEdit = !!d.can?.update;
    const complete = (id: number) =>
        router.post(`/safeguarding/${d.id}/action-plans/${id}/complete`, {}, { preserveScroll: true });
    const columns: RecordTableColumn<ActionPlan>[] = [
        {
            key: 'action',
            label: 'Action',
            className: 'min-w-[16rem]',
            cell: (a) => <RecordIdentity title={a.description} />,
        },
        { key: 'type', label: 'Type', nowrap: true, cell: (a) => titleCase(a.type) },
        {
            key: 'owner',
            label: 'Owner',
            nowrap: true,
            cell: (a) =>
                a.assigned_to ? <RecordPerson name={a.assigned_to} /> : <span className="text-muted-foreground">Unassigned</span>,
        },
        {
            key: 'due',
            label: 'Due',
            nowrap: true,
            cell: (a) => (a.due_date ? dateWithYear(a.due_date) : <span className="text-muted-foreground">—</span>),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (a) =>
                a.completed_at || a.status === 'completed' ? (
                    <StatusBadge variant="success">Completed</StatusBadge>
                ) : a.overdue ? (
                    <StatusBadge variant="critical">Overdue</StatusBadge>
                ) : a.status === 'cancelled' ? (
                    <StatusBadge variant="neutral">Cancelled</StatusBadge>
                ) : (
                    <StatusBadge variant="info">{titleCase(a.status)}</StatusBadge>
                ),
        },
    ];
    const actionsFor = (a: ActionPlan): MenuItem[] =>
        compactMenu([
            canEdit &&
                isOpenAction(a.status) &&
                !f.terminal && { label: 'Complete', icon: CheckCircle2, onClick: () => complete(a.id) },
        ]);
    return (
        <RecordTable
            title="Action plan"
            sub="Protective actions with an owner and a due date"
            actions={
                onAdd ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onAdd.disabled ? undefined : onAdd.run}
                        disabled={!!onAdd.disabled}
                        title={onAdd.disabled}
                    >
                        <ListTodo className="size-4" /> Add action
                    </Button>
                ) : undefined
            }
            columns={columns}
            rows={rows}
            rowKey={(a) => a.id}
            actionsFor={canEdit && !f.terminal ? actionsFor : undefined}
            menuTitle={(a) => a.description}
            menuIcon={ListTodo}
            empty={
                <p className="sg-quiet px-4 py-5">
                    <span>No action-plan items yet.</span>
                </p>
            }
        />
    );
}

type Attachment = NonNullable<ConcernDetail['attachments']>[number];

function EvidenceSection({ d }: { d: ConcernDetail }) {
    const files = d.attachments ?? [];
    const canEdit = !!d.can?.update;
    const [fileToRemove, setFileToRemove] = useState<Attachment | null>(null);
    const [removingFile, setRemovingFile] = useState(false);
    const remove = () => {
        if (!fileToRemove) return;
        router.delete(`/safeguarding/${d.id}/attachments/${fileToRemove.id}`, {
            preserveScroll: true,
            onStart: () => setRemovingFile(true),
            onFinish: () => {
                setRemovingFile(false);
                setFileToRemove(null);
            },
        });
    };
    const columns: RecordTableColumn<Attachment>[] = [
        {
            key: 'file',
            label: 'File',
            className: 'min-w-[16rem]',
            cell: (a) =>
                a.locked ? (
                    <span className="inline-flex items-center gap-2 text-muted-foreground">
                        <Lock aria-hidden="true" className="size-4" />
                        Sensitive evidence · restricted (need-to-know)
                    </span>
                ) : (
                    <RecordIdentity
                        leading={
                            a.is_image && a.download_url ? (
                                <img src={a.download_url} alt="" loading="lazy" className="size-[30px] rounded-[8px] object-cover" />
                            ) : (
                                <FileText aria-hidden="true" className="size-4" />
                            )
                        }
                        title={
                            a.download_url ? (
                                <a
                                    href={a.download_url}
                                    onClick={(event) => event.stopPropagation()}
                                    className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {a.name}
                                </a>
                            ) : (
                                a.name
                            )
                        }
                        sub={[a.size ? formatFileSize(a.size) : null, a.notes].filter(Boolean).join(' · ') || undefined}
                    />
                ),
        },
        {
            key: 'by',
            label: 'Added by',
            nowrap: true,
            cell: (a) => (a.uploaded_by ? a.uploaded_by : <span className="text-muted-foreground">—</span>),
        },
        {
            key: 'at',
            label: 'Added',
            nowrap: true,
            cell: (a) =>
                a.created_at ? (
                    <RecordIdentity title={dateWithYear(a.created_at)} sub={formatTime(a.created_at)} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'access',
            label: 'Access',
            nowrap: true,
            cell: (a) =>
                a.locked ? (
                    <StatusBadge variant="warning">Restricted</StatusBadge>
                ) : a.is_sensitive ? (
                    <StatusBadge variant="warning">Sensitive</StatusBadge>
                ) : (
                    <span className="text-muted-foreground">Not marked sensitive</span>
                ),
        },
    ];
    const actionsFor = (a: Attachment): MenuItem[] =>
        a.locked
            ? []
            : compactMenu([
                  a.download_url && {
                      label: 'Download',
                      icon: Download,
                      onClick: () => window.location.assign(a.download_url!),
                  },
                  canEdit && { separator: true },
                  canEdit && {
                      label: 'Remove file',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setFileToRemove(a),
                  },
              ]);
    return (
        <>
            {canEdit ? (
                <Surface title="Add evidence">
                    <div className="px-[18px] pt-2 pb-4">
                        <AttachmentUploader
                            endpoint={`/safeguarding/${d.id}/attachments`}
                            noteField="notes"
                            sensitive={{
                                field: 'is_sensitive',
                                label: 'Sensitive — restrict to cleared staff',
                            }}
                            hint="PDF, Word, images — up to 10 MB each"
                        />
                    </div>
                </Surface>
            ) : null}
            <RecordTable
                title="Evidence"
                columns={columns}
                rows={files}
                rowKey={(a) => a.id}
                actionsFor={actionsFor}
                menuTitle={(a) => a.name ?? 'Evidence file'}
                menuIcon={FileText}
                empty={
                    <p className="sg-quiet px-4 py-5">
                        <span>No evidence attached.</span>
                    </p>
                }
            />
            <ConfirmDialog
                open={fileToRemove !== null}
                onClose={() => {
                    if (!removingFile) setFileToRemove(null);
                }}
                onConfirm={remove}
                title="Remove this file?"
                description={
                    <>
                        <span className="font-medium text-foreground">{fileToRemove?.name ?? 'This evidence file'}</span>{' '}
                        will be permanently deleted from this concern. This cannot be undone.
                    </>
                }
                confirmText="Remove file"
                variant="destructive"
                processing={removingFile}
            />
        </>
    );
}

function LinkedSection({ d }: { d: ConcernDetail }) {
    const subject = d.people?.subject ?? null;
    const links: RowItem[] = [
        d.related_incident_id
            ? {
                  key: 'incident',
                  leading: <AlertTriangle aria-hidden="true" className="size-4" />,
                  title: 'Incident it came from',
                  sub: d.related_incident_ref ?? `INC-${d.related_incident_id}`,
                  href: incidentWorkspaceHref(d.related_incident_id),
              }
            : null,
        d.control_room_alert_id
            ? {
                  key: 'alert',
                  leading: <RadioTower aria-hidden="true" className="size-4" />,
                  title: 'Control Room alert',
                  sub: 'Active alert',
                  href: `/control-room/alerts/${d.control_room_alert_id}`,
              }
            : null,
        d.hs_event
            ? {
                  key: 'review',
                  leading: <Shield aria-hidden="true" className="size-4" />,
                  title: 'Safety review',
                  sub: `${d.hs_event.reference_number} · ${titleCase(d.hs_event.status)}`,
                  href: `/health-safety/events/${d.hs_event.id}`,
              }
            : null,
        subject?.href
            ? {
                  key: 'subject',
                  leading: <UserIcon aria-hidden="true" className="size-4" />,
                  title: 'Client record',
                  sub: subject.name,
                  href: subject.href,
              }
            : null,
    ].filter((item) => item !== null);
    const alerts = d.alerts ?? [];
    return (
        <>
            <RowList title="Linked records" items={links} empty="No linked records." />
            {alerts.length ? (
                <RowList
                    title="Protective alerts"
                    items={alerts.map((alert) => ({
                        key: alert.id,
                        title: `${titleCase(alert.alert_type)} · ${alert.summary}`,
                        badge: (
                            <StatusBadge variant={alert.active ? 'critical' : 'neutral'}>
                                {alert.active ? 'Active' : 'Inactive'}
                            </StatusBadge>
                        ),
                    }))}
                />
            ) : null}
        </>
    );
}
