import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Calendar,
    ChevronDown,
    ChevronRight,
    Clock,
    Download,
    FileText,
    Search,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Audit Logs', href: '/settings/audit-logs' },
];

type AuditEvent = {
    id: number;
    description: string;
    event?: string | null;
    module?: string | null;
    subject_type?: string | null;
    subject_id?: number | null;
    properties: Record<string, any>;
    causer?: { id: number; name: string; email: string } | null;
    created_at?: string | null;
};

type Props = {
    events: {
        data: AuditEvent[];
        links: any[];
        total: number;
        current_page?: number;
        last_page?: number;
    };
    users: { id: number; name: string }[];
    filters: {
        search?: string;
        user?: string;
        module?: string;
        action?: string;
        date_from?: string;
        date_to?: string;
    };
    stats: {
        today: number;
        this_week: number;
        this_month: number;
    };
};

const MODULES = [
    { value: 'all', label: 'All Modules' },
    { value: 'operations', label: 'Operations' },
    { value: 'hr', label: 'HR' },
    { value: 'fleet', label: 'Fleet' },
    { value: 'settings', label: 'Settings' },
    { value: 'finance', label: 'Finance' },
    { value: 'default', label: 'General' },
];

const ACTION_TYPES = [
    { value: 'all', label: 'All Actions' },
    { value: 'created', label: 'Created' },
    { value: 'updated', label: 'Updated' },
    { value: 'deleted', label: 'Deleted' },
    { value: 'login', label: 'Login' },
    { value: 'logout', label: 'Logout' },
];

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function relativeTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function absoluteTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function eventBadgeColor(event?: string | null): string {
    switch (event) {
        case 'created':
            return 'border-status-success/30 bg-status-success-bg text-status-success';
        case 'updated':
            return 'border-status-info/30 bg-status-info-bg text-status-info';
        case 'deleted':
            return 'border-status-critical/30 bg-status-critical-bg text-status-critical';
        case 'login':
            return 'border-primary bg-primary/10 text-primary';
        case 'logout':
            return 'border-border bg-muted text-foreground';
        default:
            return 'border-border bg-muted text-foreground';
    }
}

function moduleBadgeColor(module?: string | null): string {
    switch (module) {
        case 'operations':
            return 'border-primary bg-primary/10 text-primary';
        case 'hr':
            return 'border-status-info/30 bg-status-info-bg text-status-info';
        case 'fleet':
            return 'border-status-warning/30 bg-status-warning-bg text-status-warning';
        case 'settings':
            return 'border-primary bg-primary/10 text-primary';
        case 'finance':
            return 'border-status-success/30 bg-status-success-bg text-status-success';
        default:
            return 'border-border bg-muted text-foreground';
    }
}

function DiffViewer({ properties }: { properties: Record<string, any> }) {
    const old = properties.old ?? {};
    const attributes = properties.attributes ?? {};
    const keys = [
        ...new Set([...Object.keys(old), ...Object.keys(attributes)]),
    ];

    if (keys.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                No detailed changes recorded.
            </p>
        );
    }

    return (
        <div className="mt-3 space-y-1.5 rounded-md bg-muted p-3 font-mono text-xs">
            {keys.map((key) => (
                <div key={key} className="flex gap-2">
                    <span className="shrink-0 font-semibold text-muted-foreground">
                        {key}:
                    </span>
                    {old[key] !== undefined && (
                        <span className="text-status-critical line-through">
                            {JSON.stringify(old[key])}
                        </span>
                    )}
                    {attributes[key] !== undefined && (
                        <span className="text-status-success">
                            {JSON.stringify(attributes[key])}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function AuditLogs({
    events = { data: [], links: [], total: 0 },
    users = [],
    filters = {},
    stats = { today: 0, this_week: 0, this_month: 0 },
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [userFilter, setUserFilter] = useState(filters.user ?? 'all');
    const [moduleFilter, setModuleFilter] = useState(filters.module ?? 'all');
    const [actionFilter, setActionFilter] = useState(filters.action ?? 'all');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

    const allData = events?.data ?? [];
    const exportParams = new URLSearchParams();

    if (search) exportParams.set('search', search);
    if (userFilter !== 'all') exportParams.set('user', userFilter);
    if (moduleFilter !== 'all') exportParams.set('module', moduleFilter);
    if (actionFilter !== 'all') exportParams.set('action', actionFilter);
    if (dateFrom) exportParams.set('date_from', dateFrom);
    if (dateTo) exportParams.set('date_to', dateTo);

    const exportHref = `/settings/audit-logs/export${exportParams.toString() ? `?${exportParams.toString()}` : ''}`;

    function applyFilters(overrides: Record<string, string> = {}) {
        router.get(
            '/settings/audit-logs',
            {
                search: overrides.search ?? search,
                user: overrides.user ?? userFilter,
                module: overrides.module ?? moduleFilter,
                action: overrides.action ?? actionFilter,
                date_from: overrides.date_from ?? dateFrom,
                date_to: overrides.date_to ?? dateTo,
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        applyFilters();
    }

    function toggleExpanded(id: number) {
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />
            <SettingsLayout>
                <div className="space-y-6">
                    <PageHeader
                        title="Audit Logs"
                        description="Track all changes made across the system"
                        actions={
                            <Button variant="outline" asChild>
                                <a href={exportHref} dusk="audit-export-link">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                        }
                    />

                    {/* Stats Row */}
                    <div className="grid grid-cols-3 gap-4">
                        <OpsStatCard
                            label="Changes Today"
                            value={stats.today}
                            icon={Activity}
                            color="violet"
                        />
                        <OpsStatCard
                            label="This Week"
                            value={stats.this_week}
                            icon={TrendingUp}
                            color="blue"
                        />
                        <OpsStatCard
                            label="This Month"
                            value={stats.this_month}
                            icon={Calendar}
                            color="indigo"
                        />
                    </div>

                    {/* Filter Bar */}
                    <Card>
                        <CardContent className="pt-0">
                            <form onSubmit={handleSearch} className="space-y-3">
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    <div className="relative flex-1">
                                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            dusk="audit-search"
                                            placeholder="Search audit events..."
                                            value={search}
                                            onChange={(e) =>
                                                setSearch(e.target.value)
                                            }
                                            className="pl-9"
                                        />
                                    </div>
                                    <Button type="submit" variant="outline">
                                        <Search className="mr-2 h-4 w-4" />
                                        Search
                                    </Button>
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    <Select
                                        value={userFilter}
                                        onValueChange={(val) => {
                                            setUserFilter(val);
                                            applyFilters({ user: val });
                                        }}
                                    >
                                        <SelectTrigger className="w-[180px]">
                                            <SelectValue placeholder="All Users" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All Users
                                            </SelectItem>
                                            {(users ?? []).map((u) => (
                                                <SelectItem
                                                    key={u.id}
                                                    value={u.id.toString()}
                                                >
                                                    {u.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Select
                                        value={moduleFilter}
                                        onValueChange={(val) => {
                                            setModuleFilter(val);
                                            applyFilters({ module: val });
                                        }}
                                    >
                                        <SelectTrigger className="w-[160px]">
                                            <SelectValue placeholder="All Modules" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {MODULES.map((m) => (
                                                <SelectItem
                                                    key={m.value}
                                                    value={m.value}
                                                >
                                                    {m.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Select
                                        value={actionFilter}
                                        onValueChange={(val) => {
                                            setActionFilter(val);
                                            applyFilters({ action: val });
                                        }}
                                    >
                                        <SelectTrigger className="w-[160px]">
                                            <SelectValue placeholder="All Actions" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {ACTION_TYPES.map((a) => (
                                                <SelectItem
                                                    key={a.value}
                                                    value={a.value}
                                                >
                                                    {a.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Input
                                        type="date"
                                        value={dateFrom}
                                        onChange={(e) => {
                                            setDateFrom(e.target.value);
                                            applyFilters({
                                                date_from: e.target.value,
                                            });
                                        }}
                                        className="w-[150px]"
                                        placeholder="From"
                                    />
                                    <Input
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) => {
                                            setDateTo(e.target.value);
                                            applyFilters({
                                                date_to: e.target.value,
                                            });
                                        }}
                                        className="w-[150px]"
                                        placeholder="To"
                                    />
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Timeline */}
                    <Card>
                        <CardContent className="p-0">
                            {allData.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <div className="mb-4 rounded-full bg-primary/10 p-4">
                                        <FileText className="h-8 w-8 text-primary" />
                                    </div>
                                    <h3 className="text-lg font-semibold">
                                        No audit events found
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Try adjusting your search or filters to
                                        find activity.
                                    </p>
                                </div>
                            ) : (
                                <div className="relative">
                                    {/* Timeline line */}
                                    <div className="absolute top-0 bottom-0 left-8 w-px bg-border" />

                                    <div className="divide-y">
                                        {allData.map((event) => {
                                            const isExpanded = expandedIds.has(
                                                event.id,
                                            );
                                            const hasDetails =
                                                event.properties &&
                                                (Object.keys(
                                                    event.properties.old ?? {},
                                                ).length > 0 ||
                                                    Object.keys(
                                                        event.properties
                                                            .attributes ?? {},
                                                    ).length > 0);

                                            return (
                                                <div
                                                    key={event.id}
                                                    className="group relative flex gap-4 px-6 py-4 transition-colors hover:bg-muted/30"
                                                >
                                                    {/* Timeline dot */}
                                                    <div className="relative z-10 mt-1 flex h-5 w-5 shrink-0 items-center justify-center">
                                                        <div className="h-2.5 w-2.5 rounded-full border-2 border-primary bg-white group-hover:bg-primary/10" />
                                                    </div>

                                                    {/* Content */}
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-start gap-2">
                                                            {/* User avatar + name */}
                                                            <div className="flex items-center gap-2">
                                                                <Avatar className="h-6 w-6">
                                                                    <AvatarFallback className="bg-primary/10 text-[10px] text-primary">
                                                                        {event.causer
                                                                            ? getInitials(
                                                                                  event
                                                                                      .causer
                                                                                      .name,
                                                                              )
                                                                            : 'SY'}
                                                                    </AvatarFallback>
                                                                </Avatar>
                                                                <span className="text-sm font-medium">
                                                                    {event
                                                                        .causer
                                                                        ?.name ??
                                                                        'System'}
                                                                </span>
                                                            </div>

                                                            {/* Description */}
                                                            <p className="flex-1 text-sm text-muted-foreground">
                                                                {
                                                                    event.description
                                                                }
                                                            </p>

                                                            {/* Badges */}
                                                            <div className="flex shrink-0 items-center gap-2">
                                                                {event.event && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className={`text-[10px] ${eventBadgeColor(event.event)}`}
                                                                    >
                                                                        {
                                                                            event.event
                                                                        }
                                                                    </Badge>
                                                                )}
                                                                {event.module && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className={`text-[10px] ${moduleBadgeColor(event.module)}`}
                                                                    >
                                                                        {
                                                                            event.module
                                                                        }
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        </div>

                                                        {/* Timestamp */}
                                                        <div className="mt-1 flex items-center gap-2">
                                                            <Clock className="h-3 w-3 text-muted-foreground" />
                                                            <span
                                                                className="text-xs text-muted-foreground"
                                                                title={absoluteTime(
                                                                    event.created_at,
                                                                )}
                                                            >
                                                                {relativeTime(
                                                                    event.created_at,
                                                                )}
                                                            </span>
                                                            {event.subject_type && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {
                                                                        event.subject_type
                                                                    }
                                                                    {event.subject_id
                                                                        ? ` #${event.subject_id}`
                                                                        : ''}
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Expandable details */}
                                                        {hasDetails && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    toggleExpanded(
                                                                        event.id,
                                                                    )
                                                                }
                                                                className="mt-2 h-auto gap-1 p-0 text-xs font-medium text-primary hover:bg-transparent hover:text-primary"
                                                            >
                                                                {isExpanded ? (
                                                                    <ChevronDown className="h-3 w-3" />
                                                                ) : (
                                                                    <ChevronRight className="h-3 w-3" />
                                                                )}
                                                                {isExpanded
                                                                    ? 'Hide changes'
                                                                    : 'View changes'}
                                                            </Button>
                                                        )}
                                                        {isExpanded &&
                                                            hasDetails && (
                                                                <DiffViewer
                                                                    properties={
                                                                        event.properties
                                                                    }
                                                                />
                                                            )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pagination */}
                    {(events?.links ?? []).length > 3 && (
                        <div className="flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">
                                Showing {allData.length} of {events.total}{' '}
                                events
                            </p>
                            <div className="flex gap-1">
                                {(events.links ?? []).map(
                                    (link: any, i: number) => (
                                        <Button
                                            key={i}
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            disabled={!link.url}
                                            className={
                                                link.active
                                                    ? 'bg-primary hover:bg-primary'
                                                    : ''
                                            }
                                            asChild={!!link.url}
                                        >
                                            {link.url ? (
                                                <Link
                                                    href={link.url}
                                                    dangerouslySetInnerHTML={{
                                                        __html: link.label,
                                                    }}
                                                />
                                            ) : (
                                                <span
                                                    dangerouslySetInnerHTML={{
                                                        __html: link.label,
                                                    }}
                                                />
                                            )}
                                        </Button>
                                    ),
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
