import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RoadmapDashboard from './Dashboard';

// Rejecting a triage suggestion or a decision request asks first: nothing is
// posted until the confirm button in the dialog.

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('axios', () => ({ default: axiosMock }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: () => null,
    PageLayout: ({ children }: PropsWithChildren) => <>{children}</>,
}));

const summary = {
    published_plan: null,
    initiatives: {
        total: 0,
        in_progress: 0,
        blocked: 0,
        deferred: 0,
        completed: 0,
        top: [],
    },
    budget: { forecast_total: 0 },
    governance_budget: null,
    assurance: { overdue: 0, verified: 0 },
    decisions_required: 1,
    house_rollout: {
        not_started: 0,
        in_progress: 0,
        blocked: 0,
        completed: 0,
    },
};

const list = (rows: unknown[]) => ({ data: { items: { data: rows } } });

const responses: Record<string, unknown> = {
    '/roadmap/dashboard': {
        data: {
            summary,
            triage: { pending: 1, overload: false },
            generated_at: '2026-10-20T09:00:00+13:00',
        },
    },
    '/roadmap/initiatives?per_page=5': list([]),
    '/roadmap/quarterly-plans?per_page=5': list([]),
    '/roadmap/suggestions?status=triage_pending&per_page=5': list([
        {
            id: 41,
            title: 'Shift swap requests time out',
            source: 'sentry',
            status: 'triage_pending',
            hit_count: 3,
        },
    ]),
    '/roadmap/decisions?status=pending&per_page=8': list([
        {
            id: 9,
            request_type: 'budget_approval',
            required_role: 'board',
            amount: 12000,
            due_date: null,
            status: 'pending',
        },
    ]),
};

const can = {
    viewDashboard: true,
    viewRoadmap: true,
    manageRoadmap: true,
    approveRoadmap: false,
    manageBudget: false,
    viewDecisions: true,
    manageDecisions: true,
    exportReports: false,
};

function renderDashboard() {
    return render(
        <RoadmapDashboard
            summary={summary}
            triage={{ pending: 1, overload: false }}
            generatedAt="2026-10-20T09:00:00+13:00"
            managers={[]}
            can={can}
        />,
    );
}

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    axiosMock.get.mockReset();
    axiosMock.post.mockReset();
    axiosMock.get.mockImplementation((url: string) =>
        Promise.resolve(responses[url] ?? list([])),
    );
    axiosMock.post.mockResolvedValue({ data: {} });
});

describe('Roadmap dashboard: reject asks first', () => {
    it('asks before rejecting a decision request, then rejects it', async () => {
        renderDashboard();
        const reject = await screen.findByRole('button', { name: 'Reject' });

        fireEvent.click(reject);

        expect(axiosMock.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Reject this decision request?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('budget approval');
        expect(dialog()).toHaveTextContent(
            'that resolution is cancelled too',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Reject request' }),
        );

        await waitFor(() =>
            expect(axiosMock.post).toHaveBeenCalledWith(
                '/roadmap/decisions/9/resolve',
                expect.objectContaining({ status: 'rejected' }),
                expect.anything(),
            ),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('asks before rejecting a suggestion, and Cancel posts nothing', async () => {
        renderDashboard();
        fireEvent.click(await screen.findByRole('button', { name: 'View' }));
        const suggestionDialog = await screen.findByRole('dialog');

        fireEvent.click(
            within(suggestionDialog).getByRole('button', { name: 'Reject' }),
        );

        expect(
            within(dialog()).getByText('Reject this suggestion?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('Shift swap requests time out');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(axiosMock.post).not.toHaveBeenCalled();
    });

    it('rejects the suggestion with its notes once confirmed', async () => {
        renderDashboard();
        fireEvent.click(await screen.findByRole('button', { name: 'View' }));
        const suggestionDialog = await screen.findByRole('dialog');

        fireEvent.click(
            within(suggestionDialog).getByRole('button', { name: 'Reject' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Reject suggestion' }),
        );

        await waitFor(() =>
            expect(axiosMock.post).toHaveBeenCalledWith(
                '/roadmap/suggestions/41/triage',
                expect.objectContaining({ status: 'rejected' }),
                expect.anything(),
            ),
        );
    });
});
