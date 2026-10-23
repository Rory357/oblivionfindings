import { cleanup, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SiteProfilePpe, type SitePpeData } from './ppe';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { get: vi.fn() },
}));
afterEach(cleanup);
const href = '/health-safety/ppe/inventory/9?return_to=%2Fsites%2F2%3Ftab%3Dppe';
const data: SitePpeData = {
    href: '/health-safety/ppe?site_id=2', can_manage: false, locked: false,
    physical_record_count: 1, displayed_count: 1, truncated: false, repair_count: 0, confirmed_units: 3,
    items: [{ id: 9, name: 'Recorded gloves', href, condition: 'good', quantity: 3, status: 'available', location: 'Store A',
        expiry_date: '2026-10-12', next_inspection_due: '2026-10-13', expired: false, inspection_due: false, source_integrity: 'intact' }],
};
it('opens the permanent equipment record from both real mobile cards and desktop rows with the Site return intact', () => {
    const { container } = render(<SiteProfilePpe data={data} />);
    const mobile = container.querySelector('ul.md\\:hidden');
    // The shared list owns its complete card branch even when the desktop table is selected.
    const cards = mobile ?? container.querySelector('ul[class*="md:hidden"]');
    expect(cards).not.toBeNull();
    expect(within(cards as HTMLElement).getByRole('link', { name: /Open Recorded gloves/ })).toHaveAttribute('href', href);
    const desktop = container.querySelector('div.hidden.md\\:block');
    expect(desktop).not.toBeNull();
    expect(within(desktop as HTMLElement).getByRole('link', { name: /Open Recorded gloves/ })).toHaveAttribute('href', href);
    expect(screen.getAllByText('12 Oct 2026').length).toBeGreaterThan(0);
    expect(screen.getByText(/Quantity does not identify individual units/)).toBeInTheDocument();
});
it('withholds all damaged-row facts in both branches even when stale values arrive beside the repair marker', () => {
    render(<SiteProfilePpe data={{ ...data, repair_count: 1, confirmed_units: null, items: [{ ...data.items[0], source_integrity: 'repair_required' }] }} />);
    expect(screen.queryByText('Recorded gloves')).not.toBeInTheDocument();
    expect(screen.queryByText('Store A')).not.toBeInTheDocument();
    expect(screen.queryByText('12 Oct 2026')).not.toBeInTheDocument();
    expect(screen.getByText(/Recorded quantity is not confirmed/)).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: /Open Equipment 9/ })).toHaveLength(2);
    expect(screen.getByRole('status')).toHaveTextContent(/equipment source needs review/);
});
it('labels a capped preview and saved-record count without claiming a complete active inventory', () => {
    render(<SiteProfilePpe data={{ ...data, physical_record_count: 103, truncated: true }} />);
    expect(screen.getByText(/1 preview records · 103 saved Site records/)).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(/preview is limited/);
    expect(screen.queryByText(/All active Site PPE/)).not.toBeInTheDocument();
});
it('withdraws equipment content and links when current Site access is restricted', () => {
    render(<SiteProfilePpe data={{ ...data, locked: true }} />);
    expect(screen.getByText(/require current safety and Site access/)).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByText('Recorded gloves')).not.toBeInTheDocument();
});
it('shows real dated attention labels and distinguishes unknown earlier history from repair', () => {
    render(<SiteProfilePpe data={{ ...data, items: [{ ...data.items[0], source_integrity: 'legacy_unknown', expired: true, inspection_due: true }] }} />);
    expect(screen.getAllByText('Earlier history not confirmed')).toHaveLength(2);
    expect(screen.getAllByText('Recorded expiry has passed')).toHaveLength(2);
    expect(screen.getAllByText('Inspection due')).toHaveLength(2);
    expect(screen.queryByText('Source needs review')).not.toBeInTheDocument();
});
it('describes an empty preview without certifying that no retained equipment exists', () => {
    render(<SiteProfilePpe data={{ ...data, items: [], displayed_count: 0, physical_record_count: 2, confirmed_units: 0 }} />);
    expect(screen.getByText(/No matching equipment records are available in this Site preview/)).toBeInTheDocument();
    expect(screen.queryByText(/No active PPE is recorded/)).not.toBeInTheDocument();
});
