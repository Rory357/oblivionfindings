import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Leaf, Radio, Settings2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { privateHeaders, readJson } from './types';

type Mode = {
    id: string;
    label: string;
    interval_seconds: number;
    profile_id: number | null;
    profile_version: number | null;
    available: boolean;
};
type Envelope = {
    access_fingerprint: string;
    checked_at: string;
    observed: { mode: string | null; reported_at: string | null };
    unavailable_reason: string | null;
    modes: Mode[];
    changes: { id: number; label: string; ends_at: string }[];
    request: {
        id: string;
        status: string;
        terminal: boolean;
        profile_id: number;
        requested_at: string;
        expires_at: string;
    } | null;
};
const states: Record<string, string> = {
    awaiting_step_up: 'Confirm your identity',
    awaiting_approval: 'Awaiting approval',
    awaiting_change: 'Awaiting approved change',
    ready: 'Ready to send',
    queued: 'Waiting for dispatch',
    dispatching: 'Preparing mode change',
    accepted: 'Waiting for tracker connection',
    running: 'Waiting for tracker confirmation',
    reconciling: 'Verifying tracker settings',
    reconciled: 'Tracker settings confirmed',
    succeeded: 'Command completed',
    mismatch: 'Tracker settings did not match',
    uncertain: 'Tracker state is uncertain',
    failed: 'Mode change failed',
    expired: 'Request expired',
    rejected: 'Request declined',
    cancelled: 'Request cancelled',
    blocked: 'Request blocked',
};
const details: Record<string, string> = {
    standard:
        'Reports every 30 seconds. Return to this mode after live tracking.',
    live: 'Reports every 10 seconds while connected. Uses more battery. Continues until another mode is confirmed.',
    power_saving:
        'Reports every 2 minutes. Safe-zone alerts can take longer between reports. SOS and GNSS remain enabled.',
};

export default function TrackerModes({
    url,
    fingerprint,
    onAccessEnded,
    deviceUrl,
}: {
    url?: string;
    fingerprint?: string | null;
    onAccessEnded: (message: string) => void;
    deviceUrl?: string | null;
}) {
    const [data, setData] = useState<Envelope | null>(null);
    const [open, setOpen] = useState(() =>
        new URLSearchParams(window.location.search).has('tracker-mode'),
    );
    const [mode, setMode] = useState('');
    const [change, setChange] = useState('');
    const [reason, setReason] = useState('');
    const [reviewed, setReviewed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [locked, setLocked] = useState(false);
    const active = useRef(true);
    const operation = useRef<AbortController | null>(null);
    const draft = useRef<Record<string, unknown> | null>(null);
    const busyRef = useRef(false);
    async function load(target = url, body?: Record<string, unknown>) {
        if (!target || !fingerprint || busyRef.current) return;
        busyRef.current = true;
        setBusy(true);
        setError('');
        const abort = new AbortController();
        operation.current = abort;
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((part) => part.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(
                body
                    ? target
                    : `${target}?access_fingerprint=${encodeURIComponent(fingerprint)}`,
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
                                  ...(csrf
                                      ? { 'X-CSRF-TOKEN': csrf }
                                      : xsrf
                                        ? {
                                              'X-XSRF-TOKEN':
                                                  decodeURIComponent(xsrf),
                                          }
                                        : {}),
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
            if (!active.current || abort.signal.aborted) return;
            if ([401, 403, 404, 419].includes(response.status)) {
                onAccessEnded('Tracker access changed. Reload the client to check current access.');
                return;
            }
            const result = await readJson<Envelope>(response);
            if (!active.current || abort.signal.aborted) return;
            if (result.access_fingerprint !== fingerprint) {
                onAccessEnded('Tracker access changed. Reload the client to check current access.');
                return;
            }
            setData(result);
            if (body) {
                draft.current = null;
                setLocked(false);
                setReviewed(false);
            }
        } catch (failure) {
            if (active.current && !abort.signal.aborted)
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Could not check tracker modes. Try again.',
                );
        } finally {
            if (active.current && !abort.signal.aborted) {
                busyRef.current = false;
                setBusy(false);
            }
        }
    }
    const loadRef = useRef(load);
    loadRef.current = load;
    useEffect(() => {
        active.current = true;
        void loadRef.current();
        const refresh = () => {
            if (document.visibilityState !== 'hidden' && !busyRef.current)
                void loadRef.current();
        };
        const timer = window.setInterval(refresh, 30000);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            active.current = false;
            operation.current?.abort();
            busyRef.current = false;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [url, fingerprint]);
    const selected = data?.modes.find((item) => item.id === mode);
    const observed = data?.modes.find((item) => item.id === data.observed.mode);
    const pending = data?.request && !data.request.terminal;
    const send = () => {
        if (busyRef.current) return;
        if (
            !draft.current &&
            (!selected?.available ||
                !selected.profile_id ||
                !change ||
                !reviewed ||
                reason.trim().length < 10)
        )
            return;
        draft.current ??= {
            mode,
            profile_id: selected!.profile_id,
            it_change_id: Number(change),
            reason: reason.trim(),
            impact_acknowledged: true,
            idempotency_key: crypto.randomUUID(),
        };
        setLocked(true);
        void load(url, draft.current);
    };
    return (
        <>
            <section
                className="tracker-status-card"
                aria-label="Tracker operating mode"
            >
                <div className="tracker-battery-heading">
                    <span>TRACKER MODE</span>
                    <span>Last confirmed</span>
                </div>
                <div className="tracker-status-reading">
                    <span className="tracker-status-icon" aria-hidden="true">
                        {observed?.id === 'power_saving' ? <Leaf /> : <Radio />}
                    </span>
                    <div>
                        <strong>
                            {observed?.label ?? 'Mode not confirmed'}
                        </strong>
                        <p>
                            {observed
                                ? `Reports every ${observed.interval_seconds} seconds`
                                : 'Waiting for device settings'}
                        </p>
                    </div>
                </div>
                <p className="tracker-status-note">
                    {data?.observed.reported_at
                        ? `Confirmed ${formatDateTime(data.observed.reported_at)}`
                        : 'Standard, Live tracking and Power saving use verified device settings.'}
                </p>
                {pending && (
                    <p role="status" className="tracker-status-note">
                        {states[data.request!.status] ?? 'Request recorded'}
                    </p>
                )}
                {url && fingerprint ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11 w-full"
                        onClick={() => {
                            setOpen(true);
                            void load();
                        }}
                    >
                        <Settings2 className="size-4" />
                        Manage tracker mode
                    </Button>
                ) : (
                    <p className="tracker-status-note">
                        Device management access is required to change modes.
                    </p>
                )}
                {error && !open && (
                    <p role="status" className="tracker-status-note">
                        Mode status could not be refreshed. Open modes to retry.
                    </p>
                )}
            </section>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="client-location-workspace tracker-mode-dialog max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Tracker mode</DialogTitle>
                        <DialogDescription>
                            Change how often the unit reports. A request becomes
                            confirmed only after the tracker settings are read
                            back.
                        </DialogDescription>
                    </DialogHeader>
                    {error && (
                        <div role="alert" className="location-message">
                            <p>{error}</p>
                            <Button
                                variant="outline"
                                onClick={() => void load()}
                                disabled={busy}
                            >
                                Retry status check
                            </Button>
                        </div>
                    )}
                    {!data && (
                        <p role="status">
                            {busy
                                ? 'Checking tracker modes…'
                                : 'Tracker modes are unavailable.'}
                        </p>
                    )}
                    {data?.unavailable_reason && (
                        <p className="location-message" role="status">
                            {data.unavailable_reason}
                        </p>
                    )}
                    {data?.request && (
                        <div className="location-message" role="status">
                            <strong>
                                {states[data.request.status] ??
                                    'Request recorded'}
                            </strong>
                            <p>
                                Requested{' '}
                                {formatDateTime(data.request.requested_at)} ·{' '}
                                {data.modes.find(
                                    (item) =>
                                        item.profile_id ===
                                        data.request?.profile_id,
                                )?.label ?? 'Tracker configuration'}
                            </p>
                            <p>
                                Current mode remains{' '}
                                {observed?.label ?? 'unconfirmed'} until a
                                matching device readback arrives.
                            </p>
                            {data.request.status === 'awaiting_step_up' && (
                                <a
                                    className="frontline-tap inline-flex items-center underline"
                                    href={`${url}/${data.request.id}/confirm-identity?access_fingerprint=${encodeURIComponent(fingerprint ?? '')}`}
                                >
                                    Confirm identity
                                </a>
                            )}
                            {['ready', 'awaiting_step_up'].includes(
                                data.request.status,
                            ) && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void load(
                                            `${url}/${data.request!.id}/resume`,
                                            {},
                                        )
                                    }
                                >
                                    Continue same request
                                </Button>
                            )}
                            {data.request.status === 'awaiting_approval' && (
                                <p>
                                    A separate authorised reviewer must approve
                                    this configuration change in Device Profile.
                                </p>
                            )}
                            {deviceUrl && (
                                <a
                                    className="frontline-tap inline-flex items-center underline"
                                    href={deviceUrl}
                                >
                                    Open Device Profile
                                </a>
                            )}
                        </div>
                    )}
                    {data && !pending && (
                        <>
                            <fieldset
                                disabled={busy || locked}
                                className="space-y-2"
                            >
                                <legend className="mb-2 font-medium">
                                    Choose a reporting mode
                                </legend>
                                {data.modes.map((item) => (
                                    <label
                                        key={item.id}
                                        className="tracker-mode-option"
                                        data-selected={mode === item.id}
                                    >
                                        <input
                                            type="radio"
                                            name="tracker-mode"
                                            value={item.id}
                                            checked={mode === item.id}
                                            disabled={!item.available}
                                            onChange={() => {
                                                setMode(item.id);
                                                setReviewed(false);
                                            }}
                                        />
                                        <span>
                                            <strong>{item.label}</strong>
                                            <small>{details[item.id]}</small>
                                            {!item.available && (
                                                <small>
                                                    Not available for this
                                                    tracker
                                                </small>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                            {!data.unavailable_reason && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="mode-change">
                                            Approved change
                                        </Label>
                                        <select
                                            id="mode-change"
                                            className="tracker-mode-select"
                                            value={change}
                                            disabled={busy || locked}
                                            onChange={(event) =>
                                                setChange(event.target.value)
                                            }
                                        >
                                            <option value="">
                                                Choose an approved change
                                            </option>
                                            {data.changes.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.label} · until{' '}
                                                    {formatDateTime(
                                                        item.ends_at,
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                        {data.changes.length === 0 && (
                                            <p className="text-sm text-muted-foreground">
                                                No current approved change is
                                                linked to this unit. Ask the
                                                device administrator to prepare
                                                one in Device Profile.
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="mode-reason">
                                            Reason for this change
                                        </Label>
                                        <Textarea
                                            id="mode-reason"
                                            value={reason}
                                            disabled={busy || locked}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                            maxLength={1000}
                                            placeholder="Explain why this reporting mode is needed."
                                        />
                                    </div>
                                    <label className="flex min-h-11 items-start gap-3 text-sm">
                                        <Checkbox
                                            checked={reviewed}
                                            disabled={busy || locked}
                                            onCheckedChange={(value) =>
                                                setReviewed(value === true)
                                            }
                                        />
                                        <span>
                                            I have reviewed the reporting
                                            interval, battery impact and
                                            possible delay to safe-zone alerts.
                                            This applies the approved tracker
                                            configuration and needs separate
                                            approval.
                                        </span>
                                    </label>
                                </>
                            )}
                        </>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                        {!pending && !data?.unavailable_reason && (
                            <Button
                                disabled={
                                    busy ||
                                    (!locked &&
                                        (!reviewed ||
                                            !selected?.available ||
                                            !change ||
                                            reason.trim().length < 10))
                                }
                                onClick={send}
                            >
                                {busy
                                    ? 'Recording request…'
                                    : locked
                                      ? 'Retry same request'
                                      : 'Request mode change'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
