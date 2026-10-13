import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubPoll } from './_participation';
import type { HubBlock, HubProps } from './_types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post }, useForm: vi.fn() }));

const block: HubBlock = {
    id: 'next',
    type: 'poll',
    title: 'Have your say',
    question: 'What next?',
    options: ['Walk', 'Lunch'],
};
const data = {
    participation: {
        page_id: 7,
        polls: {
            next: {
                key: 'published-question-key',
                total: 4,
                mine: 0,
                counts: [3, 1],
                closed: false,
            },
        },
        reactions: {},
        comments: [],
    },
} as unknown as HubProps;

describe('Company Hub poll participation', () => {
    beforeEach(() => post.mockReset());

    it('sends the published question key with a changed answer', () => {
        render(<HubPoll block={block} data={data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Lunch 25%' }));
        expect(post).toHaveBeenCalledWith(
            '/company-hub/pages/7/interactions',
            {
                kind: 'vote',
                block_id: 'next',
                poll_key: 'published-question-key',
                choice: 1,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(
            screen.getByRole('button', { name: 'Lunch 25%' }),
        ).toBeDisabled();
    });

    it('prevents voting from an unpublished editor preview', () => {
        render(
            <HubPoll block={block} data={{ ...data, participation: null }} />,
        );
        expect(screen.getByRole('button', { name: 'Walk' })).toBeDisabled();
        expect(
            screen.getByText('Voting opens when this page is published.'),
        ).toBeVisible();
        expect(post).not.toHaveBeenCalled();
    });

    it('keeps closed poll results readable while disabling answers', () => {
        const closed = {
            ...data,
            participation: {
                ...data.participation!,
                polls: {
                    next: { ...data.participation!.polls.next, closed: true },
                },
            },
        };
        render(<HubPoll block={block} data={closed} />);
        expect(
            screen.getByRole('button', { name: 'Walk · Your choice 75%' }),
        ).toBeDisabled();
        expect(screen.getByText('4 responses · Poll closed')).toBeVisible();
    });
});
