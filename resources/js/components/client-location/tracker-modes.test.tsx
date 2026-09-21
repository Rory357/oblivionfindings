import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import TrackerModes from './tracker-modes';

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
const envelope = {
    access_fingerprint: 'current', checked_at: '2026-09-21T00:00:00Z', observed: { mode: null, reported_at: null },
    unavailable_reason: null, request: null,
    modes: [
        { id: 'standard', label: 'Standard', interval_seconds: 30, profile_id: 1, profile_version: 1, available: true },
        { id: 'live', label: 'Live tracking', interval_seconds: 10, profile_id: 2, profile_version: 1, available: true },
        { id: 'power_saving', label: 'Power saving', interval_seconds: 120, profile_id: 3, profile_version: 1, available: true },
    ], changes: [{ id: 7, label: 'Approved reporting change', ends_at: '2026-09-21T01:00:00Z' }],
};
const response = (data: unknown, status = 200) => ({ ok: status === 200, status, json: async () => data });
const props = { url: '/modes', fingerprint: 'current', onAccessEnded: vi.fn() };

it('requires an intentional mode, approved change, reason and impact review, then preserves the exact retry', async () => {
    const bodies: Record<string, unknown>[] = [];
    vi.stubGlobal('fetch', vi.fn(async (_url, options) => {
        if (options.method === 'POST') {
            bodies.push(JSON.parse(options.body));
            return bodies.length === 1 ? response({ message: 'Connection interrupted' }, 503) : response({ ...envelope, request: { id: 'mode-1', status: 'awaiting_approval', terminal: false, profile_id: 2, requested_at: envelope.checked_at, expires_at: '2099-01-01T00:00:00Z' } });
        }
        return response(envelope);
    }));
    render(<TrackerModes {...props} />);
    await waitFor(() => expect(fetch).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Manage tracker mode' }));
    const send = await screen.findByRole('button', { name: 'Request mode change' });
    expect(send).toBeDisabled();
    fireEvent.click(screen.getByRole('radio', { name: /Live tracking/ }));
    fireEvent.change(screen.getByLabelText('Approved change'), { target: { value: '7' } });
    fireEvent.change(screen.getByLabelText('Reason for this change'), { target: { value: 'Follow the agreed community outing.' } });
    expect(send).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(send);
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Retry same request' }));
    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toEqual(bodies[0]);
    expect(bodies[0]).toMatchObject({ mode: 'live', profile_id: 2, it_change_id: 7, impact_acknowledged: true, access_fingerprint: 'current' });
    await screen.findByText('A separate authorised reviewer must approve this configuration change in Device Profile.');
    expect(screen.getByText('Mode not confirmed')).toBeVisible();
    expect(screen.queryByRole('radio')).not.toBeInTheDocument();
});

it('shows unsupported hardware without inventing a confirmed state or allowing a command', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ ...envelope, unavailable_reason: 'Tracker model is not supported.', modes: envelope.modes.map((mode) => ({ ...mode, available: false })) })));
    render(<TrackerModes {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Manage tracker mode' }));
    await screen.findByText('Tracker model is not supported.');
    expect(screen.getByRole('radio', { name: /Power saving/ })).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Request mode change' })).not.toBeInTheDocument();
});

it('ignores late responses after unmount and clears access on a current denial', async () => {
    let resolve!: (value: unknown) => void;
    const ended = vi.fn();
    vi.stubGlobal('fetch', vi.fn(() => new Promise((done) => { resolve = done; })));
    const view = render(<TrackerModes {...props} onAccessEnded={ended} />);
    view.unmount();
    await act(async () => resolve(response({}, 403)));
    expect(ended).not.toHaveBeenCalled();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({}, 403)));
    render(<TrackerModes {...props} onAccessEnded={ended} />);
    await waitFor(() => expect(ended).toHaveBeenCalledTimes(1));
});
