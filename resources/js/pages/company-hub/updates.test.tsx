import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InterestsDialog } from './_interests';
import type { HubInterests, HubProps, HubUpdate } from './_types';
import { UpdatesFeed } from './_updates';

const { request, post } = vi.hoisted(() => ({
    request: vi.fn(),
    post: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { post },
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
}));

const interests: HubInterests = {
    version: 2,
    topics: ['Learning'],
    teams: [7],
    available_topics: ['Learning', 'Wellbeing'],
    available_teams: [{ id: 7, name: 'People team' }],
};
function item(
    key: string,
    title: string,
    extras: Partial<HubUpdate> = {},
): HubUpdate {
    return {
        key,
        title,
        type: 'news',
        summary: 'A useful company update.',
        owner: 'Company team',
        at: '2026-10-13T10:00:00+13:00',
        url: `/company-hub/read/${key}`,
        topics: [],
        teams: [],
        unread: true,
        following: false,
        ...extras,
    };
}
const updates = [
    item('page:1:4', 'Learning story', {
        topics: ['Learning'],
        page_id: 1,
        bookmark: { subject_type: 'page', subject_id: 1 },
    }),
    item('page:2:8', 'Team guide', {
        type: 'resources',
        teams: [7],
        page_id: 2,
    }),
    item('page:3:2', 'Followed page', { page_id: 3 }),
    item('page:4:9', 'Other story', { page_id: 4 }),
    item('notice:5:new', 'Read this notice', {
        type: 'announcements',
        required: true,
    }),
    item('event:9:new', 'Learning session', {
        type: 'events',
        event_id: 'event-9',
    }),
];
const data = {
    interests,
    updates,
    followedPages: [3],
    bookmarks: [],
    events: [{ id: 'event-9', title: 'Learning session' }],
} as unknown as HubProps;

describe('Company Hub discovery', () => {
    beforeEach(() => {
        request.mockReset();
        post.mockReset();
    });

    it('combines topic, team and explicit page follows without adding unrelated content', () => {
        render(<UpdatesFeed data={data} following onEvent={vi.fn()} />);
        expect(
            screen.getByRole('link', { name: /Learning story/ }),
        ).toBeVisible();
        expect(screen.getByRole('link', { name: /Team guide/ })).toBeVisible();
        expect(
            screen.getByRole('link', { name: /Followed page/ }),
        ).toBeVisible();
        expect(
            screen.queryByRole('link', { name: /Other story/ }),
        ).not.toBeInTheDocument();
    });

    it('saves selected interests with the current version, retains edits on failure and reloads conflicts explicitly', async () => {
        const saved = vi.fn();
        request.mockRejectedValueOnce(new Error('Changed in another window.'));
        render(
            <InterestsDialog
                initial={interests}
                onClose={vi.fn()}
                onSaved={saved}
            />,
        );
        fireEvent.click(screen.getByRole('checkbox', { name: 'Wellbeing' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'People team' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save interests' }));
        await screen.findByRole('alert');
        expect(request).toHaveBeenCalledWith('/company-hub/interests', 'PUT', {
            version: 2,
            topics: ['Learning', 'Wellbeing'],
            teams: [],
        });
        expect(
            screen.getByRole('checkbox', { name: 'Wellbeing' }),
        ).toBeChecked();
        expect(saved).not.toHaveBeenCalled();
        request.mockResolvedValueOnce({ ...interests, version: 3, topics: [] });
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved choices' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('checkbox', { name: 'Learning' }),
            ).not.toBeChecked(),
        );
        request.mockResolvedValueOnce({ ...interests, version: 4, topics: [] });
        fireEvent.click(screen.getByRole('button', { name: 'Save interests' }));
        await waitFor(() =>
            expect(saved).toHaveBeenCalledWith(
                expect.objectContaining({ version: 4, topics: [] }),
            ),
        );
    });

    it('cancels interest changes without a write', () => {
        const close = vi.fn();
        render(
            <InterestsDialog
                initial={interests}
                onClose={close}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('checkbox', { name: 'Learning' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).toHaveBeenCalled();
        expect(request).not.toHaveBeenCalled();
    });

    it('keeps newly saved choices when an older page response arrives', async () => {
        request.mockResolvedValue({
            ...interests,
            version: 3,
            topics: ['Learning', 'Wellbeing'],
        });
        const view = render(
            <UpdatesFeed data={data} following onEvent={vi.fn()} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage interests' }),
        );
        fireEvent.click(screen.getByRole('checkbox', { name: 'Wellbeing' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save interests' }));
        await screen.findByText('Your interests have been saved.');
        view.rerender(
            <UpdatesFeed
                data={{
                    ...data,
                    interests: { ...interests, version: 1, topics: [] },
                }}
                following
                onEvent={vi.fn()}
            />,
        );
        expect(screen.getByText('Wellbeing')).toBeVisible();
    });

    it('pages through long update sets and marks only the visible page', async () => {
        const many = Array.from({ length: 65 }, (_, i) =>
            item(`page:${i + 1}:1`, `Story ${i + 1}`),
        );
        request.mockResolvedValue({
            marked: many.slice(60).map((i) => i.key),
            batch: 'last-page',
        });
        render(
            <UpdatesFeed data={{ ...data, updates: many }} onEvent={vi.fn()} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next updates' }));
        expect(
            screen.queryByRole('link', { name: /Story 1 / }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Mark shown as seen' }),
        );
        await screen.findByText('5 updates marked as seen.');
        expect(request).toHaveBeenCalledWith('/company-hub/seen', 'POST', {
            keys: many.slice(60).map((i) => i.key),
        });
    });

    it('marks only matching shown updates and can undo the exact server batch', async () => {
        request.mockResolvedValueOnce({
            marked: ['page:1:4'],
            batch: 'batch-one',
        });
        render(<UpdatesFeed data={data} onEvent={vi.fn()} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search your updates' }),
            { target: { value: 'Learning story' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Mark shown as seen' }),
        );
        await screen.findByText('1 update marked as seen.');
        expect(request).toHaveBeenCalledWith('/company-hub/seen', 'POST', {
            keys: ['page:1:4'],
        });
        expect(
            screen.queryByRole('button', {
                name: 'Mark Learning story as seen',
            }),
        ).not.toBeInTheDocument();
        request.mockResolvedValueOnce({});
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        await screen.findByRole('button', {
            name: 'Mark Learning story as seen',
        });
        expect(request).toHaveBeenLastCalledWith('/company-hub/seen', 'POST', {
            keys: ['page:1:4'],
            undo_batch: 'batch-one',
        });
    });

    it('keeps confirmations in needs-attention after marking them seen', async () => {
        request.mockResolvedValue({
            marked: ['notice:5:new'],
            batch: 'batch-notice',
        });
        render(<UpdatesFeed data={data} onEvent={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', { name: /Needs attention/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Mark shown as seen' }),
        );
        await screen.findByText('1 update marked as seen.');
        expect(
            screen.getByRole('link', { name: /Read this notice/ }),
        ).toBeVisible();
        expect(screen.getByText('Read confirmation needed')).toBeVisible();
        expect(
            request.mock.calls.every(([url]) => url === '/company-hub/seen'),
        ).toBe(true);
    });

    it('opens the correct canonical event and reports errors without pretending a seen write succeeded', async () => {
        const open = vi.fn();
        request.mockRejectedValue(new Error('Please refresh this update.'));
        render(<UpdatesFeed data={data} onEvent={open} />);
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Update type' }),
            { target: { value: 'events' } },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /Company team.*Learning session/,
            }),
        );
        await screen.findByRole('alert');
        expect(open).toHaveBeenCalledWith(data.events[0]);
        expect(
            screen.getByRole('button', {
                name: 'Mark Learning session as seen',
            }),
        ).toBeEnabled();
    });

    it('saves a story through the existing bookmark contract', () => {
        render(<UpdatesFeed data={data} onEvent={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Save Learning story' }),
        );
        expect(post).toHaveBeenCalledWith(
            '/company-hub/bookmarks',
            { subject_type: 'page', subject_id: 1, saved: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows no editor or shared-layout controls in the personal interests chooser', () => {
        render(<UpdatesFeed data={data} following onEvent={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Manage interests' }),
        );
        const dialog = within(screen.getByRole('dialog'));
        expect(
            dialog.getByText(
                /Everyone keeps the same shared Company Hub design/,
            ),
        ).toBeVisible();
        expect(
            dialog.queryByRole('button', { name: /Publish|Design hub/ }),
        ).not.toBeInTheDocument();
    });
});
