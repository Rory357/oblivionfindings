import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    TicketMergedOriginals,
    type MergedOriginals,
} from '../ticket-merged-originals';
vi.mock('@inertiajs/react', () => ({
    Link: ({
        preserveScroll: _preserve,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & {
        preserveScroll?: boolean;
    }) => <a {...props} />,
}));
const row = {
    id: 41,
    reference: 'IT-000041',
    title: 'Preserved printer investigation',
    href: '/it/tickets/41/original',
    history_href: '/it/tickets/41/original?tab=history',
    tasks_href: '/it/tickets/41/original?tab=tasks',
    approvals_href: '/it/tickets/41/original?tab=approvals',
    links_href: '/it/tickets/41/original?tab=links',
};
const data: MergedOriginals = {
    data: [row],
    previous_page_url: null,
    next_page_url: '/it/tickets/42?originals_page=2',
};
afterEach(cleanup);
it('keeps the desktop header compact and exposes canonical evidence links on request', () => {
    render(<TicketMergedOriginals originals={data} />);
    const toggle = screen.getByRole('button', {
        name: 'View preserved original records',
    });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(
        screen.getByRole('link', { name: /Preserved printer investigation/ }),
    ).toHaveAttribute('href', row.href);
    expect(
        screen.getByRole('link', { name: 'Tasks & evidence' }),
    ).toHaveAttribute('href', row.tasks_href);
    expect(screen.getByRole('link', { name: 'Approvals' })).toHaveAttribute(
        'href',
        row.approvals_href,
    );
    expect(
        screen.getByRole('link', { name: 'Linked records' }),
    ).toHaveAttribute('href', row.links_href);
    expect(
        screen.getByRole('button', { name: 'Previous originals' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('link', { name: 'More originals' }),
    ).toHaveAttribute('href', data.next_page_url);
});
it('shows participant history without inventing private work controls', () => {
    render(
        <TicketMergedOriginals
            originals={{
                ...data,
                data: [
                    {
                        ...row,
                        tasks_href: null,
                        approvals_href: null,
                        links_href: null,
                    },
                ],
            }}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'View preserved original records' }),
    );
    expect(screen.getByRole('link', { name: 'History' })).toHaveAttribute(
        'href',
        row.history_href,
    );
    expect(
        screen.queryByRole('link', { name: 'Tasks & evidence' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Approvals' }),
    ).not.toBeInTheDocument();
});
it('keeps an empty later page recoverable when access or records changed', () => {
    render(
        <TicketMergedOriginals
            originals={{
                data: [],
                previous_page_url: '/it/tickets/42?originals_page=1',
                next_page_url: null,
            }}
        />,
    );
    expect(screen.getByText(/No original records are available/)).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Previous originals' }),
    ).toHaveAttribute('href', '/it/tickets/42?originals_page=1');
    expect(
        screen.getByRole('button', { name: 'More originals' }),
    ).toBeDisabled();
});
it('does not fabricate an empty history section for a ticket without originals', () => {
    render(
        <TicketMergedOriginals
            originals={{
                data: [],
                previous_page_url: null,
                next_page_url: null,
            }}
        />,
    );
    expect(
        screen.queryByRole('region', { name: 'Preserved original records' }),
    ).not.toBeInTheDocument();
});
