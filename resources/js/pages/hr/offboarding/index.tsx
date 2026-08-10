import { LifecycleTabs } from '@/components/hr/lifecycle-tabs';
import {
    OffboardingWizardDialog,
    type DepartureReason,
    type OffboardingEmployee,
    type OffboardingInterviewer,
    type OffboardingTaskPreview,
} from '@/components/hr/offboarding-wizard-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, UserMinus } from 'lucide-react';
import { useState } from 'react';

interface Checklist {
    id: number;
    employee_profile: {
        id: number;
        user: { name: string };
    };
    template_key: string;
    status: 'pending' | 'in_progress' | 'completed' | 'cancelled' | 'overdue';
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    tasks_count: number;
    tasks_completed_count: number;
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
    summary: {
        pending: number;
        in_progress: number;
        completed: number;
        overdue: number;
        due_next_7_days: number;
        total: number;
    };
    employees: OffboardingEmployee[];
    interviewers: OffboardingInterviewer[];
    departureReasons: DepartureReason[];
    defaultTasks: OffboardingTaskPreview[];
    defaultEndDate: string;
    filters: { status: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Offboarding', href: '/hr/offboarding' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'In Progress',
    },
    completed: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Completed',
    },
    cancelled: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Cancelled',
    },
    overdue: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Overdue',
    },
};

export default function OffboardingIndex({
    checklists,
    summary,
    employees,
    interviewers,
    departureReasons,
    defaultTasks,
    defaultEndDate,
    filters,
    can,
}: Props) {
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );

    // `?employee={profile_id}` (e.g. from the disciplinary dismissal CTA)
    // preselects the leaver in the wizard.
    const [initialEmployeeProfileId] = useState(() =>
        typeof window !== 'undefined'
            ? (new URLSearchParams(window.location.search).get('employee') ??
              '')
            : '',
    );

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/offboarding',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Offboarding" />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={UserMinus}
                        title="Offboarding Checklists"
                        description="Manage employee exits with structured checklists and progress tracking."
                        stats={[
                            { label: 'Pending', value: summary.pending },
                            {
                                label: 'In progress',
                                value: summary.in_progress,
                            },
                            { label: 'Overdue', value: summary.overdue },
                            { label: 'Completed', value: summary.completed },
                        ]}
                        actions={
                            can.manage && (
                                <Button onClick={() => setWizardOpen(true)}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Start offboarding
                                </Button>
                            )
                        }
                    />
                }
                tabs={<LifecycleTabs active="offboarding" />}
            >
                <div className="grid gap-3 md:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Pending
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {summary.pending}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                In Progress
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {summary.in_progress}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Overdue
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {summary.overdue}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Due in 7 Days
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {summary.due_next_7_days}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Completed
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {summary.completed}
                            </p>
                        </CardContent>
                    </Card>
                </div>

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
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="in_progress">
                                In Progress
                            </SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

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
                                                    /[:_]/g,
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
                                                {checklist.due_date || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/offboarding/${checklist.id}`}
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
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No offboarding checklists found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {checklists.last_page > 1 && (
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
                        <LaravelPagination links={checklists.links} />
                    </div>
                )}

                {can.manage && (
                    <OffboardingWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        employees={employees}
                        defaultTasks={defaultTasks}
                        departureReasons={departureReasons}
                        interviewers={interviewers}
                        defaultEndDate={defaultEndDate}
                        initialEmployeeProfileId={initialEmployeeProfileId}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
