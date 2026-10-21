import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { Room } from 'livekit-client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CallAudioDevices } from './call-audio-devices';

vi.mock('livekit-client', () => ({
    RoomEvent: { ActiveDeviceChanged: 'device' },
}));
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
const device = (kind: MediaDeviceKind, id: string, label: string) =>
    ({ kind, deviceId: id, label }) as MediaDeviceInfo;
describe('call audio device controls', () => {
    const enumerate = vi.fn();
    const switchDevice = vi.fn();
    let events: EventTarget;
    let room: Room;
    beforeEach(() => {
        vi.clearAllMocks();
        events = new EventTarget();
        enumerate.mockResolvedValue([
            device('audioinput', 'default', 'System microphone'),
            device('audioinput', 'usb', 'USB headset'),
            device('audiooutput', 'speaker', 'Desk speaker'),
        ]);
        switchDevice.mockResolvedValue(undefined);
        room = {
            getActiveDevice: vi.fn(() => 'default'),
            on: vi.fn(),
            off: vi.fn(),
        } as unknown as Room;
        vi.stubGlobal('navigator', {
            mediaDevices: {
                enumerateDevices: enumerate,
                addEventListener: events.addEventListener.bind(events),
                removeEventListener: events.removeEventListener.bind(events),
                getUserMedia: vi.fn(),
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
        Reflect.deleteProperty(HTMLMediaElement.prototype, 'setSinkId');
    });
    async function open() {
        fireEvent.click(screen.getByRole('button', { name: 'Audio devices' }));
        await waitFor(() =>
            expect(
                screen.getByRole('combobox', { name: 'Microphone' }),
            ).toBeEnabled(),
        );
    }
    it('switches only on explicit selection and serialises changes without opening another microphone', async () => {
        let finish!: () => void;
        switchDevice.mockImplementation(
            () =>
                new Promise<void>((resolve) => {
                    finish = resolve;
                }),
        );
        render(
            <CallAudioDevices
                room={room}
                disabled={false}
                onSwitch={switchDevice}
            />,
        );
        await open();
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
        expect(switchDevice).not.toHaveBeenCalled();
        choose('Microphone', 'USB headset');
        // The speaker control is locked while the microphone switch is in flight.
        fireEvent.keyDown(screen.getByLabelText('Speaker'), { key: 'Enter' });
        expect(
            screen.queryByRole('option', { name: 'Desk speaker' }),
        ).not.toBeInTheDocument();
        expect(switchDevice).toHaveBeenCalledExactlyOnceWith(
            room,
            'audioinput',
            'usb',
        );
        expect(screen.getByLabelText('Speaker')).toBeDisabled();
        await act(async () => finish());
        expect(screen.getByLabelText('Microphone')).toHaveTextContent(
            'USB headset',
        );
        expect(screen.getByText(/Microphone changed/)).toBeInTheDocument();
    });
    it('keeps a failed change honest and allows an explicit retry', async () => {
        switchDevice.mockRejectedValueOnce(new Error('Device unplugged'));
        render(
            <CallAudioDevices
                room={room}
                disabled={false}
                onSwitch={switchDevice}
            />,
        );
        await open();
        choose('Microphone', 'USB headset');
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Microphone')).toHaveTextContent(
            'System microphone',
        );
        choose('Microphone', 'USB headset');
        await screen.findByText(/Microphone changed/);
        expect(switchDevice).toHaveBeenCalledTimes(2);
    });
    it('refreshes after unplugging without claiming that an unavailable device is active', async () => {
        vi.mocked(room.getActiveDevice).mockReturnValue('usb');
        render(
            <CallAudioDevices
                room={room}
                disabled={false}
                onSwitch={switchDevice}
            />,
        );
        await open();
        enumerate.mockResolvedValue([
            device('audioinput', 'default', 'System microphone'),
        ]);
        await act(async () => {
            events.dispatchEvent(new Event('devicechange'));
        });
        expect(screen.getByLabelText('Microphone')).toHaveTextContent(
            'Previous device unavailable',
        );
        expect(screen.getByText(/Audio devices changed/)).toBeInTheDocument();
        expect(switchDevice).not.toHaveBeenCalled();
    });
    it('shows system guidance when speaker selection is unsupported and ignores late device reads after closing', async () => {
        Reflect.deleteProperty(HTMLMediaElement.prototype, 'setSinkId');
        const view = render(
            <CallAudioDevices
                room={room}
                disabled={false}
                onSwitch={switchDevice}
            />,
        );
        await open();
        expect(screen.queryByLabelText('Speaker')).not.toBeInTheDocument();
        expect(
            screen.getByText(/This browser uses your system speaker/),
        ).toBeInTheDocument();
        let resolve!: (devices: MediaDeviceInfo[]) => void;
        enumerate.mockImplementation(
            () =>
                new Promise<MediaDeviceInfo[]>((done) => {
                    resolve = done;
                }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh devices' }),
        );
        view.unmount();
        await act(async () =>
            resolve([device('audioinput', 'other', 'Other account device')]),
        );
        expect(
            screen.queryByText('Other account device'),
        ).not.toBeInTheDocument();
        expect(room.off).toHaveBeenCalled();
    });
    it('disables switching while reconnecting and recovers a failed enumeration', async () => {
        enumerate.mockRejectedValueOnce(new Error('Permission changed'));
        render(
            <CallAudioDevices room={room} disabled onSwitch={switchDevice} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Audio devices' }));
        await screen.findByRole('alert');
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh devices' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alert')).not.toBeInTheDocument(),
        );
        // Devices were re-listed (the active default resolves to its label) but stay locked.
        await waitFor(() =>
            expect(screen.getByLabelText('Microphone')).toHaveTextContent(
                'System microphone',
            ),
        );
        expect(screen.getByLabelText('Microphone')).toBeDisabled();
        expect(switchDevice).not.toHaveBeenCalled();
    });
});
