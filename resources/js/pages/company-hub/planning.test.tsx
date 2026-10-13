import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EditorialInsights } from './_insights';
import { EditorialCalendar, type PlanningData } from './_planning';

const { request, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { visit },
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
    it('filters calendar items and defaults new plans to the signed-in editor', async () => {
        render(<EditorialCalendar onEvent={vi.fn()} />);
        expect(
            await screen.findByRole('button', { name: 'View plan' }),
        ).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Editorial content type'), {
            target: { value: 'plan' },
        });
        expect(screen.queryByText('Welcome guide')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Plan content' }));
        expect(screen.getByLabelText('Owner')).toHaveValue('2');
        fireEvent.change(screen.getByLabelText('Working title'), {
            target: { value: 'A new story' },
        });
        fireEvent.change(screen.getByLabelText('Intended audience'), {
            target: { value: 'sites' },
        });
        fireEvent.click(screen.getByLabelText('Support office'));
        request.mockResolvedValueOnce({
            ...data.plans[0],
            title: 'A new story',
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
            new Error(
                'This plan has changed. Reload the calendar before editing it again.',
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
            })
            .mockResolvedValueOnce({ url: '/company-hub/manage/42/edit' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save & start draft' }),
        );
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith('/company-hub/manage/42/edit'),
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/editor/plans/8/start',
            'POST',
            { version: 5 },
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
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Working title')).toHaveValue(
            'Unsaved idea',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
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
        fireEvent.change(screen.getByLabelText('Insights period'), {
            target: { value: '7' },
        });
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
