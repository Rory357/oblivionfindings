import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Target } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface Goal {
    id: number;
    title: string;
    goal_type: string;
    priority: string;
    status: string;
    progress_percentage: number;
    start_date: string;
    due_date: string;
    user: { id: number; name: string };
    parent_goal: { id: number; title: string } | null;
}

interface UserItem {
    id: number;
    name: string;
}

interface Props {
    goals: { data: Goal[]; links: any[] };
    users: UserItem[];
    filters: { status: string | null; goal_type: string | null; priority: string | null; user_id: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Goals', href: '/hr/goals' },
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

export default function GoalsIndex({ goals, users, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/goals', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goals & OKRs" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Goals & OKRs</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Track individual, team, and company goals
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" asChild>
                            <Link href="/hr/goals/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Goal
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) => onFilter({ status: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Type</Label>
                            <Select
                                value={filters.goal_type || 'all'}
                                onValueChange={(val) => onFilter({ goal_type: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="individual">Individual</SelectItem>
                                    <SelectItem value="team">Team</SelectItem>
                                    <SelectItem value="company">Company</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Priority</Label>
                            <Select
                                value={filters.priority || 'all'}
                                onValueChange={(val) => onFilter({ priority: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Priorities</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Employee</Label>
                            <Select
                                value={filters.user_id || 'all'}
                                onValueChange={(val) => onFilter({ user_id: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Employees</SelectItem>
                                    {users.map((u) => (
                                        <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Goals Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Goal</TableHead>
                                    <TableHead>Assignee</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Progress</TableHead>
                                    <TableHead>Due Date</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {goals.data.map((goal) => (
                                    <TableRow key={goal.id} className="cursor-pointer hover:bg-muted/50" onClick={() => router.get(`/hr/goals/${goal.id}`)}>
                                        <TableCell>
                                            <div className="font-medium">{goal.title}</div>
                                            {goal.parent_goal && (
                                                <div className="text-xs text-slate-400">
                                                    Parent: {goal.parent_goal.title}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>{goal.user?.name}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className="capitalize">{goal.goal_type}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${priorityColors[goal.priority] ?? ''}`}>
                                                {goal.priority}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
                                                    <div
                                                        className="h-full rounded-full bg-blue-500 transition-all"
                                                        style={{ width: `${Math.min(goal.progress_percentage, 100)}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs text-slate-600">{goal.progress_percentage}%</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">{formatDate(goal.due_date)}</TableCell>
                                        <TableCell>
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[goal.status] ?? ''}`}>
                                                {goal.status}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!goals.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-sm text-slate-500">
                                            No goals found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {goals?.links?.length ? (
                    <LaravelPagination links={goals.links} />
                ) : null}
            </div>
        </AppLayout>
    );
}
