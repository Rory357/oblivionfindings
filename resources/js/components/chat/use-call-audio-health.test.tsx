import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { Room } from 'livekit-client';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useCallAudioHealth } from './use-call-audio-health';

vi.mock('livekit-client', () => ({
    RoomEvent: {
        ActiveDeviceChanged: 'device',
        MediaDevicesChanged: 'devices',
        LocalTrackPublished: 'published',
        LocalTrackUnpublished: 'unpublished',
        TrackMuted: 'muted',
        TrackUnmuted: 'unmuted',
        MediaDevicesError: 'error',
    },
}));
const device = (kind: MediaDeviceKind, id: string) =>
    ({ kind, deviceId: id, groupId: id, label: id }) as MediaDeviceInfo;
function fixture() {
    const track = Object.assign(new EventTarget(), {
        readyState: 'live',
        muted: false,
    });
    const publication = { isMuted: false, track: { mediaStreamTrack: track } };
    const listeners = new Map<string, Set<(...args: unknown[]) => void>>();
    const instance = {
        localParticipant: {
            audioTrackPublications: new Map([['mic', publication]]),
        },
        getActiveDevice: (kind: string): string =>
            kind === 'audioinput' ? 'usb' : 'default',
        on: (event: string, fn: (...args: unknown[]) => void) => {
            const list = listeners.get(event) ?? new Set();
            list.add(fn);
            listeners.set(event, list);
        },
        off: (event: string, fn: (...args: unknown[]) => void) =>
            listeners.get(event)?.delete(fn),
        switchActiveDevice: vi.fn(),
    };
    return {
        room: instance as unknown as Room,
        publication,
        track,
        emit: (event: string, ...args: unknown[]) =>
            listeners.get(event)?.forEach((fn) => fn(...args)),
        instance,
    };
}
function View({ room }: { room?: Room | null }) {
    const health = useCallAudioHealth(room);
    return (
        <>
            {health.warning && <p role="alert">{health.warning.message}</p>}
            {health.warning?.canDismiss && (
                <Button onClick={health.dismiss}>Checked</Button>
            )}
            <Button onClick={health.refresh}>Refresh</Button>
        </>
    );
}
let devices: MediaDeviceInfo[];
let events: EventTarget;
const enumerate = vi.fn();
const microphone = vi.fn();
beforeEach(() => {
    vi.clearAllMocks();
    devices = [device('audioinput', 'usb'), device('audiooutput', 'speaker')];
    events = new EventTarget();
    enumerate.mockImplementation(async () => devices);
    vi.stubGlobal('navigator', {
        mediaDevices: {
            enumerateDevices: enumerate,
            getUserMedia: microphone,
            addEventListener: events.addEventListener.bind(events),
            removeEventListener: events.removeEventListener.bind(events),
        },
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('is quiet without a room and ignores unrelated camera changes', async () => {
    const view = render(<View />);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    const call = fixture();
    view.rerender(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    devices.push(device('videoinput', 'camera'));
    await act(async () => {
        events.dispatchEvent(new Event('devicechange'));
        call.emit('device', 'videoinput');
    });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(microphone).not.toHaveBeenCalled();
});

it('reports a removed microphone while settings are closed and never changes capture', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    devices = [device('audiooutput', 'speaker')];
    await act(async () => events.dispatchEvent(new Event('devicechange')));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'microphone is unavailable',
    );
    expect(
        screen.queryByRole('button', { name: 'Checked' }),
    ).not.toBeInTheDocument();
    expect(microphone).not.toHaveBeenCalled();
    expect(call.instance.switchActiveDevice).not.toHaveBeenCalled();
    devices = [device('audioinput', 'usb'), device('audiooutput', 'speaker')];
    await act(async () => events.dispatchEvent(new Event('devicechange')));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Audio devices changed',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Checked' }));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('reports ended capture but ignores an old track after replacement', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    call.track.readyState = 'ended';
    await act(async () => call.track.dispatchEvent(new Event('ended')));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Microphone audio was interrupted',
    );
    const replacement = Object.assign(new EventTarget(), {
        readyState: 'live',
        muted: false,
    });
    call.publication.track.mediaStreamTrack = replacement;
    await act(async () => call.emit('published'));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    await act(async () => call.track.dispatchEvent(new Event('ended')));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('does not describe deliberate microphone muting as device loss', async () => {
    const call = fixture();
    call.publication.isMuted = true;
    call.track.readyState = 'ended';
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    call.publication.isMuted = false;
    await act(async () => call.emit('unmuted'));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Microphone audio was interrupted',
    );
});

it('ignores an old room enumeration and events after the room changes', async () => {
    let resolve!: (value: MediaDeviceInfo[]) => void;
    enumerate.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const old = fixture();
    const next = fixture();
    const view = render(<View room={old.room} />);
    view.rerender(<View room={next.room} />);
    await act(async () => resolve([]));
    await act(async () => {
        old.emit('error');
        old.track.readyState = 'ended';
        old.track.dispatchEvent(new Event('ended'));
    });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('keeps a device error visible until a fresh successful review', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    await act(async () => call.emit('error'));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Microphone access needs attention',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    await waitFor(() =>
        expect(screen.queryByRole('alert')).not.toBeInTheDocument(),
    );
    expect(microphone).not.toHaveBeenCalled();
});

it('reports a missing selected speaker without silently changing the output', async () => {
    const call = fixture();
    call.instance.getActiveDevice = (kind: string) =>
        kind === 'audioinput' ? 'usb' : 'speaker';
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    devices = [device('audioinput', 'usb')];
    await act(async () => events.dispatchEvent(new Event('devicechange')));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'selected speaker is unavailable',
    );
    expect(call.instance.switchActiveDevice).not.toHaveBeenCalled();
    expect(microphone).not.toHaveBeenCalled();
});

it('does not let an older healthy enumeration hide a newer capture error', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(enumerate).toHaveBeenCalledOnce());
    let resolve!: (value: MediaDeviceInfo[]) => void;
    enumerate.mockImplementationOnce(
        () =>
            new Promise<MediaDeviceInfo[]>((done) => {
                resolve = done;
            }),
    );
    await act(async () => events.dispatchEvent(new Event('devicechange')));
    await act(async () => call.emit('error'));
    await act(async () => resolve(devices));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Microphone access needs attention',
    );
});
