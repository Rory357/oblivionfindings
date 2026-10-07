/* eslint-disable no-restricted-syntax -- Minimal test-only controls replace dialogs/network widgets to exercise command contracts. */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';
import { PharmacyDispatch } from './_dispatch';
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: () => null,
    usePage: () => ({ props: {} }),
}));
vi.mock('@/layouts/app-layout', () => ({ default: () => null }));
vi.mock('@/components/confirm-dialog', () => ({
    ConfirmDialog: ({ open, onConfirm, confirmText }: any) =>
        open ? <button onClick={onConfirm}>{confirmText}</button> : null,
}));
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        request: vi.fn(),
        isCancel: () => false,
        isAxiosError: () => false,
    },
}));
const status = (state: string) => ({
    enabled: true,
    connections: [],
    dispatch: { id: 9, state, label: state, attempt_count: 1 },
    can_send: false,
    can_retry: state === 'failed',
    can_cancel: ['queued', 'failed'].includes(state),
    can_resolve_unknown: state === 'unknown',
});
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.request).mockResolvedValue({ data: { success: true } });
});
it.each([
    ['failed', 'Review retry', 'Retry delivery', 'retry'],
    ['queued', 'Stop sending', 'Stop delivery', 'cancel'],
    ['failed', 'Stop sending', 'Stop delivery', 'cancel'],
])(
    'sends only accepted command fields for %s',
    async (state, open, confirm, path) => {
        vi.mocked(axios.get).mockResolvedValue({ data: status(state) });
        render(<PharmacyDispatch orderId={4} onSaved={vi.fn()} />);
        fireEvent.click(await screen.findByRole('button', { name: open }));
        fireEvent.click(screen.getByRole('button', { name: confirm }));
        await waitFor(() => expect(axios.request).toHaveBeenCalled());
        expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
            url: '/emar/stock/pharmacy-orders/4/dispatch/9/' + path,
            data: { request_uuid: expect.any(String), expected_state: state },
        });
        expect(
            Object.keys(
                vi.mocked(axios.request).mock.calls[0][0].data as Record<
                    string,
                    unknown
                >,
            ).sort(),
        ).toEqual(['expected_state', 'request_uuid']);
    },
);
it('uses a fresh command key when retry is followed by cancellation', async () => {
    vi.mocked(axios.get)
        .mockResolvedValueOnce({ data: status('failed') })
        .mockResolvedValue({ data: status('queued') });
    render(<PharmacyDispatch orderId={4} onSaved={vi.fn()} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review retry' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));
    await screen.findByText('queued');
    fireEvent.click(
        await screen.findByRole('button', { name: 'Stop sending' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Stop delivery' }));
    await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
    const calls = vi.mocked(axios.request).mock.calls;
    expect(
        (calls[0][0].data as { request_uuid: string }).request_uuid,
    ).not.toBe((calls[1][0].data as { request_uuid: string }).request_uuid);
});
