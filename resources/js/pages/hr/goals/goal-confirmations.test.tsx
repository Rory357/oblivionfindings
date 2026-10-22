import type { Objective } from '@/components/hr/goals/okr-shared';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GoalsHub from './index';
import GoalShow from './show';

// Deleting an objective, deleting a key result and archiving an objective
// used browser confirm(). Each now opens the shared ConfirmDialog and only
// its confirm button sends the request.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => {
    const settle = (options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    };
    return {
        visit: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn((_url: string, options?: VisitOptions) =>
            settle(options),
        ),
        put: vi.fn((_url: string, _data: unknown, options?: VisitOptions) =>
            settle(options),
        ),
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: () => null,
    PageLayout: ({ children }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>{children}</>
    ),
}));
vi.mock('@/components/hr', () => ({ PerformanceTabs: () => null }));
vi.mock('@/components/hr/goals/checkin-wizard', () => ({
    CheckinWizard: () => null,
}));
vi.mock('@/components/hr/goals/development-wizard', () => ({
    DevelopmentWizard: () => null,
}));
vi.mock('@/components/hr/goals/objective-wizard', () => ({
    ObjectiveWizard: () => null,
}));

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
    router.put.mockClear();
});

describe('Goals hub: delete an objective', () => {
    const objective: Objective = {
        id: 5,
        title: 'Reduce agency shifts',
        description: null,
        goal_type: 'team',
        category: null,
        status: 'active',
        confidence: 'on_track',
        priority: 'medium',
        tags: [],
        progress_percentage: 40,
        target_value: null,
        current_value: null,
        unit: null,
        start_date: '2026-07-01',
        due_date: '2026-12-31',
        checkin_frequency: 'fortnightly',
        last_checkin_at: null,
        last_checkin_days: null,
        user: { id: 2, name: 'Hemi Walker' },
        parent_goal_id: null,
        parent_goal: null,
        cycle: null,
        cycle_id: null,
        key_results_count: 0,
        development_count: 0,
        key_results: [],
    };

    const openDelete = () => {
        render(
            <GoalsHub
                objectives={[objective]}
                developmentPlans={[]}
                users={[{ id: 2, name: 'Hemi Walker' }]}
                parentGoals={[]}
                cycles={[]}
                templates={[]}
                allTags={[]}
                competencies={[]}
                selectedCycleId={null}
                currentCycleId={null}
                analytics={{
                    total: 1,
                    active: 1,
                    completed: 0,
                    draft: 0,
                    overdue: 0,
                    on_track: 1,
                    at_risk: 0,
                    off_track: 0,
                    avg_progress: 40,
                    completion_rate: 0,
                    progress_by_type: [],
                }}
                cascadeTree={[]}
                can={{ manage: true }}
                defaultTab={null}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        fireEvent.click(
            within(screen.getByRole('menu')).getByRole('button', {
                name: 'Delete',
            }),
        );
    };

    it('asks before deleting and sends nothing from the menu', () => {
        openDelete();

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Delete this objective?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            '“Reduce agency shifts” and its key results come off Goals for everyone.',
        );
    });

    it('Cancel deletes nothing', async () => {
        openDelete();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('deletes the objective once confirmed', async () => {
        openDelete();

        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Delete objective',
            }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/goals/5',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});

describe('Goal detail: delete a key result and archive', () => {
    const goal = {
        id: 9,
        title: 'Every resident has a current support plan',
        description: null,
        goal_type: 'company' as const,
        category: null,
        tags: [],
        status: 'active',
        confidence: 'on_track' as const,
        priority: 'high',
        checkin_frequency: 'monthly',
        progress_percentage: 50,
        target_value: null,
        current_value: null,
        unit: null,
        start_date: '2026-07-01',
        due_date: '2026-12-31',
        completed_at: null,
        cycle: null,
        cycle_id: null,
        user: { id: 2, name: 'Hemi Walker' },
        creator: null,
        parent_goal_id: null,
        parent_goal: null,
        child_goals: [],
        key_results: [
            {
                id: 31,
                title: 'Plans reviewed this quarter',
                kr_type: 'number',
                start_value: 0,
                target_value: 40,
                current_value: 20,
                unit: null,
                weight: 1,
                progress_percentage: 50,
                status: 'in_progress',
                confidence: 'on_track' as const,
                due_date: null,
                owner: null,
            },
        ],
        updates: [],
        development_goals: [],
    };

    const renderGoal = () =>
        render(
            <GoalShow
                goal={goal}
                users={[]}
                parentGoals={[]}
                cycles={[]}
                can={{ manage: true, updateProgress: true }}
            />,
        );

    it('asks before deleting a key result, and Cancel deletes nothing', async () => {
        renderGoal();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Delete key result Plans reviewed this quarter',
            }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Delete this key result?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            '“Plans reviewed this quarter” and its check-in history are deleted',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('deletes the key result once confirmed', () => {
        renderGoal();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Delete key result Plans reviewed this quarter',
            }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Delete key result',
            }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/goals/key-results/31',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('asks before archiving, and Cancel archives nothing', () => {
        renderGoal();
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));

        expect(router.put).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Archive this objective?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Its key results and check-in history are kept.',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.put).not.toHaveBeenCalled();
    });

    it('archives the objective (status cancelled) once confirmed', async () => {
        renderGoal();
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Archive objective',
            }),
        );

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(router.put).toHaveBeenCalledWith(
            '/hr/goals/9',
            { status: 'cancelled' },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
