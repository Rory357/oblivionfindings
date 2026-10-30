import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SiteProfileFirstAid, type SiteFirstAidData } from './first-aid';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));
afterEach(cleanup);
const data: SiteFirstAidData = {
    can_manage: false,
    href: '/health-safety/first-aid?site_id=2',
    items: [
        {
            id: 9,
            reference: 'FA-9',
            person: 'Recorded person',
            treatment_date: '2026-10-08T12:45:00Z',
            injury: 'cut',
            outcome: 'returned_to_activity',
            ambulance_called: false,
            incident_reported: false,
            open_followups_count: 1,
            href: '/health-safety/first-aid/9?return_to=%2Fsites%2F2%3Ftab%3Dfirst_aid',
        },
    ],
};
it('keeps the NZ treatment day, year and time when opening from a Site', () => {
    render(<SiteProfileFirstAid data={data} />);
    const source = screen.getByRole('link', { name: /Recorded person/ });
    expect(source).toHaveTextContent(/9 October 2026, 1:45 am/);
    expect(source).toHaveAttribute('href', data.items[0].href);
});
it('labels an unknown treatment time as missing, never as a future schedule', () => {
    render(
        <SiteProfileFirstAid
            data={{
                ...data,
                items: [{ ...data.items[0], treatment_date: null }],
            }}
        />,
    );
    expect(screen.getByText(/Treatment time not recorded/)).toBeInTheDocument();
    expect(screen.queryByText(/Not scheduled/)).not.toBeInTheDocument();
});
