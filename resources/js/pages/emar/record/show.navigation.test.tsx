import type { RecordPageProps } from '@/components/emar/record/types';
import { Button } from '@/components/ui/button';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ url: '', push: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
    usePage: () => ({ url: state.url, props: {} }),
    router: {
        push: (visit: { url: string }) => {
            state.url = visit.url;
            state.push(visit);
        },
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/emar/record/record-dose-launch', () => ({
    RecordDoseLaunch: () => null,
}));
vi.mock('@/components/emar/record/chart', () => ({
    ChartSection: ({
        date,
        week,
        onChange,
    }: {
        date: string;
        week: boolean;
        onChange: (date: string, week: boolean) => void;
    }) => (
        <section aria-label="Chart contents">
            {date} · {week ? 'week' : 'day'}
            <Button onClick={() => onChange('2026-10-03', false)}>
                Previous day
            </Button>
        </section>
    ),
}));
vi.mock('@/components/emar/record/clinical', () => ({
    ClinicalSection: () => <section>Clinical contents</section>,
}));
vi.mock('@/components/emar/record/history', () => ({
    HistorySection: () => null,
}));
vi.mock('@/components/emar/record/reading', () => ({
    MedicinesSection: () => null,
    RecordMedicineDialog: () => null,
}));
vi.mock('@/components/emar/record/safety', () => ({
    SafetySection: () => null,
}));
vi.mock('@/components/emar/record/support', () => ({
    CanonicalSupportSection: () => null,
}));

import PersonMedicationRecord from './show';

const props: RecordPageProps = {
    person: {
        id: 10,
        name: 'Ada Synthetic',
        preferred: 'Ada',
        initials: 'AS',
        age: 44,
        nhi: null,
        status: 'Active',
        house: 'Synthetic house',
        service: 'Supported living',
    },
    meters: {
        medicines: { count: 2, hidden: 0, as_needed: 0, to_check: 0 },
        allergies: { status: 'none', count: 0 },
        inr: null,
        driver: null,
    },
    can: { manage_orders: false, view_controlled: false, view_audit: false },
    as_at: '2026-10-05T10:00:00+13:00',
};

beforeEach(() => {
    state.push.mockClear();
    state.url = '/emar/mar?client_id=10&site_id=3&date=2026-10-04&mode=week';
});

describe('person medication record context', () => {
    it('retains the person, house, historical date and chart mode through Clinical and back', () => {
        render(<PersonMedicationRecord {...props} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Clinical' }));
        expect(screen.getByText('Clinical contents')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Chart' }));
        expect(
            screen.getByRole('region', { name: 'Chart contents' }),
        ).toHaveTextContent('2026-10-04 · week');
        expect(
            Object.fromEntries(
                new URL(state.url, 'https://example.test').searchParams,
            ),
        ).toEqual({
            client_id: '10',
            date: '2026-10-04',
            site_id: '3',
            mode: 'week',
        });
        const back = new URL(
            screen.getByRole('link', { name: 'Back' }).getAttribute('href')!,
            'https://example.test',
        );
        expect(back.searchParams.get('date')).toBe('2026-10-04');
        expect(back.searchParams.get('site_id')).toBe('3');
        expect(back.searchParams.has('client_id')).toBe(false);
    });

    it('changes the day without losing the house or keeping week mode', () => {
        const page = render(<PersonMedicationRecord {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Previous day' }));
        page.rerender(<PersonMedicationRecord {...props} />);
        expect(
            screen.getByRole('region', { name: 'Chart contents' }),
        ).toHaveTextContent('2026-10-03 · day');
        expect(
            Object.fromEntries(
                new URL(state.url, 'https://example.test').searchParams,
            ),
        ).toEqual({ client_id: '10', date: '2026-10-03', site_id: '3' });
    });
});
