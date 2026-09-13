import { ItModuleShell } from '@/components/it/it-module-shell';
import { SpecialistCreateWizard } from '@/components/it/specialist-create-wizard';
import {
    SpecialistCreationResult,
    specialistCreationFrom,
    type SpecialistCreation,
} from '@/components/it/specialist-creation-result';
import { SpecialistRecordList } from '@/components/it/specialist-record-list';
import {
    SpecialistWorkspaceHeader,
    type SpecialistMeter,
} from '@/components/it/specialist-workspace-header';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { BookOpenCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface ProblemRow {
    id: number;
    problem_id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    impact_summary: string | null;
    known_error_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    summary?: SpecialistMeter[];
    problems: {
        data: ProblemRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { state: string | null; q: string | null };
    can: { manage: boolean };
}

export const problemStateVariant: Record<string, StatusVariant> = {
    submitted: 'neutral',
    investigating: 'info',
    waiting: 'warning',
    known_error: 'warning',
    resolved: 'success',
    closed: 'neutral',
};

export const problemLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
    { title: 'Problems & known errors', href: '/it/problems' },
];

export default function ItProblemsIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ItProblemsWorkspace
            key={auth.user.id}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ItProblemsWorkspace({
    actorId,
    problems,
    filters,
    can,
    summary = [],
}: Props & { actorId: number }) {
    const [creating, setCreating] = useState(false);
    const [created, setCreated] = useState<SpecialistCreation | null>(null);
    const form = useForm({
        actor_user_id: actorId,
        wizard: true,
        title: '',
        description: '',
        category: 'other',
        priority: 'normal',
        impact_summary: '',
    });

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/problems', {
            onSuccess: (page) =>
                setCreated(
                    specialistCreationFrom(page.props, 'problems', actorId),
                ),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Problems & known errors" />
            <ItModuleShell>
                <main className="min-w-0 space-y-5">
                    <SpecialistWorkspaceHeader
                        title="Problems & known errors"
                        description="Recurring incidents, investigation, workarounds and permanent fixes"
                        icon={BookOpenCheck}
                        path="/it/problems"
                        query={filters.q}
                        filters={[
                            {
                                key: 'state',
                                label: 'State',
                                value: filters.state,
                                options: Object.keys(problemStateVariant).map(
                                    (value) => ({
                                        value,
                                        label: problemLabel(value),
                                    }),
                                ),
                            },
                        ]}
                        meters={summary}
                        createLabel="New problem"
                        onCreate={
                            can.manage
                                ? () => {
                                      setCreated(null);
                                      form.resetAndClearErrors();
                                      setCreating(true);
                                  }
                                : undefined
                        }
                    />

                    <SpecialistRecordList
                        title="Problem register"
                        icon={BookOpenCheck}
                        total={problems.total}
                        links={problems.links}
                        rows={problems.data.map((problem) => ({
                            id: problem.problem_id,
                            reference: problem.reference,
                            title: problem.title,
                            href: `/it/problems/${problem.problem_id}`,
                            state: {
                                label: problemLabel(problem.workflow_state),
                                tone:
                                    problemStateVariant[
                                        problem.workflow_state
                                    ] ?? 'neutral',
                            },
                            priority: problemLabel(problem.priority),
                            impact:
                                problem.impact_summary ??
                                'Impact is still being assessed.',
                            facts: [
                                {
                                    label: 'Known error',
                                    value: problem.known_error_at
                                        ? 'Recorded'
                                        : 'Not recorded',
                                },
                            ],
                            alerts:
                                problem.workflow_state === 'known_error'
                                    ? [
                                          {
                                              label: 'Known error',
                                              tone: 'warning' as const,
                                          },
                                      ]
                                    : [],
                        }))}
                    />
                </main>
            </ItModuleShell>

            <SpecialistCreateWizard
                success={
                    created ? (
                        <SpecialistCreationResult
                            result={created}
                            onClose={() => setCreating(false)}
                            onAnother={() => {
                                form.resetAndClearErrors();
                                setCreated(null);
                            }}
                        />
                    ) : undefined
                }
                allowed={can.manage}
                open={creating}
                onClose={() => setCreating(false)}
                onDiscard={() => {
                    form.reset();
                    form.clearErrors();
                }}
                title="Open a problem investigation"
                description="Record the recurring issue, its impact and investigation priority."
                icon={BookOpenCheck}
                submitLabel="Open investigation"
                processing={form.processing}
                dirty={form.isDirty}
                errors={form.errors}
                review={[
                    { label: 'Title', value: form.data.title },
                    { label: 'Description', value: form.data.description },
                    {
                        label: 'Category',
                        value: problemLabel(form.data.category),
                    },
                    {
                        label: 'Priority',
                        value: problemLabel(form.data.priority),
                    },
                    {
                        label: 'Impact summary',
                        value: form.data.impact_summary,
                    },
                ]}
                onSubmit={create}
            >
                <div className="mt-5 space-y-4">
                    <Field label="Problem title" error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="What is happening?"
                        error={form.errors.description}
                    >
                        <Textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={4}
                        />
                    </Field>
                    <Field
                        label="Impact summary"
                        error={form.errors.impact_summary}
                    >
                        <Textarea
                            value={form.data.impact_summary}
                            onChange={(event) =>
                                form.setData(
                                    'impact_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Category">
                            <Select
                                value={form.data.category}
                                onValueChange={(value) =>
                                    form.setData('category', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        'hardware',
                                        'account',
                                        'network',
                                        'other',
                                    ].map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {problemLabel(value)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Priority">
                            <Select
                                value={form.data.priority}
                                onValueChange={(value) =>
                                    form.setData('priority', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {['low', 'normal', 'high', 'urgent'].map(
                                        (value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {problemLabel(value)}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                </div>
            </SpecialistCreateWizard>
        </AppLayout>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block space-y-1.5 text-sm font-medium">
            <span>{label}</span>
            {children}
            {error ? (
                <span className="block text-xs text-destructive">{error}</span>
            ) : null}
        </label>
    );
}
