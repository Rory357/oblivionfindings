import type { Room } from 'livekit-client';
import { useCallback, useEffect, useState } from 'react';

export type CallAudioWarning = { message: string; canDismiss: boolean };

/** Observe existing capture only: never request permission, switch or unmute. */
export function useCallAudioHealth(room: Room | null | undefined) {
    const [state, setState] = useState<{
        room: Room;
        warning: CallAudioWarning;
    } | null>(null);
    const [revision, setRevision] = useState(0);
    useEffect(() => {
        if (!room) return;
        let live = true;
        let sequence = 0;
        let previous: string | null = null;
        let unbindTracks = () => {};
        let unbindRoom = () => {};
        const report = (message: string, canDismiss = false) => {
            if (live) setState({ room, warning: { message, canDismiss } });
        };
        const interrupted = () =>
            [...room.localParticipant.audioTrackPublications.values()].some(
                (publication) => {
                    const track = publication.track?.mediaStreamTrack;
                    return (
                        track &&
                        !publication.isMuted &&
                        (track.readyState === 'ended' || track.muted)
                    );
                },
            );
        const read = async (changed = false) => {
            const request = ++sequence;
            if (interrupted())
                report(
                    'Microphone audio was interrupted. Check your audio devices before continuing.',
                );
            if (!navigator.mediaDevices?.enumerateDevices) {
                if (changed)
                    report(
                        'Audio devices changed. Check your system sound settings before continuing.',
                        true,
                    );
                return;
            }
            try {
                const devices = (
                    await navigator.mediaDevices.enumerateDevices()
                ).filter(
                    (device) =>
                        device.kind === 'audioinput' ||
                        device.kind === 'audiooutput',
                );
                if (!live || request !== sequence) return;
                const signature = devices
                    .map(
                        (device) =>
                            `${device.kind}:${device.deviceId}:${device.groupId}:${device.label}`,
                    )
                    .sort()
                    .join('|');
                const inventoryChanged =
                    previous !== null && previous !== signature;
                previous = signature;
                const input = room.getActiveDevice('audioinput');
                const output = room.getActiveDevice('audiooutput');
                const inputs = devices.filter(
                    (device) => device.kind === 'audioinput',
                );
                const outputs = devices.filter(
                    (device) => device.kind === 'audiooutput',
                );
                if (interrupted())
                    report(
                        'Microphone audio was interrupted. Check your audio devices before continuing.',
                    );
                else if (
                    !inputs.length ||
                    (input &&
                        input !== 'default' &&
                        !inputs.some((device) => device.deviceId === input))
                )
                    report(
                        'Your microphone is unavailable. Connect a microphone or choose another audio device.',
                    );
                else if (
                    output &&
                    output !== 'default' &&
                    !outputs.some((device) => device.deviceId === output)
                )
                    report(
                        'Your selected speaker is unavailable. Check your headset or choose another audio device.',
                    );
                else if (changed || inventoryChanged)
                    report(
                        'Audio devices changed. Check that you can hear and be heard.',
                        true,
                    );
                else
                    setState((value) =>
                        value?.room === room && !value.warning.canDismiss
                            ? null
                            : value,
                    );
            } catch {
                if (live && request === sequence)
                    report(
                        'Could not check audio devices. Review your browser permissions and refresh the device list.',
                    );
            }
        };
        const bindTracks = () => {
            unbindTracks();
            const cleanups: (() => void)[] = [];
            for (const publication of room.localParticipant.audioTrackPublications.values()) {
                const track = publication.track?.mediaStreamTrack;
                if (!track?.addEventListener) continue;
                const changed = () => {
                    if (!live || publication.track?.mediaStreamTrack !== track)
                        return;
                    void read();
                };
                for (const event of ['ended', 'mute', 'unmute'])
                    track.addEventListener(event, changed);
                cleanups.push(() => {
                    for (const event of ['ended', 'mute', 'unmute'])
                        track.removeEventListener(event, changed);
                });
            }
            unbindTracks = () => cleanups.forEach((cleanup) => cleanup());
        };
        const changed = (kind?: MediaDeviceKind) => {
            if (kind && kind !== 'audioinput' && kind !== 'audiooutput') return;
            bindTracks();
            void read(true);
        };
        const tracksChanged = () => {
            bindTracks();
            void read();
        };
        // Compare audio inventory so an unrelated camera change does not interrupt a voice call.
        const inventoryChanged = () => {
            bindTracks();
            void read();
        };
        const mediaError = () => {
            sequence++;
            report(
                'Microphone access needs attention. Check your browser permissions and audio devices.',
            );
        };
        bindTracks();
        void read();
        navigator.mediaDevices?.addEventListener?.(
            'devicechange',
            inventoryChanged,
        );
        void import('livekit-client').then(({ RoomEvent }) => {
            if (!live) return;
            const listeners = [
                [RoomEvent.ActiveDeviceChanged, changed],
                [RoomEvent.MediaDevicesChanged, inventoryChanged],
                [RoomEvent.LocalTrackPublished, tracksChanged],
                [RoomEvent.LocalTrackUnpublished, tracksChanged],
                [RoomEvent.TrackMuted, tracksChanged],
                [RoomEvent.TrackUnmuted, tracksChanged],
                [RoomEvent.MediaDevicesError, mediaError],
            ] as const;
            for (const [event, listener] of listeners)
                if (event) room.on(event, listener);
            unbindRoom = () => {
                for (const [event, listener] of listeners)
                    if (event) room.off(event, listener);
            };
        });
        return () => {
            live = false;
            sequence++;
            navigator.mediaDevices?.removeEventListener?.(
                'devicechange',
                inventoryChanged,
            );
            unbindTracks();
            unbindRoom();
        };
    }, [room, revision]);
    const dismiss = useCallback(() => {
        setState((value) =>
            value && value.room === room && value.warning.canDismiss
                ? null
                : value,
        );
    }, [room]);
    const refresh = useCallback(() => setRevision((value) => value + 1), []);
    return {
        warning: room && state?.room === room ? state.warning : null,
        dismiss,
        refresh,
    };
}
