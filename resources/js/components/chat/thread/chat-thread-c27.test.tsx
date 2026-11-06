import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatDraftKey, chatRequest } from '../api';
import { ChatThread } from '../chat-thread';
import type { ChatHistory, ChatMessage, ChatPolicy } from '../types';

vi.mock('../api', async () => ({
    ...(await vi.importActual<typeof import('../api')>('../api')),
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
const message = (id: number, content: string): ChatMessage => ({
    id,
    sender_id: 2,
    sender_name: 'Rohan',
    mine: false,
    content,
    deleted: false,
    pinned: false,
    created_at: '2026-10-16T01:00:00Z',
    edited_at: null,
    reply_to_id: null,
    mutation_id: null,
    attachments: [],
    legacy_attachments: [],
    reactions: [],
});
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
        unread: 0,
        muted: false,
        favourite: false,
        can_send: true,
        care_team_cover: false,
        queue_status: 'open',
        assigned_to: null,
        version: 1,
        archived: false,
    },
    messages: [message(301, 'Hello Alex')],
    has_more: false,
};

let online = true;
function setOnline(value: boolean) {
    online = value;
    act(() => {
        window.dispatchEvent(new Event(value ? 'online' : 'offline'));
    });
}

async function write(text: string) {
    fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
        target: { value: text },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
}

describe('Chat thread (C2.7)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        chatDraftKey(99, null, 71);
        chatDraftKey(1, null, 71);
        online = true;
        Object.defineProperty(window.navigator, 'onLine', {
            configurable: true,
            get: () => online,
        });
        HTMLElement.prototype.scrollTo = vi.fn();
        HTMLElement.prototype.scrollIntoView = vi.fn();
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);
        vi.mocked(chatRequest).mockImplementation(
            async () => history as never,
        );
    });
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('keeps messages written offline in the outbox and sends nothing until asked', async () => {
        const posted: FormData[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.endsWith('/messages') && options?.method === 'POST') {
                posted.push(options.body as FormData);
                return {} as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        setOnline(false);
        expect(
            screen.getByText(
                "You're offline. Messages you send now wait here until you're back online and send them.",
            ),
        ).toBeInTheDocument();

        await write('Running late');
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            '',
        );
        await write('Ten minutes away');
        const waiting = screen.getByRole('list', {
            name: 'Messages waiting to send',
        });
        expect(within(waiting).getByText('Running late')).toBeInTheDocument();
        expect(
            within(waiting).getAllByText('Waiting for a connection'),
        ).toHaveLength(2);
        expect(
            within(waiting).getAllByRole('button', { name: 'Send' })[0],
        ).toBeDisabled();

        setOnline(true);
        expect(
            await screen.findByText('2 messages not sent yet. Check, then send.'),
        ).toBeInTheDocument();
        expect(posted).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Send all' }));
        await waitFor(() => expect(posted).toHaveLength(2));
        expect(posted.map((body) => body.get('content'))).toEqual([
            'Running late',
            'Ten minutes away',
        ]);
        expect(new Set(posted.map((body) => body.get('mutation_id'))).size).toBe(
            2,
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('list', { name: 'Messages waiting to send' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('checks a message whose result is unknown before posting it again, with the same reference', async () => {
        const posted: FormData[] = [];
        const checked: string[] = [];
        vi.mocked(chatRequest).mockImplementation(async (url, options) => {
            if (url.endsWith('/messages') && options?.method === 'POST') {
                posted.push(options.body as FormData);
                if (posted.length === 1)
                    throw new ChatError('Chat could not connect.', 0);
                return {} as never;
            }
            if (url.includes('/send-outcomes/')) {
                checked.push(url);
                return {
                    state: 'unconfirmed',
                    conversation_id: 71,
                    mutation_id: decodeURIComponent(url.split('/').at(-1)!),
                    message_id: null,
                    can_retry: true,
                } as never;
            }
            return history as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        setOnline(false);
        await write('Running late');
        setOnline(true);

        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        expect(
            await screen.findByText(
                "Not sure it sent. Sending again checks first, so it won't arrive twice.",
            ),
        ).toBeInTheDocument();
        expect(checked).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(posted).toHaveLength(2));
        expect(checked).toHaveLength(1);
        expect(checked[0]).toContain(String(posted[0].get('mutation_id')));
        expect(posted[1].get('mutation_id')).toBe(posted[0].get('mutation_id'));
    });

    it('puts an outbox message back in the composer to change it', async () => {
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        setOnline(false);
        await write('Runing late');
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        expect(screen.getByRole('textbox', { name: 'Message' })).toHaveValue(
            'Runing late',
        );
        expect(
            screen.queryByRole('list', { name: 'Messages waiting to send' }),
        ).not.toBeInTheDocument();
    });

    it('loads earlier messages as the reader nears the top, keeping a button only after a failure', async () => {
        const watchers: IntersectionObserverCallback[] = [];
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: IntersectionObserverCallback) {
                    watchers.push(callback);
                }
                observe() {}
                unobserve() {}
                disconnect() {}
                takeRecords() {
                    return [];
                }
            },
        );
        let fail = true;
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.includes('?before=301')) {
                if (fail) throw new ChatError('Chat could not connect.', 503);
                return {
                    ...history,
                    messages: [message(290, 'Earlier note')],
                    has_more: false,
                } as never;
            }
            return { ...history, has_more: true } as never;
        });
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        await screen.findByText('Hello Alex');
        expect(
            screen.queryByRole('button', { name: /earlier messages/ }),
        ).not.toBeInTheDocument();

        await act(async () => {
            watchers.at(-1)!(
                [{ isIntersecting: true } as IntersectionObserverEntry],
                {} as IntersectionObserver,
            );
        });
        const retry = await screen.findByRole('button', {
            name: 'Try loading earlier messages again',
        });
        fail = false;
        fireEvent.click(retry);
        expect(await screen.findByText('Earlier note')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /earlier messages/ }),
        ).not.toBeInTheDocument();
    });

    it('draws a reply quote as a neutral box with a reply icon, not an edge bar', async () => {
        vi.mocked(chatRequest).mockImplementation(
            async () =>
                ({
                    ...history,
                    messages: [
                        {
                            ...message(302, 'Yes, done'),
                            reply_to_id: 301,
                            reply: {
                                id: 301,
                                sender_name: 'Alex',
                                content: 'Hello Alex',
                                deleted: false,
                            },
                        },
                    ],
                }) as never,
        );
        render(<ChatThread conversationId={71} actorId={1} policy={policy} />);
        const quote = await screen.findByRole('button', {
            name: 'View original message from Alex',
        });
        expect(quote.className).not.toMatch(/border-[lrtb]-/);
        expect(quote.className).toMatch(/(^|\s)border(\s|$)/);
        expect(quote.querySelector('svg')).not.toBeNull();
    });

    it('mounts only the rows near the view in a long history', async () => {
        const long = Array.from({ length: 220 }, (_, index) =>
            message(1000 + index, `Note ${index}`),
        );
        vi.mocked(chatRequest).mockImplementation(
            async () => ({ ...history, messages: long }) as never,
        );
        const height = Object.getOwnPropertyDescriptor(
            HTMLElement.prototype,
            'clientHeight',
        );
        Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
            configurable: true,
            get() {
                return (this as HTMLElement).getAttribute('role') === 'log'
                    ? 600
                    : 0;
            },
        });
        try {
            render(
                <ChatThread conversationId={71} actorId={1} policy={policy} />,
            );
            await screen.findByText('Note 0');
            await waitFor(() =>
                expect(
                    document.querySelectorAll('[data-chat-message]').length,
                ).toBeLessThan(100),
            );
            expect(screen.queryByText('Note 219')).not.toBeInTheDocument();
        } finally {
            if (height)
                Object.defineProperty(
                    HTMLElement.prototype,
                    'clientHeight',
                    height,
                );
        }
    });
});
