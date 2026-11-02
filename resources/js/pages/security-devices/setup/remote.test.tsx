import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SetupRemote } from './_remote';
import type { RemoteSetupData } from './_types';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, put: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, reload: vi.fn() } };
});

const data = (overrides: Partial<RemoteSetupData> = {}): RemoteSetupData => ({
    allowed: false,
    working: false,
    computers_on: true,
    relay_ready: true,
    relays: 1,
    sessions_30_days: 3,
    can_change: true,
    settings_href: '/settings/obli',
    ...overrides,
});

describe('Setup › Computers & Obli › Remote support (pg-sdr-15k)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('turns remote support on through today’s setting, and shows Allow and recording as facts', async () => {
        vi.mocked(axios.put).mockResolvedValueOnce({ data: {} });
        render(<SetupRemote data={data()} />);

        expect(screen.getByText('The person at the computer must click Allow')).toBeInTheDocument();
        expect(screen.getByText('Never')).toBeInTheDocument();
        expect(screen.getByText('Relay set up')).toBeInTheDocument();
        expect(screen.queryByRole('switch', { name: /Record sessions/ })).toBeNull();

        fireEvent.click(screen.getByRole('switch', { name: /Allow remote support/ }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/security-devices/setup/computers/remote-support', { allowed: true }));
    });

    it('says when there’s no relay, and who may change it', () => {
        render(<SetupRemote data={data({ relay_ready: false, relays: 0, can_change: false })} />);

        expect(screen.getByText('No relay set up')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: /Allow remote support/ })).toBeDisabled();
        expect(screen.getByText(/Only an IT admin who manages settings and secrets/)).toBeInTheDocument();
    });
});
