import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AddSensorGateway } from './sensor-gateway-popup';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, reload: vi.fn() } };
});

const form = {
    kinds: [],
    models: [],
    watch: { every: '1 minute', after: '3 missed checks', know_within: '3 minutes' },
    sites: [{ id: 4, name: 'Synthetic House' }],
} as never;

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Add a sensor gateway', () => {
    it('makes its own login, shows the password once and what to type into the gateway', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({
            data: { id: 7, username: 'gw-abcdefghijkl', password: 'synthetic-once', topic: 'oblivion/4/gw-abcdefghijkl/up', hub: { label: 'Synthetic House hub', address: '10.20.0.5' }, port: 1883 },
        });
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { last_reading_at: null } });

        render(<AddSensorGateway open form={form} onClose={() => undefined} />);

        expect(screen.getByDisplayValue('Synthetic House sensor gateway')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Make its login' }));
        await waitFor(() => expect(screen.getByText('synthetic-once')).toBeInTheDocument());
        expect(post).toHaveBeenCalledWith('/security-devices/setup/broker/gateways', { site_id: 4, model: 'Milesight UG65', name: 'Synthetic House sensor gateway' });
        expect(screen.getByText('The password is shown only now.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('10.20.0.5')).toBeInTheDocument();
        expect(screen.getByText('oblivion/4/gw-abcdefghijkl/up')).toBeInTheDocument();
        expect(screen.getByText(/the hub takes readings unencrypted on the house network/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Wait for its first reading' }));
        expect(screen.getByText('Waiting for its first reading')).toBeInTheDocument();
    });

    it('says plainly why a Site can’t take readings yet', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue(
            Object.assign(new axios.AxiosError('422'), {
                response: { status: 422, data: { errors: { site_id: ['This Site has no hub yet. Add a hub first: sensor readings never cross the public internet.'] } } },
            }),
        );

        render(<AddSensorGateway open form={form} onClose={() => undefined} />);
        fireEvent.click(screen.getByRole('button', { name: 'Make its login' }));

        await waitFor(() => expect(screen.getByText(/This Site has no hub yet/)).toBeInTheDocument());
    });
});
