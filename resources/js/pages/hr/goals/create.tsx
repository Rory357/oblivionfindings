import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useState, FormEvent } from 'react';
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
    const [form, setForm] = useState({
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

    const set = (key: string, value: string) => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/goals', {
            ...form,
            parent_goal_id: form.parent_goal_id || null,
            target_value: form.target_value || null,
        });
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
                                    <Label>Title</Label>
                                    <Input value={form.title} onChange={(e) => set('title', e.target.value)} required />
                                </div>
                                <div>
                                    <Label>Assigned To</Label>
                                    <Select value={form.user_id} onValueChange={(val) => set('user_id', val)}>
                                        <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                                        <SelectContent>
                                            {users.map((u) => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Textarea value={form.description} onChange={(e) => set('description', e.target.value)} rows={3} />
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Goal Type</Label>
                                    <Select value={form.goal_type} onValueChange={(val) => set('goal_type', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {goalTypes.map((gt) => (
                                                <SelectItem key={gt.value} value={gt.value}>{gt.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Priority</Label>
                                    <Select value={form.priority} onValueChange={(val) => set('priority', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {priorities.map((p) => (
                                                <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Input value={form.category} onChange={(e) => set('category', e.target.value)} placeholder="e.g. Sales, Engineering" />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Parent Goal (optional)</Label>
                                    <Select value={form.parent_goal_id || 'none'} onValueChange={(val) => set('parent_goal_id', val === 'none' ? '' : val)}>
                                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            {parentGoals.map((g) => (
                                                <SelectItem key={g.id} value={String(g.id)}>{g.title}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Target Value</Label>
                                    <Input type="number" step="0.01" value={form.target_value} onChange={(e) => set('target_value', e.target.value)} placeholder="e.g. 100" />
                                </div>
                                <div>
                                    <Label>Unit</Label>
                                    <Input value={form.unit} onChange={(e) => set('unit', e.target.value)} placeholder="e.g. %, deals, hours" />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Start Date</Label>
                                    <Input type="date" value={form.start_date} onChange={(e) => set('start_date', e.target.value)} required />
                                </div>
                                <div>
                                    <Label>Due Date</Label>
                                    <Input type="date" value={form.due_date} onChange={(e) => set('due_date', e.target.value)} required />
                                </div>
                                <div>
                                    <Label>Initial Status</Label>
                                    <Select value={form.status} onValueChange={(val) => set('status', val)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">Draft</SelectItem>
                                            <SelectItem value="active">Active</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" onClick={() => router.get('/hr/goals')}>Cancel</Button>
                                <Button type="submit">Create Goal</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
