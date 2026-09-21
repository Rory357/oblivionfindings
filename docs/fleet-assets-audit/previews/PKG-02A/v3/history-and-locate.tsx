import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    Clock,
    Crosshair,
    Loader2,
    Radio,
    RefreshCw,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Event = {
    type: string;
    time: string;
    title: string;
    detail: string;
    date?: string;
};
const labelDate = (d: string) =>
    new Date(`${d}T12:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
export function ActivityHistory({
    events,
    empty,
    filter,
    onFilter,
    onAccessEnded,
}: {
    events: Event[];
    empty: boolean;
    filter: string;
    onFilter: (v: string) => void;
    onAccessEnded: () => void;
}) {
    const [from, setFrom] = useState('2026-09-20'),
        [to, setTo] = useState('2026-09-20'),
        [applied, setApplied] = useState(['2026-09-20', '2026-09-20']),
        [mode, setMode] = useState('ready'),
        [state, setState] = useState('ready'),
        [error, setError] = useState(''),
        [point, setPoint] = useState<Event | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null),
        opener = useRef<HTMLElement | null>(null);
    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );
    const records = [
        ...events.map((e) => ({ ...e, date: e.date || '2026-09-20' })),
        ...(!empty
            ? [
                  {
                      type: 'observed',
                      date: '2026-09-19',
                      time: '4:12 pm',
                      title: 'Device reported at Example House',
                      detail: 'Synthetic retained observation • T-DEMO-08 • reported accuracy ±18 m',
                  },
                  {
                      type: 'observed',
                      date: '2026-09-19',
                      time: '10:05 am',
                      title: 'Device reported at Example Gardens',
                      detail: 'Synthetic retained observation • T-DEMO-08 • reported accuracy ±28 m',
                  },
              ]
            : []),
    ].filter(
        (e) =>
            e.date >= applied[0] &&
            e.date <= applied[1] &&
            (filter === 'all' || filter === e.type) &&
            (!empty || e.type !== 'observed'),
    );
    const dirty = from !== applied[0] || to !== applied[1];
    const apply = (retry = false) => {
        if (timer.current) clearTimeout(timer.current);
        if (!from || !to || from > to) {
            setError('Choose both dates, with From on or before To.');
            return;
        }
        if (to > '2026-09-20') {
            setError(
                'This preview contains records through 20 September 2026. Choose that date or earlier.',
            );
            return;
        }
        setError('');
        setApplied([from, to]);
        setState('loading');
        timer.current = setTimeout(() => {
            setState(retry ? 'ready' : mode);
            if (retry) setMode('ready');
        }, 450);
    };
    return (
        <>
            <section className="v3-history">
                <div className="v2-box-head">
                    <div>
                        <h3>Activity history</h3>
                        <p>
                            Device observations, recorded responses and plans •
                            Pacific/Auckland
                        </p>
                    </div>
                    <label className="v2-demo">
                        Preview state
                        <select
                            aria-label="History example"
                            value={mode}
                            onChange={(e) => {
                                const v = e.target.value;
                                if (timer.current) clearTimeout(timer.current);
                                if (v === 'ended') {
                                    onAccessEnded();
                                    return;
                                }
                                setMode(v);
                                setState(v);
                            }}
                        >
                            <option value="ready">Records available</option>
                            <option value="empty">Empty result</option>
                            <option value="loading">Loading</option>
                            <option value="error">Request failed</option>
                            <option value="ended">Access withdrawn</option>
                        </select>
                    </label>
                </div>
                <div className="v3-history-controls">
                    <div>
                        <label htmlFor="history-from">From</label>
                        <DatePicker
                            id="history-from"
                            label="History from"
                            value={from}
                            onChange={(v) => {
                                setFrom(v);
                                setError('');
                            }}
                        />
                    </div>
                    <div>
                        <label htmlFor="history-to">To</label>
                        <DatePicker
                            id="history-to"
                            label="History to"
                            value={to}
                            onChange={(v) => {
                                setTo(v);
                                setError('');
                            }}
                        />
                    </div>
                    <Button
                        onClick={() => apply()}
                        disabled={state === 'loading'}
                    >
                        <RefreshCw className="size-4" />
                        Apply date range
                    </Button>
                </div>
                <div className="v3-history-types">
                    <div role="group" aria-label="Activity type">
                        {[
                            ['all', 'All activity'],
                            ['observed', 'Observations'],
                            ['response', 'Staff responses'],
                            ['planned', 'Plans'],
                        ].map(([value, label]) => (
                            <Button
                                key={value}
                                variant={filter === value ? 'default' : 'ghost'}
                                aria-pressed={filter === value}
                                onClick={() => onFilter(value)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <span className="micro">
                        {labelDate(applied[0])}
                        {applied[0] !== applied[1]
                            ? ` – ${labelDate(applied[1])}`
                            : ''}
                    </span>
                </div>
                {error && (
                    <div className="v2-error" role="alert">
                        {error}
                    </div>
                )}
                {dirty ? (
                    <div className="v3-history-state">
                        Date range changed. Apply it to load the matching
                        records.
                    </div>
                ) : state === 'loading' ? (
                    <div className="v3-history-state" role="status">
                        <Loader2 className="size-5 animate-spin" />
                        <strong>Loading activity…</strong>
                        <span>
                            Checking current access before displaying history.
                        </span>
                        {mode === 'loading' && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setMode('ready');
                                    setState('ready');
                                }}
                            >
                                Complete loading example
                            </Button>
                        )}
                    </div>
                ) : state === 'error' ? (
                    <div className="v3-history-state" role="alert">
                        <AlertTriangle className="size-5" />
                        <strong>Activity could not be loaded</strong>
                        <span>
                            This is a failed request, not an empty result. Your
                            date range is kept.
                        </span>
                        <Button variant="outline" onClick={() => apply(true)}>
                            Retry history
                        </Button>
                    </div>
                ) : state === 'empty' || !records.length ? (
                    <div className="v3-history-state">
                        <Radio className="size-5" />
                        <strong>
                            No{' '}
                            {filter === 'observed'
                                ? 'observations'
                                : 'matching activity'}{' '}
                            in this period
                        </strong>
                        <span>
                            Missing records do not establish location or
                            wellbeing.
                        </span>
                    </div>
                ) : (
                    ['recorded', 'planned'].map((group) => {
                        const rows = records.filter((e) =>
                            group === 'planned'
                                ? e.type === 'planned'
                                : e.type !== 'planned',
                        );
                        if (!rows.length) return null;
                        return (
                            <div key={group}>
                                <div className="v3-history-group">
                                    {group === 'planned'
                                        ? 'Planned events — not observed locations'
                                        : 'Recorded observations & responses'}
                                </div>
                                {rows.map((e, i) => (
                                    <button
                                        className="v2-event"
                                        key={`${e.date}-${e.time}-${i}`}
                                        onClick={(ev) => {
                                            opener.current = ev.currentTarget;
                                            setPoint(e);
                                        }}
                                    >
                                        <time>
                                            {labelDate(e.date)}
                                            <br />
                                            {e.time}
                                        </time>
                                        {e.type === 'observed' ? (
                                            <Radio className="size-5" />
                                        ) : e.type === 'response' ? (
                                            <Users className="size-5" />
                                        ) : (
                                            <CalendarDays className="size-5" />
                                        )}
                                        <div>
                                            <StatusBadge
                                                variant={
                                                    e.type === 'planned'
                                                        ? 'neutral'
                                                        : 'info'
                                                }
                                            >
                                                {e.type === 'observed'
                                                    ? 'Observed'
                                                    : e.type === 'response'
                                                      ? 'Staff response'
                                                      : 'Planned'}
                                            </StatusBadge>
                                            <h4>{e.title}</h4>
                                            <p>{e.detail}</p>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        );
                    })
                )}
                <div className="v3-history-foot">
                    Current access, assignment and approved retention rules
                    govern history. Dates here filter fictional records; they do
                    not define a retention policy.
                </div>
            </section>
            <Dialog open={!!point} onOpenChange={(o) => !o && setPoint(null)}>
                <DialogContent
                    onCloseAutoFocus={(e) => {
                        e.preventDefault();
                        opener.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {point?.type === 'observed'
                                ? 'Historical observation'
                                : point?.type === 'planned'
                                  ? 'Planned event'
                                  : 'Recorded response'}
                        </DialogTitle>
                        <DialogDescription>
                            {point?.date && labelDate(point.date)} •{' '}
                            {point?.time} • NZST (UTC+12)
                        </DialogDescription>
                    </DialogHeader>
                    <h3>{point?.title}</h3>
                    <p>{point?.detail}</p>
                    <p className="micro">
                        {point?.type === 'observed'
                            ? 'This historical device report does not replace the latest observation or establish a current position.'
                            : point?.type === 'planned'
                              ? 'A plan is not evidence that the visit occurred.'
                              : 'A worker response is separate from device telemetry.'}
                    </p>
                    <Button variant="outline" onClick={() => setPoint(null)}>
                        Close detail
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function useLocatePreview(onAccessEnded: () => void) {
    const [state, setState] = useState('idle'),
        [outcome, setOutcome] = useState('success'),
        [received, setReceived] = useState(false),
        [reason, setReason] = useState(''),
        [identity, setIdentity] = useState(false);
    const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
    const reset = () => {
        timers.current.forEach(clearTimeout);
        timers.current = [];
        setState('idle');
    };
    useEffect(() => () => timers.current.forEach(clearTimeout), []);
    const request = () => {
        reset();
        setState('queued');
        timers.current.push(
            setTimeout(
                () =>
                    setState(
                        outcome === 'offline' ? 'offline' : 'acknowledged',
                    ),
                550,
            ),
        );
        timers.current.push(
            setTimeout(() => {
                if (outcome === 'ended') {
                    onAccessEnded();
                    return;
                }
                if (outcome === 'success') setReceived(true);
                setState(
                    outcome === 'success'
                        ? 'received'
                        : outcome === 'timeout'
                          ? 'timeout'
                          : outcome === 'offline'
                            ? 'offline'
                            : 'acknowledged',
                );
            }, 1700),
        );
    };
    return {
        state,
        outcome,
        setOutcome,
        request,
        reset,
        busy: state === 'queued' || state === 'acknowledged',
        received,
        reason,
        setReason,
        identity,
        setIdentity,
    };
}
type Locate = ReturnType<typeof useLocatePreview>;
const locateCopy: Record<string, [string, string]> = {
    idle: [
        'Ready to request',
        'Ask the paired tracker for a fresh location report.',
    ],
    queued: [
        'Request queued',
        'Waiting for the tracker to acknowledge the command.',
    ],
    acknowledged: [
        'Unit acknowledged • awaiting location',
        'Command acknowledgement is not a new location. The previous observation is retained.',
    ],
    received: [
        'New observation received',
        '20 Sep 2026, 2:36 pm NZST • Example Gardens • reported accuracy ±18 m.',
    ],
    timeout: [
        'No new observation received',
        'The example response window ended. The previous observation remains visible; review the device before retrying.',
    ],
    offline: [
        'Tracker unreachable',
        'No location has been returned. Check device connectivity; no position is inferred.',
    ],
};
export function LocateStatus({ locate }: { locate: Locate }) {
    if (locate.state === 'idle') return null;
    const [title, detail] = locateCopy[locate.state];
    return (
        <div
            className={`v3-locate-status ${['timeout', 'offline'].includes(locate.state) ? 'warning' : ''}`}
            role="status"
        >
            {locate.busy ? (
                <Loader2 className="size-4 animate-spin" />
            ) : locate.received ? (
                <CheckCircle2 className="size-4" />
            ) : (
                <Clock className="size-4" />
            )}
            <div>
                <strong>{title}</strong>
                <p>{detail}</p>
                <small>
                    Synthetic request LOC-DEMO-31 • no real unit contacted
                </small>
            </div>
        </div>
    );
}
export function LocatePanel({
    locate,
    limited,
    offline,
}: {
    locate: Locate;
    limited: boolean;
    offline: boolean;
}) {
    const { reason, setReason, identity, setIdentity } = locate;
    const [error, setError] = useState('');
    return (
        <div className="v3-locate-panel">
            <div className="v3-source-identity">
                <Crosshair className="size-6" />
                <div>
                    <strong>Locate now</strong>
                    <p>Personal tracker T-DEMO-08 • Alex Hale</p>
                    <span>Current assignment A-DEMO-052 • Example House</span>
                </div>
                <StatusBadge variant={offline ? 'warning' : 'info'}>
                    {offline ? 'Contact unavailable' : 'Paired unit'}
                </StatusBadge>
            </div>
            <p>
                Request a new report from the unit. It may take time to respond;
                an acknowledgement alone will not move the map or refresh the
                observation time.
            </p>
            <LocateStatus locate={locate} />
            {limited ? (
                <div className="v2-error">
                    Your role can view location but cannot send tracker
                    commands.
                </div>
            ) : (
                <>
                    <label htmlFor="locate-reason">Operational reason</label>
                    <Textarea
                        id="locate-reason"
                        value={reason}
                        disabled={locate.busy}
                        onChange={(e) => {
                            setReason(e.target.value);
                            setError('');
                        }}
                        placeholder="Why is a fresh location needed?"
                    />
                    <div className="v3-identity-check">
                        <ShieldCheck className="size-5" />
                        <div>
                            <strong>
                                {identity
                                    ? 'Identity confirmed in this preview'
                                    : 'Confirm your identity'}
                            </strong>
                            <p>
                                Jamie Taylor • the existing governed command
                                workflow requires an identity check and a
                                recorded reason.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            disabled={identity || locate.busy}
                            onClick={() => setIdentity(true)}
                        >
                            {identity ? 'Confirmed' : 'Confirm in preview'}
                        </Button>
                    </div>
                    {error && (
                        <div className="v2-error" role="alert">
                            {error}
                        </div>
                    )}
                    <Button
                        disabled={locate.busy}
                        onClick={() => {
                            if (!reason.trim() || !identity) {
                                setError(
                                    'Enter a reason and confirm identity before requesting a location.',
                                );
                                return;
                            }
                            locate.request();
                        }}
                    >
                        <Crosshair className="size-4" />
                        {locate.busy
                            ? 'Request in progress'
                            : locate.state === 'idle'
                              ? 'Send locate request'
                              : locate.state === 'received'
                                ? 'Locate again'
                                : 'Retry locate request'}
                    </Button>
                </>
            )}
            <div className="v3-preview-controls">
                <label>
                    Preview response
                    <select
                        aria-label="Locate preview response"
                        disabled={locate.busy}
                        value={locate.outcome}
                        onChange={(e) => locate.setOutcome(e.target.value)}
                    >
                        <option value="success">
                            Acknowledgement, then new observation
                        </option>
                        <option value="ack">Acknowledgement only</option>
                        <option value="timeout">No location returned</option>
                        <option value="offline">Unit offline</option>
                        <option value="ended">
                            Access withdrawn during request
                        </option>
                    </select>
                </label>
                {locate.state !== 'idle' && (
                    <Button variant="ghost" onClick={locate.reset}>
                        Reset demo request
                    </Button>
                )}
            </div>
            <p className="micro">
                Preview only. The real workflow must recheck command capability,
                permissions, current authority and assignment before dispatch
                and delivery. Retry limits and timeouts use approved device
                configuration.
            </p>
        </div>
    );
}
