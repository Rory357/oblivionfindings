import { Button } from '@/components/ui/button';
import {
    act,
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { IdeaBoard } from './_community';
import { RecognitionBoard } from './_recognition';
import { hubData } from './_test-fixtures';
import type { HubBrowse, HubIdea, HubKudos } from './_types';
import { useHubBrowse } from './_use-hub-browse';

const { request, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('./_recognition-card', () => ({
    RecognitionCard: ({
        kudos,
        onDraftChange,
    }: {
        kudos: HubKudos;
        onDraftChange?: (id: number, dirty: boolean) => void;
    }) => (
        <article>
            {kudos.message}
            <Button onClick={() => onDraftChange?.(kudos.id, true)}>
                Start comment draft {kudos.id}
            </Button>
            <Button onClick={() => onDraftChange?.(kudos.id, false)}>
                Cancel comment draft {kudos.id}
            </Button>
        </article>
    ),
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { visit, on: () => () => undefined },
    useForm: () => ({ data: { body: '' }, errors: {}, processing: false }),
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({}),
}));
vi.mock('./_idea-progress', () => ({
    IdeaProgress: () => null,
    IdeaProgressEditor: () => null,
    ideaStatuses: { open: 'Open', planned: 'Planned' },
}));

const page = <T,>(
    data: T[],
    current = 1,
    more = false,
    filters: HubBrowse<T>['filters'] = { sort: 'newest' },
): HubBrowse<T> => ({
    data,
    filters,
    pagination: {
        page: current,
        per_page: 20,
        has_more: more,
        next_page: more ? current + 1 : null,
    },
});
const first = { id: 1, title: 'First page' };
beforeEach(() => {
    request.mockReset();
    visit.mockReset();
});

it('continues the result set once, deduplicates canonical records and retries a failed continuation', async () => {
    const initial = page([first], 1, true);
    const { result } = renderHook(() =>
        useHubBrowse({
            endpoint: '/browse',
            initial,
            fallback: [first],
            filters: { sort: 'newest' },
            enabled: true,
        }),
    );
    expect(request).not.toHaveBeenCalled();
    request.mockRejectedValueOnce(new Error('Connection interrupted.'));
    act(() => {
        result.current.loadMore();
        result.current.loadMore();
    });
    await waitFor(() =>
        expect(result.current.error).toBe('Connection interrupted.'),
    );
    expect(request).toHaveBeenCalledTimes(1);
    expect(result.current.rows).toEqual([first]);
    request.mockResolvedValueOnce(
        page(
            [
                { ...first, title: 'Current first' },
                { id: 2, title: 'Older result' },
            ],
            2,
        ),
    );
    act(() => result.current.retry());
    await waitFor(() =>
        expect(result.current.rows.map((row) => row.id)).toEqual([1, 2]),
    );
    expect(request.mock.calls[1][0]).toContain('page=2');
    expect(result.current.rows[0].title).toBe('Current first');
    expect(result.current.hasMore).toBe(false);
    expect(result.current.error).toBe('');
});

it('discards a late search response and a late result from an earlier authorised source', async () => {
    const initial = page([first]);
    let oldSearch!: (value: HubBrowse<typeof first>) => void;
    request.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                oldSearch = resolve;
            }),
    );
    const { result, rerender } = renderHook(
        ({ query, source }) =>
            useHubBrowse({
                endpoint: '/browse',
                initial: source,
                fallback: source.data,
                filters: { sort: 'newest', search: query },
                enabled: true,
            }),
        { initialProps: { query: '', source: initial } },
    );
    rerender({ query: 'old', source: initial });
    expect(result.current.rows).toEqual([]);
    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    request.mockResolvedValueOnce(
        page([{ id: 3, title: 'New search' }], 1, false, {
            sort: 'newest',
            search: 'new',
        }),
    );
    rerender({ query: 'new', source: initial });
    await waitFor(() =>
        expect(result.current.rows[0]?.title).toBe('New search'),
    );
    await act(async () => oldSearch(page([{ id: 4, title: 'Stale secret' }])));
    expect(result.current.rows.map((row) => row.title)).toEqual(['New search']);
    let oldSource!: (value: HubBrowse<typeof first>) => void;
    request.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                oldSource = resolve;
            }),
    );
    rerender({ query: 'another', source: initial });
    await waitFor(() => expect(request).toHaveBeenCalledTimes(3));
    const replacement = page([{ id: 9, title: 'New account record' }]);
    rerender({ query: '', source: replacement });
    expect(result.current.rows.map((row) => row.title)).toEqual([
        'New account record',
    ]);
    await act(async () =>
        oldSource(page([{ id: 8, title: 'Earlier account result' }])),
    );
    expect(result.current.rows.map((row) => row.title)).toEqual([
        'New account record',
    ]);
});

it('queries recognition history using canonical filters and exposes loading retry and continuation controls', async () => {
    const kudos: HubKudos = {
        id: 1,
        message: 'Initial public thanks',
        from: 'Jamie',
        to: 'Aroha',
        to_id: 2,
        category: 'Teamwork',
        category_key: 'teamwork',
        created_at: '2026-10-16T00:00:00Z',
        reactions: {},
        replies: [],
        can_reply: false,
    };
    const older = {
        ...kudos,
        id: 90,
        message: 'Older moment beyond the initial page',
    };
    render(
        <RecognitionBoard
            data={hubData({
                kudos: [kudos],
                recognitionBrowse: page([kudos], 1, true),
                kudosCategories: { teamwork: 'Teamwork', kindness: 'Kindness' },
            })}
        />,
    );
    request.mockResolvedValueOnce(page([older], 2));
    fireEvent.click(
        screen.getByRole('button', { name: 'Show more shout-outs' }),
    );
    await screen.findByText(older.message);
    request.mockRejectedValueOnce(new Error('Please retry this search.'));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
        { target: { value: 'history' } },
    );
    expect(screen.getByText('Loading shout-outs…')).toBeVisible();
    await screen.findByText('Please retry this search.');
    expect(
        screen.queryByText('No shout-outs in this view'),
    ).not.toBeInTheDocument();
    request.mockResolvedValueOnce(
        page([older], 1, false, { sort: 'newest', search: 'history' }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Try loading shout-outs again' }),
    );
    await screen.findByText(older.message);
    expect(request.mock.calls.at(-1)?.[0]).toContain('search=history');
    fireEvent.click(screen.getByRole('button', { name: 'Filters · Active' }));
    fireEvent.click(screen.getByRole('combobox', { name: 'Company value' }));
    expect(screen.getByRole('option', { name: 'Kindness' })).toBeVisible();
    request.mockResolvedValue(
        page([older], 1, false, {
            sort: 'newest',
            search: 'history',
            category: 'kindness',
        }),
    );
    fireEvent.click(screen.getByRole('option', { name: 'Kindness' }));
    await waitFor(() =>
        expect(request.mock.calls.at(-1)?.[0]).toContain('category=kindness'),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Team recognition' }));
    await waitFor(() =>
        expect(request.mock.calls.at(-1)?.[0]).toContain('recipient_type=team'),
    );
});

it('loads an older idea and opens its canonical selected route while demos remain local', async () => {
    const idea: HubIdea = {
        id: 1,
        title: 'Current suggestion',
        body: 'A practical improvement.',
        category: 'Our people',
        status: 'open',
        status_note: null,
        version: 1,
        author: 'Jamie',
        created_at: '2026-10-16T00:00:00Z',
        can_remove: false,
        support_count: 0,
        supported: false,
        comments: [],
    };
    const older = {
        ...idea,
        id: 250,
        title: 'An earlier worthwhile suggestion',
    };
    const initial = page([idea], 1, true, { sort: 'updated' });
    const { unmount } = render(
        <IdeaBoard
            ideas={[idea]}
            browse={initial}
            moderate={false}
            actorId={1}
        />,
    );
    request.mockResolvedValueOnce(page([older], 2, false, { sort: 'updated' }));
    fireEvent.click(screen.getByRole('button', { name: 'Show more ideas' }));
    await screen.findByRole('button', { name: older.title });
    fireEvent.click(screen.getByRole('button', { name: older.title }));
    expect(visit).toHaveBeenCalledWith('/company-hub?view=ideas&idea=250', {
        preserveScroll: true,
        preserveState: true,
    });
    unmount();
    request.mockClear();
    visit.mockClear();
    render(
        <IdeaBoard ideas={[idea]} browse={initial} moderate={false} readOnly />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Search ideas' }), {
        target: { value: 'absent' },
    });
    expect(screen.getByText('No ideas match these filters.')).toBeVisible();
    expect(request).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: 'Show more ideas' }),
    ).not.toBeInTheDocument();
});

it('keeps local recognition filters locked until every active comment draft is cleared', () => {
    const kudos: HubKudos = {
        id: 1,
        message: 'Current public thanks',
        from: 'Jamie',
        to: 'Aroha',
        to_id: 2,
        category: 'Teamwork',
        created_at: '2026-10-16T00:00:00Z',
        reactions: {},
        replies: [],
        can_reply: true,
    };
    const data = hubData({
        kudos: [kudos, { ...kudos, id: 2 }],
        recognitionBrowse: page([kudos, { ...kudos, id: 2 }], 1, true),
        recognition: {
            actor_id: 1,
            version: 1,
            can_feature: false,
            spotlight: kudos,
            selection_unavailable: false,
        },
    });
    render(<RecognitionBoard data={data} />);
    fireEvent.click(screen.getByRole('button', { name: 'Filters' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Start comment draft 1' }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Start comment draft 2' }),
    );
    expect(
        screen.getByText('Finish or cancel your comment to change filters.'),
    ).toBeVisible();
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toBeDisabled();
    for (const label of [
        'Company value',
        'Recognition team',
        'Recognition order',
    ]) {
        expect(screen.getByRole('combobox', { name: label })).toBeDisabled();
    }
    expect(
        screen.getByRole('button', { name: 'Team recognition' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Read shout-out' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Show more shout-outs' }),
    ).toBeEnabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
        { target: { value: 'Would hide draft' } },
    );
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toHaveValue('');
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel comment draft 1' }),
    );
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel comment draft 2' }),
    );
    expect(
        screen.getByRole('textbox', { name: 'Search shout-outs' }),
    ).toBeEnabled();
    expect(
        screen.queryByText('Finish or cancel your comment to change filters.'),
    ).not.toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});
