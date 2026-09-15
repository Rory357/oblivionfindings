import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import {
    frequencyLabel,
    governanceStatus,
    refSuffix,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CalendarClock,
    CheckCircle,
    CheckCircle2,
    Link2,
    Paperclip,
    Pencil,
    Plus,
    ShieldCheck,
    ShieldOff,
    User,
    Wrench,
} from 'lucide-react';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { useState } from 'react';

import {
    AcceptRiskDialog,
    AddTreatmentDialog,
    ChangeTreatmentDueDateDialog,
    CloseRiskDialog,
    CompleteTreatmentDialog,
    riskCategoryIcon,
    RiskWizardDialog,
    type PassedResolutionOption,
    type RiskFormOptions,
    type TreatmentSummary,
} from './_dialogs';
import {
    CONTROL_EFFECT,
    CONTROL_HELP,
    RiskScoreExplainer,
    controlText,
    impactText,
    likelihoodText,
    riskBandLabel,
    riskLevelVariant,
    riskStatusChip,
    strategyLabel,
} from './_shared';

interface Treatment {
    id: number;
    action_description: string;
    assigned_to: { name: string } | null;
    due_date: string | null;
    status: string;
    expected_score_reduction: number | null;
    evidence_required?: boolean;
    evidence_attachments?: GovernanceAttachment[];
    completed_at: string | null;
    completed_by: { name: string } | null;
    completion_notes: string | null;
    can_complete: boolean;
    complete_blocked_reason: string | null;
    can_change_due_date: boolean;
}

interface Acceptance {
    id: number;
    acceptance_type: string;
    justification: string;
    conditions: string[];
    accepted_by: { name: string } | null;
    accepted_at: string | null;
    expires_at: string | null;
    expired: boolean;
    resolution: { id: number; title: string; reference: string | null } | null;
    has_resolution: boolean;
}

interface EventLink {
    id: number;
    event_type: string;
    event_type_label: string;
    event_reference: string | null;
    event_severity: string;
    link_rationale: string | null;
    linked_at: string | null;
    href: string | null;
}

interface Risk {
    id: number;
    risk_reference: string;
    title: string;
    description: string;
    category: string;
    category_label: string;
    likelihood_score: number;
    impact_score: number;
    inherent_score: number;
    residual_score: number;
    control_effectiveness: string;
    within_appetite: boolean;
    appetite_threshold: number;
    status: string;
    mitigation_strategy: string | null;
    review_frequency: string | null;
    next_review_date: string | null;
    risk_owner_id?: number | null;
    risk_owner: { id: number; name: string } | null;
    closure_rationale: string | null;
    closed_at: string | null;
    closed_by: { name: string } | null;
    accepted_until: string | null;
    acceptance_ended: boolean;
    treatments: Treatment[];
    acceptances: Acceptance[];
    events: EventLink[];
}

interface Props extends PageProps {
    risk: Risk;
    assignees: Array<{ id: number; name: string }>;
    canEdit: boolean;
    canAccept: boolean;
    canClose: boolean;
    resolutionOptions: PassedResolutionOption[];
    canViewResolutions: boolean;
    overseenBy: Array<{ id: number; name: string; type: string }>;
    formOptions?: RiskFormOptions | null;
}

const SEVERITY_VARIANTS: Record<string, 'critical' | 'warning' | 'success' | 'neutral'> = {
    critical: 'critical',
    high: 'warning',
    medium: 'warning',
    low: 'success',
};

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function ScoreTile({
    label,
    hint,
    children,
}: {
    label: React.ReactNode;
    hint?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border p-4">
            <p className="text-subtle flex items-center gap-1">{label}</p>
            <div className="mt-1 font-medium">{children}</div>
            {hint ? <p className="text-caption mt-1">{hint}</p> : null}
        </div>
    );
}

export default function RiskShow({
    risk,
    assignees,
    canEdit,
    canAccept,
    canClose,
    resolutionOptions,
    canViewResolutions,
    overseenBy = [],
    formOptions = null,
}: Props) {
    const [treatmentOpen, setTreatmentOpen] = useState(false);
    const [acceptOpen, setAcceptOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
    const [completing, setCompleting] = useState<TreatmentSummary | null>(null);
    const [rescheduling, setRescheduling] = useState<TreatmentSummary | null>(null);
    const canOpenEdit = canEdit && formOptions != null && risk.status !== 'closed';
    // Retired /risks/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canOpenEdit);

    const isOpen = risk.status === 'open';
    const isClosed = risk.status === 'closed';
    const canAcceptNow = canAccept && isOpen;
    const chip = riskStatusChip(risk);
    const completeTreatments = risk.treatments.filter(
        (t) => t.status === 'complete',
    ).length;
    const overdueTreatments = risk.treatments.filter(
        (t) => t.status === 'overdue',
    ).length;
    const currentAcceptance =
        risk.status === 'accepted' ? (risk.acceptances[0] ?? null) : null;

    const limitCaption =
        risk.status === 'accepted'
            ? chip.label
            : risk.within_appetite
              ? 'Within the limit'
              : 'Above the limit — needs action or board acceptance';

    const header = (
        <PageHeader
            variant="profile"
            backHref="/governance/risks"
            icon={riskCategoryIcon(risk.category)}
            title={risk.title}
            titleDusk="risk-heading"
            wrapTitle
            titleChip={
                <PageHeaderStatusChip variant={chip.variant}>
                    {chip.label}
                </PageHeaderStatusChip>
            }
            subline={[
                risk.category_label,
                `${riskBandLabel(risk.residual_score)} after controls (${risk.residual_score})`,
                refSuffix(risk.risk_reference),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canEdit && !isClosed ? (
                        <PageHeaderGlassButton
                            icon={Plus}
                            onClick={() => setTreatmentOpen(true)}
                        >
                            Add action
                        </PageHeaderGlassButton>
                    ) : null}
                    {canAcceptNow && !risk.within_appetite ? (
                        <PageHeaderGlassButton
                            icon={ShieldCheck}
                            onClick={() => setAcceptOpen(true)}
                        >
                            Accept risk
                        </PageHeaderGlassButton>
                    ) : null}
                    {canClose ? (
                        <PageHeaderGlassButton
                            icon={ShieldOff}
                            onClick={() => setCloseOpen(true)}
                        >
                            Close risk
                        </PageHeaderGlassButton>
                    ) : null}
                    {canOpenEdit ? (
                        <PageHeaderPrimaryButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit risk
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Risk after controls"
                        value={`${risk.residual_score} of 25`}
                        ariaLabel="View how serious this risk is"
                        onClick={() => scrollTo('risk-assessment')}
                        tone={
                            risk.residual_score >= 20
                                ? 'critical'
                                : risk.residual_score >= 10
                                  ? 'warning'
                                  : 'brand'
                        }
                    >
                        <PageHeaderMeterBar
                            percent={(risk.residual_score / 25) * 100}
                        />
                        <PageHeaderMeterCaption>
                            {riskBandLabel(risk.residual_score)} · the board&apos;s
                            limit is {risk.appetite_threshold}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Risk before controls"
                        ariaLabel="View how serious this risk is"
                        onClick={() => scrollTo('risk-assessment')}
                    >
                        <PageHeaderMeterBig>{risk.inherent_score}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Likelihood {risk.likelihood_score} × impact{' '}
                            {risk.impact_score}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="The board's limit"
                        ariaLabel="View the board's limit and any acceptance"
                        onClick={() =>
                            scrollTo(
                                risk.acceptances.length > 0
                                    ? 'risk-acceptance'
                                    : 'risk-assessment',
                            )
                        }
                        tone={
                            risk.status === 'accepted'
                                ? risk.acceptance_ended
                                    ? 'warning'
                                    : 'success'
                                : risk.within_appetite
                                  ? 'success'
                                  : 'critical'
                        }
                    >
                        <PageHeaderMeterBig>{risk.appetite_threshold}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{limitCaption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Actions"
                        value={risk.treatments.length}
                        ariaLabel="View actions to reduce this risk"
                        onClick={() => scrollTo('risk-treatments')}
                        tone={overdueTreatments > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBar
                            percent={
                                risk.treatments.length > 0
                                    ? (completeTreatments /
                                          risk.treatments.length) *
                                      100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            {overdueTreatments > 0
                                ? `${overdueTreatments} overdue · ${completeTreatments} done`
                                : `${completeTreatments} of ${risk.treatments.length} done`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Next review"
                        ariaLabel="View review details"
                        onClick={() => scrollTo('risk-details')}
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {formatDateOnly(risk.next_review_date, 'Not set')}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Reviewed{' '}
                            {frequencyLabel(risk.review_frequency).toLowerCase()}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risk register', href: '/governance/risks' },
                {
                    title: risk.title,
                    href: `/governance/risks/${risk.id}`,
                },
            ]}
        >
            <Head title={risk.title} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        {isClosed ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <ShieldOff className="h-4 w-4 text-muted-foreground" />
                                        Closed
                                    </CardTitle>
                                    <CardDescription>
                                        Closed on {formatDateLong(risk.closed_at)}
                                        {risk.closed_by
                                            ? ` by ${risk.closed_by.name}`
                                            : ''}
                                    </CardDescription>
                                </CardHeader>
                                {risk.closure_rationale ? (
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap">
                                            {risk.closure_rationale}
                                        </p>
                                    </CardContent>
                                ) : null}
                            </Card>
                        ) : null}

                        <Card>
                            <CardHeader>
                                <CardTitle>What the risk is</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-foreground">
                                    {risk.description}
                                </p>
                            </CardContent>
                        </Card>

                        <Card id="risk-assessment" className="scroll-mt-5">
                            <CardHeader>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <CardTitle>How serious it is</CardTitle>
                                        <CardDescription>
                                            Likelihood × impact, then lowered by the
                                            controls already in place
                                        </CardDescription>
                                    </div>
                                    <RiskScoreExplainer />
                                </div>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <ScoreTile
                                        label={
                                            <>
                                                Likelihood
                                                <GovernanceTermHint term="likelihood" />
                                            </>
                                        }
                                    >
                                        {likelihoodText(risk.likelihood_score)}
                                    </ScoreTile>
                                    <ScoreTile
                                        label={
                                            <>
                                                Impact
                                                <GovernanceTermHint term="impact" />
                                            </>
                                        }
                                    >
                                        {impactText(risk.impact_score)}
                                    </ScoreTile>
                                    <ScoreTile
                                        label={
                                            <>
                                                Risk before controls
                                                <GovernanceTermHint term="risk_before_controls" />
                                            </>
                                        }
                                    >
                                        <StatusBadge
                                            variant={riskLevelVariant(
                                                risk.inherent_score,
                                            )}
                                        >
                                            {risk.inherent_score} ·{' '}
                                            {riskBandLabel(risk.inherent_score)}
                                        </StatusBadge>
                                    </ScoreTile>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ScoreTile
                                        label={
                                            <>
                                                How well current controls work
                                                <GovernanceTermHint term="control_effectiveness" />
                                            </>
                                        }
                                        hint={`${CONTROL_HELP} This rating ${CONTROL_EFFECT[risk.control_effectiveness] ?? 'changes the score'}.`}
                                    >
                                        {controlText(risk.control_effectiveness)}
                                    </ScoreTile>
                                    <ScoreTile
                                        label={
                                            <>
                                                Risk after controls
                                                <GovernanceTermHint term="risk_after_controls" />
                                            </>
                                        }
                                    >
                                        <StatusBadge
                                            variant={riskLevelVariant(
                                                risk.residual_score,
                                            )}
                                        >
                                            {risk.residual_score} ·{' '}
                                            {riskBandLabel(risk.residual_score)}
                                        </StatusBadge>
                                    </ScoreTile>
                                </div>
                                <div className="rounded-lg border border-border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-subtle flex items-center gap-1">
                                            The board&apos;s limit for{' '}
                                            {risk.category_label.toLowerCase()} risks
                                            <GovernanceTermHint term="board_limit" />
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {risk.appetite_threshold}
                                        </span>
                                    </div>
                                    <div className="mt-2">
                                        {risk.within_appetite ? (
                                            <StatusBadge variant="success">
                                                <CheckCircle className="h-3.5 w-3.5" />
                                                Within the board&apos;s limit
                                            </StatusBadge>
                                        ) : risk.status === 'accepted' ? (
                                            <StatusBadge variant={chip.variant}>
                                                <ShieldCheck className="h-3.5 w-3.5" />
                                                Above the limit · {chip.label.toLowerCase()}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="critical">
                                                <AlertTriangle className="h-3.5 w-3.5" />
                                                Above the board&apos;s limit — needs
                                                more action or the board&apos;s
                                                acceptance
                                            </StatusBadge>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card id="risk-treatments" className="scroll-mt-5">
                            <CardHeader>
                                <CardTitle>Actions to reduce this risk</CardTitle>
                                <CardDescription>
                                    Work under way to make it less likely or less
                                    harmful
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {risk.treatments.length > 0 ? (
                                    <div className="flex flex-col gap-3">
                                        {risk.treatments.map((treatment) => {
                                            const status = governanceStatus(
                                                'risk_treatment_status',
                                                treatment.status,
                                            );
                                            const summary: TreatmentSummary = {
                                                id: treatment.id,
                                                action_description:
                                                    treatment.action_description,
                                                due_date: treatment.due_date,
                                                expected_score_reduction:
                                                    treatment.expected_score_reduction,
                                            };
                                            return (
                                                <div
                                                    key={treatment.id}
                                                    className="rounded-lg border border-border p-4"
                                                >
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <p className="font-medium">
                                                                {treatment.action_description}
                                                            </p>
                                                            <div className="text-subtle mt-2 flex flex-wrap items-center gap-4">
                                                                <span className="flex items-center gap-1">
                                                                    <User className="h-4 w-4" />
                                                                    {treatment.assigned_to?.name ||
                                                                        'No one assigned'}
                                                                </span>
                                                                <span className="flex items-center gap-1">
                                                                    <Calendar className="h-4 w-4" />
                                                                    Due{' '}
                                                                    {formatDateOnly(
                                                                        treatment.due_date,
                                                                        'date not set',
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <StatusBadge variant={status.variant}>
                                                            {status.label}
                                                        </StatusBadge>
                                                    </div>
                                                    {treatment.expected_score_reduction ? (
                                                        <p className="text-caption mt-2">
                                                            Should lower the risk after
                                                            controls by{' '}
                                                            {treatment.expected_score_reduction}
                                                        </p>
                                                    ) : null}
                                                    {treatment.status === 'complete' ? (
                                                        <p className="mt-2 flex items-center gap-1.5 text-sm">
                                                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                                                            Done on{' '}
                                                            {formatDateLong(treatment.completed_at)}
                                                            {treatment.completed_by
                                                                ? ` by ${treatment.completed_by.name}`
                                                                : ''}
                                                            {treatment.completion_notes
                                                                ? ` — ${treatment.completion_notes}`
                                                                : ''}
                                                        </p>
                                                    ) : null}

                                                    {canEdit &&
                                                    (treatment.can_complete ||
                                                        treatment.complete_blocked_reason ||
                                                        treatment.can_change_due_date) ? (
                                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                                            {treatment.can_complete ? (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setCompleting(summary)
                                                                    }
                                                                >
                                                                    <CheckCircle2 className="h-4 w-4" />
                                                                    Mark as done
                                                                </Button>
                                                            ) : null}
                                                            {treatment.can_change_due_date ? (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        setRescheduling(summary)
                                                                    }
                                                                >
                                                                    <CalendarClock className="h-4 w-4" />
                                                                    Change due date
                                                                </Button>
                                                            ) : null}
                                                            {treatment.complete_blocked_reason ? (
                                                                <p className="text-caption w-full">
                                                                    {treatment.complete_blocked_reason}
                                                                </p>
                                                            ) : null}
                                                        </div>
                                                    ) : null}

                                                    <details
                                                        className="group mt-3"
                                                        data-dusk={`treatment-evidence-${treatment.id}`}
                                                    >
                                                        <summary className="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-muted-foreground transition hover:text-foreground">
                                                            <Paperclip className="h-3.5 w-3.5" />
                                                            Evidence (
                                                            {treatment.evidence_attachments
                                                                ?.length ?? 0}
                                                            )
                                                            {treatment.evidence_required ? (
                                                                <StatusBadge
                                                                    variant="warning"
                                                                    size="sm"
                                                                    className="ml-1"
                                                                >
                                                                    Needed to mark done
                                                                </StatusBadge>
                                                            ) : null}
                                                        </summary>
                                                        <div className="mt-3 border-t border-border pt-3">
                                                            <GovernanceAttachmentsPanel
                                                                canManage={!!canEdit && treatment.status !== 'complete'}
                                                                attachments={
                                                                    treatment.evidence_attachments ??
                                                                    []
                                                                }
                                                                urls={{
                                                                    upload: `/governance/risks/${risk.id}/treatments/${treatment.id}/attachments`,
                                                                    delete: (id) =>
                                                                        `/governance/risks/${risk.id}/treatments/${treatment.id}/attachments/${id}`,
                                                                }}
                                                                reloadProp="risk"
                                                                helperText="Test results, supplier reports, signed letters — anything showing the action was done."
                                                                emptyText={{
                                                                    managed:
                                                                        'No evidence yet. Drop files above to show the action was done.',
                                                                    readOnly:
                                                                        'No evidence has been attached to this action.',
                                                                }}
                                                            />
                                                        </div>
                                                    </details>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Wrench}
                                        title="No actions yet"
                                        description={
                                            canEdit && !isClosed
                                                ? 'Add an action to record how this risk will be reduced.'
                                                : 'Actions added by the risk owner appear here.'
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>

                        {risk.events.length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Linked events</CardTitle>
                                    <CardDescription>
                                        Incidents, alerts and concerns connected
                                        to this risk
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2">
                                    {risk.events.map((event) => (
                                        <div
                                            key={event.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border border-border p-3"
                                        >
                                            <div className="flex min-w-0 items-center gap-2">
                                                <Link2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                <StatusBadge variant="neutral">
                                                    {event.event_type_label}
                                                </StatusBadge>
                                                {event.href ? (
                                                    <Link
                                                        href={event.href}
                                                        className="truncate text-sm font-medium text-primary underline-offset-4 hover:underline"
                                                    >
                                                        {event.event_reference
                                                            ? `Open ${event.event_reference}`
                                                            : `Open this ${event.event_type_label.toLowerCase()}`}
                                                    </Link>
                                                ) : (
                                                    <span className="truncate text-sm">
                                                        {event.event_reference ??
                                                            'No reference recorded'}
                                                    </span>
                                                )}
                                            </div>
                                            <StatusBadge
                                                variant={
                                                    SEVERITY_VARIANTS[
                                                        event.event_severity
                                                    ] ?? 'neutral'
                                                }
                                            >
                                                {governanceStatus('priority', event.event_severity).label}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-5">
                        <Card id="risk-details" className="scroll-mt-5">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div>
                                    <p className="text-subtle">Risk owner</p>
                                    <p className="font-medium">
                                        {risk.risk_owner?.name || 'No owner'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">How we will respond</p>
                                    <p className="font-medium">
                                        {strategyLabel(risk.mitigation_strategy)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">Reviewed</p>
                                    <p className="font-medium">
                                        {frequencyLabel(risk.review_frequency)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">Next review</p>
                                    <p className="font-medium">
                                        {formatDateLong(risk.next_review_date, 'Not set')}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">Overseen by</p>
                                    {overseenBy.length > 0 ? (
                                        <ul className="flex flex-col gap-1">
                                            {overseenBy.map((committee) => (
                                                <li key={committee.id}>
                                                    <Link
                                                        href={`/governance/risks/committee/${committee.id}`}
                                                        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                                                    >
                                                        {committee.name}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="font-medium text-muted-foreground">
                                            The full board (no committee set up for
                                            this kind of risk)
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {risk.acceptances.length > 0 ? (
                            <Card id="risk-acceptance" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle>The board&apos;s acceptance</CardTitle>
                                    {currentAcceptance ? (
                                        <CardDescription>{chip.label}</CardDescription>
                                    ) : null}
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    {risk.acceptances.map((acceptance) => (
                                        <div
                                            key={acceptance.id}
                                            className="flex flex-col gap-2 rounded-lg border border-border p-3"
                                        >
                                            <StatusBadge
                                                variant={acceptance.expired ? 'warning' : 'success'}
                                                className="w-fit"
                                            >
                                                {acceptance.expired
                                                    ? `Ended ${formatDateOnly(acceptance.expires_at)}`
                                                    : `Accepted until ${formatDateOnly(acceptance.expires_at)}`}
                                            </StatusBadge>
                                            <div>
                                                <p className="text-caption">
                                                    Why the board accepted it
                                                </p>
                                                <p className="text-sm whitespace-pre-wrap">
                                                    {acceptance.justification}
                                                </p>
                                            </div>
                                            {acceptance.conditions.length > 0 ? (
                                                <div>
                                                    <p className="text-caption">Conditions</p>
                                                    <ul className="list-disc pl-5 text-sm">
                                                        {acceptance.conditions.map(
                                                            (condition, index) => (
                                                                <li key={index}>{condition}</li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </div>
                                            ) : null}
                                            <div>
                                                <p className="text-caption">Resolution</p>
                                                {acceptance.resolution ? (
                                                    <Link
                                                        href={`/governance/resolutions/${acceptance.resolution.id}`}
                                                        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                                                    >
                                                        {acceptance.resolution.title}
                                                    </Link>
                                                ) : (
                                                    <p className="text-sm text-muted-foreground">
                                                        {acceptance.has_resolution
                                                            ? 'Linked to a resolution you can’t open'
                                                            : 'Accepted without a board resolution (the risk was within the limit)'}
                                                    </p>
                                                )}
                                                {acceptance.resolution?.reference ? (
                                                    <span className="text-caption ml-1">
                                                        {refSuffix(acceptance.resolution.reference)}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <p className="text-caption">
                                                Recorded
                                                {acceptance.accepted_by
                                                    ? ` by ${acceptance.accepted_by.name}`
                                                    : ''}{' '}
                                                on {formatDateLong(acceptance.accepted_at)}
                                            </p>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </PageLayout>

            {canEdit && !isClosed ? (
                <AddTreatmentDialog
                    open={treatmentOpen}
                    onClose={() => setTreatmentOpen(false)}
                    riskId={risk.id}
                    assignees={assignees}
                />
            ) : null}
            {canAcceptNow ? (
                <AcceptRiskDialog
                    open={acceptOpen}
                    onClose={() => setAcceptOpen(false)}
                    riskId={risk.id}
                    categoryLabel={risk.category_label}
                    appetiteThreshold={risk.appetite_threshold}
                    aboveLimit={!risk.within_appetite}
                    resolutionOptions={resolutionOptions}
                    canViewResolutions={canViewResolutions}
                />
            ) : null}
            {canClose ? (
                <CloseRiskDialog
                    open={closeOpen}
                    onClose={() => setCloseOpen(false)}
                    riskId={risk.id}
                    riskTitle={risk.title}
                />
            ) : null}
            {canEdit ? (
                <>
                    <CompleteTreatmentDialog
                        riskId={risk.id}
                        treatment={completing}
                        onClose={() => setCompleting(null)}
                    />
                    <ChangeTreatmentDueDateDialog
                        riskId={risk.id}
                        treatment={rescheduling}
                        onClose={() => setRescheduling(null)}
                    />
                </>
            ) : null}
            {canOpenEdit && formOptions ? (
                <RiskWizardDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={formOptions}
                    risk={risk}
                />
            ) : null}
        </AppLayout>
    );
}
