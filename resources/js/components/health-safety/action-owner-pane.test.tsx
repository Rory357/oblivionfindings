import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ActionOwnerPane,
    ActionOwnershipHistory,
    type ActionOwnership,
} from './action-owner-pane';

const state = vi.hoisted(() => ({
    post: vi.fn(),
    errors: {} as Record<string, string>,
    processing: false,
}));
vi.mock('@inertiajs/react', () => ({
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        return {
            data,
            errors: state.errors,
            processing: state.processing,
            isDirty: JSON.stringify(data) !== JSON.stringify(initial),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            clearErrors: vi.fn(),
            post: (url: string, options: unknown) =>
                state.post(url, data, options),
        };
    },
}));
const ownership: ActionOwnership = {
    revision: 3,
    state: 'assigned',
    can_assign: true,
    history: [],
};
const owner = { id: 10, name: 'Current Owner' };
const next = { id: 11, name: 'Next Owner' };
const done = vi.fn();
const cancel = vi.fn();
const fetchMock = vi.fn();
const props = {
    actionId: 42,
    owner,
    ownership,
    onDone: done,
    onCancel: cancel,
};

beforeEach(() => {
    vi.clearAllMocks();
    state.errors = {};
    state.processing = false;
    fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ options: [next] }),
    });
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Action ownership handover', () => {
    it('uses the exact action and reviewed revision, keeps the same request identity on retry and does not navigate before success', async () => {
        render(<ActionOwnerPane {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(await screen.findByRole('radio', { name: next.name }));
        fireEvent.change(
            screen.getByRole('textbox', { name: /Why you’re changing owner/ }),
            { target: { value: 'Agreed leave cover and evidence follow-up.' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm handover' }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/health-safety/corrective-actions/42/owner',
            expect.objectContaining({
                owner_user_id: '11',
                expected_revision: 3,
                request_uuid: expect.any(String),
                reason: 'Agreed leave cover and evidence follow-up.',
            }),
            expect.objectContaining({ onSuccess: done }),
        );
        expect(done).not.toHaveBeenCalled();
        const first = state.post.mock.calls[0][1].request_uuid;
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm handover' }),
        );
        expect(state.post.mock.calls[1][1].request_uuid).toBe(first);
    });

    it('keeps entries after a concurrent handover and requires review of the new owner before resubmitting', async () => {
        const view = render(<ActionOwnerPane {...props} />);
        fireEvent.click(await screen.findByRole('radio', { name: next.name }));
        const reason = screen.getByRole('textbox', {
            name: /Why you’re changing owner/,
        });
        fireEvent.change(reason, {
            target: { value: 'Keep this handover reason.' },
        });
        view.rerender(
            <ActionOwnerPane
                {...props}
                owner={{ id: 12, name: 'Changed Owner' }}
                ownership={{ ...ownership, revision: 4 }}
            />,
        );
        expect(screen.getByText('Changed Owner')).toBeInTheDocument();
        expect(reason).toHaveValue('Keep this handover reason.');
        expect(
            screen.getByRole('button', { name: 'Confirm handover' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'I have reviewed the current owner',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm handover' }),
        );
        expect(state.post.mock.calls[0][1].expected_revision).toBe(4);
        expect(state.post.mock.calls[0][1].reason).toBe(
            'Keep this handover reason.',
        );
    });

    it('retains the reason through owner lookup failure and supports retry', async () => {
        fetchMock.mockRejectedValueOnce(new Error('Unavailable'));
        render(<ActionOwnerPane {...props} />);
        const reason = screen.getByRole('textbox', {
            name: /Why you’re changing owner/,
        });
        fireEvent.change(reason, {
            target: { value: 'Retained while loading.' },
        });
        fireEvent.click(
            await screen.findByRole('button', { name: 'Retry owner search' }),
        );
        expect(
            await screen.findByRole('radio', { name: next.name }),
        ).toBeInTheDocument();
        expect(reason).toHaveValue('Retained while loading.');
        expect(state.post).not.toHaveBeenCalled();
    });

    it('does not show stale candidate results when a later search wins', async () => {
        let resolveFirst!: (value: unknown) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        render(<ActionOwnerPane {...props} />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Find the next owner' }),
            { target: { value: 'Next' } },
        );
        expect(
            await screen.findByRole('radio', { name: next.name }),
        ).toBeInTheDocument();
        resolveFirst({
            ok: true,
            json: async () => ({
                options: [{ id: 99, name: 'Stale Candidate' }],
            }),
        });
        await waitFor(() =>
            expect(
                screen.queryByRole('radio', { name: 'Stale Candidate' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('renders current server validation and uses the workspace cancel guard', () => {
        state.errors = {
            owner_user_id: 'Owner access changed.',
            expected_revision: 'Review the current owner.',
        };
        render(<ActionOwnerPane {...props} />);
        expect(screen.getByText('Owner access changed.')).toBeInTheDocument();
        expect(
            screen.getByText('Review the current owner.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(cancel).toHaveBeenCalledOnce();
        expect(done).not.toHaveBeenCalled();
    });

    it('shows the retained people and full handover reason', () => {
        render(
            <ActionOwnershipHistory
                ownership={{
                    ...ownership,
                    history: [
                        {
                            id: 5,
                            previous_owner: 'Original Name',
                            owner: 'Next Name',
                            changed_by: 'Coordinator',
                            changed_at: '2026-10-08T01:00:00Z',
                            reason: 'Keep all handover evidence.',
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByText('Original Name → Next Name'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Keep all handover evidence.'),
        ).toBeInTheDocument();
    });
});
