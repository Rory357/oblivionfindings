import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Room } from 'livekit-client';
import { Headphones, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    CallConnectionQuality,
    type CallConnectionReport,
} from './call-connection-quality';

export type CallAudioKind = 'audioinput' | 'audiooutput';

// Radix Select items cannot hold an empty value; '' maps to this sentinel.
const NO_DEVICE = '__none';

export function CallAudioDevices({
    room,
    disabled,
    onSwitch,
    controlledOpen,
    onOpenChange,
    onRefresh,
    connection,
}: {
    room: Room;
    disabled: boolean;
    onSwitch: (room: Room, kind: CallAudioKind, id: string) => Promise<void>;
    controlledOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
    onRefresh?: () => void;
    connection?: CallConnectionReport | null;
}) {
    const [localOpen, setLocalOpen] = useState(false);
    const open = controlledOpen ?? localOpen;
    const setOpen = (value: boolean) => {
        if (controlledOpen === undefined) setLocalOpen(value);
        onOpenChange?.(value);
    };
    const trigger = useRef<HTMLButtonElement>(null);
    return (
        <>
            <Button
                ref={trigger}
                variant="outline"
                className="min-h-[44px]"
                onClick={() => setOpen(true)}
            >
                <Headphones className="size-4" />
                Audio devices
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    showCloseButton={false}
                    className="max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-2xl"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        trigger.current?.focus();
                    }}
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute top-2 right-2 size-[44px]"
                        aria-label="Close audio settings"
                        onClick={() => setOpen(false)}
                    >
                        <X className="size-4" />
                    </Button>
                    <DialogHeader className="pr-10 text-left">
                        <DialogTitle>Audio and connection</DialogTitle>
                        <DialogDescription>
                            Choose the devices for this call. Changing devices
                            keeps your microphone mute setting.
                        </DialogDescription>
                    </DialogHeader>
                    {open && (
                        <AudioOptions
                            room={room}
                            disabled={disabled}
                            onSwitch={onSwitch}
                            onRefresh={onRefresh}
                        />
                    )}
                    <CallConnectionQuality report={connection ?? null} />
                </DialogContent>
            </Dialog>
        </>
    );
}

function AudioOptions({
    room,
    disabled,
    onSwitch,
    onRefresh,
}: {
    room: Room;
    disabled: boolean;
    onSwitch: (room: Room, kind: CallAudioKind, id: string) => Promise<void>;
    onRefresh?: () => void;
}) {
    const prefix = useId();
    const [devices, setDevices] = useState<MediaDeviceInfo[]>([]);
    const [selected, setSelected] = useState({
        audioinput: '',
        audiooutput: '',
    });
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [revision, setRevision] = useState(0);
    const lifetime = useRef(0);
    const changing = useRef(false);
    const outputSupported =
        typeof HTMLMediaElement !== 'undefined' &&
        'setSinkId' in HTMLMediaElement.prototype;

    useEffect(() => {
        const epoch = ++lifetime.current;
        let sequence = 0;
        let unsubscribe = () => {};
        const current = () => lifetime.current === epoch;
        const read = async () => {
            const request = ++sequence;
            try {
                if (!navigator.mediaDevices?.enumerateDevices)
                    throw new Error();
                // Enumeration uses the call's existing permission; never open another microphone.
                const next = await navigator.mediaDevices.enumerateDevices();
                if (!current() || request !== sequence) return;
                setDevices(
                    next.filter(
                        (device) =>
                            device.deviceId &&
                            (device.kind === 'audioinput' ||
                                device.kind === 'audiooutput'),
                    ),
                );
                setSelected({
                    audioinput: room.getActiveDevice('audioinput') ?? 'default',
                    audiooutput:
                        room.getActiveDevice('audiooutput') ?? 'default',
                });
                setError('');
            } catch {
                if (current() && request === sequence)
                    setError(
                        'Could not list audio devices. Check your browser permissions and refresh the list.',
                    );
            } finally {
                if (current() && request === sequence) setLoading(false);
            }
        };
        const changed = () => {
            if (!current()) return;
            setNotice(
                'Audio devices changed. Check your microphone and speaker before continuing.',
            );
            void read();
        };
        void read();
        navigator.mediaDevices?.addEventListener?.('devicechange', changed);
        void import('livekit-client').then(({ RoomEvent }) => {
            if (!current()) return;
            room.on(RoomEvent.ActiveDeviceChanged, changed);
            unsubscribe = () => {
                room.off(RoomEvent.ActiveDeviceChanged, changed);
            };
        });
        return () => {
            lifetime.current = epoch + 1;
            navigator.mediaDevices?.removeEventListener?.(
                'devicechange',
                changed,
            );
            unsubscribe();
        };
    }, [room, revision]);

    async function change(kind: CallAudioKind, id: string) {
        if (changing.current || disabled || !id) return;
        changing.current = true;
        setBusy(true);
        setError('');
        const epoch = lifetime.current;
        try {
            await onSwitch(room, kind, id);
            if (lifetime.current !== epoch) return;
            setSelected((previous) => ({ ...previous, [kind]: id }));
            setNotice(
                `${kind === 'audioinput' ? 'Microphone' : 'Speaker'} changed. Check that you can hear and be heard.`,
            );
        } catch {
            if (lifetime.current === epoch)
                setError(
                    'The device change could not be confirmed. Check your devices, refresh the list and try again.',
                );
        } finally {
            changing.current = false;
            if (lifetime.current === epoch) setBusy(false);
        }
    }

    return (
        <div className="min-w-0 space-y-4">
            {loading && (
                <p role="status" className="text-sm">
                    Checking audio devices…
                </p>
            )}
            {(['audioinput', 'audiooutput'] as const).map((kind) => {
                const label = kind === 'audioinput' ? 'Microphone' : 'Speaker';
                if (kind === 'audiooutput' && !outputSupported)
                    return (
                        <p key={kind} className="text-sm text-muted-foreground">
                            This browser uses your system speaker. Choose your
                            speaker or headset in your device’s sound settings.
                        </p>
                    );
                const options = devices.filter(
                    (device) => device.kind === kind,
                );
                const value = selected[kind];
                const known = options.some(
                    (device) => device.deviceId === value,
                );
                return (
                    <div key={kind} className="min-w-0 space-y-1.5">
                        <label
                            htmlFor={`${prefix}-${kind}`}
                            className="text-sm font-medium"
                        >
                            {label}
                        </label>
                        <Select
                            value={value || NO_DEVICE}
                            disabled={
                                disabled || busy || loading || !options.length
                            }
                            onValueChange={(next) =>
                                void change(
                                    kind,
                                    next === NO_DEVICE ? '' : next,
                                )
                            }
                        >
                            <SelectTrigger
                                id={`${prefix}-${kind}`}
                                className="min-h-[44px] w-full min-w-0 bg-background"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {!known && (
                                    <SelectItem value={value || NO_DEVICE}>
                                        {!options.length
                                            ? `No ${label.toLowerCase()} available`
                                            : value === 'default'
                                              ? 'System default'
                                              : 'Previous device unavailable'}
                                    </SelectItem>
                                )}
                                {options.map((device, index) => (
                                    <SelectItem
                                        key={device.deviceId}
                                        value={device.deviceId}
                                    >
                                        {device.label ||
                                            `${label} ${index + 1}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                );
            })}
            <p role="status" className="text-sm">
                {busy
                    ? 'Changing audio device…'
                    : disabled
                      ? 'Device changes are paused while the call reconnects or another call action finishes.'
                      : notice ||
                        'Audio devices are used only for this call. No audio is recorded.'}
            </p>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <Button
                variant="outline"
                className="min-h-[44px]"
                disabled={busy || loading}
                onClick={() => {
                    setLoading(true);
                    setRevision((value) => value + 1);
                    onRefresh?.();
                }}
            >
                Refresh devices
            </Button>
        </div>
    );
}
