import type { AccessConsoleState, AccessPlace } from '@/pages/security-devices/setup/_types';
import { accessFlag } from '@/pages/security-devices/setup/_access';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectUnifiAccess } from './connect-unifi-access-popup';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: vi.fn(), post: vi.fn(), put: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});

const consoleState = (overrides: Partial<AccessConsoleState> = {}): AccessConsoleState => ({
    id: 7,
    address: '172.16.0.5',
    port: 12445,
    state: 'untrusted',
    last_error: null,
    last_synced_at: null,
    emergency_mode: 'normal',
    doors: 0,
    staff: 0,
    to_match: 0,
    token: { saved_at: '2026-11-02T00:00:00Z', complete: true },
    certificate: { trusted_fingerprint: null, offered: { fingerprint: 'AB:CD:EF', subject: 'UniFi Access', valid_to: null, seen_at: null, changed: false }, can_trust: true },
    trusted: false,
    doors_confirmed_at: null,
    ...overrides,
});

const places: AccessPlace[] = [
    { site_id: 1, site: 'Synthetic House', hub: { name: 'Synthetic House hub', runs: true, needs_update: false, online: true }, console: null },
    { site_id: 2, site: 'No Hub House', hub: null, console: null },
];

const permissions = ['Users: View, Edit', 'System logs: View'];

describe('Connect UniFi Access (sdr-91)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('stops at a Site with no hub', () => {
        render(<ConnectUnifiAccess open places={places} permissions={permissions} canSaveToken canConfirmDoors onClose={() => undefined} />);
        fireEvent.click(screen.getByRole('button', { name: /No Hub House/ }));

        expect(screen.getByText(/This Site has no hub yet/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('saves the address and the token, trusts the certificate the hub read, and asks about exit buttons before finishing', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { console: consoleState({ token: null, certificate: null }) } });
        vi.mocked(axios.put).mockResolvedValueOnce({ data: { message: 'Token saved.' } });
        vi.mocked(axios.get).mockResolvedValueOnce({ data: { console: consoleState() } });
        render(<ConnectUnifiAccess open places={places} permissions={permissions} canSaveToken canConfirmDoors onClose={() => undefined} />);

        fireEvent.click(screen.getByRole('button', { name: /Synthetic House/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText('Address'), { target: { value: '172.16.0.5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save the address' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/setup/access/consoles', { site_id: 1, address: '172.16.0.5', port: 12445 }));

        // The token: the permissions to tick, a password field, and never shown again.
        expect(await screen.findByText('· Users: View, Edit')).toBeInTheDocument();
        const token = screen.getByLabelText('Token');
        expect(token).toHaveAttribute('type', 'password');
        fireEvent.change(token, { target: { value: 'TEST-ONLY-TOKEN-1234' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save the token' }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/security-devices/setup/access/consoles/7/token', { token: 'TEST-ONLY-TOKEN-1234' }));

        // The certificate the hub read.
        expect(await screen.findByText('AB:CD:EF')).toBeInTheDocument();
        vi.mocked(axios.post).mockResolvedValueOnce({ data: {} });
        vi.mocked(axios.get).mockResolvedValueOnce({ data: { console: consoleState({ trusted: true, state: 'ok', doors: 3, staff: 12, to_match: 2 }) } });
        fireEvent.click(screen.getByRole('button', { name: 'Trust this certificate' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/setup/access/consoles/7/trust', { fingerprint: 'AB:CD:EF' }));

        expect(await screen.findByText('Found 3 doors and 12 people')).toBeInTheDocument();
        expect(screen.getByText(/2 aren’t matched to staff yet/)).toBeInTheDocument();
        const finish = screen.getByRole('button', { name: 'Finish' });
        expect(finish).toBeDisabled();
        fireEvent.click(screen.getByLabelText(/opens from the inside by its exit button and handle/));
        vi.mocked(axios.post).mockResolvedValueOnce({ data: {} });
        fireEvent.click(finish);
        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/setup/access/consoles/7/doors-confirmed'));
        expect(await screen.findByText('UniFi Access at Synthetic House is connected')).toBeInTheDocument();
    });

    it('lets someone without the saved-logins right see the step, but not save a token', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { console: consoleState({ token: null, certificate: null }) } });
        render(
            <ConnectUnifiAccess
                open
                places={[{ ...places[0], console: consoleState({ token: null, certificate: null }) }]}
                permissions={permissions}
                canSaveToken={false}
                canConfirmDoors
                startSiteId={1}
                onClose={() => undefined}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save the address' }));

        expect(await screen.findByText(/Only an IT admin who manages saved logins can save the token/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Token')).toBeNull();
        expect(screen.getByRole('button', { name: 'Save the token' })).toBeDisabled();
    });

    it('describes each Site’s console in plain words', () => {
        expect(accessFlag(places[0]).label).toBe('Not set up');
        expect(accessFlag({ ...places[0], console: consoleState({ token: null }) }).label).toBe('Needs its token');
        expect(accessFlag({ ...places[0], console: consoleState() }).label).toBe('Certificate to check');
        expect(accessFlag({ ...places[0], console: consoleState({ trusted: true, state: 'ok' }) }).label).toBe('Connected');
        expect(accessFlag({ ...places[0], console: consoleState({ trusted: true, state: 'failed' }) }).label).toBe('Can’t read it');
    });
});
