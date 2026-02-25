import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Plus } from 'lucide-react';

interface Checklist {
    id: number;
    employee_profile: {
        id: number;
        user: { name: string };
    };
    template_key: string;
    status: 'pending' | 'in_progress' | 'completed' | 'cancelled' | 'overdue';
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    tasks_count: number;
    tasks_completed_count: number;
}

interface Props {
    checklists: {
        data: Checklist[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    summary: {
        pending: number;
        in_progress: number;
        completed: number;
        overdue: number;
        due_next_7_days: number;
        total: number;
    };
    filters: { status: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Offboarding', href: '/hr/offboarding' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        label: 'In Progress',
    },
    completed: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Completed',
    },
    cancelled: {
        className: 'border-orange-500/30 text-orange-400 bg-orange-500/10',
        label: 'Cancelled',
    },
    overdue: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Overdue',
    },
};

export default function OffboardingIndex({ checklists, summary, filters, can }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/offboarding', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Offboarding" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Offboarding Checklists</h1>
                    {can.manage && (
                        <Button asChild>
                            <Link href="/hr/offboarding/create">
                                <Plus className="mr-2 h-4 w-4" />
                                Start Offboarding
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-3 md:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Pending</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{summary.pending}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">In Progress</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{summary.in_progress}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Overdue</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{summary.overdue}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Due in 7 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{summary.due_next_7_days}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Completed</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{summary.completed}</p>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search by employee name..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Employee</th>
                                    <th className="px-4 py-3 text-left font-medium">Template</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Progress</th>
                                    <th className="px-4 py-3 text-left font-medium">Due Date</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {checklists.data.map((checklist) => {
                                    const config = statusConfig[checklist.status] || statusConfig.pending;
                                    const progressPercent = checklist.tasks_count > 0
                                        ? Math.round((checklist.tasks_completed_count / checklist.tasks_count) * 100)
                                        : 0;
                                    return (
                                        <tr key={checklist.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{checklist.employee_profile.user.name}</td>
                                            <td className="px-4 py-3 capitalize text-muted-foreground">
                                                {checklist.template_key.replace(/_/g, ' ')}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Progress value={progressPercent} className="w-20" />
                                                    <span className="text-xs text-muted-foreground">
                                                        {checklist.tasks_completed_count}/{checklist.tasks_count}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{checklist.due_date || '-'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/hr/offboarding/${checklist.id}`}>View</Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {checklists.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                            No offboarding checklists found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {checklists.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(checklists.current_page - 1) * checklists.per_page + 1} to{' '}
                            {Math.min(checklists.current_page * checklists.per_page, checklists.total)} of{' '}
                            {checklists.total} results
                        </p>
                        <div className="flex items-center gap-1">
                            {checklists.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
