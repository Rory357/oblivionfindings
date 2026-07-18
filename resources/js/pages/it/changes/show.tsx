import { Button } from '@/components/ui/button';
import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    ExternalLink,
    FileCheck2,
    Link2,
    Save,
    ShieldCheck,
} from 'lucide-react';
import { FormEvent, useState } from 'react';
import {
    changeLabel,
    changeStateVariant,
    type ChangeTicketOption,
} from './index';

interface UserOption {
    id: number;
    name: string;
}
interface SimpleOption {
    id: number;
    name: string;
    uid?: string;
}
interface LinkGroups {
    services: Array<SimpleOption & { status?: string }>;
    sites: Array<SimpleOption & { city?: string }>;
    devices: SimpleOption[];
    alerts: Array<{ id: number; reference: string | null; title: string }>;
    incidents: ChangeTicketOption[];
    problems: ChangeTicketOption[];
}
interface OptionGroups {
    services: SimpleOption[];
    sites: SimpleOption[];
    devices: SimpleOption[];
    alerts: SimpleOption[];
    incidents: ChangeTicketOption[];
    problems: ChangeTicketOption[];
}
interface Props {
    change: {
        id: number;
        change_type: string;
        risk_level: string;
        is_restricted: boolean;
        impact_summary: string | null;
        implementation_plan: string | null;
        validation_plan: string | null;
        backout_plan: string | null;
        maintenance_starts_at: string | null;
        maintenance_ends_at: string | null;
        maintenance_state: string;
        actual_outcome: string | null;
        validation_result: string | null;
        validation_summary: string | null;
        backout_summary: string | null;
        pir_summary: string | null;
        implemented_at: string | null;
        implemented_by: UserOption | null;
        validated_at: string | null;
        validated_by: UserOption | null;
        backed_out_at: string | null;
        reviewed_at: string | null;
        reviewed_by: UserOption | null;
    };
    ticket: ChangeTicketOption & {
        description: string | null;
        category: string;
        next_action: string | null;
        requires_approval: boolean;
        approval: {
            id: number;
            status: string;
            reason: string | null;
            requester: UserOption | null;
            approver: UserOption | null;
        } | null;
        sla_state: string;
        comments_count: number;
        tasks_count: number;
        approvals_count: number;
        attachments_count: number;
        events_count: number;
    };
    links: LinkGroups;
    options: OptionGroups;
    can: { manage: boolean };
}

const nextStates: Record<string, string[]> = {
    draft: ['assessment', 'cancelled'],
    assessment: ['approval_pending', 'approved', 'cancelled'],
    approval_pending: ['approved', 'rejected', 'cancelled'],
    approved: ['scheduled', 'implementing', 'cancelled'],
    scheduled: ['implementing', 'cancelled'],
    implementing: ['validation', 'failed', 'backed_out'],
    validation: ['completed', 'failed', 'backed_out'],
    completed: ['review', 'closed'],
    failed: ['review', 'closed'],
    backed_out: ['review', 'closed'],
    review: ['closed'],
    rejected: ['closed'],
    cancelled: ['closed'],
    closed: ['draft'],
};

export default function ItChangeShow({
    change,
    ticket,
    links,
    options,
    can,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [transitioning, setTransitioning] = useState(false);
    const edit = useForm({
        title: ticket.title,
        description: ticket.description ?? '',
        category: ticket.category,
        priority: ticket.priority,
        next_action: ticket.next_action ?? '',
        change_type: change.change_type,
        risk_level: change.risk_level,
        is_restricted: change.is_restricted,
        impact_summary: change.impact_summary ?? '',
        implementation_plan: change.implementation_plan ?? '',
        validation_plan: change.validation_plan ?? '',
        backout_plan: change.backout_plan ?? '',
        maintenance_starts_at: localDateTime(change.maintenance_starts_at),
        maintenance_ends_at: localDateTime(change.maintenance_ends_at),
        actual_outcome: change.actual_outcome ?? '',
        validation_result: change.validation_result ?? '',
        validation_summary: change.validation_summary ?? '',
        backout_summary: change.backout_summary ?? '',
        pir_summary: change.pir_summary ?? '',
        service_ids: links.services.map((item) => item.id),
        site_ids: links.sites.map((item) => item.id),
        device_ids: links.devices.map((item) => item.id),
        alert_ids: links.alerts.map((item) => item.id),
        incident_ids: links.incidents.map((item) => item.id),
        problem_ids: links.problems.map((item) => item.id),
    });
    const transition = useForm({
        workflow_state: nextStates[ticket.workflow_state]?.[0] ?? 'closed',
        reason: '',
        resolution_code: '',
        resolution_summary: '',
    });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Support', href: '/it' },
        { title: 'Changes', href: '/it/changes' },
        { title: ticket.reference, href: `/it/changes/${change.id}` },
    ];

    const save = (event: FormEvent) => {
        event.preventDefault();
        edit.patch(`/it/changes/${change.id}`, {
            onSuccess: () => setEditing(false),
        });
    };
    const move = (event: FormEvent) => {
        event.preventDefault();
        transition.post(`/it/changes/${change.id}/transitions`, {
            onSuccess: () => setTransitioning(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.reference} · Change`} />
            <ItModuleShell>
            <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <Link
                        href="/it/changes"
                        className="frontline-focus inline-flex min-h-11 items-center gap-2 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" aria-hidden="true" />{' '}
                        Back to changes
                    </Link>
                    <div className="mt-2 flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-sm font-bold text-primary">
                                    {ticket.reference}
                                </span>
                                <StatusBadge
                                    variant={
                                        changeStateVariant[
                                            ticket.workflow_state
                                        ] ?? 'neutral'
                                    }
                                >
                                    {changeLabel(ticket.workflow_state)}
                                </StatusBadge>
                                <StatusBadge
                                    variant={
                                        change.risk_level === 'critical' ||
                                        change.risk_level === 'high'
                                            ? 'critical'
                                            : 'info'
                                    }
                                >
                                    {changeLabel(change.risk_level)} risk
                                </StatusBadge>
                                {change.is_restricted ? (
                                    <StatusBadge variant="critical">
                                        Restricted
                                    </StatusBadge>
                                ) : null}
                            </div>
                            <h1 className="mt-3 text-2xl font-bold tracking-tight">
                                {ticket.title}
                            </h1>
                            <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                {ticket.description ||
                                    'No change description recorded.'}
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
                                <Button
                                    variant="outline"
                                    onClick={() => setEditing(true)}
                                >
                                    Edit change
                                </Button>
                            ) : null}
                            {can.manage &&
                            nextStates[ticket.workflow_state]?.length ? (
                                <Button onClick={() => setTransitioning(true)}>
                                    Update state
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </header>

                <section
                    className="grid gap-4 md:grid-cols-3"
                    aria-label="Change controls"
                >
                    <SummaryCard
                        icon={<CalendarClock />}
                        title="Maintenance window"
                    >
                        <p className="font-semibold">
                            {changeLabel(change.maintenance_state)}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {windowText(
                                change.maintenance_starts_at,
                                change.maintenance_ends_at,
                            )}
                        </p>
                    </SummaryCard>
                    <SummaryCard icon={<ShieldCheck />} title="Approval">
                        <p className="font-semibold">
                            {ticket.requires_approval
                                ? changeLabel(
                                      ticket.approval?.status ??
                                          'not requested',
                                  )
                                : 'Pre-authorized standard'}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {ticket.approval?.approver
                                ? `Decision by ${ticket.approval.approver.name}`
                                : ticket.approval?.reason ||
                                  'Approval follows the shared ticket workflow.'}
                        </p>
                    </SummaryCard>
                    <SummaryCard icon={<FileCheck2 />} title="Next action">
                        <p className="font-semibold">
                            {ticket.next_action ||
                                'Set the next accountable action'}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {changeLabel(change.change_type)} change · SLA{' '}
                            {changeLabel(ticket.sla_state)}
                        </p>
                    </SummaryCard>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <div className="space-y-6">
                        <Panel
                            title="Risk, impact, and plans"
                            description="The approved execution contract stays readable throughout the change."
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <KnowledgeBlock
                                    title="Expected impact"
                                    value={change.impact_summary}
                                />
                                <KnowledgeBlock
                                    title="Implementation plan"
                                    value={change.implementation_plan}
                                />
                                <KnowledgeBlock
                                    title="Validation plan"
                                    value={change.validation_plan}
                                />
                                <KnowledgeBlock
                                    title="Backout plan"
                                    value={change.backout_plan}
                                />
                            </div>
                        </Panel>
                        <Panel
                            title="Implementation outcome"
                            description="Observed results, independent validation, backout evidence, and post-implementation review."
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <KnowledgeBlock
                                    title="Actual outcome"
                                    value={change.actual_outcome}
                                />
                                <KnowledgeBlock
                                    title="Independent validation"
                                    value={change.validation_summary}
                                    meta={
                                        change.validated_by
                                            ? `${changeLabel(change.validation_result ?? 'pending')} · ${change.validated_by.name}`
                                            : changeLabel(
                                                  change.validation_result ??
                                                      'pending',
                                              )
                                    }
                                />
                                <KnowledgeBlock
                                    title="Backout outcome"
                                    value={change.backout_summary}
                                />
                                <KnowledgeBlock
                                    title="Post-implementation review"
                                    value={change.pir_summary}
                                    meta={change.reviewed_by?.name}
                                />
                            </div>
                        </Panel>
                        <Panel
                            title="Affected services and records"
                            description="One scoped view of operational impact without copying source-system data."
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <LinkGroup
                                    title="Services"
                                    items={links.services.map(
                                        (item) => item.name,
                                    )}
                                />
                                <LinkGroup
                                    title="Sites"
                                    items={links.sites.map((item) => item.name)}
                                />
                                <LinkGroup
                                    title="Devices"
                                    items={links.devices.map(
                                        (item) => item.name,
                                    )}
                                />
                                <LinkGroup
                                    title="Monitoring alerts"
                                    items={links.alerts.map(
                                        (item) => item.reference || item.title,
                                    )}
                                />
                                <TicketLinkGroup
                                    title="Incidents"
                                    items={links.incidents}
                                />
                                <TicketLinkGroup
                                    title="Problems"
                                    items={links.problems}
                                />
                            </div>
                        </Panel>
                    </div>

                    <aside className="space-y-6">
                        <Panel
                            title="Shared work record"
                            description="Conversation and evidence remain on the canonical ticket."
                        >
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <Count
                                    label="Comments"
                                    value={ticket.comments_count}
                                />
                                <Count
                                    label="Tasks"
                                    value={ticket.tasks_count}
                                />
                                <Count
                                    label="Approvals"
                                    value={ticket.approvals_count}
                                />
                                <Count
                                    label="Attachments"
                                    value={ticket.attachments_count}
                                />
                                <Count
                                    label="Timeline events"
                                    value={ticket.events_count}
                                    className="col-span-2"
                                />
                            </dl>
                            <Button
                                asChild
                                variant="outline"
                                className="mt-4 w-full"
                            >
                                <Link href={ticket.href}>
                                    Open shared work{' '}
                                    <ExternalLink
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        </Panel>
                        <Panel
                            title="Accountability"
                            description="Execution and validation actors are explicit."
                        >
                            <dl className="space-y-3 text-sm">
                                <Detail
                                    label="Implemented by"
                                    value={change.implemented_by?.name}
                                    date={change.implemented_at}
                                />
                                <Detail
                                    label="Validated by"
                                    value={change.validated_by?.name}
                                    date={change.validated_at}
                                />
                                <Detail
                                    label="Reviewed by"
                                    value={change.reviewed_by?.name}
                                    date={change.reviewed_at}
                                />
                            </dl>
                        </Panel>
                    </aside>
                </div>
            </main>
            </ItModuleShell>

            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                    <form onSubmit={save}>
                        <DialogHeader>
                            <DialogTitle>Edit change controls</DialogTitle>
                            <DialogDescription>
                                Keep plans, evidence, timing, and affected
                                records current before changing state.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <Field label="Title" className="md:col-span-2">
                                <Input
                                    value={edit.data.title}
                                    onChange={(event) =>
                                        edit.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Next action"
                                className="md:col-span-2"
                            >
                                <Input
                                    value={edit.data.next_action}
                                    onChange={(event) =>
                                        edit.setData(
                                            'next_action',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Change type">
                                <FormSelect
                                    value={edit.data.change_type}
                                    onChange={(value) =>
                                        edit.setData('change_type', value)
                                    }
                                    values={['standard', 'normal', 'emergency']}
                                />
                            </Field>
                            <Field label="Risk level">
                                <FormSelect
                                    value={edit.data.risk_level}
                                    onChange={(value) =>
                                        edit.setData('risk_level', value)
                                    }
                                    values={[
                                        'low',
                                        'medium',
                                        'high',
                                        'critical',
                                    ]}
                                />
                            </Field>
                            <label className="flex items-center gap-3 rounded-lg border border-border px-3 py-3 text-sm font-medium md:col-span-2">
                                <input
                                    type="checkbox"
                                    checked={edit.data.is_restricted}
                                    onChange={(event) =>
                                        edit.setData(
                                            'is_restricted',
                                            event.target.checked,
                                        )
                                    }
                                    className="h-4 w-4"
                                />{' '}
                                Restricted or privileged change
                            </label>
                            <Area
                                label="Expected impact"
                                value={edit.data.impact_summary}
                                onChange={(value) =>
                                    edit.setData('impact_summary', value)
                                }
                            />
                            <Area
                                label="Implementation plan"
                                value={edit.data.implementation_plan}
                                onChange={(value) =>
                                    edit.setData('implementation_plan', value)
                                }
                            />
                            <Area
                                label="Validation plan"
                                value={edit.data.validation_plan}
                                onChange={(value) =>
                                    edit.setData('validation_plan', value)
                                }
                            />
                            <Area
                                label="Backout plan"
                                value={edit.data.backout_plan}
                                onChange={(value) =>
                                    edit.setData('backout_plan', value)
                                }
                            />
                            <Field label="Maintenance starts">
                                <Input
                                    type="datetime-local"
                                    value={edit.data.maintenance_starts_at}
                                    onChange={(event) =>
                                        edit.setData(
                                            'maintenance_starts_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Maintenance ends">
                                <Input
                                    type="datetime-local"
                                    value={edit.data.maintenance_ends_at}
                                    onChange={(event) =>
                                        edit.setData(
                                            'maintenance_ends_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Area
                                label="Actual outcome"
                                value={edit.data.actual_outcome}
                                onChange={(value) =>
                                    edit.setData('actual_outcome', value)
                                }
                            />
                            <Field label="Validation result">
                                <FormSelect
                                    value={
                                        edit.data.validation_result || 'pending'
                                    }
                                    onChange={(value) =>
                                        edit.setData(
                                            'validation_result',
                                            value === 'pending' ? '' : value,
                                        )
                                    }
                                    values={[
                                        'pending',
                                        'successful',
                                        'failed',
                                        'inconclusive',
                                    ]}
                                />
                            </Field>
                            <Area
                                label="Validation summary"
                                value={edit.data.validation_summary}
                                onChange={(value) =>
                                    edit.setData('validation_summary', value)
                                }
                            />
                            <Area
                                label="Backout outcome"
                                value={edit.data.backout_summary}
                                onChange={(value) =>
                                    edit.setData('backout_summary', value)
                                }
                            />
                            <Area
                                label="Post-implementation review"
                                value={edit.data.pir_summary}
                                onChange={(value) =>
                                    edit.setData('pir_summary', value)
                                }
                                className="md:col-span-2"
                            />
                            <MultiSelect
                                title="Affected services"
                                values={options.services}
                                selected={edit.data.service_ids}
                                onChange={(ids) =>
                                    edit.setData('service_ids', ids)
                                }
                            />
                            <MultiSelect
                                title="Affected sites"
                                values={options.sites}
                                selected={edit.data.site_ids}
                                onChange={(ids) =>
                                    edit.setData('site_ids', ids)
                                }
                            />
                            <MultiSelect
                                title="Affected devices"
                                values={options.devices}
                                selected={edit.data.device_ids}
                                onChange={(ids) =>
                                    edit.setData('device_ids', ids)
                                }
                            />
                            <MultiSelect
                                title="Monitoring alerts"
                                values={options.alerts}
                                selected={edit.data.alert_ids}
                                onChange={(ids) =>
                                    edit.setData('alert_ids', ids)
                                }
                            />
                            <MultiSelect
                                title="Related incidents"
                                values={options.incidents.map((item) => ({
                                    id: item.id,
                                    name: `${item.reference} · ${item.title}`,
                                }))}
                                selected={edit.data.incident_ids}
                                onChange={(ids) =>
                                    edit.setData('incident_ids', ids)
                                }
                            />
                            <MultiSelect
                                title="Related problems"
                                values={options.problems.map((item) => ({
                                    id: item.id,
                                    name: `${item.reference} · ${item.title}`,
                                }))}
                                selected={edit.data.problem_ids}
                                onChange={(ids) =>
                                    edit.setData('problem_ids', ids)
                                }
                            />
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
                                Save change
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={transitioning} onOpenChange={setTransitioning}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={move}>
                        <DialogHeader>
                            <DialogTitle>Update change state</DialogTitle>
                            <DialogDescription>
                                Lifecycle gates verify plans, approval, timing,
                                outcome, validation, and review evidence.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-4">
                            <Field label="Next state">
                                <FormSelect
                                    value={transition.data.workflow_state}
                                    onChange={(value) =>
                                        transition.setData(
                                            'workflow_state',
                                            value,
                                        )
                                    }
                                    values={
                                        nextStates[ticket.workflow_state] ?? []
                                    }
                                />
                            </Field>
                            <Area
                                label="Reason"
                                value={transition.data.reason}
                                onChange={(value) =>
                                    transition.setData('reason', value)
                                }
                            />
                            {transition.data.workflow_state === 'completed' ? (
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
                                    <Area
                                        label="Resolution summary"
                                        value={
                                            transition.data.resolution_summary
                                        }
                                        onChange={(value) =>
                                            transition.setData(
                                                'resolution_summary',
                                                value,
                                            )
                                        }
                                    />
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
        </AppLayout>
    );
}

function Panel({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            <h2 className="font-semibold">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
            <div className="mt-4">{children}</div>
        </section>
    );
}
function SummaryCard({
    icon,
    title,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-4">
            <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <span className="[&_svg]:h-4 [&_svg]:w-4">{icon}</span>
                {title}
            </div>
            <div className="mt-3">{children}</div>
        </section>
    );
}
function KnowledgeBlock({
    title,
    value,
    meta,
}: {
    title: string;
    value: string | null;
    meta?: string | null;
}) {
    return (
        <article className="rounded-xl border border-border bg-muted/30 p-4">
            <h3 className="text-sm font-semibold">{title}</h3>
            {meta ? (
                <p className="mt-1 text-xs font-medium text-primary">{meta}</p>
            ) : null}
            <p className="mt-2 text-sm whitespace-pre-wrap text-muted-foreground">
                {value || 'Not recorded yet.'}
            </p>
        </article>
    );
}
function LinkGroup({ title, items }: { title: string; items: string[] }) {
    return (
        <div className="rounded-xl border border-border p-3">
            <h3 className="flex items-center gap-2 text-sm font-semibold">
                <Link2 className="h-4 w-4" aria-hidden="true" />
                {title}
            </h3>
            {items.length ? (
                <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                    {items.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
            ) : (
                <p className="mt-2 text-sm text-muted-foreground">
                    None linked.
                </p>
            )}
        </div>
    );
}
function TicketLinkGroup({
    title,
    items,
}: {
    title: string;
    items: ChangeTicketOption[];
}) {
    return (
        <div className="rounded-xl border border-border p-3">
            <h3 className="flex items-center gap-2 text-sm font-semibold">
                <Link2 className="h-4 w-4" aria-hidden="true" />
                {title}
            </h3>
            {items.length ? (
                <ul className="mt-2 space-y-1 text-sm">
                    {items.map((item) => (
                        <li key={item.id}>
                            <Link
                                className="text-primary hover:underline"
                                href={item.href}
                            >
                                {item.reference} · {item.title}
                            </Link>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-2 text-sm text-muted-foreground">
                    None linked.
                </p>
            )}
        </div>
    );
}
function Count({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className?: string;
}) {
    return (
        <div className={`rounded-lg bg-muted/50 p-3 ${className ?? ''}`}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-lg font-bold">{value}</dd>
        </div>
    );
}
function Detail({
    label,
    value,
    date,
}: {
    label: string;
    value?: string | null;
    date: string | null;
}) {
    return (
        <div>
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-1 font-medium">{value || 'Not recorded'}</dd>
            {date ? (
                <dd className="text-xs text-muted-foreground">
                    {formatDate(date)}
                </dd>
            ) : null}
        </div>
    );
}
function Field({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label
            className={`block space-y-1.5 text-sm font-medium ${className ?? ''}`}
        >
            <span>{label}</span>
            {children}
        </label>
    );
}
function Area({
    label,
    value,
    onChange,
    className,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <Field label={label} className={className}>
            <Textarea
                value={value}
                onChange={(event) => onChange(event.target.value)}
                rows={4}
            />
        </Field>
    );
}
function FormSelect({
    value,
    onChange,
    values,
}: {
    value: string;
    onChange: (value: string) => void;
    values: string[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="min-h-11">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {values.map((item) => (
                    <SelectItem key={item} value={item}>
                        {changeLabel(item)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
function MultiSelect({
    title,
    values,
    selected,
    onChange,
}: {
    title: string;
    values: SimpleOption[];
    selected: number[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-semibold">{title}</legend>
            <div className="max-h-36 space-y-1 overflow-y-auto">
                {values.length ? (
                    values.map((item) => (
                        <label
                            key={item.id}
                            className="flex min-h-9 items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={selected.includes(item.id)}
                                onChange={(event) =>
                                    onChange(
                                        event.target.checked
                                            ? [...selected, item.id]
                                            : selected.filter(
                                                  (id) => id !== item.id,
                                              ),
                                    )
                                }
                            />
                            {item.name}
                        </label>
                    ))
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No options available.
                    </p>
                )}
            </div>
        </fieldset>
    );
}
function localDateTime(value: string | null) {
    return value ? new Date(value).toISOString().slice(0, 16) : '';
}
function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
function windowText(start: string | null, end: string | null) {
    return start && end
        ? `${formatDate(start)} – ${formatDate(end)}`
        : 'No maintenance window recorded.';
}
