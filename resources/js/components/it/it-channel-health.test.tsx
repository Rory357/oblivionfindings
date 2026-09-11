import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    ItChannelHealth,
    type DeliveryHealth,
    type MailboxHealth,
} from './it-channel-health';

const checkedAt = '2026-09-11T02:00:00Z';
const mailbox: MailboxHealth = {
    viewer_user_id: 7,
    can_view: true,
    available: true,
    checked_at: checkedAt,
    settings_url: '/settings/it-mailbox',
    connections: [
        {
            provider: 'microsoft',
            status: 'error',
            last_completed_poll_at: null,
            last_attempt_at: checkedAt,
            failure_category: 'scanner_timeout',
            consecutive_failed_polls: 3,
            next_poll_at: '2026-09-11T02:05:00Z',
            operation_active: false,
            scan_pending: true,
            pending: {
                processing: 1,
                acknowledgement: 2,
                oldest_received_at: '2026-09-10T23:00:00Z',
                processing_attempts: 4,
                acknowledgement_attempts: 5,
            },
            quarantine: { count: 0, oldest_received_at: null },
        },
    ],
};
const delivery: DeliveryHealth = {
    viewer_user_id: 7,
    available: true,
    checked_at: checkedAt,
    queued: 101,
    sending: 1,
    accepted: 2,
    oldest_queued_at: '2026-09-11T00:00:00Z',
    oldest_unconfirmed_at: null,
    last_confirmed_delivery_at: null,
};

describe('IT channel health', () => {
    it('labels pending attempts and unknown outcomes precisely and links to canonical recovery', () => {
        render(
            <ItChannelHealth
                mailbox={mailbox}
                delivery={delivery}
                viewerId={7}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Review mailbox recovery' }),
        ).toHaveAttribute('href', '/settings/it-mailbox');
        expect(screen.getByText('No completed poll recorded')).toBeVisible();
        expect(screen.getByText('scanner timeout')).toBeVisible();
        expect(
            screen.getByText('4 processing · 5 acknowledgement'),
        ).toBeVisible();
        expect(screen.getByText(/3h ago at this check/)).toBeVisible();
        expect(screen.getByText('101')).toBeVisible();
        expect(
            screen.getByText('No confirmed delivery recorded'),
        ).toBeVisible();
        expect(screen.getByText('Time not recorded')).toBeVisible();
        expect(
            screen.getByText(
                /need provider reconciliation before another submission/,
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /retry/i }),
        ).not.toBeInTheDocument();
    });

    it('conceals mailbox details and recovery without settings authority while retaining permitted delivery health', () => {
        render(
            <ItChannelHealth
                mailbox={{ ...mailbox, can_view: false }}
                delivery={delivery}
                viewerId={7}
            />,
        );
        const section = screen.getByRole('region', {
            name: 'Mailbox processing health',
        });
        expect(
            within(section).getByText(/require integration settings access/),
        ).toBeVisible();
        expect(screen.queryByText('scanner timeout')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Review mailbox recovery' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('101')).toBeVisible();
    });

    it('conceals a former viewers health when the signed-in account changes', () => {
        const view = render(
            <ItChannelHealth
                mailbox={mailbox}
                delivery={delivery}
                viewerId={7}
            />,
        );
        view.rerender(
            <ItChannelHealth
                mailbox={mailbox}
                delivery={delivery}
                viewerId={8}
            />,
        );
        expect(screen.queryByText('scanner timeout')).not.toBeInTheDocument();
        expect(screen.queryByText('101')).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Current delivery health is unavailable/),
        ).toBeVisible();
    });

    it('distinguishes unavailable diagnostics from a verified empty queue', () => {
        const view = render(
            <ItChannelHealth
                mailbox={{ ...mailbox, available: false, connections: [] }}
                delivery={{ ...delivery, available: false }}
                viewerId={7}
            />,
        );
        expect(screen.getByText(/Mailbox health is unavailable/)).toBeVisible();
        expect(
            screen.queryByText('No mailbox connections are configured.'),
        ).not.toBeInTheDocument();
        view.rerender(
            <ItChannelHealth
                mailbox={{ ...mailbox, connections: [] }}
                delivery={{
                    ...delivery,
                    queued: 0,
                    sending: 0,
                    accepted: 0,
                    oldest_queued_at: null,
                }}
                viewerId={7}
            />,
        );
        expect(
            screen.getByText('No mailbox connections are configured.'),
        ).toBeVisible();
        expect(screen.getAllByText('None awaiting')).toHaveLength(2);
        expect(
            screen.getByText('No confirmed delivery recorded'),
        ).toBeVisible();
    });
});
