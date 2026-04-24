import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, FormEvent } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { TabsRoot as Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';
import {
    Target, TrendingUp, Users, Clock, Plus, Trash2,
    Pencil, Calendar, User, ChevronDown, ChevronUp,
    BarChart3, ListChecks, History,
} from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface KeyResult {
    id: number;
    title: string;
    target_value: number;
    current_value: number;
    unit: string | null;
    progress_percentage: number;
    status: string;
    due_date: string;
    owner: { id: number; name: string } | null;
}

interface ChildGoal {
    id: number;
    title: string;
    goal_type: string;
    status: string;
    priority: string;
    progress_percentage: number;
    user: { name: string } | null;
    key_results_count: number;
}

interface GoalUpdate {
    id: number;
    user_name: string;
    previous_value: string | null;
    new_value: string | null;
    progress_percentage: number;
    comment: string | null;
    created_at: string;
}

interface Goal {
    id: number;
    title: string;
    description: string | null;
    goal_type: string;
    category: string | null;
    status: string;
    priority: string;
    progress_percentage: number;
    target_value: number | null;
    current_value: number | null;
    unit: string | null;
    start_date: string;
    due_date: string;
    completed_at: string | null;
    user: { id: number; name: string } | null;
    creator: string | null;
    parent_goal: { id: number; title: string; goal_type: string } | null;
    child_goals: ChildGoal[];
    key_results: KeyResult[];
    updates: GoalUpdate[];
}

interface UserItem {
    id: number;
    name: string;
}

interface Props {
    goal: Goal;
    users: UserItem[];
    can: { manage: boolean; updateProgress: boolean };
}

/* ------------------------------------------------------------------ */
/*  Colour helpers                                                     */
/* ------------------------------------------------------------------ */

const statusColours: Record<string, string> = {
    not_started: 'bg-muted text-foreground border-border',
    in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
    completed: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    cancelled: 'bg-red-100 text-red-800 border-red-200',
};

const statusBadgeWhite: Record<string, string> = {
    not_started: 'bg-white/10 text-white/90 border-white/20',
    in_progress: 'bg-white/10 text-white/90 border-white/20',
    completed: 'bg-emerald-400/20 text-white border-emerald-300/30',
    cancelled: 'bg-red-400/20 text-white border-red-300/30',
};

const priorityColours: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-yellow-100 text-yellow-800',
    high: 'bg-red-100 text-red-800',
    critical: 'bg-red-200 text-red-900',
};

const priorityBadgeWhite: Record<string, string> = {
    low: 'bg-white/10 text-white/80 border-white/20',
    medium: 'bg-amber-400/20 text-white border-amber-300/30',
    high: 'bg-red-400/20 text-white border-red-300/30',
    critical: 'bg-red-500/30 text-white border-red-400/40',
};

const typeBadgeWhite: Record<string, string> = {
    individual: 'bg-white/10 text-white/90 border-white/20',
    team: 'bg-white/10 text-white/90 border-white/20',
    company: 'bg-white/10 text-white/90 border-white/20',
    department: 'bg-white/10 text-white/90 border-white/20',
};

function progressBarColour(pct: number): string {
    if (pct > 66) return 'bg-green-500';
    if (pct >= 33) return 'bg-amber-500';
    return 'bg-red-500';
}

function krStatusColour(status: string): string {
    switch (status) {
        case 'completed': return 'bg-emerald-500';
        case 'in_progress': return 'bg-blue-500';
        case 'at_risk': return 'bg-amber-500';
        case 'behind': return 'bg-red-500';
        default: return 'bg-slate-400';
    }
}

/* ------------------------------------------------------------------ */
/*  Date formatting (NZ locale)                                        */
/* ------------------------------------------------------------------ */

function formatDate(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit', month: 'short', year: 'numeric',
              hour: '2-digit', minute: '2-digit',
          });
}

function capitalize(str: string): string {
    return str.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function GoalShow({ goal, users, can }: Props) {
    const [showKrForm, setShowKrForm] = useState(false);
    const [editingKrId, setEditingKrId] = useState<number | null>(null);
    const [krUpdateValue, setKrUpdateValue] = useState('');
    const [progressOpen, setProgressOpen] = useState(false);

    /* Progress update form */
    const [progressForm, setProgressForm] = useState({
        current_value: '',
        progress_percentage: String(goal.progress_percentage),
        comment: '',
    });

    /* Key Result creation form */
    const krForm = useForm({
        title: '',
        target_value: '',
        unit: '',
        due_date: '',
        owner_id: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Goals', href: '/hr/goals' },
        { title: goal.title, href: `/hr/goals/${goal.id}` },
    ];

    /* ---- Actions ---- */

    function submitKeyResult(e: FormEvent) {
        e.preventDefault();
        krForm.post(`/hr/goals/${goal.id}/key-results`, {
            preserveScroll: true,
            onSuccess: () => {
                krForm.reset();
                setShowKrForm(false);
            },
        });
    }

    function updateKeyResultValue(krId: number) {
        router.put(`/hr/goals/key-results/${krId}`, {
            current_value: krUpdateValue,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingKrId(null);
                setKrUpdateValue('');
            },
        });
    }

    function deleteKeyResult(krId: number) {
        if (confirm('Are you sure you want to delete this key result?')) {
            router.delete(`/hr/goals/key-results/${krId}`, { preserveScroll: true });
        }
    }

    function submitProgress(e: FormEvent) {
        e.preventDefault();
        router.post(`/hr/goals/${goal.id}/progress`, {
            current_value: progressForm.current_value || null,
            progress_percentage: progressForm.progress_percentage,
            comment: progressForm.comment || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setProgressOpen(false);
                setProgressForm({ current_value: '', progress_percentage: String(goal.progress_percentage), comment: '' });
            },
        });
    }

    /* ---- Chart data ---- */
    const chartData = (goal.updates ?? [])
        .slice()
        .reverse()
        .map((u) => ({
            date: formatDate(u.created_at),
            progress: u.progress_percentage,
        }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={goal.title} />

            <div className="flex flex-col gap-6 p-6">
                {/* ============================================================ */}
                {/*  PURPLE GRADIENT HEADER                                       */}
                {/* ============================================================ */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                    {/* Decorative circles */}
                    <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute -left-20 -bottom-20 h-72 w-72 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute right-1/3 -bottom-10 h-48 w-48 rounded-full bg-white/5" />

                    <div className="relative flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
                        {/* Left side: title + badges + meta */}
                        <div className="min-w-0 flex-1">
                            <h1 className="text-2xl font-bold md:text-3xl">{goal.title}</h1>

                            {goal.description && (
                                <p className="mt-1 max-w-2xl text-sm text-white/70">{goal.description}</p>
                            )}

                            {/* Badges row */}
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <Badge className={statusBadgeWhite[goal.status] ?? 'bg-white/10 text-white/90 border-white/20'}>
                                    {capitalize(goal.status)}
                                </Badge>
                                <Badge className={priorityBadgeWhite[goal.priority] ?? 'bg-white/10 text-white/80 border-white/20'}>
                                    {capitalize(goal.priority)} Priority
                                </Badge>
                                <Badge className={typeBadgeWhite[goal.goal_type] ?? 'bg-white/10 text-white/90 border-white/20'}>
                                    {capitalize(goal.goal_type)}
                                </Badge>
                            </div>

                            {/* Meta row: assignee, dates, category */}
                            <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/80">
                                {goal.user && (
                                    <span className="flex items-center gap-1.5">
                                        <User className="h-3.5 w-3.5" />
                                        {goal.user.name}
                                    </span>
                                )}
                                <span className="flex items-center gap-1.5">
                                    <Calendar className="h-3.5 w-3.5" />
                                    {formatDate(goal.start_date)} &ndash; {formatDate(goal.due_date)}
                                </span>
                                {goal.category && (
                                    <span className="flex items-center gap-1.5">
                                        <Target className="h-3.5 w-3.5" />
                                        {goal.category}
                                    </span>
                                )}
                                {goal.parent_goal && (
                                    <Link
                                        href={`/hr/goals/${goal.parent_goal.id}`}
                                        className="flex items-center gap-1.5 underline decoration-white/30 hover:text-white transition-colors"
                                    >
                                        Parent: {goal.parent_goal.title}
                                    </Link>
                                )}
                            </div>
                        </div>

                        {/* Right side: large progress display */}
                        <div className="flex flex-col items-center justify-center text-center">
                            <div className="flex items-baseline gap-1">
                                <span className="text-4xl font-bold">{goal.progress_percentage}</span>
                                <span className="text-xl font-semibold text-white/80">%</span>
                            </div>
                            <span className="mt-1 text-sm font-medium text-white/70">Progress</span>
                            {goal.target_value !== null && goal.target_value !== undefined && (
                                <span className="mt-1 text-xs text-white/60">
                                    {goal.current_value ?? 0} / {goal.target_value} {goal.unit ?? ''}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {/* ============================================================ */}
                {/*  TABS                                                         */}
                {/* ============================================================ */}
                <Tabs defaultValue="key-results">
                    <TabsList>
                        <TabsTrigger value="key-results" className="gap-1.5">
                            <ListChecks className="h-4 w-4" />
                            Key Results
                        </TabsTrigger>
                        <TabsTrigger value="child-goals" className="gap-1.5">
                            <Target className="h-4 w-4" />
                            Child Goals
                        </TabsTrigger>
                        <TabsTrigger value="history" className="gap-1.5">
                            <History className="h-4 w-4" />
                            Progress History
                        </TabsTrigger>
                    </TabsList>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Key Results                                         */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="key-results">
                        <div className="space-y-4">
                            {/* Add Key Result button */}
                            {can.manage && (
                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        variant={showKrForm ? 'secondary' : 'default'}
                                        onClick={() => setShowKrForm((p) => !p)}
                                    >
                                        {showKrForm ? (
                                            <>
                                                <ChevronUp className="mr-1.5 h-4 w-4" />
                                                Cancel
                                            </>
                                        ) : (
                                            <>
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Add Key Result
                                            </>
                                        )}
                                    </Button>
                                </div>
                            )}

                            {/* Add Key Result form (collapsible) */}
                            {showKrForm && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">New Key Result</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <form onSubmit={submitKeyResult} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div className="sm:col-span-2">
                                                <Label htmlFor="kr-title">Title</Label>
                                                <Input
                                                    id="kr-title"
                                                    value={krForm.data.title}
                                                    onChange={(e) => krForm.setData('title', e.target.value)}
                                                    placeholder="e.g. Increase customer satisfaction score"
                                                    required
                                                />
                                                {krForm.errors.title && <p className="mt-1 text-xs text-red-500">{krForm.errors.title}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-target">Target Value</Label>
                                                <Input
                                                    id="kr-target"
                                                    type="number"
                                                    step="0.01"
                                                    value={krForm.data.target_value}
                                                    onChange={(e) => krForm.setData('target_value', e.target.value)}
                                                    placeholder="100"
                                                    required
                                                />
                                                {krForm.errors.target_value && <p className="mt-1 text-xs text-red-500">{krForm.errors.target_value}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-unit">Unit</Label>
                                                <Input
                                                    id="kr-unit"
                                                    value={krForm.data.unit}
                                                    onChange={(e) => krForm.setData('unit', e.target.value)}
                                                    placeholder="e.g. %, points, hours"
                                                />
                                                {krForm.errors.unit && <p className="mt-1 text-xs text-red-500">{krForm.errors.unit}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-due">Due Date</Label>
                                                <Input
                                                    id="kr-due"
                                                    type="date"
                                                    value={krForm.data.due_date}
                                                    onChange={(e) => krForm.setData('due_date', e.target.value)}
                                                />
                                                {krForm.errors.due_date && <p className="mt-1 text-xs text-red-500">{krForm.errors.due_date}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-owner">Owner</Label>
                                                <Select
                                                    value={krForm.data.owner_id}
                                                    onValueChange={(val) => krForm.setData('owner_id', val)}
                                                >
                                                    <SelectTrigger id="kr-owner">
                                                        <SelectValue placeholder="Select owner" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {users.map((u) => (
                                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {krForm.errors.owner_id && <p className="mt-1 text-xs text-red-500">{krForm.errors.owner_id}</p>}
                                            </div>
                                            <div className="sm:col-span-2 flex justify-end gap-2">
                                                <Button type="button" variant="outline" onClick={() => setShowKrForm(false)}>Cancel</Button>
                                                <Button type="submit" disabled={krForm.processing}>
                                                    {krForm.processing ? 'Creating...' : 'Create Key Result'}
                                                </Button>
                                            </div>
                                        </form>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Key Results list */}
                            {goal.key_results.length > 0 ? (
                                <div className="space-y-3">
                                    {goal.key_results.map((kr) => (
                                        <Card key={kr.id}>
                                            <CardContent className="pt-5">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <h3 className="text-sm font-semibold">{kr.title}</h3>
                                                            <Badge className={statusColours[kr.status] ?? 'bg-muted text-foreground'}>
                                                                {capitalize(kr.status)}
                                                            </Badge>
                                                        </div>

                                                        {/* Full-width progress bar */}
                                                        <div className="mt-2">
                                                            <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                                <span>{kr.current_value} / {kr.target_value} {kr.unit ?? ''}</span>
                                                                <span>{kr.progress_percentage}%</span>
                                                            </div>
                                                            <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                                                <div
                                                                    className={`h-full rounded-full transition-all ${progressBarColour(kr.progress_percentage)}`}
                                                                    style={{ width: `${Math.min(kr.progress_percentage, 100)}%` }}
                                                                />
                                                            </div>
                                                        </div>

                                                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                            {kr.owner && (
                                                                <span className="flex items-center gap-1">
                                                                    <User className="h-3 w-3" />
                                                                    {kr.owner.name}
                                                                </span>
                                                            )}
                                                            {kr.due_date && (
                                                                <span className="flex items-center gap-1">
                                                                    <Calendar className="h-3 w-3" />
                                                                    {formatDate(kr.due_date)}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Actions */}
                                                    <div className="flex shrink-0 items-center gap-1.5">
                                                        {can.updateProgress && (
                                                            <>
                                                                {editingKrId === kr.id ? (
                                                                    <div className="flex items-center gap-1.5">
                                                                        <Input
                                                                            type="number"
                                                                            step="0.01"
                                                                            className="h-8 w-24 text-xs"
                                                                            value={krUpdateValue}
                                                                            onChange={(e) => setKrUpdateValue(e.target.value)}
                                                                            placeholder={String(kr.current_value)}
                                                                            autoFocus
                                                                        />
                                                                        <Button
                                                                            size="sm"
                                                                            className="h-8 text-xs"
                                                                            onClick={() => updateKeyResultValue(kr.id)}
                                                                            disabled={!krUpdateValue}
                                                                        >
                                                                            Save
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            className="h-8 text-xs"
                                                                            onClick={() => { setEditingKrId(null); setKrUpdateValue(''); }}
                                                                        >
                                                                            Cancel
                                                                        </Button>
                                                                    </div>
                                                                ) : (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="h-8 text-xs"
                                                                        onClick={() => {
                                                                            setEditingKrId(kr.id);
                                                                            setKrUpdateValue(String(kr.current_value));
                                                                        }}
                                                                    >
                                                                        <Pencil className="mr-1 h-3 w-3" />
                                                                        Update
                                                                    </Button>
                                                                )}
                                                            </>
                                                        )}
                                                        {can.manage && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-8 w-8 p-0 text-red-500 hover:text-red-700"
                                                                onClick={() => deleteKeyResult(kr.id)}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-10 text-center">
                                        <ListChecks className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">No key results yet.</p>
                                        {can.manage && (
                                            <Button size="sm" className="mt-3" onClick={() => setShowKrForm(true)}>
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Add First Key Result
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Child Goals                                         */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="child-goals">
                        <div className="space-y-4">
                            {can.manage && (
                                <div className="flex justify-end">
                                    <Button size="sm" asChild>
                                        <Link href={`/hr/goals/create?parent_id=${goal.id}`}>
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add Child Goal
                                        </Link>
                                    </Button>
                                </div>
                            )}

                            {goal.child_goals.length > 0 ? (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {goal.child_goals.map((child) => (
                                        <Link
                                            key={child.id}
                                            href={`/hr/goals/${child.id}`}
                                            className="block"
                                        >
                                            <Card className="h-full transition-shadow hover:shadow-md">
                                                <CardContent className="pt-5">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <h3 className="text-sm font-semibold leading-snug">{child.title}</h3>
                                                        <Badge variant="outline" className="shrink-0 capitalize text-xs">
                                                            {capitalize(child.goal_type)}
                                                        </Badge>
                                                    </div>

                                                    {/* Progress bar */}
                                                    <div className="mt-3">
                                                        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                            <span>{child.progress_percentage}%</span>
                                                            <Badge className={`text-[10px] px-1.5 py-0 ${statusColours[child.status] ?? 'bg-muted text-foreground'}`}>
                                                                {capitalize(child.status)}
                                                            </Badge>
                                                        </div>
                                                        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={`h-full rounded-full transition-all ${progressBarColour(child.progress_percentage)}`}
                                                                style={{ width: `${Math.min(child.progress_percentage, 100)}%` }}
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3" />
                                                            {child.user?.name ?? 'Unassigned'}
                                                        </span>
                                                        <span className="flex items-center gap-1">
                                                            <ListChecks className="h-3 w-3" />
                                                            {child.key_results_count} KR{child.key_results_count !== 1 ? 's' : ''}
                                                        </span>
                                                    </div>

                                                    <div className="mt-2">
                                                        <Badge className={`text-[10px] px-1.5 py-0 ${priorityColours[child.priority] ?? 'bg-muted text-foreground'}`}>
                                                            {capitalize(child.priority)}
                                                        </Badge>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-10 text-center">
                                        <Target className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">No child goals yet.</p>
                                        {can.manage && (
                                            <Button size="sm" className="mt-3" asChild>
                                                <Link href={`/hr/goals/create?parent_id=${goal.id}`}>
                                                    <Plus className="mr-1.5 h-4 w-4" />
                                                    Add First Child Goal
                                                </Link>
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Progress History                                    */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="history">
                        <div className="space-y-4">
                            {/* Update Progress button */}
                            {can.updateProgress && (
                                <div className="flex justify-end">
                                    <Button size="sm" onClick={() => setProgressOpen(true)}>
                                        <TrendingUp className="mr-1.5 h-4 w-4" />
                                        Update Progress
                                    </Button>
                                </div>
                            )}

                            {/* Progress Chart */}
                            {chartData.length >= 2 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <BarChart3 className="h-4 w-4" />
                                            Progress Over Time
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="h-64">
                                            <ResponsiveContainer width="100%" height="100%">
                                                <LineChart data={chartData} margin={{ top: 5, right: 20, left: 0, bottom: 5 }}>
                                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                                    <XAxis
                                                        dataKey="date"
                                                        tick={{ fontSize: 11 }}
                                                        className="text-muted-foreground"
                                                    />
                                                    <YAxis
                                                        domain={[0, 100]}
                                                        tick={{ fontSize: 11 }}
                                                        className="text-muted-foreground"
                                                        tickFormatter={(v) => `${v}%`}
                                                    />
                                                    <Tooltip
                                                        formatter={(value?: number) => [`${value ?? 0}%`, 'Progress']}
                                                        contentStyle={{
                                                            borderRadius: '8px',
                                                            border: '1px solid hsl(var(--border))',
                                                            backgroundColor: 'hsl(var(--background))',
                                                            fontSize: '12px',
                                                        }}
                                                    />
                                                    <Line
                                                        type="monotone"
                                                        dataKey="progress"
                                                        stroke="hsl(var(--primary))"
                                                        strokeWidth={2}
                                                        dot={{ r: 4, fill: 'hsl(var(--primary))' }}
                                                        activeDot={{ r: 6 }}
                                                    />
                                                </LineChart>
                                            </ResponsiveContainer>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Timeline */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <History className="h-4 w-4" />
                                        Update Timeline
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {goal.updates.length > 0 ? (
                                        <div className="relative space-y-0">
                                            {goal.updates.map((update, idx) => (
                                                <div key={update.id} className="relative flex gap-4 pb-6 last:pb-0">
                                                    {/* Timeline line */}
                                                    {idx < goal.updates.length - 1 && (
                                                        <div className="absolute left-[11px] top-6 h-full w-0.5 bg-border" />
                                                    )}
                                                    {/* Timeline dot */}
                                                    <div className="relative z-10 mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-primary bg-background">
                                                        <div className="h-2 w-2 rounded-full bg-primary" />
                                                    </div>
                                                    {/* Content */}
                                                    <div className="min-w-0 flex-1 rounded-lg border p-3">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="text-sm font-medium">{update.user_name}</span>
                                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                                {formatDateTime(update.created_at)}
                                                            </span>
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                                            <span className="flex items-center gap-1">
                                                                <TrendingUp className="h-3 w-3" />
                                                                {update.progress_percentage}%
                                                            </span>
                                                            {update.previous_value !== null && update.new_value !== null && (
                                                                <span>
                                                                    Value: {update.previous_value} &rarr; {update.new_value}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {update.comment && (
                                                            <p className="mt-1.5 text-sm text-foreground/80">{update.comment}</p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="py-8 text-center">
                                            <History className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">No progress updates yet.</p>
                                            {can.updateProgress && (
                                                <Button size="sm" className="mt-3" onClick={() => setProgressOpen(true)}>
                                                    <TrendingUp className="mr-1.5 h-4 w-4" />
                                                    Record First Update
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            {/* ============================================================ */}
            {/*  Progress Update Dialog                                       */}
            {/* ============================================================ */}
            <Dialog open={progressOpen} onOpenChange={setProgressOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Update Progress</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitProgress} className="space-y-4">
                        {goal.target_value !== null && goal.target_value !== undefined && (
                            <div>
                                <Label htmlFor="progress-value">
                                    Current Value {goal.unit ? `(${goal.unit})` : ''}
                                </Label>
                                <Input
                                    id="progress-value"
                                    type="number"
                                    step="0.01"
                                    value={progressForm.current_value}
                                    onChange={(e) => setProgressForm((p) => ({ ...p, current_value: e.target.value }))}
                                    placeholder={`Target: ${goal.target_value}`}
                                />
                            </div>
                        )}
                        <div>
                            <Label htmlFor="progress-pct">Progress Percentage</Label>
                            <div className="flex items-center gap-3">
                                <input
                                    id="progress-pct"
                                    type="range"
                                    min="0"
                                    max="100"
                                    value={progressForm.progress_percentage}
                                    onChange={(e) => setProgressForm((p) => ({ ...p, progress_percentage: e.target.value }))}
                                    className="h-2 flex-1 cursor-pointer appearance-none rounded-lg bg-muted accent-primary"
                                />
                                <span className="w-12 text-right text-sm font-medium">{progressForm.progress_percentage}%</span>
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="progress-comment">Comment</Label>
                            <Textarea
                                id="progress-comment"
                                value={progressForm.comment}
                                onChange={(e) => setProgressForm((p) => ({ ...p, comment: e.target.value }))}
                                placeholder="Describe what was accomplished..."
                                rows={3}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setProgressOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit">Save Update</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
