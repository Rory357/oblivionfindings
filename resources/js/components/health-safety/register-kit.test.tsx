import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { children: ReactNode; href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ url: '/health-safety/ppe', props: { auth: { can: {} } } }),
}));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

import { drillLeadRows } from '@/pages/health-safety/drills';
import { drillWhereUpTo } from '@/pages/health-safety/drills/shared';
import { ppeLeadRows } from '@/pages/health-safety/ppe';
import { reportSections } from '@/pages/health-safety/reports';
import { Activity, Clock } from 'lucide-react';
import { LeadBlock, QuietRows, RegisterBoxes, overdueNote, shortDay } from './register-kit';

afterEach(cleanup);

describe('G4 register kit', () => {
    it('words how overdue a date is on the NZ calendar, and leaves future dates alone', () => {
        expect(overdueNote('2026-10-26', '2026-10-31')).toBe('5 days overdue');
        expect(overdueNote('2026-10-30', '2026-10-31')).toBe('1 day overdue');
        expect(overdueNote('2026-07-14', '2026-10-31')).toBe('3 months overdue');
        expect(overdueNote('2026-06-02', '2026-10-31')).toBe('4 months overdue');
        expect(overdueNote('2025-06-02', '2026-10-31')).toBe('1 year overdue');
        expect(overdueNote('2026-10-31', '2026-10-31')).toBe('Due today');
        expect(overdueNote('2026-11-06', '2026-10-31')).toBeNull();
        expect(shortDay('2026-06-02', '2026-10-31')).toBe('2 Jun');
        expect(shortDay('2027-04-13', '2026-10-31')).toBe('13 Apr 2027');
    });

    it('draws only confirmed counts, says "No trend yet", and takes the alert tone only where someone must act', () => {
        render(
            <RegisterBoxes
                boxes={[
                    { key: 'a', label: 'Inspections overdue', value: 2, alert: true, icon: Clock, href: '/a' },
                    { key: 'b', label: 'Past expiry date', value: 0, alert: true, icon: Clock, href: '/b' },
                    { key: 'c', label: 'Not confirmed', value: null, alert: true, icon: Clock, href: '/c' },
                    { key: 'd', label: 'Missed check-ins', value: 0, icon: Clock, href: '/d', caption: 'None right now' },
                ]}
            />,
        );
        const overdue = screen.getByRole('link', { name: 'Inspections overdue: 2. No trend yet. Open the list' });
        expect(overdue).toHaveClass('eh-meter--critical');
        expect(screen.getByRole('link', { name: /^Past expiry date: 0/ })).not.toHaveClass('eh-meter--critical');
        expect(screen.queryByRole('link', { name: /^Not confirmed/ })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Missed check-ins: 0. None right now. Open the list' })).toBeInTheDocument();
    });

    it('fills only the most urgent row’s button and says who acts on the rest', () => {
        const onClick = vi.fn();
        render(
            <LeadBlock
                rows={[
                    { key: 1, when: { day: '2 Jun', note: '5 months overdue' }, title: 'Half-face respirator', chip: { label: 'Inspection overdue', tone: 'critical' }, action: { label: 'Record inspection', onClick } },
                    { key: 2, title: 'Safety harness', action: { label: 'Record inspection', href: '/x' } },
                    { key: 3, title: 'P2 respirator', action: null, who: 'Health and safety record this' },
                ]}
                more={{ text: '2 more need doing', action: { label: 'See all', href: '/all' } }}
            />,
        );
        const block = screen.getByRole('region', { name: 'Needs doing now' });
        const buttons = within(block).getAllByText('Record inspection').map((node) => node.closest('[data-slot="button"]') ?? node);
        expect(buttons[0].className).not.toEqual(buttons[1].className);
        fireEvent.click(within(block).getAllByText('Record inspection')[0]);
        expect(onClick).toHaveBeenCalledTimes(1);
        expect(block).toHaveTextContent('Health and safety record this');
        expect(block).toHaveTextContent('5 months overdue');
        expect(screen.getByRole('link', { name: 'See all' })).toHaveAttribute('href', '/all');
    });

    it('shows quiet rows as links, a place on the page as a plain anchor, and ↗ for another area', () => {
        render(
            <QuietRows
                rows={[
                    { key: 'map', icon: Clock, title: 'Risk map', sub: 'Before and after controls', href: '#risk-map' },
                    { key: 'exposures', icon: Activity, title: 'Exposures', elsewhere: true, href: '/health-safety/chemical-exposures' },
                ]}
            />,
        );
        expect(screen.getByRole('link', { name: /Risk map/ })).toHaveAttribute('href', '#risk-map');
        expect(screen.getByRole('link', { name: /Exposures/ })).toHaveTextContent('↗');
    });
});

describe('G4 lead rows', () => {
    it('protective gear: groups a row’s problems in one chip and offers Record inspection only to people who may inspect', () => {
        const rows = ppeLeadRows(
            [
                { kind: 'inventory', id: 4, name: 'Safety harness', serial_number: 'HAR-0042', site: 'Kauri House', location: 'laundry', condition: 'poor', due: '2026-08-12', expiry_date: '2026-10-12', problems: ['inspection_overdue', 'past_expiry', 'poor_condition'], can_inspect: true },
                { kind: 'inventory', id: 5, name: 'Half-face respirator', serial_number: null, site: 'Kauri House', location: null, condition: 'fair', due: '2026-06-02', expiry_date: null, problems: ['inspection_overdue'], can_inspect: false },
                { kind: 'allocation', id: 9, name: 'P2 respirator', serial_number: null, site: 'Rimu House', worker: 'Sam Wong', handed_out_at: '2026-10-13T01:00:00Z', problems: ['no_fit_test'] },
            ],
            '/health-safety/ppe',
        );
        expect(rows[0].chip?.label).toBe('Inspection overdue · Past expiry date · Poor condition');
        expect(rows[0].title).toBe('Safety harness HAR-0042');
        expect(rows[0].action?.href).toContain('/health-safety/ppe/inventory/4?action=inspect');
        expect(rows[1].action).toBeNull();
        expect(rows[1].who).toBe('Health and safety record this');
        expect(rows[2].title).toBe('P2 respirator — Sam Wong');
        expect(rows[2].chip).toEqual({ label: 'No fit test on record', tone: 'warning' });
        expect(rows[2].when?.note).toBe('handed out');
    });

    it('drills: overdue planned drills offer Plan a drill to planners only; things to fix open the drill', () => {
        const onPlan = vi.fn();
        const rows = drillLeadRows(
            [
                { kind: 'drill', id: 4, title: 'Fire evacuation — Tōtara House', site: 'Tōtara House', site_id: 3, due: '2026-07-14', last_run_at: '2026-01-14T01:00:00Z' },
                { kind: 'finding', id: 8, drill_id: 29, title: 'Fire door wedged open in the kitchen', site: 'Aroha Respite', drill_type: 'earthquake', drill_on: '2026-10-02', owner: 'Jordan Peri', due: '2026-10-26' },
            ],
            { canPlan: true, returnTo: '/health-safety/drills', onPlan },
        );
        expect(rows[0].sub).toContain('not run since 14 Jan');
        rows[0].action?.onClick?.();
        expect(onPlan).toHaveBeenCalledWith(3);
        expect(rows[1].chip?.label).toBe('Thing to fix overdue');
        expect(rows[1].sub).toBe('Aroha Respite · from the earthquake drill on 2 Oct · owner Jordan Peri');
        expect(rows[1].action?.href).toContain('/health-safety/drills/29');
        const reader = drillLeadRows([{ kind: 'drill', id: 4, title: 'Drill', site: null, site_id: null, due: null, last_run_at: null }], { canPlan: false, returnTo: '/', onPlan });
        expect(reader[0].action).toBeNull();
        expect(reader[0].who).toBe('Health and safety plan it');
    });

    it('drills: "Where it’s up to" is one status chip and who does it', () => {
        const base = { participants_count: 6, findings_open: 0, coordinator_name: 'Hemi Walker', findings_owner: null, site_last_run_at: null };
        const day = (iso: string) => iso.slice(0, 10);
        expect(drillWhereUpTo({ ...base, status: 'scheduled' }, day)).toEqual({ label: 'Planned', tone: 'info', who: 'Hemi Walker runs it — 6 assigned' });
        expect(drillWhereUpTo({ ...base, status: 'completed', findings_open: 1, findings_owner: 'Jordan Peri' }, day)).toEqual({ label: 'Things to fix', tone: 'warning', who: 'Jordan Peri has them' });
        expect(drillWhereUpTo({ ...base, status: 'overdue', site_last_run_at: '2026-01-14T00:00:00Z' }, day).who).toBe('Not run since 2026-01-14');
        expect(drillWhereUpTo({ ...base, status: 'completed' }, day)).toEqual({ label: 'Completed', tone: 'success', who: null });
    });
});

describe('G4-15 reports', () => {
    it('draws only what each person can open: the board view with WorkSafe, or Trends and Tell WorkSafe', () => {
        const admin = reportSections(
            { hazards: { view: true }, governance: { view: true }, compliance: { view: true }, privacy: { viewRequests: true } },
            { count: 1, first: 'Burn from the kettle — Liam Davis' },
        );
        expect(admin.map((section) => section.title)).toEqual(['For the board and WorkSafe', 'Following things through', 'In other modules']);
        expect(admin[0].rows.map((row) => row.title)).toEqual(['WorkSafe notifications', 'Board reports', 'Trends']);
        expect(admin[0].rows[0].sub).toBe('In Incidents and safety reviews · 1 to tell WorkSafe about: Burn from the kettle — Liam Davis');
        expect(admin[0].rows[0].elsewhere).toBe(true);

        const officer = reportSections({ hazards: { view: true } }, { count: 1, first: 'Burn from the kettle — Liam Davis' });
        expect(officer).toHaveLength(1);
        expect(officer[0].title).toBe('');
        expect(officer[0].rows.map((row) => row.title)).toEqual(['Trends', 'Tell WorkSafe']);
        expect(officer[0].rows[1].href).toBe('/health-safety/events?tab=decide_or_tell');
        expect(JSON.stringify(officer)).not.toMatch(/Board reports|Risk assessment report|hidden/i);
    });
});
