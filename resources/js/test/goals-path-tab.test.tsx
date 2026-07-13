import { configure, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

// The app marks elements with `data-test` (not the RTL default `data-testid`).
configure({ testIdAttribute: 'data-test' });

import { GoalWizardDialog } from '@/components/clients/profile/goal-dialog';
import { GoalsPathTab } from '@/pages/operations/clients/tabs/goals-path';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

const goals = [
    {
        id: 1,
        title: 'Prepare a simple meal independently',
        status: 'in_progress',
        category: 'Daily living',
        progress_percentage: 70,
        steps_count: 4,
        steps_done_count: 3,
        open_hurdles_count: 1,
    },
    {
        id: 2,
        title: 'Catch the bus to day programme',
        status: 'completed',
        category: 'Independence',
        progress_percentage: 100,
        steps_count: 2,
        steps_done_count: 2,
        open_hurdles_count: 0,
    },
    {
        id: 3,
        title: 'Reduce early-morning waking',
        status: 'not_started',
        category: 'Wellbeing',
        progress_percentage: 0,
    },
];

describe('GoalsPathTab (design card grid)', () => {
    it('binds status, domain, progress and step/hurdle counts onto the cards', () => {
        render(
            <GoalsPathTab
                clientId={1}
                clientName="Tane"
                goals={goals}
                canManageGoals
                onAddGoal={vi.fn()}
                onManageGoal={vi.fn()}
                onEditPlan={vi.fn()}
            />,
        );

        expect(screen.getByText('1 achieved · 2 in progress')).toBeTruthy();
        expect(
            screen.getByText('Prepare a simple meal independently'),
        ).toBeTruthy();
        // domain + the new step/hurdle meta line bind correctly
        expect(
            screen.getByText('Daily living · 3/4 steps · 1 open hurdle'),
        ).toBeTruthy();
        expect(screen.getByText('70%')).toBeTruthy();
        expect(screen.getByText('Achieved')).toBeTruthy();
        expect(screen.getByText('Not started')).toBeTruthy();
    });

    it('opens add (header) and manage (card click) wizards', () => {
        const onAddGoal = vi.fn();
        const onManageGoal = vi.fn();
        render(
            <GoalsPathTab
                clientId={1}
                clientName="Tane"
                goals={goals}
                canManageGoals
                onAddGoal={onAddGoal}
                onManageGoal={onManageGoal}
                onEditPlan={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByTestId('goals-add-goal'));
        expect(onAddGoal).toHaveBeenCalledTimes(1);

        // Cards are sorted: highest-progress non-completed first → goal #1.
        fireEvent.click(screen.getAllByTestId('goal-card')[0]);
        expect(onManageGoal).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1 }),
        );
    });

    it('shows the empty state with an Add CTA when there are no goals', () => {
        const onAddGoal = vi.fn();
        render(
            <GoalsPathTab
                clientId={1}
                clientName="Tane"
                goals={[]}
                canManageGoals
                onAddGoal={onAddGoal}
                onManageGoal={vi.fn()}
                onEditPlan={vi.fn()}
            />,
        );

        expect(screen.getByText('No goals captured yet')).toBeTruthy();
        fireEvent.click(screen.getByText('Add the first goal'));
        expect(onAddGoal).toHaveBeenCalledTimes(1);
    });
});

describe('GoalWizardDialog', () => {
    it('mounts the Add-Client-style create wizard with templates + custom', () => {
        render(
            <GoalWizardDialog
                open
                onClose={vi.fn()}
                carePlanId={42}
                clientLabel="Tane · Tūī House"
            />,
        );

        // Template-or-custom first step (the "custom goals not just generic" ask)
        expect(screen.getByText('Write your own')).toBeTruthy();
        expect(screen.getByText('Prepare a simple meal')).toBeTruthy();
        // Create CTA is wired
        expect(screen.getByTestId('goal-create-continue')).toBeTruthy();
    });
});
