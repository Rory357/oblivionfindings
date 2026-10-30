/* eslint-disable no-restricted-syntax -- Native controls isolate the navigation contract in this test harness. */
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { useEffect, useState, type AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PeopleDirectory } from './_people';
import { EditorialCalendar } from './_planning';
import type { ReaderFrame } from './_reader-trail';
import {
    HubReadingProvider,
    useHubListControls,
    useHubReading,
} from './_reading-navigation';
import { HubSearchResults } from './_search';
import { hubData, hubPerson, hubStory } from './_test-fixtures';

type Visit = {
    href: string;
    options: {
        onBeforeUpdate?: (page: {
            url: string;
            props: { auth: { user: { id: number } } };
            rememberedState: Record<string, unknown>;
        }) => void;
        onStart: () => void;
        onSuccess: () => void;
        onFinish: () => void;
    };
};
const history = vi.hoisted(() => ({
    entries: new Map<string, unknown>(),
    visits: [] as Visit[],
    veto: false,
    remember: vi.fn(),
    calendarRequest: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: history.calendarRequest,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: {
        remember: history.remember,
        visit: (href: string, options: Visit['options']) => {
            history.visits.push({ href, options });
            if (!history.veto) options.onStart();
        },
    },
    // Like Inertia: restore only on mount, remember each change in the current entry.
    useRemember: (initial: unknown, key: string) => {
        const [state, setState] = useState(
            () => history.entries.get(window.location.href + key) ?? initial,
        );
        useEffect(() => history.remember(state, key), [state, key]);
        return [state, setState];
    },
}));

function Reader() {
    const reading = useHubReading()!;
    const [filters, setFilters] = useHubListControls('people', {
        query: '',
        page: 1,
    });
    return (
        <main onClickCapture={reading.capture}>
            <label>
                Find colleague
                <input
                    value={filters.query}
                    onChange={(e) =>
                        setFilters((old) => ({ ...old, query: e.target.value }))
                    }
                />
            </label>
            <output aria-label="Current page">{filters.page}</output>
            <button
                onClick={() => {
                    setFilters((old) => ({ ...old, page: old.page + 1 }));
                    setFilters((old) => ({ ...old, page: old.page + 1 }));
                }}
            >
                Next two
            </button>
            <a href="/company-hub/people/21" data-hub-return-key="person:21">
                Open profile
            </a>
            <a href="/company-hub/read/guide" data-hub-return-key="page:50">
                Open guide
            </a>
            <a href="/company-hub/events/31?date=2026-10-20">Open event</a>
            <a href="/company-hub/documents/41">Open document</a>
            <a href="/company-hub?view=updates&announcement=4">
                Open announcement
            </a>
            <a href="/company-hub?view=ideas&idea=17">Open insight idea</a>
            <a href="/company-hub?view=shoutouts&recognition=81#kudos-81">
                Open shout-out
            </a>
            <a href="/company-hub/read/guide#block-welcome-poll">
                Open insight poll
            </a>
            <a href="/company-hub#block-welcome-poll">Open Home poll</a>
            <h2 id="block-welcome-poll" tabIndex={-1}>
                Welcome poll
            </h2>
            <a href="/company-hub?view=events">Events tab</a>
            <a href="/company-hub/read/guide" target="_blank">
                New tab
            </a>
            <a href="/company-hub/read/guide" download>
                Download
            </a>
            <a href="https://other.test/read/guide">External</a>
            <a href="/company-hub/manage/9/edit">Edit page</a>
            <button onClick={reading.back} disabled={!reading.returnLabel}>
                {reading.returnLabel ?? 'No return'}
            </button>
            <div {...{ 'scroll-region': '' }} data-testid="scroll-region" />
        </main>
    );
}
const view = (label: ReaderFrame['label'] = 'people', actorId = 11) => (
    <HubReadingProvider key={actorId} actorId={actorId} label={label}>
        <Reader />
    </HubReadingProvider>
);
const latest = () => history.visits.at(-1)!;
const remembered = (url = window.location.href, actor = 11) =>
    history.entries.get(url + `company-hub:reading:${actor}`) as {
        trail: { frames: ReaderFrame[] };
        controls: Record<string, Record<string, unknown>>;
    };
const frames = [] as FrameRequestCallback[];
const arrive = (visit: Visit) =>
    act(() => {
        window.history.pushState({}, '', visit.href);
        visit.options.onSuccess();
        visit.options.onFinish();
    });

beforeEach(() => {
    vi.clearAllMocks();
    history.entries.clear();
    history.visits = [];
    history.veto = false;
    frames.length = 0;
    window.history.replaceState({}, '', '/company-hub?view=people');
    history.remember.mockImplementation((state, key) => {
        history.entries.set(window.location.href + key, structuredClone(state));
    });
    vi.stubGlobal('requestAnimationFrame', (fn: FrameRequestCallback) => {
        frames.push(fn);
        return frames.length;
    });
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
});
afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Connected Hub reading', () => {
    it('restores the editorial month, day and type after an editor visit without sharing another actor’s controls', async () => {
        window.history.replaceState({}, '', '/company-hub?view=publishing');
        history.calendarRequest.mockImplementation(async (url: string) => {
            const month = new URL(url, window.location.origin).searchParams.get(
                'month',
            )!;
            return {
                month,
                viewer_id: 11,
                can_create: false,
                can_assign: false,
                owners: [],
                plans: [],
                sites: [],
                departments: [],
                items: [
                    {
                        key: 'page:2',
                        type: 'page',
                        date: `${month}-13`,
                        title: 'Company guide',
                        owner: 'Editor',
                        status: 'Publication',
                        url: '/company-hub/manage/2/edit',
                    },
                ],
            };
        });
        const calendar = (actor = 11) => (
            <HubReadingProvider key={actor} actorId={actor} label="publishing">
                <EditorialCalendar onEvent={vi.fn()} />
            </HubReadingProvider>
        );
        let rendered = render(calendar());
        fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
        fireEvent.click(
            await screen.findByRole('button', { name: /13 .*, 1 item$/ }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Editorial content type' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Pages & stories',
            }),
        );
        const requestedMonth = new URL(
            history.calendarRequest.mock.calls.at(-1)![0],
            window.location.origin,
        ).searchParams.get('month');
        expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute(
            'href',
            '/company-hub/manage/2/edit',
        );
        rendered.unmount();
        window.history.pushState({}, '', '/company-hub/manage/2/edit');
        // Browser Back remounts the calendar using the origin entry's remembered controls.
        window.history.replaceState({}, '', '/company-hub?view=publishing');
        rendered = render(calendar());
        expect(
            await screen.findByRole('link', { name: 'Open' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /13 .*, 1 item$/ }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('combobox', { name: 'Editorial content type' }),
        ).toHaveTextContent('Pages & stories');
        expect(history.calendarRequest).toHaveBeenLastCalledWith(
            `/company-hub/editor/planning?month=${requestedMonth}`,
            'GET',
        );
        rendered.unmount();
        render(calendar(12));
        expect(
            screen.getByRole('combobox', { name: 'Editorial content type' }),
        ).toHaveTextContent('All content');
        const today = new Date().toLocaleDateString('en-CA', {
            timeZone: 'Pacific/Auckland',
        });
        expect(history.calendarRequest).toHaveBeenLastCalledWith(
            `/company-hub/editor/planning?month=${today.slice(0, 7)}`,
            'GET',
        );
    });
    it.each([
        '/company-hub?view=resources',
        '/company-hub/read/guide#block-welcome-poll',
    ])(
        'hands a document trail across a component unmount and restores the return to %s',
        (origin) => {
            window.history.replaceState({}, '', origin);
            let page = render(view('resources'));
            fireEvent.change(screen.getByRole('textbox'), {
                target: { value: 'PDF search' },
            });
            fireEvent.click(
                screen.getByRole('link', { name: 'Open document' }),
            );
            const swap = (label: ReaderFrame['label']) => {
                const visit = latest();
                const response = {
                    url: visit.href,
                    props: { auth: { user: { id: 11 } } },
                    rememberedState: {} as Record<string, unknown>,
                };
                act(() => visit.options.onBeforeUpdate?.(response));
                page.unmount();
                window.history.pushState({}, '', visit.href);
                for (const [key, value] of Object.entries(
                    response.rememberedState,
                ))
                    history.entries.set(window.location.href + key, value);
                page = render(view(label));
                act(() => {
                    visit.options.onSuccess();
                    visit.options.onFinish();
                });
            };
            swap('document');
            expect(
                screen.getByRole('button', { name: 'Back to resources' }),
            ).toBeEnabled();
            expect(screen.getByRole('textbox')).toHaveValue('PDF search');
            fireEvent.click(
                screen.getByRole('button', { name: 'Back to resources' }),
            );
            swap('resources');
            act(() => frames.splice(0).forEach((callback) => callback(0)));
            expect(
                screen.getByRole('button', { name: 'No return' }),
            ).toBeDisabled();
            expect(screen.getByRole('textbox')).toHaveValue('PDF search');
            expect(
                screen.getByRole('link', { name: 'Open document' }),
            ).toHaveFocus();
        },
    );
    it.each([
        ['Open document', '/company-hub/documents/41'],
        ['Open announcement', '/company-hub?view=updates&announcement=4'],
        ['Open insight idea', '/company-hub?view=ideas&idea=17'],
        [
            'Open shout-out',
            '/company-hub?view=shoutouts&recognition=81#kudos-81',
        ],
        ['Open insight poll', '/company-hub/read/guide#block-welcome-poll'],
        ['Open Home poll', '/company-hub#block-welcome-poll'],
    ])(
        'returns to publishing after opening an exact insight: %s',
        (label, url) => {
            window.history.replaceState({}, '', '/company-hub?view=publishing');
            render(view('publishing'));
            fireEvent.click(screen.getByRole('link', { name: label }));
            expect(latest().href).toBe(url);
            arrive(latest());
            if (url.includes('#block-')) {
                frames.splice(0).forEach((callback) => callback(0));
                expect(
                    screen.getByRole('heading', { name: 'Welcome poll' }),
                ).toHaveFocus();
            }
            expect(
                screen.getByRole('button', { name: 'Back to publishing' }),
            ).toBeEnabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Back to publishing' }),
            );
            expect(latest().href).toBe('/company-hub?view=publishing');
        },
    );
    it('restores the actual directory controls after the directory unmounts for a profile', () => {
        function DirectoryPage({ listing }: { listing: boolean }) {
            const reading = useHubReading()!;
            return (
                <main onClickCapture={reading.capture}>
                    {listing ? (
                        <PeopleDirectory
                            people={[
                                hubPerson,
                                {
                                    ...hubPerson,
                                    id: 22,
                                    name: 'Morgan Reed',
                                    site: 'South',
                                },
                            ]}
                        />
                    ) : (
                        <button onClick={reading.back}>
                            {reading.returnLabel}
                        </button>
                    )}
                </main>
            );
        }
        const directory = (listing: boolean) => (
            <HubReadingProvider
                actorId={11}
                label={listing ? 'people' : 'profile'}
            >
                <DirectoryPage listing={listing} />
            </HubReadingProvider>
        );
        const page = render(directory(true));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
            { target: { value: 'Aroha' } },
        );
        fireEvent.click(screen.getByRole('combobox', { name: 'Site' }));
        fireEvent.click(screen.getByRole('option', { name: 'North' }));
        fireEvent.click(screen.getByRole('link', { name: /Aroha Bennett/ }));
        arrive(latest());
        page.rerender(directory(false));
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back to people' }));
        arrive(latest());
        page.rerender(directory(true));
        act(() => frames.splice(0).forEach((frame) => frame(0)));
        expect(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
        ).toHaveValue('Aroha');
        expect(
            screen.getByRole('combobox', { name: 'Site' }),
        ).toHaveTextContent('North');
        expect(
            screen.queryByRole('link', { name: /Morgan Reed/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Aroha Bennett/ }),
        ).toHaveFocus();
    });

    it('returns through a guide and profile to the original directory with its filters and focus', () => {
        const page = render(view());
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Aroha' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Next two' }));
        const region = screen.getByTestId('scroll-region');
        region.scrollTop = 240;
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
        arrive(latest());
        page.rerender(view('profile'));
        expect(
            screen.getByRole('button', { name: 'Back to people' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('link', { name: 'Open guide' }));
        arrive(latest());
        page.rerender(view('guide'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to the work profile' }),
        );
        expect(latest().href).toBe('/company-hub/people/21');
        arrive(latest());
        page.rerender(view('profile'));
        fireEvent.click(screen.getByRole('button', { name: 'Back to people' }));
        expect(latest().href).toBe('/company-hub?view=people');
        region.scrollTop = 0;
        arrive(latest());
        page.rerender(view());
        act(() => frames.splice(0).forEach((frame) => frame(0)));
        expect(screen.getByRole('textbox')).toHaveValue('Aroha');
        expect(screen.getByLabelText('Current page')).toHaveTextContent('3');
        expect(
            screen.getByRole('link', { name: 'Open profile' }),
        ).toHaveFocus();
        expect(region.scrollTop).toBe(240);
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('keeps the departing browser entry intact, restores history on remount, and isolates another account', () => {
        let page = render(view());
        const origin = window.location.href;
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'North team' },
        });
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        expect(remembered(origin).trail.frames).toEqual([]);
        arrive(latest());
        expect(remembered(origin).trail.frames).toEqual([]);
        expect(remembered().trail.frames).toHaveLength(1);
        page.unmount();
        page = render(view('profile'));
        expect(
            screen.getByRole('button', { name: 'Back to people' }),
        ).toBeEnabled();
        expect(screen.getByRole('textbox')).toHaveValue('North team');
        page.unmount();
        window.history.replaceState({}, '', origin);
        page = render(view());
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
        expect(screen.getByRole('textbox')).toHaveValue('North team');
        page.rerender(view('people', 22));
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('clears the return trail for a main tab while retaining list controls', () => {
        const page = render(view());
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Aroha' },
        });
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        arrive(latest());
        page.rerender(view('profile'));
        fireEvent.click(screen.getByRole('link', { name: 'Events tab' }));
        arrive(latest());
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
        expect(screen.getByRole('textbox')).toHaveValue('Aroha');
    });

    it('does not change history for vetoed or failed navigation', () => {
        render(view());
        const original = structuredClone(remembered());
        history.veto = true;
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        expect(remembered()).toEqual(original);
        history.veto = false;
        fireEvent.click(screen.getByRole('link', { name: 'Open guide' }));
        act(() => latest().options.onFinish());
        expect(remembered()).toEqual(original);
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('prevents repeated Back from skipping an origin and allows retry after a failed return', () => {
        render(view());
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        arrive(latest());
        const back = screen.getByRole('button', { name: 'Back to people' });
        fireEvent.click(back);
        const count = history.visits.length;
        fireEvent.click(back);
        expect(history.visits).toHaveLength(count);
        act(() => latest().options.onFinish());
        expect(back).toBeEnabled();
        fireEvent.click(back);
        expect(history.visits).toHaveLength(count + 1);
        arrive(latest());
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('keeps an active Back request when another attempted visit is vetoed', () => {
        render(view());
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        arrive(latest());
        fireEvent.click(screen.getByRole('button', { name: 'Back to people' }));
        const returning = latest();
        history.veto = true;
        fireEvent.click(screen.getByRole('link', { name: 'Open guide' }));
        arrive(returning);
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
        history.veto = false;
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        arrive(latest());
        fireEvent.click(screen.getByRole('button', { name: 'Back to people' }));
        expect(latest().href).toBe('/company-hub?view=people');
    });

    it('ignores obsolete completion and focus restoration after another navigation', () => {
        render(view());
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        const old = latest();
        fireEvent.click(screen.getByRole('link', { name: 'Open guide' }));
        arrive(latest());
        const current = structuredClone(remembered());
        act(() => {
            old.options.onSuccess();
            old.options.onFinish();
        });
        expect(remembered()).toEqual(current);
        fireEvent.click(screen.getByRole('button', { name: 'Back to people' }));
        arrive(latest());
        fireEvent.click(screen.getByRole('link', { name: 'Open event' }));
        arrive(latest());
        const eventLink = screen.getByRole('link', { name: 'Open event' });
        eventLink.focus();
        act(() => frames.splice(0).forEach((frame) => frame(0)));
        expect(eventLink).toHaveFocus();
        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    it('preserves native modified, download, external and editing links', () => {
        render(view());
        // Observe default prevention at the end of bubbling, then suppress jsdom navigation.
        const prevented: boolean[] = [];
        const finish = (event: MouseEvent) => {
            prevented.push(event.defaultPrevented);
            event.preventDefault();
        };
        document.addEventListener('click', finish);
        try {
            for (const modifiers of [
                { ctrlKey: true },
                { metaKey: true },
                { shiftKey: true },
                { altKey: true },
                { button: 1 },
            ])
                fireEvent.click(
                    screen.getByRole('link', { name: 'Open profile' }),
                    modifiers,
                );
            for (const name of ['New tab', 'Download', 'External', 'Edit page'])
                fireEvent.click(screen.getByText(name));
            expect(prevented).toEqual(Array(9).fill(false));
            expect(history.visits).toHaveLength(0);
        } finally {
            document.removeEventListener('click', finish);
        }
    });

    it('rejects remembered controls with the wrong type and another actor’s history', () => {
        const initial = render(view());
        const session = (remembered() as unknown as { session: string })
            .session;
        initial.unmount();
        const key = window.location.href + 'company-hub:reading:11';
        history.entries.set(key, {
            session,
            trail: { actor_id: 11, frames: [] },
            controls: { people: { query: 22, page: 'broken' } },
        });
        const page = render(view());
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(screen.getByLabelText('Current page')).toHaveTextContent('1');
        fireEvent.click(screen.getByRole('button', { name: 'Next two' }));
        expect(screen.getByLabelText('Current page')).toHaveTextContent('3');
        page.unmount();
        history.entries.set(key, {
            session,
            trail: {
                actor_id: 22,
                frames: [{ href: '/company-hub?view=people', label: 'people' }],
            },
            controls: { people: { query: 'Private query', page: 2 } },
        });
        render(view());
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('resets presentation choices and the reading trail from a previous document after reload', () => {
        const page = render(view());
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Old search' },
        });
        fireEvent.click(screen.getByRole('link', { name: 'Open profile' }));
        arrive(latest());
        const previous = structuredClone(remembered());
        page.unmount();
        history.entries.set(window.location.href + 'company-hub:reading:11', {
            ...previous,
            session: 'previous-document',
        });
        render(view('profile'));
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'No return' }),
        ).toBeDisabled();
    });

    it('retains the selected search category across a reader and resets it for a different search', () => {
        const searchView = (query = 'Learning') => (
            <HubReadingProvider actorId={11} label="search">
                <HubSearchResults
                    data={hubData({
                        search: query,
                        pages: [hubStory],
                        people: [hubPerson],
                    })}
                    onEvent={() => {}}
                />
            </HubReadingProvider>
        );
        const page = render(searchView());
        const filter = within(
            screen.getByLabelText('Filter search results'),
        ).getByRole('button', { name: /People/ });
        fireEvent.click(filter);
        expect(filter).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.queryByRole('region', { name: 'Stories & pages results' }),
        ).not.toBeInTheDocument();
        page.unmount();
        const restored = render(searchView());
        expect(
            within(screen.getByLabelText('Filter search results')).getByRole(
                'button',
                { name: /People/ },
            ),
        ).toHaveAttribute('aria-pressed', 'true');
        restored.rerender(searchView('New search'));
        expect(
            within(screen.getByLabelText('Filter search results')).getByRole(
                'button',
                { name: /All results/ },
            ),
        ).toHaveAttribute('aria-pressed', 'true');
    });
});
