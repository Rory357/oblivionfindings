import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MonitoringHealthCard, type ServerHealthPayload } from './_server-check';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: vi.fn(), post: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, reload: vi.fn(), visit: vi.fn() } };
});

const health = (overrides: Partial<ServerHealthPayload> = {}): ServerHealthPayload => ({
    checked_at: '2026-11-02T01:15:00Z',
    actor_id: 5,
    service: { tone: 'success', label: 'Running', summary: 'All 8 parts are answering · last heard under a minute ago' },
    waiting: { tone: 'info', label: 'Working through', summary: '14 waiting, the oldest from 2 min ago · Device events 14' },
    watchdog: { tone: 'warning', label: 'Off', summary: 'No outside watchdog is set up, so nobody would be told if this whole server stopped' },
    failed: {
        tone: 'warning',
        label: '1 waiting',
        summary: 'Deliveries the monitoring service couldn’t process. Trying again uses the original message; it never repeats a device action',
        can_act: true,
        truncated: false,
        rows: [
            {
                id: 41,
                review_token: 'a'.repeat(64),
                site_id: 3,
                where: 'Synthetic House',
                what: 'Device events',
                why: 'Couldn’t be processed',
                reference: '9f8e7d6c',
                at: '2026-11-02T00:50:00Z',
                tries: 0,
                can_retry: true,
                can_stop: true,
                retrying: false,
            },
        ],
    },
    ...overrides,
});

describe('Setup › Server check: the monitoring service (S&D rebuild C6)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('shows the heartbeat, work waiting, failed deliveries and the outage watchdog in plain words', () => {
        render(<MonitoringHealthCard data={health()} />);
        const card = screen.getByRole('region', { name: 'Monitoring service' });

        expect(within(card).getByText('Heartbeat')).toBeTruthy();
        expect(within(card).getByText('Running')).toBeTruthy();
        expect(within(card).getByText('Work waiting')).toBeTruthy();
        expect(within(card).getByText('Outage watchdog')).toBeTruthy();
        expect(within(card).getByText('Off')).toBeTruthy();
        expect(within(card).getByText(/2 things need attention/)).toBeTruthy();
        expect(within(card).getByRole('button', { name: /Try again/ })).toBeTruthy();
        expect(within(card).getByRole('button', { name: /Stop trying/ })).toBeTruthy();
    });

    it('offers no delivery actions to people who don’t manage connected services', () => {
        render(<MonitoringHealthCard data={health({ failed: { ...health().failed, can_act: false, rows: [], label: '3 waiting' } })} />);

        expect(screen.getByText('3 waiting')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Try again/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /Stop trying/ })).toBeNull();
    });

    it('tries a failed delivery again with a confirm and a reason, through today’s recorded-decision route', async () => {
        render(<MonitoringHealthCard data={health()} />);
        fireEvent.click(screen.getByRole('button', { name: /Try again/ }));
        expect(await screen.findByText('Try this delivery again?')).toBeTruthy();

        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => {
            const sent = body as { request_uuid: string };
            return {
                data: {
                    decision: { dead_letter_id: 41, site_id: 3, consumer: 'event-projector', message_reference: '9f8e7d6c', reason_code: 'handler_failed', confirms: 'replay_request_recorded', confirms_processing: false, confirms_device_command: false },
                    receipt: { schema_version: 1, owner: 'Monitoring delivery recovery', request_uuid: sent.request_uuid, actor_id: 5, operation: 'replay', dead_letter_id: 41, site_id: 3, committed_at: '2026-11-02T01:16:00Z' },
                    current: { state: 'replay_requested', replay_count: 1, resolved_at: null },
                },
            };
        });
        fireEvent.change(screen.getByLabelText(/Why/), { target: { value: 'The events worker was restarted; process it again.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));

        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const [url, body] = vi.mocked(axios.post).mock.calls[0];
        expect(url).toBe('/security-devices/monitoring/dead-letters/41/replay');
        expect(body).toMatchObject({ expected_actor_id: 5, expected_site_id: 3, review_token: 'a'.repeat(64), reason: 'The events worker was restarted; process it again.' });
        await waitFor(() => expect(screen.queryByText('Try this delivery again?')).toBeNull());
    });
});
