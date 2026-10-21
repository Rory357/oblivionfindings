import { cleanup, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import MedicationErrors from './MedicationErrors';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ url: '/emar/errors', props: { auth: { can: {} } } }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/emar/emar-hub-rail', () => ({ EmarHubRail: () => null }));
vi.mock('@/components/emar/medication-export-button', () => ({
    MedicationExportButton: ({ children }: { children: ReactNode }) => (
        <button>{children}</button>
    ),
}));
vi.mock('./components/report-error-modal', () => ({
    ReportErrorModal: () => null,
}));
vi.mock('./errors/_detail', () => ({ ErrorDetail: () => null }));

afterEach(cleanup);

const stats = {
    triage: 0,
    investigating: 0,
    actions: 0,
    closed: 0,
    total_open: 0,
    recent: 0,
    reached: 0,
    near_miss: 0,
    unknown_reach: 0,
    trend: [],
};

const props: ComponentProps<typeof MedicationErrors> = {
    errors: [],
    detail: null,
    filters: { tab: 'triage', q: '', reach: 'all', site_id: null },
    pagination: { links: [], total: 0, from: null, to: null, last_page: 1 },
    stats,
    clients: [],
    staff: [],
    sites: [
        { id: 1, name: 'Kōwhai House' },
        { id: 2, name: 'Rimu House' },
    ],
    export_period: { date_from: '2026-07-21', date_to: '2026-10-18' },
    can: {
        record: true,
        manage: true,
        all: true,
        export: true,
        controlled: true,
    },
};

describe('Medication errors header and empty state (P08b, 1440 walk)', () => {
    it('is a Safety & oversight view with its houses and the approved search', () => {
        render(<MedicationErrors {...props} />);
        expect(screen.getByText('Safety & oversight')).toBeInTheDocument();
        expect(screen.getByText('2 houses')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Medication errors at Kōwhai House and Rimu House · times in Pacific/Auckland',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Search people or MED number'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^As at / }),
        ).toBeInTheDocument();
    });

    it('says no errors have been reported when there are none at all', () => {
        render(<MedicationErrors {...props} />);
        expect(
            screen.getByText('No medication errors reported'),
        ).toBeInTheDocument();
        expect(screen.queryByText('No errors in this view')).toBeNull();
        expect(
            screen.getAllByRole('button', { name: /Report an error/ }).length,
        ).toBe(2);
    });

    it('keeps the filtered empty state when errors exist in other views', () => {
        render(<MedicationErrors {...props} stats={{ ...stats, closed: 3 }} />);
        expect(screen.getByText('No errors in this view')).toBeInTheDocument();
        expect(screen.queryByText('No medication errors reported')).toBeNull();
    });
});
