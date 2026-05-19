import { FLEET_COLORS, MiniBarChart } from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import { PageHero } from '@/components/page';
import { FleetStatCard } from '@/components/fleet-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    ClipboardList,
    Download,
    Loader,
    Plus,
    User,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

type WorkOrder = {
    id: number;
    title: string;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    priority: string;
    status: string;
    assigned_to: { id: number; name: string } | null;
    reported_by: { id: number; name: string } | null;
    due_at: string | null;
    created_at: string | null;
};

type Props = {
    work_orders: {
        data: WorkOrder[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: { status?: string; priority?: string; asset_id?: string };
    users?: Array<{ id: number; name: string }>;
};

const PRIORITY_BORDER: Record<string, string> = {
    critical: 'border-l-4 border-l-red-600',
    high: 'border-l-4 border-l-orange-500',
    medium: 'border-l-4 border-l-yellow-500',
    low: 'border-l-4 border-l-blue-400',
};

function priorityVariant(
    priority: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (priority) {
        case 'critical':
            return 'destructive';
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        case 'low':
            return 'secondary';
        default:
            return 'outline';
    }
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'in_progress':
            return 'default';
        case 'open':
            return 'outline';
        case 'cancelled':
            return 'secondary';
        default:
            return 'outline';
    }
}

export default function WorkOrdersIndex({
    work_orders,
    filters,
    users,
}: Props) {
    const safeFilters = filters ?? {};
    const safeData = useMemo(
        () => work_orders?.data ?? [],
        [work_orders?.data],
    );
    const safeMeta = work_orders?.meta ?? {
        current_page: 1,
        last_page: 1,
        total: 0,
    };
    const safeLinks = work_orders?.links ?? [];

    const totalCount = safeMeta.total ?? safeData.length;
    const openCount = safeData.filter((wo) => wo.status === 'open').length;
    const inProgressCount = safeData.filter(
        (wo) => wo.status === 'in_progress',
    ).length;
    const completedCount = safeData.filter(
        (wo) => wo.status === 'completed',
    ).length;

    const criticalCount = safeData.filter(
        (wo) => wo.priority === 'critical',
    ).length;
    const highCount = safeData.filter((wo) => wo.priority === 'high').length;
    const mediumCount = safeData.filter(
        (wo) => wo.priority === 'medium',
    ).length;
    const lowCount = safeData.filter((wo) => wo.priority === 'low').length;

    const priorityChartData = [
        { label: 'Critical', value: criticalCount, color: FLEET_COLORS.danger },
        { label: 'High', value: highCount, color: FLEET_COLORS.warning },
        { label: 'Medium', value: mediumCount, color: FLEET_COLORS.secondary },
        { label: 'Low', value: lowCount, color: FLEET_COLORS.accent },
    ];

    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkAssignee, setBulkAssignee] = useState('');
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(
            window.location.pathname,
            { ...safeFilters, sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
                onClick={() => handleSort(field)}
            >
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </div>
            </th>
        );
    };

    const applyFilters = (newFilters: Partial<typeof safeFilters>) => {
        router.get(
            '/fleet-assets/maintenance/work-orders',
            { ...safeFilters, ...newFilters, page: 1 },
            { preserveState: true },
        );
    };

    const toggleSelect = useCallback((id: number) => {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }, []);

    const toggleSelectAll = useCallback(() => {
        setSelectedIds(
            selectedIds.length === safeData.length
                ? []
                : safeData.map((wo) => wo.id),
        );
    }, [safeData, selectedIds.length]);

    const handleBulkAction = useCallback(
        (action: string) => {
            if (selectedIds.length === 0) return;
            const payload: Record<string, unknown> = {
                action,
                ids: selectedIds,
            };
            if (action === 'assign' && bulkAssignee)
                payload.assigned_to_user_id = Number(bulkAssignee);
            router.post(
                '/fleet-assets/maintenance/work-orders/bulk-action',
                payload as any,
                {
                    preserveState: true,
                    onSuccess: () => setSelectedIds([]),
                },
            );
        },
        [selectedIds, bulkAssignee],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                {
                    title: 'Work Orders',
                    href: '/fleet-assets/maintenance/work-orders',
                },
            ]}
        >
            <Head title="Work Orders" />
            <PageShell>
                <PageHero
                    title="Work Orders"
                    description="Track maintenance work orders for assets."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href="/fleet-assets/maintenance/work-orders?export=csv">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Button asChild>
                                <Link href="/fleet-assets/maintenance/work-orders/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create Work Order
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard
                        label="TOTAL"
                        value={totalCount}
                        icon={ClipboardList}
                        subtitle="All work orders"
                    />
                    <FleetStatCard
                        label="OPEN"
                        value={openCount}
                        icon={Wrench}
                        color="amber"
                        valueClassName="text-status-warning"
                        subtitle="Awaiting action"
                    />
                    <FleetStatCard
                        label="IN PROGRESS"
                        value={inProgressCount}
                        icon={Loader}
                        color="blue"
                        valueClassName="text-status-info"
                        subtitle="Being worked on"
                    />
                    <FleetStatCard
                        label="COMPLETED"
                        value={completedCount}
                        icon={CheckCircle}
                        color="amber"
                        valueClassName="text-status-success"
                        subtitle="Done"
                    />
                    <Card className="border bg-primary/10 sm:col-span-2 md:col-span-3 lg:col-span-4 dark:bg-primary/20">
                        <CardContent className="p-4">
                            <p className="mb-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                PRIORITY DISTRIBUTION
                            </p>
                            <MiniBarChart
                                data={priorityChartData.map((d) => ({
                                    label: d.label,
                                    value: d.value,
                                }))}
                                height={80}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Select
                        value={safeFilters.status || 'all'}
                        onValueChange={(v) =>
                            applyFilters({ status: v === 'all' ? '' : v })
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="in_progress">
                                In Progress
                            </SelectItem>
                            <SelectItem value="on_hold">On Hold</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={safeFilters.priority || 'all'}
                        onValueChange={(v) =>
                            applyFilters({ priority: v === 'all' ? '' : v })
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Priority" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All priorities</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table with priority left borders */}
                <div className="overflow-hidden rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                <th className="w-8 px-4 py-3 text-left font-medium">
                                    <input
                                        type="checkbox"
                                        checked={
                                            safeData.length > 0 &&
                                            selectedIds.length ===
                                                safeData.length
                                        }
                                        onChange={toggleSelectAll}
                                        className="h-3.5 w-3.5 rounded border-border"
                                    />
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Title
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Asset
                                </th>
                                {renderSortHeader('priority', 'Priority')}
                                {renderSortHeader('status', 'Status')}
                                <th className="px-4 py-3 text-left font-medium">
                                    Assigned To
                                </th>
                                {renderSortHeader('created_at', 'Created')}
                            </tr>
                        </thead>
                        <tbody>
                            {safeData.length > 0 ? (
                                safeData.map((wo) => (
                                    <tr
                                        key={wo.id}
                                        className={`cursor-pointer border-b transition-colors hover:bg-muted/30 ${PRIORITY_BORDER[wo.priority] ?? ''}`}
                                        onClick={() =>
                                            router.visit(
                                                `/fleet-assets/maintenance/work-orders/${wo.id}`,
                                            )
                                        }
                                    >
                                        <td
                                            className="px-4 py-3"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.includes(
                                                    wo.id,
                                                )}
                                                onChange={() =>
                                                    toggleSelect(wo.id)
                                                }
                                                className="h-3.5 w-3.5 rounded border-border"
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <Wrench className="h-4 w-4 text-muted-foreground" />
                                                <span className="font-medium">
                                                    {wo.title}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {wo.asset ? (
                                                <Link
                                                    href={`/fleet-assets/assets/${wo.asset.id}`}
                                                    className="text-primary hover:underline"
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    {wo.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    ---
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={priorityVariant(
                                                    wo.priority,
                                                )}
                                            >
                                                {wo.priority}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={statusVariant(
                                                    wo.status,
                                                )}
                                            >
                                                {(wo.status ?? '').replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            {wo.assigned_to ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    {wo.assigned_to.name}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Unassigned
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {wo.created_at
                                                ? formatDate(wo.created_at)
                                                : '---'}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12">
                                        <FleetEmptyState
                                            icon={Wrench}
                                            title="No work orders"
                                            description="Create a work order to track vehicle and asset maintenance tasks."
                                            actionLabel="Create Work Order"
                                            actionHref="/fleet-assets/maintenance/work-orders/create"
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Bulk Action Bar */}
                {selectedIds.length > 0 && (
                    // eslint-disable-next-line no-restricted-syntax -- Fixed bulk action bar needs sticky overlay positioning rather than Card spacing.
                    <div className="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
                        <span className="text-sm font-medium">
                            {selectedIds.length} selected
                        </span>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => handleBulkAction('complete')}
                            >
                                Mark Complete
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => handleBulkAction('in_progress')}
                            >
                                Mark In Progress
                            </Button>
                            {(users ?? []).length > 0 && (
                                <>
                                    <Select
                                        value={bulkAssignee}
                                        onValueChange={setBulkAssignee}
                                    >
                                        <SelectTrigger className="h-8 w-36 text-xs">
                                            <SelectValue placeholder="Assign to..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(users ?? []).map((u) => (
                                                <SelectItem
                                                    key={u.id}
                                                    value={String(u.id)}
                                                >
                                                    {u.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            handleBulkAction('assign')
                                        }
                                        disabled={!bulkAssignee}
                                    >
                                        Assign
                                    </Button>
                                </>
                            )}
                        </div>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelectedIds([])}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                )}

                {/* Pagination */}
                {safeMeta.last_page > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {safeLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
