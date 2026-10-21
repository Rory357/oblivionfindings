/**
 * The referral profile Overview — the approved mockup's two-column
 * CaseOverview (docs/referrals/mockup/case-design.jsx) plus its submitted
 * intake review (record-workflows.jsx IntakeReview).
 *
 * Main column: the person brief, the handover readiness grid and the next
 * few follow-ups. Side column: the next step (action, owner, due), funding
 * at a glance and a quiet case-management area. Every figure comes from the
 * referral payload or the server's `profile` summary; restricted sections
 * say so instead of showing a number.
 */
import { PersonDisc, ProgressValue } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    ArrowRight,
    ChevronDown,
    ClipboardList,
    Clock,
    FileText,
    Phone,
    Settings2,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { CaseAction } from './case-action-dialog';
import { DuplicateClosureHistoryPanel } from './duplicate-closure';
import { DEFAULT_PRIORITY, ReferralPriorityBadge } from './priority-badge';
import { stageLabel } from './stage-badge';
import {
    label,
    type Options,
    type Permissions,
    type ReferralFundingTotal,
    type ReferralProfileDetail,
    type ReferralReadinessItem,
    type WorkItem,
} from './types';
import { priorityBlocked } from './workspace/referral-actions';

/** Whole-dollar money, as the Overview shows it ("$1,960"). */
export function formatMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('en-NZ', {
            style: 'currency',
            currency,
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(Number(amount));
    } catch {
        return `${currency} ${amount}`;
    }
}

export const perPeriod = (period: string) =>
    period === 'total' ? 'in total' : `/ ${period}`;

/** The first (weekly NZD where present) funding position, or null when uncosted. */
export function fundingHeadline(
    referral: ReferralProfileDetail,
): ReferralFundingTotal | null {
    return referral.profile?.funding?.totals[0] ?? null;
}

export function coveragePercent(total: ReferralFundingTotal): number | null {
    const proposed = Number(total.proposed);
    if (!proposed) return null;
    return Math.min(100, (Number(total.covered) / proposed) * 100);
}

export const READINESS_STATE: Record<
    ReferralReadinessItem['state'],
    { text: string; variant: StatusVariant }
> = {
    confirmed: { text: 'Confirmed', variant: 'success' },
    to_review: { text: 'To review', variant: 'neutral' },
    attention: { text: 'Needs attention', variant: 'warning' },
};

export const isOverdue = (value: string | null) =>
    !!value && new Date(value).getTime() < Date.now();

function Eyebrow({ children }: { children: ReactNode }) {
    return (
        <p className="text-caption font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </p>
    );
}

function SectionHeading({
    eyebrow,
    title,
    action,
}: {
    eyebrow: string;
    title: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                <Eyebrow>{eyebrow}</Eyebrow>
                <h2 className="text-section-title mt-0.5">{title}</h2>
            </div>
            {action}
        </div>
    );
}

function Fact({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-caption text-muted-foreground">{title}</dt>
            <dd className="mt-0.5 text-sm font-medium break-words">
                {children || (
                    <span className="font-normal text-muted-foreground">
                        Not recorded
                    </span>
                )}
            </dd>
        </div>
    );
}

function LinkButton({
    onClick,
    children,
}: {
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            variant="link"
            className="h-auto self-start px-0"
            onClick={onClick}
        >
            {children}
            <ArrowRight className="size-4" />
        </Button>
    );
}

export type OverviewHandlers = {
    onTab: (tab: string) => void;
    onAction: (action: CaseAction, extra?: { closeReason?: string }) => void;
    onCorrect: () => void;
    onReview: (mode: 'authority' | 'assessment') => void;
    onViewWork: (work: WorkItem) => void;
};

export function ReferralCaseOverview({
    referral,
    permissions,
    options,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    options: Options;
    handlers: OverviewHandlers;
}) {
    return (
        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(300px,360px)]">
            <div className="flex min-w-0 flex-col gap-5">
                <PersonBrief
                    referral={referral}
                    permissions={permissions}
                    options={options}
                    handlers={handlers}
                />
                <ReadinessSection
                    referral={referral}
                    permissions={permissions}
                    options={options}
                    handlers={handlers}
                />
                <UpcomingFollowUps
                    referral={referral}
                    permissions={permissions}
                    handlers={handlers}
                />
            </div>
            <aside
                aria-label="Next step and case management"
                className="flex min-w-0 flex-col gap-5"
            >
                <NextStepCard
                    referral={referral}
                    permissions={permissions}
                    handlers={handlers}
                />
                <FundingSnapshot
                    referral={referral}
                    permissions={permissions}
                    handlers={handlers}
                />
                <CaseManagement
                    referral={referral}
                    permissions={permissions}
                    handlers={handlers}
                />
            </aside>
        </div>
    );
}

function PersonBrief({
    referral,
    permissions,
    options,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    options: Options;
    handlers: OverviewHandlers;
}) {
    const open = referral.stage !== 'closed';
    const known = !referral.general_enquiry;
    const callName = known
        ? referral.preferred_name ||
          referral.identity.first_name ||
          referral.name
        : 'the person';
    const service =
        options.services.find((item) => item.id === referral.requested_service)
            ?.label ?? label(referral.requested_service);
    const choice = referral.profile?.readiness?.find(
        (item) => item.key === 'choice',
    );
    const authority = referral.profile?.readiness?.find(
        (item) => item.key === 'authority',
    );
    const contact = referral.contact;
    const contactLine = [
        contact.name,
        contact.phone || contact.email || 'Contact details to confirm',
    ]
        .filter(Boolean)
        .join(' · ');
    const contactNotes = [
        contact.channel && `Prefers ${label(contact.channel).toLowerCase()}`,
        contact.safe_time && `Safe time: ${contact.safe_time}`,
        contact.leave_message
            ? 'A message may be left'
            : 'Do not leave messages',
    ]
        .filter(Boolean)
        .join(' · ');
    return (
        <Card id="referral-person-brief" className="scroll-mt-5">
            <CardContent className="flex flex-col gap-5">
                <div className="flex flex-wrap items-start gap-3">
                    <PersonDisc name={known ? referral.name : null} size={40} />
                    <div className="min-w-0 flex-1">
                        <Eyebrow>
                            Getting to know {known ? callName : 'the person'}
                        </Eyebrow>
                        <h2 className="text-section-title mt-0.5">
                            What matters to {callName}
                        </h2>
                    </div>
                    {permissions.edit && open && (
                        <Button variant="ghost" onClick={handlers.onCorrect}>
                            {referral.general_enquiry
                                ? 'Complete intake'
                                : 'Edit details'}
                        </Button>
                    )}
                </div>
                {referral.intake.goals ? (
                    <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                        {referral.intake.goals}
                    </p>
                ) : (
                    <p className="text-subtle">
                        No goals were shared at intake. Ask about them at the
                        first conversation.
                    </p>
                )}
                <dl className="grid gap-4 sm:grid-cols-2">
                    <Fact title="Requested support">{service}</Fact>
                    <Fact title="Receiving site">
                        {referral.site_name ?? 'Central intake (no site yet)'}
                    </Fact>
                    <Fact title="Referral source">
                        {[
                            label(referral.source),
                            referral.intake.relationship &&
                                label(referral.intake.relationship),
                            referral.intake.organisation,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </Fact>
                    {choice ? (
                        <Fact title="Person’s choice">
                            {choice.state === 'confirmed'
                                ? 'Confirmed'
                                : choice.state === 'attention'
                                  ? choice.detail
                                  : 'A conversation to have'}
                        </Fact>
                    ) : (
                        <Fact title="Timeframe">
                            {referral.intake.timeframe &&
                                label(referral.intake.timeframe)}
                        </Fact>
                    )}
                </dl>
                <div className="flex flex-wrap items-center gap-3 rounded-lg bg-muted p-3">
                    <Phone
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold break-words">
                            {contactLine}
                        </p>
                        <p className="text-subtle">
                            {contactNotes}
                            {' · '}
                            {referral.first_human_contact_at
                                ? `First conversation ${formatDateTimeLong(referral.first_human_contact_at)}`
                                : 'No conversation recorded yet'}
                        </p>
                    </div>
                    {permissions.contact && open && (
                        <Button
                            variant="outline"
                            onClick={() => handlers.onAction('contact')}
                        >
                            Log contact
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <ShieldCheck
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    {authority ? (
                        <>
                            <span>
                                Authority:{' '}
                                <strong className="font-semibold">
                                    {authority.detail}
                                </strong>
                            </span>
                            <LinkButton
                                onClick={() => handlers.onTab('assessment')}
                            >
                                Contacts & authority
                            </LinkButton>
                        </>
                    ) : (
                        <span>
                            Permission for follow-up:{' '}
                            <strong className="font-semibold">
                                {referral.contact_permission
                                    ? 'Recorded'
                                    : 'Not recorded'}
                            </strong>
                        </span>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Ring state glyph (signature proposal, "rings tell the state"): the shape
 * carries the state without colour — closed with a centre = confirmed,
 * hollow = still to review, solid with a halo = needs attention. The
 * StatusBadge beside it keeps the words.
 */
function StateRing({ state }: { state: ReferralReadinessItem['state'] }) {
    return (
        <span
            aria-hidden
            className={cn(
                'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-2',
                state === 'confirmed' && 'border-primary',
                state === 'to_review' && 'border-muted-foreground bg-card',
                state === 'attention' &&
                    'border-status-warning bg-status-warning ring-4 ring-status-warning/20',
            )}
        >
            {state === 'confirmed' ? (
                <span className="size-1.5 rounded-full bg-primary" />
            ) : null}
        </span>
    );
}

/** The referral's stages in order; on hold and closed sit outside the line. */
const PATHWAY_STAGES = [
    'new',
    'triage',
    'assessment',
    'funding_assistance',
    'ready_for_handover',
    'converted',
];

/**
 * The Journey pathway (signature proposal): the referral's position through
 * its configured stages, taken from the stage itself — done = filled dot,
 * now = the ring, next = hollow. "Support started" joins the line only when
 * the viewer may see the service start, so an unknown start is never shown
 * as not started.
 */
function ReferralPathway({
    referral,
    options,
    showStart,
}: {
    referral: ReferralProfileDetail;
    options: Options;
    showStart: boolean;
}) {
    const commenced = !!referral.profile?.service_start?.commenced;
    const steps = [
        ...PATHWAY_STAGES.map((key) => ({
            key,
            label: stageLabel(key, options.stages),
        })),
        ...(showStart ? [{ key: 'started', label: 'Support started' }] : []),
    ];
    const current =
        referral.stage === 'converted' && showStart && commenced
            ? steps.length
            : PATHWAY_STAGES.indexOf(referral.stage);
    if (current < 0) {
        return (
            <p className="text-subtle">
                {referral.stage === 'closed'
                    ? `Closed · ${referral.closure_reason_label ?? label(referral.closure_reason ?? 'other')}.`
                    : `${stageLabel(referral.stage, options.stages)}${referral.hold_reason ? ` · ${referral.hold_reason_label ?? label(referral.hold_reason)}` : ''}.`}{' '}
                The progress line resumes when the referral moves to a stage
                again.
            </p>
        );
    }
    return (
        <ol
            aria-label="Referral progress"
            className="flex min-w-0 items-start overflow-x-auto pb-1"
        >
            {steps.map((step, index) => {
                const done = index < current;
                const now = index === current;
                return (
                    <li
                        key={step.key}
                        className="relative flex min-w-[88px] flex-1 flex-col items-center gap-2 text-center"
                    >
                        {index > 0 ? (
                            <span
                                aria-hidden
                                className={cn(
                                    'absolute top-[7px] right-1/2 h-0.5 w-full',
                                    index <= current
                                        ? 'bg-primary'
                                        : 'bg-border',
                                )}
                            />
                        ) : null}
                        <span
                            aria-hidden
                            className={cn(
                                'relative z-[1] size-4 rounded-full border-2',
                                done && 'border-primary bg-primary',
                                now &&
                                    'border-primary bg-card ring-4 ring-primary/20',
                                !done && !now && 'border-border bg-card',
                            )}
                        />
                        <span
                            className={cn(
                                'text-caption break-words',
                                now
                                    ? 'font-semibold text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {step.label}
                            <span className="sr-only">
                                {done
                                    ? ', done'
                                    : now
                                      ? ', current stage'
                                      : ', not yet'}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

function ReadinessSection({
    referral,
    permissions,
    options,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    options: Options;
    handlers: OverviewHandlers;
}) {
    const canStart =
        !!permissions.commence &&
        !!permissions['assessment.view'] &&
        !!permissions['funding.view'];
    const readiness = referral.profile?.readiness ?? null;
    const open = referral.stage !== 'closed';
    const canEvidence =
        permissions['assessment.view'] || permissions['funding.view'];
    const canHandover =
        permissions.convert &&
        permissions['assessment.view'] &&
        permissions['funding.view'];
    return (
        <Card id="referral-readiness" className="scroll-mt-5">
            <CardContent className="flex flex-col gap-4">
                <SectionHeading
                    eyebrow="Move forward with confidence"
                    title="A safe, considered handover"
                    action={
                        readiness &&
                        permissions['assessment.manage'] &&
                        open && (
                            <Button
                                variant="outline"
                                onClick={() => handlers.onReview('assessment')}
                            >
                                Review checks
                            </Button>
                        )
                    }
                />
                <ReferralPathway
                    referral={referral}
                    options={options}
                    showStart={canStart}
                />
                {readiness ? (
                    <ul className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                        {readiness.map((item) => {
                            const state = READINESS_STATE[item.state];
                            return (
                                <li
                                    key={item.key}
                                    className="flex min-w-0 items-start gap-3 rounded-lg bg-muted p-3"
                                >
                                    <StateRing state={item.state} />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {item.label}
                                            </span>
                                            <StatusBadge
                                                variant={state.variant}
                                                size="sm"
                                            >
                                                {state.text}
                                            </StatusBadge>
                                        </div>
                                        <p className="text-subtle mt-1 break-words">
                                            {item.detail}
                                        </p>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="text-subtle">
                        Assessment details are restricted for your current role.
                        A colleague with assessment access reviews these checks.
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border pt-3">
                    <FileText
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <p className="text-subtle min-w-0 flex-1">
                        Evidence stays with the referral and keeps its access
                        rules after handover.
                    </p>
                    {canEvidence && (
                        <LinkButton onClick={() => handlers.onTab('documents')}>
                            View evidence
                        </LinkButton>
                    )}
                    {canHandover && (
                        <LinkButton onClick={() => handlers.onTab('handover')}>
                            All handover checks
                        </LinkButton>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function UpcomingFollowUps({
    referral,
    permissions,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    handlers: OverviewHandlers;
}) {
    const open = referral.stage !== 'closed';
    const upcoming = referral.work.data.filter(
        (work) =>
            work.status === 'open' && work.id !== referral.primary_work_item_id,
    );
    return (
        <Card>
            <CardContent className="flex flex-col gap-4">
                <SectionHeading
                    eyebrow="Keep the conversation moving"
                    title={
                        <>
                            Upcoming follow-ups{' '}
                            <span className="text-muted-foreground tabular-nums">
                                {upcoming.length}
                            </span>
                        </>
                    }
                    action={
                        permissions['tasks.manage'] &&
                        open && (
                            <Button
                                variant="ghost"
                                onClick={() => handlers.onAction('work')}
                            >
                                Add follow-up
                            </Button>
                        )
                    }
                />
                {upcoming.length ? (
                    <ul className="divide-y divide-border">
                        {upcoming.slice(0, 3).map((work) => {
                            const title =
                                work.details.title ?? label(work.kind);
                            const late = isOverdue(work.due_at);
                            return (
                                <li
                                    key={work.reference}
                                    className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold break-words">
                                            {title}
                                        </p>
                                        <p className="text-subtle">
                                            {work.owner_name ?? 'No owner'} ·{' '}
                                            {formatDateTimeLong(work.due_at)}
                                        </p>
                                    </div>
                                    {late && (
                                        <StatusBadge
                                            variant="warning"
                                            size="sm"
                                        >
                                            Overdue
                                        </StatusBadge>
                                    )}
                                    <Button
                                        variant="outline"
                                        aria-label={`Review ${title}`}
                                        onClick={() =>
                                            handlers.onViewWork(work)
                                        }
                                    >
                                        Review
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="text-subtle">
                        No other follow-ups are open. The next step on the right
                        keeps the referral moving.
                    </p>
                )}
                <LinkButton onClick={() => handlers.onTab('work')}>
                    All follow-ups and completed work
                </LinkButton>
            </CardContent>
        </Card>
    );
}

function NextStepCard({
    referral,
    permissions,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    handlers: OverviewHandlers;
}) {
    const open = referral.stage !== 'closed';
    const late = open && isOverdue(referral.next_due_at);
    return (
        <Card id="referral-next-step" className="scroll-mt-5">
            <CardContent className="flex flex-col gap-4">
                <div className="flex items-center gap-2">
                    <span className="flex size-7 items-center justify-center rounded-full border border-primary/30 bg-primary-fill/10 text-primary">
                        <ArrowRight className="size-3.5" aria-hidden />
                    </span>
                    <Eyebrow>
                        {open ? 'The next step' : 'Closed referral'}
                    </Eyebrow>
                </div>
                <h2 className="text-section-title break-words whitespace-pre-wrap">
                    {open
                        ? referral.next_action || 'No next action recorded'
                        : `Closed · ${referral.closure_reason_label ?? label(referral.closure_reason ?? 'other')}`}
                </h2>
                <div className="flex items-center gap-3">
                    <PersonDisc name={referral.owner_name} size={32} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                            {referral.owner_name ?? 'No owner'}
                        </p>
                        <p className="text-subtle truncate">{referral.team}</p>
                    </div>
                    {permissions.assign && open && (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Change owner"
                            onClick={() => handlers.onAction('assign')}
                        >
                            <Users className="size-4" />
                        </Button>
                    )}
                </div>
                {open && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <Clock
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <span>
                            Follow up by{' '}
                            <strong className="font-semibold">
                                {referral.next_due_at
                                    ? formatDateTimeLong(referral.next_due_at)
                                    : 'a date still to plan'}
                            </strong>
                        </span>
                        {late && (
                            <StatusBadge variant="warning" size="sm">
                                Overdue
                            </StatusBadge>
                        )}
                    </div>
                )}
                {referral.hold_reason && open && (
                    <p className="text-subtle">
                        Waiting:{' '}
                        {referral.hold_reason_label ??
                            label(referral.hold_reason)}
                    </p>
                )}
                {open && referral.priority !== DEFAULT_PRIORITY && (
                    <div>
                        <ReferralPriorityBadge
                            priority={referral.priority}
                            priorityLabel={referral.priority_label}
                        />
                    </div>
                )}
                <div className="flex flex-col gap-2">
                    {permissions['tasks.manage'] && open && (
                        <Button
                            variant="outline"
                            onClick={() => handlers.onAction('next')}
                        >
                            Work on next action
                        </Button>
                    )}
                    {permissions.contact && open && (
                        <Button
                            className="w-full"
                            onClick={() => handlers.onAction('contact')}
                        >
                            Record an update
                            <ArrowRight className="size-4" />
                        </Button>
                    )}
                    {permissions.assign && open && !referral.owner_id && (
                        <Button
                            variant="outline"
                            className="w-full"
                            onClick={() => handlers.onAction('assign')}
                        >
                            Assign a coordinator
                        </Button>
                    )}
                    {permissions.reopen && !open && (
                        <Button
                            variant="outline"
                            className="w-full"
                            onClick={() => handlers.onAction('reopen')}
                        >
                            Reopen referral
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function FundingSnapshot({
    referral,
    permissions,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    handlers: OverviewHandlers;
}) {
    const summary = referral.profile?.funding ?? null;
    const total = fundingHeadline(referral);
    const percent = total ? coveragePercent(total) : null;
    const gap = total ? Number(total.proposed) - Number(total.covered) : 0;
    return (
        <Card id="referral-funding-glance" className="scroll-mt-5">
            <CardContent className="flex flex-col gap-3">
                <div className="flex items-center gap-2">
                    <Wallet className="size-4 text-primary" aria-hidden />
                    <h3 className="text-section-title">Funding at a glance</h3>
                </div>
                {!permissions['funding.view'] || !summary ? (
                    <p className="text-subtle">
                        Financial details need funding access. A colleague with
                        funding access can tell you where things stand.
                    </p>
                ) : (
                    <>
                        {total && Number(total.proposed) > 0 ? (
                            <>
                                <p className="text-section-title tabular-nums">
                                    {formatMoney(total.covered, total.currency)}
                                    <span className="text-subtle font-normal">
                                        {' '}
                                        {perPeriod(total.period)} covered
                                    </span>
                                </p>
                                <p className="text-subtle">
                                    {gap > 0
                                        ? `${formatMoney(String(gap), total.currency)} remains to arrange against the current proposal of ${formatMoney(total.proposed, total.currency)} ${perPeriod(total.period)}.`
                                        : 'The current proposal is covered by verified funding.'}
                                    {total.unknown_lines > 0 &&
                                        ` ${total.unknown_lines} support ${total.unknown_lines === 1 ? 'line is' : 'lines are'} not yet costed.`}
                                </p>
                                <ProgressValue
                                    percent={percent}
                                    tone={gap > 0 ? 'warning' : 'success'}
                                />
                            </>
                        ) : (
                            <>
                                <p className="text-section-title">
                                    Not yet costed
                                </p>
                                <p className="text-subtle">
                                    {summary.proposal_version
                                        ? 'The support proposal has no priced lines yet.'
                                        : 'No support proposal has been prepared yet.'}
                                </p>
                                <ProgressValue percent={null} />
                            </>
                        )}
                        <p className="text-caption text-muted-foreground">
                            {summary.cases.total} assistance{' '}
                            {summary.cases.total === 1 ? 'case' : 'cases'} ·{' '}
                            {summary.cases.verified} verified ·{' '}
                            {summary.cases.awaiting} awaiting progress
                        </p>
                        <LinkButton onClick={() => handlers.onTab('funding')}>
                            Open funding workspace
                        </LinkButton>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function CaseManagement({
    referral,
    permissions,
    handlers,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    handlers: OverviewHandlers;
}) {
    const open = referral.stage !== 'closed';
    const active = open && referral.stage !== 'converted';
    const canTransition = permissions.transition && active;
    const canClose = permissions.close && active;
    const history = referral.duplicate_closures ?? [];
    // Set priority stays listed with its reason when it can't be used.
    const priorityReason = priorityBlocked(referral, permissions);
    if (!active && !history.length) return null;
    return (
        <Card>
            <Collapsible>
                <CollapsibleTrigger className="group flex w-full items-center gap-2 rounded-xl px-6 py-4 text-left text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <Settings2
                        className="size-4 text-muted-foreground"
                        aria-hidden
                    />
                    Case management
                    <ChevronDown
                        className="ml-auto size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
                        aria-hidden
                    />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div className="flex flex-col gap-3 px-6 pb-5">
                        <p className="text-subtle">
                            Change stage or priority, arrange a waiting period
                            or resolve a duplicate. Each change records a
                            reason.
                        </p>
                        {active && (
                            <div className="flex flex-col gap-2 rounded-lg bg-muted p-3">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Priority
                                    </span>
                                    <ReferralPriorityBadge
                                        priority={referral.priority}
                                        priorityLabel={referral.priority_label}
                                    />
                                </div>
                                <Button
                                    variant="outline"
                                    disabled={!!priorityReason}
                                    onClick={() =>
                                        handlers.onAction('priority')
                                    }
                                >
                                    Set priority
                                </Button>
                                {priorityReason && (
                                    <p className="text-caption text-muted-foreground">
                                        {priorityReason}
                                    </p>
                                )}
                            </div>
                        )}
                        {canTransition && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        handlers.onAction('transition')
                                    }
                                >
                                    Change stage
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => handlers.onAction('hold')}
                                >
                                    {referral.hold_reason
                                        ? 'Manage waiting period'
                                        : 'Record waiting period'}
                                </Button>
                            </>
                        )}
                        {canClose && (
                            <>
                                {permissions['duplicates.manage'] && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            handlers.onAction('close', {
                                                closeReason: 'duplicate',
                                            })
                                        }
                                    >
                                        Review a duplicate
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() => handlers.onAction('close')}
                                >
                                    Close referral
                                </Button>
                            </>
                        )}
                        <DuplicateClosureHistoryPanel history={history} />
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

/** The mockup's "Submitted intake information" review, below the case overview. */
export function IntakeReview({
    referral,
    permissions,
    options,
    onCorrect,
}: {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    options: Options;
    onCorrect: () => void;
}) {
    const open = referral.stage !== 'closed';
    const service =
        options.services.find((item) => item.id === referral.requested_service)
            ?.label ?? label(referral.requested_service);
    const funding = referral.funding_intake;
    return (
        <section
            className="flex flex-col gap-4"
            aria-labelledby="intake-review"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="intake-review" className="text-section-title">
                        Submitted intake information
                    </h2>
                    <p className="text-subtle">
                        Submitted information is not automatically verified.
                        Contact details may belong to a referrer.
                    </p>
                </div>
                {permissions.edit && open && (
                    <Button variant="outline" onClick={onCorrect}>
                        Correct intake details
                    </Button>
                )}
            </div>
            <div className="grid gap-5 lg:grid-cols-2">
                <ReviewCard icon={Users} title="People and safe contact">
                    <ReviewRow label="Entry" value={label(referral.source)} />
                    <ReviewRow
                        label="Relationship to the person"
                        value={
                            referral.intake.relationship &&
                            label(referral.intake.relationship)
                        }
                    />
                    <ReviewRow
                        label="Organisation"
                        value={referral.intake.organisation}
                    />
                    <ReviewRow
                        label="Requested region"
                        value={referral.intake.region}
                    />
                    <ReviewRow
                        label="Contact person"
                        value={referral.contact.name}
                    />
                    <ReviewRow label="Phone" value={referral.contact.phone} />
                    <ReviewRow label="Email" value={referral.contact.email} />
                    <ReviewRow
                        label="Preferred channel"
                        value={
                            referral.contact.channel &&
                            label(referral.contact.channel)
                        }
                    />
                    <ReviewRow
                        label="Safe time"
                        value={referral.contact.safe_time}
                    />
                    <ReviewRow
                        label="Leave a message"
                        value={
                            referral.contact.leave_message
                                ? 'Yes — recorded as safe'
                                : 'No — do not leave messages'
                        }
                    />
                    <ReviewRow
                        label="Permission for follow-up"
                        value={
                            referral.contact_permission
                                ? 'Recorded'
                                : 'Not recorded'
                        }
                    />
                    <ReviewRow
                        label="Communication support"
                        value={referral.contact.communication}
                    />
                </ReviewCard>
                <ReviewCard icon={ClipboardList} title="Support and funding">
                    <ReviewRow
                        label="Preferred name"
                        value={referral.preferred_name}
                    />
                    <ReviewRow label="Requested support" value={service} />
                    <ReviewRow
                        label="Timeframe"
                        value={
                            referral.intake.timeframe &&
                            label(referral.intake.timeframe)
                        }
                    />
                    {referral.intake.preparation_reason && (
                        <ReviewRow
                            label="Staff preparation reason"
                            value={referral.intake.preparation_reason}
                        />
                    )}
                    <ReviewRow
                        label="Received"
                        value={formatDateTimeLong(referral.created_at)}
                    />
                    {funding ? (
                        <>
                            <ReviewRow
                                label="Funding situation at intake"
                                value={
                                    funding.funding_situation &&
                                    label(funding.funding_situation)
                                }
                            />
                            <ReviewRow
                                label="Agency named at intake"
                                value={funding.funding_agency}
                            />
                            {funding.reported_pathway && (
                                <ReviewRow
                                    label="Reported pathway (unverified)"
                                    value={`${funding.reported_pathway.label}${funding.reported_pathway.personal_income ? ' · Personal income support' : ''}`}
                                />
                            )}
                        </>
                    ) : (
                        <ReviewRow
                            label="Funding details"
                            value="Need funding access"
                        />
                    )}
                    <ReviewRow
                        label="Receipt reference"
                        value={referral.reference}
                    />
                    {permissions['assessment.view'] &&
                        referral.identity.date_of_birth && (
                            <ReviewRow
                                label="Date of birth"
                                value={formatDateOnly(
                                    referral.identity.date_of_birth,
                                )}
                            />
                        )}
                </ReviewCard>
            </div>
        </section>
    );
}
