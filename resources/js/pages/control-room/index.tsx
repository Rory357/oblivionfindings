import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle,
    Clock,
    FileText,
    Search,
    TrendingUp,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface Alert {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    escalation_level: number;
    triggered_at: string | null;
    acknowledged_at: string | null;
    asset_id: number | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    assigned_to: { id: number; name: string } | null;
    notes: string | null;
}

interface Props {
    alerts: {
        data: Alert[];
        links: any[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    stats: {
        total: number;
        open: number;
        acknowledged: number;
        triaging: number;
        resolved: number;
        closed: number;
        critical: number;
        high: number;
        escalated: number;
        unassigned: number;
        my_alerts: number;
    };
    daily_trend: { date: string; count: number }[];
    by_severity: Record<string, number>;
    staff: { id: number; name: string }[];
    filters: Record<string, string>;
    can: {
        manage: boolean;
        assign: boolean;
        escalate: boolean;
        create: boolean;
        viewReports: boolean;
    };
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-blue-500 text-white',
};

const statusColors: Record<string, string> = {
    open: 'bg-red-100 text-red-800 border-red-200',
    ack: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    triaging: 'bg-blue-100 text-blue-800 border-blue-200',
    resolved: 'bg-green-100 text-green-800 border-green-200',
    closed: 'bg-gray-100 text-gray-800 border-gray-200',
};

const severityChartColors: Record<string, string> = {
    critical: 'bg-red-500',
    high: 'bg-orange-500',
    medium: 'bg-yellow-500',
    low: 'bg-blue-500',
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

export default function ControlRoomIndex({
    alerts,
    stats,
    daily_trend,
    by_severity,
    staff,
    filters,
    can,
}: Props) {
    const [searchValue, setSearchValue] = useState(filters.search || '');

    const applyFilter = (key: string, value: string) => {
        router.get(
            '/control-room',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter('search', searchValue);
    };

    const clearFilters = () => {
        router.get('/control-room', {}, { preserveState: true });
        setSearchValue('');
    };

    const hasFilters = Object.values(filters).some((v) => v);

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Control Room', href: '/control-room' }]}
        >
            <Head title="Control Room" />
            <PageShell>
                <PageHeader
                    title="Control Room"
                    description="Centralized alert management and triage system."
                    actions={
                        can.viewReports ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/control-room/reports">
                                    <FileText className="mr-2 h-4 w-4" />
                                    Reports
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                {/* Statistics Cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <button
                        onClick={() => applyFilter('status', 'open')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <Bell className="h-4 w-4 text-red-500" />
                            <span className="text-xs text-muted-foreground">
                                Open
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.open}
                        </div>
                    </button>
                    <button
                        onClick={() => applyFilter('status', 'ack')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <CheckCircle className="h-4 w-4 text-yellow-500" />
                            <span className="text-xs text-muted-foreground">
                                Acknowledged
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.acknowledged}
                        </div>
                    </button>
                    <button
                        onClick={() => applyFilter('status', 'triaging')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-blue-500" />
                            <span className="text-xs text-muted-foreground">
                                Triaging
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.triaging}
                        </div>
                    </button>
                    <button
                        onClick={() => applyFilter('severity', 'critical')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-red-600" />
                            <span className="text-xs text-muted-foreground">
                                Critical
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold text-red-600">
                            {stats.critical}
                        </div>
                    </button>
                    <button
                        onClick={() => applyFilter('escalation_level', '1')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-4 w-4 text-orange-500" />
                            <span className="text-xs text-muted-foreground">
                                Escalated
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.escalated}
                        </div>
                    </button>
                    <button
                        onClick={() => applyFilter('assigned_to', 'me')}
                        className="rounded-lg border bg-card p-3 text-left transition-colors hover:bg-accent"
                    >
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 text-indigo-500" />
                            <span className="text-xs text-muted-foreground">
                                My Alerts
                            </span>
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.my_alerts}
                        </div>
                    </button>
                </div>

                {/* Charts Row */}
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    {/* Daily Alert Trend */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Daily Alert Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex h-32 items-end gap-1">
                                {daily_trend.map((day, i) => {
                                    const maxCount = Math.max(...daily_trend.map(d => d.count), 1);
                                    const heightPct = (day.count / maxCount) * 100;
                                    return (
                                        <div key={i} className="group relative flex-1">
                                            <div
                                                className="w-full rounded-t bg-primary transition-all hover:bg-primary/80"
                                                style={{
                                                    height: `${heightPct}%`,
                                                    minHeight: day.count > 0 ? '4px' : '2px',
                                                }}
                                            />
                                            <div className="absolute bottom-full left-1/2 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-popover px-2 py-1 text-xs shadow-md group-hover:block z-10">
                                                {day.date}: {day.count}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                                <span>{daily_trend[0]?.date}</span>
                                <span>{daily_trend[daily_trend.length - 1]?.date}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* By Severity */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">By Severity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {['critical', 'high', 'medium', 'low'].map((sev) => {
                                    const count = by_severity[sev] || 0;
                                    const pct = stats.total > 0 ? (count / stats.total) * 100 : 0;
                                    return (
                                        <div key={sev}>
                                            <div className="mb-1 flex justify-between text-xs">
                                                <span className="capitalize">{sev}</span>
                                                <span className="text-muted-foreground">
                                                    {count} ({pct.toFixed(0)}%)
                                                </span>
                                            </div>
                                            <div className="h-2 rounded-full bg-muted">
                                                <div
                                                    className={`h-full rounded-full ${severityChartColors[sev]}`}
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="mt-4 rounded-lg border bg-card p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <form onSubmit={handleSearch} className="flex gap-2">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    type="text"
                                    placeholder="Search alerts..."
                                    value={searchValue}
                                    onChange={(e) =>
                                        setSearchValue(e.target.value)
                                    }
                                    className="w-48 pl-9"
                                />
                            </div>
                            <Button type="submit" variant="secondary" size="sm">
                                Search
                            </Button>
                        </form>

                        <Select
                            value={filters.status || 'all'}
                            onValueChange={(v) => applyFilter('status', v)}
                        >
                            <SelectTrigger className="w-32">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="open">Open</SelectItem>
                                <SelectItem value="ack">Acknowledged</SelectItem>
                                <SelectItem value="triaging">
                                    Triaging
                                </SelectItem>
                                <SelectItem value="resolved">
                                    Resolved
                                </SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.severity || 'all'}
                            onValueChange={(v) => applyFilter('severity', v)}
                        >
                            <SelectTrigger className="w-32">
                                <SelectValue placeholder="Severity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Severity</SelectItem>
                                <SelectItem value="critical">
                                    Critical
                                </SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="low">Low</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.source || 'all'}
                            onValueChange={(v) => applyFilter('source', v)}
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="Source" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sources</SelectItem>
                                <SelectItem value="fleet">Fleet</SelectItem>
                                <SelectItem value="personal_tracker">
                                    Personal Tracker
                                </SelectItem>
                                <SelectItem value="manual">Manual</SelectItem>
                                <SelectItem value="external">
                                    External
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.assigned_to || 'all'}
                            onValueChange={(v) => applyFilter('assigned_to', v === 'all' ? '' : v)}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Assignee" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Assignees</SelectItem>
                                <SelectItem value="me">Assigned to Me</SelectItem>
                                <SelectItem value="unassigned">
                                    Unassigned
                                </SelectItem>
                                {staff.map((s) => (
                                    <SelectItem
                                        key={s.id}
                                        value={s.id.toString()}
                                    >
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                            >
                                <XCircle className="mr-1 h-4 w-4" />
                                Clear
                            </Button>
                        )}
                    </div>
                </div>

                {/* Alerts List */}
                <div className="mt-4 rounded-lg border">
                    <div className="border-b bg-muted/50 px-4 py-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">
                                Alerts ({alerts.meta.total})
                            </span>
                            {alerts.meta.last_page > 1 && (
                                <span className="text-xs text-muted-foreground">
                                    Page {alerts.meta.current_page} of{' '}
                                    {alerts.meta.last_page}
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="divide-y">
                        {alerts.data.length ? (
                            alerts.data.map((alert) => (
                                <Link
                                    key={alert.id}
                                    href={`/control-room/alerts/${alert.id}`}
                                    className="flex items-center justify-between gap-4 px-4 py-3 transition-colors hover:bg-muted/50"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {alert.alert_type}
                                            </span>
                                            {alert.escalation_level > 0 && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-orange-300 text-orange-600"
                                                >
                                                    L{alert.escalation_level}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {formatRelativeTime(
                                                    alert.triggered_at,
                                                )}
                                            </span>
                                            <span>|</span>
                                            <span>{alert.source}</span>
                                            {alert.asset && (
                                                <>
                                                    <span>|</span>
                                                    <span>
                                                        {alert.asset.name}
                                                    </span>
                                                </>
                                            )}
                                            {alert.assigned_to && (
                                                <>
                                                    <span>|</span>
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {alert.assigned_to.name}
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                        {alert.notes && (
                                            <div className="mt-1 truncate text-xs text-muted-foreground">
                                                {alert.notes}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge
                                            className={
                                                severityColors[alert.severity] ||
                                                ''
                                            }
                                        >
                                            {alert.severity}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className={
                                                statusColors[alert.status] || ''
                                            }
                                        >
                                            {alert.status}
                                        </Badge>
                                    </div>
                                </Link>
                            ))
                        ) : (
                            <div className="px-4 py-8 text-center text-sm text-muted-foreground">
                                No alerts found matching your filters.
                            </div>
                        )}
                    </div>

                    {/* Pagination */}
                    {alerts.meta.last_page > 1 && (
                        <div className="flex items-center justify-center gap-2 border-t px-4 py-3">
                            {alerts.links
                                .filter(
                                    (link) =>
                                        link.url &&
                                        !link.label.includes('Previous') &&
                                        !link.label.includes('Next'),
                                )
                                .slice(0, 10)
                                .map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                        disabled={!link.url}
                                    >
                                        <Link
                                            href={link.url || '#'}
                                            preserveState
                                            preserveScroll
                                        >
                                            {link.label
                                                .replace('&laquo;', '«')
                                                .replace('&raquo;', '»')}
                                        </Link>
                                    </Button>
                                ))}
                        </div>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
