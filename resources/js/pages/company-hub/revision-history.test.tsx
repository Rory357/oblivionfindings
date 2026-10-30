import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import {
    RevisionHistory,
    RevisionSnapshot,
    type RevisionSummary,
} from './_revision-history';
import type { HubContent } from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({ router: { on: () => vi.fn() } }));
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
    request
        .mockReset()
        .mockImplementation((_url, method) =>
            Promise.resolve(method === 'GET' ? detail : { saved: true }),
        );
    onChanged.mockReset();
});
async function open() {
    fireEvent.click(screen.getByRole('button', { name: 'Review version' }));
    await screen.findByText('Immutable body copy.');
    expect(
        screen.getByRole('region', { name: 'Saved version review' }),
    ).toBeVisible();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
}

describe('Saved version review', () => {
    it('opens the canonical saved draft separately from submitted history and continues editing', async () => {
        const close = vi.fn();
        request.mockResolvedValue({
            blocked_reason: null,
            current_draft: {
                source: 'draft',
                actor_id: 11,
                page_id: 3,
                version: 4,
                saved_at: null,
                content,
            },
        });
        render(
            <RevisionHistory
                {...props}
                revisions={[]}
                actorId={11}
                hasSavedDraft
                onClose={close}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'View saved draft' }),
        );
        await screen.findByText('Immutable body copy.');
        expect(request).toHaveBeenCalledWith(
            '/company-hub/manage/3/draft?include_snapshot=1&actor_id=11&version=4',
            'GET',
        );
        expect(
            screen.queryByRole('button', { name: /Restore|Approve/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue editing' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(onChanged).not.toHaveBeenCalled();
    });
    it.each(['review', 'schedule', 'unavailable'])(
        'honours a fresh %s lock while previewing the same saved version',
        async (blocked_reason) => {
            request.mockResolvedValue({
                blocked_reason,
                current_draft: {
                    source: 'draft',
                    actor_id: 11,
                    page_id: 3,
                    version: 4,
                    saved_at: null,
                    content,
                },
            });
            render(
                <RevisionHistory
                    {...props}
                    actorId={11}
                    hasSavedDraft
                    onClose={vi.fn()}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'View saved draft' }),
            );
            await screen.findByText('Immutable body copy.');
            expect(
                screen.getByRole('button', { name: 'Continue editing' }),
            ).toBeDisabled();
        },
    );
    it('does not display a draft returned for another account or version', async () => {
        request.mockResolvedValue({
            current_draft: {
                source: 'draft',
                actor_id: 99,
                page_id: 3,
                version: 4,
                content,
            },
        });
        render(<RevisionHistory {...props} actorId={11} hasSavedDraft />);
        fireEvent.click(
            screen.getByRole('button', { name: 'View saved draft' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(
            screen.queryByText('Immutable body copy.'),
        ).not.toBeInTheDocument();
    });
    it('drops a late saved snapshot after the confirmed version changes', async () => {
        let finish!: (value: unknown) => void;
        request
            .mockReturnValueOnce(
                new Promise((resolve) => {
                    finish = resolve;
                }),
            )
            .mockResolvedValue({ current_draft: null });
        const view = render(
            <RevisionHistory {...props} actorId={11} hasSavedDraft />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'View saved draft' }),
        );
        view.rerender(
            <RevisionHistory
                {...props}
                version={5}
                actorId={11}
                hasSavedDraft
            />,
        );
        await screen.findByText(/There is no unpublished saved draft/);
        await act(async () =>
            finish({
                current_draft: {
                    source: 'draft',
                    actor_id: 11,
                    page_id: 3,
                    version: 4,
                    content,
                },
            }),
        );
        expect(
            screen.queryByText('Immutable body copy.'),
        ).not.toBeInTheDocument();
    });
    it('asks before a stale-editor reload can discard retained feedback', async () => {
        const rendered = render(<RevisionHistory {...props} />);
        await open();
        fireEvent.change(screen.getByLabelText('Feedback for the author'), {
            target: { value: 'Keep this note until I decide.' },
        });
        rendered.rerender(<RevisionHistory {...props} version={5} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reload editor' }));
        expect(onChanged).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'Leave this saved-version review?',
        );
        expect(screen.getByLabelText('Feedback for the author')).toHaveValue(
            'Keep this note until I decide.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        await waitFor(() => expect(onChanged).toHaveBeenCalledOnce());
    });
    it('retains feedback after an unconfirmed action until a canonical version check', async () => {
        render(<RevisionHistory {...props} />);
        await open();
        fireEvent.change(screen.getByLabelText('Feedback for the author'), {
            target: { value: 'Please keep this feedback.' },
        });
        request.mockResolvedValueOnce({});
        fireEvent.click(
            screen.getByRole('button', { name: 'Request changes' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'couldn’t confirm',
        );
        expect(screen.getByLabelText('Feedback for the author')).toHaveValue(
            'Please keep this feedback.',
        );
        expect(
            screen.getByRole('button', { name: 'Request changes' }),
        ).toBeDisabled();
        expect(onChanged).not.toHaveBeenCalled();
        request.mockResolvedValueOnce(detail);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current version' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Request changes' }),
            ).toBeEnabled(),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/3/revisions/20',
            'GET',
        );
        expect(screen.getByLabelText('Feedback for the author')).toHaveValue(
            'Please keep this feedback.',
        );
    });
    it('locks duplicate review actions and ignores completion after unmount', async () => {
        const rendered = render(<RevisionHistory {...props} />);
        await open();
        let complete!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve & publish' }),
        );
        const confirm = screen.getByRole('button', { name: 'Confirm' });
        fireEvent.click(confirm);
        fireEvent.click(confirm);
        expect(
            request.mock.calls.filter(([, method]) => method === 'POST'),
        ).toHaveLength(1);
        expect(
            screen.getByRole('button', { name: 'All saved versions' }),
        ).toBeDisabled();
        rendered.unmount();
        await act(async () => complete({ saved: true }));
        expect(onChanged).not.toHaveBeenCalled();
    });
    it('shows saved callouts, button destinations, hidden content and campaign plan without live actions', () => {
        render(
            <RevisionSnapshot
                content={{
                    ...content,
                    sections: [
                        {
                            id: 'authored',
                            columns: 2,
                            blocks: [
                                {
                                    id: 'notice',
                                    type: 'notice',
                                    title: 'Saved callout',
                                    body: 'Bring your ideas to our launch.',
                                    hidden: true,
                                },
                                {
                                    id: 'button',
                                    type: 'button',
                                    title: 'Next step',
                                    body: 'Find the saved guidance.',
                                    action_label: 'Read the guidance',
                                    url: '/company-hub?view=resources',
                                },
                            ],
                        },
                    ],
                    checklist: [
                        { id: 'guide-step', title: 'Prepare the introduction' },
                    ],
                    campaign: {
                        ends_on: '2026-11-20',
                        steps: [
                            {
                                id: 'personal',
                                title: 'Share your suggestion',
                                url: '/company-hub?view=ideas',
                            },
                        ],
                        milestones: [
                            { id: '1', title: 'Plan agreed', status: 'done' },
                            {
                                id: '2',
                                title: 'Gather suggestions',
                                status: 'active',
                            },
                            {
                                id: '3',
                                title: 'Review together',
                                status: 'next',
                            },
                        ],
                        story_ids: [1, 3],
                        resource_ids: [6],
                        event_keys: ['event-4'],
                    },
                }}
            />,
        );
        const callout = screen.getByText('Saved callout').closest('article')!;
        expect(
            within(callout).getByText('Bring your ideas to our launch.'),
        ).toBeVisible();
        expect(
            within(callout).getByText('Hidden from staff in this version'),
        ).toBeVisible();
        expect(screen.getByText('Read the guidance')).toBeVisible();
        expect(
            screen.getByText('Destination: /company-hub?view=resources'),
        ).toBeVisible();
        expect(screen.getByText(/Target date:/)).toHaveTextContent(
            '20 November 2026',
        );
        expect(
            within(screen.getByText('Plan agreed').closest('li')!).getByText(
                'Complete',
            ),
        ).toBeVisible();
        expect(
            within(
                screen.getByText('Gather suggestions').closest('li')!,
            ).getByText('In progress'),
        ).toBeVisible();
        expect(
            within(
                screen.getByText('Review together').closest('li')!,
            ).getByText('Up next'),
        ).toBeVisible();
        expect(screen.getByText('News stories: 2 selected')).toBeVisible();
        expect(screen.getByText('Resources: 1 selected')).toBeVisible();
        expect(screen.getByText('Events: 1 selected')).toBeVisible();
        expect(screen.getByText('Share your suggestion')).toBeVisible();
        expect(screen.getByText('Prepare the introduction')).toBeVisible();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });
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
            new HubRequestError(
                'This submission has changed. Reload the current review.',
                409,
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
        expect(
            screen.queryByRole('region', { name: 'Saved version review' }),
        ).not.toBeInTheDocument();
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

// Workflow tests isolate the picker; date-fields.test.tsx exercises the actual shared calendar and clock.
vi.mock('./_date-fields', () => ({
 HubDateField: ({ id, label, value, onChange, disabled }: { id?: string; label: string; value: string; onChange: (value: string) => void; disabled?: boolean }) => <input id={id} aria-label={label} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />,
 HubDateTimeField: ({ id, label, value, onChange, disabled }: { id?: string; label: string; value: string; onChange: (value: string) => void; disabled?: boolean }) => <input id={id} aria-label={label} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />,
}));
