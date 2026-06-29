import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect } from 'react';

import { prettyLabel } from './shared';

export interface TemplateTask {
    category: string;
    title: string;
    description: string | null;
    is_required: boolean;
    sort_order: number;
    assigned_to_role: string | null;
    sign_off_required: boolean;
}

export interface TemplateRow {
    id: number;
    role: string;
    site_type: string | null;
    is_active: boolean;
    tasks: TemplateTask[];
    task_count: number;
    chips: string[];
    updated_at: string | null;
}

const CATEGORIES = ['general', 'compliance', 'it', 'payroll', 'induction'];

const blankTask = (sortOrder = 1): TemplateTask => ({
    category: 'general',
    title: '',
    description: '',
    is_required: true,
    sort_order: sortOrder,
    assigned_to_role: '',
    sign_off_required: false,
});

export function TemplateDialog({
    open,
    onClose,
    template,
    roleOptions,
    siteTypeOptions,
}: {
    open: boolean;
    onClose: () => void;
    template: TemplateRow | null;
    roleOptions: string[];
    siteTypeOptions: string[];
}) {
    const form = useForm<{
        template_id: string;
        role: string;
        site_type: string;
        is_active: boolean;
        tasks: TemplateTask[];
    }>({
        template_id: '',
        role: roleOptions[0] ?? 'support_worker',
        site_type: siteTypeOptions[0] ?? 'all',
        is_active: true,
        tasks: [blankTask()],
    });

    // Re-seed when the editing target changes.
    useEffect(() => {
        if (!open) return;
        if (template) {
            form.setData({
                template_id: String(template.id),
                role: template.role,
                site_type: template.site_type || 'all',
                is_active: template.is_active,
                tasks:
                    template.tasks.length > 0
                        ? template.tasks.map((t, i) => ({
                              ...t,
                              description: t.description || '',
                              assigned_to_role: t.assigned_to_role || '',
                              sort_order: t.sort_order || i + 1,
                          }))
                        : [blankTask()],
            });
        } else {
            form.setData({
                template_id: '',
                role: roleOptions[0] ?? 'support_worker',
                site_type: siteTypeOptions[0] ?? 'all',
                is_active: true,
                tasks: [blankTask()],
            });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, template?.id]);

    const setTask = (i: number, patch: Partial<TemplateTask>) =>
        form.setData(
            'tasks',
            form.data.tasks.map((t, idx) => (idx === i ? { ...t, ...patch } : t)),
        );

    const addTask = () =>
        form.setData('tasks', [...form.data.tasks, blankTask(form.data.tasks.length + 1)]);

    const removeTask = (i: number) =>
        form.setData(
            'tasks',
            form.data.tasks
                .filter((_, idx) => idx !== i)
                .map((t, idx) => ({ ...t, sort_order: idx + 1 })),
        );

    const submit = () => {
        form.transform((data) => ({
            ...data,
            template_id: template ? String(template.id) : '',
            tasks: data.tasks
                .map((t, i) => ({
                    category: t.category?.trim() || 'general',
                    title: t.title.trim(),
                    description: t.description?.trim() || null,
                    is_required: Boolean(t.is_required),
                    sort_order: Number(t.sort_order || i + 1),
                    assigned_to_role: t.assigned_to_role?.trim() || null,
                    sign_off_required: Boolean(t.sign_off_required),
                }))
                .filter((t) => t.title !== ''),
        }));
        form.put('/hr/onboarding/templates', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-hidden p-0 sm:max-w-[720px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>{template ? 'Edit template' : 'New template'}</DialogTitle>
                    <DialogDescription>
                        Define a reusable onboarding task set, auto-matched to a role when you start an onboarding.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] space-y-4 overflow-y-auto px-6 py-5">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Role</Label>
                            <Select value={form.data.role} onValueChange={(v) => form.setData('role', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roleOptions.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {prettyLabel(r)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.role && <p className="text-xs text-status-critical">{form.errors.role}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Site type</Label>
                            <Select value={form.data.site_type} onValueChange={(v) => form.setData('site_type', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All" />
                                </SelectTrigger>
                                <SelectContent>
                                    {siteTypeOptions.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {prettyLabel(s)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.is_active}
                                    onCheckedChange={(c) => form.setData('is_active', Boolean(c))}
                                />
                                Template active
                            </label>
                        </div>
                    </div>

                    <div className="space-y-2.5">
                        <div className="flex items-center justify-between">
                            <Label>Tasks</Label>
                            <Button type="button" size="sm" variant="outline" onClick={addTask}>
                                <Plus className="mr-1 h-3.5 w-3.5" /> Add task
                            </Button>
                        </div>

                        {form.data.tasks.map((task, i) => (
                            <div key={i} className="space-y-2.5 rounded-lg border border-border p-3">
                                <div className="grid gap-2.5 sm:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Category</Label>
                                        <Select value={task.category || 'general'} onValueChange={(v) => setTask(i, { category: v })}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {CATEGORIES.map((c) => (
                                                    <SelectItem key={c} value={c}>
                                                        {prettyLabel(c)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1 sm:col-span-2">
                                        <Label className="text-xs">Title</Label>
                                        <Input
                                            value={task.title}
                                            onChange={(e) => setTask(i, { title: e.target.value })}
                                            placeholder="Create user account"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Order</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={task.sort_order}
                                            onChange={(e) => setTask(i, { sort_order: Number(e.target.value || i + 1) })}
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-2.5 sm:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Description</Label>
                                        <Input
                                            value={task.description || ''}
                                            onChange={(e) => setTask(i, { description: e.target.value })}
                                            placeholder="Provide laptop, MFA, email setup"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Assigned role</Label>
                                        <Input
                                            value={task.assigned_to_role || ''}
                                            onChange={(e) => setTask(i, { assigned_to_role: e.target.value })}
                                            placeholder="team_lead"
                                        />
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-5">
                                        <label className="flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={task.is_required}
                                                onCheckedChange={(c) => setTask(i, { is_required: Boolean(c) })}
                                            />
                                            Required
                                        </label>
                                        <label className="flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={task.sign_off_required}
                                                onCheckedChange={(c) => setTask(i, { sign_off_required: Boolean(c) })}
                                            />
                                            Sign-off required
                                        </label>
                                    </div>
                                    {form.data.tasks.length > 1 && (
                                        <Button type="button" variant="ghost" size="sm" onClick={() => removeTask(i)}>
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                        {form.errors.tasks && <p className="text-xs text-status-critical">{form.errors.tasks}</p>}
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : template ? 'Update template' : 'Create template'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default TemplateDialog;
