import { router } from '@inertiajs/react';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ItApiOperations, type ApiOperationsHealth } from './it-api-operations';

vi.mock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router: { get: vi.fn() },
}));

const health: ApiOperationsHealth = {
    viewer_user_id: 7,
    available: true,
    checked_at: '2026-09-11T02:00:00Z',
    total: 28,
    history_total: 28,
    search_query: '',
    links: [
        { label: 'Previous', url: null, active: false },
        {
            label: '1',
            url: '/it/setup?tab=operations&api_request_page=1#it-api-request-history',
            active: true,
        },
        {
            label: '2',
            url: '/it/setup?tab=operations&api_request_page=2#it-api-request-history',
            active: false,
        },
        {
            label: 'Next',
            url: '/it/setup?tab=operations&api_request_page=2#it-api-request-history',
            active: false,
        },
    ],
    failures: 3,
    pending: 1,
    oldest_pending_at: '2026-09-10T02:00:00Z',
    last_success_at: null,
    identities_url: '/it/setup?tab=api',
    page: 1,
    last_page: 2,
    previous_url: null,
    next_url:
        '/it/setup?tab=operations&api_request_page=2#it-api-request-history',
    rows: [
        {
            id: 28,
            identity_name: 'Approved connector',
            operation: 'create',
            response_status: 500,
            outcome: 'not_applied',
            failure_category: 'server_failure',
            attempt_count: 3,
            last_attempt_at: '2026-09-11T01:00:00Z',
            created_at: '2026-09-11T00:00:00Z',
            completed_at: '2026-09-11T01:00:00Z',
            recovery: 'retry_same_request',
        },
    ],
};

describe('API operations diagnostics', () => {
    it('returns focus to the stable record after a context-menu item unmounts', async () => {
        const { rerender } = render(
            <ItApiOperations health={health} viewerId={7} />,
        );
        const row = screen
            .getByText('Create ticket')
            .closest('[role="row"]') as HTMLElement;
        row.focus();
        fireEvent.contextMenu(row, { clientX: 20, clientY: 20 });
        const review = await screen.findByRole('menuitem', {
            name: 'Review outcome and recovery',
        });
        review.focus();
        fireEvent.click(review);
        expect(review).not.toBeInTheDocument();
        const close = within(screen.getByRole('dialog')).getAllByRole(
            'button',
            { name: 'Close' },
        )[0];
        fireEvent.click(close);
        await waitFor(() => expect(row).toHaveFocus());
        fireEvent.keyDown(row, { key: 'Enter' });
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        rerender(<ItApiOperations health={health} viewerId={8} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Approved connector/),
        ).not.toBeInTheDocument();
    });

    it('shows recorded facts and original-client recovery with canonical identity and pagination links', async () => {
        render(<ItApiOperations health={health} viewerId={7} />);
        const region = screen.getByRole('region', {
            name: 'API command diagnostics',
        });
        expect(
            within(region).getByText('28 total · 3 errors'),
        ).toBeInTheDocument();
        expect(
            within(region).getByText('No verified success recorded'),
        ).toBeInTheDocument();
        expect(
            within(region).getByText('500 · server failure'),
        ).toBeInTheDocument();
        expect(
            within(region).getByRole('link', { name: 'Review API identities' }),
        ).toHaveAttribute('href', '/it/setup?tab=api');
        fireEvent.click(
            within(region).getByRole('button', { name: 'Next page' }),
        );
        expect(router.get).toHaveBeenCalledWith(
            health.next_url,
            {},
            { preserveState: false },
        );
        expect(
            within(region).queryByRole('button', { name: /retry/i }),
        ).not.toBeInTheDocument();
        const row = screen
            .getByText('Create ticket')
            .closest('[role="row"]') as HTMLElement;
        row.focus();
        fireEvent.keyDown(row, { key: 'Enter' });
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(/The command was rolled back/),
        ).toHaveTextContent('same Idempotency-Key');
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await waitFor(() => expect(row).toHaveFocus());
    });

    it('keeps legacy uncertainty, unrecorded attempts and read recovery distinct', () => {
        render(
            <ItApiOperations
                viewerId={7}
                health={{
                    ...health,
                    rows: [
                        {
                            ...health.rows[0],
                            outcome: 'unknown',
                            attempt_count: null,
                            recovery: 'reconcile',
                        },
                        {
                            ...health.rows[0],
                            id: 29,
                            operation: 'read',
                            outcome: 'committed',
                            response_status: 200,
                            failure_category: null,
                            recovery: 'read_again',
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('Outcome unverified')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Create ticket'));
        expect(
            screen.getByText(/does not prove whether work was applied/),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).getByText('Not recorded'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(screen.getByRole('dialog')).getAllByRole('button', {
                name: 'Close',
            })[0],
        );
        fireEvent.click(screen.getByText('Read ticket'));
        expect(
            screen.getByText(/Read requests do not apply changes/),
        ).toBeInTheDocument();
    });

    it('keeps global health visible when a search is empty and uses server-filtered numbered links', () => {
        const next =
            '/it/setup?tab=operations&q=Repair+connector&automation_from=2026-09-11&api_request_page=2#it-api-request-history';
        const { rerender } = render(
            <ItApiOperations
                viewerId={7}
                health={{
                    ...health,
                    search_query: 'Repair connector',
                    history_total: 26,
                    links: health.links.map((link) =>
                        link.label === '2' || link.label === 'Next'
                            ? { ...link, url: next }
                            : link,
                    ),
                }}
            />,
        );
        expect(screen.getByText('28 total · 3 errors')).toBeInTheDocument();
        expect(
            screen.getByText(/1 of 26 matching requests shown/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: '2' }));
        expect(router.get).toHaveBeenCalledWith(
            next,
            {},
            { preserveState: false },
        );
        rerender(
            <ItApiOperations
                viewerId={7}
                health={{
                    ...health,
                    search_query: 'No match',
                    history_total: 0,
                    rows: [],
                    last_page: 1,
                    links: [],
                }}
            />,
        );
        expect(screen.getByText('28 total · 3 errors')).toBeInTheDocument();
        expect(
            screen.getByText(/No requests match this search/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/No requests have been recorded/),
        ).not.toBeInTheDocument();
        rerender(<ItApiOperations viewerId={7} health={health} />);
        expect(
            screen.queryByText(/Search results for/),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/1 of 28 shown/)).toBeInTheDocument();
    });

    it('conceals stale account data and recovery links', () => {
        render(<ItApiOperations health={health} viewerId={8} />);
        expect(
            screen.queryByText(/Approved connector/),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText(/your current account/)).toBeInTheDocument();
    });

    it('distinguishes unavailable diagnostics from a verified empty history', () => {
        const { rerender } = render(
            <ItApiOperations
                viewerId={7}
                health={{ ...health, available: false }}
            />,
        );
        expect(
            screen.getByText(/diagnostics are unavailable/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/28 total/)).not.toBeInTheDocument();
        rerender(
            <ItApiOperations
                viewerId={7}
                health={{
                    ...health,
                    total: 0,
                    history_total: 0,
                    failures: 0,
                    pending: 0,
                    rows: [],
                    last_page: 1,
                    next_url: null,
                }}
            />,
        );
        expect(
            screen.getByText(/No requests have been recorded/),
        ).toBeInTheDocument();
        expect(screen.getByText('None awaiting')).toBeInTheDocument();
    });
});
