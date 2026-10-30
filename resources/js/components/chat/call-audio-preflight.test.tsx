import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useRef, useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    CallAudioPreflight,
    DEFAULT_CALL_AUDIO,
    type CallAudioPreflightControls,
} from './call-audio-preflight';
import { claimChatMicrophone } from './chat-microphone';

const contexts: FakeAudio[] = [];
let resumeResult: Promise<void> | null = null;
class FakeAudio {
    state = 'running';
    currentTime = 0;
    destination = {};
    resume = vi.fn(async () => {
        if (resumeResult) await resumeResult;
    });
    close = vi.fn(async () => {
        this.state = 'closed';
    });
    setSinkId = vi.fn(async (_id: string) => {});
    source = { connect: vi.fn() };
    createMediaStreamSource = vi.fn(() => this.source);
    createAnalyser = () => ({
        fftSize: 256,
        getByteTimeDomainData: (values: Uint8Array) => values.fill(144),
    });
    oscillator = {
        frequency: { value: 0 },
        connect: vi.fn(),
        disconnect: vi.fn(),
        start: vi.fn(),
        stop: vi.fn(),
        onended: null as (() => void) | null,
    };
    createOscillator = () => this.oscillator;
    gain = {
        gain: { setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn() },
        connect: vi.fn(),
        disconnect: vi.fn(),
    };
    createGain = () => this.gain;
    constructor() {
        contexts.push(this);
    }
}
const capture = vi.fn();
let events: EventTarget;
let devices: {
    kind: string;
    deviceId: string;
    label: string;
    groupId: string;
}[];
// Opens the Radix select, waits for the enumerated device, and picks it.
async function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(await screen.findByRole('option', { name: option }));
}
// Waits for enumeration to list a device without changing the selection.
async function waitForDevice(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    await screen.findByRole('option', { name: option });
    fireEvent.keyDown(screen.getByRole('listbox'), { key: 'Escape' });
    await waitFor(() =>
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument(),
    );
}
let track: EventTarget & { stop: ReturnType<typeof vi.fn> };
function View() {
    const [selection, setSelection] = useState(DEFAULT_CALL_AUDIO);
    const [ready, setReady] = useState(false);
    const controls = useRef<CallAudioPreflightControls>(null);
    return (
        <>
            <CallAudioPreflight
                selection={selection}
                onChange={setSelection}
                onReady={setReady}
                disabled={false}
                controls={controls}
            />
            <Button disabled={!ready} onClick={() => controls.current?.stop()}>
                Call
            </Button>
        </>
    );
}
beforeEach(() => {
    contexts.length = 0;
    resumeResult = null;
    capture.mockReset();
    events = new EventTarget();
    devices = [
        {
            kind: 'audioinput',
            deviceId: 'usb',
            label: 'USB microphone',
            groupId: 'usb',
        },
        {
            kind: 'audiooutput',
            deviceId: 'speaker',
            label: 'Headset output',
            groupId: 'headset',
        },
    ];
    track = Object.assign(new EventTarget(), { stop: vi.fn() });
    capture.mockResolvedValue({
        getTracks: () => [track],
        getAudioTracks: () => [track],
    });
    vi.stubGlobal('AudioContext', FakeAudio);
    vi.stubGlobal('navigator', {
        mediaDevices: {
            getUserMedia: capture,
            enumerateDevices: vi.fn(async () => devices),
            addEventListener: events.addEventListener.bind(events),
            removeEventListener: events.removeEventListener.bind(events),
        },
    });
    Object.defineProperty(HTMLMediaElement.prototype, 'setSinkId', {
        configurable: true,
        value: vi.fn(),
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    delete (HTMLMediaElement.prototype as unknown as { setSinkId?: unknown })
        .setSinkId;
});

it('opens without capture and requires an explicit check, then displays actual sampled input', async () => {
    render(<View />);
    expect(capture).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
    await choose('Microphone', 'USB microphone');
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    await screen.findByRole('meter');
    expect(capture).toHaveBeenCalledWith({
        audio: { deviceId: { exact: 'usb' } },
        video: false,
    });
    await waitFor(() =>
        expect(screen.getByRole('meter')).toHaveAttribute(
            'aria-valuenow',
            '50',
        ),
    );
    expect(contexts[0].source.connect).toHaveBeenCalledOnce();
    expect(contexts[0].gain.connect).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Call' }));
    expect(track.stop).toHaveBeenCalledOnce();
    expect(contexts[0].close).toHaveBeenCalledOnce();
    const release = claimChatMicrophone('call');
    release();
});

it('stops a late permission stream after closing and releases the capture lease', async () => {
    let resolve!: (value: unknown) => void;
    capture.mockReturnValue(
        new Promise((done) => {
            resolve = done;
        }),
    );
    const view = render(<View />);
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    view.unmount();
    const release = claimChatMicrophone('call');
    await act(async () => resolve({ getTracks: () => [track] }));
    expect(track.stop).toHaveBeenCalledOnce();
    expect(contexts).toHaveLength(0);
    release();
});

it('keeps a denied microphone check inline and does not enable calling', async () => {
    capture.mockRejectedValue(new DOMException('denied', 'NotAllowedError'));
    render(<View />);
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'browser settings',
    );
    expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
    const release = claimChatMicrophone('call');
    release();
});

it('stops active checks and requires a fresh check when the microphone changes', async () => {
    render(<View />);
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    await screen.findByRole('meter');
    await choose('Microphone', 'USB microphone');
    expect(track.stop).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
});

it('detects ended capture and actual device changes without reacting to camera-only changes', async () => {
    render(<View />);
    await waitForDevice('Microphone', 'USB microphone');
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    await screen.findByRole('meter');
    devices.push({
        kind: 'videoinput',
        deviceId: 'camera',
        label: 'Camera',
        groupId: 'camera',
    });
    await act(async () => events.dispatchEvent(new Event('devicechange')));
    expect(screen.getByRole('button', { name: 'Call' })).toBeEnabled();
    await act(async () => track.dispatchEvent(new Event('ended')));
    expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('disconnected');
});

it('bounds microphone capture to fifteen seconds', async () => {
    vi.useFakeTimers();
    render(<View />);
    await act(async () =>
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        ),
    );
    expect(screen.getByRole('meter')).toBeInTheDocument();
    await act(async () => vi.advanceTimersByTime(15000));
    expect(track.stop).toHaveBeenCalledOnce();
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('check finished');
});

it('plays a short selected-output tone without opening microphone capture', async () => {
    render(<View />);
    await choose('Speaker', 'Headset output');
    fireEvent.click(screen.getByRole('button', { name: 'Test speaker' }));
    await waitFor(() =>
        expect(contexts[0].oscillator.start).toHaveBeenCalledOnce(),
    );
    expect(contexts[0].setSinkId).toHaveBeenCalledWith('speaker');
    expect(contexts[0].oscillator.stop).toHaveBeenCalledWith(0.45);
    expect(capture).not.toHaveBeenCalled();
    await act(async () => contexts[0].oscillator.onended?.());
    expect(contexts[0].close).toHaveBeenCalledOnce();
});

it('does not take capture from an unfinished voice message', async () => {
    const release = claimChatMicrophone('voice_note');
    try {
        render(<View />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'voice message',
        );
        expect(capture).not.toHaveBeenCalled();
    } finally {
        release();
    }
});

it('releases capture even if the browser never resumes its audio context', async () => {
    vi.useFakeTimers();
    let resolve!: () => void;
    resumeResult = new Promise((done) => {
        resolve = done;
    });
    render(<View />);
    await act(async () =>
        fireEvent.click(
            screen.getByRole('button', { name: 'Check microphone' }),
        ),
    );
    await act(async () => vi.advanceTimersByTime(15000));
    expect(track.stop).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
    await act(async () => resolve());
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('took too long');
});

it('stops testing and invalidates readiness when the page is hidden', async () => {
    render(<View />);
    fireEvent.click(screen.getByRole('button', { name: 'Check microphone' }));
    await screen.findByRole('meter');
    const visibility = vi
        .spyOn(document, 'visibilityState', 'get')
        .mockReturnValue('hidden');
    try {
        await act(async () =>
            document.dispatchEvent(new Event('visibilitychange')),
        );
        expect(track.stop).toHaveBeenCalledOnce();
        expect(screen.getByRole('button', { name: 'Call' })).toBeDisabled();
        expect(screen.getByRole('status')).toHaveTextContent('page was hidden');
    } finally {
        visibility.mockRestore();
    }
});
