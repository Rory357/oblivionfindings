import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { EditorialInsights } from './_insights';
import { EditorialCalendar, type PlanningData } from './_planning';

const { request, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    visit: vi.fn(),
}));
const choose = (label: string, option: string) => {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
};
const choosePeriod = () => {
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Insights period' }),
        { key: 'ArrowDown' },
    );
    fireEvent.click(screen.getByRole('option', { name: 'Last 7 days' }));
};
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { visit, on: () => vi.fn() },
}));
const today = new Date().toLocaleDateString('en-CA', {
    timeZone: 'Pacific/Auckland',
});
const data: PlanningData = {
    month: today.slice(0, 7),
    viewer_id: 2,
    owners: [
        { id: 1, name: 'Another editor' },
        { id: 2, name: 'Current editor' },
    ],
    can_create: true,
    can_assign: true,
    sites: [{ id: 3, name: 'Support office' }],
    departments: [],
    plans: [
        {
            id: 8,
            title: 'Learning together',
            target_date: today,
            owner_id: 2,
            owner: 'Current editor',
            template: 'guide',
            brief: 'An internal briefing',
            audience: { type: 'all', ids: [] },
            version: 4,
        },
    ],
    items: [
        {
            key: 'plan:8',
            type: 'plan',
            plan_id: 8,
            date: today,
            title: 'Learning together',
            owner: 'Current editor',
            status: 'Planned',
        },
        {
            key: 'page:1',
            type: 'page',
            date: today,
            title: 'Welcome guide',
            owner: 'Current editor',
            status: 'Publication',
            url: '/company-hub/manage/1/edit',
        },
    ],
};
beforeEach(() => {
    request.mockReset().mockResolvedValue(data);
    visit.mockReset();
});

describe('Editorial calendar', () => {
    it('opens a focused page workspace and returns to the same filtered calendar', async () => {
        const onWorkspaceChange = vi.fn();
        render(
            <EditorialCalendar
                onEvent={vi.fn()}
                onWorkspaceChange={onWorkspaceChange}
            />,
        );
        const open = await screen.findByRole('button', { name: 'View plan' });
        choose('Editorial content type', 'Plans');
        open.focus();
        fireEvent.click(open);
        expect(
            screen.getByRole('heading', {
                name: 'Make space for the next good story',
            }),
        ).toHaveFocus();
        expect(
            screen.getByRole('region', { name: 'Editorial plan' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Next month' }),
        ).not.toBeInTheDocument();
        expect(onWorkspaceChange).toHaveBeenLastCalledWith(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to calendar' }),
        );
        await waitFor(() => expect(open).toHaveFocus());
        expect(
            screen.getByLabelText('Editorial content type'),
        ).toHaveTextContent('Plans');
        expect(onWorkspaceChange).toHaveBeenLastCalledWith(false);
    });

    it('protects unsaved and pending plans and ignores receipts after leaving the workspace', async () => {
        const rendered = render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'View plan' }),
        );
        fireEvent.change(screen.getByLabelText('Brief'), {
            target: { value: 'Keep this work' },
        });
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        let complete!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        const start = screen.getByRole('button', {
            name: 'Save & start draft',
        });
        fireEvent.click(start);
        fireEvent.click(start);
        expect(
            request.mock.calls.filter(([, method]) => method === 'PUT'),
        ).toHaveLength(1);
        expect(
            screen.getByRole('button', { name: 'Back to calendar' }),
        ).toBeDisabled();
        expect(screen.getByLabelText('Brief')).toBeDisabled();
        rendered.unmount();
        complete({ ...data.plans[0], version: 5, actor_id: 2 });
        await Promise.resolve();
        expect(
            request.mock.calls.filter(([url]) => url.endsWith('/start')),
        ).toHaveLength(0);
        expect(visit).not.toHaveBeenCalled();
    });

    it('retries an uncertain draft opening without saving the plan again', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'View plan' }),
        );
        request
            .mockResolvedValueOnce({
                ...data.plans[0],
                version: 5,
                actor_id: 2,
            })
            .mockRejectedValueOnce(new Error('Connection lost'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save & start draft' }),
        );
        expect(
            await screen.findByRole('button', { name: 'Retry opening draft' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Save plan' }),
        ).toBeDisabled();
        request.mockResolvedValueOnce({
            url: '/company-hub/manage/42/edit',
            page_id: 42,
            actor_id: 2,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry opening draft' }),
        );
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith('/company-hub/manage/42/edit'),
        );
        expect(
            request.mock.calls.filter(([, method]) => method === 'PUT'),
        ).toHaveLength(1);
        expect(
            request.mock.calls.filter(([url]) => url.endsWith('/start')),
        ).toHaveLength(2);
    });

    it.each([
        { actor_id: 9, version: 5 },
        { actor_id: 2, version: 7 },
        { actor_id: 2, version: 5, id: 91 },
    ])(
        'does not accept a mismatched save acknowledgement %j',
        async (patch) => {
            render(<EditorialCalendar onEvent={vi.fn()} />);
            fireEvent.click(
                await screen.findByRole('button', { name: 'View plan' }),
            );
            request.mockResolvedValueOnce({ ...data.plans[0], ...patch });
            fireEvent.click(
                screen.getByRole('button', { name: 'Save & start draft' }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'couldn’t confirm',
            );
            expect(
                screen.getByRole('button', { name: 'Save plan' }),
            ).toBeDisabled();
            expect(visit).not.toHaveBeenCalled();
            expect(
                request.mock.calls.filter(([url]) => url.endsWith('/start')),
            ).toHaveLength(0);
        },
    );

    it('retains writing and prevents duplicate creation after an uncertain save', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Plan content' }),
        );
        fireEvent.change(screen.getByLabelText('Working title'), {
            target: { value: 'A useful story' },
        });
        fireEvent.change(screen.getByLabelText('Brief'), {
            target: { value: 'A useful private brief' },
        });
        request.mockRejectedValueOnce(new Error('Connection lost'));
        fireEvent.click(screen.getByRole('button', { name: 'Save plan' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Connection lost',
        );
        expect(screen.getByLabelText('Brief')).toHaveValue(
            'A useful private brief',
        );
        expect(
            screen.getByRole('button', { name: 'Save plan' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to calendar' }),
        );
        expect(
            screen.getByRole('button', { name: 'Keep editing' }),
        ).toBeVisible();
        expect(
            request.mock.calls.filter(([, method]) => method === 'POST'),
        ).toHaveLength(1);
        request.mockResolvedValueOnce({
            ...data,
            items: [
                ...data.items,
                {
                    ...data.items[0],
                    key: 'plan:9',
                    plan_id: 9,
                    title: 'A useful story',
                },
            ],
            plans: [
                ...data.plans,
                {
                    ...data.plans[0],
                    id: 9,
                    title: 'A useful story',
                    version: 1,
                },
            ],
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                `/company-hub/editor/planning?month=${data.month}`,
                'GET',
            ),
        );
        expect(
            (await screen.findAllByText('A useful story')).length,
        ).toBeGreaterThan(0);
    });

    it('filters calendar items and defaults new plans to the signed-in editor', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        expect(
            await screen.findByRole('button', { name: 'View plan' }),
        ).toBeInTheDocument();
        choose('Editorial content type', 'Plans');
        expect(screen.queryByText('Welcome guide')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Plan content' }));
        expect(screen.getByLabelText('Owner')).toHaveTextContent(
            'Current editor',
        );
        fireEvent.change(screen.getByLabelText('Working title'), {
            target: { value: 'A new story' },
        });
        fireEvent.change(screen.getByLabelText('Brief'), {
            target: { value: 'A useful introduction for colleagues.' },
        });
        choose('Intended audience', 'Selected sites');
        fireEvent.click(screen.getByLabelText('Support office'));
        request.mockResolvedValueOnce({
            ...data.plans[0],
            title: 'A new story',
            version: 1,
            actor_id: 2,
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save plan' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/editor/plans',
                'POST',
                expect.objectContaining({
                    title: 'A new story',
                    owner_id: 2,
                    audience: { type: 'sites', ids: [3] },
                }),
            ),
        );
        expect(
            await screen.findByText(
                'Plan saved. It stays private until its linked page is published.',
            ),
        ).toBeInTheDocument();
    });

    it('preserves work after a conflict and uses the saved version to start a draft', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'View plan' }),
        );
        fireEvent.change(screen.getByLabelText('Brief'), {
            target: { value: 'Keep these edits' },
        });
        request.mockRejectedValueOnce(
            new HubRequestError(
                'This plan has changed. Reload the calendar before editing it again.',
                409,
            ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save plan' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This plan has changed',
        );
        expect(screen.getByLabelText('Brief')).toHaveValue('Keep these edits');
        request
            .mockResolvedValueOnce({
                ...data.plans[0],
                brief: 'Keep these edits',
                version: 5,
                actor_id: 2,
            })
            .mockResolvedValueOnce({
                url: '/company-hub/manage/42/edit',
                page_id: 42,
                actor_id: 2,
            });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save & start draft' }),
        );
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith('/company-hub/manage/42/edit'),
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/editor/plans/8/start',
            'POST',
            { version: 5, actor_id: 2 },
        );
    });

    it('requires an explicit discard of unsaved plan changes', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'View plan' }),
        );
        fireEvent.change(screen.getByLabelText('Working title'), {
            target: { value: 'Unsaved idea' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('region', { name: 'Editorial plan' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Working title')).toHaveValue(
            'Unsaved idea',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(
            screen.queryByRole('region', { name: 'Editorial plan' }),
        ).not.toBeInTheDocument();
    });

    it('does not show old month responses after moving to another month', async () => {
        let finish!: (value: PlanningData) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        render(<EditorialCalendar onEvent={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
        finish({
            ...data,
            items: [{ ...data.items[0], title: 'Obsolete response' }],
        });
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Plan content' }),
            ).toBeEnabled(),
        );
        expect(screen.queryByText('Obsolete response')).not.toBeInTheDocument();
    });
});

describe('Period insights', () => {
    it('keeps poll counts private until released while showing only aggregate idea support', async () => {
        const insights = {
            days: 28,
            since: `${today}T00:00:00Z`,
            until: `${today}T01:00:00Z`,
            readers: 0,
            pages: [],
            health: { reports: 0, overdue: 0, review: 0 },
            events: [],
            ideas: [
                {
                    id: 3,
                    title: 'Welcome pack',
                    status: 'reviewing',
                    support_count: 4,
                    supports_in_period: 2,
                    url: '/company-hub?view=ideas&idea=3',
                },
            ],
            polls: [
                {
                    page_id: 4,
                    block_id: 'poll',
                    key: 'one',
                    question: 'Our next activity?',
                    closed: false,
                    results_visible: false,
                    response_count: 91,
                    responses_in_period: 73,
                    url: '/company-hub/read/learning#block-poll',
                },
            ],
        };
        request.mockResolvedValue(insights);
        render(
            <EditorialInsights onCalendar={vi.fn()} onResources={vi.fn()} />,
        );
        fireEvent.click(
            await screen.findByRole('tab', { name: 'Participation' }),
        );
        expect(await screen.findByText('Welcome pack')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'View progress: Welcome pack' }),
        ).toHaveAttribute('href', '/company-hub?view=ideas&idea=3');
        expect(
            screen.getByRole('link', { name: 'View poll: Our next activity?' }),
        ).toHaveAttribute('href', '/company-hub/read/learning#block-poll');
        expect(
            screen.getByText(
                'reviewing · 4 supporting · 2 added in this period',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Open · Results available after you vote or voting closes',
            ),
        ).toBeVisible();
        expect(
            screen.queryByText(/91 responses|73 in this period/),
        ).not.toBeInTheDocument();
        request.mockResolvedValue({
            ...insights,
            polls: [
                {
                    ...insights.polls[0],
                    closed: true,
                    results_visible: true,
                    response_count: 6,
                    responses_in_period: 3,
                },
            ],
        });
        choosePeriod();
        expect(
            await screen.findByText('Closed · 6 responses · 3 in this period'),
        ).toBeVisible();
    });
    it('links only available canonical event occurrences and removes withdrawn insight links on refresh', async () => {
        const insights = {
            days: 28,
            since: `${today}T00:00:00Z`,
            until: `${today}T01:00:00Z`,
            readers: 0,
            pages: [],
            health: { reports: 0, overdue: 0, review: 0 },
            events: [
                {
                    id: 'event-7-2026-10-20',
                    title: 'Garden afternoon',
                    start: '2026-10-20T01:00:00Z',
                    going: 8,
                    waiting: 2,
                    capacity: 10,
                    url: '/company-hub/events/7?date=2026-10-20',
                },
                {
                    id: 'event-8',
                    title: 'Draft event',
                    start: '2026-10-21T01:00:00Z',
                    going: 0,
                    waiting: 0,
                    capacity: null,
                    url: null,
                },
            ],
            ideas: [
                {
                    id: 1,
                    title: 'Unsafe destination',
                    status: 'open',
                    support_count: 0,
                    supports_in_period: 0,
                    url: 'https://unrelated.test/company-hub?view=ideas&idea=1',
                },
            ],
            polls: [
                {
                    page_id: 1,
                    block_id: 'welcome-survey',
                    key: 'key',
                    question: 'Welcome survey',
                    closed: false,
                    results_visible: false,
                    response_count: null,
                    responses_in_period: null,
                    url: '/company-hub#block-welcome-survey',
                },
            ],
        };
        request.mockResolvedValueOnce(insights);
        render(
            <EditorialInsights onCalendar={vi.fn()} onResources={vi.fn()} />,
        );
        fireEvent.click(
            await screen.findByRole('tab', { name: 'Participation' }),
        );
        expect(
            await screen.findByRole('link', {
                name: 'View event: Garden afternoon',
            }),
        ).toHaveAttribute('href', '/company-hub/events/7?date=2026-10-20');
        expect(
            screen.queryByRole('link', { name: 'View event: Draft event' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Unsafe destination/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'View poll: Welcome survey' }),
        ).toHaveAttribute('href', '/company-hub#block-welcome-survey');
        request.mockResolvedValueOnce({ ...insights, events: [], polls: [] });
        choosePeriod();
        await screen.findByText('No published polls to show yet.');
        expect(
            screen.queryByRole('link', { name: /View event:|View poll:/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('tab', { name: 'Participation' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.queryByRole('heading', { name: 'Pages people explore' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Search topics' }));
        expect(screen.getByRole('tabpanel')).toHaveTextContent(
            'Individual searches are never shown here.',
        );
    });
    it('fetches recorded period aggregates and routes workload actions', async () => {
        const insights = {
            days: 28,
            since: `${today}T00:00:00Z`,
            until: `${today}T01:00:00Z`,
            readers: 2,
            pages: [
                {
                    id: 4,
                    title: 'Learning guide',
                    readers: 2,
                    saves: 1,
                    comments: 0,
                    reactions: 1,
                    helpful: 0,
                },
            ],
            health: { reports: 3, overdue: 2, review: 1 },
            events: [],
        };
        request.mockResolvedValue(insights);
        const onCalendar = vi.fn();
        const onResources = vi.fn();
        render(
            <EditorialInsights
                onCalendar={onCalendar}
                onResources={onResources}
            />,
        );
        expect(
            await screen.findByText(
                '3 open resource reports · 2 overdue reviews · 1 awaiting approval',
            ),
        ).toBeInTheDocument();
        choosePeriod();
        await waitFor(() =>
            expect(request).toHaveBeenLastCalledWith(
                '/company-hub/editor/insights?days=7',
                'GET',
            ),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review resources' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Plan next update' }),
        );
        expect(onCalendar).toHaveBeenCalledOnce();
        expect(onResources).toHaveBeenCalledOnce();
        const details = screen
            .getByText('Activity for all editable pages')
            .closest('details')!;
        fireEvent.click(
            within(details).getByText('Activity for all editable pages'),
        );
        expect(
            within(details).getByRole('link', { name: 'Learning guide' }),
        ).toHaveAttribute('href', '/company-hub/manage/4/edit');
    });
});
