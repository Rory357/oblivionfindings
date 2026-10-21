import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest, setChatDraftActor } from './api';
import { ChatCreateConversation } from './chat-create-conversation';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const props = {
    open: true,
    actorId: 1,
    family: false,
    clientId: null,
    people: [
        { id: 2, name: 'Mere Example' },
        { id: 3, name: 'Alex Example' },
    ],
    sites: [],
    canManageChannels: false,
    initialPerson: null,
    onOpenChange: vi.fn(),
    onCreated: vi.fn(),
};
function group() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Create a private group instead' }),
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Group name' }), {
        target: { value: 'Evening support' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Mere Example' }));
}
describe('approved conversation creation and request recovery', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setChatDraftActor(null);
        vi.mocked(chatRequest).mockResolvedValue({ conversation: { id: 71 } });
    });
    it('selects just one colleague for direct chat and explicitly requests a two-person group', async () => {
        render(<ChatCreateConversation {...props} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Mere Example' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Alex Example' }));
        expect(
            screen.getByRole('radio', { name: 'Mere Example' }),
        ).not.toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(props.onCreated).toHaveBeenCalledWith(71));
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[0][1]?.body)),
        ).toMatchObject({
            conversation_kind: 'direct',
            participant_ids: [3],
            title: null,
        });
        group();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(2));
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[1][1]?.body)),
        ).toMatchObject({
            conversation_kind: 'group',
            participant_ids: [2],
            title: 'Evening support',
        });
    });
    it('keeps the exact uncertain request through closing and navigation and retries it without changing the reviewed selection', async () => {
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new TypeError('Connection lost'),
        );
        const view = render(<ChatCreateConversation {...props} />);
        group();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByRole('button', { name: 'Retry same request' });
        const original = vi.mocked(chatRequest).mock.calls[0][1]?.body;
        expect(
            screen.getByRole('textbox', { name: 'Group name' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('checkbox', { name: 'Alex Example' }),
        ).toBeDisabled();
        view.rerender(<ChatCreateConversation {...props} open={false} />);
        view.rerender(<ChatCreateConversation {...props} />);
        expect(screen.getByRole('textbox', { name: 'Group name' })).toHaveValue(
            'Evening support',
        );
        view.unmount();
        render(
            <ChatCreateConversation
                {...props}
                people={[{ id: 3, name: 'Renamed person' }]}
            />,
        );
        expect(
            screen.getByRole('checkbox', { name: 'Mere Example' }),
        ).toBeChecked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await waitFor(() => expect(props.onCreated).toHaveBeenCalledWith(71));
        expect(vi.mocked(chatRequest).mock.calls[1][1]?.body).toBe(original);
    });
    it('allows a corrected selection with a fresh request only after a definitive rejection', async () => {
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Review this name.', 422),
        );
        render(<ChatCreateConversation {...props} />);
        group();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByText('Review this name.');
        expect(
            screen.getByRole('textbox', { name: 'Group name' }),
        ).toBeEnabled();
        const first = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[0][1]?.body),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Group name' }), {
            target: { value: 'Reviewed group' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await waitFor(() => expect(props.onCreated).toHaveBeenCalledWith(71));
        const second = JSON.parse(
            String(vi.mocked(chatRequest).mock.calls[1][1]?.body),
        );
        expect(second.mutation_id).not.toBe(first.mutation_id);
        expect(second.title).toBe('Reviewed group');
    });
    it('lets the user remove a no-longer-eligible selection without losing the group name', async () => {
        const view = render(<ChatCreateConversation {...props} />);
        group();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Alex Example' }));
        view.rerender(
            <ChatCreateConversation {...props} people={[props.people[1]]} />,
        );
        expect(
            screen.getByRole('button', { name: 'Create group' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove unavailable people' }),
        );
        expect(screen.getByRole('textbox', { name: 'Group name' })).toHaveValue(
            'Evening support',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await waitFor(() => expect(props.onCreated).toHaveBeenCalledWith(71));
        expect(
            JSON.parse(String(vi.mocked(chatRequest).mock.calls[0][1]?.body)),
        ).toMatchObject({ participant_ids: [3], title: 'Evening support' });
    });
    it('does not open a late result in another client context or carry a request into another account', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(
            <ChatCreateConversation {...props} family clientId={8} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(chatRequest).toHaveBeenLastCalledWith(
            '/chat/api/conversations',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '1' } }),
        );
        view.rerender(
            <ChatCreateConversation
                {...props}
                actorId={4}
                family
                clientId={9}
            />,
        );
        await act(async () => finish({ conversation: { id: 81 } }));
        expect(props.onCreated).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'Care team or named worker' }),
        ).toHaveTextContent('Care team inbox');
        view.rerender(
            <ChatCreateConversation {...props} family clientId={8} />,
        );
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
    });
});
