import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatAttachment } from './chat-attachment';

const updates = vi.hoisted(() => ({
    refresh: null as (() => Promise<boolean>) | null,
    stop: vi.fn(),
}));
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('./chat-update-watch', () => ({
    watchChatUpdates: (refresh: () => Promise<boolean>) => {
        updates.refresh = refresh;
        void refresh();
        return updates.stop;
    },
}));
const file = {
    id: 9,
    name: 'Voice message.webm',
    media_kind: 'voice_note' as const,
    mime_type: 'audio/webm',
    size_bytes: 4,
    duration_ms: 1000,
    url: '/chat/api/attachments/9',
};
const metadata = { ...file, message_id: 301, conversation_id: 71 };
const show = (actorId = 1) => (
    <ChatAttachment
        file={file}
        actorId={actorId}
        conversationId={71}
        messageId={301}
    />
);
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(chatRequest).mockResolvedValue(metadata);
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            headers: new Headers({ 'content-type': 'audio/webm' }),
            arrayBuffer: async () => new ArrayBuffer(4),
        }),
    );
    URL.createObjectURL = vi.fn(() => 'blob:private-voice');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue();
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
    vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});
it('loads private audio only after Play, keeps it inline and offers speed and seeking', async () => {
    const view = render(show());
    expect(chatRequest).not.toHaveBeenCalled();
    expect(fetch).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    );
    await screen.findByRole('combobox', { name: 'Playback speed' });
    expect(
        new Headers(vi.mocked(chatRequest).mock.calls[0][1]?.headers).get(
            'X-Chat-Actor',
        ),
    ).toBe('1');
    expect(
        new Headers(vi.mocked(fetch).mock.calls[0][1]?.headers).get(
            'X-Chat-Actor',
        ),
    ).toBe('1');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    const audio = view.container.querySelector('audio')!;
    expect(audio).toHaveAttribute('src', 'blob:private-voice');
    expect(HTMLMediaElement.prototype.play).toHaveBeenCalledTimes(1);
    fireEvent.change(screen.getByRole('combobox', { name: 'Playback speed' }), {
        target: { value: '1.5' },
    });
    expect(audio.playbackRate).toBe(1.5);
    fireEvent.change(screen.getByRole('slider'), { target: { value: '0.5' } });
    expect(audio.currentTime).toBe(0.5);
    expect(
        screen.getByRole('link', { name: 'Download voice message' }),
    ).toHaveAttribute('href', '/chat/api/attachments/9?_chat_actor=1');
    view.unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:private-voice');
    expect(HTMLMediaElement.prototype.pause).toHaveBeenCalled();
});
it.each([403, 419])(
    'stops playback and removes decoded bytes when access ends (%s)',
    async (status) => {
        const view = render(show());
        fireEvent.click(
            screen.getByRole('button', { name: 'Play Voice message.webm' }),
        );
        await screen.findByRole('combobox');
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Access ended', status),
        );
        await act(async () => {
            await updates.refresh?.();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'no longer available',
        );
        expect(view.container.querySelector('audio')).toBeNull();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:private-voice');
    },
);
it('does not fetch bytes for metadata from another conversation', async () => {
    vi.mocked(chatRequest).mockResolvedValue({
        ...metadata,
        conversation_id: 72,
    });
    render(show());
    fireEvent.click(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    );
    await screen.findByRole('alert');
    expect(fetch).not.toHaveBeenCalled();
});
it('ignores an old account lookup after switching accounts', async () => {
    let finish!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const view = render(show());
    fireEvent.click(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    );
    await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(1));
    view.rerender(show(2));
    await act(async () => {
        finish(metadata);
    });
    expect(fetch).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    ).toBeEnabled();
});
it('retains an explicit play control when the browser blocks automatic playback after loading', async () => {
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockRejectedValue(
        new DOMException('Blocked', 'NotAllowedError'),
    );
    render(show());
    fireEvent.click(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    );
    await screen.findByRole('combobox');
    expect(
        screen.getByRole('button', { name: 'Play Voice message.webm' }),
    ).toBeEnabled();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
