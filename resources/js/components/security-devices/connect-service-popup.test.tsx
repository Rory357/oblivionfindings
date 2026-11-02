import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectService, type ConnectState } from './connect-service-popup';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        usePage: () => ({ props: {} }),
        router: { ...actual.router, reload: vi.fn(), visit: vi.fn() },
    };
});
vi.mock('./sensor-gateway-popup', () => ({
    AddSensorGateway: ({ open }: { open: boolean }) => (open ? <div>Add a sensor gateway</div> : null),
}));

function state(overrides: Partial<ConnectState> = {}): ConnectState {
    return {
        services: ['unifi', 'milesight', 'queclink', 'intune', 'mqtt'],
        sites: [
            { id: 4, name: 'Synthetic House' },
            { id: 5, name: 'Synthetic Lodge' },
        ],
        can: { gateway: true, import_computers: true },
        unifi: { has_key: false, status: 'not_configured', tested_at: null, consoles: null, sites_found_at: null, remote_sites: [], taken_site_ids: [] },
        intune: { has_key: false, status: 'not_configured', tested_at: null, ids_saved: false, found_at: null, computers: [] },
        queclink: { port: 8090, address_set: true },
        ...overrides,
    };
}

const tested = (): ConnectState =>
    state({
        unifi: {
            has_key: true,
            status: 'connected',
            tested_at: new Date().toISOString(),
            consoles: 2,
            sites_found_at: new Date().toISOString(),
            remote_sites: [{ token: 'a'.repeat(64), name: 'Synthetic St', devices: 14, match: null }],
            taken_site_ids: [],
        },
    });

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Connect a service (15)', () => {
    it('offers only services with a backend, and says which cameras need no account', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state() });

        render(<ConnectService open onClose={() => undefined} />);

        await waitFor(() => expect(screen.getByRole('button', { name: /UniFi/ })).toBeInTheDocument());
        for (const name of ['Milesight', 'Queclink trackers', 'Microsoft Intune', 'MQTT sensors']) {
            expect(screen.getByRole('button', { name: new RegExp(name) })).toBeInTheDocument();
        }
        expect(screen.queryByText(/Inner Range|Gallagher|Ajax/)).not.toBeInTheDocument();
        expect(screen.getByText('Hikvision and Dahua cameras need no account. Add them by a scan or by their address.')).toBeInTheDocument();
    });

    it('hides the gateway services from someone who can’t make their logins', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state({ services: ['unifi', 'queclink', 'intune'], can: { gateway: false, import_computers: false } }) });

        render(<ConnectService open onClose={() => undefined} />);

        await waitFor(() => expect(screen.getByRole('button', { name: /UniFi/ })).toBeInTheDocument());
        expect(screen.queryByRole('button', { name: /Milesight/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /MQTT sensors/ })).not.toBeInTheDocument();
    });

    it('saves the UniFi key through its own save, tests it on the spot, then matches its sites', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state() });
        const post = vi.spyOn(axios, 'post').mockImplementation(async (url: string) => ({ data: { outcome: 'done', state: url.endsWith('/key') ? state({ unifi: { ...state().unifi, has_key: true } }) : tested() } }));

        render(<ConnectService open onClose={() => undefined} />);
        await waitFor(() => expect(screen.getByRole('button', { name: /UniFi/ })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /UniFi/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Sign in to UniFi')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/API key/), { target: { value: 'TEST-ONLY-unifi-key' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save and test' }));

        await waitFor(() => expect(screen.getByText(/✓ Works · 2 consoles found · tested/)).toBeInTheDocument());
        expect(post.mock.calls.map(([url]) => url)).toEqual([
            '/security-devices/setup/services/connect/unifi/key',
            '/security-devices/setup/services/connect/unifi/test',
            '/security-devices/setup/services/connect/unifi/sites',
        ]);
        expect(post.mock.calls[0][1]).toEqual({ api_key: 'TEST-ONLY-unifi-key' });
        // Once saved, the key isn't kept in the page.
        expect(screen.queryByDisplayValue('TEST-ONLY-unifi-key')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Match its sites to yours')).toBeInTheDocument();
        expect(screen.getByText('UniFi “Synthetic St”')).toBeInTheDocument();
        expect(screen.getByText(/Phones and laptops on the Wi-Fi/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect' })).toBeInTheDocument();
    });

    it('says plainly when UniFi refuses the key, and stays on the step', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state() });
        vi.spyOn(axios, 'post').mockImplementation(async (url: string) => ({
            data: url.endsWith('/test')
                ? { outcome: 'failed', state: state({ unifi: { ...state().unifi, has_key: true, status: 'error', tested_at: new Date().toISOString() } }) }
                : { outcome: 'done', state: state({ unifi: { ...state().unifi, has_key: true } }) },
        }));

        render(<ConnectService open onClose={() => undefined} />);
        await waitFor(() => expect(screen.getByRole('button', { name: /UniFi/ })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /UniFi/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText(/API key/), { target: { value: 'TEST-ONLY-wrong-key' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save and test' }));

        await waitFor(() => expect(screen.getByText('UniFi didn’t accept the key, or couldn’t be reached. Check the key and try again.')).toBeInTheDocument());
        expect(screen.getByText(/Didn’t work · tested/)).toBeInTheDocument();
        expect(screen.queryByText('Match its sites to yours')).not.toBeInTheDocument();
    });

    it('sends Milesight on to Add a sensor gateway', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state() });

        render(<ConnectService open onClose={() => undefined} />);
        await waitFor(() => expect(screen.getByRole('button', { name: /Milesight/ })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Milesight/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Add a sensor gateway')).toBeInTheDocument();
    });

    it('tells Queclink there is nothing to sign in to or test', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: state() });

        render(<ConnectService open onClose={() => undefined} />);
        await waitFor(() => expect(screen.getByRole('button', { name: /Queclink trackers/ })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Queclink trackers/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Point your trackers at Oblivion')).toBeInTheDocument();
        expect(screen.getByText(/nothing to sign in to and no test/)).toBeInTheDocument();
        expect(screen.getByText('8090')).toBeInTheDocument();
    });
});
