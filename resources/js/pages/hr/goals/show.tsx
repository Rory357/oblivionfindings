import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Target, TrendingUp } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';

interface GoalUpdate {
    id: number;
    previous_value: string | null;
    new_value: string | null;
    progress_percentage: number;
    comment: string | null;
    created_at: string;
    user: { id: number; name: string };
}

interface ChildGoal {
    id: number;
    title: string;
    progress_percentage: number;
    status: string;
}

interface Goal {
    id: number;
    title: string;
    description: string | null;
    goal_type: string;
    category: string | null;
    target_value: string | null;
    current_value: string | null;
    unit: string | null;
    progress_percentage: number;
    status: string;
    priority: string;
    start_date: string;
    due_date: string;
    completed_at: string | null;
    user: { id: number; name: string };
    creator: { id: number; name: string };
    parent_goal: { id: number; title: string } | null;
    child_goals: ChildGoal[];
    updates: GoalUpdate[];
    performance_review: { id: number; review_type: string; status: string } | null;
}

interface Props {
    goal: Goal;
    can: { manage: boolean; updateProgress: boolean };
}

const statusColors: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-800',
    active: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
};

const priorityColors: Record<string, string> = {
    low: 'bg-slate-100 text-slate-700',
    medium: 'bg-yellow-100 text-yellow-800',
    high: 'bg-red-100 text-red-800',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

export default function GoalShow({ goal, can }: Props) {
    const [progressOpen, setProgressOpen] = useState(false);
    const [progressForm, setProgressForm] = useState({
        current_value: '',
        progress_percentage: String(goal.progress_percentage),
        comment: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Goals', href: '/hr/goals' },
        { title: goal.title, href: `/hr/goals/${goal.id}` },
    ];

    const submitProgress = (e: FormEvent) => {
        e.preventDefault();
        router.post(`/hr/goals/${goal.id}/progress`, {
            ...progressForm,
            current_value: progressForm.current_value || null,
        }, {
            onSuccess: () => setProgressOpen(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={goal.title} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{goal.title}</h1>
                        <div className="mt-1 flex items-center gap-2 text-sm text-slate-500">
                            <span>Assigned to {goal.user?.name}</span>
                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[goal.status] ?? ''}`}>
                                {goal.status}
                            </span>
                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${priorityColors[goal.priority] ?? ''}`}>
                                {goal.priority}
                            </span>
                        </div>
                    </div>

                    {can.updateProgress && goal.status === 'active' && (
                        <Button size="sm" onClick={() => setProgressOpen(true)}>
                            <TrendingUp className="mr-1.5 h-4 w-4" />
                            Update Progress
                        </Button>
                    )}
                </div>

                {/* Progress Bar */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center gap-4">
                            <div className="flex-1">
                                <div className="mb-1 flex items-center justify-between text-sm">
                                    <span className="font-medium">Overall Progress</span>
                                    <span className="text-slate-600">{goal.progress_percentage}%</span>
                                </div>
                                <div className="h-3 w-full overflow-hidden rounded-full bg-slate-200">
                                    <div
                                        className="h-full rounded-full bg-blue-500 transition-all"
                                        style={{ width: `${Math.min(goal.progress_percentage, 100)}%` }}
                                    />
                                </div>
                            </div>
                            {goal.target_value && (
                                <div className="text-right text-sm">
                                    <div className="text-slate-500">Target</div>
                                    <div className="font-medium">{goal.current_value ?? 0} / {goal.target_value} {goal.unit ?? ''}</div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Goal Details */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {goal.description && (
                                <div>
                                    <span className="text-slate-500">Description</span>
                                    <p className="mt-0.5">{goal.description}</p>
                                </div>
                            )}
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <span className="text-slate-500">Type</span>
                                    <p className="mt-0.5 capitalize">{goal.goal_type}</p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Category</span>
                                    <p className="mt-0.5">{goal.category || '-'}</p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Start Date</span>
                                    <p className="mt-0.5">{formatDate(goal.start_date)}</p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Due Date</span>
                                    <p className="mt-0.5">{formatDate(goal.due_date)}</p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Created By</span>
                                    <p className="mt-0.5">{goal.creator?.name}</p>
                                </div>
                                {goal.completed_at && (
                                    <div>
                                        <span className="text-slate-500">Completed</span>
                                        <p className="mt-0.5">{formatDateTime(goal.completed_at)}</p>
                                    </div>
                                )}
                            </div>
                            {goal.parent_goal && (
                                <div>
                                    <span className="text-slate-500">Parent Goal</span>
                                    <p className="mt-0.5">
                                        <button
                                            className="text-blue-600 hover:underline"
                                            onClick={() => router.get(`/hr/goals/${goal.parent_goal!.id}`)}
                                        >
                                            {goal.parent_goal.title}
                                        </button>
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Child Goals */}
                    {goal.child_goals?.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Sub-Goals</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {goal.child_goals.map((child) => (
                                    <div
                                        key={child.id}
                                        className="flex cursor-pointer items-center justify-between rounded-md border p-2 hover:bg-muted/50"
                                        onClick={() => router.get(`/hr/goals/${child.id}`)}
                                    >
                                        <div>
                                            <div className="text-sm font-medium">{child.title}</div>
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[child.status] ?? ''}`}>
                                                {child.status}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 w-16 overflow-hidden rounded-full bg-slate-200">
                                                <div
                                                    className="h-full rounded-full bg-blue-500"
                                                    style={{ width: `${Math.min(child.progress_percentage, 100)}%` }}
                                                />
                                            </div>
                                            <span className="text-xs text-slate-600">{child.progress_percentage}%</span>
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Progress Updates */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Progress Updates</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {goal.updates?.length > 0 ? (
                            <div className="space-y-3">
                                {goal.updates.map((update) => (
                                    <div key={update.id} className="rounded-md border p-3">
                                        <div className="flex items-center justify-between">
                                            <div className="text-sm font-medium">{update.user?.name}</div>
                                            <div className="text-xs text-slate-500">{formatDateTime(update.created_at)}</div>
                                        </div>
                                        <div className="mt-1 flex items-center gap-3 text-sm text-slate-600">
                                            <span>Progress: {update.progress_percentage}%</span>
                                            {update.previous_value !== null && update.new_value !== null && (
                                                <span>Value: {update.previous_value} → {update.new_value}</span>
                                            )}
                                        </div>
                                        {update.comment && (
                                            <p className="mt-1 text-sm text-slate-700">{update.comment}</p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="py-4 text-center text-sm text-slate-500">No progress updates yet.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Progress Update Dialog */}
            <Dialog open={progressOpen} onOpenChange={setProgressOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Update Progress</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitProgress} className="space-y-4">
                        {goal.target_value && (
                            <div>
                                <Label>Current Value {goal.unit ? `(${goal.unit})` : ''}</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={progressForm.current_value}
                                    onChange={(e) => setProgressForm((p) => ({ ...p, current_value: e.target.value }))}
                                    placeholder={`Target: ${goal.target_value}`}
                                />
                            </div>
                        )}
                        <div>
                            <Label>Progress Percentage</Label>
                            <Input
                                type="number"
                                min="0"
                                max="100"
                                value={progressForm.progress_percentage}
                                onChange={(e) => setProgressForm((p) => ({ ...p, progress_percentage: e.target.value }))}
                                required
                            />
                        </div>
                        <div>
                            <Label>Comment</Label>
                            <Textarea
                                value={progressForm.comment}
                                onChange={(e) => setProgressForm((p) => ({ ...p, comment: e.target.value }))}
                                placeholder="Describe what was accomplished..."
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setProgressOpen(false)}>Cancel</Button>
                            <Button type="submit">Save Update</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
