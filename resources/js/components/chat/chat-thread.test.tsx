import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ActiveCallCard } from './active-call-card';
import {
    ChatError,
    chatDraftKey,
    chatRequest,
    readChatDraft,
    readChatThreadMemory,
    saveChatDraft,
    saveChatThreadMemory,
    savePendingChatSend,
    setChatDraftActor,
} from './api';
import { CallConversationContext } from './call-conversation-context';
import {
    ChatPreferencesProvider,
    defaultChatPreferences,
} from './chat-personal-preferences';
import { ChatThread } from './chat-thread';
import type { ChatHistory, ChatPolicy } from './types';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));
const policy: ChatPolicy = {
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

function sendOutcome(url: string, confirmed = false) {
    return {
        state: confirmed ? 'confirmed' : 'unconfirmed',
        conversation_id: 71,
        mutation_id: url.split('/').at(-1),
        message_id: confirmed ? 302 : null,
        can_retry: true,
    };
}

const stagedFiles = new Map<string, File>();
function stageOutcome(
    url: string,
    options?: import('./api').ChatRequestOptions,
) {
    if (!url.includes('/uploads/')) return null;
    const id = url.split('/').at(-1)!;
    if (options?.method === 'DELETE')
        return { id, conversation_id: 71, state: 'discarded' };
    if (options?.method === 'POST')
        stagedFiles.set(id, (options.body as FormData).get('file') as File);
    const file = stagedFiles.get(id);
    return {
        id,
        conversation_id: 71,
        state: file ? 'ready' : 'missing',
        size_bytes: file?.size,
    };
}
async function prepareAttachments() {
    fireEvent.click(screen.getByRole('button', { name: 'Upload and check' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Add to message' }),
    );
}

describe('shared Chat recovery and read behaviour', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        stagedFiles.clear();
        chatDraftKey(99, null, 71);
        chatDraftKey(1, null, 71);
        HTMLElement.prototype.scrollTo = vi.fn();
        HTMLElement.prototype.scrollIntoView = vi.fn();
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);
        vi.mocked(chatRequest).mockImplementation(
            async (url, options) =>
                (stageOutcome(url, options) ?? history) as never,
        );
    });
    it.each([false, true])(
        'uses saved reading and Enter preferences in the shared thread (family=%s)',
        async (family) => {
            vi.mocked(chatRequest).mockImplementation(
                async (url) =>
                    (url === '/chat/api/personal-preferences'
                        ? {
                              actor_id: 1,
                              version: 'a'.repeat(64),
                              values: {
                                  ...defaultChatPreferences,
                                  text_size: 'extra_large',
                                  spacing: 'compact',
                                  sending: 'enter_newline',
                              },
                          }
                        : history) as never,
            );
            render(
                <ChatPreferencesProvider actorId={1} enabled>
                    <ChatThread
                        actorId={1}
                        conversationId={71}
                        policy={policy}
                        family={family}
                    />
                </ChatPreferencesProvider>,
            );
            const message = await screen.findByText('Hello Alex');
            await waitFor(() =>
                expect(message.closest('p')).toHaveClass('text-xl'),
            );
            expect(message.closest('article')).toHaveClass('mb-2');
            const input = screen.getByRole('textbox', { name: 'Message' });
            expect(input).toHaveClass('text-xl');
            fireEvent.change(input, { target: { value: 'Keep this draft' } });
            expect(fireEvent.keyDown(input, { key: 'Enter' })).toBe(true);
            expect(
                screen.getByText(/Enter adds a line · Use Send to send/),
            ).toBeInTheDocument();
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([url, options]) =>
                            url.endsWith('/messages') &&
                            options?.method === 'POST',
                    ),
            ).toBe(false);
        },
    );

    it.each(['success', 'failure'])(
        'ignores a late preference %s after an account switch and binds new history to the new actor',
        async (outcome) => {
            let finish!: () => void;
            let fail!: (error: Error) => void;
            let pendingSignal: AbortSignal | null | undefined;
            const onChange = vi.fn();
            vi.mocked(chatRequest).mockImplementation(async (url, options) => {
                if (url.endsWith('/preferences')) {
                    expect(
                        new Headers(options?.headers).get('X-Chat-Actor'),
                    ).toBe('1');
                    pendingSignal = options?.signal;
                    return new Promise<void>((resolve, reject) => {
                        finish = resolve;
                        fail = reject;
                    });
                }
                return history as never;
            });
            const view = render(
                <ChatThread
                    conversationId={71}
                    actorId={1}
                    policy={policy}
                    onChange={onChange}
                />,
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Favourite conversation',
                }),
            );
            await waitFor(() => expect(pendingSignal).toBeDefined());
            setChatDraftActor(2);
            view.rerender(
                <ChatThread
                    conversationId={71}
                    actorId={2}
                    policy={policy}
                    onChange={onChange}
                />,
            );
            await screen.findByText('Hello Alex');
            expect(pendingSignal?.aborted).toBe(true);
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.some(
                        ([url, options]) =>
                            url.endsWith('/messages') &&
                            new Headers(options?.headers).get(
                                'X-Chat-Actor',
                            ) === '2',
                    ),
            ).toBe(true);
            onChange.mockClear();
            await act(async () => {
                if (outcome === 'success') finish();
                else fail(new Error('Old account failure'));
            });
            expect(onChange).not.toHaveBeenCalled();
            expect(toast.error).not.toHaveBeenCalled();
        },
    );

    it('transfers one composer and its draft to a call and back without sending', async () => {
        const viewFor = (inCall: boolean) => (
            <CallConversationContext.Provider
                value={
                    inCall
                        ? {
                              actorId: 1,
                              conversationId: 71,
                              returnToCall: vi.fn(),
                          }
                        : null
                }
            >
                <ChatThread conversationId={71} actorId={1} policy={policy} />
                {inCall && (
                    <ChatThread
                        conversationId={71}
                        actorId={1}
                        policy={policy}
                        inCall
                    />
                )}
            </CallConversationContext.Provider>
        );
        const view = render(viewFor(false));
        fireEvent.change(
            await screen.findByRole('textbox', { name: 'Message' }),
            { target: { value: 'Keep this visit draft' } },
        );
        view.rerender(viewFor(true));
        expect(
            screen.getByText('Conversation open in your call'),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('Keep this visit draft'),
        );
        expect(
            screen.getAllByRole('textbox', { name: 'Message' }),
        ).toHaveLength(1);
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Updated during the call' },
        });
        view.rerender(viewFor(false));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('Updated during the call'),
        );
        expect(
            screen.queryByText('Conversation open in your call'),
        ).not.toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([, options]) => options?.method === 'POST'),
        ).toBe(false);
    });

    it('retains attachment captions and an uncertain send reference when moving into a call', async () => {
        const key = chatDraftKey(1, null, 71);
        const file = new File(['visit'], 'visit.txt', { type: 'text/plain' });
        saveChatDraft(key, 'Confirm the visit');
        saveChatThreadMemory(key, {
            files: [file],
            fileCaptions: ['Visit arrangements'],
        });
        const body = new FormData();
        const reference = crypto.randomUUID();
        body.set('mutation_id', reference);
        body.set('content', 'Confirm the visit');
        body.append('files[]', file);
        savePendingChatSend(key, body);
        const requests: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                requests.push(options.body as FormData);
                throw new ChatError('Confirmation unavailable', 503);
            }
            return (stageOutcome(url, options) ?? history) as never;
        });
        const tree = (inCall: boolean) => (
            <CallConversationContext.Provider
                value={
                    inCall
                        ? {
                              actorId: 1,
                              conversationId: 71,
                              returnToCall: vi.fn(),
                          }
                        : null
                }
            >
                <ChatThread actorId={1} conversationId={71} policy={policy} />
                {inCall && (
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
        await screen.findByRole('button', { name: 'Retry sending message' });
        view.rerender(tree(true));
        const retry = await screen.findByRole('button', {
            name: 'Retry sending message',
        });
        expect(
            screen.getAllByRole('textbox', { name: 'Message' }),
        ).toHaveLength(1);
        expect(readChatThreadMemory(key).files).toEqual([file]);
        expect(readChatThreadMemory(key).fileCaptions).toEqual([
            'Visit arrangements',
        ]);
        expect(requests).toHaveLength(0);
        fireEvent.click(retry);
        await screen.findByText('Confirmation unavailable');
        expect(requests).toEqual([body]);
        expect(requests[0].get('mutation_id')).toBe(reference);
        view.rerender(tree(false));
        await screen.findByRole('button', { name: 'Retry sending message' });
        expect(requests).toHaveLength(1);
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Confirm the visit',
        );
    });

    it('closes compact details with Escape without dismissing the call or losing the draft', async () => {
        const closeCall = vi.fn();
        vi.mocked(chatRequest).mockResolvedValue({
            ...history,
            conversation: { ...history.conversation, policy },
        });
        render(
            <ActiveCallCard
                title="Active voice call"
                status="Connected"
                actorId={1}
                conversationId={71}
                presentation={{
                    kind: 'staff',
                    caller_name: 'Rohan',
                    caller_detail: null,
                    audience: 'Just you and Rohan',
                    viewer_is_family: false,
                }}
                expanded
                conversationOpen
                muted={false}
                microphoneDisabled={false}
                working={false}
                audioBlocked={false}
                isHost={false}
                onExpandedChange={closeCall}
                onConversationChange={vi.fn()}
                onMute={vi.fn()}
                onLeave={vi.fn()}
                onEndEveryone={vi.fn()}
                onEnableAudio={vi.fn()}
            />,
        );
        fireEvent.change(
            await screen.findByRole('textbox', { name: 'Message' }),
            { target: { value: 'Keep this call draft' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Rohan.*View people/ }),
        );
        const closeDetails = screen.getByRole('button', {
            name: 'Close details',
        });
        fireEvent.keyDown(closeDetails, { key: 'Escape', code: 'Escape' });
        expect(
            screen.queryByRole('complementary', {
                name: 'Conversation details',
            }),
        ).not.toBeInTheDocument();
        expect(closeCall).not.toHaveBeenCalled();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep this call draft',
        );
    });

    it('ignores a late send acknowledgement after moving into a call and checks the same reference before retry', async () => {
        let acknowledge!: (value: unknown) => void;
        let pendingSignal: AbortSignal | undefined;
        let sentBody: FormData | null = null;
        let writes = 0;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url, true) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                writes++;
                pendingSignal = options.signal ?? undefined;
                sentBody = options.body as FormData;
                return (await new Promise<unknown>((resolve) => {
                    acknowledge = resolve;
                })) as never;
            }
            return history as never;
        });
        const tree = (inCall: boolean) => (
            <CallConversationContext.Provider
                value={
                    inCall
                        ? {
                              actorId: 1,
                              conversationId: 71,
                              returnToCall: vi.fn(),
                          }
                        : null
                }
            >
                <ChatThread actorId={1} conversationId={71} policy={policy} />
                {inCall && (
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
        fireEvent.change(
            await screen.findByRole('textbox', { name: 'Message' }),
            { target: { value: 'Already being sent' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(writes).toBe(1));
        view.rerender(tree(true));
        expect(pendingSignal?.aborted).toBe(true);
        await screen.findByRole('button', { name: 'Retry sending message' });
        await act(async () => {
            acknowledge({ message: history.messages[0] });
        });
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Already being sent',
        );
        expect(writes).toBe(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue(''),
        );
        expect(writes).toBe(1);
        const reference = (sentBody as unknown as FormData).get('mutation_id');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([url]) =>
                    url.endsWith(`/send-outcomes/${reference}`),
                ),
        ).toBe(true);
    });

    it('recovers an in-flight upload in the call without posting the file a second time', async () => {
        let acknowledge!: (value: unknown) => void;
        let pendingSignal: AbortSignal | undefined;
        let uploadId = '';
        let uploaded = false;
        let writes = 0;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/uploads/')) {
                uploadId = url.split('/').at(-1)!;
                if (options?.method === 'POST') {
                    writes++;
                    pendingSignal = options.signal ?? undefined;
                    uploaded = true;
                    return (await new Promise<unknown>((resolve) => {
                        acknowledge = resolve;
                    })) as never;
                }
                return {
                    id: uploadId,
                    conversation_id: 71,
                    state: uploaded ? 'ready' : 'missing',
                    size_bytes: 5,
                } as never;
            }
            return history as never;
        });
        const tree = (inCall: boolean) => (
            <CallConversationContext.Provider
                value={
                    inCall
                        ? {
                              actorId: 1,
                              conversationId: 71,
                              returnToCall: vi.fn(),
                          }
                        : null
                }
            >
                <ChatThread actorId={1} conversationId={71} policy={policy} />
                {inCall && (
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
        await screen.findByRole('textbox', { name: 'Message' });
        const file = new File(['visit'], 'visit.txt', { type: 'text/plain' });
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files: [file] },
        });
        fireEvent.change(screen.getByLabelText('Caption for visit.txt'), {
            target: { value: 'Saturday arrangements' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Upload and check' }),
        );
        await waitFor(() => expect(writes).toBe(1));
        const originalId = uploadId;
        view.rerender(tree(true));
        expect(pendingSignal?.aborted).toBe(true);
        await screen.findByRole('textbox', { name: 'Message' });
        await act(async () => {
            acknowledge({
                id: originalId,
                conversation_id: 71,
                state: 'ready',
                size_bytes: 5,
            });
        });
        expect(writes).toBe(1);
        expect(
            readChatThreadMemory(chatDraftKey(1, null, 71)).uploads?.[0].id,
        ).toBe(originalId);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review attachments (1)' }),
        );
        expect(screen.getByLabelText('Caption for visit.txt')).toHaveValue(
            'Saturday arrangements',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check visit.txt',
            }),
        );
        await screen.findByRole('button', { name: 'Add to message' });
        expect(writes).toBe(1);
        expect(uploadId).toBe(originalId);
    });

    it('does not take a different conversation or account away for a call', async () => {
        const view = render(
            <CallConversationContext.Provider
                value={{
                    actorId: 2,
                    conversationId: 71,
                    returnToCall: vi.fn(),
                }}
            >
                <ChatThread conversationId={71} actorId={1} policy={policy} />
            </CallConversationContext.Provider>,
        );
        await screen.findByRole('textbox', { name: 'Message' });
        view.rerender(
            <CallConversationContext.Provider
                value={{
                    actorId: 1,
                    conversationId: 72,
                    returnToCall: vi.fn(),
                }}
            >
                <ChatThread conversationId={71} actorId={1} policy={policy} />
            </CallConversationContext.Provider>,
        );
        expect(
            screen.getByRole('textbox', { name: 'Message' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Conversation open in your call'),
        ).not.toBeInTheDocument();
    });

    it('keeps one voice recorder mounted across thread updates and preserves the text draft on discard', async () => {
        const voicePolicy = {
            ...policy,
            voice_notes_enabled: true,
            voice_notes_available: true,
        };
        const view = render(
            <ChatThread conversationId={71} actorId={1} policy={voicePolicy} />,
        );
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Keep my written update' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Record a voice message' }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Record a voice message',
        });
        view.rerender(
            <ChatThread
                conversationId={71}
                actorId={1}
                policy={{ ...voicePolicy }}
            />,
        );
        expect(screen.getAllByRole('dialog', { hidden: true })).toHaveLength(1);
        expect(
            screen.getByRole('dialog', { name: 'Record a voice message' }),
        ).toBe(dialog);
        fireEvent.click(screen.getByRole('button', { name: 'Discard' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep my written update',
        );
    });

    it('keeps new selections and edited captions when an earlier file removal finishes later', async () => {
        let confirmRemoval!: (value: unknown) => void;
        let removedId = '';
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/uploads/') && options?.method === 'DELETE') {
                removedId = url.split('/').at(-1)!;
                return new Promise((resolve) => {
                    confirmRemoval = resolve;
                });
            }
            return (stageOutcome(url, options) ?? history) as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        const first = new File(['one'], 'first.txt', { type: 'text/plain' });
        const second = new File(['two'], 'second.txt', { type: 'text/plain' });
        const third = new File(['three'], 'third.txt', { type: 'text/plain' });
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files: [first, second] },
        });
        await prepareAttachments();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review attachments (2)' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove selected first.txt' }),
        );
        await waitFor(() => expect(removedId).not.toBe(''));
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files: [third] },
        });
        fireEvent.change(screen.getByLabelText('Caption for second.txt'), {
            target: { value: 'Keep edited caption' },
        });
        fireEvent.change(screen.getByLabelText('Caption for third.txt'), {
            target: { value: 'New selection caption' },
        });
        await act(async () => {
            confirmRemoval({
                id: removedId,
                conversation_id: 71,
                state: 'discarded',
            });
        });
        expect(
            screen.queryByLabelText('Caption for first.txt'),
        ).not.toBeInTheDocument();
        expect(screen.getByLabelText('Caption for second.txt')).toHaveValue(
            'Keep edited caption',
        );
        expect(screen.getByLabelText('Caption for third.txt')).toHaveValue(
            'New selection caption',
        );
        expect(
            readChatThreadMemory(chatDraftKey(1, null, 71)).fileCaptions,
        ).toEqual(['Keep edited caption', 'New selection caption']);
    });

    it('keeps captions paired and confirms a previous upload without retransmitting files after read-only remount', async () => {
        const sends: FormData[] = [];
        let readonly = false;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            const stage = stageOutcome(url, options);
            if (stage) return stage as never;
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url, true) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1) throw new Error('Lost response');
                return { message: history.messages[0] } as never;
            }
            return {
                ...history,
                conversation: { ...history.conversation, can_send: !readonly },
            } as never;
        });
        const first = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        await screen.findByText('Hello Alex');
        const files = [
            new File(['first'], 'first.txt', { type: 'text/plain' }),
            new File(['second'], 'second.txt', { type: 'text/plain' }),
        ];
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files },
        });
        fireEvent.change(screen.getByLabelText('Caption for first.txt'), {
            target: { value: 'First caption' },
        });
        fireEvent.change(screen.getByLabelText('Caption for second.txt'), {
            target: { value: 'Second caption' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove selected first.txt' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByLabelText('Caption for first.txt'),
            ).not.toBeInTheDocument(),
        );
        expect(screen.getByLabelText('Caption for second.txt')).toHaveValue(
            'Second caption',
        );
        await prepareAttachments();
        expect(sends).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByText('Lost response');
        expect(
            screen.getByRole('group', { name: 'Pending outgoing message' }),
        ).toHaveTextContent('Second caption');
        expect(sends[0].getAll('files[]')).toEqual([]);
        expect(stagedFiles.get(String(sends[0].get('upload_ids[]')))).toBe(
            files[1],
        );
        expect(sends[0].getAll('captions[]')).toEqual(['Second caption']);
        first.unmount();
        readonly = true;
        render(
            <ChatThread
                conversationId={71}
                actorId={1}
                policy={{ ...policy, enabled: false }}
            />,
        );
        await screen.findByRole('button', { name: 'Check previous send' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review attachments (1)' }),
        );
        expect(screen.getByLabelText('Caption for second.txt')).toHaveValue(
            'Second caption',
        );
        expect(screen.getByLabelText('Caption for second.txt')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to message' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Review attachments (1)' }),
            ).toHaveFocus(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check previous send' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', {
                    name: 'Review attachments (1)',
                }),
            ).not.toBeInTheDocument(),
        );
        expect(sends).toHaveLength(1);
        expect(
            screen.queryByRole('group', { name: 'Pending outgoing message' }),
        ).not.toBeInTheDocument();
        expect(chatRequest).toHaveBeenCalledWith(
            `/chat/api/conversations/71/send-outcomes/${sends[0].get('mutation_id')}`,
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });

    it.each([
        'unavailable',
        'wrong conversation',
        'wrong reference',
        'invalid identity',
        'invalid retry policy',
        'read-only',
    ])(
        'keeps the pending send without retransmitting when outcome is %s',
        async (scenario) => {
            const body = new FormData();
            body.set('mutation_id', crypto.randomUUID());
            body.set('content', 'Keep the original message');
            const key = chatDraftKey(1, null, 71);
            saveChatDraft(key, 'Keep the original message');
            savePendingChatSend(key, body);
            vi.mocked(chatRequest).mockImplementation(async (url, options) => {
                if (options?.method === 'POST')
                    throw new Error('Unexpected resend');
                if (url.includes('/send-outcomes/')) {
                    if (scenario === 'unavailable')
                        throw new Error('Outcome check unavailable');
                    return {
                        ...sendOutcome(url, scenario !== 'read-only'),
                        ...(scenario === 'wrong conversation'
                            ? { conversation_id: 72 }
                            : {}),
                        ...(scenario === 'wrong reference'
                            ? { mutation_id: 'another-request' }
                            : {}),
                        ...(scenario === 'invalid identity'
                            ? { message_id: 1.5 }
                            : {}),
                        ...(scenario === 'invalid retry policy'
                            ? { can_retry: 'yes' }
                            : {}),
                        ...(scenario === 'read-only'
                            ? { can_retry: false }
                            : {}),
                    } as never;
                }
                return history as never;
            });
            render(
                <ChatThread conversationId={71} actorId={1} policy={policy} />,
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Retry sending message',
                }),
            );
            await screen.findByText(
                scenario === 'unavailable'
                    ? 'Outcome check unavailable'
                    : scenario === 'read-only'
                      ? 'No confirmed result yet. Your pending message is kept; check again when the conversation is available.'
                      : 'The send outcome could not be verified. Your pending message is kept.',
            );
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('Keep the original message');
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toBeDisabled();
            expect(
                vi
                    .mocked(chatRequest)
                    .mock.calls.filter(
                        ([, options]) => options?.method === 'POST',
                    ),
            ).toHaveLength(0);
        },
    );

    it('checks a read-only pending send without resending even if the server would allow a retry', async () => {
        const body = new FormData();
        body.set('mutation_id', crypto.randomUUID());
        body.set('content', 'Pending message');
        savePendingChatSend(chatDraftKey(1, null, 71), body);
        vi.mocked(chatRequest).mockImplementation(async (url) =>
            url.includes('/send-outcomes/')
                ? (sendOutcome(url) as never)
                : (history as never),
        );
        render(
            <ChatThread
                conversationId={71}
                actorId={1}
                policy={{ ...policy, enabled: false }}
            />,
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Check previous send' }),
        );
        await screen.findByText(
            'No confirmed result yet. Your pending message is kept; check again when the conversation is available.',
        );
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([, options]) => options?.method === 'POST'),
        ).toHaveLength(0);
        expect(
            screen.getByRole('button', { name: 'Check previous send' }),
        ).toBeEnabled();
    });

    it('shows checking separately from upload and ignores a late outcome after an account change', async () => {
        const body = new FormData();
        body.set('mutation_id', crypto.randomUUID());
        body.set('content', 'Old account message');
        body.append(
            'files[]',
            new File(['file'], 'visit.txt', { type: 'text/plain' }),
        );
        savePendingChatSend(chatDraftKey(1, null, 71), body);
        let finish!: (value: unknown) => void;
        let lookupUrl = '';
        let lookupSignal: AbortSignal | null | undefined;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/')) {
                lookupUrl = url;
                lookupSignal = options?.signal;
                return new Promise((resolve) => {
                    finish = resolve;
                });
            }
            return history as never;
        });
        const view = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry sending message',
            }),
        );
        await screen.findByText('Checking whether your message was sent…');
        expect(
            screen.getByRole('button', { name: 'Checking previous send' }),
        ).toBeDisabled();
        expect(screen.queryByText(/Uploading/)).not.toBeInTheDocument();
        setChatDraftActor(2);
        const nextKey = chatDraftKey(2, null, 71);
        saveChatDraft(nextKey, 'New account draft');
        view.rerender(
            <ChatThread conversationId={71} actorId={2} policy={policy} />,
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('New account draft'),
        );
        expect(lookupSignal?.aborted).toBe(true);
        await act(async () => finish(sendOutcome(lookupUrl, true)));
        expect(readChatDraft(nextKey)).toBe('New account draft');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([, options]) => options?.method === 'POST'),
        ).toHaveLength(0);
    });

    it('keeps the exact pending reference after a retry validation failure because an older request may still commit', async () => {
        const body = new FormData();
        body.set('mutation_id', crypto.randomUUID());
        body.set('content', 'Awaiting confirmation');
        savePendingChatSend(chatDraftKey(1, null, 71), body);
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url) as never;
            if (options?.method === 'POST') {
                sends.push(options.body as FormData);
                throw new ChatError('Limit changed', 422);
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry sending message',
            }),
        );
        await screen.findByText('Limit changed');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() => expect(sends).toHaveLength(2));
        expect(sends[0]).toBe(body);
        expect(sends[1]).toBe(body);
        expect(screen.getByRole('textbox', { name: 'Message' })).toBeDisabled();
    });

    it('does not duplicate the pending bubble when live history already contains the accepted reference', async () => {
        const body = new FormData();
        const reference = crypto.randomUUID();
        body.set('mutation_id', reference);
        body.set('content', 'Already accepted');
        savePendingChatSend(chatDraftKey(1, null, 71), body);
        vi.mocked(chatRequest).mockResolvedValue({
            ...history,
            messages: [
                {
                    ...history.messages[0],
                    mine: true,
                    sender_id: 1,
                    mutation_id: reference,
                    content: 'Already accepted',
                },
            ],
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Already accepted');
        expect(
            screen.queryByRole('group', { name: 'Pending outgoing message' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Retry sending message' }),
        ).toBeEnabled();
    });

    it('keeps a first send rejected by unavailable scanning editable so files can be removed and text sent', async () => {
        const key = chatDraftKey(1, null, 71);
        saveChatDraft(key, 'Keep this update');
        saveChatThreadMemory(key, {
            files: [new File(['visit'], 'visit.txt', { type: 'text/plain' })],
            fileCaptions: ['Visit file'],
        });
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            const stage = stageOutcome(url, options);
            if (stage) return stage as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1)
                    throw new ChatError(
                        'File safety checks are unavailable.',
                        503,
                        'chat_file_unavailable',
                    );
                return { message: history.messages[0] } as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', { name: 'Review attachments (1)' }),
        );
        await prepareAttachments();
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByText('File safety checks are unavailable.');
        expect(screen.getByRole('textbox', { name: 'Message' })).toBeEnabled();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep this update',
        );
        expect(
            screen.queryByRole('group', { name: 'Pending outgoing message' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove visit.txt' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Remove visit.txt' }),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() => expect(sends).toHaveLength(2));
        expect(sends[1].getAll('files[]')).toEqual([]);
        expect(sends[1].get('content')).toBe('Keep this update');
        expect(sends[1].get('mutation_id')).not.toBe(
            sends[0].get('mutation_id'),
        );
    });

    it('shows measured upload progress and ignores abandoned account completion', async () => {
        let finish!: (value: unknown) => void;
        let request: import('./api').ChatRequestOptions | undefined;
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/uploads/') && options?.method === 'POST') {
                request = options;
                return new Promise((resolve) => {
                    finish = resolve;
                });
            }
            const stage = stageOutcome(url, options);
            if (stage) return stage as never;
            return history as never;
        });
        const view = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: {
                files: [
                    new File(['file'], 'visit.txt', { type: 'text/plain' }),
                ],
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Upload and check' }),
        );
        await waitFor(() => expect(request).toBeDefined());
        act(() => request?.onUploadProgress?.({ loaded: 40, total: 100 }));
        expect(screen.getAllByText(/Uploading… 40%/).length).toBeGreaterThan(0);
        act(() => request?.onUploadProgress?.({ loaded: 100, total: 100 }));
        expect(screen.getByText(/KB · Checking file safety/)).toBeVisible();
        view.rerender(
            <ChatThread conversationId={71} actorId={2} policy={policy} />,
        );
        await screen.findByRole('textbox', { name: 'Message' });
        expect(request?.signal?.aborted).toBe(true);
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'New account draft' },
        });
        await act(async () => {
            request?.onUploadProgress?.({ loaded: 75, total: 100 });
            finish({ message: history.messages[0] });
        });
        expect(
            screen.queryByText(/Uploading attachments/),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'New account draft',
        );
    });

    it('restores an uncertain removal separately from the unsent composer and clears it only after acknowledgement', async () => {
        const memoryKey = chatDraftKey(1, null, 71);
        saveChatDraft(memoryKey, 'Keep this separate draft');
        const pendingBody = JSON.stringify({
            action: 'delete',
            mutation_id: 'removal-reference',
            version: 2,
        });
        saveChatThreadMemory(memoryKey, {
            removing: {
                messageId: 301,
                content: 'Reviewed wording',
                version: 2,
                pendingBody,
            },
        });
        vi.mocked(chatRequest).mockImplementation(async (path) =>
            path.endsWith('/action')
                ? (undefined as never)
                : (history as never),
        );
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByRole('dialog', { name: 'Remove your message?' });
        fireEvent.click(screen.getByRole('button', { name: 'Retry removal' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.find(([path]) => path.endsWith('/action'))?.[1]
                ?.body,
        ).toBe(pendingBody);
        expect(readChatThreadMemory(memoryKey).removing).toBeNull();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep this separate draft',
        );
    });

    it('opens a shared attachment without losing the active conversation draft or file trigger focus', async () => {
        const file = {
            id: 9,
            name: 'Visit checklist.docx',
            mime_type:
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            size_bytes: 2000,
            url: '/chat/api/attachments/9',
        };
        const withFile = {
            ...history,
            messages: [{ ...history.messages[0], attachments: [file] }],
        };
        vi.mocked(chatRequest).mockImplementation(async (path) =>
            path.endsWith('/metadata')
                ? ({
                      ...file,
                      message_id: 301,
                      conversation_id: 71,
                      sender_name: 'Rohan',
                      created_at: '2026-10-16T01:00:00Z',
                      audience_label: 'Current conversation members',
                      preview_url: null,
                      download_url: file.url,
                  } as never)
                : (withFile as never),
        );
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        const composer = await screen.findByRole('textbox', {
            name: 'Message',
        });
        fireEvent.change(composer, {
            target: { value: 'Keep my reply while reading' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Visit checklist.docx' }),
        );
        await screen.findByRole('link', {
            name: 'Download Visit checklist.docx',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Close viewer' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep my reply while reading',
        );
        expect(
            screen.getByRole('button', { name: 'Open Visit checklist.docx' }),
        ).toHaveFocus();
    });

    it('refreshes an open search after edits change which messages match', async () => {
        let changed = false;
        vi.mocked(chatRequest).mockImplementation(async (path) => {
            if (path.includes('?q='))
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            id: changed ? 302 : 301,
                            content: changed
                                ? 'Current matching message'
                                : 'Previously matching message',
                        },
                    ],
                } as never;
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', { name: 'Search this conversation' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search messages' }),
            { target: { value: 'matching' } },
        );
        await screen.findByText('Previously matching message');
        changed = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Current matching message');
        expect(
            screen.queryByText('Previously matching message'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: 'Search messages' }),
        ).toHaveValue('matching');
    });

    it('clears visible search content when refreshed search access is revoked', async () => {
        let revoked = false;
        vi.mocked(chatRequest).mockImplementation(async (path) => {
            if (path.includes('?q=')) {
                if (revoked)
                    throw new ChatError('Conversation access changed', 403);
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            content: 'Private search match',
                        },
                    ],
                } as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', { name: 'Search this conversation' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search messages' }),
            { target: { value: 'private' } },
        );
        await screen.findByText('Private search match');
        revoked = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() =>
            expect(
                screen.queryByText('Private search match'),
            ).not.toBeInTheDocument(),
        );
        expect(screen.queryByText('Hello Alex')).not.toBeInTheDocument();
    });

    it('keeps the query and draft when a search refresh fails and retries explicitly', async () => {
        let fail = false;
        vi.mocked(chatRequest).mockImplementation(async (path) => {
            if (path.includes('?q=')) {
                if (fail) throw new ChatError('Connection interrupted', 503);
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            content: 'Available search match',
                        },
                    ],
                } as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Keep my draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Search this conversation' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search messages' }),
            { target: { value: 'available' } },
        );
        await screen.findByText('Available search match');
        fail = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText(/Search updates paused/);
        expect(screen.getByText('Available search match')).toBeInTheDocument();
        fail = false;
        fireEvent.click(screen.getByRole('button', { name: 'Retry search' }));
        await screen.findByText('Available search match');
        expect(
            screen.queryByText(/Search updates paused/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: 'Search messages' }),
        ).toHaveValue('available');
        expect(
            screen.queryByRole('textbox', { name: 'Message' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close search' }));
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep my draft',
        );
    });

    it('discards a late result for the previous search phrase', async () => {
        let finishOld!: (value: ChatHistory) => void;
        vi.mocked(chatRequest).mockImplementation(async (path) => {
            if (path.includes('?q=previous'))
                return new Promise<ChatHistory>((resolve) => {
                    finishOld = resolve;
                }) as never;
            if (path.includes('?q=current'))
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            content: 'Current phrase result',
                        },
                    ],
                } as never;
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', { name: 'Search this conversation' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search messages' }),
            { target: { value: 'previous' } },
        );
        await waitFor(() => expect(finishOld).toBeDefined());
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search messages' }),
            { target: { value: 'current' } },
        );
        await screen.findByText('Current phrase result');
        await act(async () =>
            finishOld({
                ...history,
                messages: [
                    { ...history.messages[0], content: 'Stale phrase result' },
                ],
            }),
        );
        expect(
            screen.queryByText('Stale phrase result'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Current phrase result')).toBeInTheDocument();
    });

    it('restores the separate draft and files after cancelling an inline message edit', async () => {
        const ownHistory = {
            ...history,
            messages: [
                {
                    ...history.messages[0],
                    sender_id: 1,
                    sender_name: 'Alex',
                    mine: true,
                    can_edit: true,
                    can_remove: true,
                    edit_version: 0,
                },
            ],
        };
        vi.mocked(chatRequest).mockResolvedValue(ownHistory);
        const key = chatDraftKey(1, null, 71);
        saveChatDraft(key, 'My separate unsent draft');
        saveChatThreadMemory(key, {
            files: [new File(['test'], 'unsent.txt', { type: 'text/plain' })],
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', {
                name: /Actions for message from Alex/,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit my message' }),
        );
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Hello Alex',
        );
        expect(screen.queryByText('unsent.txt')).not.toBeInTheDocument();
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Edited draft only' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel edit' }));
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'My separate unsent draft',
        );
        expect(screen.getByText('unsent.txt')).toBeInTheDocument();
        expect(readChatThreadMemory(key).editing).toBeNull();
    });
    it('does not restore an earlier page that became stale while recovery was in flight', async () => {
        let finishOlder!: (value: ChatHistory) => void;
        const older = new Promise<ChatHistory>((resolve) => {
            finishOlder = resolve;
        });
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('?before=')) return (await older) as never;
            if (url.endsWith('/changes'))
                return {
                    conversation: history.conversation,
                    messages: [
                        {
                            ...history.messages[0],
                            id: 300,
                            content: '',
                            deleted: true,
                        },
                    ],
                    new_message_ids: [],
                    sync_cursor: 'second',
                    has_more_changes: false,
                } as never;
            return {
                ...history,
                has_more: true,
                sync_cursor: 'first',
            } as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load earlier messages' }),
        );
        fireEvent(window, new Event('online'));
        await waitFor(() =>
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/conversations/71/changes',
                expect.anything(),
            ),
        );
        await act(async () => {
            finishOlder({
                ...history,
                messages: [
                    {
                        ...history.messages[0],
                        id: 300,
                        content: 'Removed detail from stale page',
                    },
                ],
                sync_cursor: 'older-response',
            });
        });
        expect(
            screen.queryByText('Removed detail from stale page'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Load earlier messages' }),
        ).toBeEnabled();
    });

    it('catches up on reconnect and removes stale content while keeping the unsent draft', async () => {
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.endsWith('/changes'))
                return {
                    conversation: history.conversation,
                    messages: [
                        { ...history.messages[0], content: '', deleted: true },
                        {
                            ...history.messages[0],
                            id: 302,
                            content: 'Arrived while offline',
                        },
                    ],
                    new_message_ids: [302],
                    sync_cursor: 'second',
                    has_more_changes: false,
                } as never;
            return { ...history, sync_cursor: 'first' } as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Keep this draft' },
        });
        fireEvent(window, new Event('online'));
        await screen.findByText('Arrived while offline');
        expect(screen.queryByText('Hello Alex')).not.toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep this draft',
        );
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/changes',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ cursor: 'first' }),
            }),
        );
    });

    it.each([403, 419])(
        'replaces cached history after an access-scope reset and clears a later denied refresh (%s)',
        async (status) => {
            let changed = false;
            let revoked = false;
            vi.mocked(chatRequest).mockImplementation(async (url) => {
                if (url.endsWith('/changes')) {
                    if (revoked) throw new ChatError('Access removed', status);
                    changed = true;
                    throw new ChatError(
                        'History changed',
                        409,
                        'chat_sync_reset',
                    );
                }
                return {
                    ...history,
                    messages: changed
                        ? [
                              {
                                  ...history.messages[0],
                                  id: 402,
                                  content: 'Currently visible message',
                              },
                          ]
                        : history.messages,
                    sync_cursor: changed ? 'new-scope' : 'old-scope',
                } as never;
            });
            render(
                <ChatThread conversationId={71} actorId={1} policy={policy} />,
            );
            await screen.findByText('Hello Alex');
            fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
                target: { value: 'Keep this draft' },
            });
            fireEvent(window, new Event('online'));
            await screen.findByText('Currently visible message');
            expect(screen.queryByText('Hello Alex')).not.toBeInTheDocument();
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('Keep this draft');
            revoked = true;
            fireEvent(window, new Event('online'));
            await screen.findByText('Conversation unavailable');
            expect(
                screen.queryByText('Currently visible message'),
            ).not.toBeInTheDocument();
        },
    );

    it('reviews the family audience without sending and preserves the draft, attachment and reply until Send is pressed again', async () => {
        let current: ChatHistory = {
            ...history,
            conversation: {
                ...history.conversation,
                type: 'family',
                client: { id: 8, name: 'Jamie Example' },
                care_team_cover: true,
                audience_review_required: true,
                audience_review_token: 'a'.repeat(64),
            },
        };
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            const stage = stageOutcome(url, options);
            if (stage) return stage as never;
            if (url.endsWith('/audience-review')) {
                current = {
                    ...current,
                    conversation: {
                        ...current.conversation,
                        audience_review_required: false,
                    },
                };
                return { conversation: current.conversation } as never;
            }
            if (url.endsWith('/messages') && options?.method === 'POST')
                sends.push(options.body as FormData);
            return current as never;
        });
        render(
            <ChatThread
                conversationId={71}
                actorId={1}
                family
                policy={policy}
            />,
        );
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Please confirm the visit.' },
        });
        const file = new File(['Fixture'], 'visit.txt', { type: 'text/plain' });
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files: [file] },
        });
        await prepareAttachments();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Actions for message from Rohan/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        expect(
            screen.getByRole('dialog', { name: 'Before your first message' }),
        ).toBeInTheDocument();
        expect(screen.getByText('About Jamie Example')).toBeInTheDocument();
        expect(sends).toHaveLength(0);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(sends).toHaveLength(0);
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Please confirm the visit.',
        );
        expect(
            screen.getByRole('button', { name: 'Remove visit.txt' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(sends).toHaveLength(1));
        expect(sends[0].get('audience_token')).toBe('a'.repeat(64));
        expect(sends[0].get('content')).toBe('Please confirm the visit.');
        expect(sends[0].get('reply_to_id')).toBe('301');
        expect(sends[0].get('files[]')).toBeNull();
        expect(stagedFiles.get(String(sends[0].get('upload_ids[]')))).toBe(
            file,
        );
    });

    it('keeps a server-rejected stale-audience draft and reviews the current audience before another send', async () => {
        let changed = false;
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/family-work'))
                return {
                    version: 1,
                    status: 'open',
                    assigned_to: null,
                    owner_name: null,
                    owners: [],
                    can_manage: false,
                    response_expectation: '',
                    events: [],
                    next_before: null,
                    scan_floor: null,
                    older_before: null,
                } as never;
            const conversation = {
                ...history.conversation,
                type: 'family',
                client: { id: 8, name: 'Jamie Example' },
                audience_review_token: (changed ? 'b' : 'a').repeat(64),
                audience_review_required: changed,
            };
            if (url.endsWith('/audience-review'))
                return {
                    conversation: {
                        ...conversation,
                        audience_review_required: false,
                    },
                } as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1) {
                    changed = true;
                    throw new ChatError(
                        'Review the audience.',
                        409,
                        'chat_audience_review_required',
                    );
                }
            }
            return { ...history, conversation } as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Retained draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByRole('dialog', {
            name: 'Before your first message',
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Understood · Return to message',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Retained draft',
        );
        expect(sends).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await waitFor(() => expect(sends).toHaveLength(2));
        expect(sends[1].get('audience_token')).toBe('b'.repeat(64));
        expect(sends[1].get('mutation_id')).not.toBe(
            sends[0].get('mutation_id'),
        );
    });

    it('resolves an uncertain family send before asking to review an audience that changed after that send', async () => {
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url, true) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1) throw new Error('Connection lost');
            }
            return {
                ...history,
                conversation: {
                    ...history.conversation,
                    type: 'family',
                    client: { id: 8, name: 'Jamie Example' },
                    audience_review_token: (sends.length ? 'b' : 'a').repeat(
                        64,
                    ),
                    audience_review_required: sends.length > 0,
                },
            } as never;
        });
        const first = render(
            <ChatThread
                conversationId={71}
                actorId={1}
                family
                policy={policy}
            />,
        );
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Already accepted update' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByRole('button', { name: 'Retry sending message' });
        first.unmount();
        render(
            <ChatThread
                conversationId={71}
                actorId={1}
                family
                policy={policy}
            />,
        );
        await screen.findByRole('button', { name: 'Retry sending message' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue(''),
        );
        expect(sends).toHaveLength(1);
        expect(sends[0].get('audience_token')).toBe('a'.repeat(64));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('retains selected mention identities with the draft and reuses them after an uncertain send', async () => {
        const bodies: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                bodies.push(options.body as FormData);
                if (bodies.length === 1)
                    throw new Error('Connection interrupted');
                return { message: history.messages[0] } as never;
            }
            return history as never;
        });
        const first = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        await screen.findByText('Hello Alex');
        const input = screen.getByRole('textbox', { name: 'Message' });
        fireEvent.focus(input);
        fireEvent.change(input, {
            target: { value: 'Hi @Ro', selectionStart: 6 },
        });
        fireEvent.click(screen.getByRole('option', { name: '@Rohan' }));
        expect(input).toHaveValue('Hi @Rohan ');
        first.unmount();
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue('Hi @Rohan '),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByRole('alert');
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() => expect(bodies).toHaveLength(2));
        expect(bodies[0]).toBe(bodies[1]);
        expect(bodies[1].get('mentions[0][user_id]')).toBe('2');
        expect(bodies[1].get('mentions[0][start]')).toBe('3');
        expect(bodies[1].get('mentions[0][length]')).toBe('6');
    });

    it('does not clear unread messages merely because the thread was fetched in a background window', async () => {
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 800));
        });
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([url]) => url.endsWith('/read')),
        ).toBe(false);
        vi.mocked(document.hasFocus).mockReturnValue(true);
        fireEvent.focus(window);
        await waitFor(() =>
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/conversations/71/read',
                expect.objectContaining({
                    body: JSON.stringify({
                        message_id: 301,
                        unread_marker: null,
                    }),
                }),
            ),
        );
    });

    it('reuses the exact send reference after an unconfirmed request and retains the draft', async () => {
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1)
                    throw new Error('Connection interrupted');
                return { message: history.messages[0] } as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Thanks, I will follow up.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByRole('alert');
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Thanks, I will follow up.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() => expect(sends).toHaveLength(2));
        expect(sends[0].get('mutation_id')).toBe(sends[1].get('mutation_id'));
        expect(sends[1].get('content')).toBe('Thanks, I will follow up.');
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Message' }),
            ).toHaveValue(''),
        );
    });

    it('recovers the same unconfirmed send after leaving and reopening the conversation', async () => {
        const sends: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.includes('/send-outcomes/'))
                return sendOutcome(url) as never;
            if (url.endsWith('/messages') && options?.method === 'POST') {
                sends.push(options.body as FormData);
                if (sends.length === 1)
                    throw new Error('Acknowledgement was lost');
                return { message: history.messages[0] } as never;
            }
            return history as never;
        });
        const first = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Please confirm the visit.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await screen.findByRole('alert');
        first.unmount();
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        await screen.findByText(
            'A previous send has not been confirmed. Retry to check it safely.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry sending message' }),
        );
        await waitFor(() => expect(sends).toHaveLength(2));
        expect(sends[1]).toBe(sends[0]);
        expect(sends[1].get('content')).toBe('Please confirm the visit.');
    });

    it('clears in-memory drafts when the signed-in identity changes', () => {
        const key = chatDraftKey(1, 8, 71);
        saveChatDraft(key, 'Private family draft');
        expect(readChatDraft(key)).toBe('Private family draft');
        chatDraftKey(2, 8, 71);
        expect(readChatDraft(key)).toBe('');
    });

    it('preserves unsent files, a validated reply and search across navigation', async () => {
        const first = render(
            <ChatThread conversationId={71} actorId={1} policy={policy} />,
        );
        await screen.findByText('Hello Alex');
        const file = new File(['Review fixture'], 'visit.txt', {
            type: 'text/plain',
        });
        fireEvent.change(screen.getByLabelText('Choose chat files'), {
            target: { files: [file] },
        });
        await prepareAttachments();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Actions for message from Rohan/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reply' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Search this conversation' }),
        );
        fireEvent.change(
            screen.getByPlaceholderText('Search this conversation…'),
            { target: { value: 'Hello' } },
        );
        first.unmount();
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByPlaceholderText('Search this conversation…');
        expect(
            screen.getByPlaceholderText('Search this conversation…'),
        ).toHaveValue('Hello');
        fireEvent.click(screen.getByRole('button', { name: 'Close search' }));
        await screen.findByRole('button', { name: 'Remove visit.txt' });
        await waitFor(() =>
            expect(
                screen.queryByText('Checking your previous reply…'),
            ).not.toBeInTheDocument(),
        );
        const memory = readChatThreadMemory(chatDraftKey(1, null, 71));
        expect(memory.files[0]).toBe(file);
        expect(memory.replyId).toBe(301);
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/messages?around=301',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });

    it('does not silently send without a previous reply when recovering that reply fails', async () => {
        const key = chatDraftKey(1, null, 71);
        saveChatDraft(key, 'Please confirm.');
        saveChatThreadMemory(key, { replyId: 280 });
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('around=')) throw new Error('Offline');
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByRole('button', { name: 'Retry reply' });
        expect(
            screen.getByRole('button', { name: 'Send message' }),
        ).toBeDisabled();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Please confirm.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove reply' }));
        expect(
            screen.getByRole('button', { name: 'Send message' }),
        ).toBeEnabled();
        expect(readChatThreadMemory(key).replyId).toBeNull();
    });

    it('restores historical reading context without acknowledging the latest inbox', async () => {
        const key = chatDraftKey(1, null, 71);
        saveChatThreadMemory(key, { readAnchor: 280, readOffset: -12 });
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('around=280'))
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            id: 280,
                            content: 'Earlier context',
                        },
                    ],
                } as never;
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Earlier context');
        expect(
            screen.getByRole('button', { name: 'Return to latest messages' }),
        ).toBeInTheDocument();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([url]) => url.endsWith('/read')),
        ).toBe(false);
    });

    it('uses the approved details panel and keeps the draft when returning', async () => {
        vi.mocked(chatRequest).mockResolvedValue(history as never);
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Keep my unfinished reply' },
        });
        fireEvent.click(screen.getByRole('button', { name: /View people/ }));
        expect(
            screen.getByRole('complementary', { name: 'Conversation details' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Pinned messages' }),
        ).toBeInTheDocument();
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Close details' }),
            { key: 'Escape' },
        );
        expect(
            screen.queryByRole('complementary', {
                name: 'Conversation details',
            }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Keep my unfinished reply',
        );
    });

    it('saves a message through the compact action dialog without changing its draft', async () => {
        vi.mocked(chatRequest).mockResolvedValue(history as never);
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'An unfinished draft' },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: /Actions for message from Rohan/,
            }),
        );
        expect(
            screen.getByRole('dialog', { name: 'Message actions' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save for me' }));
        await waitFor(() =>
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/messages/301/action',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ action: 'save', active: true }),
                }),
            ),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'An unfinished draft',
        );
    });

    it('clears files on logout and ignores late writes from the previous account', () => {
        const key = chatDraftKey(1, null, 71);
        saveChatThreadMemory(key, {
            files: [new File(['private'], 'private.txt')],
            query: 'Private query',
        });
        setChatDraftActor(null);
        saveChatThreadMemory(key, { query: 'Late result' });
        saveChatDraft(key, 'Late draft');
        expect(readChatThreadMemory(key).files).toEqual([]);
        expect(readChatThreadMemory(key).query).toBe('');
        expect(readChatDraft(key)).toBe('');
    });

    it('opens the original reply context without clearing the draft or marking the current inbox read', async () => {
        const quoted = {
            ...history,
            messages: [
                {
                    ...history.messages[0],
                    reply_to_id: 280,
                    reply: {
                        id: 280,
                        sender_name: 'Alex',
                        content: 'Please confirm the plan.',
                        deleted: false,
                    },
                },
            ],
        };
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('around=280'))
                return {
                    ...history,
                    messages: [
                        {
                            ...history.messages[0],
                            id: 280,
                            content: 'Please confirm the plan.',
                        },
                    ],
                } as never;
            return quoted as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'An unfinished response' },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View original message from Alex',
            }),
        );
        await screen.findByRole('button', {
            name: 'Return to latest messages',
        });
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'An unfinished response',
        );
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/messages?around=280',
            expect.objectContaining({ headers: expect.any(Headers) }),
        );
        await waitFor(() =>
            expect(
                screen
                    .getByText('Please confirm the plan.')
                    .closest('[data-chat-message]'),
            ).toHaveFocus(),
        );
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([url]) => url.endsWith('/read')),
        ).toBe(false);
    });
});
