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
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Clock3,
    ExternalLink,
    Link2,
    Megaphone,
    Pencil,
    Radio,
    Save,
    Send,
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

export default function ItMajorIncidentShow({
    majorIncident,
    ticket,
    updates,
    links,
    options,
    can,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [transitioning, setTransitioning] = useState(false);
    const edit = useForm({
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
    });
    const communication = useForm({
        update_kind: 'stakeholder_update',
        audience: 'staff',
        summary: '',
        service_status: 'investigating',
    });
    const transition = useForm({
        workflow_state: nextStates[ticket.workflow_state]?.[0] ?? 'closed',
        reason: '',
        resolution_code: '',
        resolution_summary: '',
    });
    const breadcrumbs: BreadcrumbItem[] = [
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference} · Major incident`} />
            <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                <header className="overflow-hidden rounded-2xl border border-status-critical/30 bg-card shadow-sm">
                    <div className="border-l-4 border-status-critical p-5">
                        <Link
                            href="/it/major-incidents"
                            className="frontline-focus inline-flex min-h-11 items-center gap-2 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="h-4 w-4" aria-hidden="true" />{' '}
                            Back to major incidents
                        </Link>
                        <div className="mt-2 flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        variant={
                                            majorIncident.severity === 'sev1' ||
                                            majorIncident.severity === 'sev2'
                                                ? 'critical'
                                                : 'warning'
                                        }
                                    >
                                        {majorIncident.severity.toUpperCase()}
                                    </StatusBadge>
                                    <StatusBadge
                                        variant={
                                            majorIncidentStateVariant[
                                                ticket.workflow_state
                                            ] ?? 'neutral'
                                        }
                                    >
                                        {majorIncidentLabel(
                                            ticket.workflow_state,
                                        )}
                                    </StatusBadge>
                                    {majorIncident.update_state ===
                                    'overdue' ? (
                                        <StatusBadge variant="critical">
                                            Update overdue
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge variant="success">
                                            Updates on time
                                        </StatusBadge>
                                    )}
                                </div>
                                <div className="mt-3 flex items-center gap-2">
                                    <Siren
                                        className="h-5 w-5 text-status-critical"
                                        aria-hidden="true"
                                    />
                                    <span className="font-mono text-sm font-bold text-primary">
                                        {ticket.reference}
                                    </span>
                                </div>
                                <h1 className="mt-1 text-2xl font-bold tracking-tight">
                                    {ticket.title}
                                </h1>
                                <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                    {ticket.description ||
                                        'No incident description recorded.'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={ticket.href}>
                                        Open canonical ticket workspace{' '}
                                        <ExternalLink
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                                {can.manage ? (
                                    <>
                                        <Button
                                            variant="outline"
                                            className="min-h-11"
                                            onClick={() => setEditing(true)}
                                        >
                                            <Pencil
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />{' '}
                                            Edit command
                                        </Button>
                                        <Button
                                            className="min-h-11"
                                            onClick={() => setPublishing(true)}
                                        >
                                            <Send
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />{' '}
                                            Publish update
                                        </Button>
                                    </>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </header>

                <section
                    className={`rounded-2xl border p-4 ${majorIncident.update_state === 'overdue' ? 'border-status-critical/40 bg-status-critical-bg' : 'border-status-success/30 bg-status-success-bg'}`}
                >
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                        <div className="flex items-start gap-3">
                            <Clock3
                                className={`mt-0.5 h-5 w-5 ${majorIncident.update_state === 'overdue' ? 'text-status-critical' : 'text-status-success'}`}
                                aria-hidden="true"
                            />
                            <div>
                                <h2 className="font-semibold">
                                    {majorIncident.update_state === 'overdue'
                                        ? 'Update overdue'
                                        : 'Communication cadence on track'}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Next audience update:{' '}
                                    {formatDateTime(
                                        majorIncident.next_update_due_at,
                                    )}{' '}
                                    · every{' '}
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
                                    value={ticket.next_action || 'Not recorded'}
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
                                    No command or stakeholder updates published.
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
                                values={links.services.map((item) => item.name)}
                            />
                            <LinkList
                                title="Sites"
                                values={links.sites.map((item) => item.name)}
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
                        (nextStates[ticket.workflow_state]?.length ?? 0) > 0 ? (
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

            <Dialog open={publishing} onOpenChange={setPublishing}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={publish}>
                        <DialogHeader>
                            <DialogTitle>
                                Publish major incident update
                            </DialogTitle>
                            <DialogDescription>
                                Choose the audience deliberately. Internal
                                command notes never appear in the staff status
                                feed.
                            </DialogDescription>
                        </DialogHeader>
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
                                values={[
                                    'internal',
                                    'staff',
                                    'clients',
                                    'public',
                                ]}
                            />
                            <NativeSelect
                                label="Service status"
                                value={communication.data.service_status}
                                onChange={(value) =>
                                    communication.setData(
                                        'service_status',
                                        value,
                                    )
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
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setPublishing(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={communication.processing}
                            >
                                <Send className="h-4 w-4" aria-hidden="true" />{' '}
                                Publish update
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={transitioning} onOpenChange={setTransitioning}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={move}>
                        <DialogHeader>
                            <DialogTitle>Move major incident state</DialogTitle>
                            <DialogDescription>
                                Restoration, resolution, review, and closure
                                enforce their required evidence.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-4">
                            <NativeSelect
                                label="Next state"
                                value={transition.data.workflow_state}
                                onChange={(value) =>
                                    transition.setData('workflow_state', value)
                                }
                                values={nextStates[ticket.workflow_state] ?? []}
                            />
                            <Field label="Reason">
                                <Textarea
                                    value={transition.data.reason}
                                    onChange={(event) =>
                                        transition.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                    required
                                />
                            </Field>
                            {transition.data.workflow_state === 'resolved' ? (
                                <>
                                    <Field label="Resolution code">
                                        <Input
                                            value={
                                                transition.data.resolution_code
                                            }
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
                                            value={
                                                transition.data
                                                    .resolution_summary
                                            }
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
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setTransitioning(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={transition.processing}
                            >
                                Update state
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <form onSubmit={save}>
                        <DialogHeader>
                            <DialogTitle>Edit incident command</DialogTitle>
                            <DialogDescription>
                                Maintain accountability, evidence, cadence, and
                                typed operational links.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Title" className="sm:col-span-2">
                                <Input
                                    value={edit.data.title}
                                    onChange={(event) =>
                                        edit.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <NativeSelect
                                label="Severity"
                                value={edit.data.severity}
                                onChange={(value) =>
                                    edit.setData('severity', value)
                                }
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
                                    edit.setData(
                                        'communications_lead_user_id',
                                        value,
                                    )
                                }
                            />
                            <Field
                                label="Current impact"
                                className="sm:col-span-2"
                            >
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
                            <Field
                                label="Next action"
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={edit.data.next_action}
                                    onChange={(event) =>
                                        edit.setData(
                                            'next_action',
                                            event.target.value,
                                        )
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
                            <Field
                                label="Root-cause summary"
                                className="sm:col-span-2"
                            >
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
                                onChange={(value) =>
                                    edit.setData('service_ids', value)
                                }
                            />
                            <MultiSelect
                                label="Affected sites"
                                options={options.sites}
                                selected={edit.data.site_ids}
                                onChange={(value) =>
                                    edit.setData('site_ids', value)
                                }
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
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={edit.processing}>
                                <Save className="h-4 w-4" aria-hidden="true" />{' '}
                                Save command
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
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
