import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    PendingAssessmentNotice,
    type PendingAssessment,
} from './pending-assessment';

const routerMock = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: routerMock }));

const assessment: PendingAssessment = {
    id: 42,
    assessed_on: '2026-09-28',
    assessor: 'Hana Kereama',
    ends_on: '2027-09-28',
    passed_areas: 11,
    not_passed: ['Insulin administration'],
    not_assessed: [],
    restriction: null,
    to_work_on: 'Insulin pens — practise before the next assessment',
    can_give_now: false,
};

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Your assessment is waiting for you', () => {
    it('shows nothing when nothing waits', () => {
        const { container } = render(
            <PendingAssessmentNotice assessment={null} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('says it counts once acknowledged, and opens the v5 dialog', () => {
        render(<PendingAssessmentNotice assessment={assessment} />);
        expect(
            screen.getByText(
                'Your new medication assessment is waiting for you',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/until then you can’t record doses as given/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Read and acknowledge' }),
        );
        expect(
            screen.getByText('Acknowledge your assessment'),
        ).toBeInTheDocument();
        expect(screen.getByText('Passed · 11 of 12 areas')).toBeInTheDocument();
        expect(screen.getByText('Insulin administration')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Insulin pens — practise before the next assessment',
            ),
        ).toBeInTheDocument();
    });

    it('asks for the declaration before acknowledging, then posts only their own', () => {
        render(<PendingAssessmentNotice assessment={assessment} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Read and acknowledge' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));
        expect(
            screen.getByText('Turn this on to acknowledge.'),
        ).toBeInTheDocument();
        expect(routerMock.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('switch'));
        fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/competency/42/acknowledge',
            {},
            expect.anything(),
        );
    });
});
