import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatAttachment } from './chat-attachment';

const updates = vi.hoisted(() => ({
    refresh: undefined as undefined | (() => Promise<boolean>),
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
    name: 'Visit photo.png',
    mime_type: 'image/png',
    size_bytes: 400,
    url: '/chat/api/attachments/9',
};
const metadata = {
    ...file,
    message_id: 301,
    conversation_id: 71,
    sender_name: 'Jo Example',
    created_at: '2026-10-16T01:00:00Z',
    audience_label: 'Family participants and authorised care-team cover',
    preview_url: '/chat/api/attachments/9/preview',
    download_url: file.url,
};

describe('shared Chat attachment preview', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockResolvedValue(metadata);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                status: 200,
                headers: new Headers({ 'content-type': 'image/png' }),
                arrayBuffer: async () => new ArrayBuffer(16),
            })),
        );
        URL.createObjectURL = vi.fn(() => 'blob:chat-preview');
        URL.revokeObjectURL = vi.fn();
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });
    const attachment = (actorId = 1) => (
        <ChatAttachment
            actorId={actorId}
            file={file}
            messageId={301}
            conversationId={71}
        />
    );

    it('checks current access before loading bytes and restores the shared file trigger on close', async () => {
        render(attachment());
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        await screen.findByRole('img', { name: 'Visit photo.png' });
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/attachments/9/metadata',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(
            new Headers(vi.mocked(chatRequest).mock.calls[0][1]?.headers).get(
                'X-Chat-Actor',
            ),
        ).toBe('1');
        expect(fetch).toHaveBeenCalledWith(
            `${location.origin}/chat/api/attachments/9/preview?_chat_actor=1`,
            expect.objectContaining({
                credentials: 'same-origin',
                cache: 'no-store',
            }),
        );
        expect(
            screen.getByText(
                /Family participants and authorised care-team cover/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Download Visit photo.png' }),
        ).toHaveAttribute(
            'href',
            `${location.origin}${file.url}?_chat_actor=1`,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close viewer' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:chat-preview');
        expect(updates.stop).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        ).toHaveFocus();
    });

    it.each([403, 419])(
        'clears decoded media and downloads after access ends (%s) and permits an explicit recheck',
        async (status) => {
            render(attachment());
            fireEvent.click(
                screen.getByRole('button', { name: 'Open Visit photo.png' }),
            );
            await screen.findByRole('img');
            vi.mocked(chatRequest).mockRejectedValueOnce(
                new ChatError('Forbidden', status),
            );
            await act(async () => {
                await updates.refresh?.();
            });
            await screen.findByRole('heading', {
                name: 'Attachment unavailable',
            });
            expect(screen.queryByRole('img')).not.toBeInTheDocument();
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            expect(URL.revokeObjectURL).toHaveBeenCalledWith(
                'blob:chat-preview',
            );
            expect(screen.queryByText(/Shared by Jo/)).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Check access again' }),
            );
            await screen.findByRole('img', { name: 'Visit photo.png' });
        },
    );

    it('does not load a mismatched message and ignores a previous account response', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest).mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const view = render(attachment());
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            message_id: 302,
        });
        view.rerender(attachment(2));
        await screen.findByRole('heading', { name: 'Attachment unavailable' });
        await act(async () => {
            finish(metadata);
        });
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('clears media offline and ignores a check that finishes after the connection goes away', async () => {
        render(attachment());
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        await screen.findByRole('img');
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest).mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        let pending: Promise<boolean> | undefined;
        act(() => {
            pending = updates.refresh?.();
        });
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        await act(async () => {
            finish(metadata);
            await pending;
        });
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Check your connection and retry/),
        ).toBeVisible();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:chat-preview');
    });

    it('keeps unsupported documents as authorised downloads and does not claim a preview', async () => {
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...metadata,
            name: 'Visit.docx',
            mime_type:
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            preview_url: null,
        });
        render(attachment());
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        await screen.findByText(/This file format cannot be previewed here/);
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.getByRole('link', { name: 'Download Visit.docx' }),
        ).toBeVisible();
    });

    it('shows publication only with current staff capability and replaces the viewer with one compact review', async () => {
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.endsWith('/metadata'))
                return { ...metadata, can_publish: true };
            if (url.includes('/publication?q='))
                return {
                    clients: [{ id: 8, name: 'Jamie Example' }],
                    locked_client_id: 8,
                    has_more: false,
                    destinations: ['documents', 'photos'],
                };
            return {
                client: { id: 8, name: 'Jamie Example' },
                file_name: file.name,
                destination: 'documents',
                family_visible: false,
                family_readers: [],
                record_audience: 'Authorised client record readers',
                audience_token: 'review',
            };
        });
        render(attachment());
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        await screen.findByRole('img', { name: 'Visit photo.png' });
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Add to client documents or photos',
            }),
        );
        await screen.findByRole('checkbox');
        expect(screen.getAllByRole('dialog')).toHaveLength(1);
        expect(screen.getByRole('dialog')).toHaveAccessibleName(
            'Review publication',
        );
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(URL.revokeObjectURL).toHaveBeenCalled();
    });

    it('does not expose publication to a reader without the capability', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            ...metadata,
            can_publish: false,
        });
        render(attachment());
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit photo.png' }),
        );
        await screen.findByRole('img', { name: 'Visit photo.png' });
        expect(
            screen.queryByRole('button', { name: /Add to client/ }),
        ).not.toBeInTheDocument();
    });
});
