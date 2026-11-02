/**
 * The Alerts pop-ups (S&D rebuild drawings sdr-20 Acknowledge, Assign and Snooze; sdr-21 Add alert rule;
 * sdr-44 Quiet time for planned work; sdr-45 Report incident from this alert), plus turning a rule off or
 * on and the read-only story of an alert that is done. All on the pop-up kit, at kit widths.
 *
 * Every save keeps what was typed when it fails, says why in plain words, and when the answer never
 * arrives says "Result not confirmed" with a way to check instead of inviting a second save.
 */
import {
    DateTimeField,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupSection,
    PopupViewer,
    type PopupContextRow,
} from '@/components/popup';
import { ChoiceTiles } from '@/components/security-devices/device-popups';
import { useMaintenanceCommand } from '@/components/security-devices/use-maintenance-command';
import { Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toDatetimeLocal, workerTimeOffsets } from '@/lib/datetime';
import {
    agoWords,
    durationWords,
    seenTime,
    timedText,
} from '@/lib/security-devices/device-time';
import type {
    AlertRuleRow,
    PickableDevice,
    RuleForm,
    SelectedAlert,
} from '@/pages/security-devices/alerts/_types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlarmClock,
    Bell,
    BellOff,
    BellPlus,
    BellRing,
    CheckCheck,
    ClipboardCopy,
    ClipboardPlus,
    LoaderCircle,
    MapPin,
    Power,
    PowerOff,
    Send,
    UserRoundCheck,
    Users,
    WifiOff,
    X,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const BASE = '/security-devices/alerts';
const RULES = '/security-devices/settings/monitoring/alert-routes';
const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/** The alert a pop-up acts on, as the page knows it. */
export type AlertTarget = { id: number; title: string; opened_at: string };

/* ------------------------------------------------------------------ */
/*  Shared bits                                                        */
/* ------------------------------------------------------------------ */

type Failure = {
    error?: string;
    fields?: Record<string, string>;
    unconfirmed?: boolean;
};

/** A refused save in plain words; a lost answer is "unconfirmed", never "try again". */
export function failure(
    error: unknown,
    fallback: string,
    conflict?: string,
): Failure {
    if (axios.isAxiosError(error)) {
        const response = error.response;
        if (!response) return { unconfirmed: true };
        const data = response.data as
            | { message?: string; errors?: Record<string, string[] | string> }
            | undefined;
        if (response.status === 422 && data?.errors) {
            return {
                fields: Object.fromEntries(
                    Object.entries(data.errors).map(([key, value]) => [
                        key,
                        Array.isArray(value) ? String(value[0]) : String(value),
                    ]),
                ),
            };
        }
        if (response.status === 403)
            return {
                error: 'You can’t do this here. Ask a manager if you think you should be able to.',
            };
        if (response.status === 404)
            return {
                error: 'This is no longer there, or you can’t see it any more. Close this and look again.',
            };
        if (response.status === 409)
            return {
                error:
                    conflict ??
                    data?.message ??
                    'This changed after you opened it. Close this and look again.',
            };
        if (response.status === 429)
            return {
                error: 'Too many tries in a minute. Wait a moment, then try again.',
            };
    }
    return { error: fallback };
}

const UNCONFIRMED =
    'Result not confirmed: the connection may have dropped. Check the alert before trying again, so it isn’t done twice.';

/** Refresh what the page shows about alerts, then run `then` (used by "Check result"). */
function refreshAlerts(then?: () => void) {
    router.reload({
        only: ['queue', 'selected', 'counts', 'meters', 'done'],
        onFinish: () => then?.(),
    });
}

export function alertContext(
    alert: AlertTarget,
    now: Date = new Date(),
): PopupContextRow {
    return {
        icon: BellRing,
        title: alert.title,
        tag: `Alert · since ${seenTime(alert.opened_at, now)}`,
        note: 'From the alert you opened',
    };
}

/** "2026-11-01T15:00" in NZ wall-clock time: the first whole hour at least `minutes` ahead (NZ offsets are whole hours). */
export function nextWholeHour(minutes = 30, now: Date = new Date()): string {
    const hour = 3_600_000;
    return toDatetimeLocal(
        new Date(Math.ceil((now.getTime() + minutes * 60_000) / hour) * hour),
    );
}

/**
 * Fill an alert line from the server in NZ time: "{for}" is how long since `at`, "{closed}" when it
 * closed ("3 h ago"), "{snoozed}" until when, and "{time}" / "{ago}" as on every Devices screen.
 */
export function alertLine(
    text: string,
    at: string | null,
    now: Date = new Date(),
    times: { closed?: string | null; snoozed?: string | null } = {},
): string {
    const closed = times.closed ?? at;
    return timedText(
        {
            text: text
                .replace('{for}', at ? durationWords(at, now) : '—')
                .replace('{closed}', closed ? agoWords(closed, now) : '—')
                .replace(
                    '{snoozed}',
                    times.snoozed ? seenTime(times.snoozed, now) : '—',
                ),
            at,
        },
        now,
    );
}

/** An NZ wall-clock "YYYY-MM-DDTHH:mm" as an exact instant; a time skipped by daylight saving is refused. */
export function nzInstant(local: string): { iso: string } | { error: string } {
    if (!validLocalDateTime(local))
        return { error: 'Choose a date and a time.' };
    const offsets = workerTimeOffsets(local);
    if (offsets.length === 0)
        return {
            error: 'That time is skipped when the clocks go forward. Choose another time.',
        };
    // A repeated hour (clocks going back) takes its first occurrence.
    return { iso: `${local}:00${offsets[0]}` };
}

function addLocalHours(local: string, hours: number): string {
    const at = nzInstant(local);
    return 'iso' in at
        ? toDatetimeLocal(new Date(Date.parse(at.iso) + hours * 3_600_000))
        : '';
}

/** A switch with its On / Off word (the settings pattern), as a row in a pop-up. */
function SwitchRow({
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (on: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-[10px] border border-border bg-muted/40 px-3.5 py-2.5">
            <div className="min-w-0">
                <label
                    htmlFor={id}
                    className="text-sm font-semibold text-foreground"
                >
                    {label}
                </label>
                {hint ? <p className="text-caption mt-0.5">{hint}</p> : null}
            </div>
            <span className="flex shrink-0 items-center gap-2">
                <span
                    className="text-sm text-muted-foreground"
                    aria-hidden="true"
                >
                    {checked ? 'On' : 'Off'}
                </span>
                <Switch id={id} checked={checked} onCheckedChange={onChange} />
            </span>
        </div>
    );
}

/** Tick chips (the kit's "chips" block): several at once, or one when `single`. */
function Chips<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T[];
    onChange: (next: T[]) => void;
}) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={(next) => onChange(next as T[])}
            className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className="rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary"
                >
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

function SingleChips<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T;
    onChange: (next: T) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next as T);
            }}
            className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className="rounded-full! border! px-3 data-[state=on]:border-primary data-[state=on]:bg-primary-fill/10 data-[state=on]:text-primary"
                >
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/** A quiet in-place line while a list loads, or why it couldn't, with Retry. */
function ListState({
    state,
    loading,
    failed,
    onRetry,
}: {
    state: 'loading' | 'failed';
    loading: string;
    failed: string;
    onRetry: () => void;
}) {
    if (state === 'loading') {
        return (
            <p
                className="flex items-center gap-2 text-sm text-muted-foreground"
                role="status"
            >
                <LoaderCircle
                    aria-hidden="true"
                    className="size-4 animate-spin motion-reduce:animate-none"
                />
                {loading}
            </p>
        );
    }
    return (
        <PopupCallout tone="warning">
            {failed}{' '}
            <Button
                type="button"
                variant="link"
                className="h-auto p-0 align-baseline"
                onClick={onRetry}
            >
                Retry
            </Button>
        </PopupCallout>
    );
}

/* ------------------------------------------------------------------ */
/*  20 · Acknowledge alert                                             */
/* ------------------------------------------------------------------ */

export function AcknowledgeAlert({
    open,
    alert,
    onClose,
}: {
    open: boolean;
    alert: AlertTarget | null;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [remind, setRemind] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        if (open) {
            setNote('');
            setRemind(true);
            setResult({});
        }
    }, [open, alert?.id]);
    if (!alert) return null;

    const submit = () => {
        if (result.unconfirmed) {
            setProcessing(true);
            refreshAlerts(() => {
                setProcessing(false);
                onClose();
            });
            return;
        }
        setProcessing(true);
        setResult({});
        axios
            .post<{ message?: string }>(
                `${BASE}/${alert.id}/acknowledge`,
                { note: note.trim() || null, remind },
                JSON_HEADERS,
            )
            .then((response) => {
                toast.success(
                    response.data?.message ?? 'Everyone can see you have it.',
                );
                onClose();
                refreshAlerts();
            })
            .catch((error) =>
                setResult(
                    failure(error, 'It couldn’t be acknowledged. Try again.'),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={CheckCheck}
            title="Acknowledge alert"
            description="Let everyone know you’re on it"
            context={alertContext(alert)}
            error={result.error}
            callout={
                result.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={note.trim() !== '' || !remind}
            draftName={`Acknowledge alert for ${alert.title}`}
            processing={processing}
            processingLabel={
                result.unconfirmed ? 'Checking…' : 'Acknowledging…'
            }
            submitLabel={result.unconfirmed ? 'Check result' : 'Acknowledge'}
            onSubmit={submit}
            testId="sd-acknowledge-alert"
        >
            <PopupField
                label="Note"
                htmlFor="alert-ack-note"
                hint="Optional · everyone who can see the alert can read it"
                error={result.fields?.note}
            >
                <Textarea
                    id="alert-ack-note"
                    rows={3}
                    maxLength={1000}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder="e.g. Called the house: power cut on the street, back by 11 am"
                />
            </PopupField>
            <SwitchRow
                id="alert-ack-remind"
                label="Remind me if it’s still open in 1 hour"
                checked={remind}
                onChange={setRemind}
            />
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Mark done (safety alerts and wrong sensor logins)                  */
/* ------------------------------------------------------------------ */

/** Safety alerts don't clear by themselves: a person says it's dealt with, with an optional note. */
export function MarkAlertDone({
    open,
    alert,
    onClose,
}: {
    open: boolean;
    alert: AlertTarget | null;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        if (open) {
            setNote('');
            setResult({});
        }
    }, [open, alert?.id]);
    if (!alert) return null;

    const submit = () => {
        if (result.unconfirmed) {
            setProcessing(true);
            refreshAlerts(() => {
                setProcessing(false);
                onClose();
            });
            return;
        }
        setProcessing(true);
        setResult({});
        axios
            .post<{ message?: string }>(
                `${BASE}/${alert.id}/done`,
                { note: note.trim() || null },
                JSON_HEADERS,
            )
            .then((response) => {
                toast.success(response.data?.message ?? 'Marked done.');
                onClose();
                refreshAlerts();
            })
            .catch((error) =>
                setResult(failure(error, 'It couldn’t be marked done. Try again.')),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={CheckCheck}
            title="Mark this alert done?"
            description="It moves to Done, with your name and note on its timeline"
            context={alertContext(alert)}
            error={result.error}
            callout={
                result.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={note.trim() !== ''}
            draftName={`Mark done for ${alert.title}`}
            processing={processing}
            processingLabel={result.unconfirmed ? 'Checking…' : 'Marking done…'}
            submitLabel={result.unconfirmed ? 'Check result' : 'Mark done'}
            onSubmit={submit}
            testId="sd-mark-alert-done"
        >
            <PopupField
                label="What happened"
                htmlFor="alert-done-note"
                hint="Optional · e.g. what was found and what was done"
                error={result.fields?.note}
            >
                <Textarea
                    id="alert-done-note"
                    rows={3}
                    maxLength={1000}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder="e.g. False alarm: the button was knocked while cleaning"
                />
            </PopupField>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  20 (also) · Assign alert                                           */
/* ------------------------------------------------------------------ */

type Person = { id: number; name: string; hint: string | null };

export function AssignAlert({
    open,
    alert,
    onClose,
}: {
    open: boolean;
    alert: AlertTarget | null;
    onClose: () => void;
}) {
    const [people, setPeople] = useState<Person[] | null>(null);
    const [listState, setListState] = useState<'loading' | 'failed' | 'ready'>(
        'loading',
    );
    const [personId, setPersonId] = useState('');
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    const asked = useRef(0);

    const loadPeople = (id: number) => {
        const call = ++asked.current;
        setListState('loading');
        axios
            .get<{ people: Person[] }>(`${BASE}/${id}/assignees`, JSON_HEADERS)
            .then((response) => {
                if (call !== asked.current) return;
                setPeople(
                    Array.isArray(response.data?.people)
                        ? response.data.people
                        : [],
                );
                setListState('ready');
            })
            .catch(() => {
                if (call === asked.current) setListState('failed');
            });
    };
    useEffect(() => {
        // An answer for an earlier opening is ignored once the pop-up closes or moves on.
        const tickets = asked;
        if (open && alert) {
            setPersonId('');
            setNote('');
            setResult({});
            setPeople(null);
            loadPeople(alert.id);
        }
        return () => {
            tickets.current += 1;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reload only when the pop-up opens for an alert
    }, [open, alert?.id]);
    if (!alert) return null;
    const chosen =
        people?.find((person) => String(person.id) === personId) ?? null;

    const submit = () => {
        if (result.unconfirmed) {
            setProcessing(true);
            refreshAlerts(() => {
                setProcessing(false);
                onClose();
            });
            return;
        }
        if (!chosen) {
            setResult({ fields: { user_id: 'Choose who looks after it.' } });
            return;
        }
        setProcessing(true);
        setResult({});
        axios
            .post<{ message?: string }>(
                `${BASE}/${alert.id}/assign`,
                { user_id: chosen.id, note: note.trim() || null },
                JSON_HEADERS,
            )
            .then((response) => {
                toast.success(
                    response.data?.message ?? `${chosen.name} has been told.`,
                );
                onClose();
                refreshAlerts();
            })
            .catch((error) =>
                setResult(
                    failure(error, 'It couldn’t be assigned. Try again.'),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={UserRoundCheck}
            title="Assign alert"
            description="Choose who looks after it. They’re told straight away"
            context={alertContext(alert)}
            error={result.error}
            callout={
                result.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={personId !== '' || note.trim() !== ''}
            draftName={`Assign alert for ${alert.title}`}
            processing={processing}
            processingLabel={result.unconfirmed ? 'Checking…' : 'Assigning…'}
            submitDisabled={!result.unconfirmed && !chosen}
            submitLabel={result.unconfirmed ? 'Check result' : 'Assign'}
            onSubmit={submit}
            testId="sd-assign-alert"
        >
            <PopupField
                label="Assign to"
                required
                hint={chosen?.hint ?? undefined}
                error={result.fields?.user_id}
            >
                {listState === 'ready' && people && people.length > 0 ? (
                    <VehicleSearchSelect
                        label="Person"
                        value={personId}
                        options={people.map((person) => ({
                            value: String(person.id),
                            label: person.name,
                            description: person.hint ?? undefined,
                        }))}
                        onChange={setPersonId}
                        invalid={!!result.fields?.user_id}
                    />
                ) : listState === 'ready' ? (
                    <p className="text-subtle">
                        Nobody else can look after alerts at this Site.
                    </p>
                ) : (
                    <ListState
                        state={listState === 'failed' ? 'failed' : 'loading'}
                        loading="Finding people who can look after it…"
                        failed="The list of people couldn’t load."
                        onRetry={() => loadPeople(alert.id)}
                    />
                )}
            </PopupField>
            <PopupField
                label="Note"
                htmlFor="alert-assign-note"
                error={result.fields?.note}
            >
                <Textarea
                    id="alert-assign-note"
                    rows={3}
                    maxLength={1000}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder="Optional"
                />
            </PopupField>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  20 (also) · Snooze alert                                           */
/* ------------------------------------------------------------------ */

export function SnoozeAlert({
    open,
    alert,
    onClose,
}: {
    open: boolean;
    alert: AlertTarget | null;
    onClose: () => void;
}) {
    const [until, setUntil] = useState('');
    const [initial, setInitial] = useState('');
    const [why, setWhy] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        if (open) {
            const start = nextWholeHour(60);
            setUntil(start);
            setInitial(start);
            setWhy('');
            setResult({});
        }
    }, [open, alert?.id]);
    if (!alert) return null;

    const submit = () => {
        if (result.unconfirmed) {
            setProcessing(true);
            refreshAlerts(() => {
                setProcessing(false);
                onClose();
            });
            return;
        }
        const at = nzInstant(until);
        if ('error' in at) return setResult({ fields: { until: at.error } });
        const ahead = Date.parse(at.iso) - Date.now();
        if (ahead < 5 * 60_000)
            return setResult({
                fields: { until: 'Choose a time at least 5 minutes from now.' },
            });
        if (ahead > 7 * 86_400_000)
            return setResult({
                fields: {
                    until: 'Snooze for a week at most. For planned work, use Quiet time.',
                },
            });
        setProcessing(true);
        setResult({});
        axios
            .post<{ message?: string }>(
                `${BASE}/${alert.id}/snooze`,
                { until: at.iso, why: why.trim() || null },
                JSON_HEADERS,
            )
            .then((response) => {
                toast.success(
                    response.data?.message ??
                        'Snoozed. It comes back early if it gets worse.',
                );
                onClose();
                refreshAlerts();
            })
            .catch((error) =>
                setResult(failure(error, 'It couldn’t be snoozed. Try again.')),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={AlarmClock}
            title="Snooze alert"
            description="Hide it until a time. It comes back if it gets worse"
            context={alertContext(alert)}
            error={result.error}
            callout={
                result.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={until !== initial || why.trim() !== ''}
            draftName={`Snooze alert for ${alert.title}`}
            processing={processing}
            processingLabel={result.unconfirmed ? 'Checking…' : 'Snoozing…'}
            submitDisabled={!result.unconfirmed && !validLocalDateTime(until)}
            submitLabel={result.unconfirmed ? 'Check result' : 'Snooze'}
            onSubmit={submit}
            testId="sd-snooze-alert"
        >
            <DateTimeField
                id="alert-snooze-until"
                label="Snooze until"
                value={until}
                onChange={setUntil}
                error={result.fields?.until}
                clearable={false}
            />
            <PopupField
                label="Why"
                htmlFor="alert-snooze-why"
                error={result.fields?.why}
            >
                <Input
                    id="alert-snooze-why"
                    maxLength={500}
                    value={why}
                    onChange={(event) => setWhy(event.target.value)}
                    placeholder="e.g. Waiting for the power company"
                />
            </PopupField>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  44 · Quiet time for planned work                                   */
/* ------------------------------------------------------------------ */

export function QuietTime({
    open,
    devices,
    devicesFailed,
    loadDevices,
    actorId,
    onClose,
}: {
    open: boolean;
    /** Undefined while the list loads. */
    devices: PickableDevice[] | undefined;
    devicesFailed: boolean;
    loadDevices: () => void;
    actorId: number | undefined;
    onClose: () => void;
}) {
    const command = useMaintenanceCommand({
        actorId,
        active: open,
        context: 'security-devices.alerts.quiet-time',
    });
    const [chosen, setChosen] = useState<number[]>([]);
    const [paused, setPaused] = useState<number[]>([]);
    const [from, setFrom] = useState('');
    const [until, setUntil] = useState('');
    const [why, setWhy] = useState('');
    const [fields, setFields] = useState<Record<string, string>>({});
    const [stopped, setStopped] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (open) {
            const start = nextWholeHour(30);
            setChosen([]);
            setPaused([]);
            setFrom(start);
            setUntil(addLocalHours(start, 1));
            setWhy('');
            setFields({});
            setStopped(null);
        }
    }, [open]);
    const byId = useMemo(
        () => new Map((devices ?? []).map((device) => [device.id, device])),
        [devices],
    );
    const left = chosen.filter((id) => !paused.includes(id));
    const dirty = chosen.length > 0 || why.trim() !== '';

    const submit = async () => {
        if (busy || command.blocked) return;
        const errors: Record<string, string> = {};
        const start = nzInstant(from);
        const end = nzInstant(until);
        if (chosen.length === 0) errors.devices = 'Choose at least one device.';
        if ('error' in start) errors.starts_at = start.error;
        if ('error' in end) errors.ends_at = end.error;
        if (
            'iso' in start &&
            'iso' in end &&
            Date.parse(end.iso) <= Date.parse(start.iso)
        )
            errors.ends_at = 'Until must be after From.';
        if (why.trim().length < 10)
            errors.reason =
                'Say why in at least 10 characters, so others know what the work is.';
        setFields(errors);
        setStopped(null);
        if (
            Object.keys(errors).length > 0 ||
            !('iso' in start) ||
            !('iso' in end)
        )
            return;
        setBusy(true);
        const done = [...paused];
        try {
            for (const id of left) {
                const device = byId.get(id);
                if (!device) continue;
                const reply = await command.submit(
                    'create',
                    null,
                    {
                        site_id: device.site_id,
                        device_id: device.id,
                        name: `Quiet time · ${device.name}`.slice(0, 128),
                        starts_at: start.iso,
                        ends_at: end.iso,
                        timezone: 'Pacific/Auckland',
                        dst_overlap: 'reject',
                        dst_gap: 'reject',
                        reason: why.trim(),
                    },
                    {
                        site_id: device.site_id,
                        device_id: device.id,
                        monitor_id: null,
                        monitor_device_id: null,
                    },
                );
                if (!reply) {
                    setStopped(device.name);
                    break;
                }
                done.push(id);
                setPaused([...done]);
            }
        } finally {
            setBusy(false);
        }
        if (done.length === chosen.length) {
            toast.success(
                chosen.length === 1
                    ? 'Alerts paused for 1 device.'
                    : `Alerts paused for ${chosen.length} devices.`,
            );
            onClose();
            // S&D round 3: the Quiet times tab and its count update too.
            router.reload({ only: ['meters', 'quiet_times', 'counts'] });
        }
    };

    const serverError =
        command.errors.form ??
        command.errors.reason ??
        command.errors.starts_at ??
        command.errors.ends_at ??
        command.errors.site_id ??
        command.errors.device_id;
    const error =
        stopped && !command.blocked
            ? `${paused.length > 0 ? `Paused for ${paused.length} of ${chosen.length}. ` : ''}${stopped} couldn’t be paused${serverError ? `: ${serverError}` : '.'}`
            : undefined;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={BellOff}
            title="Quiet time for planned work"
            description="Pause alerts while work is done, so nobody is woken for nothing"
            error={error}
            callout={
                command.blocked && command.message
                    ? { tone: 'warning', content: command.message }
                    : undefined
            }
            dirty={dirty && paused.length === 0}
            draftName="Quiet time for planned work"
            processing={busy || command.busy}
            processingLabel="Pausing…"
            submitDisabled={command.blocked || !devices}
            destructive
            submitLabel="Pause alerts"
            onSubmit={() => void submit()}
            footerStart={
                command.blocked && command.pending ? (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={command.busy}
                        onClick={() =>
                            void command.recover().then((reply) => {
                                if (!reply) return;
                                const deviceId = reply.receipt.scope.device_id;
                                if (
                                    deviceId !== null &&
                                    chosen.includes(deviceId)
                                )
                                    setPaused((current) =>
                                        current.includes(deviceId)
                                            ? current
                                            : [...current, deviceId],
                                    );
                                toast.success('That pause was saved.');
                            })
                        }
                    >
                        Check saved request
                    </Button>
                ) : null
            }
            testId="sd-quiet-time"
        >
            <PopupField
                label="Pause alerts for"
                required
                error={fields.devices}
            >
                {devices === undefined ? (
                    <ListState
                        state={devicesFailed ? 'failed' : 'loading'}
                        loading="Loading your devices…"
                        failed="Your devices couldn’t load."
                        onRetry={loadDevices}
                    />
                ) : (
                    <div className="grid gap-2">
                        {chosen.length > 0 ? (
                            <ul
                                className="flex flex-wrap gap-1.5"
                                aria-label="Devices to pause"
                            >
                                {chosen.map((id) => {
                                    const device = byId.get(id);
                                    return (
                                        <li
                                            key={id}
                                            className="inline-flex items-center gap-1 rounded-full border border-primary bg-primary-fill/10 py-1 pr-1 pl-3 text-sm font-medium text-primary"
                                        >
                                            {device?.name ?? 'Device'}
                                            {paused.includes(id) ? (
                                                <span className="text-caption pr-2">
                                                    · paused
                                                </span>
                                            ) : null}
                                            {!paused.includes(id) ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="xs"
                                                    className="size-6 rounded-full p-0 text-primary"
                                                    aria-label={`Remove ${device?.name ?? 'device'}`}
                                                    onClick={() =>
                                                        setChosen((current) =>
                                                            current.filter(
                                                                (value) =>
                                                                    value !==
                                                                    id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X className="size-3.5" />
                                                </Button>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : null}
                        {devices.length === 0 ? (
                            <p className="text-subtle">
                                No network gear or computers you can pause at
                                your Sites.
                            </p>
                        ) : (
                            <VehicleSearchSelect
                                label="Device to add"
                                value=""
                                options={devices
                                    .filter(
                                        (device) => !chosen.includes(device.id),
                                    )
                                    .map((device) => ({
                                        value: String(device.id),
                                        label: device.name,
                                        description: [device.site, device.kind]
                                            .filter(Boolean)
                                            .join(' · '),
                                    }))}
                                onChange={(value) =>
                                    setChosen((current) =>
                                        current.includes(Number(value))
                                            ? current
                                            : [...current, Number(value)],
                                    )
                                }
                                invalid={!!fields.devices}
                            />
                        )}
                    </div>
                )}
            </PopupField>
            <DateTimeField
                id="quiet-from"
                label="From"
                value={from}
                onChange={setFrom}
                error={fields.starts_at}
                clearable={false}
            />
            <DateTimeField
                id="quiet-until"
                label="Until"
                value={until}
                onChange={setUntil}
                error={fields.ends_at}
                clearable={false}
            />
            <PopupField
                label="Why"
                htmlFor="quiet-why"
                required
                hint="Kept in the history"
                error={fields.reason}
            >
                <Input
                    id="quiet-why"
                    maxLength={500}
                    value={why}
                    onChange={(event) => setWhy(event.target.value)}
                    placeholder="e.g. Switch and Wi-Fi firmware update · Jordan Peri"
                />
            </PopupField>
            <PopupCallout tone="info">
                Panic buttons, alarms and bed sensors are never paused.
            </PopupCallout>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  45 · Report incident from this alert                               */
/* ------------------------------------------------------------------ */

export function alertDetailsText(
    alert: SelectedAlert,
    now: Date = new Date(),
): string {
    const where =
        alert.facts.find((fact) => fact.icon === 'map-pin')?.text ?? null;
    return [
        `What: ${alert.title}`,
        alert.device
            ? `Device: ${alert.device.name}${alert.device.sub ? ` (${alert.device.sub})` : ''}`
            : null,
        `Where and when: ${[where, seenTime(alert.opened_at, now)].filter(Boolean).join(' · ')}`,
        `What we saw: ${timedText({ text: alert.saw, at: alert.opened_at }, now)}`,
    ]
        .filter(Boolean)
        .join('\n');
}

export function ReportIncidentFromAlert({
    open,
    alert,
    onClose,
}: {
    open: boolean;
    alert: SelectedAlert | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    const [copied, setCopied] = useState<'yes' | 'no' | null>(null);
    useEffect(() => {
        if (open) {
            setResult({});
            setCopied(null);
        }
    }, [open, alert?.id]);
    if (!alert) return null;
    const now = new Date();
    const where =
        alert.facts.find((fact) => fact.icon === 'map-pin')?.text ?? null;

    const copy = () => {
        const text = alertDetailsText(alert, now);
        if (!navigator.clipboard?.writeText) return setCopied('no');
        navigator.clipboard.writeText(text).then(
            () => setCopied('yes'),
            () => setCopied('no'),
        );
    };
    const submit = () => {
        // The note on the alert may already be saved: open the form without sending it again.
        if (result.unconfirmed)
            return router.visit('/incidents/create?type=incident');
        setProcessing(true);
        setResult({});
        axios
            .post<{ url?: string }>(
                `${BASE}/${alert.id}/incident`,
                {},
                JSON_HEADERS,
            )
            .then((response) => {
                onClose();
                router.visit(
                    response.data?.url ?? '/incidents/create?type=incident',
                );
            })
            .catch((error) =>
                setResult(
                    failure(
                        error,
                        'The incident form couldn’t be opened. Try again.',
                    ),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ClipboardPlus}
            title="Report incident from this alert"
            description="Opens the incident form, with the alert’s details ready to paste in"
            context={alertContext(alert, now)}
            error={result.error}
            callout={
                result.unconfirmed
                    ? {
                          tone: 'warning',
                          content:
                              'Result not confirmed: we couldn’t check the alert noted it. You can still open the incident form.',
                      }
                    : undefined
            }
            processing={processing}
            processingLabel="Opening…"
            submitLabel="Open incident form"
            onSubmit={submit}
            testId="sd-report-incident"
        >
            <PopupFacts
                rows={[
                    ['What', alert.title],
                    [
                        'Where and when',
                        [where, seenTime(alert.opened_at, now)]
                            .filter(Boolean)
                            .join(' · '),
                    ],
                    ...(alert.device
                        ? ([
                              [
                                  'Device',
                                  [alert.device.name, alert.device.sub]
                                      .filter(Boolean)
                                      .join(' · '),
                              ],
                          ] as [string, string][])
                        : []),
                ]}
            />
            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="outline" onClick={copy}>
                    <ClipboardCopy aria-hidden="true" />
                    Copy the details
                </Button>
                <p className="text-subtle" role="status">
                    {copied === 'yes'
                        ? 'Copied. '
                        : copied === 'no'
                          ? 'Couldn’t copy here: select the details above and copy them. '
                          : ''}
                    Paste them into the incident form.
                </p>
            </div>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  21 · Add alert rule                                                */
/* ------------------------------------------------------------------ */

type When = 'any' | 'offline';
type Where = 'application' | 'site' | 'device';
type How = 'app' | 'email' | 'ticket';

const KINDS: { value: string; label: string }[] = [
    { value: 'any', label: 'Any kind' },
    { value: 'network', label: 'Network' },
    { value: 'endpoint', label: 'Computers' },
    { value: 'server', label: 'Servers' },
    { value: 'storage', label: 'Storage' },
    { value: 'power', label: 'Power (UPS)' },
    { value: 'printer', label: 'Printers' },
    { value: 'voice', label: 'Phones' },
];

const HOW: { value: How; label: string }[] = [
    { value: 'app', label: 'App' },
    { value: 'email', label: 'Email' },
    { value: 'ticket', label: 'IT ticket' },
];

export function AddAlertRule({
    open,
    form,
    formFailed,
    sites,
    devices,
    devicesFailed,
    load,
    onClose,
}: {
    open: boolean;
    /** Undefined while it loads. */
    form: RuleForm | null | undefined;
    formFailed: boolean;
    sites: { value: string; label: string }[];
    devices: PickableDevice[] | undefined;
    devicesFailed: boolean;
    /** Load the form choices and the device list (each only once). */
    load: () => void;
    onClose: () => void;
}) {
    const canAll = !!form?.can_all_sites;
    const [when, setWhen] = useState<When>('any');
    const [where, setWhere] = useState<Where>(canAll ? 'application' : 'site');
    const [siteId, setSiteId] = useState('');
    const [deviceId, setDeviceId] = useState('');
    const [kind, setKind] = useState('any');
    const [role, setRole] = useState('');
    const [fails, setFails] = useState<How[]>(['app', 'email', 'ticket']);
    const [attention, setAttention] = useState<How[]>(['app']);
    const [name, setName] = useState('');
    const [named, setNamed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        if (open) {
            setWhen('any');
            setWhere(canAll ? 'application' : 'site');
            setSiteId('');
            setDeviceId('');
            setKind('any');
            setRole('');
            setFails(['app', 'email', 'ticket']);
            setAttention(['app']);
            setName('');
            setNamed(false);
            setResult({});
        }
    }, [open, canAll]);
    const itDevices = useMemo(
        () =>
            (devices ?? []).filter(
                (device) => device.domain === 'it_infrastructure',
            ),
        [devices],
    );
    const roles = form?.roles ?? [];
    // Nobody told when a device fails turns its alerts off for these devices: say so, in red.
    const mutes = !fails.includes('app') && !fails.includes('email');
    const roleLabel =
        role === ''
            ? 'IT for that Site'
            : (roles.find((option) => option.value === role)?.label ?? role);
    const placeLabel =
        where === 'application'
            ? 'All Sites'
            : where === 'site'
              ? (sites.find((site) => site.value === siteId)?.label ??
                'one Site')
              : (itDevices.find((device) => String(device.id) === deviceId)
                    ?.name ?? 'one device');
    const kindLabel =
        where === 'device'
            ? null
            : kind === 'any'
              ? null
              : (KINDS.find((option) => option.value === kind)?.label ?? null);
    const suggested =
        `${when === 'offline' ? 'Stops answering' : 'Check fails'} · ${placeLabel}${kindLabel ? ` · ${kindLabel.toLowerCase()}` : ''} · tell ${roleLabel}`.slice(
            0,
            128,
        );
    const shownName = named ? name : suggested;

    const submit = () => {
        const errors: Record<string, string> = {};
        if (where === 'site' && !siteId) errors.site_id = 'Choose the Site.';
        if (where === 'device' && !deviceId)
            errors.device_id = 'Choose the device.';
        if (shownName.trim().length < 3)
            errors.name = 'Give the rule a name of at least 3 characters.';
        if (Object.keys(errors).length > 0)
            return setResult({ fields: errors });
        setProcessing(true);
        setResult({});
        axios
            .post(
                RULES,
                {
                    name: shownName.trim(),
                    scope_kind: where,
                    site_id: where === 'site' ? Number(siteId) : null,
                    device_id: where === 'device' ? Number(deviceId) : null,
                    device_category:
                        where === 'device' || kind === 'any' ? null : kind,
                    monitor_kind: when === 'offline' ? 'icmp' : null,
                    critical_notify_in_app: fails.includes('app'),
                    critical_notify_email: fails.includes('email'),
                    critical_open_it_ticket: fails.includes('ticket'),
                    warning_notify_in_app: attention.includes('app'),
                    warning_notify_email: attention.includes('email'),
                    warning_open_it_ticket: attention.includes('ticket'),
                    // Escalating to Control Room isn't acted on yet, so a rule never claims it.
                    critical_escalate_control_room: false,
                    recipient_role: role === '' ? null : role,
                },
                JSON_HEADERS,
            )
            .then(() => {
                toast.success('Rule added.');
                onClose();
                router.reload({ only: ['rules', 'counts'] });
            })
            .catch((error) => {
                const outcome = failure(
                    error,
                    'The rule couldn’t be added. Try again.',
                );
                setResult(
                    outcome.unconfirmed
                        ? {
                              error: 'Result not confirmed: the connection may have dropped. Close this and check the rules list before adding it again.',
                          }
                        : outcome,
                );
            })
            .finally(() => setProcessing(false));
    };

    const fieldError = result.fields
        ? (result.fields.scope_kind ??
          result.fields.device_domain ??
          result.fields.monitor_id ??
          result.fields.device_category ??
          result.fields.recipient_role)
        : undefined;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="lg"
            icon={BellPlus}
            title="Add alert rule"
            description="When something happens, who is told and how"
            error={result.error ?? fieldError}
            callout={{
                tone: 'info',
                content:
                    'Rules are for network gear and computers. Cameras, alarms, doors and health kit always go to Control Room and can’t be rerouted or muted.',
            }}
            dirty={
                named ||
                where !== (canAll ? 'application' : 'site') ||
                siteId !== '' ||
                deviceId !== '' ||
                role !== '' ||
                when !== 'any' ||
                kind !== 'any'
            }
            draftName="Add alert rule"
            processing={processing}
            processingLabel="Adding…"
            submitDisabled={!form}
            destructive={mutes}
            submitLabel="Add rule"
            onSubmit={submit}
            testId="sd-add-alert-rule"
        >
            {form === null ? (
                <PopupCallout tone="warning">
                    You can’t add alert rules. People who manage device checks
                    can.
                </PopupCallout>
            ) : form === undefined ? (
                <ListState
                    state={formFailed ? 'failed' : 'loading'}
                    loading="Loading who can be told…"
                    failed="The choices for a rule couldn’t load."
                    onRetry={load}
                />
            ) : null}
            {form ? (
                <>
                    <PopupSection icon={Zap} title="When">
                        <PopupField label="What happens">
                            <ChoiceTiles<When>
                                label="What happens"
                                columns={2}
                                value={when}
                                onChange={setWhen}
                                options={[
                                    {
                                        value: 'any',
                                        label: 'A check fails or needs attention',
                                        description: 'Any check on the device',
                                        icon: Bell,
                                    },
                                    {
                                        value: 'offline',
                                        label: 'Device offline',
                                        description:
                                            'Stops answering its online check',
                                        icon: WifiOff,
                                    },
                                ]}
                            />
                        </PopupField>
                    </PopupSection>
                    <PopupSection icon={MapPin} title="Where">
                        <PopupField label="Where it applies">
                            <SingleChips<Where>
                                label="Where it applies"
                                value={where}
                                onChange={(next) => {
                                    setWhere(next);
                                    if (next === 'device') load();
                                }}
                                options={[
                                    ...(canAll
                                        ? [
                                              {
                                                  value: 'application' as Where,
                                                  label: 'All Sites',
                                              },
                                          ]
                                        : []),
                                    { value: 'site', label: 'One Site' },
                                    { value: 'device', label: 'One device' },
                                ]}
                            />
                        </PopupField>
                        {where === 'site' ? (
                            <PopupField
                                label="Site"
                                required
                                error={result.fields?.site_id}
                            >
                                <VehicleSearchSelect
                                    label="Site"
                                    value={siteId}
                                    options={sites}
                                    onChange={setSiteId}
                                    invalid={!!result.fields?.site_id}
                                />
                            </PopupField>
                        ) : null}
                        {where === 'device' ? (
                            <PopupField
                                label="Device"
                                required
                                error={result.fields?.device_id}
                                hint="Network gear and computers only"
                            >
                                {devices === undefined ? (
                                    <ListState
                                        state={
                                            devicesFailed ? 'failed' : 'loading'
                                        }
                                        loading="Loading your devices…"
                                        failed="Your devices couldn’t load."
                                        onRetry={load}
                                    />
                                ) : itDevices.length === 0 ? (
                                    <p className="text-subtle">
                                        No network gear or computers at your
                                        Sites yet.
                                    </p>
                                ) : (
                                    <VehicleSearchSelect
                                        label="Device"
                                        value={deviceId}
                                        options={itDevices.map((device) => ({
                                            value: String(device.id),
                                            label: device.name,
                                            description: [
                                                device.site,
                                                device.kind,
                                            ]
                                                .filter(Boolean)
                                                .join(' · '),
                                        }))}
                                        onChange={setDeviceId}
                                        invalid={!!result.fields?.device_id}
                                    />
                                )}
                            </PopupField>
                        ) : (
                            <PopupField
                                label="Kind of device"
                                error={result.fields?.device_category}
                            >
                                <SingleChips
                                    label="Kind of device"
                                    value={kind}
                                    onChange={setKind}
                                    options={KINDS}
                                />
                            </PopupField>
                        )}
                    </PopupSection>
                    <PopupSection icon={Users} title="Who is told">
                        <PopupField
                            label="People"
                            hint="Only people in that role who look after IT or devices at the Site are told"
                            error={result.fields?.recipient_role}
                        >
                            <VehicleSearchSelect
                                label="Who is told"
                                value={role}
                                options={[
                                    {
                                        value: '',
                                        label: 'IT for that Site',
                                        description:
                                            'Everyone who looks after IT at the Site',
                                    },
                                    ...roles,
                                ]}
                                onChange={setRole}
                            />
                        </PopupField>
                    </PopupSection>
                    <PopupSection icon={Send} title="How" columns={2}>
                        <PopupField label="When it fails">
                            <Chips<How>
                                label="When it fails"
                                options={HOW}
                                value={fails}
                                onChange={setFails}
                            />
                        </PopupField>
                        <PopupField label="When it needs attention">
                            <Chips<How>
                                label="When it needs attention"
                                options={HOW}
                                value={attention}
                                onChange={setAttention}
                            />
                        </PopupField>
                        {mutes ? (
                            <div className="sm:col-span-full">
                                <PopupCallout tone="critical">
                                    Nobody will be told when these devices fail.
                                    Choose App or Email unless that is what you
                                    want.
                                </PopupCallout>
                            </div>
                        ) : null}
                        <PopupField
                            label="Rule name"
                            htmlFor="alert-rule-name"
                            wide
                            required
                            hint="Shown in the rules list and the change history"
                            error={result.fields?.name}
                        >
                            <Input
                                id="alert-rule-name"
                                maxLength={128}
                                value={shownName}
                                onChange={(event) => {
                                    setNamed(true);
                                    setName(event.target.value);
                                }}
                            />
                        </PopupField>
                    </PopupSection>
                </>
            ) : null}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Turn a rule off or on (a confirm with a reason)                    */
/* ------------------------------------------------------------------ */

export function RuleOnOff({
    rule,
    onClose,
}: {
    rule: AlertRuleRow | null;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<Failure>({});
    useEffect(() => {
        setReason('');
        setResult({});
    }, [rule?.id]);
    if (!rule) return null;
    const turningOff = rule.on;

    const confirm = () => {
        if (result.unconfirmed) {
            setProcessing(true);
            router.reload({
                only: ['rules', 'counts'],
                onFinish: () => {
                    setProcessing(false);
                    onClose();
                },
            });
            return;
        }
        if (reason.trim().length < 10)
            return setResult({
                fields: { reason: 'Say why in at least 10 characters.' },
            });
        setProcessing(true);
        setResult({});
        axios
            .post(
                `${RULES}/${rule.id}/${turningOff ? 'deactivate' : 'reactivate'}`,
                { version: rule.version, reason: reason.trim() },
                JSON_HEADERS,
            )
            .then(() => {
                toast.success(
                    turningOff ? 'Rule turned off.' : 'Rule turned on.',
                );
                onClose();
                router.reload({ only: ['rules', 'counts'] });
            })
            .catch((error) =>
                setResult(
                    failure(
                        error,
                        'The rule couldn’t be changed. Try again.',
                        'This rule changed after you opened it. Close this and look at it again.',
                    ),
                ),
            )
            .finally(() => setProcessing(false));
    };

    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={confirm}
            variant={turningOff ? 'warning' : 'plain'}
            confirmDestructive={turningOff && !result.unconfirmed}
            icon={turningOff ? PowerOff : Power}
            title={
                turningOff ? 'Turn off this rule?' : 'Turn this rule back on?'
            }
            description={
                turningOff
                    ? 'People this rule tells stop being told by it. The next rule that fits, or the standard routing for network gear, takes over. It stays in the history.'
                    : 'People this rule names are told again from now on.'
            }
            context={{
                icon: Bell,
                title: rule.when,
                tag: rule.where,
                note: rule.name,
            }}
            confirmLabel={
                result.unconfirmed
                    ? 'Check result'
                    : turningOff
                      ? 'Turn off rule'
                      : 'Turn on rule'
            }
            processing={processing}
            error={result.error ?? result.fields?.version}
            testId="sd-rule-on-off"
        >
            {result.unconfirmed ? (
                <PopupCallout tone="warning">
                    {UNCONFIRMED.replace('the alert', 'the rules list')}
                </PopupCallout>
            ) : null}
            <PopupField
                label={turningOff ? 'Why turn it off?' : 'Why turn it on?'}
                htmlFor="rule-reason"
                required
                hint="Kept in the history"
                error={result.fields?.reason}
            >
                <Textarea
                    id="rule-reason"
                    rows={3}
                    maxLength={500}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder={
                        turningOff
                            ? 'e.g. Jordan has left; the house manager takes these now'
                            : 'e.g. Back from leave'
                    }
                />
            </PopupField>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  The story of an alert that is done (Done tab rows)                 */
/* ------------------------------------------------------------------ */

export function TimelineList({
    items,
    now,
}: {
    items: SelectedAlert['timeline'];
    now: Date;
}) {
    if (items.length === 0)
        return <p className="text-subtle">Nothing recorded yet.</p>;
    return (
        <ol className="grid divide-y divide-border">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="grid grid-cols-[6.5rem_1rem_minmax(0,1fr)] items-start gap-3 py-2.5 text-sm"
                >
                    <time className="text-muted-foreground" dateTime={item.at}>
                        {seenTime(item.at, now)}
                    </time>
                    <span className="pt-0.5">
                        <Ring tone={item.tone} />
                    </span>
                    <span className="min-w-0">
                        <b className="block font-semibold">{item.title}</b>
                        {item.sub ? (
                            <small className="text-caption block">
                                {item.sub}
                            </small>
                        ) : null}
                    </span>
                </li>
            ))}
        </ol>
    );
}

export function AlertViewer({
    open,
    alert,
    failed,
    onRetry,
    onReportIncident,
    onClose,
}: {
    open: boolean;
    /** Null while it loads. */
    alert: SelectedAlert | null;
    failed: boolean;
    onRetry: () => void;
    onReportIncident: (() => void) | null;
    onClose: () => void;
}) {
    const now = new Date();
    const where =
        alert?.facts.find((fact) => fact.icon === 'map-pin')?.text ?? null;
    const checked =
        alert?.facts.find((fact) => fact.icon === 'network')?.text ?? null;
    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={BellRing}
            title={alert?.title ?? 'Alert'}
            description={
                alert
                    ? alertLine(alert.label.text, alert.label.at, now, {
                          closed: alert.closed_at,
                      })
                    : 'What happened'
            }
            action={
                alert && onReportIncident && alert.can.report_incident ? (
                    <Button type="button" onClick={onReportIncident}>
                        Report incident
                    </Button>
                ) : null
            }
            testId="sd-alert-viewer"
        >
            {alert ? (
                <>
                    <PopupFacts
                        rows={[
                            ...(where
                                ? ([['Where', where]] as [string, string][])
                                : []),
                            ['Started', seenTime(alert.opened_at, now)],
                            [
                                'Ended',
                                alert.closed_at
                                    ? seenTime(alert.closed_at, now)
                                    : 'Still open',
                            ],
                            ...(checked
                                ? ([['Checked by', checked]] as [
                                      string,
                                      string,
                                  ][])
                                : []),
                        ]}
                    />
                    <div className="grid gap-1">
                        <h3 className="text-sm font-semibold">What we saw</h3>
                        <p className="text-sm">
                            {timedText(
                                { text: alert.saw, at: alert.opened_at },
                                now,
                            )}
                        </p>
                    </div>
                    <div className="grid gap-1">
                        <h3 className="text-sm font-semibold">Timeline</h3>
                        <TimelineList items={alert.timeline} now={now} />
                    </div>
                </>
            ) : (
                <ListState
                    state={failed ? 'failed' : 'loading'}
                    loading="Loading what happened…"
                    failed="This alert couldn’t load."
                    onRetry={onRetry}
                />
            )}
        </PopupViewer>
    );
}
