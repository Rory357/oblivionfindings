import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            return {
                data,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                post: (url: string, options: unknown) => inertia.post(url, data, options),
                put: (url: string, options: unknown) => inertia.put(url, data, options),
            };
        },
        Head: () => null,
        Link: () => null,
        router: { visit: vi.fn(), post: vi.fn(), get: vi.fn() },
        usePage: () => ({ props: {} }),
    };
});

import {
    EvaluationWizardDialog,
    RATING_ANCHORS,
    unansweredQuestionErrors,
    type EvaluationWizardRecord,
} from './_dialogs';
import { writtenComments } from './Results';

const committees = [
    { id: 3, name: 'Finance and audit committee' },
    { id: 4, name: 'People committee' },
];

const draft: EvaluationWizardRecord = {
    id: 12,
    title: 'Board effectiveness 2026',
    evaluation_type: 'committee',
    board_committee_id: 3,
    period_start: '2026-01-01',
    period_end: '2026-12-31',
    due_date: '2099-02-28',
    questions: [
        { text: 'Board papers arrive on time', type: 'rating' },
        { text: 'What should change?', type: 'text' },
    ],
};

const clickContinue = () =>
    fireEvent.click(screen.getByRole('button', { name: /continue/i }));

describe('editing a draft evaluation', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
    });
    afterEach(cleanup);

    it('asks which committee a committee evaluation is about', () => {
        render(<EvaluationWizardDialog open onClose={vi.fn()} committees={committees} />);

        fireEvent.change(screen.getByLabelText(/^title/i), {
            target: { value: 'Finance committee review' },
        });
        fireEvent.click(screen.getByRole('button', { name: /committee/i, pressed: false }));
        clickContinue();

        expect(screen.getByText('Choose which committee is being evaluated.')).toBeTruthy();
        // Still on the first step.
        expect(screen.queryByLabelText(/period start/i)).toBeNull();
    });

    it('opens the same wizard prefilled and saves the draft in place', () => {
        render(
            <EvaluationWizardDialog
                open
                onClose={vi.fn()}
                evaluation={draft}
                committees={committees}
            />,
        );

        const title = screen.getByLabelText(/^title/i) as HTMLInputElement;
        expect(title.value).toBe('Board effectiveness 2026');
        fireEvent.change(title, { target: { value: 'Board effectiveness review 2026' } });
        clickContinue();
        expect((screen.getByLabelText(/period end/i) as HTMLInputElement).value).toBe('2026-12-31');
        clickContinue();
        expect((screen.getByLabelText('Question 2') as HTMLInputElement).value).toBe(
            'What should change?',
        );
        clickContinue();
        fireEvent.click(screen.getByRole('button', { name: /save evaluation/i }));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/evaluations/12');
        expect(payload).toMatchObject({
            title: 'Board effectiveness review 2026',
            evaluation_type: 'committee',
            board_committee_id: '3',
            due_date: '2099-02-28',
            questions: [
                { text: 'Board papers arrive on time', type: 'rating' },
                { text: 'What should change?', type: 'text' },
            ],
        });
    });
});

describe('answering and reading an evaluation', () => {
    it('names both ends of the rating scale', () => {
        expect(RATING_ANCHORS[1]).toBe('Strongly disagree');
        expect(RATING_ANCHORS[3]).toBe('Neutral');
        expect(RATING_ANCHORS[5]).toBe('Strongly agree');
    });

    it('flags each unanswered question with the same words as the server', () => {
        expect(
            unansweredQuestionErrors(
                [{ type: 'rating' }, { type: 'yes_no' }, { type: 'text' }, { type: 'text' }],
                { '0': '', '3': 'More time for strategy' },
            ),
        ).toEqual({
            'answers.0': 'Choose a rating from 1 to 5.',
            'answers.1': 'Choose Yes or No.',
            'answers.2': 'Answer this question.',
        });
    });

    it('lists written comments without names, in an order that does not follow who wrote them', () => {
        const responses = [
            {
                submitted: true,
                answers: [
                    { question_id: 2, answer: 'Shorter papers' },
                    { question_id: 'overall_comments', answer: 'Good year' },
                ],
            },
            { submitted: true, answers: [{ question_id: 2, answer: 'Agenda earlier' }] },
            { submitted: false, answers: [{ question_id: 2, answer: 'Unsent draft' }] },
            { submitted: true, answers: [{ question_id: 2, answer: '   ' }] },
        ];

        expect(writtenComments(responses, 2)).toEqual(['Agenda earlier', 'Shorter papers']);
        expect(writtenComments(responses, 'overall_comments')).toEqual(['Good year']);
    });
});
