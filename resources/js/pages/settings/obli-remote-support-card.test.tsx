import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    defaultRemoteSupport,
    ObliRemoteSupportCard,
    type RemoteSupportConfiguration,
} from './obli-remote-support-card';

vi.mock('axios', async (importOriginal) => {
    const actual = await importOriginal<typeof import('axios')>();
    return {
        ...actual,
        default: {
            ...actual.default,
            put: vi.fn(),
            post: vi.fn(),
            get: vi.fn(),
            isAxiosError: actual.default.isAxiosError,
        },
    };
});

describe('ObliRemoteSupportCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders switched-off defaults and explains Windows Allow / Decline and Stop controls', () => {
        render(
            <ObliRemoteSupportCard
                actorId={7}
                initial={defaultRemoteSupport(false)}
            />,
        );

        expect(
            screen.getByText('Computer management & remote support'),
        ).toBeTruthy();
        expect(screen.getByText('Switched off')).toBeTruthy();
        expect(
            screen.getByText(/Staff on the computer always stay in control/i),
        ).toBeTruthy();
        expect(screen.getByText('Allow')).toBeTruthy();
        expect(screen.getByText('Decline')).toBeTruthy();
        expect(screen.getByText('Stop')).toBeTruthy();
    });

    it('saves attended remote support settings and verifies TURN relay signing', async () => {
        const updated: RemoteSupportConfiguration = {
            ...defaultRemoteSupport(true),
            version: 1,
            endpoint_management_enabled: true,
            obli_helper_enabled: true,
            obli_technician_enabled: true,
            remote_support_enabled: true,
            relay_urls: ['turns:turn.oblivionfindings.com:5349?transport=tcp'],
            effective_endpoint_enabled: true,
            effective_obli_helper_enabled: true,
            effective_obli_technician_enabled: true,
            effective_remote_support_enabled: true,
            relay_ready: true,
            relay_secret_saved: true,
            effective_relay_urls: [
                'turns:turn.oblivionfindings.com:5349?transport=tcp',
            ],
            command_signing_ready: true,
            server_url: 'https://oblivionfindings.com',
        };

        vi.mocked(axios.put).mockResolvedValueOnce({
            data: {
                actor_id: 7,
                remote_support: updated,
                generated_relay_secret: 'g'.repeat(64),
            },
        });
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                actor_id: 7,
                verification: {
                    ok: true,
                    status: 'relay_answered',
                    message:
                        'The relay answered: turns:turn.oblivionfindings.com:5349?transport=tcp (12 ms).',
                },
            },
        });

        render(
            <ObliRemoteSupportCard
                actorId={7}
                initial={{
                    ...defaultRemoteSupport(true),
                    endpoint_management_enabled: true,
                    obli_helper_enabled: true,
                    effective_endpoint_enabled: true,
                    effective_obli_helper_enabled: true,
                }}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Configure remote support' }),
        );

        fireEvent.click(
            screen.getByLabelText('Enable attended remote support'),
        );
        fireEvent.change(screen.getByLabelText(/TURN relay URLs/i), {
            target: {
                value: 'turns:turn.oblivionfindings.com:5349?transport=tcp',
            },
        });
        fireEvent.click(
            screen.getByLabelText(/Generate a new 64-character shared secret/i),
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Save remote support' }),
        );

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/settings/obli/remote-support',
                expect.objectContaining({
                    expected_actor_id: 7,
                    expected_version: 0,
                    endpoint_management_enabled: true,
                    remote_support_enabled: true,
                    relay_urls: [
                        'turns:turn.oblivionfindings.com:5349?transport=tcp',
                    ],
                    generate_relay_secret: true,
                }),
                expect.any(Object),
            );
        });

        expect(
            await screen.findByText(/Attended remote support on/i),
        ).toBeTruthy();
        expect(
            screen.getByLabelText('Generated TURN shared secret'),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Test relay' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/settings/obli/remote-support/verify-relay',
                {},
                expect.any(Object),
            );
        });

        expect(await screen.findByText('Relay answered')).toBeTruthy();
    });
});
