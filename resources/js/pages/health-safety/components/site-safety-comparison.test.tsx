import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
vi.mock('@inertiajs/react', () => ({ Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} /> }));
import { SiteLeagueCard, SiteSafetyComparisonCard } from './charts';
afterEach(cleanup);

it('uses separate recorded counts in alphabetical order without inventing risk ranks or green zero-report clearance', () => {
    render(<SiteSafetyComparisonCard data={[{ id: 2, name: 'Zulu House', incidents: 40, hazards: 12 }, { id: 1, name: 'Alpha House', incidents: 0, hazards: 0 }]} />);
    const links = screen.getAllByRole('link');
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/sites/1', '/sites/2']);
    expect(within(links[0]).getByText(/safety reports/)).toBeInTheDocument();
    expect(within(links[1]).getByText('40')).toBeInTheDocument();
    expect(within(links[1]).getByText('12')).toBeInTheDocument();
    expect(screen.getByText(/fewer reports don't mean a safer site/)).toBeInTheDocument();
    expect(screen.queryByText(/league|risk score|safest/i)).not.toBeInTheDocument();
    expect(links[0].innerHTML).not.toMatch(/status-success|width:/);
    expect(links[0]).toHaveClass('frontline-tap', 'focus-visible:ring-2');
});
it('retains all seven provided sites instead of silently truncating the comparison to six', () => {
    render(<SiteSafetyComparisonCard data={Array.from({ length: 7 }, (_, index) => ({ id: index + 1, name: 'House ' + (index + 1), incidents: index, hazards: 0 }))} />);
    expect(screen.getAllByRole('link')).toHaveLength(7);
    expect(screen.getByRole('link', { name: 'Open House 7 safety comparison' })).toHaveAttribute('href', '/sites/7');
});
it('describes an empty current view without claiming a hazard-free organisation', () => {
    render(<SiteSafetyComparisonCard data={[]} />);
    expect(screen.getByText('No sites are available in this view.')).toBeInTheDocument();
    expect(screen.queryByText(/no incidents|no hazards|all safe/i)).not.toBeInTheDocument();
});
it('preserves the earlier component import contract with the same neutral comparison', () => {
    render(<SiteLeagueCard data={[{ id: 3, name: 'Kauri House', incidents: 1, hazards: 2 }]} />);
    expect(screen.getByText('Sites side by side')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Kauri House safety comparison' })).toHaveAttribute('href', '/sites/3');
});
it('keeps a restricted hazard source distinct from a recorded zero while preserving readable report counts', () => {
    render(<SiteSafetyComparisonCard data={[{ id: 3, name: 'Kauri House', incidents: 2, hazards: null }]} />);
    const row = screen.getByRole('link', { name: 'Open Kauri House safety comparison' });
    expect(within(row).getByText('Restricted')).toBeInTheDocument();
    expect(within(row).getByText('2')).toBeInTheDocument();
    expect(within(row).queryByText('0')).not.toBeInTheDocument();
});
