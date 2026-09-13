import { fireEvent, render, screen, within } from '@testing-library/react';
import type { FormEvent } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    TicketCommentDelivery,
    type TicketCommentDeliveryData,
} from '../ticket-comment-delivery';

const delivery: TicketCommentDeliveryData = {
    tracking: 'recorded',
    requested: true,
    attempt_statuses: {},
    checked_at: '2026-09-09T08:00:00Z',
    review_url: null,
};

describe('read-only ticket comment delivery status', () => {
    it('reports supplied current counts without turning provider acceptance into delivery', () => {
        render(
            <TicketCommentDelivery
                delivery={{
                    ...delivery,
                    attempt_statuses: {
                        queued: 2,
                        sending: 1,
                        accepted: 3,
                        delivered: 4,
                        failed: 1,
                        bounced: 2,
                        retried: 1,
                    },
                }}
            />,
        );
        const attempts = screen.getByRole('list', {
            name: 'Current delivery attempts',
        });
        expect(within(attempts).getAllByRole('listitem')).toHaveLength(7);
        for (const text of [
            'Queued: 2',
            'Sending: 1',
            'Accepted by provider: 3',
            'Delivered: 4',
            'Failed: 1',
            'Bounced: 2',
            'Retry recorded: 1',
        ])
            expect(within(attempts).getByText(text)).toBeVisible();
        expect(
            screen.getByText('Provider acceptance does not confirm delivery.'),
        ).toBeVisible();
        expect(
            screen.getByText('The sending outcome is not yet confirmed.'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /retry/i }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/15 delivered/)).not.toBeInTheDocument();
    });
    it('does not label an accepted-only message delivered', () => {
        render(
            <TicketCommentDelivery
                delivery={{
                    ...delivery,
                    attempt_statuses: { accepted: 1, delivered: 0 },
                }}
            />,
        );
        expect(screen.getByText('Accepted by provider: 1')).toBeVisible();
        expect(screen.queryByText(/^Delivered:/)).not.toBeInTheDocument();
    });
    it('distinguishes historical unrecorded tracking from no requested notification', () => {
        const { rerender } = render(
            <TicketCommentDelivery
                delivery={{
                    ...delivery,
                    tracking: 'unrecorded',
                    requested: false,
                }}
            />,
        );
        expect(
            screen.getByText(
                'Delivery tracking was not recorded for this message.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByText('No email notification was requested.'),
        ).not.toBeInTheDocument();
        rerender(
            <TicketCommentDelivery
                delivery={{ ...delivery, requested: false }}
            />,
        );
        expect(
            screen.getByText('No email notification was requested.'),
        ).toBeVisible();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });
    it('does not invent a queued or delivered attempt when a notification has no attempt record', () => {
        render(<TicketCommentDelivery delivery={delivery} />);
        expect(
            screen.getByText(
                'Email was requested; no delivery attempt is recorded yet.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
    it.each([-1, 1.5, Number.NaN])(
        'withholds invalid counts (%s) instead of rendering a partial success',
        (count) => {
            render(
                <TicketCommentDelivery
                    delivery={{
                        ...delivery,
                        attempt_statuses: { delivered: 1, failed: count },
                    }}
                    onRefresh={vi.fn()}
                />,
            );
            expect(
                screen.getByText(
                    'Delivery counts are unavailable. Refresh to check again.',
                ),
            ).toBeVisible();
            expect(screen.queryByRole('list')).not.toBeInTheDocument();
        },
    );
    it('does not echo unexpected recipient/provider fields', () => {
        const extra = {
            ...delivery,
            attempt_statuses: { queued: 1 },
            recipient_email: 'private-person@example.test',
            last_error: 'private transport details',
        };
        const { container } = render(
            <TicketCommentDelivery delivery={extra} />,
        );
        expect(container).not.toHaveTextContent('private-person');
        expect(container).not.toHaveTextContent('private transport');
    });
    it('exposes the supplied canonical scoped log link and removes it when permission projection changes', () => {
        const href =
            '/it/setup?tab=operations&ticket_id=4&comment_id=20#delivery-log';
        const { rerender } = render(
            <TicketCommentDelivery
                delivery={{ ...delivery, review_url: href }}
            />,
        );
        const link = screen.getByRole('link', { name: 'Review delivery log' });
        expect(link).toHaveAttribute('href', href);
        expect(link).not.toHaveAttribute('target');
        rerender(<TicketCommentDelivery delivery={delivery} />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it.each([
        'https://outside.example/log',
        '//outside.example/log',
        'javascript:alert(1)',
        '/it/setup/../tickets',
        '/it/setup\\outside',
    ])('omits an invalid log destination: %s', (review_url) => {
        render(
            <TicketCommentDelivery delivery={{ ...delivery, review_url }} />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('delegates refresh without submitting a form and disables duplicate refresh while pending', () => {
        const refresh = vi.fn();
        const submit = vi.fn((event: FormEvent<HTMLFormElement>) =>
            event.preventDefault(),
        );
        const { rerender } = render(
            <form onSubmit={submit}>
                <TicketCommentDelivery
                    delivery={delivery}
                    onRefresh={refresh}
                />
            </form>,
        );
        const button = screen.getByRole('button', {
            name: 'Refresh delivery status',
        });
        expect(button).toHaveAttribute('type', 'button');
        fireEvent.click(button);
        expect(refresh).toHaveBeenCalledOnce();
        expect(submit).not.toHaveBeenCalled();
        rerender(
            <TicketCommentDelivery
                delivery={delivery}
                onRefresh={refresh}
                refreshing
            />,
        );
        expect(
            screen.getByRole('group', { name: 'Email delivery' }),
        ).toHaveAttribute('aria-busy', 'true');
        const pending = screen.getByRole('button', {
            name: 'Refreshing delivery status…',
        });
        expect(pending).toBeDisabled();
        fireEvent.click(pending);
        expect(refresh).toHaveBeenCalledOnce();
    });
    it('shows the server check time in the approved Auckland format and handles an invalid time honestly', () => {
        const { rerender } = render(
            <TicketCommentDelivery delivery={delivery} />,
        );
        const time = screen.getByText(/^Wed 9 Sept?, 8:00 pm$/);
        expect(time.tagName).toBe('TIME');
        expect(time).toHaveAttribute('dateTime', delivery.checked_at);
        rerender(
            <TicketCommentDelivery
                delivery={{ ...delivery, checked_at: 'unavailable' }}
            />,
        );
        expect(screen.getByText('Check time unavailable.')).toBeVisible();
    });
});
