import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { WorkflowAction } from './BoardPriorityCard';
import {
    PriorityOverviewPanel,
    type PriorityPagination,
} from './PriorityOverviewPanel';

vi.mock('axios', () => ({
    default: { get: vi.fn() },
}));
vi.mock('@/routes/governance/dashboard', () => ({
    data: { url: () => '/governance/dashboard/data' },
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));
vi.mock('./BoardPriorityCard', () => ({
    BoardPriorityCard: ({ action }: { action: WorkflowAction }) => (
        <div data-testid="priority-card">{action.title}</div>
    ),
}));

function makeActions(from: number, count: number, areaKey = 'action_items'): WorkflowAction[] {
    return Array.from({ length: count }, (_, i) => ({
        id: `action-item:${from + i}`,
        area: 'Action Items',
        area_key: areaKey,
        title: `Priority ${from + i}`,
        detail: 'Synthetic',
        priority: 'high',
        status: 'overdue',
        due_date: null,
        action_label: 'Open Action',
        action_url: `/governance/actions/${from + i}`,
        owner: null,
    })) as WorkflowAction[];
}

function pagination(overrides: Partial<PriorityPagination>): PriorityPagination {
    return {
        tab: 'all',
        page: 1,
        per_page: 100,
        total: 128,
        last_page: 2,
        from: 1,
        to: 100,
        has_more: true,
        ...overrides,
    };
}

describe('PriorityOverviewPanel', () => {
    afterEach(() => {
        vi.mocked(axios.get).mockReset();
    });

    it('reaches every counted priority beyond the first server page', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                workflow: {
                    actions: makeActions(101, 28),
                    pagination: pagination({ page: 2, from: 101, to: 128, has_more: false }),
                },
            },
        });

        render(
            <PriorityOverviewPanel
                actions={makeActions(1, 100)}
                summary={{
                    total: 128,
                    critical: 0,
                    overdue: 128,
                    by_tab: { all: 128, meetings: 0, actions: 128, risks: 0, compliance: 0, policies: 0 },
                }}
                pagination={pagination({})}
            />,
        );

        expect(screen.getAllByTestId('priority-card')).toHaveLength(8);

        fireEvent.click(screen.getByRole('button', { name: 'Show all 128 priorities' }));
        expect(screen.getAllByTestId('priority-card')).toHaveLength(100);
        expect(screen.getByText('Showing 100 of 128 priorities')).toBeInTheDocument();
        // The first page is already loaded: no request until more is asked for.
        expect(axios.get).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Load 28 more' }));

        await waitFor(() => {
            expect(screen.getAllByTestId('priority-card')).toHaveLength(128);
        });
        expect(axios.get).toHaveBeenCalledWith('/governance/dashboard/data', {
            params: { section: 'priorities', tab: 'all', page: 2 },
        });
        expect(screen.getByText('Showing 128 of 128 priorities')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Load \d+ more/ })).not.toBeInTheDocument();
        expect(screen.getByText('Priority 128')).toBeInTheDocument();
    });

    it('reports a failed page load without claiming the items were shown', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('offline'));

        render(
            <PriorityOverviewPanel
                actions={makeActions(1, 100)}
                summary={{ total: 128, critical: 0, overdue: 0 }}
                pagination={pagination({})}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Show all 128 priorities' }));
        fireEvent.click(screen.getByRole('button', { name: 'Load 28 more' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('More priorities could not be loaded');
        expect(screen.getByText('Showing 100 of 128 priorities')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Load 28 more' })).toBeEnabled();
    });
});
