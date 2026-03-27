import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Calendar,
    CheckCircle2,
    Clock,
    ClipboardList,
    FileText,
    ListTodo,
    OctagonAlert,
} from 'lucide-react';
import { useState } from 'react';

interface Task {
    id: string;
    type: 'alert' | 'followup' | 'note_followup';
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    status: string;
    source_url: string;
    due_at: string | null;
    created_at: string;
    meta: {
        source?: string;
        client_name?: string;
        sla_status?: string;
        asset_name?: string;
    };
}

interface Props {
    tasks: Task[];
    stats: {
        total_tasks: number;
        critical_count: number;
        due_today: number;
        overdue: number;
    };
}

type FilterTab = 'all' | 'alert' | 'followup' | 'note_followup';

const priorityBorderColors: Record<string, string> = {
    critical: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-blue-400',
};

const priorityBadgeColors: Record<string, string> = {
    critical: 'bg-red-100 text-red-800 border-red-200',
    high: 'bg-orange-100 text-orange-800 border-orange-200',
    medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    low: 'bg-blue-100 text-blue-800 border-blue-200',
};

const typeBadgeColors: Record<string, string> = {
    alert: 'bg-purple-100 text-purple-800 border-purple-200',
    followup: 'bg-blue-100 text-blue-800 border-blue-200',
    note_followup: 'bg-gray-100 text-gray-800 border-gray-200',
};

const typeLabels: Record<string, string> = {
    alert: 'Alert',
    followup: 'Follow-up',
    note_followup: 'Note',
};

const filterTabs: { key: FilterTab; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'alert', label: 'Alerts' },
    { key: 'followup', label: 'Follow-ups' },
    { key: 'note_followup', label: 'Notes' },
];

function formatDueDate(dueAt: string | null): { text: string; className: string } | null {
    if (!dueAt) return null;

    const due = new Date(dueAt);
    const now = new Date();
    const todayEnd = new Date();
    todayEnd.setHours(23, 59, 59, 999);

    const isOverdue = due < now;
    const isToday = due >= now && due <= todayEnd;

    const formatted = due.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: due.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
        hour: '2-digit',
        minute: '2-digit',
    });

    if (isOverdue) {
        return { text: `Overdue: ${formatted}`, className: 'text-red-600 font-medium' };
    }
    if (isToday) {
        return { text: `Due today: ${formatted}`, className: 'text-yellow-600 font-medium' };
    }
    return { text: `Due: ${formatted}`, className: 'text-muted-foreground' };
}

function formatCreatedAt(createdAt: string): string {
    const date = new Date(createdAt);
    return date.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const breadcrumbs = [{ title: 'My Tasks', href: '/my-tasks' }];

export default function MyTasks({ tasks, stats }: Props) {
    const [activeFilter, setActiveFilter] = useState<FilterTab>('all');

    const filteredTasks = activeFilter === 'all'
        ? tasks
        : tasks.filter((t) => t.type === activeFilter);

    const filterCounts: Record<FilterTab, number> = {
        all: tasks.length,
        alert: tasks.filter((t) => t.type === 'alert').length,
        followup: tasks.filter((t) => t.type === 'followup').length,
        note_followup: tasks.filter((t) => t.type === 'note_followup').length,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Tasks" />
            <PageShell>
                <PageHeader
                    title="My Tasks"
                    description="Your assigned tasks across all modules"
                />

                {/* KPI Row */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <KpiCard
                        label="Total Tasks"
                        value={stats.total_tasks}
                        icon={ListTodo}
                    />
                    <KpiCard
                        label="Critical"
                        value={stats.critical_count}
                        icon={OctagonAlert}
                        className={stats.critical_count > 0 ? 'border-red-200 bg-red-50/50' : ''}
                    />
                    <KpiCard
                        label="Due Today"
                        value={stats.due_today}
                        icon={Calendar}
                    />
                    <KpiCard
                        label="Overdue"
                        value={stats.overdue}
                        icon={Clock}
                        className={stats.overdue > 0 ? 'border-red-200 bg-red-50/50' : ''}
                    />
                </div>

                {/* Filter Tabs */}
                <div className="flex gap-1 rounded-lg border bg-muted/50 p-1">
                    {filterTabs.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => setActiveFilter(tab.key)}
                            className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                activeFilter === tab.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {tab.label}
                            <span
                                className={`inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-xs font-medium ${
                                    activeFilter === tab.key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted-foreground/20'
                                }`}
                            >
                                {filterCounts[tab.key]}
                            </span>
                        </button>
                    ))}
                </div>

                {/* Task List */}
                <div className="space-y-3">
                    {filteredTasks.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                                <CheckCircle2 className="mb-3 h-12 w-12 text-muted-foreground/40" />
                                <h3 className="text-lg font-medium text-muted-foreground">
                                    {activeFilter === 'all'
                                        ? 'No tasks assigned'
                                        : `No ${typeLabels[activeFilter]?.toLowerCase() ?? ''} tasks`}
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground/70">
                                    {activeFilter === 'all'
                                        ? 'You have no outstanding tasks across any module.'
                                        : 'Try selecting a different filter to see your tasks.'}
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        filteredTasks.map((task) => {
                            const dueInfo = formatDueDate(task.due_at);
                            return (
                                <Card
                                    key={task.id}
                                    className={`border-l-4 ${priorityBorderColors[task.priority] ?? 'border-l-gray-300'} transition-colors hover:bg-accent/50`}
                                >
                                    <CardContent className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0 flex-1 space-y-2">
                                            {/* Type + Priority badges */}
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className={typeBadgeColors[task.type]}
                                                >
                                                    {task.type === 'alert' && <Bell className="mr-1 h-3 w-3" />}
                                                    {task.type === 'followup' && <ClipboardList className="mr-1 h-3 w-3" />}
                                                    {task.type === 'note_followup' && <FileText className="mr-1 h-3 w-3" />}
                                                    {typeLabels[task.type]}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className={priorityBadgeColors[task.priority]}
                                                >
                                                    {task.priority === 'critical' && <AlertTriangle className="mr-1 h-3 w-3" />}
                                                    {task.priority}
                                                </Badge>
                                                <Badge variant="outline">{task.status}</Badge>
                                            </div>

                                            {/* Title */}
                                            <div>
                                                <Link
                                                    href={task.source_url}
                                                    className="text-base font-semibold text-foreground hover:text-primary hover:underline"
                                                >
                                                    {task.title}
                                                </Link>
                                            </div>

                                            {/* Meta info */}
                                            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                {task.meta.client_name && (
                                                    <span>Client: {task.meta.client_name}</span>
                                                )}
                                                {task.meta.source && (
                                                    <span>Source: {task.meta.source}</span>
                                                )}
                                                {task.meta.asset_name && (
                                                    <span>Asset: {task.meta.asset_name}</span>
                                                )}
                                                {task.meta.sla_status && (
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            task.meta.sla_status === 'breached'
                                                                ? 'bg-red-100 text-red-700 border-red-200'
                                                                : task.meta.sla_status === 'at_risk'
                                                                  ? 'bg-yellow-100 text-yellow-700 border-yellow-200'
                                                                  : 'bg-green-100 text-green-700 border-green-200'
                                                        }
                                                    >
                                                        SLA: {task.meta.sla_status}
                                                    </Badge>
                                                )}
                                                <span>Created: {formatCreatedAt(task.created_at)}</span>
                                            </div>
                                        </div>

                                        {/* Due date */}
                                        {dueInfo && (
                                            <div className={`shrink-0 text-sm ${dueInfo.className}`}>
                                                <Clock className="mr-1 inline-block h-3.5 w-3.5" />
                                                {dueInfo.text}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
