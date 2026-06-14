import { router } from '@inertiajs/react';
import { Briefcase, Pencil, Plus, Search, Trash2, Users, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

import type { Department } from './department-dialog';
import { StatusBadge } from './status-badge';

export interface PaginatedDepartments {
    data: Department[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}

export interface DepartmentFilters {
    q: string;
    status: string | null;
}

const NONE = '__none__';

/** Departments list pane for the People hub (folds /hr/departments). */
export function DepartmentsPane({
    departments,
    filters,
    canManage,
    onCreate,
    onEdit,
}: {
    departments: PaginatedDepartments;
    filters: DepartmentFilters;
    canManage: boolean;
    onCreate: () => void;
    onEdit: (department: Department) => void;
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [deactivating, setDeactivating] = useState<Department | null>(null);

    const apply = (next: Partial<DepartmentFilters>) => {
        router.get(
            '/hr/people',
            {
                tab: 'departments',
                dept_q: next.q ?? filters.q ?? undefined,
                dept_status:
                    next.status !== undefined
                        ? next.status ?? undefined
                        : filters.status ?? undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const confirmDeactivate = () => {
        if (!deactivating) return;
        router.delete(`/hr/departments/${deactivating.id}`, {
            preserveScroll: true,
            onFinish: () => setDeactivating(null),
        });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q: search });
                        }}
                        className="relative"
                    >
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search departments…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-56 pl-9"
                        />
                    </form>
                    <Select
                        value={filters.status ?? NONE}
                        onValueChange={(v) =>
                            apply({ status: v === NONE ? null : v })
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    {(filters.q || filters.status) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                router.get(
                                    '/hr/people',
                                    { tab: 'departments' },
                                    { preserveState: true, replace: true },
                                );
                            }}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>
                {canManage ? (
                    <Button onClick={onCreate} className="gap-1.5">
                        <Plus className="h-4 w-4" />
                        Add department
                    </Button>
                ) : null}
            </div>

            <Card>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Department
                                    </th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                        Code
                                    </th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                        Manager
                                    </th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                        Parent
                                    </th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Employees
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {departments.data.map((dept) => (
                                    <tr
                                        key={dept.id}
                                        className="transition-colors hover:bg-muted/40"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                    <Briefcase className="h-4 w-4" />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="font-medium">
                                                        {dept.name}
                                                    </p>
                                                    {dept.description && (
                                                        <p className="max-w-[300px] truncate text-xs text-muted-foreground">
                                                            {dept.description}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell">
                                            {dept.code || '—'}
                                        </td>
                                        <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                                            {dept.manager?.name || '—'}
                                        </td>
                                        <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                                            {dept.parent?.name || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center gap-1 text-sm">
                                                <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                {dept.employees_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge
                                                status={
                                                    dept.is_active
                                                        ? 'active'
                                                        : 'inactive'
                                                }
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {canManage ? (
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            onEdit(dept)
                                                        }
                                                        className="h-8 w-8 p-0"
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                    {dept.is_active && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setDeactivating(
                                                                    dept,
                                                                )
                                                            }
                                                            className="h-8 w-8 p-0 text-status-critical hover:text-status-critical"
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                                {departments.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-16 text-center"
                                        >
                                            <Briefcase className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                            <p className="font-medium text-muted-foreground">
                                                No departments yet
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Dialog
                open={!!deactivating}
                onOpenChange={(o) => !o && setDeactivating(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Deactivate department?</DialogTitle>
                        <DialogDescription>
                            Deactivate “{deactivating?.name}”? Active employees
                            must be reassigned first or the change is blocked.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeactivating(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmDeactivate}
                            className="bg-status-critical text-white hover:bg-status-critical/90"
                        >
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default DepartmentsPane;
