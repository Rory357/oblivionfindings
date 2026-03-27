import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle2,
    ChevronRight,
    Clock,
    ClipboardList,
    ExternalLink,
    FileText,
    Flame,
    ListTodo,
    OctagonAlert,
    RefreshCw,
    Shield,
    Timer,
    User,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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
type SortKey = 'priority' | 'due_at' | 'created_at';

const priorityOrder: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };

const priorityConfig: Record<string, { border: string; badge: string; bg: string; icon: typeof AlertTriangle | null }> = {
    critical: { border: 'border-l-red-600', badge: 'bg-red-600 text-white', bg: 'bg-red-50/60 dark:bg-red-950/20', icon: Flame },
    high: { border: 'border-l-orange-500', badge: 'bg-orange-500 text-white', bg: 'bg-orange-50/40 dark:bg-orange-950/10', icon: AlertTriangle },
    medium: { border: 'border-l-yellow-500', badge: 'bg-yellow-500 text-black', bg: '', icon: null },
    low: { border: 'border-l-blue-400', badge: 'bg-blue-500 text-white', bg: '', icon: null },
};

const typeConfig: Record<string, { badge: string; icon: typeof Bell; label: string }> = {
    alert: { badge: 'bg-purple-100 text-purple-800 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300', icon: Bell, label: 'Alert' },
    followup: { badge: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/30 dark:text-sky-300', icon: ClipboardList, label: 'Follow-up' },
    note_followup: { badge: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300', icon: FileText, label: 'Note' },
};

function formatRelative(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    const days = Math.floor(hrs / 24);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    if (hrs < 24) return `${hrs}h ago`;
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function formatDue(dueAt: string | null): { text: string; urgency: 'overdue' | 'today' | 'upcoming' | 'none' } {
    if (!dueAt) return { text: '', urgency: 'none' };
    const due = new Date(dueAt);
    const now = new Date();
    const diffMs = due.getTime() - now.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHrs = Math.floor(diffMins / 60);

    if (diffMs < 0) {
        const overMins = Math.abs(diffMins);
        if (overMins < 60) return { text: `${overMins}m overdue`, urgency: 'overdue' };
        const overHrs = Math.floor(overMins / 60);
        if (overHrs < 24) return { text: `${overHrs}h overdue`, urgency: 'overdue' };
        return { text: `${Math.floor(overHrs / 24)}d overdue`, urgency: 'overdue' };
    }
    if (diffHrs < 1) return { text: `${diffMins}m left`, urgency: 'today' };
    if (diffHrs < 24) return { text: `${diffHrs}h left`, urgency: 'today' };
    return { text: due.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' }), urgency: 'upcoming' };
}

const urgencyColors = {
    overdue: 'text-red-600 bg-red-50 dark:bg-red-950/30 dark:text-red-400',
    today: 'text-amber-600 bg-amber-50 dark:bg-amber-950/30 dark:text-amber-400',
    upcoming: 'text-muted-foreground bg-muted/50',
    none: '',
};

export default function MyTasks({ tasks, stats }: Props) {
    const [activeFilter, setActiveFilter] = useState<FilterTab>('all');
    const [sortBy, setSortBy] = useState<SortKey>('priority');
    const [isRefreshing, setIsRefreshing] = useState(false);

    // Auto-refresh every 60s
    useEffect(() => {
        const interval = setInterval(() => {
            if (!document.hidden) router.reload();
        }, 60000);
        return () => clearInterval(interval);
    }, []);

    const handleRefresh = () => {
        setIsRefreshing(true);
        router.reload({ onFinish: () => setIsRefreshing(false) });
    };

    const filtered = (activeFilter === 'all' ? tasks : tasks.filter((t) => t.type === activeFilter));

    const sorted = [...filtered].sort((a, b) => {
        if (sortBy === 'priority') {
            const diff = (priorityOrder[a.priority] ?? 3) - (priorityOrder[b.priority] ?? 3);
            if (diff !== 0) return diff;
            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        }
        if (sortBy === 'due_at') {
            if (!a.due_at && !b.due_at) return 0;
            if (!a.due_at) return 1;
            if (!b.due_at) return -1;
            return new Date(a.due_at).getTime() - new Date(b.due_at).getTime();
        }
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
    });

    const counts: Record<FilterTab, number> = {
        all: tasks.length,
        alert: tasks.filter((t) => t.type === 'alert').length,
        followup: tasks.filter((t) => t.type === 'followup').length,
        note_followup: tasks.filter((t) => t.type === 'note_followup').length,
    };

    const completionRate = stats.total_tasks > 0
        ? Math.round(((stats.total_tasks - stats.overdue) / stats.total_tasks) * 100)
        : 100;

    return (
        <AppLayout breadcrumbs={[{ title: 'My Tasks', href: '/my-tasks' }]}>
            <Head title="My Tasks" />
            <PageShell>
                <PageHeader
                    title="My Tasks"
                    description="Your assigned tasks across all modules."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="ghost" size="sm" onClick={handleRefresh} disabled={isRefreshing}>
                                <RefreshCw className={`mr-2 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                                Refresh
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/control-room/my-tasks">
                                    <Shield className="mr-2 h-4 w-4" />
                                    Control Room Tasks
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Urgent Banner */}
                {(stats.overdue > 0 || stats.critical_count > 0) && (
                    <div className={`flex items-center gap-3 rounded-lg border px-4 py-3 ${
                        stats.overdue > 0
                            ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30'
                            : 'border-orange-300 bg-orange-50 dark:border-orange-800 dark:bg-orange-950/30'
                    }`}>
                        <div className="relative flex h-3 w-3 shrink-0">
                            <span className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 ${stats.overdue > 0 ? 'bg-red-500' : 'bg-orange-500'}`} />
                            <span className={`relative inline-flex h-3 w-3 rounded-full ${stats.overdue > 0 ? 'bg-red-600' : 'bg-orange-600'}`} />
                        </div>
                        <span className={`text-sm font-semibold ${stats.overdue > 0 ? 'text-red-800 dark:text-red-200' : 'text-orange-800 dark:text-orange-200'}`}>
                            {stats.overdue > 0
                                ? `${stats.overdue} overdue task${stats.overdue !== 1 ? 's' : ''} need${stats.overdue === 1 ? 's' : ''} attention`
                                : `${stats.critical_count} critical task${stats.critical_count !== 1 ? 's' : ''} assigned to you`}
                        </span>
                    </div>
                )}

                {/* KPI Row */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <KpiCard label="Total Tasks" value={stats.total_tasks} icon={ListTodo} />
                    <KpiCard
                        label="Critical"
                        value={stats.critical_count}
                        icon={Flame}
                        className={stats.critical_count > 0 ? 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20' : undefined}
                    />
                    <KpiCard label="Due Today" value={stats.due_today} icon={Calendar} />
                    <KpiCard
                        label="Overdue"
                        value={stats.overdue}
                        icon={Timer}
                        className={stats.overdue > 0 ? 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20' : undefined}
                    />
                    <div className="relative overflow-hidden rounded-xl border bg-card p-5 shadow-sm">
                        <div className="text-3xl font-bold tracking-tight">{completionRate}%</div>
                        <div className="mt-1 text-sm text-muted-foreground">On Track</div>
                        <Progress value={completionRate} className="mt-3 h-2" />
                    </div>
                </div>

                {/* Filter + Sort Bar */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    {/* Filter Tabs */}
                    <div className="flex gap-1 rounded-lg border bg-muted/50 p-1">
                        {([
                            { key: 'all' as FilterTab, label: 'All', icon: ListTodo },
                            { key: 'alert' as FilterTab, label: 'Alerts', icon: Bell },
                            { key: 'followup' as FilterTab, label: 'Follow-ups', icon: ClipboardList },
                            { key: 'note_followup' as FilterTab, label: 'Notes', icon: FileText },
                        ]).map((tab) => (
                            <button
                                key={tab.key}
                                onClick={() => setActiveFilter(tab.key)}
                                className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                    activeFilter === tab.key
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <tab.icon className="h-3.5 w-3.5" />
                                {tab.label}
                                <span className={`ml-0.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-xs font-semibold ${
                                    activeFilter === tab.key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted-foreground/15 text-muted-foreground'
                                }`}>
                                    {counts[tab.key]}
                                </span>
                            </button>
                        ))}
                    </div>

                    {/* Sort */}
                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span>Sort:</span>
                        {([
                            { key: 'priority' as SortKey, label: 'Priority' },
                            { key: 'due_at' as SortKey, label: 'Due Date' },
                            { key: 'created_at' as SortKey, label: 'Newest' },
                        ]).map((s) => (
                            <button
                                key={s.key}
                                onClick={() => setSortBy(s.key)}
                                className={`rounded-md px-2 py-1 text-xs transition-colors ${
                                    sortBy === s.key
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'hover:bg-muted'
                                }`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Task List */}
                {sorted.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-20 text-center">
                            <div className="rounded-full bg-green-100 p-4 dark:bg-green-900/30">
                                <CheckCircle2 className="h-10 w-10 text-green-600 dark:text-green-400" />
                            </div>
                            <h3 className="mt-4 text-lg font-semibold">All clear</h3>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                {activeFilter === 'all'
                                    ? "You have no outstanding tasks. Great work!"
                                    : `No ${typeConfig[activeFilter]?.label.toLowerCase()} tasks right now.`}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {sorted.map((task) => {
                            const pConfig = priorityConfig[task.priority] ?? priorityConfig.medium;
                            const tConfig = typeConfig[task.type] ?? typeConfig.alert;
                            const TypeIcon = tConfig.icon;
                            const PriorityIcon = pConfig.icon;
                            const due = formatDue(task.due_at);

                            return (
                                <Link
                                    key={task.id}
                                    href={task.source_url}
                                    className={`group flex items-stretch rounded-lg border border-l-4 ${pConfig.border} ${pConfig.bg} transition-all hover:shadow-md hover:border-primary/30`}
                                >
                                    {/* Main content */}
                                    <div className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3.5">
                                        {/* Type icon circle */}
                                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${tConfig.badge}`}>
                                            <TypeIcon className="h-4.5 w-4.5" />
                                        </div>

                                        {/* Content */}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate font-semibold text-foreground group-hover:text-primary transition-colors">
                                                    {task.title}
                                                </span>
                                                {PriorityIcon && (
                                                    <PriorityIcon className={`h-4 w-4 shrink-0 ${task.priority === 'critical' ? 'text-red-500' : 'text-orange-500'}`} />
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                <Badge variant="outline" className={`${pConfig.badge} px-1.5 py-0 text-[10px]`}>
                                                    {task.priority}
                                                </Badge>
                                                <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
                                                    {task.status}
                                                </Badge>
                                                {task.meta.client_name && (
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {task.meta.client_name}
                                                    </span>
                                                )}
                                                {task.meta.source && (
                                                    <span>{task.meta.source}</span>
                                                )}
                                                {task.meta.asset_name && (
                                                    <span>{task.meta.asset_name}</span>
                                                )}
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {formatRelative(task.created_at)}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Right section: SLA + Due + Arrow */}
                                    <div className="flex shrink-0 items-center gap-3 px-4">
                                        {task.meta.sla_status && (
                                            <span className={`inline-flex h-2.5 w-2.5 rounded-full ${
                                                task.meta.sla_status === 'breached' ? 'bg-red-500' :
                                                task.meta.sla_status === 'at_risk' ? 'bg-yellow-500' : 'bg-green-500'
                                            }`} title={`SLA: ${task.meta.sla_status}`} />
                                        )}
                                        {due.urgency !== 'none' && (
                                            <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${urgencyColors[due.urgency]}`}>
                                                <Timer className="h-3 w-3" />
                                                {due.text}
                                            </span>
                                        )}
                                        <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                )}

                {/* Quick Links */}
                {tasks.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/control-room">
                                <Bell className="mr-2 h-4 w-4" />
                                Control Room Dashboard
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/control-room/alerts">
                                <ListTodo className="mr-2 h-4 w-4" />
                                All Alerts
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/control-room/escalations">
                                <ArrowRight className="mr-2 h-4 w-4" />
                                Escalation Queue
                            </Link>
                        </Button>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
