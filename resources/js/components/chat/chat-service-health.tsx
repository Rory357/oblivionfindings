import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';
import { CircleAlert, CircleCheck, CircleHelp } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ChatError } from './api';
import { chatActorRequest } from './chat-actor-request';

const services = {
    messaging: 'Messaging',
    realtime: 'Realtime connection',
    push: 'Push notifications',
    uploads: 'Uploads and scanning',
    calling: 'Voice connections',
} as const;
const states = {
    passed: 'Check passed',
    partial: 'Partly checked',
    unavailable: 'Needs attention',
    not_configured: 'Not configured',
    not_available: 'Not available',
    disabled: 'Disabled',
} as const;
type HealthRow = {
    id: keyof typeof services;
    state: keyof typeof states;
    detail: string;
    owner: string | null;
    checked_at: string;
    trend: 'changed' | 'unchanged' | 'unknown';
    compared_at: string | null;
};
type HealthSnapshot = {
    checked_at: string;
    rollout_enabled: boolean;
    services: HealthRow[];
};
function validSnapshot(value: HealthSnapshot): boolean {
    return (
        !!value &&
        typeof value.rollout_enabled === 'boolean' &&
        Number.isFinite(Date.parse(value.checked_at)) &&
        Array.isArray(value.services) &&
        value.services.length === Object.keys(services).length &&
        new Set(value.services.map((row) => row.id)).size ===
            Object.keys(services).length &&
        value.services.every(
            (row) =>
                row &&
                Object.hasOwn(services, row.id) &&
                Object.hasOwn(states, row.state) &&
                typeof row.detail === 'string' &&
                (row.owner === null || typeof row.owner === 'string') &&
                Number.isFinite(Date.parse(row.checked_at)) &&
                ['changed', 'unchanged', 'unknown'].includes(row.trend) &&
                (row.compared_at === null ||
                    Number.isFinite(Date.parse(row.compared_at))),
        )
    );
}

export function ChatServiceHealth({ actorId }: { actorId: number }) {
    return <ServiceHealthSession key={actorId} actorId={actorId} />;
}
function ServiceHealthSession({ actorId }: { actorId: number }) {
    const [snapshot, setSnapshot] = useState<HealthSnapshot | null>(null);
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState('');
    const [clock, setClock] = useState(Date.now);
    const pending = useRef<AbortController | null>(null);
    useEffect(() => {
        const timer = setInterval(() => setClock(Date.now()), 30000);
        return () => {
            clearInterval(timer);
            pending.current?.abort();
            pending.current = null;
        };
    }, []);
    async function refresh() {
        if (pending.current) return;
        const controller = new AbortController();
        pending.current = controller;
        setChecking(true);
        setError('');
        try {
            const next = await chatActorRequest<HealthSnapshot>(
                actorId,
                '/settings/chat/service-health',
                {
                    method: 'POST',
                    body: '{}',
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(20000),
                    ]),
                },
            );
            if (pending.current !== controller || controller.signal.aborted)
                return;
            if (!validSnapshot(next))
                throw new Error(
                    'The service check returned an incomplete result. Try again.',
                );
            setSnapshot(next);
            setClock(Date.now());
        } catch (failure) {
            if (pending.current !== controller || controller.signal.aborted)
                return;
            if (
                failure instanceof ChatError &&
                [401, 403, 419].includes(failure.status)
            ) {
                setSnapshot(null);
                setError('You no longer have access to Chat service health.');
            } else
                setError(
                    'The service check could not finish. Try again. Previous results, if shown, are not a new check.',
                );
        } finally {
            if (pending.current === controller) {
                pending.current = null;
                setChecking(false);
            }
        }
    }
    const stale =
        snapshot && clock - Date.parse(snapshot.checked_at) > 5 * 60000;
    return (
        <section
            aria-label="Chat service health"
            aria-busy={checking}
            className="space-y-4"
        >
            <p className="text-sm text-muted-foreground">
                Shared services across enabled sites. These checks do not change
                policy, send messages or start calls.
            </p>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p role="status" className="text-sm">
                    {checking
                        ? 'Checking services…'
                        : snapshot
                          ? `Snapshot checked ${formatDateTime(snapshot.checked_at)}.${stale ? ' This snapshot is over five minutes old.' : ''}`
                          : 'No service check has run in this view.'}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px]"
                    disabled={checking}
                    onClick={() => void refresh()}
                >
                    {checking ? 'Checking…' : 'Check services'}
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {snapshot && !snapshot.rollout_enabled && (
                <p className="rounded-md border bg-muted p-3 text-sm">
                    Shared Chat rollout is disabled. Passing a service check
                    does not enable it.
                </p>
            )}
            <div className="grid min-w-0 gap-4 md:grid-cols-2">
                {Object.entries(services).map(([id, name]) => {
                    const row = snapshot?.services.find(
                        (item) => item.id === id,
                    );
                    const Icon =
                        row?.state === 'passed'
                            ? CircleCheck
                            : row?.state === 'unavailable'
                              ? CircleAlert
                              : CircleHelp;
                    return (
                        <Card
                            key={id}
                            className="min-w-0 gap-3 p-4"
                            aria-label={name}
                        >
                            <h2 className="font-semibold">{name}</h2>
                            <Badge
                                variant="secondary"
                                className={
                                    row?.state === 'passed'
                                        ? 'w-fit gap-2 bg-status-success/10 text-status-success'
                                        : row?.state === 'unavailable'
                                          ? 'w-fit gap-2 bg-status-critical/10 text-status-critical'
                                          : 'w-fit gap-2'
                                }
                            >
                                <Icon
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {row ? states[row.state] : 'Not checked'}
                            </Badge>
                            <p className="text-sm">
                                Trend:{' '}
                                {row?.trend === 'changed'
                                    ? 'Result changed since the previous check'
                                    : row?.trend === 'unchanged'
                                      ? 'Same result as the previous check'
                                      : 'No recent comparison available'}
                                {row?.compared_at &&
                                    ` (${formatDateTime(row.compared_at)})`}
                                .
                            </p>
                            <p className="text-sm">
                                Owner: {row?.owner || 'Not assigned'}
                            </p>
                            {row && (
                                <p className="text-xs text-muted-foreground">
                                    Updated {formatDateTime(row.checked_at)} ·
                                    Affects enabled services only
                                </p>
                            )}
                            {row ? (
                                <details className="text-sm">
                                    <summary className="min-h-[44px] cursor-pointer py-3 font-medium">
                                        Check details
                                    </summary>
                                    <p className="pb-2 text-muted-foreground">
                                        {row.detail}
                                    </p>
                                </details>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Run a service check to see the current
                                    result.
                                </p>
                            )}
                        </Card>
                    );
                })}
            </div>
            <p className="text-xs text-muted-foreground">
                Trend compares the last recorded check within one hour, not
                delivery speed or call quality. Service owners are configured by
                the system administrator.
            </p>
        </section>
    );
}
