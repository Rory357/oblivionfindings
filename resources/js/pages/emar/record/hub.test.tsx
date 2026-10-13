import { fireEvent, render, screen, within } from '@testing-library/react';
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
    DosePicker: () => null,
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
const menuItems = (name: string) => {
    fireEvent.contextMenu(screen.getByText(name).closest('[role="row"]')!);
    return within(screen.getByRole('menu'))
        .getAllByRole('menuitem')
        .map((item) => item.textContent);
};
describe('MAR chart discovery', () => {
    it('opens the record from the named identity link and the row menu, without recording authority', () => {
        render(<MedicationRecordHub {...props} />);
        expect(
            screen.getByRole('link', { name: 'Test Person' }),
        ).toHaveAttribute('href', props.page.data[0].href);
        // EA-111 (Lane C9): the approved row menu.
        const items = menuItems('Test Person');
        expect(items).toContain('Open Test Person’s medication record');
        expect(items).toContain('Open the client profile');
        expect(items).not.toContain('Record a dose');
        expect(items).not.toContain('Print MAR');
    });

    it('shows the approved meters and offers recording, why and Print MAR when they apply', () => {
        render(
            <MedicationRecordHub
                {...props}
                can_report
                meters={{
                    people: 1,
                    medicines: 3,
                    due: 2,
                    overdue: 1,
                    to_check: 1,
                    due_people: 1,
                    help: 1,
                    recorded: 1,
                    so_far: 4,
                    as_needed: 2,
                    effect_overdue: 1,
                }}
                page={{
                    ...props.page,
                    data: [
                        {
                            ...props.page.data[0],
                            due: 2,
                            help: 1,
                            help_reason:
                                'A change to the order is waiting to be checked',
                            can_record: true,
                        },
                    ],
                }}
            />,
        );
        for (const label of [
            'Due now',
            'Late',
            'Needs help',
            'Recorded today',
            'Medicines',
            'As needed · 24 h',
        ]) {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        }
        expect(screen.getByText('Blocked — see why')).toBeInTheDocument();
        expect(screen.getByText('1 order to check')).toBeInTheDocument();
        expect(screen.getByText('1 effect check overdue')).toBeInTheDocument();
        expect(
            screen.getByText('A change to the order is waiting to be checked'),
        ).toBeInTheDocument();
        const items = menuItems('Test Person');
        expect(items).toEqual(
            expect.arrayContaining([
                'Record a dose',
                'Why can’t these be recorded?',
                'Print MAR',
            ]),
        );
    });
});
