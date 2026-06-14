import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

export interface Department {
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

interface DeptFormData {
    name: string;
    code: string;
    description: string;
    manager_user_id: string;
    parent_id: string;
    sort_order: number;
    is_active: boolean;
}

const NONE = '__none__';

/** Create/edit a department. Shared by the People-hub Departments tab. */
export function DepartmentDialog({
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
        manager_user_id: department?.manager_user_id
            ? String(department.manager_user_id)
            : '',
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

        const opts = {
            onSuccess: () => onClose(),
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
        };

        form.transform(() => payload);
        if (isEdit) form.put(`/hr/departments/${department!.id}`, opts);
        else form.post('/hr/departments', opts);
    }

    const filteredParents = parentOptions.filter(
        (p) => !department || p.id !== department.id,
    );

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit department' : 'New department'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update department details.'
                            : 'Create a new department for your organisation.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="dept-name">Name *</Label>
                            <Input
                                id="dept-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Care Services"
                            />
                            {form.errors.name && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dept-code">Code</Label>
                            <Input
                                id="dept-code"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. CS"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dept-sort">Sort order</Label>
                            <Input
                                id="dept-sort"
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(e) =>
                                    form.setData(
                                        'sort_order',
                                        parseInt(e.target.value) || 0,
                                    )
                                }
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="dept-desc">Description</Label>
                        <Textarea
                            id="dept-desc"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={2}
                            placeholder="Brief description of the department"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Manager</Label>
                            <Select
                                value={form.data.manager_user_id || NONE}
                                onValueChange={(v) =>
                                    form.setData(
                                        'manager_user_id',
                                        v === NONE ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select manager" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>None</SelectItem>
                                    {managers.map((m) => (
                                        <SelectItem
                                            key={m.id}
                                            value={String(m.id)}
                                        >
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Parent department</Label>
                            <Select
                                value={form.data.parent_id || NONE}
                                onValueChange={(v) =>
                                    form.setData('parent_id', v === NONE ? '' : v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="None (top-level)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        None (top-level)
                                    </SelectItem>
                                    {filteredParents.map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                        >
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {isEdit && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) =>
                                    form.setData('is_active', e.target.checked)
                                }
                                className="rounded border-border"
                            />
                            Active
                        </label>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {isEdit ? 'Save changes' : 'Create department'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default DepartmentDialog;
