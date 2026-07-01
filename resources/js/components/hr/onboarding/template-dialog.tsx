import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LayoutTemplate, ListChecks, Plus, Settings2, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';

import { prettyLabel } from './shared';

export interface TemplateTask {
    category: string;
    title: string;
    description: string | null;
    is_required: boolean;
    sort_order: number;
    assigned_to_role: string | null;
    sign_off_required: boolean;
    course_code?: string | null;
}

export interface CourseOption {
    code: string;
    title: string;
    is_mandatory: boolean;
}

const NO_COURSE = '__none__';

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

const STEPS: readonly WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Role, site type & status', icon: Settings2 },
    { key: 'tasks', label: 'Tasks', blurb: 'Build the task set', icon: ListChecks },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: CheckCircle2 },
];

const blankTask = (sortOrder = 1): TemplateTask => ({
    category: 'general',
    title: '',
    description: '',
    is_required: true,
    sort_order: sortOrder,
    assigned_to_role: '',
    sign_off_required: false,
    course_code: '',
});

export function TemplateDialog({
    open,
    onClose,
    template,
    roleOptions,
    siteTypeOptions,
    courseOptions = [],
}: {
    open: boolean;
    onClose: () => void;
    template: TemplateRow | null;
    roleOptions: string[];
    siteTypeOptions: string[];
    courseOptions?: CourseOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);

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
                              course_code: t.course_code || '',
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
        setDone(false);
        wizard.reset();
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

    const validTasks = form.data.tasks.filter((t) => t.title.trim() !== '');
    const canSubmit = validTasks.length > 0;

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
                    course_code:
                        t.category === 'induction' ? t.course_code?.trim() || null : null,
                }))
                .filter((t) => t.title !== ''),
        }));
        form.put('/hr/onboarding/templates', {
            preserveScroll: true,
            onSuccess: () => setDone(true),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={template ? 'Edit template' : 'New template'}
            description="Define a reusable onboarding task set, auto-matched to a role when you start an onboarding."
            railIcon={LayoutTemplate}
            railTitle={template ? 'Edit template' : 'New template'}
            railSub="Onboarding templates"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={template ? 'Template updated' : 'Template created'}
                        blurb={
                            <>
                                The {prettyLabel(form.data.role)} template with {validTasks.length}{' '}
                                {validTasks.length === 1 ? 'task' : 'tasks'} is saved and will
                                auto-match when you start an onboarding.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !canSubmit}>
                            {form.processing
                                ? 'Saving…'
                                : template
                                  ? 'Update template'
                                  : 'Create template'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={wizard.index === 1 && !canSubmit}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Settings2}
                        title="Template basics"
                        blurb="Which role this task set is for, and where it applies."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Role" required error={form.errors.role}>
                            <SelectInput
                                value={form.data.role}
                                onChange={(v) => form.setData('role', v)}
                                placeholder="Select role"
                                options={roleOptions.map((r) => ({ value: r, label: prettyLabel(r) }))}
                            />
                        </Field>
                        <Field label="Site type" error={form.errors.site_type}>
                            <SelectInput
                                value={form.data.site_type}
                                onChange={(v) => form.setData('site_type', v)}
                                placeholder="All"
                                options={siteTypeOptions.map((s) => ({ value: s, label: prettyLabel(s) }))}
                            />
                        </Field>
                        <Field label="Status" hint="inactive templates are never auto-matched">
                            <Segmented
                                value={form.data.is_active ? 'active' : 'inactive'}
                                onChange={(v) => form.setData('is_active', v === 'active')}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ListChecks}
                        title="Build the task set"
                        blurb="Every onboarding started from this template gets these tasks."
                    />
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
                                        <SelectInput
                                            value={task.category || 'general'}
                                            onChange={(v) => setTask(i, { category: v })}
                                            placeholder="Category"
                                            options={CATEGORIES.map((c) => ({
                                                value: c,
                                                label: prettyLabel(c),
                                            }))}
                                        />
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
                                {task.category === 'induction' && (
                                    <div className="space-y-1">
                                        <Label className="text-xs">
                                            Auto-enrol course{' '}
                                            <span className="font-normal text-muted-foreground">
                                                (optional — defaults to mandatory courses)
                                            </span>
                                        </Label>
                                        <SelectInput
                                            value={task.course_code || NO_COURSE}
                                            onChange={(v) => setTask(i, { course_code: v === NO_COURSE ? '' : v })}
                                            placeholder="Mandatory courses"
                                            options={[
                                                { value: NO_COURSE, label: 'Mandatory courses (default)' },
                                                ...courseOptions.map((c) => ({
                                                    value: c.code,
                                                    label: `${c.title} (${c.code})`,
                                                })),
                                            ]}
                                        />
                                    </div>
                                )}
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
                        <FieldErr>{form.errors.tasks}</FieldErr>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review the template"
                        blurb="Check the details, then confirm below."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Settings2} title="Basics" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Role" value={prettyLabel(form.data.role)} />
                            <ReviewRow label="Site type" value={prettyLabel(form.data.site_type)} />
                            <ReviewRow label="Status" value={form.data.is_active ? 'Active' : 'Inactive'} />
                        </ReviewCard>
                        <ReviewCard icon={ListChecks} title="Tasks" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow
                                label="Task count"
                                value={`${validTasks.length} ${validTasks.length === 1 ? 'task' : 'tasks'}`}
                            />
                            <ReviewRow
                                label="Sign-off required"
                                value={`${validTasks.filter((t) => t.sign_off_required).length} of ${validTasks.length}`}
                            />
                        </ReviewCard>
                        <ReviewCard icon={ListChecks} title="Task list" onEdit={() => wizard.goTo(1)} span>
                            {validTasks.length > 0 ? (
                                validTasks.map((t, i) => (
                                    <ReviewRow
                                        key={i}
                                        label={`${i + 1}. ${t.title.trim()}`}
                                        value={prettyLabel(t.category || 'general')}
                                    />
                                ))
                            ) : (
                                <ReviewRow label="No tasks yet" />
                            )}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default TemplateDialog;
