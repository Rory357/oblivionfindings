import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    SpecialistCommandWizard,
    specialistReviewNames,
} from '@/components/it/specialist-create-wizard';
import { SpecialistRecordHeader } from '@/components/it/specialist-record-header';
import { useSpecialistFormDefaults } from '@/components/it/use-specialist-form-defaults';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Clock3,
    ExternalLink,
    Link2,
    Megaphone,
    Radio,
    Siren,
    Users,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import {
    majorIncidentLabel,
    majorIncidentStateVariant,
    type MajorIncidentTicketOption,
} from './index';

interface UserOption {
    id: number;
    name: string;
}
interface SimpleOption {
    id: number;
    name: string;
    status?: string;
    city?: string;
}
interface Update {
    id: number;
    update_kind: string;
    audience: string;
    summary: string;
    service_status: string | null;
    published_at: string | null;
    author: UserOption | null;
}
interface LinkGroups {
    services: SimpleOption[];
    sites: SimpleOption[];
    incidents: MajorIncidentTicketOption[];
    alert: { id: number; reference: string | null; title: string } | null;
}
interface OptionGroups {
    agents: UserOption[];
    services: SimpleOption[];
    sites: SimpleOption[];
    incidents: MajorIncidentTicketOption[];
    alerts: SimpleOption[];
}

interface Props {
    majorIncident: {
        id: number;
        severity: string;
        impact_summary: string | null;
        commander: UserOption | null;
        communications_lead: UserOption | null;
        target_update_minutes: number;
        declared_at: string | null;
        next_update_due_at: string | null;
        update_state: string;
        restoration_summary: string | null;
        restored_at: string | null;
        root_cause_summary: string | null;
        review_summary: string | null;
        reviewed_at: string | null;
    };
    ticket: MajorIncidentTicketOption & {
        lock_version: number;
        description: string | null;
        category: string;
        next_action: string | null;
        sla_state: string;
        resolution_summary: string | null;
        comments_count: number;
        tasks_count: number;
        approvals_count: number;
        attachments_count: number;
        events_count: number;
    };
    updates: Update[];
    links: LinkGroups;
    options: OptionGroups;
    can: { manage: boolean };
}

const nextStates: Record<string, string[]> = {
    declared: ['responding', 'monitoring', 'restored', 'resolved', 'closed'],
    responding: ['monitoring', 'restored', 'resolved', 'closed'],
    monitoring: ['responding', 'restored', 'resolved', 'closed'],
    restored: ['resolved', 'review', 'closed'],
    resolved: ['responding', 'review', 'closed'],
    review: ['closed'],
    closed: ['declared'],
};

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-NZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'Pacific/Auckland',
          }).format(new Date(value))
        : 'Not recorded';

export default function ItMajorIncidentShow(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ItMajorIncidentRecord
            key={`${auth.user.id}:${props.ticket.id}`}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ItMajorIncidentRecord({
    actorId,
    majorIncident,
    ticket,
    updates,
    links,
    options,
    can,
}: Props & { actorId: number }) {
    const [editing, setEditing] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [transitioning, setTransitioning] = useState(false);
    const editDefaults = {
        actor_user_id: actorId,
        expected_version: ticket.lock_version,
        title: ticket.title,
        description: ticket.description ?? '',
        category: ticket.category,
        priority: ticket.priority,
        next_action: ticket.next_action ?? '',
        severity: majorIncident.severity,
        impact_summary: majorIncident.impact_summary ?? '',
        commander_user_id: String(majorIncident.commander?.id ?? ''),
        communications_lead_user_id: String(
            majorIncident.communications_lead?.id ?? '',
        ),
        target_update_minutes: majorIncident.target_update_minutes,
        restoration_summary: majorIncident.restoration_summary ?? '',
        root_cause_summary: majorIncident.root_cause_summary ?? '',
        review_summary: majorIncident.review_summary ?? '',
        service_ids: links.services.map((item) => item.id),
        site_ids: links.sites.map((item) => item.id),
        incident_ids: links.incidents.map((item) => item.id),
        control_room_alert_id: String(links.alert?.id ?? ''),
    };
    const edit = useForm(editDefaults);
    const communicationDefaults = {
        actor_user_id: actorId,
        expected_version: ticket.lock_version,
        update_kind: 'stakeholder_update',
        audience: 'staff',
        summary: '',
        service_status: 'investigating',
    };
    const communication = useForm(communicationDefaults);
    const transitionDefaults = {
        next_action: ticket.next_action ?? '',
        actor_user_id: actorId,
        expected_version: ticket.lock_version,
        workflow_state: nextStates[ticket.workflow_state]?.[0] ?? 'closed',
        reason: '',
        resolution_code: '',
        resolution_summary: '',
    };
    const transition = useForm(transitionDefaults);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'IT & Support', href: '/it' },
        { title: 'Major incidents', href: '/it/major-incidents' },
        {
            title: ticket.reference,
            href: `/it/major-incidents/${majorIncident.id}`,
        },
    ];

    const save = (event: FormEvent) => {
        event.preventDefault();
        edit.patch(`/it/major-incidents/${majorIncident.id}`, {
            onSuccess: () => setEditing(false),
        });
    };
    const publish = (event: FormEvent) => {
        event.preventDefault();
        communication.post(`/it/major-incidents/${majorIncident.id}/updates`, {
            onSuccess: () => {
                setPublishing(false);
                communication.reset();
            },
        });
    };
    const move = (event: FormEvent) => {
        event.preventDefault();
        transition.post(`/it/major-incidents/${majorIncident.id}/transitions`, {
            onSuccess: () => setTransitioning(false),
        });
    };

    useSpecialistFormDefaults(editing, edit, editDefaults);
    useSpecialistFormDefaults(transitioning, transition, transitionDefaults);
    useSpecialistFormDefaults(publishing, communication, communicationDefaults);

    const editReview = (values: typeof editDefaults) => [
        { label: 'Title', value: values.title },
        { label: 'Description', value: values.description },
        { label: 'Category', value: majorIncidentLabel(values.category) },
        { label: 'Priority', value: majorIncidentLabel(values.priority) },
        { label: 'Next action', value: values.next_action },
        { label: 'Severity', value: values.severity.toUpperCase() },
        { label: 'Impact', value: values.impact_summary },
        {
            label: 'Commander',
            value: specialistReviewNames(
                values.commander_user_id
                    ? [Number(values.commander_user_id)]
                    : [],
                options.agents,
            ),
        },
        {
            label: 'Communications lead',
            value: specialistReviewNames(
                values.communications_lead_user_id
                    ? [Number(values.communications_lead_user_id)]
                    : [],
                options.agents,
            ),
        },
        {
            label: 'Update interval',
            value: `${values.target_update_minutes} minutes`,
        },
        { label: 'Restoration evidence', value: values.restoration_summary },
        { label: 'Root cause', value: values.root_cause_summary },
        { label: 'Review', value: values.review_summary },
        {
            label: 'Services',
            value: specialistReviewNames(values.service_ids, [
                ...options.services,
                ...links.services,
            ]),
        },
        {
            label: 'Sites',
            value: specialistReviewNames(values.site_ids, [
                ...options.sites,
                ...links.sites,
            ]),
        },
        {
            label: 'Incidents',
            value: specialistReviewNames(values.incident_ids, [
                ...options.incidents,
                ...links.incidents,
            ]),
        },
        {
            label: 'Control room alert',
            value: specialistReviewNames(
                values.control_room_alert_id
                    ? [Number(values.control_room_alert_id)]
                    : [],
                [...options.alerts, ...(links.alert ? [links.alert] : [])],
            ),
        },
    ];
    const currentRecord = {
        version: ticket.lock_version,
        review: [
            {
                label: 'Current state',
                value: majorIncidentLabel(ticket.workflow_state),
            },
            {
                label: 'Next update due',
                value: formatDateTime(majorIncident.next_update_due_at),
            },
            ...editReview(editDefaults),
            ...updates.slice(0, 3).map((update) => ({
                label: `Recent update · ${formatDateTime(update.published_at)} · ${update.id}`,
                value: `${majorIncidentLabel(update.audience)} · ${majorIncidentLabel(update.update_kind)}\n${update.summary}`,
            })),
        ],
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference} · Major incident`} />
            <ItModuleShell>
                <main className="min-w-0 space-y-5">
                    <SpecialistRecordHeader
                        icon={Siren}
                        backHref="/it/major-incidents"
                        title={ticket.title}
                        reference={ticket.reference}
                        status={majorIncidentLabel(ticket.workflow_state)}
                        statusVariant={
                            majorIncidentStateVariant[ticket.workflow_state] ??
                            'neutral'
                        }
                        subline={`${majorIncident.severity.toUpperCase()} · ${majorIncident.commander?.name ?? 'Commander unassigned'} · ${majorIncident.update_state === 'overdue' ? 'Update overdue' : majorIncident.next_update_due_at ? `Next update ${formatDateTime(majorIncident.next_update_due_at)}` : 'No next update scheduled'}`}
                        ticket={ticket}
                        primary={
                            can.manage
                                ? {
                                      label: 'Publish update',
                                      run: () => setPublishing(true),
                                  }
                                : undefined
                        }
                        actions={
                            can.manage
                                ? [
                                      {
                                          label: 'Edit command',
                                          run: () => setEditing(true),
                                      },
                                  ]
                                : []
                        }
                    />

                    <section
                        className={`rounded-2xl border p-4 ${majorIncident.update_state === 'overdue' ? 'border-status-critical/40 bg-status-critical-bg' : majorIncident.update_state === 'on_time' ? 'border-status-success/30 bg-status-success-bg' : 'border-border bg-muted/30'}`}
                    >
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <div className="flex items-start gap-3">
                                <Clock3
                                    className={`mt-0.5 h-5 w-5 ${majorIncident.update_state === 'overdue' ? 'text-status-critical' : majorIncident.update_state === 'on_time' ? 'text-status-success' : 'text-muted-foreground'}`}
                                    aria-hidden="true"
                                />
                                <div>
                                    <h2 className="font-semibold">
                                        {majorIncident.update_state ===
                                        'overdue'
                                            ? 'Update overdue'
                                            : majorIncident.update_state ===
                                                'on_time'
                                              ? 'Next update scheduled'
                                              : majorIncident.update_state ===
                                                  'not_required'
                                                ? 'Regular updates are no longer required'
                                                : 'No next update scheduled'}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {majorIncident.next_update_due_at
                                            ? 'Next audience update: '
                                            : 'Update interval: '}
                                        {majorIncident.next_update_due_at
                                            ? `${formatDateTime(majorIncident.next_update_due_at)} · every `
                                            : ''}
                                        {majorIncident.target_update_minutes}{' '}
                                        minutes
                                    </p>
                                </div>
                            </div>
                            {can.manage ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setPublishing(true)}
                                >
                                    Publish now
                                </Button>
                            ) : null}
                        </div>
                    </section>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(20rem,.75fr)]">
                        <div className="space-y-6">
                            <section className="rounded-2xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Users
                                        className="h-5 w-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="font-semibold">
                                        Command accountability
                                    </h2>
                                </div>
                                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                    <Fact
                                        label="Incident commander"
                                        value={
                                            majorIncident.commander?.name ??
                                            'Unassigned'
                                        }
                                    />
                                    <Fact
                                        label="Communications lead"
                                        value={
                                            majorIncident.communications_lead
                                                ?.name ?? 'Unassigned'
                                        }
                                    />
                                    <Fact
                                        label="Declared"
                                        value={formatDateTime(
                                            majorIncident.declared_at,
                                        )}
                                    />
                                    <Fact
                                        label="Next action"
                                        value={
                                            ticket.next_action || 'Not recorded'
                                        }
                                    />
                                </div>
                                <div className="mt-4 rounded-xl bg-muted/40 p-4">
                                    <h3 className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        Current impact
                                    </h3>
                                    <p className="mt-2 text-sm">
                                        {majorIncident.impact_summary ||
                                            'Impact has not been recorded.'}
                                    </p>
                                </div>
                            </section>

                            <section className="rounded-2xl border border-border bg-card">
                                <div className="flex items-center justify-between border-b border-border px-5 py-4">
                                    <div className="flex items-center gap-2">
                                        <Megaphone
                                            className="h-5 w-5 text-primary"
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <h2 className="font-semibold">
                                                Live communications
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                Internal command notes remain
                                                separate from staff and public
                                                updates.
                                            </p>
                                        </div>
                                    </div>
                                    {can.manage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setPublishing(true)}
                                        >
                                            New update
                                        </Button>
                                    ) : null}
                                </div>
                                {updates.length === 0 ? (
                                    <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                                        No command or stakeholder updates
                                        published.
                                    </div>
                                ) : (
                                    <ol className="divide-y divide-border">
                                        {updates.map((update) => (
                                            <li key={update.id} className="p-5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <StatusBadge
                                                        variant={
                                                            update.audience ===
                                                            'internal'
                                                                ? 'neutral'
                                                                : 'info'
                                                        }
                                                    >
                                                        {majorIncidentLabel(
                                                            update.audience,
                                                        )}
                                                    </StatusBadge>
                                                    <StatusBadge variant="neutral">
                                                        {majorIncidentLabel(
                                                            update.update_kind,
                                                        )}
                                                    </StatusBadge>
                                                    {update.service_status ? (
                                                        <StatusBadge variant="warning">
                                                            {majorIncidentLabel(
                                                                update.service_status,
                                                            )}
                                                        </StatusBadge>
                                                    ) : null}
                                                    <span className="ml-auto text-xs text-muted-foreground">
                                                        {formatDateTime(
                                                            update.published_at,
                                                        )}
                                                    </span>
                                                </div>
                                                <p className="mt-3 text-sm whitespace-pre-wrap">
                                                    {update.summary}
                                                </p>
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    Published by{' '}
                                                    {update.author?.name ??
                                                        'System'}
                                                </p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>

                            <section className="rounded-2xl border border-border bg-card p-5">
                                <h2 className="font-semibold">
                                    Restoration and review evidence
                                </h2>
                                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                                    <Evidence
                                        title="Restoration"
                                        body={majorIncident.restoration_summary}
                                        stamp={majorIncident.restored_at}
                                    />
                                    <Evidence
                                        title="Root cause"
                                        body={majorIncident.root_cause_summary}
                                    />
                                    <Evidence
                                        title="Post-incident review"
                                        body={majorIncident.review_summary}
                                        stamp={majorIncident.reviewed_at}
                                    />
                                </div>
                            </section>
                        </div>

                        <aside className="space-y-6">
                            <section className="rounded-2xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Link2
                                        className="h-5 w-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="font-semibold">
                                        Operational impact
                                    </h2>
                                </div>
                                <LinkList
                                    title="Services"
                                    values={links.services.map(
                                        (item) => item.name,
                                    )}
                                />
                                <LinkList
                                    title="Sites"
                                    values={links.sites.map(
                                        (item) => item.name,
                                    )}
                                />
                                <LinkList
                                    title="Related incidents"
                                    values={links.incidents.map(
                                        (item) =>
                                            `${item.reference} · ${item.title}`,
                                    )}
                                />
                                <LinkList
                                    title="Control Room alert"
                                    values={
                                        links.alert
                                            ? [
                                                  `${links.alert.reference ?? `Alert ${links.alert.id}`} · ${links.alert.title}`,
                                              ]
                                            : []
                                    }
                                />
                            </section>

                            <section className="rounded-2xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Radio
                                        className="h-5 w-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="font-semibold">
                                        Shared work record
                                    </h2>
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Comments, tasks, evidence, approvals,
                                    attachments, and audit events stay on the
                                    canonical IT ticket.
                                </p>
                                <div className="mt-4 grid grid-cols-2 gap-2">
                                    <Count
                                        label="Comments"
                                        value={ticket.comments_count}
                                    />
                                    <Count
                                        label="Tasks"
                                        value={ticket.tasks_count}
                                    />
                                    <Count
                                        label="Attachments"
                                        value={ticket.attachments_count}
                                    />
                                    <Count
                                        label="Events"
                                        value={ticket.events_count}
                                    />
                                </div>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="mt-4 min-h-11 w-full"
                                >
                                    <Link href={ticket.href}>
                                        Open shared work{' '}
                                        <ExternalLink
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                            </section>

                            {can.manage &&
                            (nextStates[ticket.workflow_state]?.length ?? 0) >
                                0 ? (
                                <Button
                                    className="min-h-11 w-full"
                                    onClick={() => setTransitioning(true)}
                                >
                                    Move command state
                                </Button>
                            ) : null}
                        </aside>
                    </div>
                </main>
            </ItModuleShell>

            <SpecialistCommandWizard
                open={publishing}
                onClose={() => setPublishing(false)}
                onDiscard={() => communication.resetAndClearErrors()}
                title="Publish major incident update"
                description="Check the message and audience before publishing this update."
                icon={Megaphone}
                submitLabel="Publish update"
                allowed={can.manage}
                processing={communication.processing}
                dirty={communication.isDirty}
                errors={communication.errors}
                expectedVersion={communication.data.expected_version}
                current={currentRecord}
                onVersionReviewed={(version) => {
                    communication.setData('expected_version', version);
                    communication.clearErrors('expected_version');
                }}
                review={[
                    { label: 'Record', value: ticket.reference },
                    {
                        label: 'Update type',
                        value: majorIncidentLabel(
                            communication.data.update_kind,
                        ),
                    },
                    {
                        label: 'Audience',
                        value: majorIncidentLabel(communication.data.audience),
                    },
                    {
                        label: 'Service status',
                        value: majorIncidentLabel(
                            communication.data.service_status,
                        ),
                    },
                    { label: 'Message', value: communication.data.summary },
                ]}
                onSubmit={publish}
            >
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <NativeSelect
                        label="Update type"
                        value={communication.data.update_kind}
                        onChange={(value) =>
                            communication.setData('update_kind', value)
                        }
                        values={[
                            'command_note',
                            'stakeholder_update',
                            'service_restored',
                            'resolution',
                            'review',
                        ]}
                    />
                    <NativeSelect
                        label="Audience"
                        value={communication.data.audience}
                        onChange={(value) =>
                            communication.setData('audience', value)
                        }
                        values={['internal', 'staff', 'clients', 'public']}
                    />
                    <NativeSelect
                        label="Service status"
                        value={communication.data.service_status}
                        onChange={(value) =>
                            communication.setData('service_status', value)
                        }
                        values={[
                            'investigating',
                            'identified',
                            'monitoring',
                            'major_outage',
                            'degraded',
                            'operational',
                        ]}
                    />
                    <Field label="Update" className="sm:col-span-2">
                        <Textarea
                            value={communication.data.summary}
                            onChange={(event) =>
                                communication.setData(
                                    'summary',
                                    event.target.value,
                                )
                            }
                            rows={5}
                            required
                        />
                    </Field>
                </div>
            </SpecialistCommandWizard>

            <SpecialistCommandWizard
                open={transitioning}
                onClose={() => setTransitioning(false)}
                onDiscard={() => transition.resetAndClearErrors()}
                title="Update major incident state"
                description="Review the next state and supporting evidence before updating the record."
                icon={Siren}
                submitLabel="Update state"
                allowed={can.manage}
                processing={transition.processing}
                dirty={transition.isDirty}
                errors={transition.errors}
                expectedVersion={transition.data.expected_version}
                current={currentRecord}
                onVersionReviewed={(version) => {
                    transition.setData('expected_version', version);
                    transition.clearErrors('expected_version');
                }}
                review={[
                    { label: 'Record', value: ticket.reference },
                    {
                        label: 'From',
                        value: majorIncidentLabel(ticket.workflow_state),
                    },
                    {
                        label: 'To',
                        value: majorIncidentLabel(
                            transition.data.workflow_state ?? '',
                        ),
                    },
                    { label: 'Reason', value: transition.data.reason },
                    {
                        label: 'Next action',
                        value: transition.data.next_action,
                    },
                    ...(transition.data.workflow_state === 'resolved'
                        ? [
                              {
                                  label: 'Resolution code',
                                  value: transition.data.resolution_code,
                              },
                              {
                                  label: 'Resolution summary',
                                  value: transition.data.resolution_summary,
                              },
                          ]
                        : []),
                ]}
                onSubmit={move}
            >
                <div className="mt-5 space-y-4">
                    <NativeSelect
                        label="Next state"
                        value={transition.data.workflow_state}
                        onChange={(value) =>
                            transition.setData('workflow_state', value)
                        }
                        values={nextStates[ticket.workflow_state] ?? []}
                    />
                    <Field label="Next action">
                        <Textarea
                            value={transition.data.next_action}
                            onChange={(event) =>
                                transition.setData(
                                    'next_action',
                                    event.target.value,
                                )
                            }
                            rows={2}
                            maxLength={2000}
                        />
                    </Field>
                    <Field label="Reason">
                        <Textarea
                            value={transition.data.reason}
                            onChange={(event) =>
                                transition.setData('reason', event.target.value)
                            }
                            rows={3}
                            required
                        />
                    </Field>
                    {transition.data.workflow_state === 'resolved' ? (
                        <>
                            <Field label="Resolution code">
                                <Input
                                    value={transition.data.resolution_code}
                                    onChange={(event) =>
                                        transition.setData(
                                            'resolution_code',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Resolution summary">
                                <Textarea
                                    value={transition.data.resolution_summary}
                                    onChange={(event) =>
                                        transition.setData(
                                            'resolution_summary',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                        </>
                    ) : null}
                </div>
            </SpecialistCommandWizard>

            <SpecialistCommandWizard
                open={editing}
                onClose={() => setEditing(false)}
                onDiscard={() => edit.resetAndClearErrors()}
                title="Edit incident command"
                description="Update the record and linked work, then review the proposed changes."
                icon={Siren}
                submitLabel="Save changes"
                allowed={can.manage}
                processing={edit.processing}
                dirty={edit.isDirty}
                errors={edit.errors}
                expectedVersion={edit.data.expected_version}
                current={currentRecord}
                onVersionReviewed={(version) => {
                    edit.setData('expected_version', version);
                    edit.clearErrors('expected_version');
                }}
                review={editReview(edit.data)}
                onSubmit={save}
            >
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Description" className="sm:col-span-2">
                        <Textarea
                            value={edit.data.description}
                            maxLength={10000}
                            rows={4}
                            onChange={(event) =>
                                edit.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Category">
                        <select
                            className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={edit.data.category}
                            onChange={(event) =>
                                edit.setData('category', event.target.value)
                            }
                        >
                            {['hardware', 'account', 'network', 'other'].map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {majorIncidentLabel(value)}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Priority">
                        <select
                            className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={edit.data.priority}
                            onChange={(event) =>
                                edit.setData('priority', event.target.value)
                            }
                        >
                            {['low', 'normal', 'high', 'urgent'].map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {majorIncidentLabel(value)}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Title" className="sm:col-span-2">
                        <Input
                            value={edit.data.title}
                            onChange={(event) =>
                                edit.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <NativeSelect
                        label="Severity"
                        value={edit.data.severity}
                        onChange={(value) => edit.setData('severity', value)}
                        values={['sev1', 'sev2', 'sev3', 'sev4']}
                    />
                    <Field label="Update cadence (minutes)">
                        <Input
                            type="number"
                            min={5}
                            max={240}
                            value={edit.data.target_update_minutes}
                            onChange={(event) =>
                                edit.setData(
                                    'target_update_minutes',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </Field>
                    <AgentSelect
                        label="Incident commander"
                        value={edit.data.commander_user_id}
                        agents={options.agents}
                        onChange={(value) =>
                            edit.setData('commander_user_id', value)
                        }
                    />
                    <AgentSelect
                        label="Communications lead"
                        value={edit.data.communications_lead_user_id}
                        agents={options.agents}
                        onChange={(value) =>
                            edit.setData('communications_lead_user_id', value)
                        }
                    />
                    <Field label="Current impact" className="sm:col-span-2">
                        <Textarea
                            value={edit.data.impact_summary}
                            onChange={(event) =>
                                edit.setData(
                                    'impact_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                    </Field>
                    <Field label="Next action" className="sm:col-span-2">
                        <Textarea
                            value={edit.data.next_action}
                            onChange={(event) =>
                                edit.setData('next_action', event.target.value)
                            }
                            rows={2}
                        />
                    </Field>
                    <Field
                        label="Restoration evidence"
                        className="sm:col-span-2"
                    >
                        <Textarea
                            value={edit.data.restoration_summary}
                            onChange={(event) =>
                                edit.setData(
                                    'restoration_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                    </Field>
                    <Field label="Root-cause summary" className="sm:col-span-2">
                        <Textarea
                            value={edit.data.root_cause_summary}
                            onChange={(event) =>
                                edit.setData(
                                    'root_cause_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                    </Field>
                    <Field
                        label="Post-incident review"
                        className="sm:col-span-2"
                    >
                        <Textarea
                            value={edit.data.review_summary}
                            onChange={(event) =>
                                edit.setData(
                                    'review_summary',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                    </Field>
                    <MultiSelect
                        label="Affected services"
                        options={options.services}
                        selected={edit.data.service_ids}
                        onChange={(value) => edit.setData('service_ids', value)}
                    />
                    <MultiSelect
                        label="Affected sites"
                        options={options.sites}
                        selected={edit.data.site_ids}
                        onChange={(value) => edit.setData('site_ids', value)}
                    />
                    <MultiSelect
                        label="Related incidents"
                        options={options.incidents.map((item) => ({
                            id: item.id,
                            name: `${item.reference} · ${item.title}`,
                        }))}
                        selected={edit.data.incident_ids}
                        onChange={(value) =>
                            edit.setData('incident_ids', value)
                        }
                    />
                    <label className="space-y-1.5 text-sm font-medium">
                        Canonical Control Room alert
                        <select
                            className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={edit.data.control_room_alert_id}
                            onChange={(event) =>
                                edit.setData(
                                    'control_room_alert_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">No linked alert</option>
                            {options.alerts.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </SpecialistCommandWizard>
        </AppLayout>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border/70 bg-muted/30 p-3">
            <span className="block text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="mt-1 block text-sm font-semibold">{value}</span>
        </div>
    );
}
function Evidence({
    title,
    body,
    stamp,
}: {
    title: string;
    body: string | null;
    stamp?: string | null;
}) {
    return (
        <div className="rounded-xl border border-border/70 p-3">
            <h3 className="text-sm font-semibold">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">
                {body || 'Evidence not yet recorded.'}
            </p>
            {stamp ? (
                <p className="mt-2 text-xs text-muted-foreground">
                    Recorded {formatDateTime(stamp)}
                </p>
            ) : null}
        </div>
    );
}
function LinkList({ title, values }: { title: string; values: string[] }) {
    return (
        <div className="mt-4">
            <h3 className="text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            {values.length ? (
                <ul className="mt-1.5 space-y-1">
                    {values.map((value) => (
                        <li
                            key={value}
                            className="rounded-lg bg-muted/40 px-3 py-2 text-sm"
                        >
                            {value}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-1 text-xs text-muted-foreground">
                    None linked
                </p>
            )}
        </div>
    );
}
function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg bg-muted/40 p-3 text-center">
            <span className="block text-lg font-bold">{value}</span>
            <span className="text-xs text-muted-foreground">{label}</span>
        </div>
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
function AgentSelect({
    label,
    value,
    agents,
    onChange,
}: {
    label: string;
    value: string;
    agents: UserOption[];
    onChange: (value: string) => void;
}) {
    return (
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Unassigned</option>
                {agents.map((agent) => (
                    <option key={agent.id} value={agent.id}>
                        {agent.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
function MultiSelect({
    label,
    options,
    selected,
    onChange,
}: {
    label: string;
    options: SimpleOption[];
    selected: number[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-medium">{label}</legend>
            <div className="mt-1 max-h-36 space-y-1 overflow-y-auto">
                {options.length ? (
                    options.map((option) => (
                        <label
                            key={option.id}
                            className="flex min-h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted/50"
                        >
                            <input
                                type="checkbox"
                                checked={selected.includes(option.id)}
                                onChange={(event) =>
                                    onChange(
                                        event.target.checked
                                            ? [...selected, option.id]
                                            : selected.filter(
                                                  (id) => id !== option.id,
                                              ),
                                    )
                                }
                            />
                            {option.name}
                        </label>
                    ))
                ) : (
                    <p className="px-2 text-xs text-muted-foreground">
                        No options available
                    </p>
                )}
            </div>
        </fieldset>
    );
}
