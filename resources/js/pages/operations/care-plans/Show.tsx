import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Circle, Pencil, Plus, Target, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Goal = {
    id: number;
    title: string;
    description: string | null;
    category: string;
    target_date: string | null;
    status: string;
    priority: string;
    progress_percentage: number;
    outcome_notes: string | null;
};

type Props = {
    care_plan: {
        id: number;
        title: string;
        status: string;
        plan_type: string;
        starts_at: string | null;
        ends_at: string | null;
        next_review_at: string | null;
        reviewed_at: string | null;
        version: number;
        content: any;
        client: { id: number; first_name: string; last_name: string } | null;
        creator: { id: number; name: string } | null;
        reviewer: { id: number; name: string } | null;
        goals: Goal[];
    };
};

const GOAL_STATUS_COLORS: Record<string, string> = {
    not_started: OPS_COLORS.muted,
    in_progress: OPS_COLORS.primary,
    achieved: OPS_COLORS.success,
    discontinued: OPS_COLORS.danger,
};

const PRIORITY_COLORS: Record<string, string> = {
    low: 'text-slate-500',
    medium: 'text-amber-600',
    high: 'text-red-600',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function CarePlanShow({ care_plan }: Props) {
    const [showGoalForm, setShowGoalForm] = useState(false);
    const goalForm = useForm({
        title: '',
        description: '',
        category: 'health',
        priority: 'medium',
        target_date: '',
    });

    const handleAddGoal = (e: React.FormEvent) => {
        e.preventDefault();
        goalForm.post(`/operations/care-plans/${care_plan.id}/goals`, {
            preserveScroll: true,
            onSuccess: () => {
                goalForm.reset();
                setShowGoalForm(false);
            },
        });
    };

    const updateGoalProgress = (goalId: number, progress: number, status: string) => {
        router.patch(`/operations/care-plans/${care_plan.id}/goals/${goalId}/progress`, {
            progress_percentage: progress,
            status,
        }, { preserveScroll: true });
    };

    const goals = care_plan.goals ?? [];
    const goalStatusCounts = {
        not_started: goals.filter((g) => g.status === 'not_started').length,
        in_progress: goals.filter((g) => g.status === 'in_progress').length,
        achieved: goals.filter((g) => g.status === 'achieved').length,
        discontinued: goals.filter((g) => g.status === 'discontinued').length,
    };

    return (
        <AppLayout>
            <Head title={care_plan.title} />
            <PageHeader
                title={care_plan.title}
                description={`${care_plan.client?.first_name ?? ''} ${care_plan.client?.last_name ?? ''} — Version ${care_plan.version}`}
                backHref="/operations/care-plans"
            />
            <PageShell>
                {/* Header info */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={care_plan.status === 'active' ? 'default' : 'outline'} className="capitalize">
                        {care_plan.status}
                    </Badge>
                    <Badge variant="outline">{care_plan.plan_type.replace(/_/g, ' ')}</Badge>
                    {care_plan.starts_at && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3" />
                            {formatDate(care_plan.starts_at)} — {formatDate(care_plan.ends_at)}
                        </span>
                    )}
                    {care_plan.next_review_at && (
                        <span className={`text-xs ${new Date(care_plan.next_review_at) <= new Date() ? 'font-medium text-amber-600' : 'text-muted-foreground'}`}>
                            Next review: {formatDate(care_plan.next_review_at)}
                        </span>
                    )}
                    <div className="ml-auto flex gap-1">
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/care-plans/${care_plan.id}/edit`}>
                                <Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Goals Overview + Chart */}
                <div className="mt-6 grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Goals Progress</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center gap-3">
                                <DonutChart
                                    segments={[
                                        { label: 'Achieved', value: goalStatusCounts.achieved, color: OPS_COLORS.success },
                                        { label: 'In Progress', value: goalStatusCounts.in_progress, color: OPS_COLORS.primary },
                                        { label: 'Not Started', value: goalStatusCounts.not_started, color: OPS_COLORS.muted },
                                        { label: 'Discontinued', value: goalStatusCounts.discontinued, color: OPS_COLORS.danger },
                                    ]}
                                    centerValue={goals.length}
                                    centerLabel="Goals"
                                    size={120}
                                    strokeWidth={14}
                                />
                                <div className="space-y-1">
                                    {Object.entries(goalStatusCounts).map(([status, count]) => (
                                        <div key={status} className="flex items-center gap-2">
                                            <div className="h-2 w-2 rounded-full" style={{ backgroundColor: GOAL_STATUS_COLORS[status] }} />
                                            <span className="text-xs capitalize text-muted-foreground">{status.replace(/_/g, ' ')}</span>
                                            <span className="ml-auto text-xs font-medium tabular-nums">{count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Plan Content */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Plan Content</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {care_plan.content ? (
                                <div className="prose prose-sm max-w-none dark:prose-invert">
                                    {typeof care_plan.content === 'string' ? (
                                        <p className="whitespace-pre-wrap text-sm">{care_plan.content}</p>
                                    ) : (
                                        <pre className="text-xs">{JSON.stringify(care_plan.content, null, 2)}</pre>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No content added yet.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Goals List */}
                <div className="mt-6">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold">Goals ({goals.length})</h3>
                        <Button size="sm" variant="outline" onClick={() => setShowGoalForm(!showGoalForm)}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Goal
                        </Button>
                    </div>

                    {/* Add Goal Form */}
                    {showGoalForm && (
                        <Card className="mb-4 border-dashed border-indigo-300 bg-indigo-50/50 dark:border-indigo-800 dark:bg-indigo-950/20">
                            <CardContent className="p-4">
                                <form onSubmit={handleAddGoal} className="space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label className="text-xs">Goal Title *</Label>
                                            <Input
                                                value={goalForm.data.title}
                                                onChange={(e) => goalForm.setData('title', e.target.value)}
                                                placeholder="e.g. Improve social participation"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="grid grid-cols-3 gap-2">
                                            <div className="space-y-1">
                                                <Label className="text-xs">Category</Label>
                                                <Select value={goalForm.data.category} onValueChange={(v) => goalForm.setData('category', v)}>
                                                    <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {['health', 'social', 'independence', 'skills', 'wellbeing'].map((c) => (
                                                            <SelectItem key={c} value={c} className="capitalize">{c}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Priority</Label>
                                                <Select value={goalForm.data.priority} onValueChange={(v) => goalForm.setData('priority', v)}>
                                                    <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {['low', 'medium', 'high'].map((p) => (
                                                            <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Target Date</Label>
                                                <Input type="date" value={goalForm.data.target_date} onChange={(e) => goalForm.setData('target_date', e.target.value)} className="h-8 text-xs" />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Description</Label>
                                        <Textarea value={goalForm.data.description} onChange={(e) => goalForm.setData('description', e.target.value)} rows={2} className="text-sm" />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={goalForm.processing}>Add Goal</Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => setShowGoalForm(false)}>Cancel</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {/* Goals */}
                    <div className="space-y-2">
                        {goals.length === 0 && !showGoalForm && (
                            <Card>
                                <CardContent className="py-8 text-center">
                                    <Target className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                                    <p className="text-sm text-muted-foreground">No goals defined yet. Add goals to track client outcomes.</p>
                                </CardContent>
                            </Card>
                        )}
                        {goals.map((goal) => (
                            <Card key={goal.id} className="transition-all hover:shadow-sm">
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-3">
                                        <div className="mt-0.5">
                                            {goal.status === 'achieved' ? (
                                                <CheckCircle2 className="h-5 w-5 text-emerald-500" />
                                            ) : goal.status === 'in_progress' ? (
                                                <Circle className="h-5 w-5 text-indigo-500" />
                                            ) : (
                                                <Circle className="h-5 w-5 text-muted-foreground/40" />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{goal.title}</span>
                                                <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{goal.category}</Badge>
                                                <span className={`text-[10px] font-medium capitalize ${PRIORITY_COLORS[goal.priority] ?? ''}`}>
                                                    {goal.priority}
                                                </span>
                                            </div>
                                            {goal.description && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">{goal.description}</p>
                                            )}
                                            {/* Progress bar */}
                                            <div className="mt-2 flex items-center gap-3">
                                                <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                    <div
                                                        className="h-1.5 rounded-full transition-all"
                                                        style={{
                                                            width: `${goal.progress_percentage}%`,
                                                            backgroundColor: GOAL_STATUS_COLORS[goal.status] ?? OPS_COLORS.muted,
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium tabular-nums">{goal.progress_percentage}%</span>
                                            </div>
                                            {/* Quick status buttons */}
                                            <div className="mt-2 flex items-center gap-1">
                                                {goal.status !== 'achieved' && (
                                                    <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px]"
                                                        onClick={() => updateGoalProgress(goal.id, 100, 'achieved')}>
                                                        Mark Achieved
                                                    </Button>
                                                )}
                                                {goal.status === 'not_started' && (
                                                    <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px]"
                                                        onClick={() => updateGoalProgress(goal.id, 10, 'in_progress')}>
                                                        Start
                                                    </Button>
                                                )}
                                                {goal.target_date && (
                                                    <span className="ml-2 text-[10px] text-muted-foreground">
                                                        Target: {formatDate(goal.target_date)}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
