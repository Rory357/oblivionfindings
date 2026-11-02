import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FirmwareUpdatePopup, type FirmwareUpdate } from '@/components/security-devices/firmware-update-popup';
import { SavedLoginPopup, type SavedLogin } from './_device-saved-login';
import { SettingsChangedPopup, type SettingsChange } from './_device-settings-changed';

/** Pop-ups 24, 62 and 63 (S&D Phase G) with synthetic devices and TEST-ONLY values. */

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, ...inertia, on: () => () => undefined } };
});
const toasts = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn() }));
vi.mock('sonner', () => ({ toast: toasts }));

beforeEach(() => {
    inertia.reload.mockReset();
    toasts.success.mockReset();
    toasts.warning.mockReset();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

function change(overrides: Partial<SettingsChange> = {}): SettingsChange {
    return {
        device: { id: 41, name: 'Synthetic router', model: 'MikroTik hAP ax3' },
        before: { id: 7, at: '2026-10-21T16:58:00Z', how: 'Settings backup' },
        after: { id: 8, at: '2026-10-22T16:58:00Z', how: 'Settings backup' },
        lines: 2,
        added: 2,
        removed: 2,
        too_many: false,
        rows: [
            { kind: 'gap', left: null, right: null, hidden: 14 },
            { kind: 'same', left: { n: 15, text: '/interface bridge add name=br-lan' }, right: { n: 15, text: '/interface bridge add name=br-lan' }, hidden: 0 },
            {
                kind: 'changed',
                left: { n: 16, text: '/ip firewall filter add chain=forward action=accept src-address=10.30.0.0/24' },
                right: { n: 16, text: '/ip firewall filter add chain=forward action=drop src-address=10.30.0.50' },
                hidden: 0,
            },
            { kind: 'same', left: { n: 17, text: '/user add name=backup password=[REDACTED]' }, right: { n: 17, text: '/user add name=backup password=[REDACTED]' }, hidden: 0 },
            { kind: 'changed', left: { n: 18, text: '/interface wireless set wlan1 ssid="Synthetic-Staff"' }, right: { n: 18, text: '/interface wireless set wlan1 ssid="Synthetic-Staff-2"' }, hidden: 0 },
        ],
        truncated: false,
        changed_by: { known: false, changes: [], terminal: [] },
        kept: 'kept for 12 months',
        schedule: 'every night, 2:00 am – 5:00 am',
        download_href: '/security-devices/devices/41/settings-backups/8/download',
        ...overrides,
    };
}

describe('Pop-up 24, Settings changed', () => {
    it('puts the two backups side by side, picks out the changed lines and keeps passwords redacted', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: change() });
        const onBackup = vi.fn();
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={onBackup} onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-settings-changed');
        await waitFor(() => expect(within(popup).getByText('2 lines changed between Thu 22 Oct and Fri 23 Oct')).toBeInTheDocument());
        expect(get).toHaveBeenCalledWith('/security-devices/devices/41/settings-backups/8/changes', expect.anything());
        expect(within(popup).getByText('Settings changed · Synthetic router')).toBeInTheDocument();
        expect(within(popup).getByText('Backed up every night, 2:00 am – 5:00 am')).toBeInTheDocument();
        expect(within(popup).getByText('Before · Thu 22 Oct')).toBeInTheDocument();
        expect(within(popup).getByText('After · Fri 23 Oct')).toBeInTheDocument();
        expect(within(popup).getByText('14 lines the same')).toBeInTheDocument();

        const changed = popup.querySelectorAll('[data-kind="changed"]');
        expect(changed).toHaveLength(2);
        expect(changed[0]).toHaveTextContent('action=accept src-address=10.30.0.0/24');
        expect(changed[0]).toHaveTextContent('action=drop src-address=10.30.0.50');
        expect(within(popup).getAllByText(/password=\[REDACTED\]/)).toHaveLength(2);

        expect(within(popup).getByText('Not known · nobody used the Oblivion terminal on it between the two backups')).toBeInTheDocument();
        expect(within(popup).getByText(/kept for 12 months/)).toBeInTheDocument();
        expect(within(popup).getByRole('link', { name: 'Download both' })).toHaveAttribute('href', '/security-devices/devices/41/settings-backups/8/download');

        const footer = popup.querySelector('[data-slot="popup-footer"]') as HTMLElement;
        expect(Array.from(footer.querySelectorAll('a, button')).map((element) => element.textContent?.trim())).toEqual(['Close', 'Download both', 'Back up now']);
        fireEvent.click(within(popup).getByRole('button', { name: 'Back up now' }));
        expect(onBackup).toHaveBeenCalledTimes(1);
    });

    it('says who changed it only when a record does, and names terminal use only when the viewer may see it', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: change({ changed_by: { known: true, changes: [{ who: 'Synthetic Admin', what: 'Disable network port', at: null }], terminal: null } }),
        });
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        expect(await screen.findByText('Synthetic Admin: Disable network port')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Back up now' })).not.toBeInTheDocument();
        cleanup();

        vi.spyOn(axios, 'get').mockResolvedValue({ data: change({ changed_by: { known: false, changes: [], terminal: null } }) });
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        expect(await screen.findByText('Not known')).toBeInTheDocument();
    });

    it('shows the first copy, and too many changes, without guessing', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: change({ before: null, lines: null, added: null, removed: null, rows: [], changed_by: null }) });
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        expect(await screen.findByText('The first copy kept, so there’s nothing to compare it with yet')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download' })).toBeInTheDocument();
        expect(screen.queryByText('Changed by')).not.toBeInTheDocument();
        cleanup();

        vi.spyOn(axios, 'get').mockResolvedValue({ data: change({ lines: null, added: null, removed: null, too_many: true, rows: [] }) });
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        expect(await screen.findByText(/Over 2,000 lines were added or removed/)).toBeInTheDocument();
    });

    it('says when the viewer may not open copies, and offers Retry when loading failed', async () => {
        vi.spyOn(axios, 'get').mockRejectedValue(Object.assign(new axios.AxiosError('Forbidden'), { response: { status: 403, data: {} } }));
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        expect(await screen.findByText('You can’t open stored copies of its settings.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
        cleanup();
        vi.restoreAllMocks();

        const get = vi
            .spyOn(axios, 'get')
            .mockRejectedValueOnce(Object.assign(new axios.AxiosError('Server'), { response: { status: 500, data: {} } }))
            .mockResolvedValueOnce({ data: change() });
        render(<SettingsChangedPopup open deviceId={41} deviceName="Synthetic router" snapshotId={8} onBackup={null} onClose={() => undefined} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));
        expect(await screen.findByText('2 lines changed between Thu 22 Oct and Fri 23 Oct')).toBeInTheDocument();
        expect(get).toHaveBeenCalledTimes(2);
    });
});

const firmware: FirmwareUpdate = {
    id: 11,
    name: 'Synthetic switch',
    model: 'UniFi USW-24-PoE',
    kind: 'switch',
    domain: 'it_infrastructure',
    where: 'Synthetic House',
    site_id: 4,
    state: 'online',
    badge: { label: 'Online', tone: 'success' },
    href: '/security-devices/devices/11',
    source: 'unifi',
    source_label: 'UniFi',
    maker: 'Ubiquiti',
    current: '6.6.61',
    next: null,
    notes: null,
    current_since: null,
    install: { label: 'Open in UniFi', href: 'https://unifi.ui.com' },
    plan: { label: 'Quiet time now', tone: 'info', note: 'Quiet time set by Synthetic Planner · update in UniFi', at: null, order: 0, quiet: { starts_at: null, ends_at: null, by: 'Synthetic Planner', now: true } },
};

describe('Pop-up 62, Firmware update', () => {
    it('shows only what UniFi said and opens UniFi to install', () => {
        render(<FirmwareUpdatePopup open update={firmware} onClose={() => undefined} />);
        const popup = screen.getByTestId('sd-firmware-update');
        expect(within(popup).getByText('A newer version is ready for Synthetic switch, which is on 6.6.61')).toBeInTheDocument();
        expect(within(popup).getByText('A newer version · UniFi didn’t say which')).toBeInTheDocument();
        expect(within(popup).getByText('6.6.61')).toBeInTheDocument();
        expect(within(popup).getByText(/Oblivion shows updates; you install them in UniFi/)).toBeInTheDocument();
        expect(within(popup).getByText(/Quiet time is on now, set by Synthetic Planner/)).toBeInTheDocument();
        expect(within(popup).getByRole('link', { name: 'Open in UniFi' })).toHaveAttribute('rel', 'noopener noreferrer');
        expect(within(popup).queryByText('Takes')).not.toBeInTheDocument();
    });
});

const login: SavedLogin = {
    id: 3,
    name: 'MikroTik login',
    state: { tone: 'critical', text: 'Login failed' },
    failed_at: '2026-10-22T17:02:00Z',
    used_by: 'Synthetic House hub',
    shared_with: { count: 1, names: ['Synthetic switch'] },
    store: 'encrypted in Oblivion',
    url: '/security-devices/devices/41/saved-login',
};
const device = { id: 41, name: 'Synthetic router', model: 'MikroTik hAP ax3', kind: 'router', site: 'Synthetic House' };

describe('Pop-up 63, Update the saved login', () => {
    it('starts empty, says where it failed and who else shares it, and offers no Test login', () => {
        render(<SavedLoginPopup open device={device} login={login} onClose={() => undefined} />);
        const popup = screen.getByTestId('sd-saved-login');
        expect(within(popup).getByText('Synthetic House hub uses this to reach the device · stored encrypted in Oblivion')).toBeInTheDocument();
        expect(within(popup).getByText(/^Login failed at .*Someone may have changed the device’s password\.$/)).toBeInTheDocument();
        expect(within(popup).getByLabelText(/Username/)).toHaveValue('');
        expect(within(popup).getByLabelText(/Password/)).toHaveValue('');
        expect(within(popup).getByLabelText(/Password/)).toHaveAttribute('type', 'password');
        expect(within(popup).getByText(/The same saved login is used for Synthetic switch at Synthetic House/)).toBeInTheDocument();
        expect(within(popup).queryByRole('button', { name: 'Test login' })).not.toBeInTheDocument();
        const footer = popup.querySelector('[data-slot="popup-footer"]') as HTMLElement;
        expect(Array.from(footer.querySelectorAll('button')).map((element) => element.textContent?.trim())).toEqual(['Cancel', 'Save login']);
    });

    it('sends the new login once and keeps nothing of it', async () => {
        const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { state: 'working', message: 'Login saved. The hub uses it from its next job.' } });
        const onClose = vi.fn();
        render(<SavedLoginPopup open device={device} login={login} onClose={onClose} />);
        fireEvent.change(screen.getByLabelText(/Username/), { target: { value: 'TEST-ONLY-backup' } });
        fireEvent.change(screen.getByLabelText(/Password/), { target: { value: 'TEST-ONLY-new-password' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save login' }));

        await waitFor(() => expect(onClose).toHaveBeenCalled());
        expect(put).toHaveBeenCalledWith('/security-devices/devices/41/saved-login', { username: 'TEST-ONLY-backup', password: 'TEST-ONLY-new-password' }, expect.anything());
        expect(toasts.success).toHaveBeenCalledWith('Login saved. The hub uses it from its next job.');
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['actions'] });
    });

    it('asks for both fields, and says so when the person may not replace logins', async () => {
        const put = vi.spyOn(axios, 'put').mockRejectedValue(Object.assign(new axios.AxiosError('Forbidden'), { response: { status: 403, data: {} } }));
        render(<SavedLoginPopup open device={device} login={{ ...login, failed_at: null, shared_with: { count: 0, names: [] } }} onClose={() => undefined} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save login' }));
        expect(await screen.findByText('Enter the username the device expects.')).toBeInTheDocument();
        expect(screen.getByText('Enter the password.')).toBeInTheDocument();
        expect(put).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText(/Username/), { target: { value: 'TEST-ONLY-backup' } });
        fireEvent.change(screen.getByLabelText(/Password/), { target: { value: 'TEST-ONLY-new-password' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save login' }));
        expect(await screen.findByText('You can’t replace saved logins.')).toBeInTheDocument();
        expect(screen.queryByText(/The same saved login/)).not.toBeInTheDocument();
    });
});
