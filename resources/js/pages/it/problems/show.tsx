import { ItModuleShell } from '@/components/it/it-module-shell';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ExternalLink,
    FileClock,
    Link2,
    Save,
    Wrench,
} from 'lucide-react';
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
    investigating: ['known_error', 'resolved', 'closed'],
    waiting: ['investigating', 'resolved', 'closed'],
    known_error: ['investigating', 'resolved', 'closed'],
    resolved: ['closed'],
};

export default function ItProblemShow({
    problem,
    ticket,
    incidents,
    permanentFixChange,
    incidentOptions,
    changeOptions,
    can,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Support', href: '/it' },
        { title: 'Problems', href: '/it/problems' },
        { title: ticket.reference, href: `/it/problems/${problem.id}` },
    ];
    const form = useForm({
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
    });
    const [transitioning, setTransitioning] = useState<string | null>(null);
    const transitionForm = useForm({
        reason: '',
        resolution_code: '',
        resolution_summary: '',
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/it/problems/${problem.id}`, { preserveScroll: true });
    };
    const transition = (event: FormEvent) => {
        event.preventDefault();
        if (!transitioning) return;
        router.post(
            `/it/problems/${problem.id}/transitions`,
            {
                workflow_state: transitioning,
                ...transitionForm.data,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTransitioning(null);
                    transitionForm.reset();
                },
            },
        );
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference} — ${ticket.title}`} />
            <ItModuleShell>
                <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                    <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <Link
                            href="/it/problems"
                            className="frontline-focus inline-flex min-h-10 items-center gap-2 rounded-md text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="h-4 w-4" aria-hidden="true" />{' '}
                            Back to problems
                        </Link>
                        <div className="mt-3 flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-sm font-bold text-primary">
                                        {ticket.reference}
                                    </span>
                                    <StatusBadge
                                        variant={
                                            problemStateVariant[
                                                ticket.workflow_state
                                            ] ?? 'neutral'
                                        }
                                    >
                                        {problemLabel(ticket.workflow_state)}
                                    </StatusBadge>
                                    <StatusBadge
                                        variant={
                                            ticket.priority === 'high' ||
                                            ticket.priority === 'urgent'
                                                ? 'critical'
                                                : 'neutral'
                                        }
                                    >
                                        {problemLabel(ticket.priority)}
                                    </StatusBadge>
                                </div>
                                <h1 className="mt-2 text-2xl font-bold tracking-tight">
                                    {ticket.title}
                                </h1>
                                <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                    {ticket.description ||
                                        'No investigation summary has been added yet.'}
                                </p>
                            </div>
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link href={ticket.href}>
                                    <ExternalLink
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />{' '}
                                    Open canonical ticket workspace
                                </Link>
                            </Button>
                        </div>
                        {can.manage &&
                        (nextStates[ticket.workflow_state] ?? []).length > 0 ? (
                            <div className="mt-5 flex flex-wrap gap-2 border-t border-border pt-4">
                                {(nextStates[ticket.workflow_state] ?? []).map(
                                    (state) => (
                                        <Button
                                            key={state}
                                            variant={
                                                state === 'closed'
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                            onClick={() =>
                                                setTransitioning(state)
                                            }
                                        >
                                            Move to {problemLabel(state)}
                                        </Button>
                                    ),
                                )}
                            </div>
                        ) : null}
                    </header>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <form
                            onSubmit={save}
                            className="space-y-5 rounded-2xl border border-border bg-card p-5"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold">
                                        Investigation knowledge
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        This becomes the known-error context
                                        shown to affected incident responders.
                                    </p>
                                </div>
                                {can.manage ? (
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        <Save
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />{' '}
                                        Save
                                    </Button>
                                ) : null}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Title">
                                    <Input
                                        value={form.data.title}
                                        onChange={(event) =>
                                            form.setData(
                                                'title',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!can.manage}
                                    />
                                </Field>
                                <Field label="Next action">
                                    <Input
                                        value={form.data.next_action}
                                        onChange={(event) =>
                                            form.setData(
                                                'next_action',
                                                event.target.value,
                                            )
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
                                        form.setData(
                                            'impact_summary',
                                            event.target.value,
                                        )
                                    }
                                    disabled={!can.manage}
                                    rows={3}
                                />
                            </Field>
                            <Field label="Root cause">
                                <Textarea
                                    value={form.data.root_cause}
                                    onChange={(event) =>
                                        form.setData(
                                            'root_cause',
                                            event.target.value,
                                        )
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
                                        form.setData(
                                            'workaround',
                                            event.target.value,
                                        )
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
                                    <h3 className="font-semibold">
                                        Affected incidents
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Link only incidents that share this
                                        problem’s cause or workaround.
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
                                                        toggleIncident(
                                                            incident.id,
                                                        )
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
                                            value={
                                                form.data
                                                    .permanent_fix_change_id ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'permanent_fix_change_id',
                                                    event.target.value
                                                        ? Number(
                                                              event.target
                                                                  .value,
                                                          )
                                                        : null,
                                                )
                                            }
                                        >
                                            <option value="">
                                                No linked change yet
                                            </option>
                                            {changeOptions.map((change) => (
                                                <option
                                                    key={change.id}
                                                    value={change.id}
                                                >
                                                    {change.reference} —{' '}
                                                    {change.title}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </section>
                            ) : null}
                        </form>

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

            <Dialog
                open={transitioning !== null}
                onOpenChange={(open) => !open && setTransitioning(null)}
            >
                <DialogContent>
                    <form onSubmit={transition}>
                        <DialogHeader>
                            <DialogTitle>
                                Move to{' '}
                                {transitioning
                                    ? problemLabel(transitioning)
                                    : 'next state'}
                            </DialogTitle>
                            <DialogDescription>
                                Record why this state is accurate. The
                                transition is written to the canonical timeline.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-4">
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
                                            value={
                                                transitionForm.data
                                                    .resolution_code
                                            }
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
                                                transitionForm.data
                                                    .resolution_summary
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
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setTransitioning(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Confirm state</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
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
