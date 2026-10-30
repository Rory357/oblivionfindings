import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReportsIndex from './index';

const page = vi.hoisted(() => ({
    kpis: {} as Record<string, number>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({
        props: { kpis: page.kpis, modules: [], combined_reports: [] },
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({ stats }: { stats?: { label: string; value: number }[] }) => (
        <dl>
            {stats?.map(({ label, value }) => (
                <div key={label}>
                    <dt>{label}</dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    ),
}));
afterEach(cleanup);

describe('reports hub medication visibility', () => {
    it('omits medication metrics when the backend does not authorize that dataset', () => {
        page.kpis = {
            openIncidents: 2,
            completedShifts7d: 7,
            openSafeguarding: 1,
        };
        render(<ReportsIndex />);
        expect(
            screen.getByText('Incidents', { exact: true }),
        ).toBeInTheDocument();
        expect(screen.getByText('Shifts (7d)')).toBeInTheDocument();
        expect(
            screen.queryByText('Medication exceptions'),
        ).not.toBeInTheDocument();
    });

    it.each([0, 3])('retains an authorized medication value of %s', (count) => {
        page.kpis = {
            openIncidents: 2,
            completedShifts7d: 7,
            openSafeguarding: 1,
            missedMeds7d: count,
        };
        render(<ReportsIndex />);
        expect(
            screen.getByText('Medication exceptions').nextElementSibling,
        ).toHaveTextContent(String(count));
    });
});
