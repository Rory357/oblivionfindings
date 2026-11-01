/**
 * The door's pop-ups (S&D rebuild drawings sdr-18 Unlock the front door? and sdr-70 Keep the door
 * unlocked), on the pop-up kit at kit widths. Both state the effect first and ask for a reason; both
 * are a person's action only, because automations never unlock a door (D12).
 *
 * "Unlock for N seconds" is today's governed timed unlock: the seconds are the door's own approved
 * setting and it locks again by itself. When the framework wants the person to confirm it's them, or
 * a second person's approval, the pop-up says so. "Keep unlocked" is drawn here, but no governed
 * command can keep a door unlocked for hours yet, so the door page never opens it until one exists.
 */
import { DateTimeField, validLocalDateTime } from '@/components/fleet-assets/maintenance/date-time-field';
import { PopupCallout, PopupConfirm, PopupField, PopupForm } from '@/components/popup';
import { Textarea } from '@/components/ui/textarea';
import { toDatetimeLocal } from '@/lib/datetime';
import type { GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
import { DoorClosed, LockOpen } from 'lucide-react';
import { useEffect, useState } from 'react';
import { confirmIdentity, UNCONFIRMED_WORDS, useSafetyRequest } from './safety-request';

export type DoorPopupDoor = {
    id: number;
    name: string;
    model: string;
    site: string | null;
    lockWords: string;
};

const RELOAD = ['control', 'lock', 'overview', 'meters'];

/** "Front door lock" → "the front door"; a name that starts with a place keeps its capitals. */
export function doorWords(name: string): string {
    const bare = name.replace(/\s+lock$/i, '').trim() || name;
    const words = bare.split(/\s+/);
    if (words.length > 1 && /^[A-Z]/.test(words[0]) && /^[a-z]/.test(words[1])) {
        return `the ${words[0].toLowerCase()} ${words.slice(1).join(' ')}`;
    }
    return bare;
}

/* ------------------------------------------------------------------ */
/*  18 · Unlock the front door?                                        */
/* ------------------------------------------------------------------ */

export function UnlockDoor({
    open,
    door,
    action,
    seconds,
    confirmUrl,
    onClose,
}: {
    open: boolean;
    door: DoorPopupDoor;
    action: GovernedAction;
    seconds: number;
    confirmUrl: string;
    onClose: () => void;
}) {
    const { state, send, check } = useSafetyRequest(open, 'unlock');
    const [reason, setReason] = useState('');
    useEffect(() => {
        if (open) setReason('');
    }, [open]);
    const stepUp = action.step_up;
    const reasonOk = reason.trim().length >= 10;

    const submit = () => {
        if (state.unconfirmed) return check(RELOAD, onClose);
        if (stepUp) return confirmIdentity(confirmUrl);
        send(`/security-devices/devices/${door.id}/safety/unlock`, { reason: reason.trim() }, onClose);
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={submit}
            variant="warning"
            icon={LockOpen}
            title={`Unlock ${doorWords(door.name)}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{door.name}</b>
                    {door.site ? ` at ${door.site}` : ''} will unlock for {seconds} seconds.
                </>
            }
            error={state.unconfirmed ? undefined : state.error}
            confirmLabel={state.unconfirmed ? 'Check result' : stepUp ? 'Confirm it’s you' : 'Unlock'}
            processing={state.processing}
            processingLabel={state.unconfirmed ? 'Checking…' : 'Unlocking…'}
            confirmDisabled={!action.available || (!stepUp && !state.unconfirmed && !reasonOk)}
            testId="sd-unlock-door"
        >
            {state.unconfirmed ? <PopupCallout tone="warning">{UNCONFIRMED_WORDS}</PopupCallout> : null}
            {!action.available && action.reason ? <PopupCallout tone="warning">{action.reason}</PopupCallout> : null}
            {stepUp ? (
                <PopupCallout tone="info">For your safety, confirm it’s you (your password) before you unlock a door. You’ll come straight back here.</PopupCallout>
            ) : (
                <PopupField label="Reason" htmlFor="sd-unlock-reason" required hint="Kept in the door’s history" error={state.fields.reason}>
                    <Textarea
                        id="sd-unlock-reason"
                        rows={2}
                        maxLength={1000}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="e.g. Letting the physio in"
                    />
                </PopupField>
            )}
            <PopupCallout tone="info">
                Unlocking is recorded with your name. The door locks again after {seconds} seconds.
                {action.approval ? ' A second person who approves device commands says yes first; you’ll see it waiting on this page.' : ''}
            </PopupCallout>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  70 · Keep the door unlocked                                        */
/* ------------------------------------------------------------------ */

const MAX_HOURS = 4;

/** "15:00" → "3:00 pm" (WRITING_STYLE_GUIDE W4). */
function clockWords(hhmm: string): string {
    const [hours, minutes] = hhmm.split(':').map(Number);
    return `${((hours + 11) % 12) + 1}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'am' : 'pm'}`;
}

function untilProblem(value: string, now: Date): string | null {
    if (!validLocalDateTime(value)) return 'Choose a date and a time.';
    // NZ wall-clock "YYYY-MM-DDTHH:mm" compared with NZ wall-clock now, as strings of the same shape.
    const nowLocal = toDatetimeLocal(now);
    const latestLocal = toDatetimeLocal(new Date(now.getTime() + MAX_HOURS * 3_600_000));
    if (value <= nowLocal) return 'Choose a time later than now.';
    if (value > latestLocal) return `Choose a time at most ${MAX_HOURS} hours ahead.`;
    return null;
}

export function KeepDoorUnlocked({
    open,
    door,
    onSubmit,
    processing = false,
    error,
    onClose,
}: {
    open: boolean;
    door: DoorPopupDoor;
    /** Sends the request; the door page passes it only once a governed command exists for it. */
    onSubmit: (until: string, reason: string) => void;
    processing?: boolean;
    error?: string;
    onClose: () => void;
}) {
    const [until, setUntil] = useState('');
    const [reason, setReason] = useState('');
    const [touched, setTouched] = useState(false);
    useEffect(() => {
        if (open) {
            setUntil(toDatetimeLocal(new Date(Date.now() + 3_600_000)).slice(0, 14) + '00');
            setReason('');
            setTouched(false);
        }
    }, [open]);
    const problem = untilProblem(until, new Date());
    const time = validLocalDateTime(until) ? clockWords(until.split('T')[1]) : null;
    const where = door.site ? ` at ${door.site}` : '';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={LockOpen}
            title="Keep the door unlocked"
            description={`Leave ${doorWords(door.name)} open for a while, then it locks by itself`}
            context={{ title: door.name, tag: door.site ?? undefined, note: `${door.model} · ${door.lockWords}`, icon: DoorClosed }}
            callout={{
                tone: 'warning',
                content: (
                    <>
                        <b className="font-semibold">
                            {doorWords(door.name).replace(/^the /, 'The ')}
                            {where} stays unlocked until {time ?? 'the time you choose'}.
                        </b>{' '}
                        Anyone can walk in until then.
                    </>
                ),
            }}
            error={error}
            dirty={reason.trim() !== ''}
            draftName={`Keep ${door.name} unlocked`}
            submitLabel="Keep unlocked"
            processing={processing}
            processingLabel="Asking…"
            submitDisabled={problem !== null || reason.trim().length < 10}
            onSubmit={() => {
                setTouched(true);
                if (problem === null && reason.trim().length >= 10) onSubmit(until, reason.trim());
            }}
            testId="sd-keep-unlocked"
        >
            <DateTimeField
                id="sd-keep-unlocked-until"
                label="Until"
                value={until}
                onChange={(value) => {
                    setUntil(value);
                    setTouched(true);
                }}
                hint="At most 4 hours ahead"
                error={touched && problem ? problem : undefined}
                clearable={false}
            />
            <PopupField label="Reason" htmlFor="sd-keep-unlocked-reason" required>
                <Textarea
                    id="sd-keep-unlocked-reason"
                    rows={3}
                    maxLength={1000}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder="Why does the door need to stay open?"
                />
            </PopupField>
            <PopupField label="Who is told">
                <p className="text-sm font-semibold text-foreground">The house manager and the staff on shift{where}</p>
            </PopupField>
            <PopupCallout tone="info">
                Locks again by itself{time ? ` at ${time}` : ''}. Only a person can do this; automations never unlock.
            </PopupCallout>
        </PopupForm>
    );
}
