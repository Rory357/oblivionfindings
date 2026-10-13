import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Monitor, MousePointer2, Square } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type KeyboardEvent,
    type PointerEvent,
} from 'react';
import { screenPoint, windowsKey } from './remote-input';
import { mode, record, uuid, type AccessMode } from './remote-protocol';
import {
    liveStatus,
    remoteJSON,
    RemoteViewer,
    sessionReply,
    stopRemote,
    type RemoteSession,
    type ViewerState,
} from './remote-viewer';

type Props = {
    open: boolean;
    onClose: () => void;
    actorId: number;
    device: { id: number; name: string };
    ticketId?: number;
};
type Options = {
    policy_version: number;
    enabled: boolean;
    consent_mode: string;
    allow_control: boolean;
    max_minutes: number;
};
export function RemoteConnectionDialog(props: Props) {
    return (
        <Dialog
            open={props.open}
            onOpenChange={(open) => {
                if (!open) props.onClose();
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                style={{
                    width: 'min(92vw, 1100px)',
                    maxWidth: 'min(92vw, 1100px)',
                }}
                onEscapeKeyDown={(event) => {
                    if (event.target instanceof HTMLCanvasElement)
                        event.preventDefault();
                }}
            >
                {props.open && <ConnectionBody {...props} />}
            </DialogContent>
        </Dialog>
    );
}
function ConnectionBody({ actorId, device, ticketId, onClose }: Props) {
    const [options, setOptions] = useState<Options | null>(null),
        [access, setAccess] = useState<AccessMode>('view');
    const [purpose, setPurpose] = useState(''),
        [notice, setNotice] = useState('Loading connection permissions…');
    const [attempted, setAttempted] = useState(false);
    const [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [session, setSession] = useState<RemoteSession | null>(null);
    const alive = useRef(true),
        submission = useRef<Record<string, unknown> | null>(null),
        pending = useRef(false);
    useEffect(() => {
        alive.current = true;
        const abort = new AbortController();
        void remoteJSON(
            '/it/remote-support/devices/' + device.id + '/connection-options',
            abort.signal,
        )
            .then((value) => {
                if (!alive.current) return;
                const r = record(value);
                if (
                    r.actor_id !== actorId ||
                    r.device_id !== device.id ||
                    !Number.isInteger(r.policy_version) ||
                    (r.policy_version as number) < 0 ||
                    typeof r.enabled !== 'boolean' ||
                    typeof r.allow_control !== 'boolean' ||
                    !['attended', 'unattended'].includes(
                        String(r.consent_mode),
                    ) ||
                    !Number.isInteger(r.max_minutes) ||
                    (r.max_minutes as number) < 5 ||
                    (r.max_minutes as number) > 120
                )
                    throw Error('Permissions changed');
                setOptions(r as unknown as Options);
                setNotice(
                    !r.enabled
                        ? 'Remote support is disabled for this computer.'
                        : r.consent_mode !== 'attended'
                          ? 'Unattended connections are not available in this build.'
                          : '',
                );
            })
            .catch(() => {
                if (alive.current && !abort.signal.aborted)
                    setNotice(
                        'Connection permissions could not be verified. Close this window and try again.',
                    );
            });
        return () => {
            alive.current = false;
            abort.abort();
        };
    }, [actorId, device.id]);
    async function connect() {
        if (
            pending.current ||
            !options ||
            !options.enabled ||
            options.consent_mode !== 'attended'
        )
            return;
        if (!submission.current) {
            if (
                purpose.trim().length < 10 ||
                purpose.trim().length > 500 ||
                /[\p{Cc}\p{Cf}]/u.test(purpose)
            ) {
                setNotice(
                    'Describe the support reason using 10–500 characters.',
                );
                return;
            }
            submission.current = {
                request_uuid: crypto.randomUUID(),
                expected_actor_id: actorId,
                expected_policy_version: options.policy_version,
                access_mode: access,
                purpose: purpose.trim(),
                ...(ticketId ? { ticket_id: ticketId } : {}),
            };
        }
        pending.current = true;
        setAttempted(true);
        setBusy(true);
        setNotice('');
        try {
            const value = await remoteJSON(
                '/it/remote-support/devices/' + device.id + '/sessions',
                undefined,
                submission.current,
            );
            if (!alive.current) {
                const r = record(value);
                if (uuid(r.uuid)) stopRemote(r.uuid);
                return;
            }
            const next = sessionReply(
                value,
                device.id,
                mode(submission.current.access_mode),
            );
            if (!liveStatus(next.status)) {
                setNotice(
                    'This request has ended. Close this window to make a new request.',
                );
                setUncertain(false);
                return;
            }
            setSession(next);
            setUncertain(false);
        } catch {
            if (alive.current) {
                setUncertain(true);
                setNotice(
                    'The request could not be confirmed. Check the same request before trying another. Unconfirmed requests expire automatically.',
                );
            }
        } finally {
            pending.current = false;
            if (alive.current) setBusy(false);
        }
    }
    return (
        <>
            <DialogHeader>
                <DialogTitle>Remote support · {device.name}</DialogTitle>
                <DialogDescription>
                    {session
                        ? 'The user can stop access at any time. Closing this window disconnects.'
                        : 'Ask the person at this computer to approve a support session.'}
                </DialogDescription>
            </DialogHeader>
            {session ? (
                <RemoteScreen key={session.uuid} session={session} />
            ) : (
                <form
                    id="remote-connection"
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void connect();
                    }}
                >
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    {options?.enabled &&
                        options.consent_mode === 'attended' && (
                            <>
                                <fieldset
                                    disabled={busy || attempted}
                                    className="space-y-2"
                                >
                                    <legend className="text-sm font-medium">
                                        Access requested
                                    </legend>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Button
                                            type="button"
                                            variant={
                                                access === 'view'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            aria-pressed={access === 'view'}
                                            onClick={() => setAccess('view')}
                                        >
                                            <Monitor aria-hidden="true" />
                                            View screen
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={
                                                access === 'control'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            disabled={!options.allow_control}
                                            aria-pressed={access === 'control'}
                                            onClick={() => setAccess('control')}
                                        >
                                            <MousePointer2 aria-hidden="true" />
                                            View and control
                                        </Button>
                                    </div>
                                    <p className="text-subtle">
                                        {options.allow_control
                                            ? 'Control includes mouse and keyboard access.'
                                            : 'Your current permissions allow viewing only.'}
                                    </p>
                                    <Label htmlFor="remote-purpose">
                                        Support reason
                                    </Label>
                                    <Textarea
                                        id="remote-purpose"
                                        value={purpose}
                                        onChange={(event) =>
                                            setPurpose(event.target.value)
                                        }
                                        maxLength={500}
                                        rows={3}
                                        required
                                    />
                                </fieldset>
                                <p className="text-subtle">
                                    User approval required · Up to{' '}
                                    {options.max_minutes} minutes · Recording
                                    off
                                </p>
                            </>
                        )}
                </form>
            )}
            <DialogFooter>
                <Button variant="outline" onClick={onClose}>
                    {session ? 'Close viewer' : 'Cancel'}
                </Button>
                {!session &&
                    options?.enabled &&
                    options.consent_mode === 'attended' && (
                        <Button
                            type="submit"
                            form="remote-connection"
                            disabled={busy || (!uncertain && attempted)}
                        >
                            {busy
                                ? 'Checking request…'
                                : uncertain
                                  ? 'Check same request'
                                  : 'Request connection'}
                        </Button>
                    )}
            </DialogFooter>
        </>
    );
}
function RemoteScreen({ session }: { session: RemoteSession }) {
    const canvas = useRef<HTMLCanvasElement | null>(null),
        viewer = useRef<RemoteViewer | null>(null),
        controlling = useRef(false);
    const [control, setControl] = useState(false);
    const [state, setState] = useState<ViewerState>({
        status: 'requested',
        message: 'Waiting for the user to allow the connection…',
        hasFrame: false,
    });
    function release() {
        viewer.current?.input.release();
        controlling.current = false;
        setControl(false);
    }
    useEffect(() => {
        if (!canvas.current) return;
        let mounted = true;
        const current = new RemoteViewer(session, canvas.current, (next) => {
            if (mounted) {
                setState(next);
                if (!next.hasFrame || next.status !== 'active') {
                    controlling.current = false;
                    setControl(false);
                }
            }
        });
        viewer.current = current;
        const hidden = () => {
            if (document.hidden)
                current.stop(
                    'The viewer was hidden. Start a new request to reconnect.',
                );
        };
        const leaving = () => current.stop();
        const blur = () => {
            current.input.release();
            controlling.current = false;
            if (mounted) setControl(false);
        };
        document.addEventListener('visibilitychange', hidden);
        window.addEventListener('pagehide', leaving);
        window.addEventListener('blur', blur);
        const element = canvas.current;
        const wheel = (event: WheelEvent) => {
            if (!controlling.current) return;
            event.preventDefault();
            const point = screenPoint(
                element.getBoundingClientRect(),
                event.clientX,
                event.clientY,
            );
            if (point && event.deltaY)
                current.input.send({
                    type: 'wheel',
                    ...point,
                    delta: event.deltaY > 0 ? -120 : 120,
                });
        };
        element.addEventListener('wheel', wheel, { passive: false });
        current.start();
        return () => {
            mounted = false;
            current.stop();
            viewer.current = null;
            document.removeEventListener('visibilitychange', hidden);
            window.removeEventListener('pagehide', leaving);
            window.removeEventListener('blur', blur);
            element.removeEventListener('wheel', wheel);
        };
    }, [session]);
    function pointer(
        event: PointerEvent<HTMLCanvasElement>,
        type: 'move' | 'button_down' | 'button_up',
    ) {
        if (!controlling.current || event.pointerType !== 'mouse') return;
        event.preventDefault();
        const point = screenPoint(
            event.currentTarget.getBoundingClientRect(),
            event.clientX,
            event.clientY,
        );
        if (!point) {
            if (type === 'button_up') release();
            return;
        }
        if (type === 'move') viewer.current?.input.send({ type, ...point });
        else if (event.button >= 0 && event.button <= 2) {
            if (type === 'button_down')
                event.currentTarget.setPointerCapture(event.pointerId);
            viewer.current?.input.send({
                type,
                button: event.button,
                ...point,
            });
            if (
                type === 'button_up' &&
                event.currentTarget.hasPointerCapture(event.pointerId)
            )
                event.currentTarget.releasePointerCapture(event.pointerId);
        }
    }
    function key(
        event: KeyboardEvent<HTMLCanvasElement>,
        type: 'key_down' | 'key_up',
    ) {
        if (!controlling.current) return;
        event.preventDefault();
        event.stopPropagation();
        if (
            event.code === 'Escape' ||
            event.nativeEvent.isComposing ||
            event.metaKey
        ) {
            release();
            return;
        }
        const key = windowsKey(event.code);
        if (key) viewer.current?.input.send({ type, key });
    }
    const live = liveStatus(state.status);
    return (
        <section aria-label="Remote screen" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <StatusBadge
                    variant={
                        state.status === 'active' && state.hasFrame
                            ? 'success'
                            : 'neutral'
                    }
                >
                    {state.hasFrame
                        ? 'Connected'
                        : live
                          ? 'Connecting'
                          : 'Disconnected'}
                </StatusBadge>
                <div className="flex flex-wrap gap-2">
                    {session.access_mode === 'control' && (
                        <Button
                            variant="outline"
                            disabled={!state.hasFrame || !live}
                            aria-pressed={control}
                            onPointerDown={(event) => {
                                if (control) event.preventDefault();
                            }}
                            onClick={() => {
                                if (control) release();
                                else {
                                    controlling.current = true;
                                    setControl(true);
                                    canvas.current?.focus();
                                }
                            }}
                        >
                            <MousePointer2 aria-hidden="true" />
                            {control ? 'Release control' : 'Control screen'}
                        </Button>
                    )}
                    <Button
                        variant="destructive"
                        disabled={!live}
                        onClick={() => viewer.current?.stop()}
                    >
                        <Square aria-hidden="true" />
                        Disconnect
                    </Button>
                </div>
            </div>
            <p role="status" className="text-subtle">
                {state.message}
            </p>
            <div className="overflow-hidden rounded-lg border bg-muted p-2">
                <canvas
                    ref={canvas}
                    width={1}
                    height={1}
                    tabIndex={control ? 0 : -1}
                    aria-label="Remote primary screen"
                    aria-describedby="remote-screen-help"
                    className={
                        state.hasFrame
                            ? 'frontline-focus block h-auto w-full'
                            : 'hidden'
                    }
                    onPointerMove={(event) => pointer(event, 'move')}
                    onPointerDown={(event) => pointer(event, 'button_down')}
                    onPointerUp={(event) => pointer(event, 'button_up')}
                    onPointerCancel={release}
                    onBlur={release}
                    onContextMenu={(event) => {
                        if (controlling.current) event.preventDefault();
                    }}
                    onKeyDown={(event) => key(event, 'key_down')}
                    onKeyUp={(event) => key(event, 'key_up')}
                />
                {!state.hasFrame && (
                    <div className="flex min-h-48 items-center justify-center gap-3 p-6 text-muted-foreground">
                        <Monitor aria-hidden="true" />
                        <span>
                            {live ? 'No screen shared yet' : 'Screen cleared'}
                        </span>
                    </div>
                )}
            </div>
            <p id="remote-screen-help" className="text-caption">
                {control
                    ? 'Mouse and keyboard control is on. Press Esc or move focus away to release control.'
                    : session.access_mode === 'control'
                      ? 'Choose Control screen to send mouse and keyboard input.'
                      : 'View only. Mouse and keyboard input is not sent.'}{' '}
                Primary screen · Recording off.
            </p>
        </section>
    );
}
