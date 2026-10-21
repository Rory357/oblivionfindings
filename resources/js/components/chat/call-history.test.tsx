import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { CallHistory } from './call-history';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
    conversationPath: (id: number) => `/chat/api/conversations/${id}`,
}));
vi.mock('./call-summary', () => ({ CallSummary: () => null }));

const call = (id: string, outcome = 'missed') => ({
    id,
    status: 'ended',
    direction: 'incoming',
    outcome,
    client_id: null,
    client_name: null,
    can_record_note: false,
    created_at: '2026-10-16T01:00:00Z',
    ended_at: '2026-10-16T01:01:00Z',
    connected_at: null,
    participants: [{ id: 2, name: `Person ${id}` }],
});

describe('call history', () => {
    beforeEach(() => vi.clearAllMocks());

    it('requests missed history from the server and loads its next page without duplicates', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                calls: [call('declined', 'declined')],
                next_cursor: null,
            })
            .mockResolvedValueOnce({
                calls: [call('missed-1')],
                next_cursor: 'cursor-1',
            })
            .mockResolvedValueOnce({
                calls: [call('missed-1'), call('missed-2')],
                next_cursor: null,
            });
        render(<CallHistory actorId={11} conversationId={17} />);
        fireEvent.click(screen.getByRole('button', { name: 'Call history' }));
        await screen.findByText('Person declined');
        fireEvent.click(screen.getByRole('button', { name: 'Missed' }));
        await screen.findByText('Person missed-1');
        expect(screen.queryByText('Person declined')).not.toBeInTheDocument();
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/conversations/17/calls?filter=missed',
            expect.objectContaining({
                signal: expect.any(AbortSignal),
                headers: expect.any(Headers),
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older calls' }),
        );
        await screen.findByText('Person missed-2');
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/conversations/17/calls?filter=missed&before=cursor-1',
            expect.objectContaining({
                signal: expect.any(AbortSignal),
                headers: expect.any(Headers),
            }),
        );
        expect(screen.getAllByText('Person missed-1')).toHaveLength(1);
        expect(
            new Headers(
                vi.mocked(chatRequest).mock.calls.at(-1)?.[1]?.headers,
            ).get('X-Chat-Actor'),
        ).toBe('11');
    });

    it('ignores a delayed response from a previously selected filter', async () => {
        let resolveAll!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveAll = resolve;
                    }),
            )
            .mockResolvedValueOnce({ calls: [], next_cursor: null });
        render(<CallHistory actorId={11} conversationId={17} />);
        fireEvent.click(screen.getByRole('button', { name: 'Call history' }));
        fireEvent.click(screen.getByRole('button', { name: 'Missed' }));
        await screen.findByText('No missed calls.');
        resolveAll({ calls: [call('old')], next_cursor: null });
        await waitFor(() =>
            expect(screen.queryByText('Person old')).not.toBeInTheDocument(),
        );
    });

    it('opens the missed filter from a notice without a second trigger or automatic redial', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            calls: [call('notice')],
            next_cursor: null,
        });
        render(
            <CallHistory
                actorId={11}
                conversationId={17}
                initiallyOpen
                initialFilter="missed"
                hideTrigger
            />,
        );
        await screen.findByText('Person notice');
        expect(screen.getByRole('button', { name: 'Missed' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(
            screen.queryByRole('button', { name: 'Call history' }),
        ).not.toBeInTheDocument();
        expect(vi.mocked(chatRequest).mock.calls.map(([url]) => url)).toEqual([
            '/chat/api/conversations/17/calls?filter=missed',
        ]);
    });

    it('aborts old-account history and ignores its response after replacement', async () => {
        let resolveOld!: (value: unknown) => void;
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveOld = resolve;
                    }),
            )
            .mockResolvedValueOnce({ calls: [call('new')], next_cursor: null });
        const view = render(
            <CallHistory actorId={11} conversationId={17} initiallyOpen />,
        );
        await waitFor(() => expect(chatRequest).toHaveBeenCalledOnce());
        const signal = vi.mocked(chatRequest).mock.calls[0][1]?.signal;
        view.rerender(
            <CallHistory actorId={12} conversationId={17} initiallyOpen />,
        );
        await screen.findByText('Person new');
        expect(signal?.aborted).toBe(true);
        resolveOld({ calls: [call('old-account')], next_cursor: null });
        await waitFor(() =>
            expect(
                screen.queryByText('Person old-account'),
            ).not.toBeInTheDocument(),
        );
        expect(
            new Headers(
                vi.mocked(chatRequest).mock.calls.at(-1)?.[1]?.headers,
            ).get('X-Chat-Actor'),
        ).toBe('12');
    });

    it('clears earlier call details when the session expires during paging', async () => {
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                calls: [call('private')],
                next_cursor: 'next',
            })
            .mockRejectedValueOnce(new ChatError('Session expired', 419));
        render(<CallHistory actorId={11} conversationId={17} initiallyOpen />);
        await screen.findByText('Person private');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older calls' }),
        );
        await screen.findByRole('alert');
        expect(screen.queryByText('Person private')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Load older calls' }),
        ).not.toBeInTheDocument();
    });
});
