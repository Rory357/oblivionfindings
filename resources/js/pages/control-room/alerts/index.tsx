import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Circle,
    Clock,
    Eye,
    Filter,
    ShieldAlert,
    User,
    UserPlus,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface AlertItem {
    id: number;
    source: string;
    alert_type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    status: string;
    escalation_level: number | null;
    triggered_at: string | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    assigned_to: { id: number; name: string } | null;
    client_name: string | null;
    sla_status: 'green' | 'yellow' | 'red' | null;
    notes: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    alerts: {
        data: AlertItem[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Record<string, string | undefined>;
    stats: {
        total: number;
        open: number;
        critical: number;
        assigned_to_me: number;
        unassigned: number;
    };
    staff: Array<{ id: number; name: string; email: string }>;
    can: {
        manage: boolean;
        assign: boolean;
    };
    basePath?: string;
    pageTitle?: string;
    pageDescription?: string;
    pageBreadcrumbs?: Array<{ title: string; href: string }>;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-success text-white',
};

const severityBorders: Record<string, string> = {
    critical: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-green-600',
};

const statusColors: Record<string, string> = {
    open: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    ack: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    triaging: 'bg-status-info-bg text-status-info border-status-info/30',
    resolved:
        'bg-status-success-bg text-status-success border-status-success/30',
    closed: 'bg-muted text-foreground border-border',
};

const statusLabels: Record<string, string> = {
    open: 'Open',
    ack: 'Acknowledged',
    triaging: 'Triaging',
    resolved: 'Resolved',
    closed: 'Closed',
};

const slaColors: Record<string, string> = {
    green: 'text-status-success',
    yellow: 'text-status-warning',
    red: 'text-status-critical',
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

function severityIcon(severity: string) {
    switch (severity) {
        case 'critical':
            return <ShieldAlert className="h-3 w-3" />;
        case 'high':
            return <AlertTriangle className="h-3 w-3" />;
        default:
            return null;
    }
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function AlertsIndex({
    alerts,
    filters,
    stats,
    staff,
    can,
    basePath = '/control-room/alerts',
    pageTitle = 'Alerts',
    pageDescription = 'Monitor and manage all control room alerts',
    pageBreadcrumbs = [
        { title: 'Control Room', href: '/control-room' },
        { title: 'All Alerts', href: '/control-room/alerts' },
    ],
}: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [assignDialogOpen, setAssignDialogOpen] = useState(false);
    const [assignUserId, setAssignUserId] = useState<string>('');
    const searchRef = useRef<HTMLInputElement>(null);

    // 30-second auto-refresh
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({ only: ['alerts', 'stats'], preserveScroll: true });
        }, 30000);
        return () => clearInterval(interval);
    }, []);

    // Clear selection on page change
    useEffect(() => {
        setSelected(new Set());
    }, [alerts.current_page]);

    // ------------------------------------------------------------------
    // Filter helpers
    // ------------------------------------------------------------------

    const applyFilter = useCallback(
        (key: string, value: string) => {
            const newFilters = { ...filters, [key]: value || undefined };
            // Reset to page 1 when filtering
            router.get(basePath, newFilters as Record<string, string>, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [basePath, filters],
    );

    const applyQuickFilter = useCallback(
        (preset: Record<string, string | undefined>) => {
            router.get(basePath, preset as Record<string, string>, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [basePath],
    );

    const clearFilters = useCallback(() => {
        router.get(basePath, {}, { preserveState: true, preserveScroll: true });
    }, [basePath]);

    const hasFilters = Object.values(filters).some((v) => v);

    // ------------------------------------------------------------------
    // Sorting
    // ------------------------------------------------------------------

    const currentSort = filters.sort || 'triggered_at';
    const currentDir = filters.dir || 'desc';

    const toggleSort = useCallback(
        (field: string) => {
            let dir = 'asc';
            if (currentSort === field && currentDir === 'asc') dir = 'desc';
            const newFilters = { ...filters, sort: field, dir };
            router.get(basePath, newFilters as Record<string, string>, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [basePath, currentSort, currentDir, filters],
    );

    const renderSortIcon = (field: string) => {
        if (currentSort !== field)
            return <ChevronDown className="h-3 w-3 opacity-30" />;
        return currentDir === 'asc' ? (
            <ChevronUp className="h-3 w-3" />
        ) : (
            <ChevronDown className="h-3 w-3" />
        );
    };

    // ------------------------------------------------------------------
    // Selection helpers
    // ------------------------------------------------------------------

    const allOnPageSelected =
        alerts.data.length > 0 && alerts.data.every((a) => selected.has(a.id));

    const toggleAll = () => {
        if (allOnPageSelected) {
            setSelected(new Set());
        } else {
            setSelected(new Set(alerts.data.map((a) => a.id)));
        }
    };

    const toggleOne = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    // ------------------------------------------------------------------
    // Bulk actions
    // ------------------------------------------------------------------

    const bulkAcknowledge = () => {
        router.post(
            '/control-room/alerts/bulk-acknowledge',
            { alert_ids: Array.from(selected) },
            { preserveScroll: true, onSuccess: () => setSelected(new Set()) },
        );
    };

    const bulkAssign = () => {
        if (!assignUserId) return;
        router.post(
            '/control-room/alerts/bulk-assign',
            {
                alert_ids: Array.from(selected),
                assigned_to_user_id: Number(assignUserId),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected(new Set());
                    setAssignDialogOpen(false);
                    setAssignUserId('');
                },
            },
        );
    };

    // ------------------------------------------------------------------
    // Inline actions
    // ------------------------------------------------------------------

    const inlineAcknowledge = (id: number) => {
        router.post(
            `/control-room/alerts/${id}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    };

    const inlineAssignToMe = (id: number) => {
        router.post(
            `/control-room/alerts/${id}/assign-to-me`,
            {},
            { preserveScroll: true },
        );
    };

    // ------------------------------------------------------------------
    // Quick filter tabs config
    // ------------------------------------------------------------------

    const tabs = [
        { label: 'All', count: stats.total, filter: {} },
        { label: 'Open', count: stats.open, filter: { status: 'open' } },
        {
            label: 'Critical',
            count: stats.critical,
            filter: { severity: 'critical' },
        },
        {
            label: 'Assigned to Me',
            count: stats.assigned_to_me,
            filter: { assigned_to: 'me' },
        },
        {
            label: 'Unassigned',
            count: stats.unassigned,
            filter: { assigned_to: 'unassigned' },
        },
    ];

    const activeTab = (() => {
        if (filters.assigned_to === 'me') return 'Assigned to Me';
        if (filters.assigned_to === 'unassigned') return 'Unassigned';
        if (filters.status === 'open' && !filters.severity) return 'Open';
        if (filters.severity === 'critical' && !filters.status)
            return 'Critical';
        if (!hasFilters) return 'All';
        return null;
    })();

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    return (
        <AppLayout breadcrumbs={pageBreadcrumbs}>
            <Head
                title={
                    pageTitle === 'Alerts'
                        ? 'Control Room Alerts'
                        : `${pageTitle} - Control Room`
                }
            />

            <div className="flex flex-col gap-4 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {pageTitle}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {pageDescription}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room">Dashboard</Link>
                    </Button>
                </div>

                {/* Quick filter tabs */}
                <div className="flex flex-wrap gap-1 rounded-lg border bg-muted/40 p-1">
                    {tabs.map((tab) => (
                        <Button
                            key={tab.label}
                            type="button"
                            variant="ghost"
                            onClick={() => applyQuickFilter(tab.filter)}
                            className={`h-auto gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                activeTab === tab.label
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {tab.label}
                            <span
                                className={`inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1 text-xs font-semibold ${
                                    activeTab === tab.label
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                }`}
                            >
                                {tab.count}
                            </span>
                        </Button>
                    ))}
                </div>

                {/* Filter bar */}
                <Card className="flex flex-row flex-wrap items-end gap-3 rounded-lg p-3">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
                        <Filter className="h-4 w-4" />
                        Filters
                    </div>

                    <Input
                        ref={searchRef}
                        placeholder="Search alerts..."
                        defaultValue={filters.search || ''}
                        className="h-9 w-52"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')
                                applyFilter(
                                    'search',
                                    (e.target as HTMLInputElement).value,
                                );
                        }}
                    />

                    <Select
                        value={filters.severity || 'all'}
                        onValueChange={(v) =>
                            applyFilter('severity', v === 'all' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Severity" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Severity</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.source || 'all'}
                        onValueChange={(v) =>
                            applyFilter('source', v === 'all' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Source" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Sources</SelectItem>
                            <SelectItem value="fleet">Fleet</SelectItem>
                            <SelectItem value="personal_tracker">
                                Tracker
                            </SelectItem>
                            <SelectItem value="manual">Manual</SelectItem>
                            <SelectItem value="external">External</SelectItem>
                            <SelectItem value="compliance">
                                Compliance
                            </SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) =>
                            applyFilter('status', v === 'all' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="ack">Acknowledged</SelectItem>
                            <SelectItem value="triaging">Triaging</SelectItem>
                            <SelectItem value="resolved">Resolved</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>

                    <Input
                        type="date"
                        placeholder="From"
                        defaultValue={filters.date_from || ''}
                        className="h-9 w-36"
                        onChange={(e) =>
                            applyFilter('date_from', e.target.value)
                        }
                    />

                    <Input
                        type="date"
                        placeholder="To"
                        defaultValue={filters.date_to || ''}
                        className="h-9 w-36"
                        onChange={(e) => applyFilter('date_to', e.target.value)}
                    />

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-9"
                            onClick={clearFilters}
                        >
                            <X className="mr-1 h-3 w-3" />
                            Clear
                        </Button>
                    )}
                </Card>

                {/* Bulk actions bar */}
                {selected.size > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2">
                        <span className="text-sm font-medium">
                            {selected.size} selected
                        </span>
                        <div className="h-4 w-px bg-border" />
                        {can.manage && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={bulkAcknowledge}
                            >
                                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                Acknowledge Selected
                            </Button>
                        )}
                        {can.assign && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAssignDialogOpen(true)}
                            >
                                <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                                Assign Selected
                            </Button>
                        )}
                        <div className="flex-1" />
                        <Button variant="ghost" size="sm" onClick={toggleAll}>
                            {allOnPageSelected ? 'Deselect All' : 'Select All'}
                        </Button>
                    </div>
                )}

                {/* Alerts table */}
                <Card className="gap-0 overflow-x-auto rounded-lg p-0">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                <th className="w-10 px-3 py-3">
                                    <Checkbox
                                        checked={allOnPageSelected}
                                        onCheckedChange={toggleAll}
                                    />
                                </th>
                                <th
                                    className="cursor-pointer px-3 py-3"
                                    onClick={() => toggleSort('alert_type')}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        Alert Type{' '}
                                        {renderSortIcon('alert_type')}
                                    </span>
                                </th>
                                <th className="px-3 py-3">Source</th>
                                <th
                                    className="cursor-pointer px-3 py-3"
                                    onClick={() => toggleSort('severity')}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        Severity {renderSortIcon('severity')}
                                    </span>
                                </th>
                                <th
                                    className="cursor-pointer px-3 py-3"
                                    onClick={() => toggleSort('status')}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        Status {renderSortIcon('status')}
                                    </span>
                                </th>
                                <th className="px-3 py-3">SLA</th>
                                <th
                                    className="cursor-pointer px-3 py-3"
                                    onClick={() => toggleSort('triggered_at')}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        Triggered{' '}
                                        {renderSortIcon('triggered_at')}
                                    </span>
                                </th>
                                <th className="px-3 py-3">Assigned</th>
                                <th className="px-3 py-3 text-right">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {alerts.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={9}
                                        className="px-3 py-16 text-center"
                                    >
                                        <Bell className="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" />
                                        <p className="text-sm text-muted-foreground">
                                            No alerts found matching your
                                            filters.
                                        </p>
                                    </td>
                                </tr>
                            ) : (
                                alerts.data.map((alert, idx) => (
                                    <tr
                                        key={alert.id}
                                        className={`border-b border-l-4 transition-colors hover:bg-muted/40 ${
                                            severityBorders[alert.severity] ??
                                            'border-l-transparent'
                                        } ${idx % 2 === 1 ? 'bg-muted/20' : ''} ${
                                            selected.has(alert.id)
                                                ? 'bg-primary/5'
                                                : ''
                                        }`}
                                    >
                                        <td className="px-3 py-2.5">
                                            <Checkbox
                                                checked={selected.has(alert.id)}
                                                onCheckedChange={() =>
                                                    toggleOne(alert.id)
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {alert.alert_type}
                                                </span>
                                                {alert.escalation_level &&
                                                    alert.escalation_level >
                                                        0 && (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-status-warning/30 px-1 py-0 text-[10px] text-status-warning"
                                                        >
                                                            L
                                                            {
                                                                alert.escalation_level
                                                            }
                                                        </Badge>
                                                    )}
                                            </div>
                                            {alert.client_name && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {alert.client_name}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span className="text-xs text-muted-foreground capitalize">
                                                {alert.source?.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <Badge
                                                className={`inline-flex items-center gap-1 ${
                                                    severityColors[
                                                        alert.severity
                                                    ] ??
                                                    'bg-muted-foreground/80 text-white'
                                                }`}
                                            >
                                                {severityIcon(alert.severity)}
                                                {alert.severity}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    statusColors[
                                                        alert.status
                                                    ] ?? ''
                                                }
                                            >
                                                {statusLabels[alert.status] ??
                                                    alert.status}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {alert.sla_status ? (
                                                <Circle
                                                    className={`h-3 w-3 fill-current ${
                                                        slaColors[
                                                            alert.sla_status
                                                        ] ??
                                                        'text-muted-foreground'
                                                    }`}
                                                />
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    -
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span
                                                className="flex items-center gap-1 text-xs text-muted-foreground"
                                                title={
                                                    alert.triggered_at
                                                        ? new Date(
                                                              alert.triggered_at,
                                                          ).toLocaleString()
                                                        : ''
                                                }
                                            >
                                                <Clock className="h-3 w-3" />
                                                {formatRelativeTime(
                                                    alert.triggered_at,
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {alert.assigned_to ? (
                                                <span className="flex items-center gap-1 text-xs">
                                                    <User className="h-3 w-3" />
                                                    {alert.assigned_to.name}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-muted-foreground italic">
                                                    Unassigned
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <div className="flex items-center justify-end gap-1">
                                                {can.manage &&
                                                    alert.status === 'open' && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-7 px-2 text-xs"
                                                            onClick={() =>
                                                                inlineAcknowledge(
                                                                    alert.id,
                                                                )
                                                            }
                                                        >
                                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                                            Ack
                                                        </Button>
                                                    )}
                                                {can.assign &&
                                                    !alert.assigned_to && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-7 px-2 text-xs"
                                                            onClick={() =>
                                                                inlineAssignToMe(
                                                                    alert.id,
                                                                )
                                                            }
                                                        >
                                                            <UserPlus className="mr-1 h-3 w-3" />
                                                            Me
                                                        </Button>
                                                    )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/control-room/alerts/${alert.id}`}
                                                    >
                                                        <Eye className="mr-1 h-3 w-3" />
                                                        View
                                                    </Link>
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </Card>

                {/* Pagination */}
                {alerts.links?.length > 3 && (
                    <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                            Page {alerts.current_page} of {alerts.last_page} (
                            {alerts.total} total alerts)
                        </p>
                        <div className="flex gap-1">
                            {alerts.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    className="h-8 min-w-[2rem] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Bulk assign dialog */}
            <Dialog open={assignDialogOpen} onOpenChange={setAssignDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Assign Alerts</DialogTitle>
                        <DialogDescription>
                            Assign {selected.size} selected alert(s) to a staff
                            member.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                        <Select
                            value={assignUserId}
                            onValueChange={setAssignUserId}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select staff member..." />
                            </SelectTrigger>
                            <SelectContent>
                                {staff.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setAssignDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={bulkAssign} disabled={!assignUserId}>
                            Assign
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
