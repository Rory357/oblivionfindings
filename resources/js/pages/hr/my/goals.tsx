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
}

interface Props {
    goals: Goal[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Goals', href: '/hr/my/goals' },
];

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

export default function MyGoals({ goals }: Props) {
    const [progressOpen, setProgressOpen] = useState(false);
    const [selectedGoal, setSelectedGoal] = useState<Goal | null>(null);
    const [progressForm, setProgressForm] = useState({
        current_value: '',
        progress_percentage: '',
        comment: '',
    });

    const openProgress = (goal: Goal) => {
        setSelectedGoal(goal);
        setProgressForm({
            current_value: '',
            progress_percentage: String(goal.progress_percentage),
            comment: '',
        });
        setProgressOpen(true);
    };

    const submitProgress = (e: FormEvent) => {
        e.preventDefault();
        if (!selectedGoal) return;
        router.post(`/hr/goals/${selectedGoal.id}/progress`, {
            ...progressForm,
            current_value: progressForm.current_value || null,
        }, {
            onSuccess: () => setProgressOpen(false),
        });
    };

    const activeGoals = goals.filter((g) => g.status === 'active');
    const otherGoals = goals.filter((g) => g.status !== 'active');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Goals" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">My Goals</h1>
                    <div className="mt-1 text-sm text-slate-500">
                        Track and update your personal goals and OKRs
                    </div>
                </div>

                {/* Active Goals */}
                {activeGoals.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-medium text-slate-700">Active Goals</h2>
                        {activeGoals.map((goal) => (
                            <Card key={goal.id}>
                                <CardContent className="pt-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <h3 className="font-medium">{goal.title}</h3>
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${priorityColors[goal.priority] ?? ''}`}>
                                                    {goal.priority}
                                                </span>
                                                <Badge variant="outline" className="capitalize">{goal.goal_type}</Badge>
                                            </div>
                                            {goal.description && (
                                                <p className="mt-1 text-sm text-slate-600">{goal.description}</p>
                                            )}
                                            <div className="mt-2 flex items-center gap-4">
                                                <div className="flex-1">
                                                    <div className="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                                        <div
                                                            className="h-full rounded-full bg-blue-500 transition-all"
                                                            style={{ width: `${Math.min(goal.progress_percentage, 100)}%` }}
                                                        />
                                                    </div>
                                                </div>
                                                <span className="text-sm font-medium">{goal.progress_percentage}%</span>
                                                {goal.target_value && (
                                                    <span className="text-xs text-slate-500">
                                                        {goal.current_value ?? 0}/{goal.target_value} {goal.unit ?? ''}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Due: {formatDate(goal.due_date)}
                                            </div>
                                        </div>
                                        <Button size="sm" variant="outline" onClick={() => openProgress(goal)}>
                                            <TrendingUp className="mr-1.5 h-3.5 w-3.5" />
                                            Update
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Other Goals */}
                {otherGoals.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-medium text-slate-700">Other Goals</h2>
                        {otherGoals.map((goal) => (
                            <Card key={goal.id} className="opacity-75">
                                <CardContent className="pt-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <h3 className="font-medium">{goal.title}</h3>
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[goal.status] ?? ''}`}>
                                                    {goal.status}
                                                </span>
                                            </div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {formatDate(goal.start_date)} - {formatDate(goal.due_date)}
                                            </div>
                                        </div>
                                        <span className="text-sm font-medium">{goal.progress_percentage}%</span>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {goals.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center text-sm text-slate-500">
                            No goals assigned to you yet.
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* Progress Update Dialog */}
            <Dialog open={progressOpen} onOpenChange={setProgressOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Update Progress: {selectedGoal?.title}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitProgress} className="space-y-4">
                        {selectedGoal?.target_value && (
                            <div>
                                <Label>Current Value {selectedGoal.unit ? `(${selectedGoal.unit})` : ''}</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={progressForm.current_value}
                                    onChange={(e) => setProgressForm((p) => ({ ...p, current_value: e.target.value }))}
                                    placeholder={`Target: ${selectedGoal.target_value}`}
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
