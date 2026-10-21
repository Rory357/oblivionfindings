import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest, setChatDraftActor } from './api';
import {
    ChatFamilyFollowup,
    type FollowupReview,
} from './chat-family-followup';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
vi.mock('@/components/fleet-assets/maintenance/date-picker', () => ({
    DatePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));

const review = (overrides: Partial<FollowupReview> = {}): FollowupReview => ({
    client: { id: 8, name: 'Jamie Example' },
    audience: [
        { id: 3, name: 'Moana' },
        { id: 4, name: 'Aria' },
    ],
    audience_token: 'review-one',
    owners: [
        { id: 1, name: 'Alex' },
        { id: 2, name: 'Rohan' },
    ],
    can_create: true,
    ...overrides,
});
const createButton = () =>
    screen.getByRole('button', { name: 'Create reviewed follow-up' });
const acknowledgement = () =>
    screen.getByRole('checkbox', {
        name: 'I have reviewed the shared Family Notes audience.',
    });
async function open() {
    fireEvent.click(screen.getByRole('button', { name: 'Create follow-up' }));
    await screen.findByText('About Jamie Example');
}
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
function fill() {
    fireEvent.change(screen.getByLabelText('Family-visible action'), {
        target: { value: 'Confirm Friday visit' },
    });
    choose('Assigned to', 'Rohan');
    fireEvent.change(screen.getByLabelText('Due date'), {
        target: { value: '2026-10-23' },
    });
}
describe('Reviewed Family Notes follow-ups', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset();
        vi.mocked(toast.success).mockReset();
        setChatDraftActor(null);
    });
    it('requires review of the shared audience and creates only the explicitly written action', async () => {
        const bodies: string[] = [];
        const event = vi.fn();
        window.addEventListener('oblivion:family-followup-created', event);
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST') {
                bodies.push(String(options.body));
                return {
                    family_note_id: 19,
                    already_created: false,
                    href: '/clients/8?tab=family_notes',
                } as never;
            }
            return review() as never;
        });
        render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
        await open();
        expect(
            screen.getByText(/including people outside this chat/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Moana, Aria/)).toBeInTheDocument();
        expect(
            screen.getByText(
                /Chat reply status and task completion stay separate/,
            ),
        ).toBeInTheDocument();
        fill();
        expect(createButton()).toBeDisabled();
        expect(bodies).toHaveLength(0);
        fireEvent.click(acknowledgement());
        fireEvent.click(createButton());
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(JSON.parse(bodies[0])).toEqual({
            mutation_id: expect.any(String),
            action: 'Confirm Friday visit',
            assigned_to: 2,
            due_date: '2026-10-23',
            audience_token: 'review-one',
            audience_confirmed: true,
        });
        expect(event).toHaveBeenCalledTimes(1);
        for (const [, options] of vi.mocked(chatRequest).mock.calls) {
            expect(new Headers(options?.headers).get('X-Chat-Actor')).toBe('1');
        }
        expect(event.mock.calls[0][0].detail).toEqual({ clientId: 8 });
        expect(toast.success).toHaveBeenCalledWith(
            'Follow-up created. Family chat remains open.',
            expect.any(Object),
        );
        await open();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue('');
        window.removeEventListener('oblivion:family-followup-created', event);
    });
    it('invalidates acknowledgement when the current audience changes without erasing the draft', async () => {
        let current = review();
        vi.mocked(chatRequest).mockImplementation(async () => current as never);
        render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
        await open();
        fill();
        fireEvent.click(acknowledgement());
        expect(createButton()).toBeEnabled();
        current = review({
            audience_token: 'review-two',
            audience: [{ id: 3, name: 'Moana' }],
        });
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() => expect(acknowledgement()).not.toBeChecked());
        expect(screen.getByLabelText('Family-visible action')).toHaveValue(
            'Confirm Friday visit',
        );
        expect(createButton()).toBeDisabled();
        fireEvent.click(acknowledgement());
        expect(createButton()).toBeEnabled();
    });
    it('retries exactly the same uncertain creation after remounting, even when the conversation became read-only', async () => {
        const bodies: string[] = [];
        let current = review();
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST') {
                bodies.push(String(options.body));
                if (bodies.length === 1) {
                    current = review({ can_create: false, owners: [] });
                    throw new Error('Connection lost');
                }
                return {
                    family_note_id: 19,
                    already_created: true,
                    href: '/clients/8?tab=family_notes',
                } as never;
            }
            return current as never;
        });
        const view = render(
            <ChatFamilyFollowup actorId={1} conversationId={71} />,
        );
        await open();
        fill();
        fireEvent.click(acknowledgement());
        fireEvent.click(createButton());
        await screen.findByText('Connection lost');
        view.unmount();
        render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
        await open();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue(
            'Confirm Friday visit',
        );
        expect(screen.getByLabelText('Family-visible action')).toBeDisabled();
        const retry = screen.getByRole('button', {
            name: 'Retry same follow-up',
        });
        expect(retry).toHaveFocus();
        fireEvent.click(retry);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(bodies).toHaveLength(2);
        expect(bodies[1]).toBe(bodies[0]);
    });
    it('requires a fresh audience review after a conflict and refuses a removed owner', async () => {
        let current = review();
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST') {
                current = review({
                    audience_token: 'review-two',
                    owners: [{ id: 1, name: 'Alex' }],
                });
                throw new ChatError(
                    'The audience changed. Review it again.',
                    409,
                );
            }
            return current as never;
        });
        render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
        await open();
        fill();
        fireEvent.click(acknowledgement());
        fireEvent.click(createButton());
        await waitFor(() =>
            expect(screen.getByLabelText('Assigned to')).toHaveTextContent(
                'Previous choice is no longer eligible',
            ),
        );
        expect(acknowledgement()).not.toBeChecked();
        expect(
            screen.queryByRole('button', { name: 'Retry same follow-up' }),
        ).not.toBeInTheDocument();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue(
            'Confirm Friday visit',
        );
        fireEvent.click(acknowledgement());
        expect(createButton()).toBeDisabled();
        choose('Assigned to', 'Alex');
        expect(acknowledgement()).not.toBeChecked();
        fireEvent.click(acknowledgement());
        expect(createButton()).toBeEnabled();
    });
    it.each([403, 419])(
        'removes private content and the unsent draft when current access is denied (%s)',
        async (status) => {
            let revoked = false;
            vi.mocked(chatRequest).mockImplementation(async () => {
                if (revoked) throw new ChatError('Access changed', status);
                return review() as never;
            });
            render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
            await open();
            fill();
            revoked = true;
            act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
            await screen.findByText('Access changed');
            expect(
                screen.queryByText('About Jamie Example'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByLabelText('Family-visible action'),
            ).not.toBeInTheDocument();
            expect(createButton()).toBeDisabled();
            revoked = false;
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry audience check' }),
            );
            await screen.findByLabelText('Family-visible action');
            expect(screen.getByLabelText('Family-visible action')).toHaveValue(
                '',
            );
        },
    );
    it('blocks creation when an audience refresh fails and preserves the reviewed draft for recovery', async () => {
        let offline = false;
        vi.mocked(chatRequest).mockImplementation(async () => {
            if (offline) throw new Error('Cannot check current access');
            return review() as never;
        });
        render(<ChatFamilyFollowup actorId={1} conversationId={71} />);
        await open();
        fill();
        fireEvent.click(acknowledgement());
        offline = true;
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await screen.findByText('Cannot check current access');
        expect(createButton()).toBeDisabled();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue(
            'Confirm Friday visit',
        );
        offline = false;
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry audience check' }),
        );
        await waitFor(() => expect(createButton()).toBeEnabled());
        // Closing is harmless, but reopening always requires current audience confirmation.
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await open();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue(
            'Confirm Friday visit',
        );
        expect(acknowledgement()).not.toBeChecked();
        expect(createButton()).toBeDisabled();
    });
    it('ignores late success from a previous account and does not share its draft', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
            if (options?.method === 'POST')
                return new Promise((done) => {
                    resolve = done;
                });
            return review() as never;
        });
        const view = render(
            <ChatFamilyFollowup actorId={1} conversationId={71} />,
        );
        await open();
        fill();
        fireEvent.click(acknowledgement());
        fireEvent.click(createButton());
        view.rerender(<ChatFamilyFollowup actorId={2} conversationId={72} />);
        await open();
        await act(async () =>
            resolve({
                family_note_id: 19,
                already_created: false,
                href: '/clients/8?tab=family_notes',
            }),
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByLabelText('Family-visible action')).toHaveValue('');
        expect(toast.success).not.toHaveBeenCalled();
    });
});
