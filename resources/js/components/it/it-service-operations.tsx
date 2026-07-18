import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import {
    Activity,
    BookOpenCheck,
    Braces,
    CheckCircle2,
    Clock3,
    MailWarning,
    RefreshCw,
    Route,
    ShieldCheck,
    UsersRound,
} from 'lucide-react';

export interface OperationsAudit {
    teams: {
        total: number;
        active: number;
        missing_manager: number;
        without_members: number;
    };
    queues: {
        total: number;
        active: number;
        missing_team: number;
        without_default_assignee: number;
    };
    catalogue: { total: number; published: number; missing_service: number };
    forms: { configured: number; empty: number };
    email: {
        connections: number;
        connected: number;
        connection_errors: number;
        failed_or_bounced: number;
    };
    api: {
        identities: number;
        active: number;
        revoked: number;
        request_errors: number;
    };
    slas: { custom_policies: number; effective_priorities: number };
    settings: {
        inbound_status_callback: boolean;
        outbound_status_callback: boolean;
    };
}

export interface EmailDeliveryRow {
    id: number;
    notification_uuid: string;
    ticket: { id: number; reference: string; title: string } | null;
    provisioning?: { id: number; item: string } | null;
    recipient: string | null;
    recipient_email: string;
    subject: string;
    status: string;
    attempt_count: number;
    retry_count: number;
    last_error: string | null;
    queued_at: string | null;
    accepted_at?: string | null;
    provider_status_at?: string | null;
    delivered_at: string | null;
    can_retry: boolean;
}

export interface AutomationDefinition {
    key: string;
    label: string;
    expression: string;
    timezone: string;
    next_run_at: string;
    without_overlapping: boolean;
    on_one_server: boolean;
    latest_status: string | null;
    latest_at: string | null;
}

export interface AutomationRunRow {
    id: number;
    automation_key: string;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    runtime_ms: number | null;
    error_summary: string | null;
}

const readable = (value: string) =>
    value
        .replace(/[._-]/g, ' ')
        .replace(/^\w/, (letter) => letter.toUpperCase());

const stamp = (value: string | null) =>
    value
        ? new Date(value).toLocaleString('en-NZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'Not run yet';

export function ItServiceOperations({
    audit,
    deliveries,
    automationDefinitions,
    automationRuns,
}: {
    audit: OperationsAudit;
    deliveries: EmailDeliveryRow[];
    automationDefinitions: AutomationDefinition[];
    automationRuns: AutomationRunRow[];
}) {
    const failures = deliveries.filter((delivery) =>
        ['failed', 'bounced'].includes(delivery.status),
    );

    return (
        <div className="space-y-5">
            <section className="rounded-2xl border border-border bg-card p-5">
                <div className="flex items-start gap-3">
                    <span className="grid h-10 w-10 place-items-center rounded-xl bg-primary/10 text-primary">
                        <ShieldCheck className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 className="font-semibold">Configuration audit</h2>
                        <p className="text-sm text-muted-foreground">
                            One plain-language health check across ownership,
                            routing, request forms, channels, SLAs, and API
                            access.
                        </p>
                    </div>
                </div>
                <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <AuditCard
                        icon={UsersRound}
                        title="Teams"
                        value={`${audit.teams.active}/${audit.teams.total} active`}
                        issues={
                            audit.teams.missing_manager +
                            audit.teams.without_members
                        }
                        detail="missing manager or members"
                    />
                    <AuditCard
                        icon={Route}
                        title="Queues"
                        value={`${audit.queues.active}/${audit.queues.total} active`}
                        issues={
                            audit.queues.missing_team +
                            audit.queues.without_default_assignee
                        }
                        detail="missing team or default assignee"
                    />
                    <AuditCard
                        icon={BookOpenCheck}
                        title="Catalogue & forms"
                        value={`${audit.catalogue.published} published`}
                        issues={
                            audit.catalogue.missing_service + audit.forms.empty
                        }
                        detail="missing service or form fields"
                    />
                    <AuditCard
                        icon={MailWarning}
                        title="Email channels"
                        value={`${audit.email.connected}/${audit.email.connections} connected`}
                        issues={
                            audit.email.connection_errors +
                            audit.email.failed_or_bounced
                        }
                        detail="connection or delivery failures"
                    />
                    <AuditCard
                        icon={Braces}
                        title="API identities"
                        value={`${audit.api.active}/${audit.api.identities} active`}
                        issues={audit.api.request_errors}
                        detail="recent request errors"
                    />
                    <AuditCard
                        icon={Clock3}
                        title="SLA policies"
                        value={`${audit.slas.effective_priorities} priorities covered`}
                        issues={0}
                        detail={`${audit.slas.custom_policies} custom policies`}
                    />
                    <AuditCard
                        icon={CheckCircle2}
                        title="Inbound callback"
                        value={
                            audit.settings.inbound_status_callback
                                ? 'Configured'
                                : 'Not configured'
                        }
                        issues={audit.settings.inbound_status_callback ? 0 : 1}
                        detail="email-to-ticket authentication"
                    />
                    <AuditCard
                        icon={CheckCircle2}
                        title="Delivery callback"
                        value={
                            audit.settings.outbound_status_callback
                                ? 'Configured'
                                : 'Not configured'
                        }
                        issues={audit.settings.outbound_status_callback ? 0 : 1}
                        detail="delivery and bounce status"
                    />
                </div>
            </section>

            <section className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className="border-b border-border px-5 py-4">
                    <h2 className="font-semibold">Email delivery</h2>
                    <p className="text-xs text-muted-foreground">
                        Public ticket replies keep their provider state.
                        Failures stay visible until a technician retries them.
                    </p>
                </div>
                {deliveries.length ? (
                    <div className="divide-y divide-border/70">
                        {deliveries.slice(0, 25).map((delivery) => (
                            <article
                                key={delivery.id}
                                className="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            variant={
                                                delivery.status === 'delivered'
                                                    ? 'success'
                                                    : delivery.can_retry
                                                      ? 'critical'
                                                      : 'info'
                                            }
                                            size="sm"
                                        >
                                            {readable(delivery.status)}
                                        </StatusBadge>
                                        <span className="font-mono text-xs text-primary">
                                            {delivery.ticket?.reference ??
                                                (delivery.provisioning
                                                    ? 'Provisioning'
                                                    : 'System mail')}
                                        </span>
                                    </div>
                                    <p className="mt-1 truncate text-sm font-semibold">
                                        {delivery.subject}
                                    </p>
                                    {delivery.provisioning ? (
                                        <p className="truncate text-xs text-muted-foreground">
                                            Request:{' '}
                                            {delivery.provisioning.item}
                                        </p>
                                    ) : null}
                                    <p className="text-xs text-muted-foreground">
                                        To{' '}
                                        {delivery.recipient ??
                                            delivery.recipient_email}{' '}
                                        · queued {stamp(delivery.queued_at)} ·
                                        attempts {delivery.attempt_count} ·
                                        retries {delivery.retry_count}
                                    </p>
                                    {delivery.last_error ? (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {delivery.last_error}
                                        </p>
                                    ) : null}
                                </div>
                                {delivery.can_retry ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/it/setup/email-deliveries/${delivery.id}/retry`,
                                            )
                                        }
                                    >
                                        <RefreshCw
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />{' '}
                                        Retry delivery
                                    </Button>
                                ) : null}
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                        No outbound IT email has been queued yet.
                    </p>
                )}
                {failures.length ? (
                    <p className="border-t border-border bg-status-critical-bg px-5 py-2 text-xs text-status-critical">
                        {failures.length} delivery{' '}
                        {failures.length === 1
                            ? 'failure needs'
                            : 'failures need'}{' '}
                        attention.
                    </p>
                ) : null}
            </section>

            <section
                id="automations"
                className="overflow-hidden rounded-2xl border border-border bg-card"
            >
                <div className="border-b border-border px-5 py-4">
                    <h2 className="font-semibold">Automation health</h2>
                    <p className="text-xs text-muted-foreground">
                        These are the existing Laravel schedules—this view
                        records outcomes and does not create a second scheduler.
                    </p>
                </div>
                <div className="grid gap-3 p-4 lg:grid-cols-3">
                    {automationDefinitions.map((definition) => (
                        <article
                            key={definition.key}
                            className="rounded-xl border border-border/70 p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="font-semibold">
                                        {definition.label}
                                    </h3>
                                    <p className="font-mono text-xs text-primary">
                                        {definition.key}
                                    </p>
                                </div>
                                <StatusBadge
                                    variant={
                                        definition.latest_status === 'failed'
                                            ? 'critical'
                                            : definition.latest_status ===
                                                'succeeded'
                                              ? 'success'
                                              : 'neutral'
                                    }
                                    size="sm"
                                >
                                    {definition.latest_status
                                        ? readable(definition.latest_status)
                                        : 'Awaiting run'}
                                </StatusBadge>
                            </div>
                            <dl className="mt-3 space-y-1 text-xs text-muted-foreground">
                                <div className="flex justify-between gap-3">
                                    <dt>Schedule</dt>
                                    <dd className="font-mono text-foreground">
                                        {definition.expression}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Next run</dt>
                                    <dd className="text-right text-foreground">
                                        {stamp(definition.next_run_at)}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Overlap guard</dt>
                                    <dd className="text-foreground">
                                        {definition.without_overlapping
                                            ? 'On'
                                            : 'Scheduler default'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Cluster guard</dt>
                                    <dd className="text-foreground">
                                        {definition.on_one_server
                                            ? 'One server'
                                            : 'Every server'}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    ))}
                </div>
                {automationRuns.some((run) => run.status === 'failed') ? (
                    <div className="border-t border-border px-5 py-4">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Activity className="h-4 w-4" /> Recent failures
                        </h3>
                        <div className="mt-2 space-y-2">
                            {automationRuns
                                .filter((run) => run.status === 'failed')
                                .slice(0, 10)
                                .map((run) => (
                                    <div
                                        key={run.id}
                                        className="rounded-lg bg-status-critical-bg px-3 py-2 text-xs"
                                    >
                                        <span className="font-semibold text-status-critical">
                                            {readable(run.automation_key)}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {stamp(run.started_at)}
                                        </span>
                                        {run.error_summary ? (
                                            <p className="mt-1 text-status-critical">
                                                {run.error_summary}
                                            </p>
                                        ) : null}
                                    </div>
                                ))}
                        </div>
                    </div>
                ) : null}
            </section>
        </div>
    );
}

function AuditCard({
    icon: Icon,
    title,
    value,
    issues,
    detail,
}: {
    icon: typeof UsersRound;
    title: string;
    value: string;
    issues: number;
    detail: string;
}) {
    return (
        <article className="rounded-xl border border-border/70 p-3.5">
            <div className="flex items-center gap-2 text-sm font-semibold">
                <Icon className="h-4 w-4 text-primary" aria-hidden="true" />
                {title}
            </div>
            <p className="mt-2 text-lg font-bold">{value}</p>
            <p
                className={`text-xs ${issues ? 'text-status-critical' : 'text-muted-foreground'}`}
            >
                {issues ? `${issues} ${detail}` : detail}
            </p>
        </article>
    );
}
