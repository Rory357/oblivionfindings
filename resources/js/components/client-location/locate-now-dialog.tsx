import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import {
    Check,
    Crosshair,
    LoaderCircle,
    MapPin,
    Radio,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import LocationAddress from './location-address';
import { privateHeaders, type HistoryPoint } from './types';

export type LocateRequest = {
    id: string;
    status: string;
    reason: string;
    requested_at: string;
    expires_at: string;
    sent_at: string | null;
    acknowledged_at: string | null;
    completed_at: string | null;
    terminal: boolean;
    status_url: string;
    identity_url: string;
    resume_url: string;
    observation:
        | (HistoryPoint & { is_new: boolean; received_at: string })
        | null;
};
type Envelope = {
    access_fingerprint: string;
    checked_at: string;
    request: LocateRequest | null;
    available?: boolean;
    unavailable_reason?: string | null;
};
const states: Record<string, string> = {
    requested: 'Request recorded',
    awaiting_step_up: 'Confirm your identity',
    awaiting_approval: 'Awaiting approval',
    awaiting_change: 'Awaiting approved change',
    ready: 'Ready to send',
    queued: 'Waiting for dispatch',
    dispatching: 'Preparing request',
    accepted: 'Waiting for tracker connection',
    running: 'Waiting for a location report',
    succeeded: 'Command completed',
    reconciling: 'Checking the tracker response',
    reconciled: 'Command confirmed',
    uncertain: 'Delivery is uncertain',
    failed: 'Request failed',
    rejected: 'Request declined',
    expired: 'Request expired',
    cancelled: 'Request cancelled',
    blocked: 'Request blocked',
    mismatch: 'Tracker response did not match',
};

export default function LocateNowDialog({
    open,
    onOpenChange,
    clientId,
    trackerName,
    lastMeasuredAt,
    url,
    fingerprint,
    onAccessEnded,
    onObservation,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    trackerName: string;
    lastMeasuredAt?: string | null;
    url: string;
    fingerprint: string;
    onAccessEnded: () => void;
    onObservation: (point: HistoryPoint) => void;
}) {
    const [request, setRequest] = useState<LocateRequest | null>(null);
    const [reason, setReason] = useState('');
    const [available, setAvailable] = useState(false);
    const [unavailable, setUnavailable] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [checked, setChecked] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [paused, setPaused] = useState(false);
    const [expiryReached, setExpiryReached] = useState(false);
    const generation = useRef(0);
    const operation = useRef<AbortController | null>(null);
    const busyRef = useRef(false);
    const draft = useRef({
        key: '',
        reason: '',
        submitted: false,
        creating: false,
    });
    const lastObservation = useRef('');
    const owner = `${clientId}:${fingerprint}:${url}`;
    const ownerRef = useRef(owner);
    // Invalidate immediately during a changed-context render, before effects run.
    if (ownerRef.current !== owner) {
        ownerRef.current = owner;
        generation.current++;
        operation.current?.abort();
        draft.current = {
            key: '',
            reason: '',
            submitted: false,
            creating: false,
        };
    }
    const openRef = useRef(open);
    openRef.current = open;
    const cancel = useCallback(() => {
        generation.current++;
        operation.current?.abort();
        busyRef.current = false;
    }, []);
    const withFingerprint = useCallback(
        (target: string) =>
            `${target}${target.includes('?') ? '&' : '?'}access_fingerprint=${encodeURIComponent(fingerprint)}`,
        [fingerprint],
    );
    const load = useCallback(
        async (
            target: string,
            body?: Record<string, string>,
            availability = false,
        ) => {
            if (
                !openRef.current ||
                document.visibilityState === 'hidden' ||
                busyRef.current
            )
                return;
            cancel();
            const token = generation.current;
            const abort = new AbortController();
            operation.current = abort;
            busyRef.current = true;
            setBusy(true);
            setError('');
            const current = () =>
                !abort.signal.aborted &&
                generation.current === token &&
                openRef.current &&
                ownerRef.current === owner;
            try {
                const response = await fetch(
                    body ? target : withFingerprint(target),
                    {
                        method: body ? 'POST' : 'GET',
                        signal: abort.signal,
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            ...privateHeaders,
                            ...(body
                                ? {
                                      'Content-Type': 'application/json',
                                      'X-CSRF-TOKEN':
                                          document.querySelector<HTMLMetaElement>(
                                              'meta[name="csrf-token"]',
                                          )?.content ?? '',
                                      'X-XSRF-TOKEN': decodeURIComponent(
                                          document.cookie
                                              .split('; ')
                                              .find((part) =>
                                                  part.startsWith(
                                                      'XSRF-TOKEN=',
                                                  ),
                                              )
                                              ?.slice(11) ?? '',
                                      ),
                                  }
                                : {}),
                        },
                        ...(body
                            ? {
                                  body: JSON.stringify({
                                      ...body,
                                      access_fingerprint: fingerprint,
                                  }),
                              }
                            : {}),
                    },
                );
                if (!current()) return;
                if ([401, 403, 404, 419].includes(response.status)) {
                    cancel();
                    setRequest(null);
                    onOpenChange(false);
                    onAccessEnded();
                    return;
                }
                const data = await response.json();
                if (!current()) return;
                if (!response.ok) {
                    throw new Error(
                        Object.values(data.errors ?? {})
                            .flat()
                            .join(' ') ||
                            data.message ||
                            'The status could not be checked. Retry to check the same request.',
                    );
                }
                const envelope = data as Envelope;
                if (envelope.access_fingerprint !== fingerprint) {
                    cancel();
                    setRequest(null);
                    onOpenChange(false);
                    onAccessEnded();
                    return;
                }
                if (availability) {
                    setAvailable(envelope.available === true);
                    setUnavailable(envelope.unavailable_reason ?? null);
                }
                setChecked(envelope.checked_at);
                if (!availability || !draft.current.creating)
                    setRequest(envelope.request);
                const point = envelope.request?.observation;
                if (
                    point?.is_new &&
                    lastObservation.current !==
                        `${envelope.request?.id}:${point.timestamp}`
                ) {
                    lastObservation.current = `${envelope.request?.id}:${point.timestamp}`;
                    onObservation(point);
                }
            } catch (failure) {
                if (current())
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'The status could not be checked. Retry the same request.',
                    );
            } finally {
                if (current()) {
                    busyRef.current = false;
                    setBusy(false);
                }
            }
        },
        [
            cancel,
            fingerprint,
            onAccessEnded,
            onObservation,
            onOpenChange,
            owner,
            withFingerprint,
        ],
    );
    const loadRef = useRef(load);
    loadRef.current = load;
    useEffect(() => {
        cancel();
        setBusy(false);
        setRequest(null);
        setError('');
        setChecked(null);
        setAvailable(false);
        setReason(draft.current.reason);
        setExpiryReached(false);
        lastObservation.current = '';
        if (open) void loadRef.current(url, undefined, true);
        return cancel;
    }, [open, owner, url, cancel]);
    useEffect(() => {
        if (!open) return;
        const visibility = () => {
            cancel();
            setBusy(false);
            const hidden = document.visibilityState === 'hidden';
            setPaused(hidden);
            if (!hidden) void loadRef.current(url, undefined, true);
        };
        const focus = () => {
            if (document.visibilityState !== 'hidden' && !busyRef.current)
                void loadRef.current(url, undefined, true);
        };
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('focus', focus);
        return () => {
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('focus', focus);
        };
    }, [open, url, cancel]);
    useEffect(() => {
        if (!open || paused || !request || request.terminal) return;
        const tick = () => {
            if (Date.now() >= Date.parse(request.expires_at)) {
                setExpiryReached(true);
                return;
            }
            if (!busyRef.current) void loadRef.current(request.status_url);
        };
        const interval = window.setInterval(tick, 5000);
        return () => window.clearInterval(interval);
    }, [open, paused, request]);
    const close = (value: boolean) => {
        if (!value) cancel();
        onOpenChange(value);
    };
    const send = () => {
        if (busyRef.current) return;
        if (reason.trim().length < 10) {
            setError('Add a reason of at least 10 characters.');
            return;
        }
        if (!draft.current.key) draft.current.key = crypto.randomUUID();
        draft.current.reason = reason.trim();
        draft.current.submitted = true;
        draft.current.creating = false;
        void load(url, {
            reason: draft.current.reason,
            idempotency_key: draft.current.key,
        });
    };
    const fresh = request?.observation?.is_new === true;
    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="location-locate-dialog sm:max-w-xl">
                <DialogHeader>
                    <div className="location-locate-icon">
                        <Crosshair aria-hidden="true" />
                    </div>
                    <DialogTitle>Locate now</DialogTitle>
                    <DialogDescription>
                        Ask {trackerName} to report its location. It needs a
                        connection to respond.
                    </DialogDescription>
                </DialogHeader>
                <div className="location-locate-last">
                    <MapPin aria-hidden="true" className="size-4" />
                    <div>
                        <strong>Last measured location</strong>
                        <p>
                            {formatDateTime(lastMeasuredAt, 'Not yet reported')}
                        </p>
                    </div>
                    <span>NZ time</span>
                </div>
                {error && (
                    <div role="alert" className="location-locate-error">
                        <strong>
                            {checked
                                ? 'Status may be out of date'
                                : 'Please review'}
                        </strong>
                        <p>{error}</p>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                                void load(
                                    request?.status_url ?? url,
                                    undefined,
                                    !request,
                                )
                            }
                        >
                            Retry status check
                        </Button>
                    </div>
                )}
                {!checked && busy && (
                    <p role="status" className="flex items-center gap-2">
                        <LoaderCircle className="size-4 animate-spin" />
                        Checking current tracker access…
                    </p>
                )}
                {unavailable && (
                    <div role="status" className="location-locate-error">
                        <strong>Locate is unavailable</strong>
                        <p>{unavailable}</p>
                    </div>
                )}
                {!request && checked && available && (
                    <div className="space-y-3">
                        <Label htmlFor="locate-reason">
                            Why are you requesting this location?
                        </Label>
                        <Textarea
                            id="locate-reason"
                            value={reason}
                            maxLength={1000}
                            rows={3}
                            disabled={busy || draft.current.submitted}
                            onChange={(event) => {
                                setReason(event.target.value);
                                draft.current.reason = event.target.value;
                            }}
                            placeholder="For example, check the agreed pickup location."
                            aria-describedby="locate-reason-help"
                        />
                        <p
                            id="locate-reason-help"
                            className="text-sm text-muted-foreground"
                        >
                            This reason is recorded with the request. You may be
                            asked to confirm your identity before sending.
                        </p>
                    </div>
                )}
                {request && (
                    <div className="space-y-4">
                        <div
                            className="location-locate-status"
                            role="status"
                            aria-live="polite"
                            aria-atomic="true"
                        >
                            {fresh ? (
                                <Check aria-hidden="true" />
                            ) : request.status === 'awaiting_step_up' ? (
                                <ShieldCheck aria-hidden="true" />
                            ) : (
                                <Radio aria-hidden="true" />
                            )}
                            <div>
                                <strong>
                                    {fresh
                                        ? 'New location received'
                                        : (states[request.status] ??
                                          'Check request status')}
                                </strong>
                                <p>
                                    {fresh
                                        ? `Measured ${formatDateTime(request.observation!.timestamp)}`
                                        : `Request recorded ${formatDateTime(request.requested_at)}`}
                                </p>
                            </div>
                        </div>
                        <ol
                            className="location-locate-progress"
                            aria-label="Location request progress"
                        >
                            <li data-complete="true">
                                <Check aria-hidden="true" />
                                <div>
                                    <strong>Request recorded</strong>
                                    <span>
                                        {formatDateTime(request.requested_at)}
                                    </span>
                                </div>
                            </li>
                            <li data-complete={!!request.sent_at}>
                                <Radio aria-hidden="true" />
                                <div>
                                    <strong>
                                        {request.sent_at
                                            ? 'Sent to tracker'
                                            : 'Waiting to send'}
                                    </strong>
                                    <span>
                                        {request.sent_at
                                            ? formatDateTime(request.sent_at)
                                            : 'Identity, access and connection are checked'}
                                    </span>
                                </div>
                            </li>
                            <li data-complete={!!request.acknowledged_at}>
                                <Check aria-hidden="true" />
                                <div>
                                    <strong>
                                        {request.acknowledged_at
                                            ? 'Tracker acknowledged'
                                            : 'Acknowledgement pending'}
                                    </strong>
                                    <span>
                                        {request.acknowledged_at
                                            ? formatDateTime(
                                                  request.acknowledged_at,
                                              )
                                            : 'An acknowledgement is not a location fix'}
                                    </span>
                                </div>
                            </li>
                            <li data-complete={fresh}>
                                <MapPin aria-hidden="true" />
                                <div>
                                    <strong>
                                        {fresh
                                            ? 'New measured location'
                                            : 'No newer measured location yet'}
                                    </strong>
                                    <span>
                                        {request.observation
                                            ? `${request.observation.is_new ? 'Measured' : 'Older report measured'} ${formatDateTime(request.observation.timestamp)}`
                                            : 'Your existing map position is unchanged'}
                                    </span>
                                </div>
                            </li>
                        </ol>
                        <p className="text-sm text-muted-foreground">
                            {request.reason}
                        </p>
                        {request.observation && (
                            <LocationAddress point={request.observation} />
                        )}
                        {request.observation && (
                            <p className="text-xs text-muted-foreground">
                                This report arrived after the request. The
                                tracker does not identify which request produced
                                a location report.
                            </p>
                        )}
                        {(expiryReached ||
                            request.terminal ||
                            request.status === 'uncertain') &&
                            !fresh && (
                                <p className="text-sm">
                                    {expiryReached && !request.terminal
                                        ? 'Automatic checks have stopped at the request expiry. Refresh for its final status.'
                                        : 'The command status is separate from location evidence. No newer measured location is available.'}
                                </p>
                            )}
                        <p className="text-xs text-muted-foreground">
                            {paused
                                ? 'Checks paused while this page is hidden.'
                                : checked
                                  ? `Last checked ${formatDateTime(checked)}`
                                  : ''}{' '}
                            · Expires {formatDateTime(request.expires_at)}
                        </p>
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    {request || draft.current.submitted
                        ? 'Closing stops status checks. It does not recall a request that has already been sent.'
                        : 'Your existing location and battery timestamps stay unchanged until new information is received.'}
                </p>
                <DialogFooter className="flex-wrap gap-2">
                    <Button variant="outline" onClick={() => close(false)}>
                        {request || draft.current.submitted
                            ? 'Close'
                            : 'Cancel'}
                    </Button>
                    {request && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void load(request.status_url)}
                        >
                            <RefreshCw className="size-4" />
                            Refresh status
                        </Button>
                    )}
                    {request?.status === 'awaiting_step_up' && (
                        <>
                            <Button variant="outline" asChild>
                                <a href={withFingerprint(request.identity_url)}>
                                    <ShieldCheck className="size-4" />
                                    Confirm identity
                                </a>
                            </Button>
                            <Button
                                disabled={busy || !available}
                                onClick={() =>
                                    void load(request.resume_url, {})
                                }
                            >
                                {busy ? 'Checking…' : 'Continue request'}
                            </Button>
                        </>
                    )}
                    {request?.status === 'ready' && (
                        <Button
                            disabled={busy || !available}
                            onClick={() => void load(request.resume_url, {})}
                        >
                            Send request
                        </Button>
                    )}
                    {request?.terminal && available && (
                        <Button
                            disabled={busy}
                            onClick={() => {
                                cancel();
                                draft.current = {
                                    key: '',
                                    reason: '',
                                    submitted: false,
                                    creating: true,
                                };
                                setReason('');
                                setRequest(null);
                                setError('');
                                setExpiryReached(false);
                            }}
                        >
                            New request
                        </Button>
                    )}
                    {!request && checked && available && (
                        <Button disabled={busy} onClick={send}>
                            <Crosshair className="size-4" />
                            {busy
                                ? 'Recording request…'
                                : draft.current.submitted
                                  ? 'Retry same request'
                                  : 'Request location'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
