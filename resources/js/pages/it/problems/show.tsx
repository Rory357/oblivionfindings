import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    SpecialistCommandWizard,
    specialistReviewNames,
} from '@/components/it/specialist-create-wizard';
import { SpecialistRecordHeader } from '@/components/it/specialist-record-header';
import { useSpecialistFormDefaults } from '@/components/it/use-specialist-form-defaults';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, FileClock, Link2, Wrench } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { problemLabel, problemStateVariant } from './index';

interface TicketOption {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    href: string;
}

interface Props {
    problem: {
        id: number;
        impact_summary: string | null;
        root_cause: string | null;
        workaround: string | null;
        corrective_action: string | null;
        known_error_at: string | null;
    };
    ticket: TicketOption & {
        lock_version: number;
        description: string | null;
        category: string;
        next_action: string | null;
        sla_state: string;
        first_response_due_at: string | null;
        resolution_due_at: string | null;
        comments_count: number;
        tasks_count: number;
        approvals_count: number;
        attachments_count: number;
        events_count: number;
    };
    incidents: TicketOption[];
    permanentFixChange: TicketOption | null;
    incidentOptions: TicketOption[];
    changeOptions: TicketOption[];
    can: { manage: boolean };
}

const nextStates: Record<string, string[]> = {
    submitted: ['investigating', 'closed'],
    investigating: ['waiting', 'known_error', 'resolved', 'closed'],
    waiting: ['investigating', 'resolved', 'closed'],
    known_error: ['investigating', 'resolved', 'closed'],
    resolved: ['closed', 'submitted'],
    closed: ['submitted'],
};

export default function ItProblemShow(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ItProblemRecord
            key={`${auth.user.id}:${props.ticket.id}`}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ItProblemRecord({
    actorId,
    problem,
    ticket,
    incidents,
    permanentFixChange,
    incidentOptions,
    changeOptions,
    can,
}: Props & { actorId: number }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'IT & Support', href: '/it' },
        { title: 'Problems', href: '/it/problems' },
        { title: ticket.reference, href: `/it/problems/${problem.id}` },
    ];
    const [editing, setEditing] = useState(false);
    const formDefaults = {
        actor_user_id: actorId,
        expected_version: ticket.lock_version,
        title: ticket.title,
        description: ticket.description ?? '',
        category: ticket.category,
        priority: ticket.priority,
        impact_summary: problem.impact_summary ?? '',
        root_cause: problem.root_cause ?? '',
        workaround: problem.workaround ?? '',
        corrective_action: problem.corrective_action ?? '',
        next_action: ticket.next_action ?? '',
        incident_ids: incidents.map((item) => item.id),
        permanent_fix_change_id:
            permanentFixChange?.id ?? (null as number | null),
    };
    const form = useForm(formDefaults);
    const [transitioning, setTransitioning] = useState<string | null>(null);
    const transitionFormDefaults = {
        next_action: ticket.next_action ?? '',
        waiting_party: '',
        actor_user_id: actorId,
        expected_version: ticket.lock_version,
        reason: '',
        resolution_code: '',
        resolution_summary: '',
    };
    const transitionForm = useForm(transitionFormDefaults);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/it/problems/${problem.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };
    const transition = (event: FormEvent) => {
        event.preventDefault();
        if (!transitioning) return;
        transitionForm.transform((data) => ({
            ...data,
            workflow_state: transitioning,
        }));
        transitionForm.post(`/it/problems/${problem.id}/transitions`, {
            preserveScroll: true,
            onSuccess: () => {
                setTransitioning(null);
                transitionForm.reset();
            },
        });
    };
    const toggleIncident = (id: number) => {
        const selected = form.data.incident_ids.includes(id);
        form.setData(
            'incident_ids',
            selected
                ? form.data.incident_ids.filter((candidate) => candidate !== id)
                : [...form.data.incident_ids, id],
        );
    };

    useSpecialistFormDefaults(editing, form, formDefaults);
    useSpecialistFormDefaults(
        transitioning !== null,
        transitionForm,
        transitionFormDefaults,
    );

    const editReview = (values: typeof formDefaults) => [
        { label: 'Title', value: values.title },
        { label: 'Description', value: values.description },
        { label: 'Category', value: problemLabel(values.category) },
        { label: 'Priority', value: problemLabel(values.priority) },
        { label: 'Next action', value: values.next_action },
        { label: 'Impact', value: values.impact_summary },
        { label: 'Root cause', value: values.root_cause },
        { label: 'Safe workaround', value: values.workaround },
        { label: 'Corrective action', value: values.corrective_action },
        {
            label: 'Affected incidents',
            value: specialistReviewNames(values.incident_ids, [
                ...incidentOptions,
                ...incidents,
            ]),
        },
        {
            label: 'Permanent-fix change',
            value: specialistReviewNames(
                values.permanent_fix_change_id === null
                    ? []
                    : [values.permanent_fix_change_id],
                [
                    ...changeOptions,
                    ...(permanentFixChange ? [permanentFixChange] : []),
                ],
            ),
        },
    ];
    const currentRecord = {
        version: ticket.lock_version,
        review: [
            {
                label: 'Current state',
                value: problemLabel(ticket.workflow_state),
            },
            ...editReview(formDefaults),
        ],
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference} — ${ticket.title}`} />
            <ItModuleShell>
                <main className="min-w-0 space-y-5">
                    <SpecialistRecordHeader
                        icon={Wrench}
                        backHref="/it/problems"
                        title={ticket.title}
                        reference={ticket.reference}
                        status={problemLabel(ticket.workflow_state)}
                        statusVariant={
                            problemStateVariant[ticket.workflow_state] ??
                            'neutral'
                        }
                        subline={`${problemLabel(ticket.priority)} priority · ${ticket.next_action || 'Investigation and permanent fix'}`}
                        ticket={ticket}
                        primary={
                            can.manage &&
                            nextStates[ticket.workflow_state]?.length
                                ? {
                                      label: 'Update state',
                                      run: () =>
                                          setTransitioning(
                                              nextStates[
                                                  ticket.workflow_state
                                              ][0],
                                          ),
                                  }
                                : undefined
                        }
                        actions={
                            can.manage
                                ? [
                                      {
                                          label: 'Edit investigation',
                                          run: () => setEditing(true),
                                      },
                                      ...(
                                          nextStates[ticket.workflow_state] ??
                                          []
                                      )
                                          .slice(1)
                                          .map((state) => ({
                                              label: `Move to ${problemLabel(state)}`,
                                              run: () =>
                                                  setTransitioning(state),
                                          })),
                                  ]
                                : []
                        }
                    />

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <section
                            className="space-y-5 rounded-2xl border border-border bg-card p-5"
                            aria-label="Problem investigation"
                        >
                            <div>
                                <h2 className="font-semibold">Investigation</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Root cause, safe response and the permanent
                                    correction.
                                </p>
                            </div>
                            {[
                                {
                                    label: 'Description',
                                    value: ticket.description,
                                },
                                {
                                    label: 'Impact',
                                    value: problem.impact_summary,
                                },
                                {
                                    label: 'Root cause',
                                    value: problem.root_cause,
                                },
                                {
                                    label: 'Safe workaround',
                                    value: problem.workaround,
                                },
                                {
                                    label: 'Corrective action',
                                    value: problem.corrective_action,
                                },
                            ].map((item) => (
                                <article
                                    key={item.label}
                                    className="rounded-xl border border-border bg-muted/20 p-4"
                                >
                                    <h3 className="text-sm font-semibold">
                                        {item.label}
                                    </h3>
                                    <p className="mt-2 text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                        {item.value || 'Not recorded yet.'}
                                    </p>
                                </article>
                            ))}
                        </section>

                        <aside className="space-y-5">
                            <section className="rounded-2xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <FileClock
                                        className="h-4 w-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="font-semibold">
                                        Shared work record
                                    </h2>
                                </div>
                                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <Metric
                                        label="Conversation"
                                        value={ticket.comments_count}
                                    />
                                    <Metric
                                        label="Tasks"
                                        value={ticket.tasks_count}
                                    />
                                    <Metric
                                        label="Approvals"
                                        value={ticket.approvals_count}
                                    />
                                    <Metric
                                        label="Attachments"
                                        value={ticket.attachments_count}
                                    />
                                    <Metric
                                        label="Timeline events"
                                        value={ticket.events_count}
                                    />
                                    <Metric
                                        label="SLA"
                                        value={problemLabel(ticket.sla_state)}
                                    />
                                </dl>
                            </section>
                            <section className="rounded-2xl border border-border bg-card p-5">
                                <div className="flex items-center gap-2">
                                    <Link2
                                        className="h-4 w-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="font-semibold">
                                        Linked work
                                    </h2>
                                </div>
                                <div className="mt-4 space-y-2">
                                    {incidents.map((incident) => (
                                        <TicketLink
                                            key={incident.id}
                                            item={incident}
                                        />
                                    ))}
                                    {incidents.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No affected incidents linked yet.
                                        </p>
                                    ) : null}
                                </div>
                                <div className="mt-5 border-t border-border pt-4">
                                    <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        Permanent fix
                                    </p>
                                    {permanentFixChange ? (
                                        <div className="mt-2">
                                            <TicketLink
                                                item={permanentFixChange}
                                            />
                                        </div>
                                    ) : (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No change linked yet.
                                        </p>
                                    )}
                                </div>
                            </section>
                        </aside>
                    </div>
                </main>
            </ItModuleShell>

            <SpecialistCommandWizard
                open={editing}
                onClose={() => setEditing(false)}
                onDiscard={() => form.resetAndClearErrors()}
                title="Edit problem investigation"
                description="Record the root cause, workaround and permanent correction, then review the changes."
                icon={Wrench}
                submitLabel="Save investigation"
                allowed={can.manage}
                processing={form.processing}
                dirty={form.isDirty}
                errors={form.errors}
                expectedVersion={form.data.expected_version}
                current={currentRecord}
                onVersionReviewed={(version) => {
                    form.setData('expected_version', version);
                    form.clearErrors('expected_version');
                }}
                review={editReview(form.data)}
                onSubmit={save}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Description">
                        <Textarea
                            value={form.data.description}
                            maxLength={10000}
                            rows={4}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Category">
                        <select
                            className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData('category', event.target.value)
                            }
                        >
                            {['hardware', 'account', 'network', 'other'].map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {problemLabel(value)}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Priority">
                        <select
                            className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={form.data.priority}
                            onChange={(event) =>
                                form.setData('priority', event.target.value)
                            }
                        >
                            {['low', 'normal', 'high', 'urgent'].map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {problemLabel(value)}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Title">
                        <Input
                            required
                            maxLength={255}
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            disabled={!can.manage}
                        />
                    </Field>
                    <Field label="Next action">
                        <Input
                            value={form.data.next_action}
                            onChange={(event) =>
                                form.setData('next_action', event.target.value)
                            }
                            disabled={!can.manage}
                            placeholder="State the next owned action"
                        />
                    </Field>
                </div>
                <Field label="Impact summary">
                    <Textarea
                        value={form.data.impact_summary}
                        onChange={(event) =>
                            form.setData('impact_summary', event.target.value)
                        }
                        disabled={!can.manage}
                        rows={3}
                    />
                </Field>
                <Field label="Root cause">
                    <Textarea
                        value={form.data.root_cause}
                        onChange={(event) =>
                            form.setData('root_cause', event.target.value)
                        }
                        disabled={!can.manage}
                        rows={5}
                        placeholder="What underlying condition creates the incidents?"
                    />
                </Field>
                <Field label="Safe workaround">
                    <Textarea
                        value={form.data.workaround}
                        onChange={(event) =>
                            form.setData('workaround', event.target.value)
                        }
                        disabled={!can.manage}
                        rows={5}
                        placeholder="What can responders do safely before the permanent fix?"
                    />
                </Field>
                <Field label="Corrective action">
                    <Textarea
                        value={form.data.corrective_action}
                        onChange={(event) =>
                            form.setData(
                                'corrective_action',
                                event.target.value,
                            )
                        }
                        disabled={!can.manage}
                        rows={5}
                        placeholder="What permanent correction removes the root cause?"
                    />
                </Field>

                {can.manage ? (
                    <section className="border-t border-border pt-5">
                        <h3 className="font-semibold">Affected incidents</h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Link only incidents that share this problem’s cause
                            or workaround.
                        </p>
                        <div className="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                            {incidentOptions.map((incident) => (
                                <label
                                    key={incident.id}
                                    className="frontline-focus flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border border-border p-3 hover:bg-muted/50"
                                >
                                    <input
                                        type="checkbox"
                                        className="mt-1 h-4 w-4"
                                        checked={form.data.incident_ids.includes(
                                            incident.id,
                                        )}
                                        onChange={() =>
                                            toggleIncident(incident.id)
                                        }
                                    />
                                    <span className="min-w-0">
                                        <span className="block font-mono text-xs font-bold text-primary">
                                            {incident.reference}
                                        </span>
                                        <span className="block truncate text-sm">
                                            {incident.title}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <Field label="Permanent-fix change">
                            <select
                                className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={form.data.permanent_fix_change_id ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'permanent_fix_change_id',
                                        event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    )
                                }
                            >
                                <option value="">No linked change yet</option>
                                {changeOptions.map((change) => (
                                    <option key={change.id} value={change.id}>
                                        {change.reference} — {change.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </section>
                ) : null}
            </SpecialistCommandWizard>

            <SpecialistCommandWizard
                open={transitioning !== null}
                onClose={() => setTransitioning(null)}
                onDiscard={() => transitionForm.resetAndClearErrors()}
                title="Update problem state"
                description="Review the next state and supporting evidence before updating the record."
                icon={Wrench}
                submitLabel="Update state"
                allowed={can.manage}
                processing={transitionForm.processing}
                dirty={transitionForm.isDirty}
                errors={transitionForm.errors}
                expectedVersion={transitionForm.data.expected_version}
                current={currentRecord}
                onVersionReviewed={(version) => {
                    transitionForm.setData('expected_version', version);
                    transitionForm.clearErrors('expected_version');
                }}
                review={[
                    { label: 'Record', value: ticket.reference },
                    {
                        label: 'From',
                        value: problemLabel(ticket.workflow_state),
                    },
                    { label: 'To', value: problemLabel(transitioning ?? '') },
                    { label: 'Reason', value: transitionForm.data.reason },
                    {
                        label: 'Next action',
                        value: transitionForm.data.next_action,
                    },
                    ...(transitioning === 'waiting'
                        ? [
                              {
                                  label: 'Waiting for',
                                  value: problemLabel(
                                      transitionForm.data.waiting_party,
                                  ),
                              },
                          ]
                        : []),
                    ...(transitioning === 'resolved'
                        ? [
                              {
                                  label: 'Resolution code',
                                  value: transitionForm.data.resolution_code,
                              },
                              {
                                  label: 'Resolution summary',
                                  value: transitionForm.data.resolution_summary,
                              },
                          ]
                        : []),
                ]}
                onSubmit={transition}
            >
                <div className="mt-5 space-y-4">
                    <Field label="Next state">
                        <select
                            className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={transitioning ?? ''}
                            onChange={(event) =>
                                setTransitioning(event.target.value)
                            }
                            required
                        >
                            <option value="" disabled>
                                Choose the next state
                            </option>
                            {(nextStates[ticket.workflow_state] ?? []).map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {problemLabel(value)}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    {transitioning === 'waiting' && (
                        <Field label="Waiting for">
                            <select
                                className="frontline-focus min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={transitionForm.data.waiting_party}
                                onChange={(event) =>
                                    transitionForm.setData(
                                        'waiting_party',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">
                                    Choose who needs to act
                                </option>
                                {[
                                    'requester',
                                    'vendor',
                                    'approver',
                                    'team',
                                    'change',
                                    'other',
                                ].map((value) => (
                                    <option key={value} value={value}>
                                        {problemLabel(value)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field label="Next action">
                        <Textarea
                            value={transitionForm.data.next_action}
                            onChange={(event) =>
                                transitionForm.setData(
                                    'next_action',
                                    event.target.value,
                                )
                            }
                            rows={2}
                            maxLength={2000}
                            required={transitioning === 'waiting'}
                        />
                    </Field>
                    <Field label="Reason">
                        <Textarea
                            value={transitionForm.data.reason}
                            onChange={(event) =>
                                transitionForm.setData(
                                    'reason',
                                    event.target.value,
                                )
                            }
                            required
                            rows={3}
                        />
                    </Field>
                    {transitioning === 'resolved' ? (
                        <>
                            <Field label="Resolution code">
                                <Input
                                    value={transitionForm.data.resolution_code}
                                    onChange={(event) =>
                                        transitionForm.setData(
                                            'resolution_code',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    placeholder="permanent_fix"
                                />
                            </Field>
                            <Field label="Resolution summary">
                                <Textarea
                                    value={
                                        transitionForm.data.resolution_summary
                                    }
                                    onChange={(event) =>
                                        transitionForm.setData(
                                            'resolution_summary',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    rows={4}
                                />
                            </Field>
                        </>
                    ) : null}
                </div>
            </SpecialistCommandWizard>
        </AppLayout>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block space-y-1.5 text-sm font-medium">
            <span>{label}</span>
            {children}
        </label>
    );
}

function Metric({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-xl bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-semibold">{value}</dd>
        </div>
    );
}

function TicketLink({ item }: { item: TicketOption }) {
    return (
        <Link
            href={item.href}
            className="frontline-focus flex min-h-11 items-center gap-3 rounded-xl border border-border p-3 hover:bg-muted/50"
        >
            <Wrench
                className="h-4 w-4 flex-none text-primary"
                aria-hidden="true"
            />
            <span className="min-w-0 flex-1">
                <span className="block font-mono text-xs font-bold text-primary">
                    {item.reference}
                </span>
                <span className="block truncate text-sm font-medium">
                    {item.title}
                </span>
            </span>
            <ExternalLink
                className="h-3.5 w-3.5 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>
    );
}
