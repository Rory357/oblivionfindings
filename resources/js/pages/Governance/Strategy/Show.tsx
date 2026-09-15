import {
    pageHasFlashError,
    useDialogDeepLink,
} from '@/components/governance/governance-dialog-deep-link';
import InputError from '@/components/input-error';
import { EntityChip } from '@/components/lists';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import {
    formatNzd,
    goalStatusLabel,
    governanceStatus,
    refSuffix,
    themeLabel,
} from '@/lib/governance-labels';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Circle,
    Clock,
    Compass,
    Copy,
    Eye,
    Flag,
    History,
    Loader2,
    Pencil,
    Plus,
    Rocket,
    Target,
    UserCheck,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
    StrategicPlanWizardDialog,
    formatTimeframe,
    humaniseHorizon,
    normaliseValues,
    type PlanValue,
    type StrategicPlanFormOptions,
    type StrategicPlanStepKey,
} from './_dialogs';

interface CarriedResolution {
    id: number;
    resolution_reference: string | null;
    title: string;
    outcome: string;
    closed_at: string | null;
}

interface Initiative {
    id: number;
    name: string;
    description: string;
    budget_allocated: number | string;
    budget_spent: number | string;
    start_date: string;
    target_completion: string | null;
    status: string;
    owner: { name: string } | null;
}

interface Goal {
    id: number;
    pillar: string;
    timeframe: string | null;
    title: string;
    description: string;
    progress_pct?: number | string;
    key_results: Array<{ result: string; status: string }> | null;
    status: string | null;
    lead_executive?: { name: string } | null;
    roadmap_initiative?: { id: number; title: string; status: string } | null;
    initiatives: Initiative[];
}

interface StrategicPlan {
    id: number;
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    vision_statement: string | null;
    mission_statement: string | null;
    values: PlanValue[] | null;
    status: string;
    version_number: number;
    version_notes?: string | null;
    approved_by_board_at?: string | null;
    goals: Goal[];
    supersedes?: { id: number; title: string; version_number: number } | null;
}

interface ApprovalSummary {
    key: string;
    label: string;
    detail: string;
    resolution: {
        id: number;
        title: string;
        reference: string | null;
    } | null;
}

interface Props {
    plan: StrategicPlan;
    approval: ApprovalSummary;
    carriedResolutions?: CarriedResolution[];
    canEdit?: boolean;
    canAddGoal?: boolean;
    canApprove?: boolean;
    canCreateVersion?: boolean;
    canViewResolutions?: boolean;
    /** Edit wizard options — only sent to viewers who may edit. */
    formOptions?: StrategicPlanFormOptions | null;
}

const APPROVAL_VARIANT: Record<string, StatusVariant> = {
    approved: 'success',
    passed: 'success',
    voting_open: 'info',
    drafted: 'warning',
    on_agenda: 'info',
    stale: 'warning',
    not_passed: 'critical',
    waiting: 'neutral',
    superseded: 'neutral',
    archived: 'neutral',
};

const NONE = '__none';

const dateOnly = (value: string | null | undefined) =>
    (value ?? '').slice(0, 10);

/** A goal's progress is tracked once initiatives or a percentage are recorded. */
export function goalProgressTracked(goal: Pick<Goal, 'initiatives' | 'progress_pct'>) {
    return (goal.initiatives ?? []).length > 0 || Number(goal.progress_pct ?? 0) > 0;
}

function MeasureIcon({ status }: { status: string | null | undefined }) {
    switch (status) {
        case 'achieved':
        case 'complete':
        case 'completed':
            return <CheckCircle className="h-4 w-4 shrink-0 text-status-success" />;
        case 'in_progress':
        case 'on_track':
            return <Clock className="h-4 w-4 shrink-0 text-status-info" />;
        case 'at_risk':
        case 'delayed':
        case 'off_track':
        case 'missed':
        case 'blocked':
            return (
                <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
            );
        default:
            return <Circle className="h-4 w-4 shrink-0 text-muted-foreground" />;
    }
}

export default function StrategyShow({
    plan,
    approval,
    carriedResolutions = [],
    canEdit = false,
    canAddGoal = false,
    canApprove = false,
    canCreateVersion = false,
    canViewResolutions = false,
    formOptions = null,
}: Props) {
    const canOpenWizard = Boolean(canEdit && formOptions);
    const isApproved = ['approved', 'active'].includes(plan.status);
    const [editOpen, setEditOpen] = useDialogDeepLink(
        'edit',
        canOpenWizard,
        isApproved
            ? "This plan has been approved, so it can't be edited. Create a new version to change it."
            : "This version can't be edited.",
    );
    const [wizardStep, setWizardStep] = useState<StrategicPlanStepKey>('plan');
    const [isApproveOpen, setIsApproveOpen] = useState(false);
    const [isVersionOpen, setIsVersionOpen] = useState(false);

    const openWizard = (step: StrategicPlanStepKey) => {
        setWizardStep(step);
        setEditOpen(true);
    };

    const goals = plan.goals ?? [];
    const values = normaliseValues(plan.values);
    const pillarLabel = (pillar: string) =>
        formOptions?.pillars[pillar] ?? themeLabel(pillar);

    const groupedGoals = goals.reduce<Record<string, Goal[]>>((acc, goal) => {
        (acc[goal.pillar] ??= []).push(goal);
        return acc;
    }, {});

    const trackedGoals = goals.filter(goalProgressTracked);
    const averageProgress =
        trackedGoals.length > 0
            ? trackedGoals.reduce(
                  (sum, goal) => sum + Number(goal.progress_pct ?? 0),
                  0,
              ) / trackedGoals.length
            : 0;
    const achievedGoals = goals.filter(
        (goal) => goal.status === 'achieved',
    ).length;
    const measures = goals.flatMap((goal) => goal.key_results ?? []);
    const achievedMeasures = measures.filter(
        (measure) => measure.status === 'achieved',
    ).length;
    const measuresTracked = measures.some(
        (measure) => (measure.status ?? 'not_started') !== 'not_started',
    );
    const initiatives = goals.flatMap((goal) => goal.initiatives ?? []);
    const statusChip = governanceStatus('strategic_plan_status', plan.status);
    const period = `${formatDateOnly(dateOnly(plan.period_start))} – ${formatDateOnly(dateOnly(plan.period_end))}`;
    const goalsHref = `/governance/strategy/${plan.id}#goals`;
    const nextVersion = plan.version_number + 1;

    const scrollToApproval = () =>
        document
            .getElementById('board-approval')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategic plan', href: '/governance/strategy' },
                { title: plan.title, href: `/governance/strategy/${plan.id}` },
            ]}
        >
            <Head title={`${plan.title} — Strategic plan`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/strategy"
                        icon={Compass}
                        title={plan.title}
                        titleDusk="strategy-heading"
                        titleChip={
                            <PageHeaderStatusChip variant={statusChip.variant}>
                                {statusChip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            formOptions?.horizons[plan.planning_horizon] ??
                                humaniseHorizon(plan.planning_horizon),
                            period,
                            `Version ${plan.version_number}`,
                        ].join(' · ')}
                        actions={
                            <>
                                <PageHeaderGlassButton
                                    icon={History}
                                    onClick={() =>
                                        router.visit(
                                            `/governance/strategy/${plan.id}/changes`,
                                        )
                                    }
                                >
                                    See what changed
                                </PageHeaderGlassButton>
                                {canOpenWizard ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => openWizard('plan')}
                                    >
                                        Edit plan
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canOpenWizard && canAddGoal ? (
                                    <PageHeaderGlassButton
                                        icon={Plus}
                                        onClick={() => openWizard('goals')}
                                    >
                                        Add goal
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canApprove ? (
                                    <PageHeaderPrimaryButton
                                        icon={CheckCircle}
                                        onClick={() => setIsApproveOpen(true)}
                                    >
                                        Record board approval
                                    </PageHeaderPrimaryButton>
                                ) : canCreateVersion ? (
                                    <PageHeaderPrimaryButton
                                        icon={Copy}
                                        onClick={() => setIsVersionOpen(true)}
                                    >
                                        Create version {nextVersion}
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Goals"
                                    href={goalsHref}
                                    preserveScroll
                                >
                                    <PageHeaderMeterBig>
                                        {goals.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Across{' '}
                                        {Object.keys(groupedGoals).length}{' '}
                                        theme
                                        {Object.keys(groupedGoals).length === 1
                                            ? ''
                                            : 's'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Goal progress"
                                    value={
                                        trackedGoals.length > 0
                                            ? `${Math.round(averageProgress)}%`
                                            : undefined
                                    }
                                    href={goalsHref}
                                    preserveScroll
                                    tone={
                                        trackedGoals.length > 0 &&
                                        averageProgress >= 100
                                            ? 'success'
                                            : 'brand'
                                    }
                                >
                                    {trackedGoals.length > 0 ? (
                                        <>
                                            <PageHeaderMeterBar
                                                percent={averageProgress}
                                            />
                                            <PageHeaderMeterCaption>
                                                {achievedGoals} of{' '}
                                                {goals.length} goals achieved
                                            </PageHeaderMeterCaption>
                                        </>
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                {goals.length > 0
                                                    ? 'Not tracked'
                                                    : '—'}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {goals.length > 0
                                                    ? 'Progress not tracked yet'
                                                    : 'No goals yet'}
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Measures of success"
                                    value={
                                        measuresTracked
                                            ? `${achievedMeasures}/${measures.length}`
                                            : undefined
                                    }
                                    href={goalsHref}
                                    preserveScroll
                                >
                                    {measuresTracked ? (
                                        <>
                                            <PageHeaderMeterBar
                                                percent={
                                                    (achievedMeasures /
                                                        measures.length) *
                                                    100
                                                }
                                            />
                                            <PageHeaderMeterCaption>
                                                Measures met so far
                                            </PageHeaderMeterCaption>
                                        </>
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                {measures.length}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {measures.length > 0
                                                    ? 'Set · not tracked yet'
                                                    : 'None set yet'}
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Initiatives"
                                    href={goalsHref}
                                    preserveScroll
                                >
                                    <PageHeaderMeterBig>
                                        {initiatives.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Work delivering the goals
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Board approval"
                                    href={
                                        approval.resolution
                                            ? `/governance/resolutions/${approval.resolution.id}`
                                            : undefined
                                    }
                                    onClick={
                                        approval.resolution
                                            ? undefined
                                            : scrollToApproval
                                    }
                                    tone={
                                        APPROVAL_VARIANT[approval.key] ===
                                        'success'
                                            ? 'success'
                                            : APPROVAL_VARIANT[approval.key] ===
                                                'critical'
                                              ? 'critical'
                                              : APPROVAL_VARIANT[
                                                      approval.key
                                                  ] === 'warning'
                                                ? 'warning'
                                                : 'brand'
                                    }
                                    ariaLabel={
                                        approval.resolution
                                            ? `Open resolution: ${approval.resolution.title}`
                                            : 'View where the board approval stands'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {statusChip.label}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {approval.resolution?.title ??
                                            approval.label}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <Card id="board-approval" className="scroll-mt-5">
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Board approval
                            </CardTitle>
                            <CardDescription>
                                Approved by the board when a resolution naming
                                this version passes.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    variant={
                                        APPROVAL_VARIANT[approval.key] ??
                                        'neutral'
                                    }
                                >
                                    {approval.label}
                                </StatusBadge>
                                {approval.resolution ? (
                                    <>
                                        <Link
                                            href={`/governance/resolutions/${approval.resolution.id}`}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {approval.resolution.title}
                                        </Link>
                                        {approval.resolution.reference ? (
                                            <span className="text-caption">
                                                {refSuffix(
                                                    approval.resolution
                                                        .reference,
                                                )}
                                            </span>
                                        ) : null}
                                    </>
                                ) : null}
                            </div>
                            <p className="text-subtle">
                                {approval.detail}
                                {approval.key === 'waiting' &&
                                canViewResolutions ? (
                                    <>
                                        {' '}
                                        <Link
                                            href="/governance/resolutions"
                                            className="font-medium text-primary hover:underline"
                                        >
                                            Go to Resolutions
                                        </Link>
                                    </>
                                ) : null}
                            </p>
                            {isApproved ? (
                                <p className="text-subtle">
                                    This version can&apos;t be edited. To
                                    change the plan, create a new version and
                                    put it to the board.
                                </p>
                            ) : null}
                            {plan.supersedes ? (
                                <p className="text-subtle">
                                    Replaces{' '}
                                    <Link
                                        href={`/governance/strategy/${plan.supersedes.id}`}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {plan.supersedes.title} (version{' '}
                                        {plan.supersedes.version_number})
                                    </Link>
                                </p>
                            ) : null}
                            {plan.version_notes ? (
                                <p className="text-subtle">
                                    Why this version: {plan.version_notes}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Eye className="h-4 w-4 text-primary" />
                                    Vision
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {plan.vision_statement ? (
                                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                        {plan.vision_statement}
                                    </p>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Eye}
                                        title="Vision not written yet."
                                        description={
                                            canOpenWizard
                                                ? 'Add it with Edit plan.'
                                                : undefined
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Flag className="h-4 w-4 text-primary" />
                                    Mission
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {plan.mission_statement ? (
                                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                        {plan.mission_statement}
                                    </p>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Flag}
                                        title="Mission not written yet."
                                        description={
                                            canOpenWizard
                                                ? 'Add it with Edit plan.'
                                                : undefined
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {values.length > 0 ? (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Values
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                    {values.map((value, index) => (
                                        <div
                                            key={`${value.value}-${index}`}
                                            className="rounded-lg bg-muted p-4 text-center"
                                        >
                                            <p className="font-semibold text-foreground">
                                                {value.value}
                                            </p>
                                            {value.description ? (
                                                <p className="text-caption mt-1">
                                                    {value.description}
                                                </p>
                                            ) : null}
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    <section
                        id="goals"
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <h2 className="text-section-title">Strategic goals</h2>

                        {goals.length === 0 ? (
                            <EmptyState
                                icon={Target}
                                title="No goals yet"
                                description="Goals turn the plan's direction into things the organisation commits to achieve."
                                action={
                                    canOpenWizard && canAddGoal ? (
                                        <Button
                                            size="sm"
                                            onClick={() => openWizard('goals')}
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            Add goal
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            Object.entries(groupedGoals).map(
                                ([pillar, pillarGoals]) => (
                                    <Card key={pillar}>
                                        <CardHeader className="border-b">
                                            <CardTitle className="text-section-title flex items-center gap-2">
                                                <Target className="h-4 w-4 text-primary" />
                                                {pillarLabel(pillar)}
                                            </CardTitle>
                                            <CardDescription>
                                                Theme · {pillarGoals.length}{' '}
                                                goal
                                                {pillarGoals.length === 1
                                                    ? ''
                                                    : 's'}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="flex flex-col gap-5 pt-4">
                                            {pillarGoals.map((goal) => (
                                                <GoalBlock
                                                    key={goal.id}
                                                    goal={goal}
                                                />
                                            ))}
                                        </CardContent>
                                    </Card>
                                ),
                            )
                        )}
                    </section>
                </div>
            </PageLayout>

            {canApprove ? (
                <ApproveDialog
                    open={isApproveOpen}
                    onClose={() => setIsApproveOpen(false)}
                    plan={plan}
                    resolutions={carriedResolutions}
                />
            ) : null}

            {canCreateVersion ? (
                <VersionDialog
                    open={isVersionOpen}
                    onClose={() => setIsVersionOpen(false)}
                    plan={plan}
                />
            ) : null}

            {canOpenWizard && formOptions ? (
                <StrategicPlanWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={formOptions}
                    initialStep={wizardStep}
                    plan={{
                        id: plan.id,
                        title: plan.title,
                        planning_horizon: plan.planning_horizon,
                        period_start: plan.period_start,
                        period_end: plan.period_end,
                        vision_statement: plan.vision_statement,
                        mission_statement: plan.mission_statement,
                        values: plan.values,
                        status: plan.status,
                        version_number: plan.version_number,
                        goals: goals.map((goal) => ({
                            id: goal.id,
                            title: goal.title,
                            pillar: goal.pillar,
                            timeframe: goal.timeframe,
                        })),
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

function GoalBlock({ goal }: { goal: Goal }) {
    const tracked = goalProgressTracked(goal);
    const chip = governanceStatus('goal_status', goal.status ?? 'not_started');
    const progress = Math.round(Number(goal.progress_pct ?? 0));

    return (
        <div className="border-l-4 border-border pl-4">
            <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="font-semibold text-foreground">
                        {goal.title}
                    </h3>
                    <p className="text-subtle">{goal.description}</p>
                    <div className="text-caption mt-1 flex flex-wrap items-center gap-3">
                        {goal.lead_executive ? (
                            <span className="flex items-center gap-1">
                                <UserCheck className="h-3.5 w-3.5" />
                                Lead: {goal.lead_executive.name}
                            </span>
                        ) : null}
                        {goal.roadmap_initiative ? (
                            <EntityChip outline>
                                Roadmap: {goal.roadmap_initiative.title}
                            </EntityChip>
                        ) : null}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {goal.timeframe ? (
                        <EntityChip>{formatTimeframe(goal.timeframe)}</EntityChip>
                    ) : null}
                    <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                </div>
            </div>

            <div className="mt-2 mb-3">
                {tracked ? (
                    <>
                        <div className="text-caption mb-1 flex items-center justify-between">
                            <span>Progress</span>
                            <span className="font-medium">{progress}%</span>
                        </div>
                        <Progress value={progress} className="h-1.5" />
                    </>
                ) : (
                    <p className="text-caption">Progress not tracked yet</p>
                )}
            </div>

            {(goal.key_results ?? []).length > 0 ? (
                <div className="mt-3 flex flex-col gap-2">
                    <p className="text-sm font-medium text-foreground">
                        Measures of success
                    </p>
                    {(goal.key_results ?? []).map((measure, index) => (
                        <div key={index} className="flex items-center gap-2 text-sm">
                            <MeasureIcon status={measure.status} />
                            <span
                                className={
                                    measure.status === 'achieved'
                                        ? 'text-muted-foreground line-through'
                                        : undefined
                                }
                            >
                                {measure.result}
                            </span>
                            <span className="sr-only">
                                ({goalStatusLabel(measure.status ?? 'not_started')})
                            </span>
                        </div>
                    ))}
                </div>
            ) : null}

            {(goal.initiatives ?? []).length > 0 ? (
                <div className="mt-4">
                    <p className="mb-2 text-sm font-medium text-foreground">
                        Initiatives
                    </p>
                    <div className="flex flex-col gap-3">
                        {goal.initiatives.map((initiative) => {
                            const allocated =
                                Number(initiative.budget_allocated) || 0;
                            const spent = Number(initiative.budget_spent) || 0;
                            const initiativeChip = governanceStatus(
                                'goal_status',
                                initiative.status,
                            );
                            return (
                                <div
                                    key={initiative.id}
                                    className="rounded-lg bg-muted p-3"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <Rocket className="h-4 w-4 text-muted-foreground" />
                                                <p className="text-sm font-medium">
                                                    {initiative.name}
                                                </p>
                                            </div>
                                            <p className="text-caption mt-1">
                                                {initiative.owner?.name ||
                                                    'No owner yet'}
                                                {initiative.target_completion
                                                    ? ` · Due ${formatDateOnly(dateOnly(initiative.target_completion))}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <StatusBadge
                                            variant={initiativeChip.variant}
                                        >
                                            {initiativeChip.label}
                                        </StatusBadge>
                                    </div>
                                    {allocated > 0 ? (
                                        <div className="mt-2">
                                            <div className="text-caption mb-1 flex items-center justify-between">
                                                <span>Budget</span>
                                                <span>
                                                    {formatNzd(spent)} spent of{' '}
                                                    {formatNzd(allocated)}
                                                </span>
                                            </div>
                                            <Progress
                                                value={Math.min(
                                                    100,
                                                    (spent / allocated) * 100,
                                                )}
                                                className="h-1"
                                            />
                                        </div>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function ApproveDialog({
    open,
    onClose,
    plan,
    resolutions,
}: {
    open: boolean;
    onClose: () => void;
    plan: StrategicPlan;
    resolutions: CarriedResolution[];
}) {
    const form = useForm({
        resolution_id:
            resolutions.length === 1 ? String(resolutions[0].id) : NONE,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.resolution_id === NONE) return;
        form.transform((data) => ({
            resolution_id: Number(data.resolution_id),
        }));
        form.post(`/governance/strategy/${plan.id}/approve`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.clearErrors();
                    onClose();
                }
            }}
        >
            <DialogContent style={{ maxWidth: 'min(92vw, 520px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Record board approval</DialogTitle>
                        <DialogDescription>
                            Record that the board passed a resolution approving
                            version {plan.version_number} of this plan. It
                            becomes the approved strategic plan
                            {plan.supersedes
                                ? `, replacing version ${plan.supersedes.version_number}`
                                : ''}
                            , and can no longer be edited.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        <Label>Resolution the board passed</Label>
                        <SelectInput
                            value={form.data.resolution_id}
                            onChange={(value) =>
                                form.setData('resolution_id', value)
                            }
                            placeholder="Choose the resolution"
                            ariaLabel="Resolution the board passed"
                            options={[
                                { value: NONE, label: 'Choose the resolution' },
                                ...resolutions.map((resolution) => ({
                                    value: String(resolution.id),
                                    label: `${resolution.title}${resolution.resolution_reference ? ` · ${refSuffix(resolution.resolution_reference)}` : ''}`,
                                })),
                            ]}
                        />
                        <InputError message={form.errors.resolution_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.data.resolution_id === NONE ||
                                form.processing
                            }
                        >
                            {form.processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : null}
                            Record approval
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function VersionDialog({
    open,
    onClose,
    plan,
}: {
    open: boolean;
    onClose: () => void;
    plan: StrategicPlan;
}) {
    const form = useForm({ version_notes: '' });
    const nextVersion = plan.version_number + 1;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!form.data.version_notes.trim()) return;
        form.post(`/governance/strategy/${plan.id}/version`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) {
                    form.reset();
                    onClose();
                }
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.clearErrors();
                    onClose();
                }
            }}
        >
            <DialogContent style={{ maxWidth: 'min(92vw, 520px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Create version {nextVersion}</DialogTitle>
                        <DialogDescription>
                            Create version {nextVersion}: a copy of this plan
                            you can update and put to the board. This version
                            stays approved until the board approves the new
                            one.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="version-notes">
                            Why is a new version needed?{' '}
                            <span className="text-status-critical">*</span>
                        </Label>
                        <Textarea
                            id="version-notes"
                            value={form.data.version_notes}
                            onChange={(e) =>
                                form.setData('version_notes', e.target.value)
                            }
                            maxLength={500}
                            placeholder="e.g. Yearly refresh after the 2026 review."
                            rows={3}
                        />
                        <InputError message={form.errors.version_notes} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                !form.data.version_notes.trim() ||
                                form.processing
                            }
                        >
                            {form.processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : null}
                            Create version {nextVersion}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
