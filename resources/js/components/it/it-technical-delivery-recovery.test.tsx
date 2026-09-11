import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { parseDeliveryReview } from './it-technical-delivery-contract';
import { ItTechnicalDeliveryRecovery } from './it-technical-delivery-recovery';

const review = {
    viewer_user_id: 7,
    source: 'device',
    id: 21,
    version: 'a'.repeat(64),
    can_retry: true,
    state: 'failed',
    source_delivered: true,
    attempts: 4,
    attempt_limit: 5,
    outcome_code: 'processing_failed',
    created_at: '2026-09-11T18:00:00Z',
    completed_at: null,
    last_attempt_at: null,
};
const result = {
    ...review,
    version: 'b'.repeat(64),
    state: 'pending',
    can_retry: false,
    retry_requested: true,
};
const props = {
    actorId: 7,
    source: 'device' as const,
    deliveryId: 21,
    onAccessLost: vi.fn(),
};
beforeEach(() => {
    vi.restoreAllMocks();
    props.onAccessLost.mockClear();
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: review } });
    vi.spyOn(axios, 'post').mockResolvedValue({ data: { data: result } });
});
async function consent() {
    fireEvent.click(
        await screen.findByRole('checkbox', {
            name: 'I want to request one delivery attempt.',
        }),
    );
}

it('requires a fresh review and explicit consent then reports only the recorded retry allowance', async () => {
    render(<ItTechnicalDeliveryRecovery {...props} />);
    const retry = await screen.findByRole('button', {
        name: 'Request one retry',
    });
    expect(retry).toBeDisabled();
    await consent();
    fireEvent.click(retry);
    expect(
        await screen.findByText(/One retry allowance was recorded/),
    ).toBeInTheDocument();
    expect(screen.getByText('Awaiting IT delivery')).toBeInTheDocument();
    expect(screen.queryByText('IT outcome recorded')).not.toBeInTheDocument();
    expect(axios.post).toHaveBeenCalledExactlyOnceWith(
        '/it/setup/technical-deliveries/device/21/retry',
        { viewer_user_id: 7, version: review.version },
        expect.objectContaining({
            signal: expect.any(AbortSignal),
            timeout: 15000,
        }),
    );
    expect(
        screen.queryByRole('button', { name: 'Request one retry' }),
    ).not.toBeInTheDocument();
});

it('requires refresh and new consent after a stale review rather than rewriting and resending silently', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 409 },
    });
    render(<ItTechnicalDeliveryRecovery {...props} />);
    await consent();
    fireEvent.click(screen.getByRole('button', { name: 'Request one retry' }));
    expect(await screen.findByText(/Delivery changed/)).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    vi.mocked(axios.get).mockResolvedValueOnce({
        data: { data: { ...review, version: 'c'.repeat(64) } },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Refresh delivery outcome' }),
    );
    expect(
        await screen.findByRole('button', { name: 'Request one retry' }),
    ).toBeDisabled();
    await consent();
    fireEvent.click(screen.getByRole('button', { name: 'Request one retry' }));
    await screen.findByText(/One retry allowance was recorded/);
    expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual({
        viewer_user_id: 7,
        version: 'c'.repeat(64),
    });
});

it('stops waiting without claiming cancellation and ignores a late result after a newer status check', async () => {
    let complete!: (value: unknown) => void;
    vi.mocked(axios.post).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                complete = resolve;
            }),
    );
    render(<ItTechnicalDeliveryRecovery {...props} />);
    await consent();
    fireEvent.click(screen.getByRole('button', { name: 'Request one retry' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
    expect(
        screen.getByText(/This does not cancel a recorded retry/),
    ).toBeInTheDocument();
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { data: result } });
    fireEvent.click(
        screen.getByRole('button', { name: 'Refresh delivery outcome' }),
    );
    await screen.findByText('Awaiting IT delivery');
    await act(async () =>
        complete({ data: { data: { ...result, state: 'applied' } } }),
    );
    expect(screen.queryByText('IT outcome recorded')).not.toBeInTheDocument();
    expect(
        screen.queryByText(/One retry allowance was recorded/),
    ).not.toBeInTheDocument();
    expect(axios.post).toHaveBeenCalledTimes(1);
});

it('does not treat a failed or malformed mutation response as a successful retry', async () => {
    vi.mocked(axios.post).mockResolvedValueOnce({
        data: { data: { ...result, retry_requested: false } },
    });
    render(<ItTechnicalDeliveryRecovery {...props} />);
    await consent();
    fireEvent.click(screen.getByRole('button', { name: 'Request one retry' }));
    expect(
        await screen.findByText(/retry result could not be confirmed/),
    ).toBeInTheDocument();
    expect(
        screen.queryByText(/One retry allowance was recorded/),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it.each([401, 403, 404, 419])(
    'conceals current delivery details on access/session failure %i',
    async (status) => {
        render(<ItTechnicalDeliveryRecovery {...props} />);
        await consent();
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Request one retry' }),
        );
        await waitFor(() => expect(props.onAccessLost).toHaveBeenCalledOnce());
        expect(screen.queryByText('Delivery failed')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    },
);

it('aborts a pending read on unmount and ignores late denial from that read', async () => {
    let reject!: (value: unknown) => void;
    vi.mocked(axios.get).mockImplementationOnce(
        () =>
            new Promise((_, fail) => {
                reject = fail;
            }),
    );
    const view = render(<ItTechnicalDeliveryRecovery {...props} />);
    const signal = vi.mocked(axios.get).mock.calls[0][1]?.signal;
    view.unmount();
    expect(signal?.aborted).toBe(true);
    await act(async () =>
        reject({ isAxiosError: true, response: { status: 403 } }),
    );
    expect(props.onAccessLost).not.toHaveBeenCalled();
});

it.each([
    { viewer_user_id: 8 },
    { source: 'fleet' },
    { id: 22 },
    { version: 'wrong' },
    { state: '__proto__' },
    { attempts: -1 },
    { source_delivered: false },
    { completed_at: 'not-a-time' },
])(
    'refuses a response whose identity or current-state contract is invalid: %j',
    (change) => {
        expect(
            parseDeliveryReview(
                { data: { ...review, ...change } },
                7,
                'device',
                21,
            ),
        ).toBeNull();
    },
);

it('drops unknown diagnostic properties rather than rendering provider content', () => {
    const parsed = parseDeliveryReview(
        {
            data: {
                ...review,
                outcome_code: '__proto__',
                raw_payload: 'private-source',
            },
        },
        7,
        'device',
        21,
    );
    expect(parsed?.outcome_code).toBeNull();
    expect(JSON.stringify(parsed)).not.toContain('private-source');
});
