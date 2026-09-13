import { useState } from 'react';
import { PageHero, PageLayout } from '@/components/page';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    Compass,
    History,
    Plus,
    Rocket,
    Target,
    UserCheck,
} from 'lucide-react';

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
    budget_allocated: number;
    budget_spent: number;
    start_date: string;
    target_completion: string;
    status: string;
    owner: { name: string } | null;
}

interface Goal {
    id: number;
    pillar: string;
    timeframe: string;
    title: string;
    description: string;
    progress_pct?: number | string;
    key_results: Array<{ result: string; status: string }>;
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
    vision_statement: string;
    mission_statement: string;
    values: Array<{ value: string; description?: string }>;
    status: string;
    version_number: number;
    approval_resolution: {
        resolution_reference: string;
        outcome: string;
    } | null;
    goals: Goal[];
    supersedes?: { id: number; title: string; version_number: number } | null;
}

interface Props extends PageProps {
    plan: StrategicPlan;
    carriedResolutions?: CarriedResolution[];
}

export default function StrategyShow({ auth, plan, carriedResolutions = [] }: Props) {
    const [isApproveOpen, setIsApproveOpen] = useState(false);
    const [selectedResolutionId, setSelectedResolutionId] = useState<string>('');
    const [isVersionOpen, setIsVersionOpen] = useState(false);
    const [versionNotes, setVersionNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const handleApprove = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedResolutionId) return;
        setSubmitting(true);
        router.post(
            `/governance/strategy/${plan.id}/approve`,
            { resolution_id: Number(selectedResolutionId) },
            {
                onFinish: () => {
                    setSubmitting(false);
                    setIsApproveOpen(false);
                },
            },
        );
    };

    const handleCreateVersion = (e: React.FormEvent) => {
        e.preventDefault();
        if (!versionNotes.trim()) return;
        setSubmitting(true);
        router.post(
            `/governance/strategy/${plan.id}/version`,
            { version_notes: versionNotes.trim() },
            {
                onFinish: () => {
                    setSubmitting(false);
                    setIsVersionOpen(false);
                    setVersionNotes('');
                },
            },
        );
    };
    const getPillarLabel = (pillar: string) => {
        const labels: Record<string, string> = {
            safety: 'Safety',
            quality: 'Quality',
            people: 'People',
            finance: 'Finance',
            compliance: 'Compliance',
            it_resilience: 'IT Resilience',
        };
        return labels[pillar] || pillar;
    };

    const getPillarColor = (pillar: string) => {
        const colors: Record<string, string> = {
            safety: 'bg-status-critical-bg text-status-critical border-status-critical/30',
            quality: 'bg-status-info-bg text-status-info border-status-info/30',
            people: 'bg-status-success-bg text-status-success border-status-success/30',
            finance:
                'bg-status-warning-bg text-status-warning border-status-warning/30',
            compliance: 'bg-primary/10 text-primary border-primary',
            it_resilience:
                'bg-status-info-bg text-status-info border-status-info/30',
        };
        return colors[pillar] || 'bg-muted text-foreground border-border';
    };

    const getStatusColor = (status: string) => governanceStatusColor(status);

    const groupGoalsByPillar = () => {
        const grouped: Record<string, Goal[]> = {};
        plan.goals.forEach((goal) => {
            if (!grouped[goal.pillar]) {
                grouped[goal.pillar] = [];
            }
            grouped[goal.pillar].push(goal);
        });
        return grouped;
    };

    const calculateInitiativeProgress = (initiative: Initiative) => {
        if (initiative.budget_allocated === 0) return 0;
        return Math.round(
            (initiative.budget_spent / initiative.budget_allocated) * 100,
        );
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategy', href: '/governance/strategy' },
                { title: 'Plan', href: `/governance/strategy/${plan.id}` },
            ]}
        >
            <Head title={plan.title} />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/strategy"
                        icon={Compass}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="strategy-heading"
                            >
                                {plan.title}
                            </span>
                        }
                        description={`${plan.period_start} to ${plan.period_end}`}
                        stats={[
                            { label: 'Status', value: plan.status },
                            { label: 'Goals', value: plan.goals.length },
                            {
                                label: 'Horizon',
                                value: plan.planning_horizon.replace('_', ' '),
                            },
                            {
                                label: 'Version',
                                value: `v${plan.version_number}`,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {plan.planning_horizon.replace('_', ' ')}{' '}
                                    Plan
                                </Badge>
                                <Badge className={getStatusColor(plan.status)}>
                                    {plan.status}
                                </Badge>
                                <Badge variant="outline">
                                    v{plan.version_number}
                                </Badge>
                                <Link href={`/governance/strategy/${plan.id}/changes`}>
                                    <Button variant="outline" size="sm">
                                        <History className="mr-1.5 h-4 w-4" />
                                        View Changes
                                    </Button>
                                </Link>
                                {(plan.status === 'draft' || plan.status === 'review') && (
                                    <Button size="sm" onClick={() => setIsApproveOpen(true)}>
                                        <CheckCircle className="mr-1.5 h-4 w-4" />
                                        Approve Plan
                                    </Button>
                                )}
                                {plan.status === 'approved' && (
                                    <Button size="sm" variant="outline" onClick={() => setIsVersionOpen(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Version
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
                {/* Vision & Mission */}
                <div className="mb-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                    <Card className="border-primary bg-primary/10">
                        <CardHeader>
                            <CardTitle className="text-primary">
                                Vision
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-lg text-primary italic">
                                {plan.vision_statement}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="border-primary bg-primary/10">
                        <CardHeader>
                            <CardTitle className="text-primary">
                                Mission
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-primary">
                                {plan.mission_statement}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Values */}
                {plan.values.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Our Values</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                                {plan.values.map((value, index) => (
                                    <div
                                        key={index}
                                        className="rounded-lg bg-muted p-4 text-center"
                                    >
                                        <p className="font-semibold text-foreground">
                                            {value.value}
                                        </p>
                                        {value.description && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {value.description}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Approval Status */}
                {plan.approval_resolution && (
                    <Card className="mb-6 border-status-success/30 bg-status-success-bg">
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <CheckCircle className="h-6 w-6 text-status-success" />
                                <div>
                                    <p className="font-medium text-status-success">
                                        Board Approved
                                    </p>
                                    <p className="text-sm text-status-success">
                                        Resolution{' '}
                                        {
                                            plan.approval_resolution
                                                .resolution_reference
                                        }{' '}
                                        - {plan.approval_resolution.outcome}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Strategic Goals by Pillar */}
                <div className="space-y-6">
                    <h2 className="text-xl font-bold text-foreground">
                        Strategic Goals
                    </h2>

                    {Object.entries(groupGoalsByPillar()).map(
                        ([pillar, goals]) => (
                            <Card key={pillar}>
                                <CardHeader
                                    className={cn(
                                        'border-b',
                                        getPillarColor(pillar),
                                    )}
                                >
                                    <CardTitle className="flex items-center gap-2">
                                        <Target className="h-5 w-5" />
                                        {getPillarLabel(pillar)}
                                    </CardTitle>
                                    <CardDescription>
                                        {goals.length} goal
                                        {goals.length !== 1 ? 's' : ''}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="pt-4">
                                    <div className="space-y-6">
                                        {goals.map((goal) => (
                                            <div
                                                key={goal.id}
                                                className="border-l-4 border-border pl-4"
                                            >
                                                <div className="mb-2 flex items-start justify-between">
                                                    <div>
                                                        <h4 className="font-semibold text-foreground">
                                                            {goal.title}
                                                        </h4>
                                                        <p className="text-sm text-muted-foreground">
                                                            {goal.description}
                                                        </p>
                                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                            {goal.lead_executive && (
                                                                <span className="flex items-center gap-1">
                                                                    <UserCheck className="h-3.5 w-3.5" />
                                                                    Lead: {goal.lead_executive.name}
                                                                </span>
                                                            )}
                                                            {goal.roadmap_initiative && (
                                                                <Badge variant="outline" className="text-[10px]">
                                                                    Roadmap: {goal.roadmap_initiative.title}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2">
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {goal.timeframe}
                                                        </Badge>
                                                        <Badge
                                                            className={getStatusColor(
                                                                goal.status,
                                                            )}
                                                        >
                                                            {goal.status.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    </div>
                                                </div>

                                                {/* Goal Progress */}
                                                <div className="mt-2 mb-3">
                                                    <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                        <span>Progress</span>
                                                        <span className="font-medium">
                                                            {Math.round(Number(goal.progress_pct ?? 0))}%
                                                        </span>
                                                    </div>
                                                    <Progress
                                                        value={Number(goal.progress_pct ?? 0)}
                                                        className="h-1.5"
                                                    />
                                                </div>

                                                {/* Key Results */}
                                                {goal.key_results.length >
                                                    0 && (
                                                    <div className="mt-3 space-y-2">
                                                        <p className="text-sm font-medium text-foreground">
                                                            Key Results:
                                                        </p>
                                                        {goal.key_results.map(
                                                            (kr, index) => (
                                                                <div
                                                                    key={index}
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
                                                                        className={cn(
                                                                            kr.status ===
                                                                                'achieved' &&
                                                                                'text-muted-foreground line-through',
                                                                        )}
                                                                    >
                                                                        {
                                                                            kr.result
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}

                                                {/* Initiatives */}
                                                {goal.initiatives.length >
                                                    0 && (
                                                    <div className="mt-4">
                                                        <p className="mb-2 text-sm font-medium text-foreground">
                                                            Initiatives:
                                                        </p>
                                                        <div className="space-y-3">
                                                            {goal.initiatives.map(
                                                                (
                                                                    initiative,
                                                                ) => (
                                                                    <div
                                                                        key={
                                                                            initiative.id
                                                                        }
                                                                        className="rounded-lg bg-muted p-3"
                                                                    >
                                                                        <div className="flex items-start justify-between">
                                                                            <div>
                                                                                <div className="flex items-center gap-2">
                                                                                    <Rocket className="h-4 w-4 text-muted-foreground" />
                                                                                    <p className="text-sm font-medium">
                                                                                        {
                                                                                            initiative.name
                                                                                        }
                                                                                    </p>
                                                                                </div>
                                                                                <p className="mt-1 text-xs text-muted-foreground">
                                                                                    {initiative
                                                                                        .owner
                                                                                        ?.name ||
                                                                                        'No owner'}{' '}
                                                                                    •
                                                                                    Due:{' '}
                                                                                    {
                                                                                        initiative.target_completion
                                                                                    }
                                                                                </p>
                                                                            </div>
                                                                            <Badge
                                                                                className={getStatusColor(
                                                                                    initiative.status,
                                                                                )}
                                                                                variant="outline"
                                                                            >
                                                                                {initiative.status.replace(
                                                                                    '_',
                                                                                    ' ',
                                                                                )}
                                                                            </Badge>
                                                                        </div>
                                                                        <div className="mt-2">
                                                                            <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                                                <span>
                                                                                    Budget
                                                                                </span>
                                                                                <span>
                                                                                    $
                                                                                    {initiative.budget_spent.toLocaleString()}{' '}
                                                                                    /
                                                                                    $
                                                                                    {initiative.budget_allocated.toLocaleString()}
                                                                                </span>
                                                                            </div>
                                                                            <Progress
                                                                                value={calculateInitiativeProgress(
                                                                                    initiative,
                                                                                )}
                                                                                className="h-1"
                                                                            />
                                                                        </div>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ),
                    )}

                    {plan.goals.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                <Target className="mx-auto mb-4 h-12 w-12 opacity-50" />
                                <p>No strategic goals defined yet.</p>
                                <Button variant="outline" className="mt-4">
                                    Add Goal
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Approval Modal */}
                <Dialog open={isApproveOpen} onOpenChange={setIsApproveOpen}>
                    <DialogContent className="sm:max-w-md">
                        <form onSubmit={handleApprove}>
                            <DialogHeader>
                                <DialogTitle>Approve Strategic Plan</DialogTitle>
                                <DialogDescription>
                                    Formally approve version {plan.version_number} with a carried board resolution.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="py-4">
                                {carriedResolutions.length === 0 ? (
                                    <div className="flex items-start gap-3 rounded-lg border border-status-warning/40 bg-status-warning-bg p-3 text-status-warning">
                                        <AlertTriangle className="h-5 w-5 shrink-0" />
                                        <div>
                                            <p className="text-sm font-semibold">
                                                No Carried Resolutions Available
                                            </p>
                                            <p className="mt-1 text-xs">
                                                A board resolution must be closed and marked as &quot;carried&quot; before this plan can be approved.
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <Label htmlFor="resolution-select">Select Carried Resolution</Label>
                                        <select
                                            id="resolution-select"
                                            value={selectedResolutionId}
                                            onChange={(e) => setSelectedResolutionId(e.target.value)}
                                            required
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <option value="">-- Choose resolution --</option>
                                            {carriedResolutions.map((res) => (
                                                <option key={res.id} value={res.id}>
                                                    {res.resolution_reference} - {res.title} ({res.outcome})
                                                </option>
                                            ))}
                                        </select>
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
                                        !selectedResolutionId ||
                                        carriedResolutions.length === 0 ||
                                        submitting
                                    }
                                >
                                    {submitting ? 'Approving...' : 'Approve Plan'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* New Version Modal */}
                <Dialog open={isVersionOpen} onOpenChange={setIsVersionOpen}>
                    <DialogContent className="sm:max-w-md">
                        <form onSubmit={handleCreateVersion}>
                            <DialogHeader>
                                <DialogTitle>Create New Plan Version</DialogTitle>
                                <DialogDescription>
                                    Create version {plan.version_number + 1} branched from this plan. All goals and initiatives will retain their lineage for snapshot comparisons.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-3 py-4">
                                <Label htmlFor="version-notes">Version Notes / Rationale</Label>
                                <Textarea
                                    id="version-notes"
                                    value={versionNotes}
                                    onChange={(e) => setVersionNotes(e.target.value)}
                                    required
                                    placeholder="Describe why this new version is being created..."
                                    rows={3}
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
                                    disabled={!versionNotes.trim() || submitting}
                                >
                                    {submitting ? 'Creating...' : 'Create Version'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
