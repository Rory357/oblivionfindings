import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EventDraftWorkspace, type EventDraft } from './_event-drafts';

const { request, reload } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/components/hr/calendar/event-wizard-dialog', () => ({
    EventWizardDialog: ({
        publishing,
    }: {
        publishing: { draft_id: number; version: number };
    }) => (
        <p>
            Editing draft {publishing.draft_id} version {publishing.version}
        </p>
    ),
}));
const draft: EventDraft = {
    id: 12,
    version: 3,
    state: 'review',
    archived_at: null,
    owner: 'Company editor',
    feedback: null,
    updated_at: '2026-10-13T00:00:00Z',
    published_at: null,
    initial: {
        id: 0,
        edit_version: 1,
        title: 'Welcome afternoon',
        description: 'Meet your colleagues.',
        event_type: 'social',
        starts_at: '2026-10-20T00:00:00Z',
        ends_at: '2026-10-20T01:00:00Z',
        is_all_day: false,
        location: 'Main room',
        site_id: null,
        department_id: null,
        audience_type: 'org',
        attachments: [
            {
                id: -6,
                name: 'welcome.png',
                mime: 'image/png',
                size: 100,
                url: '/company-hub/event-draft-files/6',
            },
        ],
    },
};
const options = {
    sites: [],
    departments: [],
    teams: [],
    staff: [],
    categories: [],
    storyOptions: [],
};
async function openWorkspace(canPublish = true, source = draft) {
    request
        .mockResolvedValueOnce({ can_publish: canPublish, drafts: [source] })
        .mockResolvedValueOnce(options);
    render(<EventDraftWorkspace />);
    fireEvent.click(screen.getByRole('button', { name: 'Event drafts' }));
    if (source.archived_at)
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Event draft status' }),
            { target: { value: 'archived' } },
        );
    fireEvent.click(
        await screen.findByRole('button', { name: /Welcome afternoon/ }),
    );
}
describe('Event publication workspace', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.history.replaceState({}, '', '/company-hub?view=events');
    });
    it('clears the selected review when switching to a different history view', async () => {
        await openWorkspace();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Event draft status' }),
            { target: { value: 'published' } },
        );
        expect(
            screen.queryByRole('button', { name: 'Approve & publish' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Select a draft to review its details and files.'),
        ).toBeInTheDocument();
    });
    it('reviews the full draft and approves only its displayed version', async () => {
        await openWorkspace();
        expect(screen.getByText('Meet your colleagues.')).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'welcome.png · Private draft file',
            }),
        ).toHaveAttribute('href', '/company-hub/event-draft-files/6');
        request.mockResolvedValueOnce({
            draft: {
                ...draft,
                version: 4,
                state: 'published',
                published_at: '2026-10-13T00:00:00Z',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve & publish' }),
        );
        await screen.findByText(
            'Event published. Staff can now see the approved version.',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/event-drafts/12/transition',
            'POST',
            { action: 'approve', version: 3, feedback: undefined },
        );
        expect(reload).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByRole('button', { name: 'Approve & publish' }),
        ).not.toBeInTheDocument();
    });
    it('requires feedback and keeps the same review visible after a failed approval', async () => {
        await openWorkspace();
        request.mockRejectedValueOnce(
            new Error('This draft changed. Reopen it.'),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve & publish' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This draft changed',
        );
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Return with feedback' }),
        );
        expect(
            screen.getByRole('button', { name: 'Send feedback' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByLabelText('What should the author change?'),
            { target: { value: 'Clarify the location.' } },
        );
        request.mockResolvedValueOnce({
            draft: {
                ...draft,
                state: 'changes_requested',
                version: 4,
                feedback: 'Clarify the location.',
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send feedback' }));
        await screen.findByText('Feedback returned to the author.');
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/event-drafts/12/transition',
            'POST',
            { action: 'return', version: 3, feedback: 'Clarify the location.' },
        );
    });
    it('lets an author withdraw before editing and hides publisher actions', async () => {
        await openWorkspace(false);
        expect(
            screen.queryByRole('button', { name: 'Approve & publish' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit draft' }),
        ).not.toBeInTheDocument();
        request.mockResolvedValueOnce({
            draft: { ...draft, state: 'draft', version: 4 },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Withdraw review' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit draft' }),
        );
        expect(
            screen.getByText('Editing draft 12 version 4'),
        ).toBeInTheDocument();
    });
    it('restores an archived draft without changing the published event', async () => {
        await openWorkspace(true, {
            ...draft,
            archived_at: '2026-10-13T00:00:00Z',
            state: 'draft',
        });
        expect(
            screen.queryByRole('button', { name: 'Edit draft' }),
        ).not.toBeInTheDocument();
        request.mockResolvedValueOnce({
            draft: { ...draft, state: 'draft', version: 4 },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Restore draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Edit draft' }),
            ).toBeEnabled(),
        );
        expect(reload).not.toHaveBeenCalled();
    });
});
