import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TicketMergeRecovery } from '../ticket-merge-recovery';
const mocks = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: mocks }));
const uuid = '12345678-1234-4234-8234-123456789abc';
const key = 'it.pending-merge-command.v1.7:41';
function mount(actorId = 7) {
    return render(<TicketMergeRecovery actorId={actorId} sourceId={41} />);
}
function response(status = 'committed') {
    return {
        status: 200,
        data: {
            status,
            data: {
                viewer_user_id: 7,
                source_id: 41,
                target_id: 42,
                request_uuid: uuid,
                operation: 'ticket.merge',
                source_version: 4,
                target_version: 5,
                url: '/it/tickets/42?merged_from=41',
                replayed: true,
                cancelled_at: '2026-09-10T03:00:00Z',
            },
        },
    };
}
beforeEach(() => {
    sessionStorage.clear();
    mocks.visit.mockClear();
    sessionStorage.setItem(
        key,
        JSON.stringify([{ targetId: 42, requestUuid: uuid }]),
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    sessionStorage.clear();
});
it('recovers a saved result from an original record without candidate data or a new merge', async () => {
    const get = vi.spyOn(axios, 'get').mockResolvedValue(response());
    const post = vi.spyOn(axios, 'post');
    mount();
    expect(get).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Check merge result 1' }),
    );
    await screen.findByRole('button', {
        name: 'Open confirmed surviving ticket',
    });
    expect(get).toHaveBeenCalledWith(
        `/it/tickets/41/merge-commands/${uuid}`,
        expect.objectContaining({
            params: { actor_user_id: 7, target_ticket_id: 42 },
        }),
    );
    expect(post).not.toHaveBeenCalled();
    expect(mocks.visit).not.toHaveBeenCalled();
    expect(sessionStorage.getItem(key)).toBeNull();
    fireEvent.click(
        screen.getByRole('button', { name: 'Open confirmed surviving ticket' }),
    );
    expect(mocks.visit).toHaveBeenCalledWith('/it/tickets/42?merged_from=41');
});
it('confirms cancellation and preserves the reference when cancellation fails', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValue({ isAxiosError: true, response: { status: 500 } });
    mount();
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel pending merge 1' }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel merge command' }),
    );
    await screen.findByText(/result is not confirmed/i);
    expect(sessionStorage.getItem(key)).not.toBeNull();
    expect(
        screen.queryByRole('button', {
            name: 'Open confirmed surviving ticket',
        }),
    ).not.toBeInTheDocument();
});
it('does not expose another actor recovery references', () => {
    mount(8);
    expect(
        screen.queryByRole('region', { name: 'Merge result recovery' }),
    ).not.toBeInTheDocument();
});
