/**
 * Setup › Hubs & networks › Server check (S&D rebuild C6, plan "Keep, move"): today's Settings ›
 * Server readiness as one card, for admins (settings.access.manage). The live checks load after
 * the page (a deferred prop), so they never hold Setup up.
 *
 * Below it, the old Monitoring page's runtime health (plan: "Hub/queue health → Setup › Hubs › Server
 * check (admins)"): the monitoring service's heartbeat, work waiting, failed deliveries and the outage
 * watchdog, in plain words and loaded on its own. Failed deliveries can be tried again or stopped, with
 * a confirm, by people who manage connected services, as on the old page.
 */
import { DeferredPanel } from '@/components/deferred-panel';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { confirmedRecovery, type RecoveryAttempt } from '@/components/monitoring/delivery-recovery-data';
import { PopupConfirm, PopupField } from '@/components/popup';
import { FactRows, KnowList, QuietLine, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import type { ServerReadinessPayload } from '@/pages/settings/server-readiness';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ArchiveX, Home, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

export function ServerCheck() {
    return (
        <div className="space-y-5">
            <DeferredPanel<{ server_check: ServerReadinessPayload }>
                data="server_check"
                label="Server check"
                fallback={<Skeleton className="h-40 w-full rounded-xl" aria-label="Checking the server" />}
            >
                {({ server_check }) => <ServerCheckCard data={server_check} />}
            </DeferredPanel>
            <DeferredPanel<{ server_health: ServerHealthPayload }>
                data="server_health"
                label="Monitoring service"
                fallback={<Skeleton className="h-40 w-full rounded-xl" aria-label="Checking the monitoring service" />}
            >
                {({ server_health }) => <MonitoringHealthCard data={server_health} />}
            </DeferredPanel>
            <DeferredPanel<{ server_readiness: ServerChecksSites }>
                data="server_readiness"
                label="Sites the server checks itself"
                fallback={<Skeleton className="h-40 w-full rounded-xl" aria-label="Checking which Sites the server checks itself" />}
            >
                {({ server_readiness }) => <SitesServerChecks data={server_readiness} />}
            </DeferredPanel>
        </div>
    );
}

export function ServerCheckCard({ data }: { data: ServerReadinessPayload }) {
    const [checking, setChecking] = useState(false);
    const again = () => {
        if (checking) return;
        setChecking(true);
        router.reload({ only: ['server_check'], onFinish: () => setChecking(false) });
    };

    const failing = data.checks.filter((check) => check.status === 'fail');
    const passing = data.checks.filter((check) => check.status === 'pass');
    const sub =
        data.overall_status === 'pass'
            ? `All ${data.total_count} checks pass · checked ${formatDateTime(data.checked_at)}`
            : `${data.failed_count} of ${data.total_count} ${data.failed_count === 1 ? 'check needs' : 'checks need'} attention · checked ${formatDateTime(data.checked_at)}`;

    return (
        <FactRows
            title="Server check"
            sub={sub}
            action={{ label: checking ? 'Checking…' : 'Check again', onClick: again }}
            aria-label="Server check"
            rows={[...failing, ...passing].map((check) => [
                check.title,
                <span key={check.key} className="flex flex-col gap-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant={check.status === 'pass' ? 'success' : 'critical'}>{check.status === 'pass' ? 'Working' : 'Needs fixing'}</StatusBadge>
                        <span>{check.summary}</span>
                    </span>
                    {check.status === 'fail' && check.fix ? <span className="text-muted-foreground">{check.fix}</span> : null}
                </span>,
            ])}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  The monitoring service (the old Monitoring page's runtime health)   */
/* ------------------------------------------------------------------ */

export type HealthLine = { tone: StatusVariant; label: string; summary: string };

export type FailedDelivery = {
    id: number;
    review_token: string;
    site_id: number | null;
    where: string;
    what: string;
    why: string;
    reference: string;
    at: string | null;
    tries: number;
    can_retry: boolean;
    can_stop: boolean;
    retrying: boolean;
};

export type ServerHealthPayload = {
    checked_at: string;
    actor_id: number;
    service: HealthLine;
    waiting: HealthLine;
    watchdog: HealthLine;
    failed: HealthLine & { can_act: boolean; rows: FailedDelivery[]; truncated: boolean };
};

function Line({ line }: { line: HealthLine }) {
    return (
        <span className="flex flex-wrap items-center gap-2">
            <StatusBadge variant={line.tone}>{line.label}</StatusBadge>
            <span>{line.summary}</span>
        </span>
    );
}

export function MonitoringHealthCard({ data }: { data: ServerHealthPayload }) {
    const [checking, setChecking] = useState(false);
    const [decision, setDecision] = useState<{ row: FailedDelivery; kind: 'replay' | 'discard' } | null>(null);
    const again = () => {
        if (checking) return;
        setChecking(true);
        router.reload({ only: ['server_health'], onFinish: () => setChecking(false) });
    };
    const attention = [data.service, data.waiting, data.failed, data.watchdog].filter((line) => line.tone === 'critical' || line.tone === 'warning').length;

    return (
        <>
            <FactRows
                title="Monitoring service"
                sub={`${attention === 0 ? 'Nothing needs you' : `${attention} ${attention === 1 ? 'thing needs' : 'things need'} attention`} · checked ${formatDateTime(data.checked_at)}`}
                action={{ label: checking ? 'Checking…' : 'Check again', onClick: again }}
                aria-label="Monitoring service"
                rows={[
                    ['Heartbeat', <Line key="service" line={data.service} />],
                    ['Work waiting', <Line key="waiting" line={data.waiting} />],
                    [
                        'Failed deliveries',
                        <span key="failed" className="flex flex-col gap-2">
                            <Line line={data.failed} />
                            {data.failed.can_act && data.failed.rows.length > 0 ? (
                                <ul aria-label="Failed deliveries" className="grid gap-2">
                                    {data.failed.rows.map((row) => (
                                        <li key={row.id} className="flex flex-wrap items-start justify-between gap-2 rounded-[10px] border border-border px-3 py-2">
                                            <span className="grid min-w-0 gap-0.5">
                                                <span className="font-medium text-foreground">
                                                    {row.what} · {row.where}
                                                </span>
                                                <span className="text-caption">
                                                    {row.why}
                                                    {row.at ? ` · ${formatDateTime(row.at)}` : ''}
                                                    {row.tries > 0 ? ` · tried again ${row.tries} ${row.tries === 1 ? 'time' : 'times'}` : ''}
                                                </span>
                                            </span>
                                            {row.retrying ? (
                                                <StatusBadge variant="info">Trying again</StatusBadge>
                                            ) : (
                                                <span className="flex flex-wrap gap-2">
                                                    {row.can_retry ? (
                                                        <Button type="button" variant="outline" size="sm" onClick={() => setDecision({ row, kind: 'replay' })}>
                                                            <RefreshCw className="size-4" />
                                                            Try again
                                                        </Button>
                                                    ) : null}
                                                    {row.can_stop ? (
                                                        <Button type="button" variant="ghost" size="sm" onClick={() => setDecision({ row, kind: 'discard' })}>
                                                            <ArchiveX className="size-4" />
                                                            Stop trying
                                                        </Button>
                                                    ) : null}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                            {data.failed.truncated ? <span className="text-caption">Showing the newest {data.failed.rows.length}. Older ones show here as these are dealt with.</span> : null}
                        </span>,
                    ],
                    ['Outage watchdog', <Line key="watchdog" line={data.watchdog} />],
                ]}
            />
            <DeliveryDecision
                open={decision !== null}
                decision={decision}
                actorId={data.actor_id}
                onClose={() => setDecision(null)}
                onDone={() => {
                    setDecision(null);
                    router.reload({ only: ['server_health'] });
                }}
            />
        </>
    );
}

type DecisionState = 'idle' | 'busy' | 'unconfirmed';

/**
 * Try a failed delivery again, or stop trying it (today's replay and discard, same keys and checks).
 * One request number per opening: after a lost answer, "Check what happened" asks for that request
 * instead of sending a second one.
 */
export function DeliveryDecision({
    open,
    decision,
    actorId,
    onClose,
    onDone,
}: {
    open: boolean;
    decision: { row: FailedDelivery; kind: 'replay' | 'discard' } | null;
    actorId: number;
    onClose: () => void;
    onDone: () => void;
}) {
    const [why, setWhy] = useState('');
    const [state, setState] = useState<DecisionState>('idle');
    const [error, setError] = useState<string | undefined>(undefined);
    const [fieldError, setFieldError] = useState<string | undefined>(undefined);
    const uuid = useMemo(() => (open && decision && typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : ''), [open, decision]);

    useEffect(() => {
        if (!open) return;
        setWhy('');
        setState('idle');
        setError(undefined);
        setFieldError(undefined);
    }, [open, decision]);

    if (!decision) return null;
    const { row, kind } = decision;
    const attempt: RecoveryAttempt = { uuid, actor: actorId, site: row.site_id, letter: row.id, operation: kind };
    const finished = () => {
        toast.success(kind === 'replay' ? 'Asked to try it again. Check again in a minute to see it clear.' : 'Stopped trying it. The original stays on record with your reason.');
        onDone();
    };

    const send = async () => {
        if (state === 'busy') return;
        if (state === 'unconfirmed') {
            setState('busy');
            try {
                const response = await axios.post<unknown>(
                    `/security-devices/monitoring/dead-letters/requests/${uuid}/recover`,
                    { expected_actor_id: actorId, expected_site_id: row.site_id },
                    { headers: { Accept: 'application/json' }, timeout: 30000 },
                );
                confirmedRecovery(response.data, attempt);
                finished();
            } catch {
                setError('Still not confirmed. It may yet finish: check again on this card in a minute. Nothing was sent twice.');
                setState('unconfirmed');
            }
            return;
        }
        const reason = why.trim();
        if (reason.length < 3 || reason.length > 500) {
            setFieldError('Say why in 3 to 500 characters.');
            return;
        }
        if (uuid === '') {
            setError('This browser can’t make a request number. Try another browser.');
            return;
        }
        setState('busy');
        setError(undefined);
        setFieldError(undefined);
        try {
            const response = await axios.post<unknown>(
                `/security-devices/monitoring/dead-letters/${row.id}/${kind}`,
                { request_uuid: uuid, expected_actor_id: actorId, expected_site_id: row.site_id, review_token: row.review_token, reason },
                { headers: { Accept: 'application/json' }, timeout: 30000 },
            );
            confirmedRecovery(response.data, attempt);
            finished();
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            if (status === 422) {
                const message = axios.isAxiosError(failure) ? (failure.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors?.reason?.[0] : undefined;
                setFieldError(typeof message === 'string' ? message.slice(0, 500) : 'Check what you wrote.');
                setState('idle');
            } else if (status === 401 || status === 403 || status === 404) {
                setError('You can’t do this any more, or it has gone. Close this and check again.');
                setState('idle');
            } else if (status === 409) {
                setError('It changed since this card was checked. Close this and check again.');
                setState('idle');
            } else {
                setError('Not confirmed. It may have been saved: check what happened before doing anything else.');
                setState('unconfirmed');
            }
        }
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => void send()}
            variant={kind === 'replay' ? 'plain' : 'warning'}
            icon={kind === 'replay' ? RefreshCw : ArchiveX}
            title={kind === 'replay' ? 'Try this delivery again?' : 'Stop trying this delivery?'}
            description={
                kind === 'replay'
                    ? 'The monitoring service processes the original message again. It never repeats a device action.'
                    : 'It won’t be processed. The original stays on record with your reason.'
            }
            context={{ title: `${row.what} · message ${row.reference}`, tag: row.where, note: row.why }}
            error={error}
            confirmLabel={state === 'unconfirmed' ? 'Check what happened' : kind === 'replay' ? 'Try again' : 'Stop trying'}
            confirmDestructive={kind === 'discard' && state !== 'unconfirmed'}
            processing={state === 'busy'}
            processingLabel={kind === 'replay' ? 'Asking…' : 'Stopping…'}
            testId="sd-delivery-decision"
        >
            <PopupField label="Why" htmlFor="sd-delivery-why" required hint="Keep passwords and personal details out of it" error={fieldError}>
                <Textarea
                    id="sd-delivery-why"
                    rows={3}
                    maxLength={500}
                    value={why}
                    disabled={state !== 'idle'}
                    onChange={(event) => {
                        setWhy(event.target.value);
                        setFieldError(undefined);
                    }}
                />
            </PopupField>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Sites the server checks itself (pg-sdr-15q)                         */
/* ------------------------------------------------------------------ */

type ReadinessTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

export type ServerChecksSites = {
    rows: {
        site: { id: number; name: string; href: string };
        checked_by: string;
        state: { label: string; tone: ReadinessTone; note: string };
        devices: number;
        next: string;
    }[];
    meanings: { tone: ReadinessTone; text: string }[];
};

type ReadinessRow = ServerChecksSites['rows'][number];

const READINESS_RING: Record<ReadinessTone, SgTone> = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'none' };

/** The retired Monitoring page's Central Site readiness, in plain words: whether the server checks each Site itself. */
export function SitesServerChecks({ data }: { data: ServerChecksSites }) {
    const menu = useEntityContextMenu<ReadinessRow>();
    const [checking, setChecking] = useState(false);
    const again = () => {
        if (checking) return;
        setChecking(true);
        router.reload({ only: ['server_readiness'], onFinish: () => setChecking(false) });
    };
    const open = (row: ReadinessRow) => router.visit(row.site.href);
    const actionsFor = (row: ReadinessRow): MenuItem[] => [{ label: 'Open the Site', onClick: () => open(row) }];
    const bold = (text: string) => text.split(/\*\*(.+?)\*\*/g).map((part, index) => (index % 2 === 1 ? <b key={index}>{part}</b> : part));

    return (
        <div className="space-y-5" aria-label="Sites the server checks itself">
            <ListCaption
                title="Sites the server checks itself"
                caption="Admins only, like the server check · a Site with a hub is always checked by its hub"
                right={
                    <Button size="sm" variant="outline" onClick={again} disabled={checking}>
                        {checking ? 'Checking…' : 'Check again'}
                    </Button>
                }
            />
            {data.rows.length === 0 ? (
                <QuietLine text="No Sites to show. Sites appear here once you can see them in Security & devices." />
            ) : (
                <EntityTable<ReadinessRow>
                    rows={data.rows}
                    rowKey={(row) => row.site.id}
                    identityLabel="Site"
                    identity={(row) => ({ icon: Home, name: row.site.name, linkLabel: `Open ${row.site.name}` })}
                    columns={[
                        { key: 'by', label: 'Checked by', width: '1.1fr', cell: (row) => row.checked_by },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.6fr',
                            cell: (row) => (
                                <span className="flex flex-col items-start gap-1">
                                    <StatusBadge variant={row.state.tone}>{row.state.label}</StatusBadge>
                                    <span className="text-caption">{row.state.note}</span>
                                </span>
                            ),
                        },
                        { key: 'devices', label: 'Devices', width: '0.6fr', cell: (row) => (row.devices > 0 ? row.devices : <span className="text-muted-foreground">—</span>) },
                        { key: 'next', label: 'Next step', width: '1.8fr', cell: (row) => row.next },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={open}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    minWidth={900}
                />
            )}
            {menu.ctx ? <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={Home} title={menu.ctx.record.site.name} items={actionsFor(menu.ctx.record)} onClose={menu.close} /> : null}
            <KnowList title="What each state means" items={data.meanings.map((line) => ({ tone: READINESS_RING[line.tone], text: bold(line.text) }))} />
        </div>
    );
}
