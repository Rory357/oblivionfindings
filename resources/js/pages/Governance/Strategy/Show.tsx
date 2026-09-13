import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import InputError from '@/components/input-error';
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
import { Badge } from '@/components/ui/badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    Compass,
    GitBranch,
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
    humaniseHorizon,
    normaliseValues,
    planStatusLabel,
    planStatusVariant,
    type PlanValue,
    type StrategicPlanFormOptions,
    type StrategicPlanStepKey,
} from './_dialogs';

interface CarriedResolution {
    id: number;
    resolution_reference: string;
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
    status: string;
    lead_executive?: { name: string } | null;
    origin_goal_id?: number | null;
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
    approval_resolution: {
        id: number;
        resolution_reference: string;
        outcome: string;
    } | null;
    approved_by_board_at?: string | null;
    goals: Goal[];
    supersedes?: { id: number; title: string; version_number: number } | null;
}

interface Props {
    plan: StrategicPlan;
    carriedResolutions?: CarriedResolution[];
    canEdit?: boolean;
    canAddGoal?: boolean;
    canApprove?: boolean;
    canCreateVersion?: boolean;
    /** Edit wizard options — only sent to viewers who may edit. */
    formOptions?: StrategicPlanFormOptions | null;
}

const PILLAR_LABELS: Record<string, string> = {
    safety: 'Safety',
    quality: 'Quality',
    people: 'People',
    finance: 'Finance',
    compliance: 'Compliance',
    it_resilience: 'IT resilience',
};

const humanise = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');

function workStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'achieved':
        case 'completed':
            return 'success';
        case 'in_progress':
        case 'on_track':
            return 'info';
        case 'at_risk':
        case 'on_hold':
        case 'delayed':
            return 'warning';
        case 'blocked':
        case 'off_track':
            return 'critical';
        default:
            return 'neutral';
    }
}

const statusFilterFor = (status: string) =>
    ({
        draft: 'draft',
        review: 'draft',
        approved: 'approved',
        active: 'approved',
        superseded: 'superseded',
        archived: 'archived',
        completed: 'archived',
    })[status] ?? null;

const formatNzd = (amount: number | string | null | undefined) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(Number(amount) || 0);

const dateOnly = (value: string | null | undefined) =>
    (value ?? '').slice(0, 10);

export default function StrategyShow({
    plan,
    carriedResolutions = [],
    canEdit = false,
    canAddGoal = false,
    canApprove = false,
    canCreateVersion = false,
    formOptions = null,
}: Props) {
    const canOpenWizard = Boolean(canEdit && formOptions);
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canOpenWizard);
    const [wizardStep, setWizardStep] = useState<StrategicPlanStepKey>('plan');
    const [isApproveOpen, setIsApproveOpen] = useState(false);
    const [isVersionOpen, setIsVersionOpen] = useState(false);

    const approveForm = useForm({ resolution_id: '' });
    const versionForm = useForm({ version_notes: '' });

    const openWizard = (step: StrategicPlanStepKey) => {
        setWizardStep(step);
        setEditOpen(true);
    };

    const handleApprove = (e: FormEvent) => {
        e.preventDefault();
        if (!approveForm.data.resolution_id) return;
        approveForm.transform((data) => ({
            resolution_id: Number(data.resolution_id),
        }));
        approveForm.post(`/governance/strategy/${plan.id}/approve`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsApproveOpen(false);
                approveForm.reset();
            },
        });
    };

    const handleCreateVersion = (e: FormEvent) => {
        e.preventDefault();
        if (!versionForm.data.version_notes.trim()) return;
        versionForm.post(`/governance/strategy/${plan.id}/version`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsVersionOpen(false);
                versionForm.reset();
            },
        });
    };

    const goals = plan.goals ?? [];
    const values = normaliseValues(plan.values);
    const pillarLabel = (pillar: string) =>
        formOptions?.pillars[pillar] ??
        PILLAR_LABELS[pillar] ??
        humanise(pillar);

    const groupedGoals = goals.reduce<Record<string, Goal[]>>((acc, goal) => {
        (acc[goal.pillar] ??= []).push(goal);
        return acc;
    }, {});

    const averageProgress =
        goals.length > 0
            ? goals.reduce(
                  (sum, goal) => sum + Number(goal.progress_pct ?? 0),
                  0,
              ) / goals.length
            : 0;
    const achievedGoals = goals.filter(
        (goal) => goal.status === 'achieved',
    ).length;
    const keyResults = goals.flatMap((goal) => goal.key_results ?? []);
    const achievedResults = keyResults.filter(
        (kr) => kr.status === 'achieved',
    ).length;
    const initiatives = goals.flatMap((goal) => goal.initiatives ?? []);
    const statusFilter = statusFilterFor(plan.status);
    const period = `${formatDateOnly(dateOnly(plan.period_start))} – ${formatDateOnly(dateOnly(plan.period_end))}`;
    const goalsHref = `/governance/strategy/${plan.id}#goals`;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategic plans', href: '/governance/strategy' },
                { title: plan.title, href: `/governance/strategy/${plan.id}` },
            ]}
        >
            <Head title={plan.title} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/strategy"
                        icon={Compass}
                        title={plan.title}
                        titleDusk="strategy-heading"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={planStatusVariant(plan.status)}
                            >
                                {planStatusLabel(plan.status)}
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
                                    View changes
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
                                        Approve plan
                                    </PageHeaderPrimaryButton>
                                ) : canCreateVersion ? (
                                    <PageHeaderPrimaryButton
                                        icon={GitBranch}
                                        onClick={() => setIsVersionOpen(true)}
                                    >
                                        New version
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
                                        pillar
                                        {Object.keys(groupedGoals).length === 1
                                            ? ''
                                            : 's'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Goal progress"
                                    value={
                                        goals.length > 0
                                            ? `${Math.round(averageProgress)}%`
                                            : undefined
                                    }
                                    href={goalsHref}
                                    preserveScroll
                                    tone={
                                        goals.length > 0 &&
                                        averageProgress >= 100
                                            ? 'success'
                                            : 'brand'
                                    }
                                >
                                    {goals.length > 0 ? (
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
                                                —
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                No goals yet
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Key results"
                                    value={
                                        keyResults.length > 0
                                            ? `${achievedResults}/${keyResults.length}`
                                            : undefined
                                    }
                                    href={goalsHref}
                                    preserveScroll
                                >
                                    {keyResults.length > 0 ? (
                                        <>
                                            <PageHeaderMeterBar
                                                percent={
                                                    (achievedResults /
                                                        keyResults.length) *
                                                    100
                                                }
                                            />
                                            <PageHeaderMeterCaption>
                                                Key results achieved
                                            </PageHeaderMeterCaption>
                                        </>
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                0
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                No key results set
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
                                        Delivering the goals
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Approval"
                                    href={
                                        statusFilter
                                            ? `/governance/strategy?status=${statusFilter}`
                                            : '/governance/strategy'
                                    }
                                    tone={
                                        planStatusVariant(plan.status) ===
                                        'success'
                                            ? 'success'
                                            : planStatusVariant(plan.status) ===
                                                'warning'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel={`View ${planStatusLabel(plan.status).toLowerCase()} plans`}
                                >
                                    <PageHeaderMeterBig>
                                        {planStatusLabel(plan.status)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {plan.approval_resolution
                                            ? `Resolution ${plan.approval_resolution.resolution_reference}`
                                            : 'No board resolution yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Vision
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                    {plan.vision_statement || '—'}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Mission
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                    {plan.mission_statement || '—'}
                                </p>
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

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Board approval
                            </CardTitle>
                            <CardDescription>
                                A plan is approved only by a carried resolution
                                that was explicitly bound to this version before
                                voting.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    variant={planStatusVariant(plan.status)}
                                >
                                    {planStatusLabel(plan.status)}
                                </StatusBadge>
                                {plan.approval_resolution ? (
                                    <Link
                                        href={`/governance/resolutions/${plan.approval_resolution.id}`}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        Resolution{' '}
                                        {
                                            plan.approval_resolution
                                                .resolution_reference
                                        }{' '}
                                        ·{' '}
                                        {humanise(
                                            plan.approval_resolution.outcome ??
                                                '',
                                        )}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Not yet approved by the board
                                    </span>
                                )}
                            </div>
                            {plan.supersedes ? (
                                <p className="text-muted-foreground">
                                    Supersedes{' '}
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
                                <p className="text-muted-foreground">
                                    Version notes: {plan.version_notes}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <section
                        id="goals"
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <h2 className="text-section-title">Strategic goals</h2>

                        {goals.length === 0 ? (
                            <EmptyState
                                icon={Target}
                                title="No strategic goals yet"
                                description="Goals turn the plan’s direction into measurable commitments."
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
                                                {pillarGoals.length} goal
                                                {pillarGoals.length === 1
                                                    ? ''
                                                    : 's'}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="flex flex-col gap-5 pt-4">
                                            {pillarGoals.map((goal) => (
                                                <div
                                                    key={goal.id}
                                                    className="border-l-4 border-border pl-4"
                                                >
                                                    <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <h3 className="font-semibold text-foreground">
                                                                {goal.title}
                                                            </h3>
                                                            <p className="text-sm text-muted-foreground">
                                                                {
                                                                    goal.description
                                                                }
                                                            </p>
                                                            <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                                {goal.lead_executive ? (
                                                                    <span className="flex items-center gap-1">
                                                                        <UserCheck className="h-3.5 w-3.5" />
                                                                        Lead:{' '}
                                                                        {
                                                                            goal
                                                                                .lead_executive
                                                                                .name
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                                {goal.roadmap_initiative ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Roadmap:{' '}
                                                                        {
                                                                            goal
                                                                                .roadmap_initiative
                                                                                .title
                                                                        }
                                                                    </Badge>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            {goal.timeframe ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    {
                                                                        goal.timeframe
                                                                    }
                                                                </Badge>
                                                            ) : null}
                                                            <StatusBadge
                                                                variant={workStatusVariant(
                                                                    goal.status,
                                                                )}
                                                            >
                                                                {humanise(
                                                                    goal.status,
                                                                )}
                                                            </StatusBadge>
                                                        </div>
                                                    </div>

                                                    <div className="mt-2 mb-3">
                                                        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                            <span>
                                                                Progress
                                                            </span>
                                                            <span className="font-medium">
                                                                {Math.round(
                                                                    Number(
                                                                        goal.progress_pct ??
                                                                            0,
                                                                    ),
                                                                )}
                                                                %
                                                            </span>
                                                        </div>
                                                        <Progress
                                                            value={Number(
                                                                goal.progress_pct ??
                                                                    0,
                                                            )}
                                                            className="h-1.5"
                                                        />
                                                    </div>

                                                    {(goal.key_results ?? [])
                                                        .length > 0 ? (
                                                        <div className="mt-3 flex flex-col gap-2">
                                                            <p className="text-sm font-medium text-foreground">
                                                                Key results
                                                            </p>
                                                            {(
                                                                goal.key_results ??
                                                                []
                                                            ).map(
                                                                (kr, index) => (
                                                                    <div
                                                                        key={
                                                                            index
                                                                        }
                                                                        className="flex items-center gap-2 text-sm"
                                                                    >
                                                                        {kr.status ===
                                                                        'achieved' ? (
                                                                            <CheckCircle className="h-4 w-4 text-status-success" />
                                                                        ) : kr.status ===
                                                                          'in_progress' ? (
                                                                            <Clock className="h-4 w-4 text-status-info" />
                                                                        ) : (
                                                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                                                        )}
                                                                        <span
                                                                            className={
                                                                                kr.status ===
                                                                                'achieved'
                                                                                    ? 'text-muted-foreground line-through'
                                                                                    : undefined
                                                                            }
                                                                        >
                                                                            {
                                                                                kr.result
                                                                            }
                                                                        </span>
                                                                        <span className="sr-only">
                                                                            (
                                                                            {humanise(
                                                                                kr.status ??
                                                                                    'not_started',
                                                                            )}
                                                                            )
                                                                        </span>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    ) : null}

                                                    {(goal.initiatives ?? [])
                                                        .length > 0 ? (
                                                        <div className="mt-4">
                                                            <p className="mb-2 text-sm font-medium text-foreground">
                                                                Initiatives
                                                            </p>
                                                            <div className="flex flex-col gap-3">
                                                                {goal.initiatives.map(
                                                                    (
                                                                        initiative,
                                                                    ) => {
                                                                        const allocated =
                                                                            Number(
                                                                                initiative.budget_allocated,
                                                                            ) ||
                                                                            0;
                                                                        const spent =
                                                                            Number(
                                                                                initiative.budget_spent,
                                                                            ) ||
                                                                            0;
                                                                        return (
                                                                            <div
                                                                                key={
                                                                                    initiative.id
                                                                                }
                                                                                className="rounded-lg bg-muted p-3"
                                                                            >
                                                                                <div className="flex items-start justify-between gap-3">
                                                                                    <div className="min-w-0">
                                                                                        <div className="flex items-center gap-2">
                                                                                            <Rocket className="h-4 w-4 text-muted-foreground" />
                                                                                            <p className="text-sm font-medium">
                                                                                                {
                                                                                                    initiative.name
                                                                                                }
                                                                                            </p>
                                                                                        </div>
                                                                                        <p className="text-caption mt-1">
                                                                                            {initiative
                                                                                                .owner
                                                                                                ?.name ||
                                                                                                'No owner'}
                                                                                            {initiative.target_completion
                                                                                                ? ` · Due ${formatDateOnly(dateOnly(initiative.target_completion))}`
                                                                                                : ''}
                                                                                        </p>
                                                                                    </div>
                                                                                    <StatusBadge
                                                                                        variant={workStatusVariant(
                                                                                            initiative.status,
                                                                                        )}
                                                                                    >
                                                                                        {humanise(
                                                                                            initiative.status,
                                                                                        )}
                                                                                    </StatusBadge>
                                                                                </div>
                                                                                <div className="mt-2">
                                                                                    <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                                                        <span>
                                                                                            Budget
                                                                                        </span>
                                                                                        <span>
                                                                                            {formatNzd(
                                                                                                spent,
                                                                                            )}{' '}
                                                                                            of{' '}
                                                                                            {formatNzd(
                                                                                                allocated,
                                                                                            )}
                                                                                        </span>
                                                                                    </div>
                                                                                    <Progress
                                                                                        value={
                                                                                            allocated >
                                                                                            0
                                                                                                ? Math.min(
                                                                                                      100,
                                                                                                      (spent /
                                                                                                          allocated) *
                                                                                                          100,
                                                                                                  )
                                                                                                : 0
                                                                                        }
                                                                                        className="h-1"
                                                                                    />
                                                                                </div>
                                                                            </div>
                                                                        );
                                                                    },
                                                                )}
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                </div>
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
                <Dialog
                    open={isApproveOpen}
                    onOpenChange={(open) => {
                        setIsApproveOpen(open);
                        if (!open) approveForm.clearErrors();
                    }}
                >
                    <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                        <form onSubmit={handleApprove}>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <CheckCircle className="h-4 w-4 text-primary" />
                                    Approve strategic plan
                                </DialogTitle>
                                <DialogDescription>
                                    Approve version {plan.version_number} with
                                    the carried board resolution bound to it.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="py-4">
                                {carriedResolutions.length === 0 ? (
                                    <div className="flex items-start gap-3 rounded-lg border border-status-warning/40 bg-status-warning-bg p-3 text-status-warning">
                                        <AlertTriangle className="h-5 w-5 shrink-0" />
                                        <div>
                                            <p className="text-sm font-semibold">
                                                No bound resolution is ready
                                            </p>
                                            <p className="mt-1 text-xs text-foreground">
                                                Bind a decision paper to this
                                                plan while it is a draft; once
                                                the board carries it, it appears
                                                here. Only unused resolutions
                                                bound to this plan are listed.
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor="resolution-select">
                                            Carried resolution
                                        </Label>
                                        <Select
                                            value={
                                                approveForm.data
                                                    .resolution_id || undefined
                                            }
                                            onValueChange={(value) =>
                                                approveForm.setData(
                                                    'resolution_id',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="resolution-select"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose the bound resolution" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {carriedResolutions.map(
                                                    (res) => (
                                                        <SelectItem
                                                            key={res.id}
                                                            value={String(
                                                                res.id,
                                                            )}
                                                        >
                                                            {
                                                                res.resolution_reference
                                                            }{' '}
                                                            — {res.title}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                approveForm.errors.resolution_id
                                            }
                                        />
                                    </div>
                                )}
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setIsApproveOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        !approveForm.data.resolution_id ||
                                        carriedResolutions.length === 0 ||
                                        approveForm.processing
                                    }
                                >
                                    {approveForm.processing ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : null}
                                    Approve plan
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            ) : null}

            {canCreateVersion ? (
                <Dialog
                    open={isVersionOpen}
                    onOpenChange={(open) => {
                        setIsVersionOpen(open);
                        if (!open) versionForm.clearErrors();
                    }}
                >
                    <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                        <form onSubmit={handleCreateVersion}>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <GitBranch className="h-4 w-4 text-primary" />
                                    Create new plan version
                                </DialogTitle>
                                <DialogDescription>
                                    Create version {plan.version_number + 1}{' '}
                                    branched from this plan. Goals and
                                    initiatives keep their lineage for change
                                    comparisons.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="flex flex-col gap-2 py-4">
                                <Label htmlFor="version-notes">
                                    Version notes
                                </Label>
                                <Textarea
                                    id="version-notes"
                                    value={versionForm.data.version_notes}
                                    onChange={(e) =>
                                        versionForm.setData(
                                            'version_notes',
                                            e.target.value,
                                        )
                                    }
                                    required
                                    maxLength={500}
                                    placeholder="Why is a new version needed? e.g. Annual refresh after the 2026 review."
                                    rows={3}
                                />
                                <InputError
                                    message={versionForm.errors.version_notes}
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setIsVersionOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        !versionForm.data.version_notes.trim() ||
                                        versionForm.processing
                                    }
                                >
                                    {versionForm.processing ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : null}
                                    Create version
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
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
