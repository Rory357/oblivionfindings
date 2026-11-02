import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { DeviceFormData } from '@/pages/security-devices/devices/_types';
import { AddHubWizard, ApproveHub, HubPanel, InstallOnComputer, RemoveHub, STILL_WAITING_MS, type HubPanelData } from './hub-popups';

const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
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
    return {
        default: { ...actual.default, get: http.get, post: http.post, patch: http.patch, delete: http.delete, isAxiosError: actual.default.isAxiosError },
    };
});

const form: DeviceFormData = {
    kinds: [],
    models: [],
    watch: { every: 'Every minute', after: '3 missed checks', know_within: 'about 3 minutes' },
    sites: [
        { id: 4, name: 'Synthetic House', checked_by: 'The Oblivion server', hubs: [], suggested_hub_id: null, suggested_range: '10.40.0.0/24' },
        { id: 5, name: 'Synthetic Lodge', checked_by: 'Synthetic Lodge hub', hubs: [{ id: 9, label: 'Synthetic Lodge hub' }], suggested_hub_id: 9, suggested_range: '10.50.0.0/24' },
    ],
};

const issued = {
    setup_code_id: 31,
    code: 'T6WD-3KQN',
    platform: 'windows',
    expires_at: '2026-11-02T08:59:59Z',
    file_name: 'Oblivion-Setup (Synthetic House) [oblivion.test T6WD-3KQN].exe',
    download_url: '/security-devices/hubs/setup-codes/31/download',
    share_link: 'https://oblivion.test/h/T6WD-3KQN',
    linux_command: null,
};

const waiting = { data: { setup_code_id: 31, code_state: 'waiting', expires_at: issued.expires_at, hub: null } };
const hello = (approved: boolean) => ({
    data: {
        setup_code_id: 31,
        code_state: 'used',
        expires_at: issued.expires_at,
        hub: {
            id: 12,
            name: 'Synthetic House hub',
            site_id: 4,
            covered_site_ids: [],
            state: approved ? 'online' : 'waiting_for_approval',
            software_version: '1.3.1',
            last_heartbeat_at: '2026-11-01T08:52:00Z',
            approved_at: approved ? '2026-11-01T08:53:00Z' : null,
        },
    },
});

const panel: HubPanelData = {
    hub: {
        id: 12,
        name: 'Synthetic House',
        label: 'Synthetic House hub',
        site: { id: 4, name: 'Synthetic House' },
        platform: 'windows',
        state: 'online',
        heard_at: new Date().toISOString(),
        version: '1.3.1',
        installed: { by: 'Synthetic Admin', at: '2026-10-30T20:00:00Z' },
        replaces: null,
        removed_at: null,
    },
    updates: { ring: 'everyone', window_start: '02:00', window_end: '05:00', last: null, check_requested_at: null, held: false, held_at: null },
    watching: { devices: 12, sites: 1, cant_check: 0 },
    sites: [{ id: 4, name: 'Synthetic House', own: true }],
    networks: [{ key: 'scope-3', site: 'Synthetic House', own: true, range: '10.40.0.0/24', daily_at: '06:00' }],
    events: [{ key: 'audit-1', at: new Date().toISOString(), tone: 'success', text: 'Approved by Synthetic Admin' }],
    can: { edit: true, approve: false, remove: true, replace: true, check_update: true },
};

/** Let resolved requests and the effects they start run (fake timers). */
async function flush(ms = 0) {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

beforeEach(() => {
    Object.values(inertia).forEach((fn) => fn.mockReset());
    Object.values(http).forEach((fn) => fn.mockReset());
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('Hub pop-ups', () => {
    it('12 · Add a hub: one download for the Site, waits live for the hello, then approve, networks and the first scan', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        // The fixtures are on 1 Nov (setup code until 2 Nov, hello at 9:52 pm NZ): run on that day.
        vi.setSystemTime(new Date('2026-11-01T08:55:00Z'));
        const changed = vi.fn();
        const seeScan = vi.fn();
        http.post.mockImplementation((url: string) => {
            if (url === '/security-devices/hubs/setup-codes') return Promise.resolve({ data: issued });
            if (url === '/security-devices/hubs/12/approve') return Promise.resolve({ data: { hub: hello(true).data.hub } });
            if (url === '/security-devices/networks') return Promise.resolve({ data: { scope_id: 3 } });
            if (url === '/security-devices/scans') return Promise.resolve({ data: { run_id: 77 } });
            return Promise.reject(new Error(url));
        });
        http.get.mockResolvedValue(waiting);

        render(<AddHubWizard open form={form} initialSiteId={4} canApprove onClose={() => undefined} onSeeScan={seeScan} onChanged={changed} />);

        // 1 · Where: the Site is filled in and the name follows it.
        expect(screen.getByText('Where will the hub run?')).toBeTruthy();
        expect((screen.getByLabelText(/Hub name/) as HTMLInputElement).value).toBe('Synthetic House hub');
        expect(screen.getByText(/watched by Synthetic Lodge hub now/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await flush();
        expect(http.post).toHaveBeenCalledWith(
            '/security-devices/hubs/setup-codes',
            { site_id: 4, platform: 'windows', name: 'Synthetic House hub', covered_site_ids: [] },
            expect.anything(),
        );

        // 2 · Download: the file is named for the Site and carries the code.
        expect(screen.getByText('Download Oblivion for Synthetic House', { selector: 'h2' })).toBeTruthy();
        expect((screen.getByLabelText('File name') as HTMLInputElement).value).toBe(issued.file_name);
        expect((screen.getByLabelText(/^Link/) as HTMLInputElement).value).toBe(issued.share_link);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        // 3 · Install: Next, Next, Finish.
        expect(screen.getByText('Run it and click Next, Next, Finish')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        // 4 · Waits for the hello; after 2 minutes it says what to check.
        expect(screen.getByText('Waiting for the hub to say hello…')).toBeTruthy();
        expect(screen.queryByText(/Still waiting\?/)).toBeNull();
        await flush(STILL_WAITING_MS + 5000);
        expect(screen.getByText(/Still waiting\? Check the computer is on/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Start the first scan' }) as HTMLButtonElement).disabled).toBe(true);

        // It says hello: connected, waiting for approval; networks wait for it.
        http.get.mockResolvedValue(hello(false));
        await flush(8000);
        expect(screen.getByText(/Connected ✓ Synthetic House hub/)).toBeTruthy();
        expect(screen.getByText('Waiting for your approval')).toBeTruthy();
        expect((screen.getByRole('checkbox', { name: /Synthetic House · 10\.40\.0\.0\/24/ }) as HTMLButtonElement).disabled).toBe(true);

        // Approve hub opens 79.
        fireEvent.click(screen.getByRole('button', { name: /Approve hub\s*Check it’s the computer/ }));
        await flush();
        const approve = screen.getByRole('alertdialog');
        expect(within(approve).getByText('Approve Synthetic House hub?')).toBeTruthy();
        expect(within(approve).getByText('From your download · said hello at 9:52 pm')).toBeTruthy();
        fireEvent.click(within(approve).getByRole('button', { name: 'Approve hub' }));
        await flush();
        expect(changed).toHaveBeenCalled();

        // Approved: the suggested network is ticked and the first scan starts.
        expect(screen.getByText('Approved')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Start the first scan' }));
        await flush();
        expect(http.post).toHaveBeenCalledWith(
            '/security-devices/networks',
            { site_id: 4, range: '10.40.0.0/24', hub_id: 12, schedule: 'daily', look_for: ['everything'] },
            expect.anything(),
        );
        expect(http.post).toHaveBeenCalledWith('/security-devices/scans', { site_id: 4, range: '10.40.0.0/24', hub_id: 12, look_for: ['everything'] }, expect.anything());
        expect(screen.getByText('Synthetic House hub is watching 1 network')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'See the scan' }));
        expect(seeScan).toHaveBeenCalledWith(77);
    });

    it('79 · Approve hub warns first, offers Remove it, and approves', async () => {
        http.post.mockResolvedValueOnce(hello(true));
        const approved = vi.fn();
        const remove = vi.fn();
        render(
            <ApproveHub
                open
                hub={{ id: 12, label: 'Synthetic House hub', platform: 'linux', sites: ['Synthetic House'], devices: null, installedBy: 'Synthetic Admin', heardAt: null }}
                canRemove
                onClose={() => undefined}
                onApproved={approved}
                onRemove={remove}
            />,
        );

        expect(screen.getByText(/can’t receive any saved logins/)).toBeTruthy();
        expect(screen.getByText('Raspberry Pi or NUC (Linux)')).toBeTruthy();
        expect(screen.getByText('From Synthetic Admin’s download')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Later' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Remove it' }));
        expect(remove).toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Approve hub' }));
        await waitFor(() => expect(approved).toHaveBeenCalled());
        expect(http.post).toHaveBeenCalledWith('/security-devices/hubs/12/approve', {}, expect.anything());
    });

    it('14 · Remove hub says what stops and what stays, then removes it', async () => {
        http.post.mockResolvedValueOnce({ data: { hub: { id: 12, state: 'removed' } } });
        const removed = vi.fn();
        render(
            <RemoveHub
                open
                hub={{ id: 12, label: 'Synthetic House hub', platform: 'windows', sites: ['Synthetic House', 'Synthetic Lodge'], devices: 12 }}
                onClose={() => undefined}
                onRemoved={removed}
            />,
        );

        expect(screen.getByText('Remove Synthetic House hub?')).toBeTruthy();
        expect(screen.getByText(/will stop checking 12 devices at Synthetic House and Synthetic Lodge/)).toBeTruthy();
        expect(screen.getByText(/Until another hub covers these Sites/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Remove hub' }));
        await waitFor(() => expect(removed).toHaveBeenCalled());
        expect(http.post).toHaveBeenCalledWith('/security-devices/hubs/12/remove', {}, expect.anything());
    });

    it('13 · The hub panel says what the server knows, "Not available" for the rest, and offers only what the person can do', async () => {
        http.get.mockResolvedValue({ data: panel });
        render(<HubPanel open hubId={12} form={form} onClose={() => undefined} onChanged={() => undefined} />);

        expect(await screen.findByText('Windows PC or server · CPU, memory and disk: Not available')).toBeTruthy();
        expect(http.get).toHaveBeenCalledWith('/security-devices/hubs/12/panel', expect.anything());
        expect(screen.getByText('12 devices at 1 Site')).toBeTruthy();
        expect(screen.getByText('10.40.0.0/24 · its own network')).toBeTruthy();
        expect(screen.getByText('Approved by Synthetic Admin')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Edit hub' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Approve hub' })).toBeNull();

        fireEvent.click(screen.getByRole('tab', { name: 'Updates' }));
        expect(screen.getByText('2:00 am – 5:00 am, New Zealand time')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Edit hub' }));
        expect(await screen.findByRole('button', { name: 'Check now' })).toBeTruthy();
        expect((screen.getByLabelText(/^Name/) as HTMLInputElement).value).toBe('Synthetic House');
    });

    it('23 · Install Oblivion on a computer: only IT’s full install for now, with the reason in one line', async () => {
        http.post.mockResolvedValueOnce({ data: { ...issued, setup_code_id: 40, install_mode: 'full' } });
        render(<InstallOnComputer open form={form} initialSiteId={4} onClose={() => undefined} />);

        const staff = screen.getByRole('radio', { name: 'Obli for staff (no admin password)' }) as HTMLButtonElement;
        expect(staff.disabled).toBe(true);
        expect(screen.getByRole('radio', { name: /Full: hub or IT-managed/ }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText(/Staff installs aren’t ready yet/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Download' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Download again' })).toBeTruthy());
        expect(http.post).toHaveBeenCalledWith('/security-devices/hubs/computer-setup-codes', { site_id: 4, install_mode: 'full' }, expect.anything());
    });
});
