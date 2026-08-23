import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertStatusChip } from '@/components/control-room/alert-worklist/alert-status';
import { AlertWorklist } from '@/components/control-room/alert-worklist/alert-worklist';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    AlertWorkspaceDialog,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import {
    BulkAlertActionDialog,
    type BulkAlertMode,
} from '@/components/control-room/bulk-alert-action-dialog';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import { NewAlertWizard } from '@/components/control-room/new-alert-wizard';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    Eye,
    Filter,
    User,
    UserPlus,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

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
    snoozed_until: string | null;
    notes: string | null;
}

type CanonicalAlertItem = AlertWorklistRow & {
    alert_type: string;
    escalation_level: number;
    assigned_to: { id: number; name: string } | null;
    client_name: string | null;
    snoozed_until: string | null;
};

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    alerts: {
        data: Array<AlertItem | CanonicalAlertItem>;
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
        snoozed: number;
        history?: number;
    };
    staff: Array<{ id: number; name: string; email: string }>;
    /** For the New-alert wizard (manual creation). */
    clients?: Array<{ id: number; name: string }>;
    sites?: Array<{ id: number; name: string }>;
    can: {
        manage: boolean;
        assign: boolean;
        create?: boolean;
    };
    /** Workspace-over-list: present when ?alert= is in the URL. */
    detail?: AlertWorkspaceDetail | null;
    basePath?: string;
    pageTitle?: string;
    pageDescription?: string;
    pageBreadcrumbs?: Array<{ title: string; href: string }>;
}

function AlertsWorkspaceFrame({
    canonical,
    stats,
    canCreate,
    onCreate,
    children,
}: {
    canonical: boolean;
    stats: Props['stats'];
    canCreate: boolean;
    onCreate: () => void;
    children: ReactNode;
}) {
    if (!canonical) return <>{children}</>;

    return (
        <CommandCentrePage
            current="/control-room/alerts"
            icon={Bell}
            title="Active alerts"
            description="Work the live priority queue, keep ownership clear, and continue each alert through its governed response."
            status="Live priority worklist"
            badges={{ '/control-room/alerts': stats.open }}
            actions={
                canCreate ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={onCreate}
                    >
                        <Bell className="h-4 w-4" aria-hidden />
                        New alert
                    </Button>
                ) : null
            }
            metricGroups={[
                {
                    title: 'Live workload',
                    icon: Bell,
                    metrics: [
                        {
                            label: 'Open',
                            value: String(stats.open),
                            caption: 'actionable now',
                            tone: 'neutral',
                        },
                        {
                            label: 'Critical',
                            value: String(stats.critical),
                            caption: 'act first',
                            tone: stats.critical > 0 ? 'critical' : 'success',
                        },
                        {
                            label: 'Unassigned',
                            value: String(stats.unassigned),
                            caption: 'claim or assign',
                            tone: stats.unassigned > 0 ? 'warning' : 'success',
                        },
                        {
                            label: 'Snoozed',
                            value: String(stats.snoozed),
                            caption: 'deferred safely',
                            tone: 'neutral',
                        },
                    ],
                },
            ]}
        >
            {children}
        </CommandCentrePage>
    );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function AlertsIndex({
    alerts,
    filters,
    stats,
    staff,
    clients = [],
    sites = [],
    can,
    detail = null,
    basePath = '/control-room/alerts',
    pageTitle = 'Active alerts',
    pageDescription = 'Alerts worklist — every alert opens a guided workspace.',
    pageBreadcrumbs = [
        { title: 'Control Room', href: '/control-room' },
        { title: 'Alerts', href: '/control-room/alerts' },
    ],
}: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [bulkMode, setBulkMode] = useState<BulkAlertMode | null>(null);
    // ?new=1 deep-links straight into the New-alert wizard (house pattern).
    const [newOpen, setNewOpen] = useState<boolean>(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );
    const searchRef = useRef<HTMLInputElement>(null);
    const isCanonicalWorklist = basePath === '/control-room/alerts';

    // Workspace-over-list: fetch only the `detail` prop and open the dialog
    // without navigating away; closing drops the param so `detail` goes null.
    const openWorkspace = (id: number) =>
        router.get(
            basePath,
            { ...filters, alert: String(id) } as Record<string, string>,
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    const closeWorkspace = () =>
        router.get(basePath, { ...filters } as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
            only: ['detail'],
        });

    const rowActions = (row: CanonicalAlertItem) =>
        buildControlRoomAlertRowActions(row, {
            openWorkspace,
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

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

    const hasFilters = Object.entries(filters).some(
        ([key, value]) => value && !(key === 'lens' && value === 'active'),
    );

    // ------------------------------------------------------------------
    // Sorting
    // ------------------------------------------------------------------

    const currentSort =
        filters.sort || (isCanonicalWorklist ? 'priority' : 'triggered_at');
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
    // Bulk actions — stepped dialogs (review selection → details → submit)
    // ------------------------------------------------------------------

    const selectedAlerts = alerts.data.filter((a) => selected.has(a.id));

    // ------------------------------------------------------------------
    // Quick filter tabs config
    // ------------------------------------------------------------------

    const tabs = [
        {
            label: isCanonicalWorklist ? 'Active' : 'All',
            count: stats.total,
            filter: isCanonicalWorklist ? { lens: 'active' } : {},
        },
        {
            label: 'Open',
            count: stats.open,
            filter: { lens: 'active', status: 'open' },
        },
        {
            label: 'Critical',
            count: stats.critical,
            filter: { lens: 'active', severity: 'critical' },
        },
        {
            label: 'Assigned to Me',
            count: stats.assigned_to_me,
            filter: { lens: 'my_queue', assigned_to: 'me' },
        },
        {
            label: 'Unassigned',
            count: stats.unassigned,
            filter: { lens: 'active', assigned_to: 'unassigned' },
        },
        {
            label: 'Snoozed',
            count: stats.snoozed ?? 0,
            filter: { lens: 'snoozed', snoozed: '1' },
        },
        ...(isCanonicalWorklist
            ? [
                  {
                      label: 'History',
                      count: stats.history ?? 0,
                      filter: { lens: 'history' },
                  },
              ]
            : []),
    ];

    const activeTab = (() => {
        if (filters.snoozed === '1') return 'Snoozed';
        if (filters.lens === 'history') return 'History';
        if (filters.assigned_to === 'me') return 'Assigned to Me';
        if (filters.assigned_to === 'unassigned') return 'Unassigned';
        if (filters.status === 'open' && !filters.severity) return 'Open';
        if (filters.severity === 'critical' && !filters.status)
            return 'Critical';
        if (!hasFilters || filters.lens === 'active')
            return isCanonicalWorklist ? 'Active' : 'All';
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

            <PageLayout
                hero={
                    !isCanonicalWorklist ? (
                        <PageHero
                            pageType="task"
                            title={pageTitle}
                            description={pageDescription}
                            actions={
                                can.create &&
                                basePath === '/control-room/alerts' ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setNewOpen(true)}
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground backdrop-blur-sm"
                                    >
                                        <Bell className="mr-2 h-4 w-4" />
                                        New alert
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : undefined
                }
            >
                <AlertsWorkspaceFrame
                    canonical={isCanonicalWorklist}
                    stats={stats}
                    canCreate={Boolean(can.create)}
                    onCreate={() => setNewOpen(true)}
                >
                    {/* Quick filter tabs */}
                    <div
                        role="group"
                        aria-label="Alert queue filters"
                        className="bg-muted/40 flex flex-wrap gap-1 rounded-lg border p-1"
                    >
                        {tabs.map((tab, index) => (
                            <Button
                                key={tab.label}
                                data-page-first-action={
                                    index === 0 ? '' : undefined
                                }
                                type="button"
                                variant="ghost"
                                aria-pressed={activeTab === tab.label}
                                onClick={() => applyQuickFilter(tab.filter)}
                                className={`frontline-focus frontline-tap h-auto gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
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
                        <div className="text-muted-foreground flex items-center gap-1.5 text-sm font-medium">
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
                                <SelectItem value="all">
                                    All Severity
                                </SelectItem>
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
                                <SelectItem value="sensor">Sensor</SelectItem>
                                <SelectItem value="manual">Manual</SelectItem>
                                <SelectItem value="control_room">
                                    Control Room
                                </SelectItem>
                                <SelectItem value="external">
                                    External
                                </SelectItem>
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
                                <SelectItem value="ack">
                                    Acknowledged
                                </SelectItem>
                                <SelectItem value="triaging">
                                    Triaging
                                </SelectItem>
                                <SelectItem value="resolved">
                                    Resolved
                                </SelectItem>
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
                            onChange={(e) =>
                                applyFilter('date_to', e.target.value)
                            }
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
                        <div className="border-primary/30 bg-primary/5 flex items-center gap-3 rounded-lg border px-4 py-2">
                            <span className="text-sm font-medium">
                                {selected.size} selected
                            </span>
                            <div className="bg-border h-4 w-px" />
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBulkMode('acknowledge')}
                                >
                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                    Acknowledge Selected
                                </Button>
                            )}
                            {can.assign && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBulkMode('assign')}
                                >
                                    <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                                    Assign Selected
                                </Button>
                            )}
                            <div className="flex-1" />
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={toggleAll}
                            >
                                {allOnPageSelected
                                    ? 'Deselect All'
                                    : 'Select All'}
                            </Button>
                        </div>
                    )}

                    {/* Canonical worklist on the Control Room route; the integration
                    route keeps its existing compatibility table until Task 13. */}
                    {isCanonicalWorklist ? (
                        <AlertWorklist
                            rows={alerts.data as CanonicalAlertItem[]}
                            selected={selected}
                            onSelectionChange={setSelected}
                            onSort={toggleSort}
                            onOpen={openWorkspace}
                            getActions={rowActions}
                        />
                    ) : (
                        <Card className="gap-0 overflow-x-auto rounded-lg p-0">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-muted-foreground border-b text-left text-xs font-medium uppercase tracking-wider">
                                        <th className="w-10 px-3 py-3">
                                            <Checkbox
                                                checked={allOnPageSelected}
                                                onCheckedChange={toggleAll}
                                            />
                                        </th>
                                        <th
                                            className="cursor-pointer px-3 py-3"
                                            onClick={() =>
                                                toggleSort('alert_type')
                                            }
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                Alert Type{' '}
                                                {renderSortIcon('alert_type')}
                                            </span>
                                        </th>
                                        <th className="px-3 py-3">Source</th>
                                        <th
                                            className="cursor-pointer px-3 py-3"
                                            onClick={() =>
                                                toggleSort('severity')
                                            }
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                Severity{' '}
                                                {renderSortIcon('severity')}
                                            </span>
                                        </th>
                                        <th
                                            className="cursor-pointer px-3 py-3"
                                            onClick={() => toggleSort('status')}
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                Status{' '}
                                                {renderSortIcon('status')}
                                            </span>
                                        </th>
                                        <th className="px-3 py-3">SLA</th>
                                        <th
                                            className="cursor-pointer px-3 py-3"
                                            onClick={() =>
                                                toggleSort('triggered_at')
                                            }
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
                                                <Bell className="text-muted-foreground/50 mx-auto mb-3 h-10 w-10" />
                                                <p className="text-muted-foreground text-sm">
                                                    No alerts found matching
                                                    your filters.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        (alerts.data as AlertItem[]).map(
                                            (alert, idx) => (
                                                <tr
                                                    key={alert.id}
                                                    onClick={() =>
                                                        openWorkspace(alert.id)
                                                    }
                                                    className={`border-l-primary/50 hover:bg-muted/40 cursor-pointer border-b border-l-4 transition-colors ${idx % 2 === 1 ? 'bg-muted/20' : ''} ${
                                                        selected.has(alert.id)
                                                            ? 'bg-primary/5'
                                                            : ''
                                                    }`}
                                                >
                                                    <td
                                                        className="px-3 py-2.5"
                                                        onClick={(e) =>
                                                            e.stopPropagation()
                                                        }
                                                    >
                                                        <Checkbox
                                                            checked={selected.has(
                                                                alert.id,
                                                            )}
                                                            onCheckedChange={() =>
                                                                toggleOne(
                                                                    alert.id,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {
                                                                    alert.alert_type
                                                                }
                                                            </span>
                                                            {alert.escalation_level &&
                                                                alert.escalation_level >
                                                                    0 && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 text-status-warning px-1 py-0 text-[10px]"
                                                                    >
                                                                        L
                                                                        {
                                                                            alert.escalation_level
                                                                        }
                                                                    </Badge>
                                                                )}
                                                        </div>
                                                        {alert.client_name && (
                                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                                {
                                                                    alert.client_name
                                                                }
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <span className="text-muted-foreground text-xs capitalize">
                                                            {alert.source?.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <AlertStatusChip
                                                            kind="severity"
                                                            value={
                                                                alert.severity
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <AlertStatusChip
                                                            kind="status"
                                                            value={alert.status}
                                                        />
                                                        {alert.snoozed_until &&
                                                        new Date(
                                                            alert.snoozed_until,
                                                        ) > new Date() ? (
                                                            <span
                                                                className="text-muted-foreground mt-1 flex items-center gap-1 text-[11px]"
                                                                title={`Snoozed until ${new Date(alert.snoozed_until).toLocaleString()}`}
                                                            >
                                                                <BellOff className="h-3 w-3" />
                                                                Snoozed
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        {alert.sla_status ? (
                                                            <AlertStatusChip
                                                                kind="sla"
                                                                value={
                                                                    alert.sla_status
                                                                }
                                                            />
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">
                                                                -
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <span
                                                            className="text-muted-foreground flex items-center gap-1 text-xs"
                                                            title={
                                                                alert.triggered_at
                                                                    ? new Date(
                                                                          alert.triggered_at,
                                                                      ).toLocaleString()
                                                                    : ''
                                                            }
                                                        >
                                                            <Clock className="h-3 w-3" />
                                                            {formatRelative(
                                                                alert.triggered_at,
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        {alert.assigned_to ? (
                                                            <span className="flex items-center gap-1 text-xs">
                                                                <User className="h-3 w-3" />
                                                                {
                                                                    alert
                                                                        .assigned_to
                                                                        .name
                                                                }
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs italic">
                                                                Unassigned
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td
                                                        className="px-3 py-2.5"
                                                        onClick={(e) =>
                                                            e.stopPropagation()
                                                        }
                                                    >
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 px-2 text-xs"
                                                                onClick={() =>
                                                                    openWorkspace(
                                                                        alert.id,
                                                                    )
                                                                }
                                                            >
                                                                <Eye className="mr-1 h-3 w-3" />
                                                                Open
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ),
                                        )
                                    )}
                                </tbody>
                            </table>
                        </Card>
                    )}

                    {/* Pagination */}
                    {alerts.links?.length > 3 && (
                        <div className="flex min-w-0 flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-muted-foreground text-xs">
                                Page {alerts.current_page} of {alerts.last_page}{' '}
                                ({alerts.total} total alerts)
                            </p>
                            <div className="max-w-full overflow-x-auto pb-1">
                                <div className="flex w-max gap-1">
                                    {alerts.links.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
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
                        </div>
                    )}
                </AlertsWorkspaceFrame>
            </PageLayout>

            {/* Stepped bulk actions: review selection → details → submit */}
            {bulkMode ? (
                <BulkAlertActionDialog
                    mode={bulkMode}
                    open
                    onClose={() => setBulkMode(null)}
                    alerts={selectedAlerts.map((item) => {
                        const alert = item as AlertItem | CanonicalAlertItem;
                        return {
                            id: alert.id,
                            alert_type: alert.alert_type,
                            severity: alert.severity,
                            status: alert.status,
                            client_name: alert.client_name,
                        };
                    })}
                    staff={staff}
                    onDone={() => setSelected(new Set())}
                />
            ) : null}

            {/* Workspace-over-list */}
            {detail ? (
                <AlertWorkspaceDialog
                    detail={detail}
                    open
                    onClose={closeWorkspace}
                />
            ) : null}

            {/* Manual alert creation — guided wizard. Mounted only while open so
                every run starts fresh and a closed wizard can never linger. */}
            {newOpen ? (
                <NewAlertWizard
                    open
                    onClose={() => setNewOpen(false)}
                    clients={clients}
                    sites={sites}
                    onOpenAlert={openWorkspace}
                />
            ) : null}
        </AppLayout>
    );
}
