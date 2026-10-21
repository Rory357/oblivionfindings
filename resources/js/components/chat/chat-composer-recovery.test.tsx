import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatError,
    chatDraftKey,
    chatRequest,
    readChatThreadMemory,
    saveChatDraft,
    saveChatThreadMemory,
    setChatDraftActor,
    type ChatRequestOptions,
} from './api';
import { CallConversationContext } from './call-conversation-context';
import { createCallTestLocks } from './call-recovery-test-locks';
import type {
    PrivateChatDraft,
    PrivateDraftSnapshot,
    PrivateSendAttempt,
    PrivateSendBody,
} from './chat-private-draft';
import { ChatThread } from './chat-thread';
import type { ChatHistory, ChatPolicy } from './types';
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));
const policy: ChatPolicy = {
    draft_recovery_enabled: true,
    enabled: true,
    family_enabled: true,
    files_enabled: true,
    max_file_mb: 20,
    max_files: 5,
    response_expectation: '',
};
const history: ChatHistory = {
    conversation: {
        id: 71,
        title: 'Rohan',
        type: 'direct',
        client: null,
        participants: [
            { id: 1, name: 'Alex' },
            { id: 2, name: 'Rohan' },
        ],
        mentionable_ids: [2],
        latest: null,
        unread: 1,
        muted: false,
        favourite: false,
        can_send: true,
        care_team_cover: false,
        queue_status: 'open',
        assigned_to: null,
        version: 1,
        archived: false,
    },
    messages: [
        {
            id: 301,
            sender_id: 2,
            sender_name: 'Rohan',
            mine: false,
            content: 'Hello Alex',
            deleted: false,
            pinned: false,
            created_at: '2026-10-16T01:00:00Z',
            edited_at: null,
            reply_to_id: null,
            mutation_id: null,
            attachments: [],
            legacy_attachments: [],
            reactions: [],
        },
    ],
    has_more: false,
};

const payload = (content: string): PrivateChatDraft => ({
    content,
    reply_to_id: null,
    mentions: [],
    uploads: [],
});
let saved: PrivateDraftSnapshot;
let attempt: PrivateSendAttempt | null;
let writes: PrivateSendBody[];
let loseReply: boolean;
let loseSave: boolean;
async function server(
    url: string,
    options?: ChatRequestOptions,
): Promise<unknown> {
    if (url.endsWith('/draft')) {
        if (options?.method === 'PUT') {
            if (loseSave)
                throw new ChatError('Draft confirmation unavailable', 503);
            const value = JSON.parse(options.body as string);
            saved = {
                ...saved,
                version: saved.version + 1,
                draft: value.draft,
                state: value.draft ? 'saved' : 'empty',
                applied_version: saved.version + 1,
            };
        }
        return {
            ...saved,
            pending_send: attempt?.state === 'pending' ? attempt : null,
        };
    }
    if (url.endsWith('/draft/send-attempts') && options?.method === 'POST') {
        const reservation = JSON.parse(options.body as string);
        attempt = {
            actor_id: 1,
            conversation_id: 71,
            mutation_id: reservation.mutation_id,
            state: 'pending',
            can_retry: true,
            message_id: null,
            body: {
                recoverable_send: true,
                ...reservation,
                content: saved.draft!.content,
                reply_to_id: saved.draft!.reply_to_id,
                mentions: saved.draft!.mentions,
                upload_ids: saved.draft!.uploads.map((item) => item.id),
                captions: saved.draft!.uploads.map((item) => item.caption),
            },
        };
        return attempt;
    }
    if (url.includes('/draft/send-attempts/')) {
        if (url.endsWith('/cancel'))
            attempt = {
                ...attempt!,
                state: 'cancelled',
                body: null,
                can_retry: false,
            };
        return attempt;
    }
    if (url.endsWith('/messages') && options?.method === 'POST') {
        writes.push(JSON.parse(options.body as string));
        if (loseReply)
            throw new ChatError('Message confirmation unavailable', 503);
        attempt = {
            ...attempt!,
            state: 'confirmed',
            message_id: 302,
            body: null,
            can_retry: false,
        };
        saved = {
            ...saved,
            version: saved.version + 1,
            draft: null,
            state: 'empty',
            pending_send: null,
        };
        return {};
    }
    if (url.includes('/uploads/'))
        return {
            id: url.split('/').at(-1),
            conversation_id: 71,
            state: options?.method === 'DELETE' ? 'discarded' : 'missing',
        };
    return history;
}
const renderThread = () =>
    render(<ChatThread actorId={1} conversationId={71} policy={policy} />);
async function editor() {
    const input = await screen.findByRole('textbox', { name: 'Message' });
    await waitFor(() => expect(input).toBeEnabled());
    return input;
}
describe('protected recovery in the shared composer', () => {
    beforeEach(() => {
        setChatDraftActor(null);
        localStorage.clear();
        vi.clearAllMocks();
        Object.defineProperty(navigator, 'locks', {
            configurable: true,
            value: createCallTestLocks(),
        });
        HTMLElement.prototype.scrollTo = vi.fn();
        HTMLElement.prototype.scrollIntoView = vi.fn();
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);
        saved = {
            actor_id: 1,
            conversation_id: 71,
            client_id: null,
            boundary: 'a'.repeat(64),
            version: 0,
            state: 'empty',
            draft: null,
            pending_send: null,
            updated_at: null,
        };
        attempt = null;
        writes = [];
        loseReply = false;
        loseSave = false;
        vi.mocked(chatRequest).mockImplementation(
            async (url, options) => (await server(url, options)) as never,
        );
    });
    it('requires Resume before showing a server draft and privately saves later typing', async () => {
        saved = {
            ...saved,
            version: 3,
            state: 'saved',
            draft: payload('Saved privately'),
        };
        renderThread();
        const resume = await screen.findByRole('button', {
            name: 'Resume draft',
        });
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            '',
        );
        expect(writes).toEqual([]);
        fireEvent.click(resume);
        const input = await editor();
        expect(input).toHaveValue('Saved privately');
        fireEvent.change(input, { target: { value: 'Updated after review' } });
        await waitFor(
            () => expect(saved.draft?.content).toBe('Updated after review'),
            { timeout: 2000 },
        );
        expect(writes).toEqual([]);
        expect(localStorage.length).toBe(0);
    });
    it('flushes the reviewed draft, reserves once, sends once and clears only the confirmed draft', async () => {
        renderThread();
        fireEvent.change(await editor(), {
            target: { value: 'Send this exact text' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(writes).toHaveLength(1));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue(''),
        );
        expect(writes[0]).toMatchObject({
            content: 'Send this exact text',
            recoverable_send: true,
            draft_version: 1,
        });
        expect(localStorage.length).toBe(0);
    });
    it('keeps an unresolved send across call handoff and stops it without another message write', async () => {
        loseReply = true;
        const tree = (call: boolean) => (
            <CallConversationContext.Provider
                value={
                    call
                        ? {
                              actorId: 1,
                              conversationId: 71,
                              returnToCall: vi.fn(),
                          }
                        : null
                }
            >
                <ChatThread actorId={1} conversationId={71} policy={policy} />
                {call && (
                    <ChatThread
                        actorId={1}
                        conversationId={71}
                        policy={policy}
                        inCall
                    />
                )}
            </CallConversationContext.Provider>
        );
        const view = render(tree(false));
        fireEvent.change(await editor(), {
            target: { value: 'Keep original identity' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByText('Message confirmation unavailable');
        const reference = attempt!.mutation_id;
        view.rerender(tree(true));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Check previous send' }),
        );
        await screen.findByRole('button', { name: 'Send this message' });
        expect(writes).toHaveLength(1);
        expect(attempt!.mutation_id).toBe(reference);
        fireEvent.click(screen.getByRole('button', { name: 'Stop this send' }));
        await waitFor(() => expect(attempt!.state).toBe('cancelled'));
        expect(await editor()).toHaveValue('Keep original identity');
        expect(writes).toHaveLength(1);
        expect(localStorage.length).toBe(0);
    });
    it('does not send when the draft save is uncertain and retries the same save explicitly', async () => {
        loseSave = true;
        renderThread();
        fireEvent.change(await editor(), {
            target: { value: 'Unconfirmed draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        const retry = await screen.findByRole('button', {
            name: 'Retry draft save',
        });
        expect(writes).toEqual([]);
        const first = vi
            .mocked(chatRequest)
            .mock.calls.find(
                ([url, options]) =>
                    url.endsWith('/draft') && options?.method === 'PUT',
            )![1]!.body;
        loseSave = false;
        fireEvent.click(retry);
        await editor();
        const saves = vi
            .mocked(chatRequest)
            .mock.calls.filter(
                ([url, options]) =>
                    url.endsWith('/draft') && options?.method === 'PUT',
            );
        expect(saves.at(-1)![1]!.body).toBe(first);
        expect(writes).toEqual([]);
    });
    it('keeps a missing saved attachment visible until the user removes it', async () => {
        saved = {
            ...saved,
            version: 1,
            state: 'saved',
            draft: {
                ...payload('Keep the text'),
                uploads: [
                    {
                        id: '9071f3af-d342-4d6e-bd78-ae236a1ae19e',
                        caption: 'Retain this caption',
                    },
                ],
            },
        };
        renderThread();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        await editor();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review attachments (1)' }),
        );
        await screen.findByText(
            'This attachment is no longer available. Remove it and choose the file again.',
        );
        expect(writes).toEqual([]);
        expect(
            screen.getByRole('button', { name: 'Upload and check' }),
        ).toBeDisabled();
    });
    it('discards a saved draft only after its tombstone is confirmed', async () => {
        saved = {
            ...saved,
            version: 3,
            state: 'saved',
            draft: payload('Discard explicitly'),
        };
        renderThread();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard draft' }),
        );
        expect(await editor()).toHaveValue('');
        expect(saved.draft).toBeNull();
        expect(writes).toEqual([]);
    });
    it('checks a pending reference after page-owner replacement without automatically resending', async () => {
        loseReply = true;
        const first = renderThread();
        fireEvent.change(await editor(), {
            target: { value: 'Recover without resend' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByText('Message confirmation unavailable');
        const reference = attempt!.mutation_id;
        first.unmount();
        setChatDraftActor(null);
        await act(async () => {
            await Promise.resolve();
        });
        renderThread();
        await screen.findByRole('button', { name: 'Send this message' });
        expect(writes).toHaveLength(1);
        expect(attempt!.mutation_id).toBe(reference);
        fireEvent.click(screen.getByRole('button', { name: 'Stop this send' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        expect(await editor()).toHaveValue('Recover without resend');
        expect(writes).toHaveLength(1);
    });
    it('removes the visible cached draft when its audience boundary has changed', async () => {
        saveChatDraft(chatDraftKey(1, null, 71), 'Earlier audience content');
        saved = { ...saved, version: 2, state: 'scope_changed', draft: null };
        renderThread();
        await screen.findByRole('button', { name: 'Discard earlier draft' });
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue(''),
        );
        expect(screen.getByRole('textbox', { name: 'Message' })).toBeDisabled();
        expect(writes).toEqual([]);
    });
    it('allows Stop while the message request is waiting and ignores its late reply', async () => {
        let resolveWrite!: (value: unknown) => void;
        let request: ChatRequestOptions | undefined;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.endsWith('/messages') && options?.method === 'POST') {
                request = options;
                writes.push(JSON.parse(options.body as string));
                return (await new Promise<unknown>((resolve) => {
                    resolveWrite = resolve;
                })) as never;
            }
            return (await server(url, options)) as never;
        });
        renderThread();
        fireEvent.change(await editor(), {
            target: { value: 'Stop while waiting' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(request).toBeDefined());
        const stop = screen.getByRole('button', { name: 'Stop this send' });
        expect(stop).toBeEnabled();
        fireEvent.click(stop);
        await waitFor(() => expect(attempt?.state).toBe('cancelled'));
        expect(request?.signal?.aborted).toBe(true);
        await act(async () => {
            resolveWrite({});
        });
        expect(await editor()).toHaveValue('Stop while waiting');
        expect(writes).toHaveLength(1);
        expect(
            screen.queryByRole('button', { name: 'Send this message' }),
        ).not.toBeInTheDocument();
    });
    it('stops a waiting preparation without dispatching the message when its reply arrives late', async () => {
        let resolvePrepare!: (value: unknown) => void;
        let request: ChatRequestOptions | undefined;
        let prepared: unknown;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            const result = await server(url, options);
            if (
                url.endsWith('/draft/send-attempts') &&
                options?.method === 'POST'
            ) {
                request = options;
                prepared = result;
                return (await new Promise<unknown>((resolve) => {
                    resolvePrepare = resolve;
                })) as never;
            }
            return result as never;
        });
        renderThread();
        fireEvent.change(await editor(), {
            target: { value: 'Do not dispatch this' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(request).toBeDefined());
        fireEvent.click(screen.getByRole('button', { name: 'Stop this send' }));
        await waitFor(() => expect(attempt?.state).toBe('cancelled'));
        expect(request?.signal?.aborted).toBe(true);
        await act(async () => {
            resolvePrepare(prepared);
        });
        expect(await editor()).toHaveValue('Do not dispatch this');
        expect(writes).toEqual([]);
    });
    it('shows both conflicting versions and preserves local file bytes when keeping this tab', async () => {
        const key = chatDraftKey(1, null, 71);
        const file = new File(['local bytes'], 'local-visit.txt', {
            type: 'text/plain',
        });
        saveChatDraft(key, 'This tab version');
        saveChatThreadMemory(key, {
            files: [file],
            fileCaptions: ['Local caption'],
        });
        saved = {
            ...saved,
            version: 2,
            state: 'saved',
            draft: payload('Another device version'),
        };
        renderThread();
        fireEvent.click(await screen.findByText('Review drafts'));
        expect(
            screen.getByRole('region', { name: 'Saved elsewhere' }),
        ).toHaveTextContent('Another device version');
        expect(
            screen.getByRole('region', { name: 'This tab' }),
        ).toHaveTextContent('This tab version');
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep this tab’s draft' }),
        );
        expect(await editor()).toHaveValue('This tab version');
        expect(readChatThreadMemory(key).files[0]).toBe(file);
        expect(readChatThreadMemory(key).fileCaptions).toEqual([
            'Local caption',
        ]);
        expect(writes).toEqual([]);
    });
});
