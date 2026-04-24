import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    ClipboardList,
    X,
} from 'lucide-react';
import { useCallback, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Health & Safety', href: '/health-safety' },
    { title: 'Corrective Actions', href: '/health-safety/corrective-actions' },
];

interface ActionRow {
    id: number;
    reference_number: string;
    title: string;
    action_type: string;
    priority: string;
    status: string;
    assigned_to_name: string | null;
    due_date: string | null;
    is_overdue: boolean;
    event_reference: string | null;
    event_category: string | null;
    site_name: string | null;
}

interface Props {
    actions: {
        data: ActionRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        status?: string | null;
        priority?: string | null;
        overdue?: string | null;
        awaiting_verification?: string | null;
    };
}

export default function CorrectiveActionsIndex({ actions, filters }: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const applyFilter = useCallback(
        (key: string, value: string | null) => {
            router.get(
                '/health-safety/corrective-actions',
                { ...filters, [key]: value || undefined },
                { preserveState: true, replace: true },
            );
        },
        [filters],
    );

    const fmtDate = (iso: string | null) => {
        if (!iso) return '-';
        return new Date(iso).toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    };

    const overdueCount = actions.data.filter((a) => a.is_overdue).length;

    // Only open/in_progress actions can be selected for bulk operations
    const selectableIds = actions.data
        .filter((a) => a.status === 'open' || a.status === 'in_progress')
        .map((a) => a.id);

    const toggleSelect = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const toggleAll = () => {
        if (selected.size === selectableIds.length) {
            setSelected(new Set());
        } else {
            setSelected(new Set(selectableIds));
        }
    };

    const clearSelection = () => setSelected(new Set());

    const allSelected =
        selectableIds.length > 0 && selected.size === selectableIds.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Corrective Actions" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning text-white">
                            <ClipboardList className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">
                                Corrective Actions
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {actions.total} action
                                {actions.total !== 1 ? 's' : ''}
                                {overdueCount > 0 && (
                                    <span className="ml-2 font-medium text-status-critical">
                                        {overdueCount} overdue
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Bulk selection banner */}
                {selected.size > 0 && (
                    <div className="flex items-center justify-between rounded-lg border border-status-info/30 bg-status-info-bg px-4 py-2.5 text-sm dark:border-status-info/30 dark:bg-status-info">
                        <span className="font-medium text-status-info dark:text-status-info">
                            {selected.size} action
                            {selected.size !== 1 ? 's' : ''} selected
                        </span>
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-status-info dark:text-status-info">
                                Bulk operations available in management view
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={clearSelection}
                                className="h-7 gap-1 text-status-info"
                            >
                                <X className="h-3 w-3" /> Clear
                            </Button>
                        </div>
                    </div>
                )}

                {/* Filters */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 py-4">
                        <Select
                            value={filters.status ?? '__none__'}
                            onValueChange={(v) =>
                                applyFilter(
                                    'status',
                                    v === '__none__' ? null : v,
                                )
                            }
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">
                                    All statuses
                                </SelectItem>
                                <SelectItem value="open">Open</SelectItem>
                                <SelectItem value="in_progress">
                                    In Progress
                                </SelectItem>
                                <SelectItem value="completed">
                                    Awaiting Verification
                                </SelectItem>
                                <SelectItem value="verified">
                                    Verified
                                </SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.priority ?? '__none__'}
                            onValueChange={(v) =>
                                applyFilter(
                                    'priority',
                                    v === '__none__' ? null : v,
                                )
                            }
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="All priority" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">
                                    All priority
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
                            value={filters.overdue ?? '__none__'}
                            onValueChange={(v) =>
                                applyFilter(
                                    'overdue',
                                    v === '__none__' ? null : v,
                                )
                            }
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="Due date" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All</SelectItem>
                                <SelectItem value="true">
                                    Overdue only
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="w-10 px-4 py-3">
                                        {selectableIds.length > 0 && (
                                            <input
                                                type="checkbox"
                                                checked={allSelected}
                                                onChange={toggleAll}
                                                className="h-4 w-4 rounded border-border"
                                            />
                                        )}
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Reference
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Action
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Priority
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Owner
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Due
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Event
                                    </th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {actions.data.map((action) => {
                                    const isSelectable =
                                        action.status === 'open' ||
                                        action.status === 'in_progress';
                                    const isSelected = selected.has(action.id);

                                    return (
                                        <tr
                                            key={action.id}
                                            className={[
                                                'hover:bg-muted/30',
                                                action.is_overdue
                                                    ? 'bg-status-critical-bg'
                                                    : '',
                                                isSelected
                                                    ? 'bg-status-info-bg dark:bg-status-info'
                                                    : '',
                                            ].join(' ')}
                                        >
                                            <td className="px-4 py-3">
                                                {isSelectable && (
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={() =>
                                                            toggleSelect(
                                                                action.id,
                                                            )
                                                        }
                                                        className="h-4 w-4 rounded border-border"
                                                    />
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-medium">
                                                {action.reference_number}
                                            </td>
                                            <td className="max-w-xs truncate px-4 py-3 text-muted-foreground">
                                                {action.title}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    status={action.priority}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <StatusBadge
                                                        status={
                                                            action.is_overdue
                                                                ? 'overdue'
                                                                : action.status
                                                        }
                                                    />
                                                    {action.is_overdue && (
                                                        <AlertTriangle className="h-3.5 w-3.5 text-status-critical" />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {action.assigned_to_name ?? '-'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {fmtDate(action.due_date)}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                                {action.event_reference ?? '-'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            </td>
                                        </tr>
                                    );
                                })}
                                {actions.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-4 py-12 text-center text-muted-foreground"
                                        >
                                            <CheckCircle2 className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">
                                                No actions match your filters
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {actions.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {actions.from}–{actions.to} of{' '}
                            {actions.total}
                        </p>
                        <LaravelPagination links={actions.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
