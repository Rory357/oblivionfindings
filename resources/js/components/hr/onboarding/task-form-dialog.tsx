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
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export interface TaskFormTarget {
    id: number;
    title: string;
    description: string | null;
    category: string;
    due_date: string | null;
    is_required: boolean;
    sign_off_required: boolean;
    assigned_to_user_id: number | null;
}

export interface OwnerOption {
    id: number;
    name: string | null;
}

const CATEGORIES = ['general', 'compliance', 'it', 'payroll', 'induction'];
const UNASSIGNED = '__none__';

/**
 * Add an ad-hoc task to a checklist, or edit an existing one.
 *  - add:  checklistId set, task null → POST /hr/onboarding/{checklist}/tasks
 *  - edit: task set                  → PATCH /hr/onboarding/tasks/{task}
 */
export function TaskFormDialog({
    open,
    onClose,
    checklistId,
    task,
    owners,
}: {
    open: boolean;
    onClose: () => void;
    checklistId: number;
    task: TaskFormTarget | null;
    owners: OwnerOption[];
}) {
    const form = useForm({
        title: '',
        description: '',
        category: 'general',
        due_date: '',
        is_required: false,
        sign_off_required: false,
        assigned_to_user_id: UNASSIGNED,
    });

    useEffect(() => {
        if (!open) return;
        form.setData(
            task
                ? {
                      title: task.title,
                      description: task.description ?? '',
                      category: task.category || 'general',
                      due_date: task.due_date ?? '',
                      is_required: task.is_required,
                      sign_off_required: task.sign_off_required,
                      assigned_to_user_id: task.assigned_to_user_id ? String(task.assigned_to_user_id) : UNASSIGNED,
                  }
                : {
                      title: '',
                      description: '',
                      category: 'general',
                      due_date: '',
                      is_required: false,
                      sign_off_required: false,
                      assigned_to_user_id: UNASSIGNED,
                  },
        );
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            description: data.description || null,
            due_date: data.due_date || null,
            assigned_to_user_id: data.assigned_to_user_id === UNASSIGNED ? null : data.assigned_to_user_id,
        }));
        if (task) {
            form.patch(`/hr/onboarding/tasks/${task.id}`, {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        } else {
            form.post(`/hr/onboarding/${checklistId}/tasks`, {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="p-0 sm:max-w-[540px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>{task ? 'Edit task' : 'Add task'}</DialogTitle>
                    <DialogDescription>
                        {task ? 'Update this task or reassign its owner.' : 'Ad-hoc task for this checklist.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 px-6 py-5">
                    <div className="space-y-1.5">
                        <Label>Title</Label>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="e.g. Order uniform"
                        />
                        {form.errors.title && <p className="text-xs text-status-critical">{form.errors.title}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Category</Label>
                            <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CATEGORIES.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c.replace(/\b\w/g, (ch) => ch.toUpperCase())}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Due date</Label>
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) => form.setData('due_date', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Owner</Label>
                        <Select
                            value={form.data.assigned_to_user_id}
                            onValueChange={(v) => form.setData('assigned_to_user_id', v)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Unassigned" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={UNASSIGNED}>Unassigned</SelectItem>
                                {owners.map((o) => (
                                    <SelectItem key={o.id} value={String(o.id)}>
                                        {o.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Description</Label>
                        <Textarea
                            rows={2}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder="Optional details…"
                        />
                    </div>

                    <div className="flex gap-5">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_required}
                                onCheckedChange={(c) => form.setData('is_required', Boolean(c))}
                            />
                            Required
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.sign_off_required}
                                onCheckedChange={(c) => form.setData('sign_off_required', Boolean(c))}
                            />
                            Sign-off required
                        </label>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : task ? 'Save task' : 'Add task'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default TaskFormDialog;
