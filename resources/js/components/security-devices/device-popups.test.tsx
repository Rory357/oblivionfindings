import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { DeviceFormData } from '@/pages/security-devices/devices/_types';
import { AddDeviceChooser, DeviceFormPopup, MoveDevices, PingViewer, WatchDevice } from './device-popups';
import { ScanNetwork } from './scan-popups';

const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn(), put: vi.fn() }));
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia },
    };
});
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: http.get, post: http.post, isAxiosError: actual.default.isAxiosError } };
});

const form: DeviceFormData = {
    kinds: [
        { value: 'network', label: 'Network' },
        { value: 'camera', label: 'Camera' },
    ],
    models: [{ value: 'Synthetic|DS-1', label: 'Synthetic DS-1', description: 'Built from vendor docs', kind: 'camera', brand: 'Synthetic', model: 'DS-1' }],
    watch: { every: 'Every minute', after: '3 missed checks', know_within: 'about 3 minutes' },
    sites: [
        { id: 4, name: 'Synthetic House', checked_by: 'Synthetic hub', hubs: [{ id: 2, label: 'Synthetic hub' }], suggested_hub_id: 2, suggested_range: '10.0.0.0/24' },
        { id: 5, name: 'Synthetic Lodge', checked_by: 'The Oblivion server', hubs: [], suggested_hub_id: null, suggested_range: null },
    ],
};

beforeEach(() => {
    Object.values(inertia).forEach((fn) => fn.mockReset());
    http.get.mockReset();
    http.post.mockReset();
});
afterEach(cleanup);

describe('Devices pop-ups', () => {
    it('1 · Add device offers only what the person can do, and the button follows the choice', () => {
        const choose = vi.fn();
        render(<AddDeviceChooser open can={{ add: true, scan: true, connect: false }} onClose={() => undefined} onChoose={choose} />);

        expect(screen.queryByText('Connect a service')).toBeNull();
        expect(screen.getByRole('button', { name: 'Continue to scan' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Add one device by its address/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(choose).toHaveBeenCalledWith('one');
    });

    it('2 · Add one device asks the address once, adds it, turns on offline alerts and opens it', async () => {
        http.post.mockResolvedValueOnce({ data: { id: 41, name: 'Hallway camera', href: '/security-devices/devices/41' } });
        http.post.mockResolvedValueOnce({ data: { enabled: true } });
        render(<DeviceFormPopup open mode="add" form={form} canAssign onClose={() => undefined} />);

        fireEvent.click(screen.getByRole('button', { name: 'Add device' }));
        expect(await screen.findByText('Choose what kind of device it is.')).toBeTruthy();
        expect(screen.getAllByLabelText(/Address/)).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Camera' }));
        fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Hallway camera' } });
        fireEvent.change(screen.getByLabelText(/Address/), { target: { value: '10.0.0.46' } });
        fireEvent.click(screen.getByRole('combobox', { name: 'Site' }));
        fireEvent.click(await screen.findByRole('option', { name: /Synthetic House/ }));
        expect(screen.getByText('Synthetic hub')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Add device' }));

        await waitFor(() => expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/41'));
        expect(http.post).toHaveBeenNthCalledWith(
            1,
            '/security-devices/devices/add-one',
            expect.objectContaining({ kind: 'camera', name: 'Hallway camera', ip_address: '10.0.0.46', site_id: 4 }),
            expect.anything(),
        );
        expect(http.post).toHaveBeenNthCalledWith(2, '/security-devices/devices/41/offline-alert', { enabled: true }, expect.anything());
    });

    it('8 · Ping answers in one line and says the server asked', async () => {
        http.post.mockResolvedValueOnce({
            data: { reachable: true, target: '10.0.0.2', resolved_address: '10.0.0.2', latency_label: '2 ms', message: 'Replied in 2 ms.', checked_at: new Date().toISOString(), checked_from: 'server', covering_hub: null },
        });
        render(<PingViewer open device={{ id: 3, name: 'Synthetic switch', address: '10.0.0.2' }} onClose={() => undefined} />);

        expect(await screen.findByText(/Synthetic switch replied/)).toBeTruthy();
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('The Oblivion server')).toBeTruthy();
        expect(within(dialog).getAllByText('2 ms').length).toBeGreaterThan(0);
    });

    it('9 · Watch this device needs an address before it can ping', () => {
        render(<WatchDevice open device={{ id: 3, name: 'Synthetic phone base', state: 'not_watched', site_id: 4, address: null }} form={form} onClose={() => undefined} />);

        expect(screen.getByText(/Add its address first/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Start watching' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/you’ll know about 3 minutes after/)).toBeTruthy();
    });

    it('72 · Move says which hub will check it and why a device couldn’t move', async () => {
        http.post.mockResolvedValueOnce({ data: { moved: [], refused: [{ name: 'Synthetic tracker', reason: 'It’s with a person. Move it from its assignments so consent and custody rules stay intact.' }] } });
        render(<MoveDevices open devices={[{ id: 8, name: 'Synthetic tracker', site: 'Synthetic House', site_id: 4 }]} form={form} onClose={() => undefined} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'New Site' }));
        fireEvent.click(await screen.findByRole('option', { name: /Synthetic Lodge/ }));
        expect(screen.getByText('Synthetic hub → The Oblivion server')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Move device' }));

        expect(await screen.findByText(/It’s with a person/)).toBeTruthy();
        expect(http.post).toHaveBeenCalledWith('/security-devices/devices/move', { device_ids: [8], site_id: 5, room: null }, expect.anything());
    });

    it('3 · Scan a network suggests the range and hub from the Site and starts the scan', async () => {
        http.post.mockResolvedValueOnce({ data: { run_id: 77 } });
        const started = vi.fn();
        render(<ScanNetwork open form={form} initialSiteId={4} onClose={() => undefined} onStarted={started} />);

        expect((screen.getByLabelText(/Network range/) as HTMLInputElement).value).toBe('10.0.0.0/24');
        fireEvent.click(screen.getByRole('button', { name: 'Cameras' }));
        fireEvent.click(screen.getByRole('button', { name: 'Start scan' }));

        await waitFor(() => expect(started).toHaveBeenCalledWith(77));
        expect(http.post).toHaveBeenCalledWith(
            '/security-devices/scans',
            { site_id: 4, range: '10.0.0.0/24', hub_id: 2, look_for: ['cameras'] },
            expect.anything(),
        );
    });
});
