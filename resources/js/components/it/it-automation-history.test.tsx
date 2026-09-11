import { router } from '@inertiajs/react';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    ItAutomationHistory,
    type AutomationHistory,
} from './it-automation-history';

vi.mock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router: { get: vi.fn() },
}));
const health: AutomationHistory = {
    search_query: '',
    viewer_user_id: 7,
    can_view: true,
    available: true,
    checked_at: '2026-09-11T02:00:00Z',
    total: 26,
    unfinished: 1,
    oldest_unfinished_at: '2026-09-10T02:00:00Z',
    page: 1,
    last_page: 2,
    links: [
        { label: 'Previous', url: null, active: false },
        {
            label: '1',
            url: '/it/setup?tab=operations&automation_page=1',
            active: true,
        },
        {
            label: '2',
            url: '/it/setup?tab=operations&automation_page=2',
            active: false,
        },
        {
            label: 'Next',
            url: '/it/setup?tab=operations&automation_page=2',
            active: false,
        },
    ],
    rows: [
        {
            id: 26,
            label: 'Poll support mailbox',
            outcome: 'pending',
            execution_status: 'succeeded',
            started_at: '2026-09-11T01:00:00Z',
            finished_at: '2026-09-11T01:01:00Z',
            runtime_ms: 60000,
            failure_category: null,
            error_summary: null,
            mailbox_counts: {
                connections: 2,
                failed: 0,
                pending: 1,
                skipped: 0,
            },
            recovery_guidance:
                'A bounded batch can finish while the mailbox scan still needs another run.',
            recovery_url: '/settings/it-mailbox',
            recovery_label: 'Review mailbox recovery',
        },
    ],
};

describe('automation run history', () => {
    it('distinguishes pending scans from completed executions and uses permitted recovery navigation', () => {
        render(<ItAutomationHistory health={health} viewerId={7} />);
        expect(screen.getByText('Mailbox scan pending')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Poll support mailbox'));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(/2 connections considered/),
        ).toHaveTextContent('1 pending');
        expect(
            within(dialog).getByRole('link', {
                name: 'Review mailbox recovery',
            }),
        ).toHaveAttribute('href', '/settings/it-mailbox');
        expect(
            within(dialog).queryByRole('button', { name: /retry/i }),
        ).not.toBeInTheDocument();
    });

    it('restores stable row focus after a context menu closes and conceals an open dialog on account change', async () => {
        const { rerender } = render(
            <ItAutomationHistory health={health} viewerId={7} />,
        );
        const row = screen
            .getByText('Poll support mailbox')
            .closest('[role="row"]') as HTMLElement;
        row.focus();
        fireEvent.contextMenu(row, { clientX: 20, clientY: 20 });
        const review = await screen.findByRole('menuitem', {
            name: 'Review run and recovery',
        });
        review.focus();
        fireEvent.click(review);
        expect(review).not.toBeInTheDocument();
        fireEvent.click(
            within(screen.getByRole('dialog')).getAllByRole('button', {
                name: 'Close',
            })[0],
        );
        await waitFor(() => expect(row).toHaveFocus());
        fireEvent.keyDown(row, { key: 'Enter' });
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        rerender(<ItAutomationHistory health={health} viewerId={8} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Poll support mailbox'),
        ).not.toBeInTheDocument();
    });

    it('does not offer withheld mailbox details or recovery controls', () => {
        render(
            <ItAutomationHistory
                health={{
                    ...health,
                    rows: [
                        {
                            ...health.rows[0],
                            mailbox_counts: null,
                            recovery_url: null,
                            recovery_label: null,
                        },
                    ],
                }}
                viewerId={7}
            />,
        );
        fireEvent.click(screen.getByText('Poll support mailbox'));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).queryByText(/connections considered/),
        ).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('link')).not.toBeInTheDocument();
    });

    it('distinguishes no search matches from an empty retained history and clears the context with server results', () => {
        const empty = {
            ...health,
            total: 0,
            unfinished: 0,
            rows: [],
            last_page: 1,
            links: [],
        };
        const { rerender } = render(
            <ItAutomationHistory
                health={{ ...empty, search_query: 'Run 999' }}
                viewerId={7}
            />,
        );
        expect(
            screen.getByText(/No automation runs match this search/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Search results for “Run 999”/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/0 of 0 matching executions/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                'No automation runs were recorded for this period.',
            ),
        ).not.toBeInTheDocument();
        rerender(<ItAutomationHistory health={empty} viewerId={7} />);
        expect(
            screen.getByText(
                'No automation runs were recorded for this period.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Search results for/),
        ).not.toBeInTheDocument();
    });

    it('pages using the server URL and keeps unavailable evidence distinct from an empty history', () => {
        const { rerender } = render(
            <ItAutomationHistory health={health} viewerId={7} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(router.get).toHaveBeenCalledWith(
            health.links[3].url,
            {},
            { preserveState: false, preserveScroll: true },
        );
        rerender(
            <ItAutomationHistory
                health={{ ...health, available: false }}
                viewerId={7}
            />,
        );
        expect(screen.getByText(/history is unavailable/)).toBeInTheDocument();
        expect(
            screen.queryByText('Poll support mailbox'),
        ).not.toBeInTheDocument();
        rerender(
            <ItAutomationHistory
                health={{
                    ...health,
                    total: 0,
                    unfinished: 0,
                    rows: [],
                    last_page: 1,
                    links: [],
                }}
                viewerId={7}
            />,
        );
        expect(
            screen.getByText(
                'No automation runs were recorded for this period.',
            ),
        ).toBeInTheDocument();
    });
});
