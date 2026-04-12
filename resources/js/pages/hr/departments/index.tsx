import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
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
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Head, router, useForm } from '@inertiajs/react';
import { Briefcase, Pencil, Plus, Search, Trash2, Users, X } from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Department {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    manager_user_id: number | null;
    parent_id: number | null;
    is_active: boolean;
    sort_order: number;
    employees_count: number;
    manager?: { id: number; name: string } | null;
    parent?: { id: number; name: string } | null;
}

interface Props {
    departments: {
        data: Department[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    managers: Array<{ id: number; name: string }>;
    parentOptions: Array<{ id: number; name: string }>;
    filters: { q: string; status: string | null };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Departments', href: '/hr/departments' },
];

const NONE = '__none__';

/* ------------------------------------------------------------------ */
/*  Form Dialog                                                        */
/* ------------------------------------------------------------------ */

interface DeptFormData {
    name: string;
    code: string;
    description: string;
    manager_user_id: string;
    parent_id: string;
    sort_order: number;
    is_active: boolean;
}

function DepartmentDialog({
    open,
    onClose,
    department,
    managers,
    parentOptions,
}: {
    open: boolean;
    onClose: () => void;
    department: Department | null;
    managers: Array<{ id: number; name: string }>;
    parentOptions: Array<{ id: number; name: string }>;
}) {
    const isEdit = !!department;

    const form = useForm<DeptFormData>({
        name: department?.name || '',
        code: department?.code || '',
        description: department?.description || '',
        manager_user_id: department?.manager_user_id ? String(department.manager_user_id) : '',
        parent_id: department?.parent_id ? String(department.parent_id) : '',
        sort_order: department?.sort_order || 0,
        is_active: department?.is_active ?? true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();

        const payload = {
            ...form.data,
            manager_user_id: form.data.manager_user_id || null,
            parent_id: form.data.parent_id || null,
        };

        if (isEdit) {
            form.transform(() => payload);
            form.put(`/hr/departments/${department!.id}`, {
                onSuccess: () => onClose(),
                preserveScroll: true,
                onFinish: () => form.transform((data) => data),
            });
        } else {
            form.transform(() => payload);
            form.post('/hr/departments', {
                onSuccess: () => onClose(),
                preserveScroll: true,
                onFinish: () => form.transform((data) => data),
            });
        }
    }

    // Filter out current department from parent options
    const filteredParents = parentOptions.filter((p) => !department || p.id !== department.id);

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Department' : 'New Department'}</DialogTitle>
                    <DialogDescription>
                        {isEdit ? 'Update department details.' : 'Create a new department for your organisation.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="name">Name *</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Care Services"
                            />
                            {form.errors.name && <p className="text-xs text-red-600">{form.errors.name}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="e.g. CS"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="sort_order">Sort Order</Label>
                            <Input
                                id="sort_order"
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(e) => form.setData('sort_order', parseInt(e.target.value) || 0)}
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="description">Description</Label>
                        <Textarea
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={2}
                            placeholder="Brief description of the department"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Manager</Label>
                            <Select
                                value={form.data.manager_user_id || NONE}
                                onValueChange={(v) => form.setData('manager_user_id', v === NONE ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select manager" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>None</SelectItem>
                                    {managers.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Parent Department</Label>
                            <Select
                                value={form.data.parent_id || NONE}
                                onValueChange={(v) => form.setData('parent_id', v === NONE ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="None (top-level)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>None (top-level)</SelectItem>
                                    {filteredParents.map((p) => (
                                        <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {isEdit && (
                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="is_active"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            <Label htmlFor="is_active">Active</Label>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>
                            {isEdit ? 'Save Changes' : 'Create Department'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function DepartmentsIndex({ departments, managers, parentOptions, filters }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Department | null>(null);

    function applyFilter(key: string, value: string | null) {
        router.get('/hr/departments', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    const hasFilters = !!(filters.q || filters.status);

    function openCreate() {
        setEditing(null);
        setDialogOpen(true);
    }

    function openEdit(dept: Department) {
        setEditing(dept);
        setDialogOpen(true);
    }

    function closeDialog() {
        setDialogOpen(false);
        setEditing(null);
    }

    function handleDeactivate(dept: Department) {
        if (!confirm(`Deactivate "${dept.name}"? Employees must be reassigned first.`)) return;
        router.delete(`/hr/departments/${dept.id}`, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Departments" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Departments</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage organisational departments &mdash; {departments.total} total
                        </p>
                    </div>
                    <Button onClick={openCreate} className="gap-1.5">
                        <Plus className="h-4 w-4" />
                        Add Department
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or code..."
                            defaultValue={filters.q}
                            className="w-64 pl-9"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                            }}
                        />
                    </div>

                    <Select value={filters.status || NONE} onValueChange={(v) => applyFilter('status', v === NONE ? null : v)}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get('/hr/departments', {}, { preserveState: true, replace: true })}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Department</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Code</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Manager</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Parent</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted-foreground">Employees</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {departments.data.map((dept) => (
                                        <tr key={dept.id} className="transition-colors hover:bg-muted/40">
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                        <Briefcase className="h-4 w-4" />
                                                    </div>
                                                    <div>
                                                        <p className="font-medium">{dept.name}</p>
                                                        {dept.description && (
                                                            <p className="truncate text-xs text-muted-foreground" style={{ maxWidth: '300px' }}>
                                                                {dept.description}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell">
                                                {dept.code || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                                                {dept.manager?.name || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                                                {dept.parent?.name || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <div className="inline-flex items-center gap-1 text-sm">
                                                    <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                    {dept.employees_count}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        dept.is_active
                                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                            : 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-400'
                                                    }
                                                >
                                                    {dept.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button variant="ghost" size="sm" onClick={() => openEdit(dept)} className="h-8 w-8 p-0">
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                    {dept.is_active && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleDeactivate(dept)}
                                                            className="h-8 w-8 p-0 text-red-600 hover:text-red-700"
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {departments.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-16 text-center">
                                                <Briefcase className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                <p className="font-medium text-muted-foreground">No departments yet</p>
                                                <p className="mt-1 text-sm text-muted-foreground/70">Create your first department to get started</p>
                                                <Button onClick={openCreate} variant="outline" size="sm" className="mt-4 gap-1.5">
                                                    <Plus className="h-4 w-4" />
                                                    Add Department
                                                </Button>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {departments.last_page > 1 && (
                    <LaravelPagination links={departments.links} />
                )}
            </div>

            <DepartmentDialog
                open={dialogOpen}
                onClose={closeDialog}
                department={editing}
                managers={managers}
                parentOptions={parentOptions}
            />
        </AppLayout>
    );
}
