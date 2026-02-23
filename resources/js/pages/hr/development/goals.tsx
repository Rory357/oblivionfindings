import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type GoalRow = {
    id: number;
    title: string;
    description: string | null;
    category: string;
    competency_area: string | null;
    target_level: number | null;
    current_level: number | null;
    status: 'not_started' | 'in_progress' | 'blocked' | 'completed' | 'cancelled';
    progress_percent: number;
    start_date: string | null;
    due_date: string | null;
    completed_at: string | null;
    review_frequency: string | null;
    review_notes: string | null;
    employee: { id: number; name: string; email: string } | null;
    manager: { id: number; name: string } | null;
};

type Staff = {
    id: number;
    name: string;
    email: string;
};

type PaginatedGoals = {
    data: GoalRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    goals: PaginatedGoals;
    staff: Staff[];
    filters: { status: string | null };
    can: { manage: boolean };
};

const statuses: Array<GoalRow['status']> = ['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'];

export default function DevelopmentGoals({ goals, staff, filters, can }: Props) {
    const createForm = useForm({
        employee_user_id: '',
        manager_user_id: '',
        title: '',
        description: '',
        category: 'growth',
        competency_area: '',
        target_level: '',
        current_level: '',
        start_date: '',
        due_date: '',
        review_frequency: 'monthly',
    });

    const updateForm = useForm({
        status: '' as GoalRow['status'] | '',
        progress_percent: 0,
        current_level: '',
        review_notes: '',
    });

    function submitCreate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        createForm.post('/hr/development/goals', {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    }

    function updateGoal(goalId: number, payload: { status: GoalRow['status']; progress_percent: number; current_level?: number | null; review_notes?: string }) {
        updateForm.transform(() => ({
            status: payload.status,
            progress_percent: payload.progress_percent,
            current_level: payload.current_level ?? null,
            review_notes: payload.review_notes ?? null,
        }));

        updateForm.put(`/hr/development/goals/${goalId}`, {
            preserveScroll: true,
        });
    }

    function applyStatusFilter(status: string | null) {
        router.get('/hr/development/goals', { status: status || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Development Goals', href: '/hr/development/goals' },
            ]}
        >
            <Head title="Development Goals" />
            <PageShell>
                <PageHeader title="Development Goals" description="Track growth plans, competency progression, and manager coaching outcomes." />

                <div className="flex items-center gap-2">
                    <Label>Status</Label>
                    <Select value={filters.status ?? '__all__'} onValueChange={(value) => applyStatusFilter(value === '__all__' ? null : value)}>
                        <SelectTrigger className="w-52"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All statuses</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {status.replace('_', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create Development Goal</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitCreate} className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Employee</Label>
                                        <Select value={createForm.data.employee_user_id} onValueChange={(value) => createForm.setData('employee_user_id', value)}>
                                            <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((member) => (
                                                    <SelectItem key={member.id} value={String(member.id)}>
                                                        {member.name} ({member.email})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {createForm.errors.employee_user_id && <p className="text-sm text-destructive">{createForm.errors.employee_user_id}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Manager (optional)</Label>
                                        <Select value={createForm.data.manager_user_id || '__none__'} onValueChange={(value) => createForm.setData('manager_user_id', value === '__none__' ? '' : value)}>
                                            <SelectTrigger><SelectValue placeholder="Select manager" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">No manager</SelectItem>
                                                {staff.map((member) => (
                                                    <SelectItem key={member.id} value={String(member.id)}>
                                                        {member.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>Goal title</Label>
                                    <Input value={createForm.data.title} onChange={(event) => createForm.setData('title', event.target.value)} />
                                    {createForm.errors.title && <p className="text-sm text-destructive">{createForm.errors.title}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label>Description</Label>
                                    <Textarea
                                        rows={3}
                                        value={createForm.data.description}
                                        onChange={(event) => createForm.setData('description', event.target.value)}
                                    />
                                </div>

                                <div className="grid gap-4 md:grid-cols-4">
                                    <div className="space-y-2">
                                        <Label>Category</Label>
                                        <Select value={createForm.data.category} onValueChange={(value) => createForm.setData('category', value)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="growth">Growth</SelectItem>
                                                <SelectItem value="performance">Performance</SelectItem>
                                                <SelectItem value="leadership">Leadership</SelectItem>
                                                <SelectItem value="compliance">Compliance</SelectItem>
                                                <SelectItem value="capability">Capability</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Competency area</Label>
                                        <Input
                                            value={createForm.data.competency_area}
                                            onChange={(event) => createForm.setData('competency_area', event.target.value)}
                                            placeholder="Communication"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Current level (1-5)</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={5}
                                            value={createForm.data.current_level}
                                            onChange={(event) => createForm.setData('current_level', event.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Target level (1-5)</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={5}
                                            value={createForm.data.target_level}
                                            onChange={(event) => createForm.setData('target_level', event.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label>Start date</Label>
                                        <Input type="date" value={createForm.data.start_date} onChange={(event) => createForm.setData('start_date', event.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Due date</Label>
                                        <Input type="date" value={createForm.data.due_date} onChange={(event) => createForm.setData('due_date', event.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Review frequency</Label>
                                        <Select value={createForm.data.review_frequency} onValueChange={(value) => createForm.setData('review_frequency', value)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="weekly">Weekly</SelectItem>
                                                <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                                <SelectItem value="monthly">Monthly</SelectItem>
                                                <SelectItem value="quarterly">Quarterly</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <Button type="submit" disabled={createForm.processing}>
                                    {createForm.processing ? 'Creating...' : 'Create Goal'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4">
                    {goals.data.map((goal) => (
                        <Card key={goal.id}>
                            <CardContent className="pt-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="font-semibold">{goal.title}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {goal.employee?.name ?? 'Unknown employee'} · {goal.category}
                                            {goal.competency_area ? ` · ${goal.competency_area}` : ''}
                                        </p>
                                        {goal.description && <p className="mt-2 text-sm">{goal.description}</p>}
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Progress {goal.progress_percent}% · Due {goal.due_date ?? 'Not set'}
                                        </p>
                                    </div>
                                    <Badge variant={goal.status === 'completed' ? 'default' : 'outline'}>
                                        {goal.status.replace('_', ' ')}
                                    </Badge>
                                </div>

                                <div className="mt-3 grid gap-3 md:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">Status</Label>
                                        <Select
                                            value={goal.status}
                                            onValueChange={(value: GoalRow['status']) =>
                                                updateGoal(goal.id, {
                                                    status: value,
                                                    progress_percent: value === 'completed' ? 100 : goal.progress_percent,
                                                    current_level: goal.current_level,
                                                    review_notes: goal.review_notes ?? '',
                                                })
                                            }
                                        >
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {statuses.map((status) => (
                                                    <SelectItem key={status} value={status}>
                                                        {status.replace('_', ' ')}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">Progress</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={100}
                                            defaultValue={goal.progress_percent}
                                            onBlur={(event) => {
                                                const progress = Number(event.target.value);
                                                updateGoal(goal.id, {
                                                    status: progress >= 100 ? 'completed' : goal.status,
                                                    progress_percent: Number.isFinite(progress) ? Math.max(0, Math.min(100, progress)) : goal.progress_percent,
                                                    current_level: goal.current_level,
                                                    review_notes: goal.review_notes ?? '',
                                                });
                                            }}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">Current level</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={5}
                                            defaultValue={goal.current_level ?? ''}
                                            onBlur={(event) => {
                                                const currentLevel = event.target.value === '' ? null : Number(event.target.value);
                                                updateGoal(goal.id, {
                                                    status: goal.status,
                                                    progress_percent: goal.progress_percent,
                                                    current_level: currentLevel,
                                                    review_notes: goal.review_notes ?? '',
                                                });
                                            }}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    {goals.data.length === 0 && (
                        <Card>
                            <CardContent className="py-8 text-center text-muted-foreground">
                                No development goals found.
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
