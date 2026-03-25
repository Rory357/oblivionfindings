import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, CalendarDays, Clock, Filter, Users } from 'lucide-react';

type ActivityItem = {
    id: string;
    type: string;
    action: string;
    title: string;
    description: string;
    timestamp: string;
    link: string;
};

type Props = {
    activities: ActivityItem[];
    filter: string;
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function typeIcon(type: string) {
    switch (type) {
        case 'shift':
            return <CalendarDays className="h-4 w-4" />;
        case 'timesheet':
            return <Clock className="h-4 w-4" />;
        case 'client':
            return <Users className="h-4 w-4" />;
        default:
            return <Activity className="h-4 w-4" />;
    }
}

function actionColor(action: string): string {
    switch (action) {
        case 'completed':
        case 'approved':
        case 'created':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
        case 'started':
        case 'submitted':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        case 'cancelled':
        case 'rejected':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
        default:
            return 'bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300';
    }
}

export default function ActivityFeed({ activities, filter }: Props) {
    const { labels } = usePage().props as any;
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const FILTERS = [
        { value: 'all', label: 'All Activity' },
        { value: 'shifts', label: 'Shifts' },
        { value: 'timesheets', label: 'Timesheets' },
        { value: 'clients', label: clientPlural },
    ];

    const setFilter = (f: string) => {
        router.get('/operations/activity', { filter: f }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Activity Feed" />
            <PageHeader title="Activity Feed" description={`Recent operational activity across shifts, timesheets, and ${clientPlural.toLowerCase()}.`} backHref="/operations" />
            <PageShell>
                {/* Filters */}
                <div className="mb-4 flex flex-wrap gap-1.5">
                    {FILTERS.map((f) => (
                        <Button
                            key={f.value}
                            size="sm"
                            variant={filter === f.value ? 'default' : 'outline'}
                            className="h-7 text-xs"
                            onClick={() => setFilter(f.value)}
                        >
                            {f.label}
                        </Button>
                    ))}
                </div>

                {/* Activity list */}
                <div className="space-y-2">
                    {activities.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center">
                                <Activity className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">No activity found for the selected filter.</p>
                            </CardContent>
                        </Card>
                    )}
                    {activities.map((item) => (
                        <Link key={item.id} href={item.link} className="block">
                            <Card className="transition-all hover:border-border hover:shadow-sm">
                                <CardContent className="flex items-center gap-4 p-3">
                                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${actionColor(item.action)}`}>
                                        {typeIcon(item.type)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">{item.title}</span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                                {item.type}
                                            </Badge>
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">{item.description}</p>
                                    </div>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {item.timestamp ? formatRelativeTime(item.timestamp) : ''}
                                    </span>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
