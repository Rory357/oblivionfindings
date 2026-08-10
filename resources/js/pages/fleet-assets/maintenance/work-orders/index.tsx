import { FLEET_COLORS, MiniBarChart } from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
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
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    RefChip,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { FleetResponsiveTable } from '@/pages/fleet-assets/components/fleet-responsive-list';
import { HeroActionButton } from '@/pages/fleet-assets/maintenance/components/hero-action-button';
import {
    WorkOrderCreateWizard,
    type WizardAsset,
    type WizardChecklistRun,
} from '@/pages/fleet-assets/maintenance/work-orders/create-wizard';
import {
    mergeWorkOrderFilters,
    workOrderStatusFilterUpdate,
    workOrderStatusFilterValue,
    type WorkOrderFilters,
} from '@/pages/fleet-assets/maintenance/work-orders/work-order-filters';
import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    Download,
    Plus,
    User,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type WorkOrder = {
    id: number;
    reference_number: string | null;
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
    filters: WorkOrderFilters;
    users?: Array<{ id: number; name: string }>;
    stats?: {
        open: number;
        overdue: number;
        in_progress: number;
        completed_30d: number;
    };
    assets?: WizardAsset[];
    checklist_runs?: WizardChecklistRun[];
    prefill_asset_id?: string | null;
    prefill_checklist_run_id?: string | null;
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
    stats,
    assets,
    checklist_runs,
    prefill_asset_id,
    prefill_checklist_run_id,
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
    const heroStats = stats ?? {
        open: 0,
        overdue: 0,
        in_progress: 0,
        completed_30d: 0,
    };

    const [wizardOpen, setWizardOpen] = useState(false);

    // Deep-link shim: /create redirects here with ?new=1 (opens the wizard modal).
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('new') === '1') {
            setWizardOpen(true);
        }
    }, []);

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

    const applyFilters = (newFilters: Partial<WorkOrderFilters>) => {
        router.get(
            '/fleet-assets/maintenance/work-orders',
            {
                ...mergeWorkOrderFilters(safeFilters, newFilters),
                page: 1,
            },
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
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Wrench} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Maintenance · work orders
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Work Orders
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Track maintenance work orders for assets.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders?status=open"
                                label="Open"
                                value={fmt(heroStats.open)}
                                caption="awaiting action"
                                tone={
                                    heroStats.open > 0 ? 'warning' : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders?overdue=1"
                                label="Overdue"
                                value={fmt(heroStats.overdue)}
                                caption="past due date"
                                tone={
                                    heroStats.overdue > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders?status=in_progress"
                                label="In progress"
                                value={fmt(heroStats.in_progress)}
                                caption="being worked on"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders?status=completed"
                                label="Completed 30d"
                                value={fmt(heroStats.completed_30d)}
                                caption="closed this month"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <HeroActionButton
                            onClick={() => setWizardOpen(true)}
                            icon={Plus}
                            emphasis
                        >
                            New work order
                        </HeroActionButton>
                        <FleetHeroAction
                            href="/fleet-assets/maintenance/work-orders?export=csv"
                            icon={Download}
                            external
                        >
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* Priority distribution (current page) */}
                <Card className="border bg-primary/10 dark:bg-primary/20">
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

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Select
                        value={workOrderStatusFilterValue(safeFilters)}
                        onValueChange={(value) =>
                            applyFilters(workOrderStatusFilterUpdate(value))
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="overdue">Overdue</SelectItem>
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
                    <FleetResponsiveTable>
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
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
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
                                            <td
                                                data-fleet-row-identity
                                                className="px-4 py-3"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Wrench className="h-4 w-4 text-muted-foreground" />
                                                    <RefChip
                                                        value={
                                                            wo.reference_number ??
                                                            `#${wo.id}`
                                                        }
                                                    />
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
                                            <td
                                                data-fleet-row-status
                                                data-fleet-row-action
                                                className="px-4 py-3"
                                            >
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
                                            <td
                                                data-fleet-row-time
                                                className="px-4 py-3 text-muted-foreground"
                                            >
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
                                                onAction={() =>
                                                    setWizardOpen(true)
                                                }
                                            />
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </FleetResponsiveTable>
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

                <WorkOrderCreateWizard
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    assets={assets ?? []}
                    users={users ?? []}
                    checklistRuns={checklist_runs ?? []}
                    prefillAssetId={prefill_asset_id}
                    prefillChecklistRunId={prefill_checklist_run_id}
                />
            </PageShell>
        </AppLayout>
    );
}
