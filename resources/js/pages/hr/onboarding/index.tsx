import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    OnboardingWizardDialog,
    type OnboardingEmailOption,
    type OnboardingEmployee,
} from '@/components/hr/onboarding-wizard-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardList, Plus, UserPlus } from 'lucide-react';
import { useState } from 'react';

const formatDate = (value: string | null) => {
    if (!value) return '\u2014';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

interface Checklist {
    id: number;
    employee_profile: {
        id: number;
        user: { name: string };
    };
    template_key: string;
    status: 'pending' | 'in_progress' | 'completed' | 'overdue';
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    tasks_count: number;
    tasks_completed_count: number;
}

interface TemplateTask {
    category: string;
    title: string;
    description: string | null;
    is_required: boolean;
    sort_order: number;
    assigned_to_role: string | null;
    sign_off_required: boolean;
}

interface TemplateRow {
    id: number;
    role: string;
    site_type: string | null;
    is_active: boolean;
    tasks: TemplateTask[];
    task_count: number;
    updated_at: string | null;
}

interface Props {
    checklists: {
        data: Checklist[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    templates: TemplateRow[];
    employees: OnboardingEmployee[];
    emailTemplates: OnboardingEmailOption[];
    templateRoleOptions: string[];
    siteTypeOptions: string[];
    filters: { status: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Onboarding', href: '/hr/onboarding' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'In Progress',
    },
    completed: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Completed',
    },
    overdue: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Overdue',
    },
};

const createTemplateTask = (sortOrder = 1): TemplateTask => ({
    category: 'general',
    title: '',
    description: '',
    is_required: true,
    sort_order: sortOrder,
    assigned_to_role: '',
    sign_off_required: false,
});

export default function OnboardingIndex({
    checklists,
    templates,
    employees,
    emailTemplates,
    templateRoleOptions,
    siteTypeOptions,
    filters,
    can,
}: Props) {
    const [editingTemplateId, setEditingTemplateId] = useState<number | null>(
        null,
    );
    const [wizardOpen, setWizardOpen] = useState(false);
    const { data, setData, put, processing, errors, reset, transform } =
        useForm({
            template_id: '',
            role: templateRoleOptions[0] ?? 'support_worker',
            site_type: siteTypeOptions[0] ?? 'all',
            is_active: true,
            tasks: [createTemplateTask()],
        });

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/onboarding',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    function resetTemplateForm() {
        setEditingTemplateId(null);
        reset();
        setData({
            template_id: '',
            role: templateRoleOptions[0] ?? 'support_worker',
            site_type: siteTypeOptions[0] ?? 'all',
            is_active: true,
            tasks: [createTemplateTask()],
        });
    }

    function addTemplateTask() {
        setData('tasks', [
            ...data.tasks,
            createTemplateTask(data.tasks.length + 1),
        ]);
    }

    function updateTemplateTask(index: number, patch: Partial<TemplateTask>) {
        setData(
            'tasks',
            data.tasks.map((task, i) =>
                i === index ? { ...task, ...patch } : task,
            ),
        );
    }

    function removeTemplateTask(index: number) {
        setData(
            'tasks',
            data.tasks
                .filter((_, i) => i !== index)
                .map((task, i) => ({ ...task, sort_order: i + 1 })),
        );
    }

    function startEditTemplate(template: TemplateRow) {
        setEditingTemplateId(template.id);
        setData({
            template_id: String(template.id),
            role: template.role,
            site_type: template.site_type || 'all',
            is_active: template.is_active,
            tasks:
                template.tasks.length > 0
                    ? template.tasks.map((task, i) => ({
                          ...task,
                          description: task.description || '',
                          assigned_to_role: task.assigned_to_role || '',
                          sort_order: task.sort_order || i + 1,
                      }))
                    : [createTemplateTask()],
        });
    }

    function submitTemplate(e: React.FormEvent) {
        e.preventDefault();

        const tasksPayload = data.tasks
            .map((task, index) => ({
                category: task.category?.trim() || 'general',
                title: task.title.trim(),
                description: task.description?.trim() || null,
                is_required: Boolean(task.is_required),
                sort_order: Number(task.sort_order || index + 1),
                assigned_to_role: task.assigned_to_role?.trim() || null,
                sign_off_required: Boolean(task.sign_off_required),
            }))
            .filter((task) => task.title !== '');

        const payload = {
            template_id: editingTemplateId ? String(editingTemplateId) : '',
            role: data.role,
            site_type: data.site_type,
            is_active: data.is_active,
            tasks: tasksPayload,
        };

        transform(() => payload);
        put('/hr/onboarding/templates', {
            preserveScroll: true,
            onSuccess: () => resetTemplateForm(),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Onboarding" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={UserPlus}
                        title="Onboarding Checklists"
                        description="Track and manage onboarding checklists for new employees."
                        stats={[
                            { label: 'Total', value: checklists.total },
                            { label: 'Templates', value: templates.length },
                            {
                                label: 'In progress',
                                value: checklists.data.filter((c) => c.status === 'in_progress').length,
                            },
                            {
                                label: 'Overdue',
                                value: checklists.data.filter((c) => c.status === 'overdue').length,
                            },
                        ]}
                        actions={
                            can.manage ? (
                                <Button onClick={() => setWizardOpen(true)}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Start onboarding
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search by employee name..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')
                                applyFilter(
                                    'q',
                                    (e.target as HTMLInputElement).value,
                                );
                        }}
                    />
                    <Select
                        value={filters.status || '__none__'}
                        onValueChange={(v) =>
                            applyFilter('status', v === '__none__' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="in_progress">
                                In Progress
                            </SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="overdue">Overdue</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {can.manage && (
                    <>
                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-lg font-semibold">
                                        {editingTemplateId
                                            ? 'Edit Onboarding Template'
                                            : 'Create Onboarding Template'}
                                    </h2>
                                    {editingTemplateId && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={resetTemplateForm}
                                        >
                                            Cancel Edit
                                        </Button>
                                    )}
                                </div>

                                <form
                                    onSubmit={submitTemplate}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Role</Label>
                                            <Select
                                                value={data.role || '__none__'}
                                                onValueChange={(v) =>
                                                    setData(
                                                        'role',
                                                        v === '__none__'
                                                            ? ''
                                                            : v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select role" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none__">
                                                        Select role
                                                    </SelectItem>
                                                    {templateRoleOptions.map(
                                                        (role) => (
                                                            <SelectItem
                                                                key={role}
                                                                value={role}
                                                            >
                                                                {role.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            {errors.role && (
                                                <p className="text-xs text-destructive">
                                                    {errors.role}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Site Type</Label>
                                            <Select
                                                value={
                                                    data.site_type || '__none__'
                                                }
                                                onValueChange={(v) =>
                                                    setData(
                                                        'site_type',
                                                        v === '__none__'
                                                            ? ''
                                                            : v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="All" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none__">
                                                        All
                                                    </SelectItem>
                                                    {siteTypeOptions.map(
                                                        (siteType) => (
                                                            <SelectItem
                                                                key={siteType}
                                                                value={siteType}
                                                            >
                                                                {siteType.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex items-end">
                                            <label className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={data.is_active}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setData(
                                                            'is_active',
                                                            Boolean(checked),
                                                        )
                                                    }
                                                />
                                                <span>Template active</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div className="space-y-3">
                                        <div className="flex items-center justify-between">
                                            <Label>Template Tasks</Label>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={addTemplateTask}
                                            >
                                                Add Task
                                            </Button>
                                        </div>

                                        {data.tasks.map((task, index) => (
                                            <div
                                                key={`${index}-${task.sort_order}`}
                                                className="space-y-3 rounded-md border p-3"
                                            >
                                                <div className="grid gap-3 md:grid-cols-4">
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Category
                                                        </Label>
                                                        <Select
                                                            value={
                                                                task.category ||
                                                                'general'
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                updateTemplateTask(
                                                                    index,
                                                                    {
                                                                        category:
                                                                            value,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="general">
                                                                    General
                                                                </SelectItem>
                                                                <SelectItem value="it">
                                                                    IT
                                                                </SelectItem>
                                                                <SelectItem value="compliance">
                                                                    Compliance
                                                                </SelectItem>
                                                                <SelectItem value="payroll">
                                                                    Payroll
                                                                </SelectItem>
                                                                <SelectItem value="induction">
                                                                    Induction
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="space-y-1 md:col-span-2">
                                                        <Label className="text-xs">
                                                            Task Title
                                                        </Label>
                                                        <Input
                                                            value={task.title}
                                                            onChange={(e) =>
                                                                updateTemplateTask(
                                                                    index,
                                                                    {
                                                                        title: e
                                                                            .target
                                                                            .value,
                                                                    },
                                                                )
                                                            }
                                                            placeholder="Create user account"
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Sort Order
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            value={
                                                                task.sort_order
                                                            }
                                                            onChange={(e) =>
                                                                updateTemplateTask(
                                                                    index,
                                                                    {
                                                                        sort_order:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value ||
                                                                                    index +
                                                                                        1,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <div className="grid gap-3 md:grid-cols-2">
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Description
                                                        </Label>
                                                        <Input
                                                            value={
                                                                task.description ||
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                updateTemplateTask(
                                                                    index,
                                                                    {
                                                                        description:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                            placeholder="Provide laptop, MFA, and email setup"
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Assigned Role
                                                        </Label>
                                                        <Input
                                                            value={
                                                                task.assigned_to_role ||
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                updateTemplateTask(
                                                                    index,
                                                                    {
                                                                        assigned_to_role:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                            placeholder="team_lead"
                                                        />
                                                    </div>
                                                </div>
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-6">
                                                        <label className="flex items-center gap-2 text-xs">
                                                            <Checkbox
                                                                checked={
                                                                    task.is_required
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    updateTemplateTask(
                                                                        index,
                                                                        {
                                                                            is_required:
                                                                                Boolean(
                                                                                    checked,
                                                                                ),
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <span>
                                                                Required
                                                            </span>
                                                        </label>
                                                        <label className="flex items-center gap-2 text-xs">
                                                            <Checkbox
                                                                checked={
                                                                    task.sign_off_required
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    updateTemplateTask(
                                                                        index,
                                                                        {
                                                                            sign_off_required:
                                                                                Boolean(
                                                                                    checked,
                                                                                ),
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <span>
                                                                Sign-off
                                                                required
                                                            </span>
                                                        </label>
                                                    </div>
                                                    {data.tasks.length > 1 && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                removeTemplateTask(
                                                                    index,
                                                                )
                                                            }
                                                        >
                                                            Remove
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                        {errors.tasks && (
                                            <p className="text-xs text-destructive">
                                                {errors.tasks}
                                            </p>
                                        )}
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Saving...'
                                            : editingTemplateId
                                              ? 'Update Template'
                                              : 'Create Template'}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Role
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Site Type
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Tasks
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {templates.map((template) => (
                                            <tr
                                                key={template.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {template.role.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {(
                                                        template.site_type ||
                                                        'all'
                                                    ).replace(/_/g, ' ')}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {template.task_count}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        variant={
                                                            template.is_active
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {template.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            startEditTemplate(
                                                                template,
                                                            )
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                        {templates.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-4 py-8 text-center text-muted-foreground"
                                                >
                                                    No onboarding templates
                                                    configured.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </>
                )}

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Employee
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Template
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Progress
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Due Date
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {checklists.data.map((checklist) => {
                                    const config =
                                        statusConfig[checklist.status] ||
                                        statusConfig.pending;
                                    const progressPercent =
                                        checklist.tasks_count > 0
                                            ? Math.round(
                                                  (checklist.tasks_completed_count /
                                                      checklist.tasks_count) *
                                                      100,
                                              )
                                            : 0;
                                    return (
                                        <tr
                                            key={checklist.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {
                                                    checklist.employee_profile
                                                        .user.name
                                                }
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground capitalize">
                                                {checklist.template_key.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Progress
                                                        value={progressPercent}
                                                        className="w-20"
                                                    />
                                                    <span className="text-xs text-muted-foreground">
                                                        {
                                                            checklist.tasks_completed_count
                                                        }
                                                        /{checklist.tasks_count}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatDate(checklist.due_date)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/onboarding/${checklist.id}`}
                                                    >
                                                        View
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {checklists.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-16 text-center"
                                        >
                                            <div className="flex flex-col items-center gap-3">
                                                <ClipboardList className="h-10 w-10 text-muted-foreground/50" />
                                                <div>
                                                    <p className="font-medium text-muted-foreground">
                                                        No onboarding checklists
                                                        found
                                                    </p>
                                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                                        {filters.q ||
                                                        filters.status
                                                            ? 'Try adjusting your search or filter criteria.'
                                                            : 'Get started by creating a checklist for a new employee.'}
                                                    </p>
                                                </div>
                                                {can.manage &&
                                                    !filters.q &&
                                                    !filters.status && (
                                                        <Button
                                                            size="sm"
                                                            className="mt-2"
                                                            onClick={() =>
                                                                setWizardOpen(true)
                                                            }
                                                        >
                                                            <Plus className="mr-2 h-4 w-4" />
                                                            Start onboarding
                                                        </Button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {checklists.total > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(checklists.current_page - 1) *
                                checklists.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                checklists.current_page * checklists.per_page,
                                checklists.total,
                            )}{' '}
                            of {checklists.total} results
                        </p>
                        {checklists.last_page > 1 && (
                            <LaravelPagination links={checklists.links} />
                        )}
                    </div>
                )}

                {can.manage && (
                    <OnboardingWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        employees={employees}
                        templates={templates}
                        emailTemplates={emailTemplates}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
