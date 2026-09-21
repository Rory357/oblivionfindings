import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ZoneDraft } from './types';
import ZoneMonitoringDialog from './zone-monitoring-dialog';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const zone: ZoneDraft = {
    id: 1,
    revision: 2,
    status: 'draft',
    name: 'Library',
    purpose: 'Visit',
    classification: 'agreed',
    geometry_source: 'custom',
    geometry: {
        type: 'circle',
        center: { lat: -36.85, lng: 174.76 },
        radius_m: 80,
    },
    schedule: {
        timezone: 'Pacific/Auckland',
        weekdays: [1],
        start: '09:00',
        end: '16:00',
        following_day: false,
        first_date: '2026-09-21',
        last_date: '2026-10-30',
        exception_dates: [],
    },
    response_proposal: 'Call the duty coordinator.',
    saved_at: '2026-09-21T00:00:00Z',
};
const props = {
    zone,
    url: '/zones',
    fingerprint: 'current',
    onClose: vi.fn(),
    onSaved: vi.fn(),
    onAccessEnded: vi.fn(),
};

it('requires review and sends the exact revision to Control Room activation without losing error context', async () => {
    const request = vi
        .fn()
        .mockResolvedValueOnce({
            ok: false,
            status: 409,
            json: async () => ({ message: 'Changed' }),
        })
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ zone }),
        });
    vi.stubGlobal('fetch', request);
    render(<ZoneMonitoringDialog {...props} />);
    const activate = screen.getByRole('button', {
        name: 'Activate monitoring',
    });
    expect(activate).toBeDisabled();
    expect(screen.getByText('Control Room · High priority')).toBeVisible();
    expect(screen.getByText('Call the duty coordinator.')).toBeVisible();
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(activate);
    await screen.findByRole('alert');
    expect(props.onSaved).not.toHaveBeenCalled();
    fireEvent.click(activate);
    await waitFor(() => expect(props.onSaved).toHaveBeenCalledWith(zone));
    const first = JSON.parse(request.mock.calls[0][1].body);
    expect(first).toMatchObject({
        action: 'activate',
        expected_revision: 2,
        access_fingerprint: 'current',
        reviewed: true,
    });
    expect(JSON.parse(request.mock.calls[1][1].body).idempotency_key).toBe(
        first.idempotency_key,
    );
});

it('binds a pause to the current monitoring run and keeps existing alerts open', async () => {
    const request = vi
        .fn()
        .mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ zone }),
        });
    vi.stubGlobal('fetch', request);
    render(
        <ZoneMonitoringDialog
            {...props}
            zone={{
                ...zone,
                monitoring: {
                    id: 8,
                    status: 'active',
                    started_at: zone.saved_at,
                    ended_at: null,
                    last_observed_at: null,
                    position_status: 'unknown',
                    breach_count: 0,
                    in_schedule: true,
                    destination: 'Control Room',
                },
            }}
        />,
    );
    expect(
        screen.getByText(/Existing Control Room alerts remain open/),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Pause monitoring' }));
    await waitFor(() => expect(request).toHaveBeenCalledOnce());
    expect(JSON.parse(request.mock.calls[0][1].body)).toMatchObject({
        action: 'pause',
        monitor_id: 8,
    });
});

it('rejects late responses after closing and ends access on a permission loss', async () => {
    let resolve!: (value: unknown) => void;
    vi.stubGlobal(
        'fetch',
        vi.fn().mockReturnValue(
            new Promise((done) => {
                resolve = done;
            }),
        ),
    );
    const saved = vi.fn();
    const view = render(<ZoneMonitoringDialog {...props} onSaved={saved} />);
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Activate monitoring' }),
    );
    view.unmount();
    await act(async () =>
        resolve({ ok: true, status: 200, json: async () => ({ zone }) }),
    );
    expect(saved).not.toHaveBeenCalled();
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: false, status: 403 }),
    );
    const ended = vi.fn();
    render(<ZoneMonitoringDialog {...props} onAccessEnded={ended} />);
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Activate monitoring' }),
    );
    await waitFor(() => expect(ended).toHaveBeenCalledOnce());
});
