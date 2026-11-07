import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mergeConversationRows, namedRows } from './list-rows';
import { ChatSync } from './sync';
import { createTypingSignal, typingLabel, useTypingNames } from './typing';

describe('typing signals', () => {
    let sync: ChatSync;
    beforeEach(() => {
        vi.useFakeTimers();
        sync = new ChatSync();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('sends start at most every 3 seconds while typing, and stop after a pause or a send', async () => {
        sync.setState('live');
        const send = vi.fn().mockResolvedValue(undefined);
        const signal = createTypingSignal({
            actorId: 1,
            conversationId: 2,
            sync,
            send,
        });
        signal.input('H');
        signal.input('He');
        await vi.advanceTimersByTimeAsync(2900);
        signal.input('Hel');
        expect(send.mock.calls.map(([state]) => state)).toEqual(['start']);
        await vi.advanceTimersByTimeAsync(100);
        signal.input('Hell');
        expect(send.mock.calls.map(([state]) => state)).toEqual([
            'start',
            'start',
        ]);
        await vi.advanceTimersByTimeAsync(5000);
        expect(send.mock.calls.map(([state]) => state)).toEqual([
            'start',
            'start',
            'stop',
        ]);
        signal.input('Hello');
        signal.stop();
        signal.stop();
        expect(send.mock.calls.map(([state]) => state)).toEqual([
            'start',
            'start',
            'stop',
            'start',
            'stop',
        ]);
        // Clearing the box ends typing too.
        signal.input('x');
        signal.input('  ');
        expect(send.mock.calls.at(-1)?.[0]).toBe('stop');
    });

    it('sends nothing while live updates are down', () => {
        const send = vi.fn().mockResolvedValue(undefined);
        const signal = createTypingSignal({
            actorId: 1,
            conversationId: 2,
            sync,
            send,
        });
        signal.input('Hello');
        signal.stop();
        expect(send).not.toHaveBeenCalled();
    });

    it('words the typing line like the Chat v3 boards', () => {
        expect(typingLabel([])).toBeNull();
        expect(typingLabel(['Hana Wiremu'])).toBe('Hana is typing…');
        expect(typingLabel(['Care team'])).toBe('Care team is typing…');
        expect(typingLabel(['Hana Wiremu', 'Rohan Patel'])).toBe(
            'Hana and Rohan are typing…',
        );
        expect(typingLabel(['A B', 'C D', 'E F'])).toBe('3 people are typing…');
    });

    it('shows who is typing in this conversation for 6 seconds unless refreshed', async () => {
        function Line({ conversationId }: { conversationId: number }) {
            return (
                <p role="status">
                    {typingLabel(useTypingNames(conversationId, sync)) ??
                        'nobody'}
                </p>
            );
        }
        sync.setState('live');
        render(<Line conversationId={3} />);
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 4,
                who: 'Rohan Patel',
                state: 'start',
            }),
        );
        expect(screen.getByRole('status')).toHaveTextContent('nobody');
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 3,
                who: 'Care team',
                state: 'start',
            }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Care team is typing…',
        );
        await act(() => vi.advanceTimersByTimeAsync(4000));
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 3,
                who: 'Care team',
                state: 'start',
            }),
        );
        await act(() => vi.advanceTimersByTimeAsync(4000));
        expect(screen.getByRole('status')).toHaveTextContent(
            'Care team is typing…',
        );
        await act(() => vi.advanceTimersByTimeAsync(2100));
        expect(screen.getByRole('status')).toHaveTextContent('nobody');
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 3,
                who: 'Hana Wiremu',
                state: 'start',
            }),
        );
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 3,
                who: 'Hana Wiremu',
                state: 'stop',
            }),
        );
        expect(screen.getByRole('status')).toHaveTextContent('nobody');
        act(() =>
            sync.receive({
                type: 'typing',
                conversation_id: 3,
                who: 'Hana Wiremu',
                state: 'start',
            }),
        );
        act(() => sync.setState('recovering'));
        expect(screen.getByRole('status')).toHaveTextContent('nobody');
    });
});

describe('list rows named by live events', () => {
    const change = (conversation_id: number, kinds = ['message']) => ({
        type: 'conversation.changed' as const,
        conversation_id,
        change_id: 1,
        kinds,
    });

    it('names up to five conversations, and asks for the whole list otherwise', () => {
        expect(
            namedRows({
                kind: 'event',
                events: [change(1), change(2, ['read']), change(1)],
            }),
        ).toEqual({ ids: [1, 2], raised: [1] });
        expect(
            namedRows({
                kind: 'event',
                events: [change(1), { type: 'inbox.changed', reason: 'added' }],
            }),
        ).toBeNull();
        expect(
            namedRows({
                kind: 'event',
                events: [1, 2, 3, 4, 5, 6].map((id) => change(id)),
            }),
        ).toBeNull();
        expect(namedRows({ kind: 'poll', events: [] })).toBeNull();
        expect(namedRows({ kind: 'resync', events: [] })).toBeNull();
    });

    it('replaces rows, removes ones no longer visible and raises new messages to the top', () => {
        const list = [
            { id: 1, title: 'a' },
            { id: 2, title: 'b' },
            { id: 3, title: 'c' },
        ];
        expect(
            mergeConversationRows(list, [3, 2], [{ id: 3, title: 'c2' }], [3]),
        ).toEqual([
            { id: 3, title: 'c2' },
            { id: 1, title: 'a' },
        ]);
        expect(
            mergeConversationRows(list, [2], [{ id: 2, title: 'b2' }], []),
        ).toEqual([
            { id: 1, title: 'a' },
            { id: 2, title: 'b2' },
            { id: 3, title: 'c' },
        ]);
        // A conversation new to this list needs the whole list.
        expect(
            mergeConversationRows(list, [9], [{ id: 9, title: 'new' }], [9]),
        ).toBeNull();
    });
});
