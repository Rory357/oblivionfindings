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
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface UserOption {
    id: number;
    name: string;
}

export interface MajorIncidentTicketOption {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    href: string;
}

interface MajorIncidentRow extends MajorIncidentTicketOption {
    major_incident_id: number;
    severity: string;
    impact_summary: string | null;
    commander: UserOption | null;
    communications_lead: UserOption | null;
    next_update_due_at: string | null;
    update_state: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    summary?: SpecialistMeter[];
    majorIncidents: {
        data: MajorIncidentRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: {
        severity: string | null;
        state: string | null;
        q: string | null;
    };
    options: { agents: UserOption[] };
    can: { manage: boolean };
}

export const majorIncidentLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

export const majorIncidentStateVariant: Record<string, StatusVariant> = {
    declared: 'critical',
    responding: 'critical',
    monitoring: 'warning',
    restored: 'success',
    resolved: 'success',
    review: 'info',
    closed: 'neutral',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
    { title: 'Major incidents', href: '/it/major-incidents' },
];

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-NZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Pacific/Auckland',
          }).format(new Date(value))
        : 'Not scheduled';

export default function ItMajorIncidentsIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ItMajorIncidentsWorkspace
            key={auth.user.id}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ItMajorIncidentsWorkspace({
    actorId,
    majorIncidents,
    summary = [],
    filters,
    options,
    can,
}: Props & { actorId: number }) {
    const [creating, setCreating] = useState(false);
    const [created, setCreated] = useState<SpecialistCreation | null>(null);
    const form = useForm({
        actor_user_id: actorId,
        wizard: true,
        title: '',
        description: '',
        category: 'other',
        priority: 'urgent',
        severity: 'sev2',
        impact_summary: '',
        communications_lead_user_id: '',
        target_update_minutes: 30,
    });

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/major-incidents', {
            onSuccess: (page) =>
                setCreated(
                    specialistCreationFrom(
                        page.props,
                        'major-incidents',
                        actorId,
                    ),
                ),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Major incidents" />
            <ItModuleShell>
                <main className="min-w-0 space-y-5">
                    <SpecialistWorkspaceHeader
                        title="Major incidents"
                        description="Technical response, accountable command and audience updates"
                        icon={Siren}
                        path="/it/major-incidents"
                        query={filters.q}
                        filters={[
                            {
                                key: 'severity',
                                label: 'Severity',
                                value: filters.severity,
                                options: ['sev1', 'sev2', 'sev3', 'sev4'].map(
                                    (value) => ({
                                        value,
                                        label: majorIncidentLabel(value),
                                    }),
                                ),
                            },
                            {
                                key: 'state',
                                label: 'State',
                                value: filters.state,
                                options: Object.keys(
                                    majorIncidentStateVariant,
                                ).map((value) => ({
                                    value,
                                    label: majorIncidentLabel(value),
                                })),
                            },
                        ]}
                        meters={summary}
                        createLabel="Declare major incident"
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
                        title="Command register"
                        icon={Siren}
                        total={majorIncidents.total}
                        links={majorIncidents.links}
                        rows={majorIncidents.data.map((incident) => ({
                            id: incident.major_incident_id,
                            reference: incident.reference,
                            title: incident.title,
                            href: `/it/major-incidents/${incident.major_incident_id}`,
                            state: {
                                label: majorIncidentLabel(
                                    incident.workflow_state,
                                ),
                                tone:
                                    majorIncidentStateVariant[
                                        incident.workflow_state
                                    ] ?? 'neutral',
                            },
                            priority: majorIncidentLabel(incident.priority),
                            impact:
                                incident.impact_summary ??
                                'Impact summary not recorded.',
                            owner: incident.commander?.name,
                            facts: [
                                {
                                    label: 'Severity',
                                    value: incident.severity.toUpperCase(),
                                },
                                {
                                    label: 'Commander',
                                    value:
                                        incident.commander?.name ??
                                        'Unassigned',
                                },
                                {
                                    label: 'Communications lead',
                                    value:
                                        incident.communications_lead?.name ??
                                        'Unassigned',
                                },
                                {
                                    label: 'Next update',
                                    value: formatDateTime(
                                        incident.next_update_due_at,
                                    ),
                                },
                            ],
                            alerts:
                                incident.update_state === 'overdue'
                                    ? [
                                          {
                                              label: 'Update overdue',
                                              tone: 'critical' as const,
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
                title="Declare major incident"
                description="Record technical impact, severity and communication ownership."
                icon={Siren}
                submitLabel="Declare incident"
                processing={form.processing}
                dirty={form.isDirty}
                errors={form.errors}
                review={[
                    { label: 'Title', value: form.data.title },
                    { label: 'Description', value: form.data.description },
                    {
                        label: 'Category',
                        value: majorIncidentLabel(form.data.category),
                    },
                    {
                        label: 'Priority',
                        value: majorIncidentLabel(form.data.priority),
                    },
                    {
                        label: 'Severity',
                        value: form.data.severity.toUpperCase(),
                    },
                    {
                        label: 'Impact summary',
                        value: form.data.impact_summary,
                    },
                    {
                        label: 'Communications lead',
                        value:
                            options.agents.find(
                                (agent) =>
                                    String(agent.id) ===
                                    form.data.communications_lead_user_id,
                            )?.name ?? 'Unassigned',
                    },
                    {
                        label: 'Update cadence',
                        value: form.data.target_update_minutes + ' minutes',
                    },
                ]}
                onSubmit={create}
            >
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Title" className="sm:col-span-2">
                        <Input
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field label="Description" className="sm:col-span-2">
                        <Textarea
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={3}
                        />
                    </Field>
                    <NativeSelect
                        label="Category"
                        value={form.data.category}
                        onChange={(value) => form.setData('category', value)}
                        values={['hardware', 'account', 'network', 'other']}
                    />
                    <NativeSelect
                        label="Priority"
                        value={form.data.priority}
                        onChange={(value) => form.setData('priority', value)}
                        values={['urgent', 'high', 'normal', 'low']}
                    />
                    <NativeSelect
                        label="Severity"
                        value={form.data.severity}
                        onChange={(value) => form.setData('severity', value)}
                        values={['sev1', 'sev2', 'sev3', 'sev4']}
                    />
                    <label className="space-y-1.5 text-sm font-medium">
                        Communications lead
                        <select
                            className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={form.data.communications_lead_user_id}
                            onChange={(event) =>
                                form.setData(
                                    'communications_lead_user_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Assign later</option>
                            {options.agents.map((agent) => (
                                <option key={agent.id} value={agent.id}>
                                    {agent.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Field label="Impact summary" className="sm:col-span-2">
                        <Textarea
                            value={form.data.impact_summary}
                            onChange={(event) =>
                                form.setData(
                                    'impact_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                            required
                        />
                    </Field>
                    <Field label="Update cadence (minutes)">
                        <Input
                            type="number"
                            min={5}
                            max={240}
                            value={form.data.target_update_minutes}
                            onChange={(event) =>
                                form.setData(
                                    'target_update_minutes',
                                    Number(event.target.value),
                                )
                            }
                            required
                        />
                    </Field>
                </div>
            </SpecialistCreateWizard>
        </AppLayout>
    );
}

function Field({
    label,
    className = '',
    children,
}: {
    label: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            {label}
            {children}
        </label>
    );
}

function NativeSelect({
    label,
    value,
    onChange,
    values,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    values: string[];
}) {
    return (
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {values.map((item) => (
                    <option key={item} value={item}>
                        {majorIncidentLabel(item)}
                    </option>
                ))}
            </select>
        </label>
    );
}
