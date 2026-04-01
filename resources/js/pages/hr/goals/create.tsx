import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Target, ArrowLeft, Lightbulb, Users, User, Building2 } from 'lucide-react';
import { FormEvent, useMemo } from 'react';
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
    goal_type: string;
}

interface ParentContext {
    id: number;
    title: string;
    goal_type: string;
    progress_percentage: number;
    status: string;
    user: { name: string } | null;
    key_results_count: number;
}

interface Props {
    users: UserItem[];
    parentGoals: ParentGoal[];
    parentContext: ParentContext | null;
    goalTypes: SelectOption[];
    priorities: SelectOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Goals', href: '/hr/goals' },
    { title: 'Create Objective', href: '/hr/goals/create' },
];

const goalTypeIcons: Record<string, typeof Building2> = {
    company: Building2,
    team: Users,
    individual: User,
};

const statusColors: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-800',
    active: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
    on_track: 'bg-green-100 text-green-800',
    at_risk: 'bg-amber-100 text-amber-800',
    behind: 'bg-red-100 text-red-800',
};

export default function CreateGoal({ users, parentGoals, parentContext, goalTypes, priorities }: Props) {
    const form = useForm({
        user_id: '',
        title: '',
        description: '',
        goal_type: parentContext ? (parentContext.goal_type === 'company' ? 'team' : 'individual') : 'individual',
        category: '',
        parent_goal_id: parentContext ? String(parentContext.id) : '',
        target_value: '',
        unit: '',
        priority: 'medium',
        start_date: '',
        due_date: '',
        status: 'draft',
    });

    const set = (key: string, value: string) => form.setData(key as keyof typeof form.data, value);

    const filteredParentGoals = useMemo(() => {
        if (form.data.goal_type === 'team') {
            return parentGoals.filter((g) => g.goal_type === 'company');
        }
        if (form.data.goal_type === 'individual') {
            return parentGoals.filter((g) => g.goal_type === 'team');
        }
        return [];
    }, [form.data.goal_type, parentGoals]);

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
            <Head title="Create Objective" />

            <div className="flex flex-col gap-6 p-6">
                {/* Green Gradient Header */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600/90 via-emerald-600 to-emerald-500/80 p-6 text-white md:p-8">
                    <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/5" />
                    <div className="absolute -bottom-6 -left-6 h-28 w-28 rounded-full bg-white/5" />
                    <div className="relative flex items-center gap-4">
                        <Link
                            href="/hr/goals"
                            className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/10 transition hover:bg-white/20"
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <div className="flex items-center gap-3">
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                                <Target className="h-6 w-6" />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold md:text-2xl">Create Objective</h1>
                                <p className="text-sm text-emerald-100">
                                    Define a new objective or key result for your organisation
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Two-column layout */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Left column - Form */}
                    <div className="space-y-6 lg:col-span-2">
                        <form onSubmit={submit} className="space-y-6">
                            {/* Card 1: Objective Details */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Target className="h-4 w-4 text-emerald-600" />
                                        Objective Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label>Title <span className="text-red-500">*</span></Label>
                                        <Input
                                            value={form.data.title}
                                            onChange={(e) => set('title', e.target.value)}
                                            placeholder="e.g. Increase customer satisfaction score to 90%"
                                            required
                                        />
                                        {form.errors.title && <p className="mt-1 text-sm text-red-600">{form.errors.title}</p>}
                                    </div>

                                    <div>
                                        <Label>Description</Label>
                                        <Textarea
                                            value={form.data.description}
                                            onChange={(e) => set('description', e.target.value)}
                                            rows={3}
                                            placeholder="Provide additional context about this objective..."
                                        />
                                        {form.errors.description && <p className="mt-1 text-sm text-red-600">{form.errors.description}</p>}
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Objective Type <span className="text-red-500">*</span></Label>
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
                                            <Label>Category</Label>
                                            <Input
                                                value={form.data.category}
                                                onChange={(e) => set('category', e.target.value)}
                                                placeholder="e.g. Sales, Engineering, Quality"
                                            />
                                            {form.errors.category && <p className="mt-1 text-sm text-red-600">{form.errors.category}</p>}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Card 2: Assignment & Priority */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Users className="h-4 w-4 text-emerald-600" />
                                        Assignment & Priority
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Start Date <span className="text-red-500">*</span></Label>
                                            <Input
                                                type="date"
                                                value={form.data.start_date}
                                                onChange={(e) => set('start_date', e.target.value)}
                                                required
                                            />
                                            {form.errors.start_date && <p className="mt-1 text-sm text-red-600">{form.errors.start_date}</p>}
                                        </div>

                                        <div>
                                            <Label>Due Date <span className="text-red-500">*</span></Label>
                                            <Input
                                                type="date"
                                                value={form.data.due_date}
                                                onChange={(e) => set('due_date', e.target.value)}
                                                required
                                            />
                                            {form.errors.due_date && <p className="mt-1 text-sm text-red-600">{form.errors.due_date}</p>}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Card 3: Target & Parent */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Target className="h-4 w-4 text-emerald-600" />
                                        Target & Parent
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Target Value</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={form.data.target_value}
                                                onChange={(e) => set('target_value', e.target.value)}
                                                placeholder="e.g. 100"
                                            />
                                            {form.errors.target_value && <p className="mt-1 text-sm text-red-600">{form.errors.target_value}</p>}
                                        </div>

                                        <div>
                                            <Label>Unit</Label>
                                            <Input
                                                value={form.data.unit}
                                                onChange={(e) => set('unit', e.target.value)}
                                                placeholder="e.g. %, deals, hours"
                                            />
                                            {form.errors.unit && <p className="mt-1 text-sm text-red-600">{form.errors.unit}</p>}
                                        </div>
                                    </div>

                                    <div>
                                        <Label>Parent Objective</Label>
                                        <Select
                                            value={form.data.parent_goal_id || 'none'}
                                            onValueChange={(val) => set('parent_goal_id', val === 'none' ? '' : val)}
                                        >
                                            <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">None</SelectItem>
                                                {filteredParentGoals.map((g) => (
                                                    <SelectItem key={g.id} value={String(g.id)}>
                                                        {g.title}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {form.data.goal_type === 'team'
                                                ? 'Showing company-level objectives as potential parents'
                                                : form.data.goal_type === 'individual'
                                                  ? 'Showing team-level objectives as potential parents'
                                                  : 'Company objectives have no parent'}
                                        </p>
                                        {form.errors.parent_goal_id && <p className="mt-1 text-sm text-red-600">{form.errors.parent_goal_id}</p>}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Card 4: Initial Status */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Initial Status</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex gap-4">
                                        <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-4 transition hover:bg-muted has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                            <input
                                                type="radio"
                                                name="status"
                                                value="draft"
                                                checked={form.data.status === 'draft'}
                                                onChange={() => set('status', 'draft')}
                                                className="accent-emerald-600"
                                            />
                                            <div>
                                                <div className="font-medium">Draft</div>
                                                <div className="text-xs text-muted-foreground">Save as draft for review before activation</div>
                                            </div>
                                        </label>
                                        <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-4 transition hover:bg-muted has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                                            <input
                                                type="radio"
                                                name="status"
                                                value="active"
                                                checked={form.data.status === 'active'}
                                                onChange={() => set('status', 'active')}
                                                className="accent-emerald-600"
                                            />
                                            <div>
                                                <div className="font-medium">Active</div>
                                                <div className="text-xs text-muted-foreground">Start tracking progress immediately</div>
                                            </div>
                                        </label>
                                    </div>
                                    {form.errors.status && <p className="mt-1 text-sm text-red-600">{form.errors.status}</p>}
                                </CardContent>
                            </Card>

                            {/* Actions */}
                            <div className="flex items-center justify-end gap-3">
                                <Link href="/hr/goals">
                                    <Button type="button" variant="outline">Cancel</Button>
                                </Link>
                                <Button type="submit" disabled={form.processing} className="bg-emerald-600 hover:bg-emerald-700">
                                    <Target className="mr-2 h-4 w-4" />
                                    Create Objective
                                </Button>
                            </div>
                        </form>
                    </div>

                    {/* Right column - Context Panel */}
                    <div className="space-y-6">
                        {/* Parent Context Card */}
                        {parentContext && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Parent Objective</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex items-start gap-3">
                                        {(() => {
                                            const Icon = goalTypeIcons[parentContext.goal_type] ?? Target;
                                            return (
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                                    <Icon className="h-4 w-4" />
                                                </div>
                                            );
                                        })()}
                                        <div className="min-w-0">
                                            <Link
                                                href={`/hr/goals/${parentContext.id}`}
                                                className="font-medium text-sm hover:underline"
                                            >
                                                {parentContext.title}
                                            </Link>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <Badge variant="outline" className="text-xs capitalize">
                                                    {parentContext.goal_type}
                                                </Badge>
                                                <Badge className={`text-xs ${statusColors[parentContext.status] ?? 'bg-slate-100 text-slate-800'}`}>
                                                    {parentContext.status.replace(/_/g, ' ')}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Progress bar */}
                                    <div>
                                        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                            <span>Progress</span>
                                            <span>{parentContext.progress_percentage}%</span>
                                        </div>
                                        <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className="h-full rounded-full bg-emerald-500 transition-all"
                                                style={{ width: `${parentContext.progress_percentage}%` }}
                                            />
                                        </div>
                                    </div>

                                    {parentContext.user && (
                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                            <User className="h-3 w-3" />
                                            <span>Owner: {parentContext.user.name}</span>
                                        </div>
                                    )}

                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Target className="h-3 w-3" />
                                        <span>{parentContext.key_results_count} key result{parentContext.key_results_count !== 1 ? 's' : ''}</span>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* OKR Tips Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Lightbulb className="h-4 w-4 text-amber-500" />
                                    OKR Tips
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-3 text-sm text-muted-foreground">
                                    <li className="flex gap-2">
                                        <Building2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                        <span>Company objectives cascade to teams, which cascade to individuals.</span>
                                    </li>
                                    <li className="flex gap-2">
                                        <Target className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                        <span>Add Key Results after creating the objective to define measurable outcomes.</span>
                                    </li>
                                    <li className="flex gap-2">
                                        <Users className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                        <span>Keep objectives ambitious but achievable. Aim for 70% completion as a healthy target.</span>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
