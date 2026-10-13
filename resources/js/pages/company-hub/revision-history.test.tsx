import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RevisionHistory, type RevisionSummary } from './_revision-history';
import type { HubContent } from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
const revision: RevisionSummary = {
    id: 20,
    state: 'submitted',
    draft_version: 4,
    created_at: '2026-10-12T00:00:00Z',
    publish_at: null,
    title: 'Saved announcement',
    author: 'Page author',
};
const content: HubContent = {
    title: 'Exact saved title',
    summary: 'The submitted introduction.',
    category: 'Company news',
    hero_label: 'Our company',
    cover_id: null,
    featured: false,
    audience: { type: 'all', ids: [] },
    comments_enabled: true,
    reactions_enabled: true,
    requires_approval: true,
    show_in_navigation: false,
    navigation_order: 0,
    review_on: null,
    sections: [
        {
            id: 'section',
            columns: 1,
            blocks: [
                {
                    id: 'text',
                    type: 'text',
                    title: 'The saved message',
                    body: 'Immutable body copy.',
                },
                { id: 'people', type: 'people', title: 'Colleagues', limit: 4 },
            ],
        },
    ],
};
const detail = {
    ...revision,
    page_version: 4,
    content,
    can_return: true,
    can_approve: true,
    can_withdraw: true,
};
const onChanged = vi.fn();
const props = {
    pageId: 3,
    version: 4,
    revisions: [revision],
    disabled: false,
    onChanged,
};
beforeEach(() => {
    request.mockReset().mockResolvedValue(detail);
    onChanged.mockReset();
});
async function open() {
    fireEvent.click(screen.getByRole('button', { name: 'Review version' }));
    await screen.findByText('Immutable body copy.');
}

describe('Saved version review', () => {
    it.each([
        ['Withdraw review', 'withdraw', { can_withdraw: true }],
        ['Cancel schedule', 'cancel_schedule', { can_cancel_schedule: true }],
    ] as const)(
        'confirms %s without approving or restoring content',
        async (label, action, capability) => {
            request.mockResolvedValueOnce({ ...detail, ...capability });
            render(<RevisionHistory {...props} />);
            await open();
            fireEvent.click(screen.getByRole('button', { name: label }));
            expect(request).toHaveBeenCalledTimes(1);
            fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
            await waitFor(() =>
                expect(request).toHaveBeenLastCalledWith(
                    '/company-hub/manage/3/revisions/20/review',
                    'POST',
                    { version: 4, action, note: null },
                ),
            );
            await waitFor(() => expect(onChanged).toHaveBeenCalledOnce());
        },
    );
    it('fetches the exact snapshot on opening and exposes no live interaction controls', async () => {
        render(<RevisionHistory {...props} />);
        expect(
            screen.queryByText('Immutable body copy.'),
        ).not.toBeInTheDocument();
        await open();
        expect(request).toHaveBeenCalledWith(
            '/company-hub/manage/3/revisions/20',
            'GET',
        );
        expect(screen.getByText('Exact saved title')).toBeInTheDocument();
        expect(
            screen.getByText(/not a copy of staff activity/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /vote|react|register/i }),
        ).not.toBeInTheDocument();
    });
    it('requires feedback, retains it after a conflict, and protects unsent work on close', async () => {
        render(<RevisionHistory {...props} />);
        await open();
        expect(
            screen.getByRole('button', { name: 'Request changes' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Feedback for the author'), {
            target: { value: '  Explain the next step.  ' },
        });
        request.mockRejectedValueOnce(
            new Error(
                'This submission has changed. Reload the current review.',
            ),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request changes' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This submission has changed',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/3/revisions/20/review',
            'POST',
            {
                version: 4,
                action: 'request_changes',
                note: 'Explain the next step.',
            },
        );
        expect(screen.getByLabelText('Feedback for the author')).toHaveValue(
            '  Explain the next step.  ',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close review' }));
        fireEvent.click(screen.getByRole('button', { name: 'Keep reviewing' }));
        expect(screen.getByLabelText('Feedback for the author')).toHaveValue(
            '  Explain the next step.  ',
        );
        request.mockResolvedValueOnce({ saved: true });
        fireEvent.click(
            screen.getByRole('button', { name: 'Request changes' }),
        );
        await waitFor(() => expect(onChanged).toHaveBeenCalledOnce());
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('confirms restore and sends the current draft version without publishing', async () => {
        render(<RevisionHistory {...props} />);
        await open();
        fireEvent.click(
            screen.getByRole('button', { name: 'Restore to draft' }),
        );
        expect(request).toHaveBeenCalledTimes(1);
        expect(
            screen.getByText('Replace the current draft with version 4?'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                '/company-hub/manage/3/revisions/20/restore',
                'POST',
                { version: 4 },
            ),
        );
        await waitFor(() => expect(onChanged).toHaveBeenCalledOnce());
    });
    it('confirms approval and submits optional schedule settings separately from the snapshot', async () => {
        render(<RevisionHistory {...props} />);
        await open();
        fireEvent.change(screen.getByLabelText('Publish at (optional)'), {
            target: { value: '2026-10-20T09:00' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve schedule' }),
        );
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                '/company-hub/manage/3/revisions/20/approve',
                'POST',
                {
                    version: 4,
                    publish_at: expect.any(String),
                    expires_at: null,
                },
            ),
        );
        await waitFor(() => expect(onChanged).toHaveBeenCalledOnce());
    });
    it('blocks actions while draft changes are unsaved or the loaded version is stale', async () => {
        const view = render(<RevisionHistory {...props} disabled />);
        await open();
        expect(
            screen.getByRole('button', { name: 'Restore to draft' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Approve & publish' }),
        ).toBeDisabled();
        view.rerender(<RevisionHistory {...props} version={5} />);
        expect(
            screen.getByRole('button', { name: 'Restore to draft' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Reload editor' }));
        expect(onChanged).toHaveBeenCalledOnce();
    });
    it('does not display historical content when current access is denied', async () => {
        request.mockRejectedValueOnce(
            new Error('This saved version is unavailable.'),
        );
        render(<RevisionHistory {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Review version' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'unavailable',
        );
        expect(
            screen.queryByText('Immutable body copy.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Restore to draft' }),
        ).not.toBeInTheDocument();
    });
    it('discards an older pagination response after refreshed history arrives', async () => {
        let finish: (result: unknown) => void = () => {};
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<RevisionHistory {...props} cursor={20} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load older versions' }),
        );
        view.rerender(
            <RevisionHistory
                {...props}
                revisions={[{ ...revision, title: 'Fresh history' }]}
                cursor={null}
            />,
        );
        await act(async () => {
            finish({
                items: [{ ...revision, id: 9, title: 'Obsolete response' }],
                next_cursor: 9,
            });
        });
        expect(screen.getByText('Fresh history')).toBeInTheDocument();
        expect(screen.queryByText('Obsolete response')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Load older versions' }),
        ).not.toBeInTheDocument();
    });
});
