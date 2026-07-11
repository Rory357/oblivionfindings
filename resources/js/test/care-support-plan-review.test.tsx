import { configure, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

configure({ testIdAttribute: 'data-test' });

const inertia = vi.hoisted(() => ({
    router: {
        delete: vi.fn(),
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia.router,
}));

import { CareSupportPlanTab } from '@/pages/operations/clients/tabs/care-support-plan';

const activePlan = {
    id: 10,
    title: 'Published plan',
    status: 'active',
    version: 1,
    plan_type: 'support_plan',
    content: {},
    goals: [
        {
            id: 101,
            title: 'Published goal',
            status: 'in_progress',
            progress_percentage: 25,
        },
    ],
    sign_offs: [
        {
            id: 1001,
            party_role: 'client',
            party_name: 'Prior signatory',
            agreed_on: '2026-06-01',
        },
    ],
};

const reviewPlan = {
    id: 20,
    title: 'Review working copy',
    status: 'review',
    version: 2,
    plan_type: 'support_plan',
    content: {},
    goals: [
        {
            id: 201,
            title: 'Review-only goal',
            status: 'not_started',
            progress_percentage: 0,
        },
    ],
    sign_offs: [],
};

function renderReview(onEditPlan = vi.fn()) {
    render(
        <CareSupportPlanTab
            client={{ id: 1, first_name: 'Aroha' }}
            summary={
                {
                    active_plan: activePlan,
                    review_plan: reviewPlan,
                    working_plan: reviewPlan,
                    versions: [],
                } as any
            }
            canEdit
            canCreate
            onCreatePlan={vi.fn()}
            onEditPlan={onEditPlan}
            onGoToGoals={vi.fn()}
        />,
    );

    return onEditPlan;
}

describe('CareSupportPlanTab review working version', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders and edits the review copy instead of the published source version', () => {
        const onEditPlan = renderReview();

        expect(
            screen.getByRole('heading', { name: 'Review working copy' }),
        ).toBeTruthy();
        expect(screen.getByText('Review-only goal')).toBeTruthy();
        expect(screen.queryByText('Published goal')).toBeNull();
        expect(screen.queryByText('Prior signatory')).toBeNull();

        fireEvent.click(screen.getByTestId('careplan-edit'));
        expect(onEditPlan).toHaveBeenCalledWith(
            expect.objectContaining({ id: reviewPlan.id, status: 'review' }),
        );
    });

    it('records fresh agreement against the review copy', () => {
        renderReview();

        fireEvent.click(screen.getByTestId('careplan-add-signoff'));
        fireEvent.change(screen.getByPlaceholderText('Full name'), {
            target: { value: 'Fresh signatory' },
        });
        fireEvent.change(document.querySelector('input[type="date"]')!, {
            target: { value: '2026-07-11' },
        });
        fireEvent.click(screen.getByText('Record sign-off'));

        expect(inertia.router.post).toHaveBeenCalledWith(
            `/operations/care-plans/${reviewPlan.id}/sign-offs`,
            expect.objectContaining({
                party_name: 'Fresh signatory',
                agreed_on: '2026-07-11',
            }),
            expect.any(Object),
        );
    });

    it('explains that a fresh sign-off is needed before completion', () => {
        renderReview();

        expect(
            screen.getByText(
                'Record at least one new sign-off on this review before completing it.',
            ),
        ).toBeTruthy();
        expect(screen.getByTestId('careplan-complete-review')).toBeDisabled();
    });
});
