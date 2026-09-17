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
import { ShieldCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';

export interface ChangeTicketOption {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    href: string;
}

interface ChangeRow extends ChangeTicketOption {
    change_id: number;
    change_type: string;
    risk_level: string;
    is_restricted: boolean;
    impact_summary: string | null;
    maintenance_starts_at: string | null;
    maintenance_ends_at: string | null;
    maintenance_state: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    summary?: SpecialistMeter[];
    changes: { data: ChangeRow[]; links: PaginationLink[]; total: number };
    filters: {
        type: string | null;
        risk: string | null;
        state: string | null;
        q: string | null;
    };
    can: { manage: boolean };
}

export const changeLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

export const changeStateVariant: Record<string, StatusVariant> = {
    draft: 'neutral',
    assessment: 'info',
    approval_pending: 'warning',
    approved: 'info',
    scheduled: 'info',
    implementing: 'warning',
    validation: 'warning',
    completed: 'success',
    failed: 'critical',
    backed_out: 'critical',
    review: 'info',
    closed: 'neutral',
    rejected: 'critical',
    cancelled: 'neutral',
};

const maintenanceLabels: Record<string, string> = {
    upcoming: 'Upcoming window',
    active: 'Window active',
    overdue: 'Window overdue',
    unscheduled: 'Window not set',
    emergency: 'Emergency execution',
    finished: 'Execution finished',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
    { title: 'Changes', href: '/it/changes' },
];

export default function ItChangesIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ItChangesWorkspace
            key={auth.user.id}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ItChangesWorkspace({
    actorId,
    changes,
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
        change_type: 'normal',
        risk_level: 'medium',
        is_restricted: false,
        impact_summary: '',
    });

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/changes', {
            onSuccess: (page) =>
                setCreated(
                    specialistCreationFrom(page.props, 'changes', actorId),
                ),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Changes" />
            <ItModuleShell>
                <main className="min-w-0 space-y-5">
                    <SpecialistWorkspaceHeader
                        title="Changes"
                        description="Risk, approvals, maintenance windows and verified outcomes"
                        icon={ShieldCheck}
                        path="/it/changes"
                        query={filters.q}
                        filters={[
                            {
                                key: 'type',
                                label: 'Type',
                                value: filters.type,
                                options: [
                                    'standard',
                                    'normal',
                                    'emergency',
                                ].map((value) => ({
                                    value,
                                    label: changeLabel(value),
                                })),
                            },
                            {
                                key: 'risk',
                                label: 'Risk',
                                value: filters.risk,
                                options: [
                                    'low',
                                    'medium',
                                    'high',
                                    'critical',
                                ].map((value) => ({
                                    value,
                                    label: changeLabel(value),
                                })),
                            },
                            {
                                key: 'state',
                                label: 'State',
                                value: filters.state,
                                options: Object.keys(changeStateVariant).map(
                                    (value) => ({
                                        value,
                                        label: changeLabel(value),
                                    }),
                                ),
                            },
                        ]}
                        meters={summary}
                        createLabel="New change"
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
                        title="Change register"
                        icon={ShieldCheck}
                        total={changes.total}
                        links={changes.links}
                        rows={changes.data.map((change) => ({
                            id: change.change_id,
                            reference: change.reference,
                            title: change.title,
                            href: `/it/changes/${change.change_id}`,
                            state: {
                                label: changeLabel(change.workflow_state),
                                tone:
                                    changeStateVariant[change.workflow_state] ??
                                    'neutral',
                            },
                            priority: changeLabel(change.priority),
                            impact:
                                change.impact_summary ??
                                'Impact is still being assessed.',
                            facts: [
                                {
                                    label: 'Type',
                                    value: changeLabel(change.change_type),
                                },
                                {
                                    label: 'Risk',
                                    value: changeLabel(change.risk_level),
                                },
                                {
                                    label: 'Window',
                                    value:
                                        maintenanceLabels[
                                            change.maintenance_state
                                        ] ?? 'Not recorded',
                                },
                                ...(change.maintenance_starts_at
                                    ? [
                                          {
                                              label: 'Starts',
                                              value: formatDate(
                                                  change.maintenance_starts_at,
                                              ),
                                          },
                                      ]
                                    : []),
                                ...(change.maintenance_ends_at
                                    ? [
                                          {
                                              label: 'Ends',
                                              value: formatDate(
                                                  change.maintenance_ends_at,
                                              ),
                                          },
                                      ]
                                    : []),
                            ],
                            alerts:
                                change.maintenance_state === 'overdue'
                                    ? [
                                          {
                                              label: 'Maintenance window overdue',
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
                title="New change"
                description="Record the proposed change, its impact and initial risk assessment."
                icon={ShieldCheck}
                submitLabel="Create change"
                processing={form.processing}
                dirty={form.isDirty}
                errors={form.errors}
                review={[
                    { label: 'Title', value: form.data.title },
                    { label: 'Description', value: form.data.description },
                    {
                        label: 'Category',
                        value: changeLabel(form.data.category),
                    },
                    {
                        label: 'Priority',
                        value: changeLabel(form.data.priority),
                    },
                    {
                        label: 'Change type',
                        value: changeLabel(form.data.change_type),
                    },
                    { label: 'Risk', value: changeLabel(form.data.risk_level) },
                    {
                        label: 'Restricted',
                        value: form.data.is_restricted ? 'Yes' : 'No',
                    },
                    {
                        label: 'Impact summary',
                        value: form.data.impact_summary,
                    },
                ]}
                onSubmit={create}
            >
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Change title"
                        error={form.errors.title}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="What will change?"
                        error={form.errors.description}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={3}
                        />
                    </Field>
                    <Field
                        label="Expected impact"
                        error={form.errors.impact_summary}
                        className="sm:col-span-2"
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
                    <Field label="Change type">
                        <FormSelect
                            value={form.data.change_type}
                            onChange={(value) =>
                                form.setData('change_type', value)
                            }
                            values={['standard', 'normal', 'emergency']}
                        />
                    </Field>
                    <Field label="Risk level">
                        <FormSelect
                            value={form.data.risk_level}
                            onChange={(value) =>
                                form.setData('risk_level', value)
                            }
                            values={['low', 'medium', 'high', 'critical']}
                        />
                    </Field>
                    <Field label="Category">
                        <FormSelect
                            value={form.data.category}
                            onChange={(value) =>
                                form.setData('category', value)
                            }
                            values={['hardware', 'account', 'network', 'other']}
                        />
                    </Field>
                    <Field label="Priority">
                        <FormSelect
                            value={form.data.priority}
                            onChange={(value) =>
                                form.setData('priority', value)
                            }
                            values={['low', 'normal', 'high', 'urgent']}
                        />
                    </Field>
                    <label className="flex min-h-11 items-center gap-3 rounded-lg border border-border px-3 text-sm font-medium sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.data.is_restricted}
                            onChange={(event) =>
                                form.setData(
                                    'is_restricted',
                                    event.target.checked,
                                )
                            }
                            className="h-4 w-4"
                        />
                        Restricted or privileged change
                    </label>
                </div>
            </SpecialistCreateWizard>
        </AppLayout>
    );
}

function FormSelect({
    label,
    value,
    onChange,
    values,
}: {
    label?: string;
    value: string;
    onChange: (value: string) => void;
    values: string[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="min-h-11" aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {values.map((item) => (
                    <SelectItem key={item} value={item}>
                        {item === 'all'
                            ? `All ${label?.toLowerCase() ?? 'values'}s`
                            : changeLabel(item)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function Field({
    label,
    error,
    className,
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label
            className={`block space-y-1.5 text-sm font-medium ${className ?? ''}`}
        >
            <span>{label}</span>
            {children}
            {error ? (
                <span className="block text-xs text-destructive">{error}</span>
            ) : null}
        </label>
    );
}

function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
