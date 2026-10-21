import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest, setChatDraftActor } from './api';
import type { ChatCallback, ChatCallbackPage } from './chat-callback-sync';
import { ChatCallbacks } from './chat-callbacks';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const row = (overrides: Partial<ChatCallback> = {}): ChatCallback => ({
    id: 10,
    requested_by: 1,
    assigned_to: 2,
    owner_name: 'Rohan',
    status: 'assigned',
    preferred_time: 'After 2 pm',
    agreed_time: null,
    request_note: null,
    attempts: 0,
    attempt_history: [],
    eligible_owner_ids: [2, 3],
    version: 1,
    created_at: '2026-10-16T00:00:00Z',
    can_manage: false,
    can_cancel: true,
    ...overrides,
});
const page = (
    callbacks: ChatCallback[] = [],
    current: ChatCallback | null = null,
): ChatCallbackPage => ({
    callbacks,
    current_callback: current,
    owners: [
        { id: 2, name: 'Rohan' },
        { id: 3, name: 'Mere' },
    ],
    can_request: !current,
    next_before: null,
    scan_floor: callbacks.at(-1)?.id ?? null,
});
describe('Shared callback workflow', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockReset();
        setChatDraftActor(null);
    });

    it('requires a preferred time without presenting it as an agreed appointment', async () => {
        vi.mocked(chatRequest).mockResolvedValue(page());
        render(<ChatCallbacks actorId={1} conversationId={71} family />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        const dialog = await screen.findByRole('dialog');
        const preferred =
            await within(dialog).findByLabelText('Preferred time');
        expect(
            within(dialog).getByRole('button', { name: 'Request callback' }),
        ).toBeDisabled();
        expect(
            within(dialog).getByText(
                /A preferred time is not an agreed appointment/,
            ),
        ).toBeInTheDocument();
        fireEvent.change(preferred, { target: { value: 'After 2 pm' } });
        fireEvent.click(
            within(dialog).getByText('Close', { selector: 'button' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        expect(await screen.findByLabelText('Preferred time')).toHaveValue(
            'After 2 pm',
        );
    });

    it('retries an uncertain request with the identical body after closing and reopening', async () => {
        const bodies: string[] = [];
        let current: ChatCallback | null = null;
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST') {
                bodies.push(options.body as string);
                current = row();
                if (bodies.length === 1)
                    throw new Error('Connection lost after commit');
                return { id: 10, already_requested: true } as never;
            }
            return page(current ? [current] : [], current) as never;
        });
        render(<ChatCallbacks actorId={1} conversationId={71} family />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        fireEvent.change(await screen.findByLabelText('Preferred time'), {
            target: { value: 'After 2 pm' },
        });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request callback',
            }),
        );
        await screen.findByText(/This update has not been confirmed/);
        fireEvent.click(screen.getByText('Close', { selector: 'button' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry callback request',
            }),
        );
        await waitFor(() => expect(bodies).toHaveLength(2));
        expect(bodies[1]).toBe(bodies[0]);
        await waitFor(() =>
            expect(
                screen.queryByRole('button', {
                    name: 'Retry callback request',
                }),
            ).not.toBeInTheDocument(),
        );
        expect(
            await screen.findByText('Preferred: After 2 pm'),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Preferred time'),
        ).not.toBeInTheDocument();
    });

    it('preserves a scheduling draft during conflict and requires review of current details', async () => {
        let current = row({ can_manage: true });
        let revoked = false;
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (revoked) throw new ChatError('Access changed', 403);
            if (options?.method === 'PATCH') {
                current = row({
                    can_manage: true,
                    version: 2,
                    agreed_time: 'Friday at 3 pm',
                    status: 'scheduled',
                });
                throw new ChatError(
                    'This callback changed. Review current details.',
                    409,
                );
            }
            return page([current], current) as never;
        });
        render(
            <ChatCallbacks actorId={2} conversationId={71} family={false} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Callbacks' }));
        fireEvent.change(await screen.findByLabelText('Agreed callback time'), {
            target: { value: 'Monday at 2 pm' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save agreed time' }),
        );
        await screen.findByText(
            'This callback changed. Review current details.',
        );
        await screen.findByText('Agreed time: Friday at 3 pm');
        expect(screen.getByLabelText('Agreed callback time')).toHaveValue(
            'Monday at 2 pm',
        );
        expect(
            screen.getByRole('button', { name: 'Save agreed time' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use current details' }),
        );
        expect(screen.getByLabelText('Agreed callback time')).toHaveValue(
            'Friday at 3 pm',
        );
        revoked = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Access changed');
        expect(
            screen.queryByLabelText('Callback owner'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(
                'This callback changed. Review current details.',
            ),
        ).not.toBeInTheDocument();
    });

    it('shows dated attempts and supports explicit reassignment without family administration controls', async () => {
        const current = row({
            can_manage: true,
            attempts: 2,
            attempt_history: [{ id: 1, created_at: '2026-10-16T00:00:00Z' }],
        });
        vi.mocked(chatRequest).mockResolvedValue(page([current], current));
        render(
            <ChatCallbacks actorId={2} conversationId={71} family={false} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Callbacks' }));
        fireEvent.keyDown(await screen.findByLabelText('Callback owner'), {
            key: 'Enter',
        });
        fireEvent.click(screen.getByRole('option', { name: 'Mere' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        await waitFor(() =>
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/callbacks/10',
                expect.objectContaining({
                    method: 'PATCH',
                    body: expect.stringContaining('"assigned_to":3'),
                }),
            ),
        );
        expect(screen.getByText(/No answer/)).toBeInTheDocument();
        expect(
            screen.getByText(/1 earlier contact attempts/),
        ).toBeInTheDocument();
    });

    it('clears callback content after access removal and offers loading recovery', async () => {
        let denied = false;
        const current = row();
        vi.mocked(chatRequest).mockImplementation(async () => {
            if (denied) throw new ChatError('Access changed', 403);
            return page([current], current) as never;
        });
        render(<ChatCallbacks actorId={1} conversationId={71} family />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        await screen.findByText('Preferred: After 2 pm');
        expect(
            screen.queryByLabelText('Callback owner'),
        ).not.toBeInTheDocument();
        denied = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Access changed');
        expect(
            screen.queryByText('Preferred: After 2 pm'),
        ).not.toBeInTheDocument();
        denied = false;
        fireEvent.click(screen.getByRole('button', { name: 'Retry loading' }));
        await screen.findByText('Preferred: After 2 pm');
    });

    it('keeps an unsaved time editable during background refresh and requires review of a newer version', async () => {
        let resolve!: (value: ChatCallbackPage) => void;
        const current = row({ can_manage: true });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(page([current], current))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        render(
            <ChatCallbacks actorId={2} conversationId={71} family={false} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Callbacks' }));
        const input = await screen.findByLabelText('Agreed callback time');
        fireEvent.change(input, { target: { value: 'Monday at 2 pm' } });
        input.focus();
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(2));
        expect(input).toBeEnabled();
        expect(input).toHaveFocus();
        const changed = row({
            can_manage: true,
            version: 2,
            agreed_time: 'Friday at 3 pm',
        });
        await act(async () => resolve(page([changed], changed)));
        expect(input).toHaveValue('Monday at 2 pm');
        expect(input).toHaveFocus();
        expect(
            screen.getByRole('button', { name: 'Save agreed time' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Use current details' }),
        ).toBeInTheDocument();
    });

    it('discards a late callback response after switching account and conversation', async () => {
        let resolve!: (value: ChatCallbackPage) => void;
        const other = row({ id: 20, preferred_time: 'New account request' });
        vi.mocked(chatRequest)
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValue(page([other], other));
        const view = render(
            <ChatCallbacks actorId={1} conversationId={71} family />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(1));
        view.rerender(<ChatCallbacks actorId={2} conversationId={72} family />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        await screen.findByText('Preferred: New account request');
        await act(async () => resolve(page([row()], row())));
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/callbacks',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/72/callbacks',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '2' } }),
        );
        expect(
            screen.queryByText('Preferred: After 2 pm'),
        ).not.toBeInTheDocument();
    });

    it('keeps an old open callback visible while paging newer closed requests', async () => {
        const active = row({ id: 1 });
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                ...page(
                    [row({ id: 40, status: 'completed', can_cancel: false })],
                    active,
                ),
                next_before: 40,
                scan_floor: 40,
            })
            .mockResolvedValueOnce(
                page(
                    [
                        row({
                            id: 30,
                            status: 'completed',
                            preferred_time: 'Older closed request',
                            can_cancel: false,
                        }),
                    ],
                    active,
                ),
            );
        render(<ChatCallbacks actorId={1} conversationId={71} family />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request callback' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Earlier callback requests',
            }),
        );
        await screen.findByText('Preferred: Older closed request');
        expect(
            screen.getAllByText(/This existing request is reused/),
        ).toHaveLength(1);
        expect(
            screen.queryByLabelText('Preferred time'),
        ).not.toBeInTheDocument();
    });
});
