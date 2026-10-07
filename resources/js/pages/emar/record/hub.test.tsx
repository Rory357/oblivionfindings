import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import MedicationRecordHub from './hub';
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({ url: '/emar/mar?site_id=3&date=2026-10-04', props: {} }),
    router: { get: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/emar/record/record-dose-launch', () => ({
    RecordDoseLaunch: () => null,
}));
vi.mock('@/components/emar/medication-export-button', () => ({
    MedicationExportButton: () => null,
}));
const props: ComponentProps<typeof MedicationRecordHub> = {
    view: 'charts',
    filters: {
        q: '',
        site_id: 3,
        client_id: null,
        date: '2026-10-04',
        status: 'current',
        range: 30,
    },
    today: '2026-10-07',
    as_at: '2026-10-07T09:00:00+13:00',
    sites: [{ id: 3, name: 'Fictional house' }],
    page: {
        data: [
            {
                key: 'p16',
                id: 16,
                client_id: 16,
                person: 'Test Person',
                house: 'Fictional house',
                href: '/emar/mar?client_id=16&date=2026-10-04',
                can_record: false,
            },
        ],
        current_page: 1,
        last_page: 1,
        from: 1,
        to: 1,
        total: 1,
    },
    meters: { people: 1, medicines: 1, due: 0, overdue: 0 },
    controlled_left_out: false,
    can_report: false,
    coverage: { complete: true, notice: null },
};
describe('MAR chart discovery', () => {
    it('offers both a named identity link and an explicit chart action without recording authority', () => {
        render(<MedicationRecordHub {...props} />);
        expect(
            screen.getByRole('link', { name: 'Test Person' }),
        ).toHaveAttribute('href', props.page.data[0].href);
        expect(
            screen.getByRole('link', {
                name: 'Open MAR chart for Test Person',
            }),
        ).toHaveAttribute('href', props.page.data[0].href);
    });
});
