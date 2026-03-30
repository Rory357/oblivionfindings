import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';

interface SelectOption {
    value: string;
    label: string;
}

interface UserItem {
    id: number;
    name: string;
}

interface ParentGoal {
    id: number;
    title: string;
}

interface Props {
    users: UserItem[];
    parentGoals: ParentGoal[];
    goalTypes: SelectOption[];
    priorities: SelectOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Goals', href: '/hr/goals' },
    { title: 'New Goal', href: '/hr/goals/create' },
];

export default function CreateGoal({ users, parentGoals, goalTypes, priorities }: Props) {
    const form = useForm({
        user_id: '',
        title: '',
        description: '',
        goal_type: 'individual',
        category: '',
        parent_goal_id: '',
        target_value: '',
        unit: '',
        priority: 'medium',
        start_date: '',
        due_date: '',
        status: 'draft',
    });

    const set = (key: string, value: string) => form.setData(key as keyof typeof form.data, value);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            parent_goal_id: data.parent_goal_id || null,
            target_value: data.target_value || null,
        }));
        form.post('/hr/goals');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Goal" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Create Goal</h1>
                    <div className="mt-1 text-sm text-slate-500">
                        Define a new goal or OKR for an employee or team
                    </div>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Title <span className="text-red-500">*</span></Label>
                                    <Input value={form.data.title} onChange={(e) => set('title', e.target.value)} required />
                                    {form.errors.title && <p className="mt-1 text-sm text-red-600">{form.errors.title}</p>}
                                </div>
                                <div>
                                    <Label>Assigned To <span className="text-red-500">*</span></Label>
                                    <Select value={form.data.user_id} onValueChange={(val) => set('user_id', val)}>
                                        <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                                        <SelectContent>
                                            {users.map((u) => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.user_id && <p className="mt-1 text-sm text-red-600">{form.errors.user_id}</p>}
                                </div>
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Textarea value={form.data.description} onChange={(e) => set('description', e.target.value)} rows={3} />
                                {form.errors.description && <p className="mt-1 text-sm text-red-600">{form.errors.description}</p>}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Goal Type <span className="text-red-500">*</span></Label>
                                    <Select value={form.data.goal_type} onValueChange={(val) => set('goal_type', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {goalTypes.map((gt) => (
                                                <SelectItem key={gt.value} value={gt.value}>{gt.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.goal_type && <p className="mt-1 text-sm text-red-600">{form.errors.goal_type}</p>}
                                </div>
                                <div>
                                    <Label>Priority <span className="text-red-500">*</span></Label>
                                    <Select value={form.data.priority} onValueChange={(val) => set('priority', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {priorities.map((p) => (
                                                <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.priority && <p className="mt-1 text-sm text-red-600">{form.errors.priority}</p>}
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Input value={form.data.category} onChange={(e) => set('category', e.target.value)} placeholder="e.g. Sales, Engineering" />
                                    {form.errors.category && <p className="mt-1 text-sm text-red-600">{form.errors.category}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Parent Goal (optional)</Label>
                                    <Select value={form.data.parent_goal_id || 'none'} onValueChange={(val) => set('parent_goal_id', val === 'none' ? '' : val)}>
                                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            {parentGoals.map((g) => (
                                                <SelectItem key={g.id} value={String(g.id)}>{g.title}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.parent_goal_id && <p className="mt-1 text-sm text-red-600">{form.errors.parent_goal_id}</p>}
                                </div>
                                <div>
                                    <Label>Target Value</Label>
                                    <Input type="number" step="0.01" value={form.data.target_value} onChange={(e) => set('target_value', e.target.value)} placeholder="e.g. 100" />
                                    {form.errors.target_value && <p className="mt-1 text-sm text-red-600">{form.errors.target_value}</p>}
                                </div>
                                <div>
                                    <Label>Unit</Label>
                                    <Input value={form.data.unit} onChange={(e) => set('unit', e.target.value)} placeholder="e.g. %, deals, hours" />
                                    {form.errors.unit && <p className="mt-1 text-sm text-red-600">{form.errors.unit}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Start Date <span className="text-red-500">*</span></Label>
                                    <Input type="date" value={form.data.start_date} onChange={(e) => set('start_date', e.target.value)} required />
                                    {form.errors.start_date && <p className="mt-1 text-sm text-red-600">{form.errors.start_date}</p>}
                                </div>
                                <div>
                                    <Label>Due Date <span className="text-red-500">*</span></Label>
                                    <Input type="date" value={form.data.due_date} onChange={(e) => set('due_date', e.target.value)} required />
                                    {form.errors.due_date && <p className="mt-1 text-sm text-red-600">{form.errors.due_date}</p>}
                                </div>
                                <div>
                                    <Label>Initial Status</Label>
                                    <Select value={form.data.status} onValueChange={(val) => set('status', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">Draft</SelectItem>
                                            <SelectItem value="active">Active</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {form.errors.status && <p className="mt-1 text-sm text-red-600">{form.errors.status}</p>}
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                                <Button type="submit" disabled={form.processing}>Create Goal</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
