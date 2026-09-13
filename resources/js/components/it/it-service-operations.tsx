import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
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
import { useEffect, useRef, useState } from 'react';
import { ItApiOperations, type ApiOperationsHealth } from './it-api-operations';
import {
    ItAutomationHistory,
    type AutomationHistory,
} from './it-automation-history';
import {
    ItChannelHealth,
    type DeliveryHealth,
    type MailboxHealth,
} from './it-channel-health';

export interface OperationsAudit {
    automation_history?: AutomationHistory;
    api_health?: ApiOperationsHealth;
    mailbox_health?: MailboxHealth;
    delivery_health?: DeliveryHealth;
    attachment_cleanup?: {
        viewer_user_id: number;
        can_view_counts: boolean;
        readiness: 'ready' | 'not_ready' | 'unavailable';
        checked_at: string;
        counts: {
            cleanup_pending: number;
            reserved_unclassified: number;
            reconciliation_required: number;
        } | null;
    };
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
        connections: number | null;
        connected: number | null;
        connection_errors: number | null;
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
    failure_category?: string | null;
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
    overlap_minutes?: number;
    freshness?: {
        state: 'fresh' | 'stale' | 'failed' | 'running' | 'unmeasured';
        last_success_at: string | null;
        latest_status: string | null;
        latest_started_at: string | null;
        required_since: string;
        evaluated_at: string;
        grace_seconds: number;
    };
}

export interface AutomationRunRow {
    id: number;
    automation_key: string;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    runtime_ms: number | null;
    error_summary: string | null;
    cleanup?: { limit: number; counts: CleanupBatchCounts | null } | null;
}

interface CleanupBatchCounts {
    requested: number;
    deleted: number;
    failed: number;
    deferred: number;
    reconciliation_required: number;
}

const AUTOMATION_FRESHNESS: Record<
    NonNullable<AutomationDefinition['freshness']>['state'],
    { label: string; variant: StatusVariant }
> = {
    fresh: { label: 'Current', variant: 'success' },
    stale: { label: 'Overdue check', variant: 'warning' },
    failed: { label: 'Failed', variant: 'critical' },
    running: { label: 'Running', variant: 'info' },
    unmeasured: { label: 'No verified check', variant: 'neutral' },
};

const readable = (value: string) =>
    value
        .replace(/[._-]/g, ' ')
        .replace(/^\w/, (letter) => letter.toUpperCase());

const stamp = (value: string | null) => formatDateTime(value, 'Not recorded');

const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

type RetryReceipt = {
    original_delivery_id: number;
    retry_delivery_id: number;
    status: 'queued';
    actor_id: number;
};

const retryReceipt = (
    value: unknown,
    deliveryId: number,
    actorId: number,
): RetryReceipt | null => {
    const data = record(value) && record(value.data) ? value.data : null;
    if (
        !data ||
        data.original_delivery_id !== deliveryId ||
        !Number.isSafeInteger(data.retry_delivery_id) ||
        (data.retry_delivery_id as number) < 1 ||
        data.retry_delivery_id === deliveryId ||
        data.status !== 'queued' ||
        data.actor_id !== actorId
    )
        return null;

    return data as RetryReceipt;
};

const freshRetryEligibility = (
    page: unknown,
    deliveryId: number,
    actorId: number,
) => {
    const props = record(page) && record(page.props) ? page.props : null;
    const auth = props && record(props.auth) ? props.auth : null;
    const user = auth && record(auth.user) ? auth.user : null;
    const rows = props?.emailDeliveries;

    return (
        user?.id === actorId &&
        Array.isArray(rows) &&
        rows.some(
            (row) =>
                record(row) && row.id === deliveryId && row.can_retry === true,
        )
    );
};

function DeliveryStatusDetail({ delivery }: { delivery: EmailDeliveryRow }) {
    if (delivery.status === 'sending') {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                Submission started; the outcome is unconfirmed. Check the
                provider result before retrying.
                {delivery.provider_status_at ? (
                    <>
                        {' '}
                        Provider update{' '}
                        <time dateTime={delivery.provider_status_at}>
                            {stamp(delivery.provider_status_at)}
                        </time>
                        .
                    </>
                ) : null}
            </p>
        );
    }

    if (delivery.status === 'accepted') {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                Provider accepted this email. Delivery is not yet confirmed.
                {delivery.accepted_at ? (
                    <>
                        {' '}
                        Accepted{' '}
                        <time dateTime={delivery.accepted_at}>
                            {stamp(delivery.accepted_at)}
                        </time>
                        .
                    </>
                ) : null}
                {delivery.provider_status_at ? (
                    <>
                        {' '}
                        Provider update{' '}
                        <time dateTime={delivery.provider_status_at}>
                            {stamp(delivery.provider_status_at)}
                        </time>
                        .
                    </>
                ) : null}
            </p>
        );
    }

    if (delivery.status === 'delivered' && delivery.delivered_at) {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                Delivery confirmed{' '}
                <time dateTime={delivery.delivered_at}>
                    {stamp(delivery.delivered_at)}
                </time>
                .
            </p>
        );
    }

    return null;
}

function RetryDeliveryControl({
    delivery,
    actorId,
}: {
    delivery: EmailDeliveryRow;
    actorId: number | null;
}) {
    const [state, setState] = useState<
        'idle' | 'pending' | 'confirmed' | 'review' | 'unavailable'
    >('idle');
    const [message, setMessage] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const refreshing = useRef(false);
    const epoch = useRef(0);
    const ownerActorId = useRef(actorId);
    const currentActorId = useRef(actorId);
    currentActorId.current = actorId;
    const sameActor = actorId !== null && ownerActorId.current === actorId;

    useEffect(() => {
        if (sameActor) return;
        epoch.current += 1;
        request.current?.abort();
        request.current = null;
        refreshing.current = false;
        setState('review');
        setMessage(null);
    }, [sameActor]);

    useEffect(
        () => () => {
            epoch.current += 1;
            request.current?.abort();
        },
        [],
    );

    if (!sameActor) return null;

    const refresh = (retainMessage = false) => {
        if (refreshing.current) return;
        refreshing.current = true;
        const refreshEpoch = ++epoch.current;
        const current = () =>
            epoch.current === refreshEpoch &&
            currentActorId.current === actorId;
        let answered = false;
        if (!retainMessage) {
            setState('review');
            setMessage('Refreshing current delivery state.');
        }
        router.reload({
            // The summary uses all visible deliveries, not this filtered page.
            only: [
                'auth',
                'emailDeliveries',
                'emailDeliveryFilter',
                'operationsAudit',
                'generatedAt',
            ],
            preserveScroll: true,
            onSuccess: (page) => {
                answered = true;
                refreshing.current = false;
                if (!current() || actorId === null) return;
                if (freshRetryEligibility(page, delivery.id, actorId)) {
                    setState('idle');
                    setMessage(null);
                    return;
                }
                setState('review');
                setMessage(
                    'Current delivery state does not confirm that another retry is eligible.',
                );
            },
            onError: () => {
                answered = true;
                refreshing.current = false;
                if (!current()) return;
                setState('review');
                setMessage(
                    'Current delivery state could not be refreshed. Try again before retrying.',
                );
            },
            onCancel: () => {
                answered = true;
                refreshing.current = false;
                if (!current()) return;
                setState('review');
                setMessage(
                    'Current delivery state was not refreshed. Try again before retrying.',
                );
            },
            onFinish: () => {
                if (answered || !current()) return;
                refreshing.current = false;
                setState('review');
                setMessage(
                    'Current delivery state could not be confirmed. Try again before retrying.',
                );
            },
        });
    };

    const retry = async () => {
        if (state !== 'idle' || request.current || actorId === null) return;
        const controller = new AbortController();
        request.current = controller;
        const requestEpoch = ++epoch.current;
        const current = () =>
            epoch.current === requestEpoch &&
            currentActorId.current === actorId;
        setState('pending');
        setMessage(null);

        try {
            const response = await axios.post(
                `/it/setup/email-deliveries/${delivery.id}/retry`,
                { expected_actor_id: actorId },
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                    timeout: 30000,
                },
            );
            if (!current()) return;
            if (
                response.status !== 200 ||
                !retryReceipt(response.data, delivery.id, actorId)
            )
                throw new Error('Unconfirmed retry response');

            setState('confirmed');
            setMessage(
                'Email was queued for another delivery attempt. Refreshing current delivery state.',
            );
            refresh(true);
        } catch (error) {
            if (!current()) return;
            const response = axios.isAxiosError(error) ? error.response : null;
            const responseMessage =
                response &&
                record(response.data) &&
                typeof response.data.message === 'string'
                    ? response.data.message
                    : null;
            if ([401, 403, 404].includes(response?.status ?? 0)) {
                setState('unavailable');
                setMessage(
                    'This delivery is no longer available to retry. Refresh current delivery state.',
                );
            } else if ([409, 422].includes(response?.status ?? 0)) {
                setState('review');
                setMessage(
                    responseMessage
                        ? `${responseMessage} Refresh current delivery state before trying again.`
                        : 'The retry could not be confirmed. Refresh current delivery state before trying again.',
                );
            } else {
                setState('review');
                setMessage(
                    'The retry outcome was not confirmed. Refresh current delivery state before trying again.',
                );
            }
        } finally {
            if (request.current === controller) request.current = null;
        }
    };

    return (
        <div className="flex shrink-0 flex-col items-start gap-2 lg:items-end">
            {state === 'idle' || state === 'pending' ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={state === 'pending'}
                    onClick={() => void retry()}
                >
                    <RefreshCw className="h-4 w-4" aria-hidden="true" />{' '}
                    {state === 'pending'
                        ? 'Retrying delivery…'
                        : 'Retry delivery'}
                </Button>
            ) : null}
            {message ? (
                <p
                    role={state === 'confirmed' ? 'status' : 'alert'}
                    className={
                        state === 'confirmed'
                            ? 'max-w-sm text-xs text-status-success'
                            : 'max-w-sm text-xs text-status-critical'
                    }
                >
                    {message}
                </p>
            ) : null}
            {state === 'confirmed' ||
            state === 'review' ||
            state === 'unavailable' ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={refreshing.current}
                    onClick={() => refresh()}
                >
                    {refreshing.current
                        ? 'Refreshing current state…'
                        : 'Refresh current state'}
                </Button>
            ) : null}
        </div>
    );
}

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
    const actorId =
        usePage<{
            auth?: { user?: { id?: number } | null };
        }>().props.auth?.user?.id ?? null;
    const failures = deliveries.filter((delivery) =>
        ['failed', 'bounced'].includes(delivery.status),
    );
    const mailboxVisible =
        audit.mailbox_health?.viewer_user_id === actorId &&
        audit.mailbox_health.can_view;

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
                        value={
                            mailboxVisible && audit.mailbox_health?.available
                                ? `${audit.email.connected}/${audit.email.connections} connected`
                                : 'Mailbox details unavailable'
                        }
                        issues={
                            (mailboxVisible
                                ? (audit.email.connection_errors ?? 0)
                                : 0) + audit.email.failed_or_bounced
                        }
                        detail={
                            mailboxVisible
                                ? 'connection or delivery failures'
                                : 'permitted delivery failures'
                        }
                    />
                    <AuditCard
                        icon={Braces}
                        title="API identities"
                        value={`${audit.api.active}/${audit.api.identities} active`}
                        issues={audit.api.request_errors}
                        detail="recorded request errors"
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

            <ItChannelHealth
                mailbox={audit.mailbox_health}
                delivery={audit.delivery_health}
                viewerId={actorId}
            />
            <ItApiOperations health={audit.api_health} viewerId={actorId} />

            <section
                aria-label="Email delivery"
                id="deliveries"
                className="overflow-hidden rounded-2xl border border-border bg-card"
            >
                <div className="border-b border-border px-5 py-4">
                    <h2 className="font-semibold">Email delivery</h2>
                    <p className="text-xs text-muted-foreground">
                        Review each recipient’s delivery status and retry
                        eligible failures.
                    </p>
                </div>
                {deliveries.length ? (
                    <div className="divide-y divide-border/70">
                        {deliveries.map((delivery) => (
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
                                                    : [
                                                            'failed',
                                                            'bounced',
                                                        ].includes(
                                                            delivery.status,
                                                        )
                                                      ? 'critical'
                                                      : delivery.status ===
                                                          'sending'
                                                        ? 'warning'
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
                                    <DeliveryStatusDetail delivery={delivery} />
                                    {delivery.last_error ? (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {delivery.failure_category
                                                ? `${readable(delivery.failure_category)}: `
                                                : ''}
                                            {delivery.last_error}
                                        </p>
                                    ) : null}
                                </div>
                                {delivery.can_retry ? (
                                    <RetryDeliveryControl
                                        delivery={delivery}
                                        actorId={actorId}
                                    />
                                ) : null}
                            </article>
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={MailWarning}
                        title="No outbound IT email yet"
                        description="Queued public-ticket replies will appear here with their provider status."
                        className="m-5"
                    />
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
                                        (
                                            AUTOMATION_FRESHNESS[
                                                definition.freshness?.state ??
                                                    'unmeasured'
                                            ] ?? AUTOMATION_FRESHNESS.unmeasured
                                        ).variant
                                    }
                                    size="sm"
                                >
                                    {
                                        (
                                            AUTOMATION_FRESHNESS[
                                                definition.freshness?.state ??
                                                    'unmeasured'
                                            ] ?? AUTOMATION_FRESHNESS.unmeasured
                                        ).label
                                    }
                                </StatusBadge>
                            </div>
                            <dl className="mt-3 space-y-1 text-xs text-muted-foreground">
                                <div className="flex justify-between gap-3">
                                    <dt>Last successful check</dt>
                                    <dd className="text-right text-foreground">
                                        {stamp(
                                            definition.freshness
                                                ?.last_success_at ?? null,
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Health checked</dt>
                                    <dd className="text-right text-foreground">
                                        {stamp(
                                            definition.freshness
                                                ?.evaluated_at ?? null,
                                        )}
                                    </dd>
                                </div>
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
                                            ? definition.overlap_minutes === 10
                                                ? 'On · 10 minute expiry'
                                                : 'On'
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
                {audit.attachment_cleanup ? (
                    <AttachmentCleanupEvidence
                        health={audit.attachment_cleanup}
                        runs={automationRuns}
                    />
                ) : null}
                <ItAutomationHistory
                    health={audit.automation_history}
                    viewerId={actorId}
                />
            </section>
        </div>
    );
}

function AttachmentCleanupEvidence({
    health,
    runs,
}: {
    health: NonNullable<OperationsAudit['attachment_cleanup']>;
    runs: AutomationRunRow[];
}) {
    const { auth } = usePage<{
        auth?: {
            user?: { id: number } | null;
            can?: { it?: { manage?: boolean }; audit?: { viewAny?: boolean } };
        };
    }>().props;
    const permitted =
        health.can_view_counts &&
        health.viewer_user_id === auth?.user?.id &&
        auth?.can?.it?.manage === true &&
        auth?.can?.audit?.viewAny === true;
    const counts =
        permitted &&
        health.counts &&
        Object.values(health.counts).every(
            (value) => Number.isSafeInteger(value) && value >= 0,
        )
            ? health.counts
            : null;
    const run = permitted
        ? runs.find(
              (item) =>
                  item.automation_key === 'it.retry-attachment-cleanup' &&
                  item.cleanup,
          )
        : undefined;
    const batch = run?.cleanup?.counts;
    const validBatch =
        batch &&
        Object.values(batch).every(
            (value) => Number.isSafeInteger(value) && value >= 0,
        )
            ? batch
            : null;
    return (
        <section
            aria-label="Attachment cleanup evidence"
            className="border-t border-border px-5 py-4"
        >
            <div className="flex flex-wrap items-center gap-2">
                <ShieldCheck className="size-4" aria-hidden="true" />
                <h3 className="font-semibold">Attachment cleanup</h3>
                <StatusBadge
                    size="sm"
                    variant={health.readiness === 'ready' ? 'info' : 'warning'}
                >
                    {health.readiness === 'ready'
                        ? 'Storage records available'
                        : health.readiness === 'not_ready'
                          ? 'Storage setup incomplete'
                          : 'Storage evidence unavailable'}
                </StatusBadge>
            </div>
            <p className="text-caption mt-2">
                Recovery covers confirmed rollbacks and temporary copies of
                accepted email attachments. Interrupted mailbox copies are
                checked against their original writer before cleanup.
                Quarantined files are retained; unclassified reservations may
                still belong to active or uncertain work.
            </p>
            {!permitted ? (
                <p className="text-subtle mt-2">
                    Cleanup counts require IT management and audit access.
                </p>
            ) : counts ? (
                <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt className="text-caption">Pending cleanup</dt>
                        <dd className="font-semibold">
                            {counts.cleanup_pending}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption">
                            Unclassified reservations
                        </dt>
                        <dd className="font-semibold">
                            {counts.reserved_unclassified}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption">Needs reconciliation</dt>
                        <dd className="font-semibold">
                            {counts.reconciliation_required}
                        </dd>
                    </div>
                </dl>
            ) : (
                <p className="text-subtle mt-2">
                    Cleanup counts are unavailable.
                </p>
            )}
            {permitted && run ? (
                <div className="text-subtle mt-3">
                    <p>
                        Latest shown batch · {stamp(run.finished_at)} ·{' '}
                        {readable(run.status)}
                    </p>
                    {validBatch ? (
                        <p>
                            {validBatch.deleted} deleted · {validBatch.failed}{' '}
                            failed · {validBatch.deferred} deferred ·{' '}
                            {validBatch.reconciliation_required} need
                            reconciliation
                        </p>
                    ) : (
                        <p>Verified batch counts are unavailable.</p>
                    )}
                </div>
            ) : null}
            <p className="text-caption mt-2">
                Checked {stamp(health.checked_at)}. A retry never authorizes
                removal of unknown files.
            </p>
        </section>
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
