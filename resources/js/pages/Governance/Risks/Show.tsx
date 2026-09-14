import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { riskScoreLevel } from '@/lib/governance-status';
import { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Link2,
    Paperclip,
    Pencil,
    Plus,
    ShieldCheck,
    User,
    Wrench,
} from 'lucide-react';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { useState } from 'react';

import {
    AcceptRiskDialog,
    AddTreatmentDialog,
    riskCategoryIcon,
    RiskWizardDialog,
    STRATEGY_OPTIONS,
    type RiskFormOptions,
} from './_dialogs';
import { riskLevelVariant, riskStatusLabel, riskStatusVariant } from './_shared';

interface Treatment {
    id: number;
    action_description: string;
    assigned_to: { name: string } | null;
    due_date: string | null;
    status: string;
    expected_score_reduction: number | null;
    evidence_required?: boolean;
    evidence_attachments?: GovernanceAttachment[];
}

interface Acceptance {
    id: number;
    acceptance_type: string;
    justification: string;
    accepted_by: { name: string } | null;
    accepted_at: string;
    expires_at: string | null;
}

interface EventLink {
    id: number;
    event_type: string;
    event_reference: string | null;
    event_severity: string;
    link_rationale: string | null;
    linked_at: string;
}

interface Risk {
    id: number;
    risk_reference: string;
    title: string;
    description: string;
    category: string;
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
    treatments: Treatment[];
    acceptances: Acceptance[];
    events: EventLink[];
}

interface Props extends PageProps {
    risk: Risk;
    assignees: Array<{ id: number; name: string; email: string }>;
    canEdit: boolean;
    canAccept: boolean;
    formOptions?: RiskFormOptions | null;
}

const TREATMENT_VARIANTS: Record<string, StatusVariant> = {
    complete: 'success',
    in_progress: 'info',
    overdue: 'critical',
    planned: 'neutral',
};

const SEVERITY_VARIANTS: Record<string, StatusVariant> = {
    critical: 'critical',
    high: 'warning',
    medium: 'warning',
    low: 'success',
};

function dateOnly(value: string | null | undefined): string {
    return formatDateOnly(value ? value.slice(0, 10) : null);
}

function humanise(value: string | null | undefined): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function RiskShow({
    risk,
    assignees,
    canEdit,
    canAccept,
    formOptions = null,
}: Props) {
    const { labels: pageLabels } = usePage().props as {
        labels?: Record<string, string>;
    };
    const clientSingular = pageLabels?.['client.singular'] ?? 'Client';
    const [treatmentOpen, setTreatmentOpen] = useState(false);
    const [acceptOpen, setAcceptOpen] = useState(false);
    const canOpenEdit = canEdit && formOptions != null;
    // Retired /risks/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canOpenEdit);

    const categoryLabel = (category: string) => {
        const labels: Record<string, string> = {
            client_safety: `${clientSingular} Safety`,
            reputational: 'Reputational',
            financial: 'Financial',
            it_cyber: 'IT/Cyber',
            workforce: 'Workforce',
            legal_compliance: 'Legal/Compliance',
            operational: 'Operational',
            clinical: 'Clinical',
        };
        return labels[category] || humanise(category);
    };

    const canAcceptNow =
        canEdit &&
        canAccept &&
        !risk.within_appetite &&
        risk.status === 'active';
    const completeTreatments = risk.treatments.filter(
        (t) => t.status === 'complete',
    ).length;
    const overdueTreatments = risk.treatments.filter(
        (t) => t.status === 'overdue',
    ).length;
    const strategyLabel =
        STRATEGY_OPTIONS.find((s) => s.key === risk.mitigation_strategy)
            ?.label ?? humanise(risk.mitigation_strategy);

    const header = (
        <PageHeader
            variant="profile"
            backHref="/governance/risks"
            icon={riskCategoryIcon(risk.category)}
            title={risk.title}
            titleDusk="risk-heading"
            wrapTitle
            titleChip={
                !risk.within_appetite ? (
                    <PageHeaderStatusChip variant="critical">
                        Above appetite
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip
                        variant={riskStatusVariant(risk.status)}
                    >
                        {riskStatusLabel(risk.status)}
                    </PageHeaderStatusChip>
                )
            }
            subline={`${risk.risk_reference} · ${categoryLabel(risk.category)} · ${riskStatusLabel(risk.status)} · ${riskScoreLevel(risk.residual_score)} residual risk (${risk.residual_score})`}
            actions={
                <>
                    {canEdit ? (
                        <PageHeaderGlassButton
                            icon={Plus}
                            onClick={() => setTreatmentOpen(true)}
                        >
                            Add treatment
                        </PageHeaderGlassButton>
                    ) : null}
                    {canAcceptNow ? (
                        <PageHeaderGlassButton
                            icon={ShieldCheck}
                            onClick={() => setAcceptOpen(true)}
                        >
                            Accept risk
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
                        label="Residual score"
                        value={`${risk.residual_score}/25`}
                        ariaLabel="View the risk assessment"
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
                            {riskScoreLevel(risk.residual_score)} · appetite{' '}
                            {risk.appetite_threshold}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Inherent score"
                        ariaLabel="View the risk assessment"
                        onClick={() => scrollTo('risk-assessment')}
                    >
                        <PageHeaderMeterBig>{risk.inherent_score}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Likelihood {risk.likelihood_score} × impact{' '}
                            {risk.impact_score}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Appetite"
                        ariaLabel="View appetite details"
                        onClick={() => scrollTo('risk-assessment')}
                        tone={risk.within_appetite ? 'success' : 'critical'}
                    >
                        <PageHeaderMeterBig>
                            {risk.appetite_threshold}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {risk.within_appetite
                                ? 'Within appetite'
                                : 'Above appetite · acceptance needed'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Treatments"
                        value={risk.treatments.length}
                        ariaLabel="View treatment actions"
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
                                ? `${overdueTreatments} overdue · ${completeTreatments} complete`
                                : `${completeTreatments} of ${risk.treatments.length} complete`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Next review"
                        ariaLabel="View review details"
                        onClick={() => scrollTo('risk-details')}
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {dateOnly(risk.next_review_date)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {humanise(risk.review_frequency)} review
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
                    title: risk.risk_reference,
                    href: `/governance/risks/${risk.id}`,
                },
            ]}
        >
            <Head title={risk.title} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Description</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-foreground">
                                    {risk.description}
                                </p>
                            </CardContent>
                        </Card>

                        <Card id="risk-assessment">
                            <CardHeader>
                                <CardTitle>Risk assessment</CardTitle>
                                <CardDescription>
                                    5×5 likelihood × impact, adjusted for
                                    control effectiveness
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div className="grid grid-cols-3 gap-4">
                                    {[
                                        {
                                            label: 'Likelihood',
                                            value: risk.likelihood_score,
                                        },
                                        {
                                            label: 'Impact',
                                            value: risk.impact_score,
                                        },
                                        {
                                            label: 'Inherent score',
                                            value: risk.inherent_score,
                                        },
                                    ].map((tile) => (
                                        <div
                                            key={tile.label}
                                            className="rounded-lg bg-muted p-4 text-center"
                                        >
                                            <p className="text-page-title tabular-nums">
                                                {tile.value}
                                            </p>
                                            <p className="text-subtle">
                                                {tile.label}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="rounded-lg border border-border p-4">
                                        <p className="text-subtle">
                                            Control effectiveness
                                        </p>
                                        <p className="font-medium capitalize">
                                            {risk.control_effectiveness}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border border-border p-4">
                                        <p className="text-subtle">
                                            Residual score
                                        </p>
                                        <StatusBadge
                                            variant={riskLevelVariant(
                                                risk.residual_score,
                                            )}
                                            className="mt-1"
                                        >
                                            {risk.residual_score} ·{' '}
                                            {riskScoreLevel(risk.residual_score)}
                                        </StatusBadge>
                                    </div>
                                </div>
                                <div className="rounded-lg border border-border p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-subtle">
                                            Appetite threshold
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {risk.appetite_threshold}
                                        </span>
                                    </div>
                                    <div className="mt-2">
                                        {risk.within_appetite ? (
                                            <StatusBadge variant="success">
                                                <CheckCircle className="h-3.5 w-3.5" />
                                                Within appetite
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="critical">
                                                <AlertTriangle className="h-3.5 w-3.5" />
                                                Above appetite — requires
                                                acceptance
                                            </StatusBadge>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card id="risk-treatments">
                            <CardHeader>
                                <CardTitle>Treatment actions</CardTitle>
                                <CardDescription>
                                    Active mitigation measures
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {risk.treatments.length > 0 ? (
                                    <div className="flex flex-col gap-3">
                                        {risk.treatments.map((treatment) => (
                                            <div
                                                key={treatment.id}
                                                className="rounded-lg border border-border p-4"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="font-medium">
                                                            {
                                                                treatment.action_description
                                                            }
                                                        </p>
                                                        <div className="text-subtle mt-2 flex flex-wrap items-center gap-4">
                                                            <span className="flex items-center gap-1">
                                                                <User className="h-4 w-4" />
                                                                {treatment
                                                                    .assigned_to
                                                                    ?.name ||
                                                                    'Unassigned'}
                                                            </span>
                                                            <span className="flex items-center gap-1">
                                                                <Calendar className="h-4 w-4" />
                                                                {dateOnly(
                                                                    treatment.due_date,
                                                                )}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <StatusBadge
                                                        variant={
                                                            TREATMENT_VARIANTS[
                                                                treatment.status
                                                            ] ?? 'neutral'
                                                        }
                                                    >
                                                        {humanise(
                                                            treatment.status,
                                                        )}
                                                    </StatusBadge>
                                                </div>
                                                {treatment.expected_score_reduction ? (
                                                    <p className="mt-2 text-sm text-status-success">
                                                        Expected score
                                                        reduction: −
                                                        {
                                                            treatment.expected_score_reduction
                                                        }
                                                    </p>
                                                ) : null}

                                                <details
                                                    className="group mt-3"
                                                    data-dusk={`treatment-evidence-${treatment.id}`}
                                                >
                                                    <summary className="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-muted-foreground transition hover:text-foreground">
                                                        <Paperclip className="h-3.5 w-3.5" />
                                                        Evidence (
                                                        {treatment
                                                            .evidence_attachments
                                                            ?.length ?? 0}
                                                        )
                                                        {treatment.evidence_required ? (
                                                            <StatusBadge
                                                                variant="warning"
                                                                size="sm"
                                                                className="ml-1"
                                                            >
                                                                Required
                                                            </StatusBadge>
                                                        ) : null}
                                                    </summary>
                                                    <div className="mt-3 border-t border-border pt-3">
                                                        <GovernanceAttachmentsPanel
                                                            canManage={!!canEdit}
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
                                                            helperText="Control test results, vendor reports, sign-off letters — anything proving the treatment was actioned."
                                                            emptyText={{
                                                                managed:
                                                                    'No evidence yet. Drop files above to record proof of action.',
                                                                readOnly:
                                                                    'No evidence has been attached to this treatment.',
                                                            }}
                                                        />
                                                    </div>
                                                </details>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Wrench}
                                        title="No treatment actions yet"
                                        description={
                                            canEdit
                                                ? 'Add a treatment to record how this risk will be reduced.'
                                                : 'Treatment actions added by the risk owner will appear here.'
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
                                        Related incidents, alerts, and concerns
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
                                                    {humanise(event.event_type)}
                                                </StatusBadge>
                                                <span className="truncate text-sm">
                                                    {event.event_reference ??
                                                        '—'}
                                                </span>
                                            </div>
                                            <StatusBadge
                                                variant={
                                                    SEVERITY_VARIANTS[
                                                        event.event_severity
                                                    ] ?? 'neutral'
                                                }
                                            >
                                                {humanise(event.event_severity)}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-5">
                        <Card id="risk-details">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div>
                                    <p className="text-subtle">Risk owner</p>
                                    <p className="font-medium">
                                        {risk.risk_owner?.name ||
                                            'Not assigned'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">
                                        Mitigation strategy
                                    </p>
                                    <p className="font-medium">
                                        {strategyLabel}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">
                                        Review frequency
                                    </p>
                                    <p className="font-medium">
                                        {humanise(risk.review_frequency)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">Next review</p>
                                    <p className="font-medium">
                                        {dateOnly(risk.next_review_date)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        {risk.acceptances.length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Risk acceptances</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    {risk.acceptances.map((acceptance) => (
                                        <div
                                            key={acceptance.id}
                                            className="rounded-lg border border-primary/40 bg-primary/10 p-3"
                                        >
                                            <p className="text-sm font-medium text-primary">
                                                {humanise(
                                                    acceptance.acceptance_type,
                                                )}
                                            </p>
                                            <p className="text-caption mt-1">
                                                Accepted by{' '}
                                                {acceptance.accepted_by?.name ??
                                                    '—'}
                                            </p>
                                            <p className="text-caption">
                                                Expires{' '}
                                                {dateOnly(acceptance.expires_at)}
                                            </p>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </PageLayout>

            {canEdit ? (
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
                    appetiteThreshold={risk.appetite_threshold}
                />
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
