import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { IdeaBoard } from './_community';
import { IdeaProgress, IdeaProgressEditor } from './_idea-progress';
import type { HubIdea } from './_types';

const { request, reload, post, before, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
    before: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { reload, post, on: before, visit },
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        return {
            data,
            errors: {},
            processing: false,
            setData: (key: string, value: unknown) =>
                setData((old) => ({ ...old, [key]: value })),
            post: (url: string, options: unknown) => post(url, data, options),
            reset: (...keys: string[]) =>
                setData((current) =>
                    Object.fromEntries(
                        Object.entries(current).map(([key, value]) => [
                            key,
                            keys.includes(key) ? initial[key] : value,
                        ]),
                    ),
                ),
        };
    },
}));

const owner = {
    id: 11,
    profile_id: 21,
    name: 'Aroha Bennett',
    position: 'Learning advisor',
    url: '/company-hub/people/21',
};
const team = { id: 3, name: 'Learning' };
const idea: HubIdea = {
    id: 17,
    title: 'Share practical tips',
    body: 'Make space to learn from each other.',
    category: 'Better ways of working',
    status: 'in_progress',
    status_note: 'The first workshop is underway.',
    version: 4,
    author: 'Jordan',
    created_at: '2026-10-12T01:00:00Z',
    can_remove: false,
    can_update: true,
    support_count: 5,
    supported: false,
    comments: [],
    owner,
    team,
    updates: [
        {
            id: 1,
            status: 'reviewing',
            note: 'We are gathering ideas.',
            created_at: '2026-10-12T02:00:00Z',
            author: { id: 11, name: owner.name },
            owner,
            team,
            legacy: false,
        },
        {
            id: 2,
            status: 'in_progress',
            note: 'The first workshop is underway.',
            created_at: '2026-10-13T02:00:00Z',
            author: { id: 11, name: owner.name },
            owner,
            team,
            legacy: false,
        },
    ],
};
const context = {
    actorId: 11,
    progressReady: true,
    owners: [owner],
    teams: [team],
};
const choose = (label: string, option: string) => {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
};
const openEditor = () => {
    render(<IdeaProgressEditor idea={idea} {...context} />);
    fireEvent.click(screen.getByText('Post a progress update'));
};
describe('Ideas ownership and progress', () => {
    beforeEach(() => {
        request.mockReset();
        reload.mockReset();
        post.mockReset();
        before.mockReset().mockReturnValue(vi.fn());
        visit.mockReset();
    });
    it('discards the dialog on confirmed departure even when the route preserves component state', () => {
        render(
            <IdeaBoard
                ideas={[idea]}
                moderate
                initialIdeaId={17}
                {...context}
            />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
            { target: { value: 'Unsent discussion' } },
        );
        const event = {
            preventDefault: vi.fn(),
            detail: {
                visit: {
                    method: 'get',
                    url: '/company-hub?view=home',
                    preserveState: true,
                },
            },
        };
        act(() =>
            before.mock.calls
                .filter(([name]) => name === 'before')
                .at(-1)![1](event),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(visit).toHaveBeenCalledExactlyOnceWith(
            event.detail.visit.url,
            event.detail.visit,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: idea.title }));
        expect(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
        ).toHaveValue('');
    });
    it('invalidates removal confirmation when the current idea changes', () => {
        const props = {
            ideas: [{ ...idea, can_remove: true }],
            moderate: true,
            initialIdeaId: 17,
            ...context,
        };
        const view = render(<IdeaBoard {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Remove idea…' }));
        expect(
            screen.getByRole('button', { name: 'Remove idea' }),
        ).toBeVisible();
        view.rerender(
            <IdeaBoard
                {...props}
                ideas={[{ ...idea, can_remove: true, version: 5 }]}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Remove idea' }),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove idea…' }));
        fireEvent.click(screen.getByRole('button', { name: 'Remove idea' }));
        expect(post).toHaveBeenCalledWith(
            '/company-hub/ideas/17',
            { action: 'remove', version: 5, actor_id: 11 },
            expect.any(Object),
        );
    });
    it('retains discussion and progress drafts on close cancellation and new versions until explicit adoption', () => {
        const props = {
            ideas: [idea],
            moderate: true,
            initialIdeaId: 17,
            ...context,
        };
        const view = render(<IdeaBoard {...props} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
            { target: { value: 'A question still being written.' } },
        );
        fireEvent.click(screen.getByText('Post a progress update'));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'A progress note still being written.' } },
        );
        choose('Stage', 'Planned');
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'Leave this idea?',
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        view.rerender(
            <IdeaBoard
                {...props}
                ideas={[{ ...idea, version: 5, status: 'delivered' }]}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
        ).toHaveValue('A question still being written.');
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('A progress note still being written.');
        expect(
            screen.getByRole('combobox', { name: 'Stage' }),
        ).toHaveTextContent('Planned');
        expect(
            screen.getByRole('button', { name: 'Post update' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use latest stage, owner and team; keep my note',
            }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Stage' }),
        ).toHaveTextContent('Delivered');
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('A progress note still being written.');
        expect(
            screen.getByRole('button', { name: 'Post update' }),
        ).toBeEnabled();
        expect(request).not.toHaveBeenCalled();
    });
    it('refreshes a confirmed progress update without clearing an independent discussion draft or blocking the internal GET', async () => {
        request.mockResolvedValueOnce({ saved: true, idea_id: 17 });
        const props = {
            ideas: [idea],
            moderate: true,
            initialIdeaId: 17,
            ...context,
        };
        const view = render(<IdeaBoard {...props} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
            { target: { value: 'Keep my separate question.' } },
        );
        fireEvent.click(screen.getByText('Post a progress update'));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'The next workshop is ready.' } },
        );
        const event = {
            preventDefault: vi.fn(),
            detail: {
                visit: { method: 'get', url: '/company-hub?view=ideas' },
            },
        };
        reload.mockImplementation(() =>
            before.mock.calls
                .filter(([name]) => name === 'before')
                .at(-1)![1](event),
        );
        await act(async () =>
            fireEvent.click(
                screen.getByRole('button', { name: 'Post update' }),
            ),
        );
        expect(event.preventDefault).not.toHaveBeenCalled();
        act(() => reload.mock.calls[0][0].onFinish());
        expect(
            screen.getByText('Update saved.', { exact: false }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Post update' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <IdeaBoard
                {...props}
                ideas={[{ ...idea, version: 5, status: 'delivered' }]}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
        ).toHaveValue('Keep my separate question.');
        fireEvent.click(screen.getByRole('button', { name: 'Post comment' }));
        expect(post).toHaveBeenLastCalledWith(
            '/company-hub/ideas/17',
            {
                action: 'comment',
                body: 'Keep my separate question.',
                actor_id: 11,
            },
            expect.any(Object),
        );
        act(() => {
            post.mock.calls[0][2].onSuccess();
            post.mock.calls[0][2].onFinish();
        });
        expect(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
        ).toHaveValue('');
    });
    it('locks dismissal and navigation while progress is saving, then retains writing on failure', async () => {
        let fail!: (error: Error) => void;
        request.mockReturnValueOnce(
            new Promise((_resolve, reject) => {
                fail = reject;
            }),
        );
        render(
            <IdeaBoard
                ideas={[idea]}
                moderate
                initialIdeaId={17}
                {...context}
            />,
        );
        fireEvent.click(screen.getByText('Post a progress update'));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'Keep this note.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.getByRole('dialog', { name: idea.title })).toBeVisible();
        const handler = before.mock.calls
            .filter(([name]) => name === 'before')
            .at(-1)![1];
        const event = {
            preventDefault: vi.fn(),
            detail: { visit: { method: 'get', url: '/company-hub?view=news' } },
        };
        act(() => handler(event));
        expect(
            screen.getByRole('button', { name: 'Discard and continue' }),
        ).toBeDisabled();
        await act(async () =>
            fail(new Error('Please reload current progress.')),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('Keep this note.');
        expect(
            screen.getByRole('button', { name: 'Post update' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Reload latest progress' }),
        ).toBeEnabled();
    });
    it('does not carry writing or a late acknowledgement into another account', async () => {
        let finish!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const props = {
            ideas: [idea],
            moderate: true,
            initialIdeaId: 17,
            ...context,
        };
        const view = render(<IdeaBoard {...props} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
            { target: { value: 'Private unsent discussion.' } },
        );
        fireEvent.click(screen.getByText('Post a progress update'));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'Private unsent progress.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        view.rerender(<IdeaBoard {...props} actorId={22} />);
        await act(async () => finish({ saved: true, idea_id: 17 }));
        expect(
            screen.getByRole('textbox', { name: 'Add to the idea' }),
        ).toHaveValue('');
        expect(reload).not.toHaveBeenCalled();
        view.rerender(<IdeaBoard {...props} ideas={[]} actorId={22} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private unsent progress.'),
        ).not.toBeInTheDocument();
    });
    it('retains dated earlier updates and only uses projected work-profile links', () => {
        render(<IdeaProgress idea={idea} />);
        const history = within(
            screen.getByRole('list', { name: 'Progress updates' }),
        );
        expect(history.getAllByRole('listitem')).toHaveLength(2);
        expect(history.getByText('We are gathering ideas.')).toBeVisible();
        expect(
            history.getByText('The first workshop is underway.'),
        ).toBeVisible();
        expect(screen.getByRole('link', { name: owner.name })).toHaveAttribute(
            'href',
            owner.url,
        );
    });
    it('does not invent identities or dates for an earlier response', () => {
        render(
            <IdeaProgress
                idea={{
                    ...idea,
                    owner: null,
                    team: null,
                    updates: [],
                    status_note: 'Previously considered.',
                }}
            />,
        );
        expect(screen.getByText('Owner to be confirmed')).toBeVisible();
        expect(
            screen.getByText('Date not recorded · Earlier response'),
        ).toBeVisible();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('requires a current owner and lets staff find that colleague by role', async () => {
        request.mockResolvedValue({ saved: true, idea_id: idea.id });
        render(
            <IdeaProgressEditor
                idea={{ ...idea, owner: { ...owner, id: 900 } }}
                {...context}
            />,
        );
        fireEvent.click(screen.getByText('Post a progress update'));
        expect(
            screen.getByRole('combobox', { name: 'Taking it forward' }),
        ).toHaveTextContent('Choose an owner');
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'Next workshop is ready.' } },
        );
        expect(
            screen.getByRole('button', { name: 'Post update' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Taking it forward' }),
        );
        fireEvent.change(
            screen.getByRole('combobox', {
                name: 'Search colleagues by name or role',
            }),
            { target: { value: 'Learning advisor' } },
        );
        fireEvent.click(screen.getByRole('option', { name: /Aroha Bennett/ }));
        expect(
            screen.getByRole('combobox', { name: 'Taking it forward' }),
        ).toHaveTextContent('Aroha Bennett');
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/ideas/17',
                'POST',
                expect.objectContaining({
                    owner_id: 11,
                    actor_id: 11,
                    version: 4,
                }),
            ),
        );
    });
    it('submits once with actor/version/team/owner and refreshes only after confirmed persistence', async () => {
        let done!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    done = resolve;
                }),
        );
        openEditor();
        choose('Stage', 'Delivered');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: '  The workshop is now available.  ' } },
        );
        const submit = screen.getByRole('button', { name: 'Post update' });
        fireEvent.click(submit);
        fireEvent.click(submit);
        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith('/company-hub/ideas/17', 'POST', {
            action: 'status',
            status: 'delivered',
            status_note: 'The workshop is now available.',
            owner_id: 11,
            version: 4,
            actor_id: 11,
        });
        expect(reload).not.toHaveBeenCalled();
        await act(async () => done({ saved: true, idea_id: 17 }));
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: [
                    'ideas',
                    'ideaBrowse',
                    'selectedIdea',
                    'ideaId',
                    'ideaUnavailable',
                    'ideaProgressReady',
                    'ideaActorId',
                    'ideaTeams',
                    'ideaOwners',
                ],
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Post update' }),
        ).not.toBeInTheDocument();
    });
    it('keeps the note on conflict and never treats the failure as success', async () => {
        request.mockRejectedValue(
            new Error('This idea changed. Reload before updating it.'),
        );
        openEditor();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'Keep this explanation.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'This idea changed',
            ),
        );
        expect(
            screen.getByRole('textbox', { name: 'Update for staff' }),
        ).toHaveValue('Keep this explanation.');
        expect(reload).not.toHaveBeenCalled();
    });
    it('changes team routing only when the editor explicitly chooses a different team', async () => {
        request.mockResolvedValue({ saved: true, idea_id: idea.id });
        openEditor();
        choose('Best team', 'Company-wide');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'We will consider this across the company.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/ideas/17',
                'POST',
                expect.objectContaining({ department_id: null }),
            ),
        );
    });
    it('rejects a successful HTTP response for a different idea', async () => {
        request.mockResolvedValue({ saved: true, idea_id: 99 });
        openEditor();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Update for staff' }),
            { target: { value: 'Keep this explanation.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Post update' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        expect(reload).not.toHaveBeenCalled();
    });
    it.each([
        { ...context, progressReady: false },
        { ...context, actorId: null },
    ])('hides mutation controls without current readiness/account', (ctx) => {
        render(<IdeaProgressEditor idea={idea} {...ctx} />);
        expect(
            screen.queryByText('Post a progress update'),
        ).not.toBeInTheDocument();
    });
    it('does not grant update permission because the reader is the owner', () => {
        render(
            <IdeaProgressEditor
                idea={{ ...idea, can_update: false }}
                {...context}
            />,
        );
        expect(
            screen.queryByText('Post a progress update'),
        ).not.toBeInTheDocument();
    });
    it('searches public owner/team/history and filters In progress', () => {
        render(<IdeaBoard ideas={[idea]} moderate={false} {...context} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search ideas' }),
            { target: { value: 'gathering' } },
        );
        expect(screen.getByRole('button', { name: idea.title })).toBeVisible();
        choose('Idea status', 'In progress');
        expect(screen.getByRole('button', { name: idea.title })).toBeVisible();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search ideas' }),
            { target: { value: 'unrelated' } },
        );
        expect(screen.getByText('No ideas match these filters.')).toBeVisible();
    });
    it('opens a specifically selected authorised idea and keeps it closed after dismissal and refresh', () => {
        const view = render(
            <IdeaBoard
                ideas={[idea]}
                moderate={false}
                initialIdeaId={17}
                {...context}
            />,
        );
        expect(screen.getByRole('dialog', { name: idea.title })).toBeVisible();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        view.rerender(
            <IdeaBoard
                ideas={[{ ...idea, support_count: 7 }]}
                moderate={false}
                initialIdeaId={17}
                {...context}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });
    it('shows a withdrawn or unavailable idea without rendering protected details', () => {
        const view = render(
            <IdeaBoard
                ideas={[idea]}
                moderate={false}
                initialIdeaId={17}
                {...context}
            />,
        );
        view.rerender(
            <IdeaBoard
                ideas={[]}
                moderate={false}
                initialIdeaId={17}
                {...context}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'no longer available',
        );
        expect(screen.queryByText(idea.body)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        view.unmount();
        render(
            <IdeaBoard
                ideas={[]}
                moderate={false}
                initialUnavailable
                {...context}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'no longer available',
        );
        expect(request).not.toHaveBeenCalled();
    });
    it('routes a new suggestion to a canonical team with current actor identity', () => {
        render(<IdeaBoard ideas={[]} moderate={false} {...context} />);
        fireEvent.click(screen.getByRole('button', { name: 'Share an idea' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
            {
                target: { value: 'Share our skills' },
            },
        );
        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'What would change, and who would it help?',
            }),
            { target: { value: 'Run a practical workshop.' } },
        );
        choose('Best team to help', 'Learning');
        fireEvent.click(screen.getByRole('button', { name: 'Preview idea' }));
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Share idea' }));
        expect(request).toHaveBeenCalledWith('/company-hub/ideas', 'POST', {
            title: 'Share our skills',
            body: 'Run a practical workshop.',
            category: 'Better ways of working',
            department_id: 3,
            actor_id: 11,
        });
    });

    it('opens the acknowledged new idea from the refreshed board after sharing', async () => {
        request.mockResolvedValue({ saved: true, actor_id: 11, idea_id: 24 });
        const view = render(
            <IdeaBoard ideas={[]} moderate={false} {...context} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Share an idea' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
            { target: { value: 'Share our skills' } },
        );
        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'What would change, and who would it help?',
            }),
            { target: { value: 'Run a practical workshop.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Preview idea' }));
        fireEvent.click(screen.getByRole('button', { name: 'Share idea' }));
        await screen.findByText('Your idea is on the board.');
        view.rerender(
            <IdeaBoard
                ideas={[{ ...idea, id: 24, title: 'Share our skills' }]}
                moderate={false}
                {...context}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'View idea' }));
        expect(
            screen.getByRole('dialog', { name: 'Share our skills' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('dialog', { name: 'Thank you for sharing it' }),
        ).not.toBeInTheDocument();
        expect(request).toHaveBeenCalledOnce();
    });
});
