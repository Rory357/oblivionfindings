import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EligibilityRefreshPanel,
    loadEligibilityObservations,
    type EligibilityObservation,
} from './eligibility-refresh-panel';
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
const request = vi.fn();
const observation: EligibilityObservation = {
    shift_id: 7,
    posture: 'clear',
    freshness: 'current',
    starts_at: '2026-10-12T09:00:00+13:00',
    checked_at: '2026-10-05T08:00:00+13:00',
    last_successful_at: '2026-10-05T08:00:00+13:00',
    block_count: 0,
    warning_count: 0,
    can_retry: true,
    retry_url: '/operations/workforce/eligibility-refresh/7/retry',
};
const response = (rows: EligibilityObservation[], current = 1, last = 1) => ({
    ok: true,
    json: async () => ({ data: rows, current_page: current, last_page: last }),
});
beforeEach(() => {
    request.mockReset();
    vi.stubGlobal('fetch', request);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('eligibility freshness and recovery', () => {
    it('fetches every page and chunks ID filters without an unscoped request', async () => {
        const ids = Array.from({ length: 201 }, (_, i) => i + 1);
        request
            .mockResolvedValueOnce(response([observation], 1, 2))
            .mockResolvedValueOnce(
                response(
                    [
                        {
                            ...observation,
                            shift_id: 199,
                            retry_url:
                                '/operations/workforce/eligibility-refresh/199/retry',
                        },
                    ],
                    2,
                    2,
                ),
            )
            .mockResolvedValueOnce(
                response([
                    {
                        ...observation,
                        shift_id: 201,
                        retry_url:
                            '/operations/workforce/eligibility-refresh/201/retry',
                    },
                ]),
            );
        const result = await loadEligibilityObservations(
            ids,
            new AbortController().signal,
        );
        expect(result.map((row) => row.shift_id)).toEqual([7, 199, 201]);
        expect(request).toHaveBeenCalledTimes(3);
        const queries = request.mock.calls.map(
            ([url]) => new URL(String(url), 'http://localhost').searchParams,
        );
        expect(queries[0].getAll('shift_ids[]')).toHaveLength(200);
        expect(queries[1].get('page')).toBe('2');
        expect(queries[2].getAll('shift_ids[]')).toEqual(['201']);
    });
    it('never requests all shifts for an empty selection', async () => {
        expect(
            await loadEligibilityObservations([], new AbortController().signal),
        ).toEqual([]);
        expect(request).not.toHaveBeenCalled();
    });
    it('retains the last result visibly as old when refresh fails', async () => {
        request
            .mockResolvedValueOnce(
                response([{ ...observation, freshness: 'failed' }]),
            )
            .mockRejectedValueOnce(new Error('Offline'));
        render(
            <EligibilityRefreshPanel
                duties={[{ id: 7, label: 'Preview worker duty' }]}
            />,
        );
        await screen.findByText('Check unavailable');
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh results' }),
        );
        await screen.findByRole('alert');
        expect(screen.getByText('Previously loaded result')).toBeTruthy();
        expect(
            screen.getByText('Last result: No eligibility issues found'),
        ).toBeTruthy();
        expect(screen.queryByText('Up to date')).toBeNull();
        expect(
            screen
                .getByRole('button', { name: 'Recheck shift' })
                .hasAttribute('disabled'),
        ).toBe(true);
    });
    it('does not offer retry to a read-only worker', async () => {
        request.mockResolvedValue(
            response([
                {
                    ...observation,
                    freshness: 'unverified',
                    can_retry: false,
                    retry_url: null,
                },
            ]),
        );
        render(
            <EligibilityRefreshPanel duties={[{ id: 7, label: 'My duty' }]} />,
        );
        await screen.findByText('Not checked yet');
        expect(
            screen.queryByRole('button', { name: 'Recheck shift' }),
        ).toBeNull();
    });
    it('only requests the permitted retry URL and displays an actual queued result', async () => {
        request
            .mockResolvedValueOnce(
                response([{ ...observation, freshness: 'stale' }]),
            )
            .mockResolvedValueOnce({
                ok: true,
                status: 202,
                json: async () => ({ shift_id: 7, queued: true }),
            })
            .mockResolvedValueOnce(
                response([{ ...observation, freshness: 'pending' }]),
            );
        render(
            <EligibilityRefreshPanel
                duties={[{ id: 7, label: 'Preview duty' }]}
            />,
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Recheck shift' }),
        );
        await screen.findByText('Recheck pending');
        expect(request.mock.calls[1][0]).toBe(observation.retry_url);
        expect(request.mock.calls[1][1].method).toBe('POST');
        expect(
            screen
                .getByRole('button', { name: 'Recheck shift' })
                .hasAttribute('disabled'),
        ).toBe(true);
    });
    it('clears previous scope results when the selected duties change', async () => {
        request
            .mockResolvedValueOnce(
                response([{ ...observation, freshness: 'failed' }]),
            )
            .mockResolvedValueOnce(response([]));
        const view = render(
            <EligibilityRefreshPanel
                duties={[{ id: 7, label: 'Old selection' }]}
            />,
        );
        await screen.findByText('Old selection');
        view.rerender(
            <EligibilityRefreshPanel
                duties={[{ id: 8, label: 'New selection' }]}
            />,
        );
        await waitFor(() =>
            expect(screen.queryByText('Old selection')).toBeNull(),
        );
        await screen.findByText(
            'No current or published future assigned shifts in this selection.',
        );
    });
});

it('holds an unconfirmed retry until a complete fresh read succeeds', async () => {
    request
        .mockResolvedValueOnce(
            response([{ ...observation, freshness: 'failed' }]),
        )
        .mockRejectedValueOnce(new Error('Disconnected'))
        .mockResolvedValueOnce(
            response([{ ...observation, freshness: 'pending' }]),
        );
    render(<EligibilityRefreshPanel duties={[{ id: 7, label: 'My duty' }]} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Recheck shift' }),
    );
    await screen.findByText(/It may already be queued/);
    expect(
        screen.getByRole('button', { name: 'Recheck shift' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Recheck shift' }));
    expect(request).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh results' }));
    await screen.findByText('Recheck pending');
    expect(request).toHaveBeenCalledTimes(3);
});
it('rejects a retry destination for a different duty', async () => {
    request.mockResolvedValueOnce(
        response([
            {
                ...observation,
                retry_url: '/operations/workforce/eligibility-refresh/8/retry',
            },
        ]),
    );
    await expect(
        loadEligibilityObservations([7], new AbortController().signal),
    ).rejects.toThrow('incomplete');
});
