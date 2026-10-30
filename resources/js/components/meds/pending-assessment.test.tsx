import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AcknowledgeAssessment,
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
    not_passed: ['Insulin'],
    not_assessed: [],
    restriction: null,
    to_work_on: 'Insulin pens — practise before the next assessment',
    can_give_now: false,
};

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Acknowledge your assessment', () => {
    it('shows v5’s result: areas passed, what wasn’t, what to work on', () => {
        render(
            <AcknowledgeAssessment assessment={assessment} onClose={vi.fn()} />,
        );
        expect(
            screen.getByText('Acknowledge your assessment'),
        ).toBeInTheDocument();
        expect(screen.getByText('Passed · 11 of 12 areas')).toBeInTheDocument();
        expect(screen.getByText('Insulin')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Insulin pens — practise before the next assessment',
            ),
        ).toBeInTheDocument();
    });

    it('asks for the declaration before acknowledging, then posts only their own', () => {
        render(
            <AcknowledgeAssessment assessment={assessment} onClose={vi.fn()} />,
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

    it('gives support workers 44 px targets', () => {
        render(
            <AcknowledgeAssessment assessment={assessment} onClose={vi.fn()} />,
        );
        for (const name of ['Cancel', 'Acknowledge'])
            expect(screen.getByRole('button', { name })).toHaveClass(
                'frontline-tap',
            );
        expect(screen.getByRole('switch')).toHaveClass('frontline-hit');
    });
});
