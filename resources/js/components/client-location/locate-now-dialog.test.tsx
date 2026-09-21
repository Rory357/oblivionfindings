import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import LocateNowDialog, { type LocateRequest } from './locate-now-dialog';

const fingerprint = 'a'.repeat(64);
const request: LocateRequest = {
    id: 'first',
    status: 'awaiting_step_up',
    reason: 'Check the agreed pickup location.',
    requested_at: '2026-09-21T01:00:00Z',
    expires_at: '2099-09-21T01:03:00Z',
    sent_at: null,
    acknowledged_at: null,
    completed_at: null,
    terminal: false,
    status_url: '/requests/first',
    identity_url: '/requests/first/confirm-identity',
    resume_url: '/requests/first/resume',
    observation: null,
};
const point = {
    lat: -36.85,
    lng: 174.76,
    timestamp: '2026-09-21T01:00:10Z',
    received_at: '2026-09-21T01:00:12Z',
    is_new: true,
};
const props = {
    open: true,
    onOpenChange: vi.fn(),
    clientId: 1,
    trackerName: 'Review pendant',
    lastMeasuredAt: '2026-09-20T23:00:00Z',
    url: '/requests',
    fingerprint,
    onAccessEnded: vi.fn(),
    onObservation: vi.fn(),
};
const envelope = (value: LocateRequest | null = null) => ({
    available: true,
    unavailable_reason: null,
    request: value,
    access_fingerprint: fingerprint,
    checked_at: '2026-09-21T01:00:15Z',
});
const response = (value: unknown, status = 200) => ({
    ok: status < 400,
    status,
    json: async () => value,
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value: 'visible',
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('validates the reason, suppresses double submit and offers real identity confirmation', async () => {
    const pending = deferred<ReturnType<typeof response>>();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(envelope()))
        .mockReturnValueOnce(pending.promise);
    vi.stubGlobal('fetch', fetchMock);
    render(<LocateNowDialog {...props} />);
    const send = await screen.findByRole('button', {
        name: 'Request location',
    });
    fireEvent.click(send);
    expect(screen.getByRole('alert')).toHaveTextContent(
        'at least 10 characters',
    );
    fireEvent.change(
        screen.getByLabelText('Why are you requesting this location?'),
        { target: { value: request.reason } },
    );
    fireEvent.click(send);
    fireEvent.click(send);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await act(async () => pending.resolve(response(envelope(request), 201)));
    expect(
        await screen.findByRole('link', { name: 'Confirm identity' }),
    ).toHaveAttribute(
        'href',
        `/requests/first/confirm-identity?access_fingerprint=${fingerprint}`,
    );
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(props.onObservation).not.toHaveBeenCalled();
});

it('recovers the existing request on reopen and keeps acknowledgement separate from a fix', async () => {
    const acked = {
        ...request,
        status: 'running',
        sent_at: request.requested_at,
        acknowledged_at: '2026-09-21T01:00:08Z',
    };
    const fetchMock = vi.fn().mockResolvedValue(response(envelope(acked)));
    vi.stubGlobal('fetch', fetchMock);
    const view = render(<LocateNowDialog {...props} />);
    expect(await screen.findByText('Tracker acknowledged')).toBeVisible();
    expect(screen.getByText('No newer measured location yet')).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Request location' }),
    ).not.toBeInTheDocument();
    view.rerender(<LocateNowDialog {...props} open={false} />);
    view.rerender(<LocateNowDialog {...props} open />);
    expect(await screen.findByText('Tracker acknowledged')).toBeVisible();
    expect(props.onObservation).not.toHaveBeenCalled();
    expect(
        fetchMock.mock.calls.every(([, options]) => options.method === 'GET'),
    ).toBe(true);
});

it('applies only a new measured observation, with its original measured timestamp', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            response(
                envelope({
                    ...request,
                    status: 'reconciled',
                    terminal: true,
                    observation: { ...point, is_new: false },
                }),
            ),
        )
        .mockResolvedValue(
            response(
                envelope({
                    ...request,
                    status: 'reconciled',
                    terminal: true,
                    observation: point,
                }),
            ),
        );
    vi.stubGlobal('fetch', fetchMock);
    render(<LocateNowDialog {...props} />);
    await screen.findByText('No newer measured location yet');
    expect(props.onObservation).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    expect(await screen.findByText('New location received')).toBeVisible();
    expect(props.onObservation).toHaveBeenCalledExactlyOnceWith(point);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
    expect(props.onObservation).toHaveBeenCalledTimes(1);
});

it('retries an uncertain submission with the same nonce and immutable reason', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(envelope()))
        .mockRejectedValueOnce(new Error('Offline'))
        .mockResolvedValueOnce(response(envelope(request), 201));
    vi.stubGlobal('fetch', fetchMock);
    render(<LocateNowDialog {...props} />);
    await screen.findByRole('button', { name: 'Request location' });
    fireEvent.change(
        screen.getByLabelText('Why are you requesting this location?'),
        { target: { value: request.reason } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Request location' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Offline');
    expect(
        screen.getByLabelText('Why are you requesting this location?'),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Retry same request' }));
    await screen.findByRole('link', { name: 'Confirm identity' });
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual(
        JSON.parse(fetchMock.mock.calls[2][1].body),
    );
});

it('ignores a late parsed response after close and reopen', async () => {
    const oldJson = deferred<unknown>();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => oldJson.promise,
        })
        .mockResolvedValueOnce(response(envelope()));
    vi.stubGlobal('fetch', fetchMock);
    const view = render(<LocateNowDialog {...props} />);
    await act(async () => {});
    view.rerender(<LocateNowDialog {...props} open={false} />);
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
    view.rerender(<LocateNowDialog {...props} open />);
    await screen.findByRole('button', { name: 'Request location' });
    await act(async () =>
        oldJson.resolve(envelope({ ...request, observation: point })),
    );
    expect(screen.queryByText('New location received')).not.toBeInTheDocument();
    expect(props.onObservation).not.toHaveBeenCalled();
});

it('clears the request and ends the workspace when current access is denied', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(envelope(request)))
        .mockResolvedValueOnce(response({}, 403));
    vi.stubGlobal('fetch', fetchMock);
    render(<LocateNowDialog {...props} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Refresh status' }),
    );
    await waitFor(() => expect(props.onAccessEnded).toHaveBeenCalledTimes(1));
    expect(props.onOpenChange).toHaveBeenCalledWith(false);
    expect(props.onObservation).not.toHaveBeenCalled();
});

it('aborts hidden-page work and revalidates when visible without accepting the old response', async () => {
    const late = deferred<unknown>();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => late.promise,
        })
        .mockResolvedValueOnce(response(envelope(request)));
    vi.stubGlobal('fetch', fetchMock);
    render(<LocateNowDialog {...props} />);
    await act(async () => {});
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value: 'hidden',
    });
    fireEvent(document, new Event('visibilitychange'));
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
    await act(async () =>
        late.resolve(envelope({ ...request, observation: point })),
    );
    expect(props.onObservation).not.toHaveBeenCalled();
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value: 'visible',
    });
    fireEvent(document, new Event('visibilitychange'));
    await screen.findByRole('link', { name: 'Confirm identity' });
    expect(fetchMock).toHaveBeenCalledTimes(2);
});

it('aborts on unmount and returns keyboard focus to the opener on Escape', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response(envelope()));
    vi.stubGlobal('fetch', fetchMock);
    function Host() {
        const [open, setOpen] = useState(false);
        return (
            <>
                <Button onClick={() => setOpen(true)}>Open locate</Button>
                <LocateNowDialog
                    {...props}
                    open={open}
                    onOpenChange={setOpen}
                />
            </>
        );
    }
    const view = render(<Host />);
    const opener = screen.getByRole('button', { name: 'Open locate' });
    opener.focus();
    fireEvent.click(opener);
    await screen.findByRole('button', { name: 'Request location' });
    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
    await waitFor(() => expect(opener).toHaveFocus());
    view.unmount();
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
});
