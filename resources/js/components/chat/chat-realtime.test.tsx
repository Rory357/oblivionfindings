import { act, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ChatRealtimeProvider, useChatRealtime } from './chat-realtime';
import { connectChatRealtime } from './chat-realtime-connection';

vi.mock('./chat-realtime-connection', () => ({ connectChatRealtime: vi.fn() }));
function Status() {
    return <p role="status">{useChatRealtime()}</p>;
}
const view = (actorId: number | null, enabled = true) => (
    <ChatRealtimeProvider actorId={actorId} enabled={enabled}>
        <Status />
    </ChatRealtimeProvider>
);
beforeEach(() => {
    vi.mocked(connectChatRealtime).mockReset();
});
it('disposes the old connection and resets its visible state when the rendered account changes', () => {
    const stopFirst = vi.fn();
    const stopSecond = vi.fn();
    vi.mocked(connectChatRealtime)
        .mockReturnValueOnce(stopFirst)
        .mockReturnValueOnce(stopSecond);
    const mounted = render(view(1));
    act(() => vi.mocked(connectChatRealtime).mock.calls[0][1]('live'));
    expect(screen.getByRole('status')).toHaveTextContent('live');
    mounted.rerender(view(2));
    expect(stopFirst).toHaveBeenCalledOnce();
    expect(screen.getByRole('status')).toHaveTextContent('off');
    expect(
        vi.mocked(connectChatRealtime).mock.calls.map((args) => args[0]),
    ).toEqual([1, 2]);
    mounted.rerender(view(2, false));
    expect(stopSecond).toHaveBeenCalledOnce();
    expect(screen.getByRole('status')).toHaveTextContent('off');
    expect(connectChatRealtime).toHaveBeenCalledTimes(2);
});
it('does not connect without a rendered account', () => {
    render(view(null));
    expect(connectChatRealtime).not.toHaveBeenCalled();
    expect(screen.getByRole('status')).toHaveTextContent('off');
});
