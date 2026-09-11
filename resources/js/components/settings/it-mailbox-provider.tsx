import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    MAILBOX_LABELS,
    validMailboxConnection,
    validMailboxConnections,
    type MailboxConnection,
    type MailboxProvider,
} from './it-mailbox-contract';

type Operation = 'save' | 'disconnect' | 'poll';
type Failure = 'validation' | 'conflict' | 'unknown' | null;

export function MailboxProviderPanel({
    provider,
    initial,
    onSaved,
    onDirtyChange,
    onAccessLost,
}: {
    provider: MailboxProvider;
    initial: MailboxConnection;
    onSaved: (connection: MailboxConnection) => void;
    onDirtyChange: (dirty: boolean) => void;
    onAccessLost: () => void;
}) {
    const [saved, setSaved] = useState(initial);
    const [draft, setDraft] = useState(initial.mailbox_email ?? '');
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<Failure>(null);
    const [message, setMessage] = useState('');
    const [fieldError, setFieldError] = useState('');
    const [review, setReview] = useState<MailboxConnection | null>(null);
    const [confirm, setConfirm] = useState<'disconnect' | 'discard' | null>(
        null,
    );
    const abort = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const inFlight = useRef(false);
    const alert = useRef<HTMLDivElement>(null);
    const edited = draft.trim() !== (saved.mailbox_email ?? '');
    const dirty = edited || pending || failure === 'unknown';
    useEffect(() => {
        onDirtyChange(dirty);
    }, [dirty, onDirtyChange]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            abort.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (message) alert.current?.focus();
    }, [message]);

    function fail(error: unknown, reading = false) {
        const status = axios.isAxiosError(error)
            ? error.response?.status
            : undefined;
        if ([401, 403, 419].includes(status ?? 0)) {
            onAccessLost();
            return;
        }
        if (status === 422 && !reading) {
            const value = axios.isAxiosError(error)
                ? error.response?.data?.errors?.mailbox_email
                : null;
            setFieldError(
                Array.isArray(value) && typeof value[0] === 'string'
                    ? value[0]
                    : 'Review the mailbox and connection details.',
            );
            setFailure('validation');
            setMessage(
                'The mailbox was not saved. Your entry is retained for review.',
            );
        } else {
            setFailure(status === 409 ? 'conflict' : 'unknown');
            setMessage(
                reading
                    ? 'Current mailbox state could not be loaded. Retry the read before making another change.'
                    : status === 409
                      ? 'The connection changed or is busy. Review its current saved state before trying again.'
                      : 'The operation outcome is unknown. Reload the saved state before retrying. Stopping the wait does not undo an operation already sent.',
            );
        }
    }
    async function run(operation: Operation) {
        if (
            inFlight.current ||
            (failure && failure !== 'validation') ||
            saved.id === null
        )
            return;
        inFlight.current = true;
        const controller = new AbortController();
        abort.current = controller;
        setPending(true);
        setMessage('');
        setFieldError('');
        setFailure(null);
        setReview(null);
        try {
            const data = {
                connection_id: saved.id,
                expected_version: saved.version,
                ...(operation === 'save'
                    ? { mailbox_email: draft.trim() || null }
                    : {}),
                ...(operation === 'poll' ? { provider } : {}),
            };
            const response = await axios.request({
                method:
                    operation === 'save'
                        ? 'put'
                        : operation === 'disconnect'
                          ? 'delete'
                          : 'post',
                url:
                    operation === 'save'
                        ? `/settings/it-mailbox/mailbox/${provider}`
                        : operation === 'disconnect'
                          ? `/settings/it-mailbox/connect/${provider}`
                          : '/settings/it-mailbox/poll-now',
                data,
                signal: controller.signal,
                timeout: 45000,
                headers: { Accept: 'application/json' },
            });
            const connection = response.data?.connection;
            if (
                !validMailboxConnection(connection) ||
                response.data?.status !==
                    {
                        save: 'saved',
                        disconnect: 'disconnected',
                        poll: 'requested',
                    }[operation] ||
                (operation === 'disconnect'
                    ? connection.id !== null
                    : connection.id !== saved.id ||
                      connection.version !==
                          (saved.version ?? 0) + (operation === 'save' ? 1 : 0))
            )
                throw new Error('Unconfirmed operation response.');
            if (!alive.current) return;
            setSaved(connection);
            onSaved(connection);
            if (operation !== 'poll') setDraft(connection.mailbox_email ?? '');
            setMessage(
                operation === 'save'
                    ? 'Mailbox saved after a provider read-access check. A completed poll remains unverified.'
                    : operation === 'disconnect'
                      ? 'Mailbox disconnected. Existing tickets and inbound records are retained.'
                      : 'Poll requested. Review the recorded result; a request is not proof that every message was processed.',
            );
        } catch (error) {
            if (alive.current) fail(error);
        } finally {
            inFlight.current = false;
            if (alive.current) setPending(false);
        }
    }
    async function reload() {
        if (inFlight.current) return;
        inFlight.current = true;
        setPending(true);
        setMessage('');
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.get('/settings/it-mailbox', {
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            if (!validMailboxConnections(response.data?.connections))
                throw new Error('Invalid mailbox state.');
            if (!alive.current) return;
            const current = response.data.connections[provider];
            if (edited || failure) {
                setReview(current);
                setMessage(
                    'Current saved state loaded. Review it, then use the saved mailbox to continue.',
                );
            } else {
                setSaved(current);
                onSaved(current);
                setDraft(current.mailbox_email ?? '');
                setMessage('Current mailbox state loaded.');
            }
        } catch (error) {
            if (alive.current) fail(error, true);
        } finally {
            inFlight.current = false;
            if (alive.current) setPending(false);
        }
    }
    function adoptCurrent() {
        if (!review) return;
        setSaved(review);
        onSaved(review);
        setDraft(review.mailbox_email ?? '');
        setReview(null);
        setFailure(null);
        setFieldError('');
        setMessage(
            'Using the current saved mailbox. Review it before sending another operation.',
        );
    }
    const frozen = pending || !!(failure && failure !== 'validation');
    const statusLabel =
        saved.id === null
            ? 'Not connected'
            : saved.operation_active
              ? 'Operation in progress'
              : saved.authorization_required
                ? 'Reconnect required'
                : saved.last_error
                  ? 'Needs attention'
                  : saved.last_polled_at
                    ? 'Last poll completed'
                    : 'Authorization stored';

    return (
        <div className="space-y-4">
            {pending && (
                <p role="status" className="text-subtle">
                    Waiting for the server. Stopping the wait does not undo an
                    operation already sent.
                </p>
            )}
            {message && (
                <Alert ref={alert} tabIndex={-1} role="status">
                    <AlertTitle>
                        {failure ? 'Review required' : 'Mailbox update'}
                    </AlertTitle>
                    <AlertDescription>{message}</AlertDescription>
                </Alert>
            )}
            {review && (
                <Card>
                    <CardHeader>
                        <CardTitle>Current saved connection</CardTitle>
                        <CardDescription>
                            Using this state replaces your unsaved mailbox
                            entry.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Fact
                                label="Account"
                                value={review.account_email ?? 'Not connected'}
                            />
                            <Fact
                                label="Mailbox"
                                value={review.effective_mailbox ?? 'None'}
                            />
                            <Fact
                                label="Version"
                                value={review.version?.toString() ?? 'None'}
                            />
                            <Fact
                                label="Retry time"
                                value={formatDateTime(
                                    review.next_poll_at,
                                    'No retry delay',
                                )}
                            />
                        </dl>
                        <Button
                            type="button"
                            onClick={adoptCurrent}
                            disabled={pending}
                        >
                            Use saved mailbox
                        </Button>
                    </CardContent>
                </Card>
            )}
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1">
                            <CardTitle>{MAILBOX_LABELS[provider]}</CardTitle>
                            <CardDescription>
                                {provider === 'microsoft'
                                    ? 'Use the connected account or a mailbox it is authorized to read.'
                                    : 'Gmail reads the connected support account’s own inbox.'}
                            </CardDescription>
                        </div>
                        <StatusBadge
                            variant={saved.last_error ? 'warning' : 'neutral'}
                        >
                            {statusLabel}
                        </StatusBadge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-5">
                    <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        <Fact
                            label="Connected account"
                            value={saved.account_email ?? 'Not connected'}
                        />
                        <Fact
                            label="Effective support mailbox"
                            value={saved.effective_mailbox ?? 'Not selected'}
                        />
                        <Fact
                            label="Last completed poll"
                            value={formatDateTime(
                                saved.last_polled_at,
                                'Not verified',
                            )}
                        />
                        <Fact
                            label="Last poll attempt"
                            value={formatDateTime(
                                saved.last_poll_attempt_at,
                                'No attempt recorded',
                            )}
                        />
                        <Fact
                            label="Next permitted retry"
                            value={formatDateTime(
                                saved.next_poll_at,
                                'No retry delay',
                            )}
                        />
                        <Fact
                            label="Discovery"
                            value={
                                saved.scan_pending
                                    ? 'A captured inbox scan is pending'
                                    : 'No unfinished scan recorded'
                            }
                        />
                    </dl>
                    {saved.last_error && (
                        <Alert>
                            <AlertTitle>
                                Mailbox processing needs attention
                            </AlertTitle>
                            <AlertDescription>
                                {saved.last_error}
                            </AlertDescription>
                        </Alert>
                    )}
                    <p className="text-subtle">
                        These counts cover the connected account and its current
                        support mailbox.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Count
                            label="Awaiting processing"
                            value={saved.counts.awaiting_processing}
                        />
                        <Count
                            label="Awaiting acknowledgement"
                            value={saved.counts.awaiting_acknowledgement}
                        />
                        <Count
                            label="Quarantined"
                            value={saved.counts.quarantined}
                        />
                    </div>
                    {saved.id !== null && provider === 'microsoft' && (
                        <form
                            className="space-y-3 border-t pt-4"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                void run('save');
                            }}
                        >
                            <Label htmlFor="mailbox-address">
                                Support mailbox
                            </Label>
                            <Input
                                id="mailbox-address"
                                type="email"
                                value={draft}
                                onChange={(event) =>
                                    setDraft(event.target.value)
                                }
                                disabled={frozen}
                                aria-invalid={!!fieldError}
                                aria-describedby="mailbox-address-help mailbox-address-error"
                                placeholder={saved.account_email ?? ''}
                            />
                            <p
                                id="mailbox-address-help"
                                className="text-subtle"
                            >
                                Leave blank to use the connected account. Saving
                                checks provider read access before changing the
                                mailbox.
                            </p>
                            <p
                                id="mailbox-address-error"
                                className="text-subtle text-destructive"
                            >
                                {fieldError}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    disabled={frozen || !edited}
                                >
                                    Verify and save mailbox
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={frozen || !edited}
                                    onClick={() => setConfirm('discard')}
                                >
                                    Discard edit
                                </Button>
                            </div>
                        </form>
                    )}
                    <div className="flex flex-wrap gap-2 border-t pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={pending}
                            onClick={() => void reload()}
                        >
                            Reload current state
                        </Button>
                        {saved.id !== null && (
                            <>
                                <Button
                                    type="button"
                                    disabled={
                                        frozen || edited || !saved.can_poll
                                    }
                                    onClick={() => void run('poll')}
                                >
                                    Poll mailbox now
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={frozen || edited}
                                    onClick={() => setConfirm('disconnect')}
                                >
                                    Disconnect
                                </Button>
                            </>
                        )}
                        {saved.configured && (
                            <Button
                                asChild
                                variant="outline"
                                disabled={frozen || edited}
                            >
                                <a
                                    href={`/settings/it-mailbox/connect/${provider}`}
                                    aria-disabled={frozen || edited}
                                    onClick={(event) => {
                                        if (frozen || edited)
                                            event.preventDefault();
                                    }}
                                >
                                    {saved.id === null
                                        ? 'Connect account'
                                        : 'Reconnect account'}
                                </a>
                            </Button>
                        )}
                        {!saved.configured && (
                            <Button asChild variant="outline">
                                <Link href="/settings/sso">
                                    Configure provider in SSO
                                </Link>
                            </Button>
                        )}
                        {pending && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => abort.current?.abort()}
                            >
                                Stop waiting
                            </Button>
                        )}
                    </div>
                    <p className="text-subtle">
                        Polling runs hourly. Retry delays and active operations
                        are respected. Reload to see progress; pending work is
                        retained across polls.
                    </p>
                    {saved.id !== null && !saved.can_poll && (
                        <p className="text-subtle">
                            Polling is unavailable while authorization needs
                            renewal, a retry delay applies, or another mailbox
                            operation is active.
                        </p>
                    )}
                </CardContent>
            </Card>
            <ConfirmDialog
                open={confirm !== null}
                onClose={() => setConfirm(null)}
                title={
                    confirm === 'disconnect'
                        ? 'Disconnect this support mailbox?'
                        : 'Discard this mailbox edit?'
                }
                description={
                    confirm === 'disconnect'
                        ? 'Polling will stop for this connection. Existing tickets and inbound records are retained. Reconnecting later requires the approved account.'
                        : 'The unsaved mailbox entry will be replaced by the current saved value.'
                }
                confirmText={
                    confirm === 'disconnect'
                        ? 'Disconnect mailbox'
                        : 'Discard edit'
                }
                onConfirm={() => {
                    if (confirm === 'disconnect') void run('disconnect');
                    else {
                        setDraft(saved.mailbox_email ?? '');
                        setFieldError('');
                        setFailure(null);
                    }
                }}
            />
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-caption text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-sm font-medium break-words">{value}</dd>
        </div>
    );
}
function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border bg-muted/30 p-3">
            <p className="text-caption text-muted-foreground">{label}</p>
            <p className="text-section-title">{value}</p>
        </div>
    );
}
