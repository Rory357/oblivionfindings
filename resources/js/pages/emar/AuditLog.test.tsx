import type { AuditEvent } from '@/components/emar/medication-event-drawer';
import { Button } from '@/components/ui/button';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    url: '/emar/reports/history?site_id=8&client_id=42',
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: inertia.get, visit: inertia.visit },
    usePage: () => ({ url: inertia.url, props: {} }),
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
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/hooks/use-emar-breadcrumbs', () => ({
    useEmarBreadcrumbs: () => [],
}));
vi.mock('@/components/emar/historical-audit-export-dialog', () => ({
    HistoricalAuditExportDialog: ({ url }: { url: string }) => (
        <p role="dialog">Export {url}</p>
    ),
}));
vi.mock('@/components/emar/medication-event-drawer', async (importOriginal) => {
    const actual =
        await importOriginal<
            typeof import('@/components/emar/medication-event-drawer')
        >();
    return {
        ...actual,
        MedicationEventDrawer: ({
            event,
            onExport,
        }: {
            event: AuditEvent;
            onExport?: () => void;
        }) => (
            <div role="dialog">
                Record {event.id}
                {onExport && <Button onClick={onExport}>Export record</Button>}
            </div>
        ),
    };
});

import AuditLog from './AuditLog';

const administration: AuditEvent = {
    id: 'admin_17',
    event_type: 'dose_administered',
    category: 'doses',
    timestamp: '2026-09-26T12:05:00Z',
    description: 'Aspirin given to Ada',
    performed_by: 'Jo',
    witness: null,
    witness_required: false,
    client_id: 42,
    client_name: 'Ada',
    site_id: 8,
    site_name: 'Maple House',
    details: {},
    outcome: 'Given',
    flags: [],
    source: 'MAR',
};
const stock: AuditEvent = {
    ...administration,
    id: 'stock_2',
    event_type: 'stock_received',
    category: 'stock',
    timestamp: '2026-09-26T12:10:00Z',
    description: 'Stock received for Ada',
    source: 'Pharmacy',
};
const props: ComponentProps<typeof AuditLog> = {
    events: [stock, administration],
    stats: { total: 2, this_week: 2, this_month: 2, open_gaps: 0 },
    filters: {
        client_id: 42,
        date_from: '2026-09-27',
        date_to: '2026-09-27',
        event_types: ['dose_administered', 'stock_received'],
    },
    clients: [{ id: 42, name: 'Ada' }],
    staff: [{ id: 1, name: 'Jo' }],
    sites: [{ id: 8, name: 'Maple House' }],
    active_site: { id: 8, name: 'Maple House' },
    site_brand_colour: null,
    user_first_name: 'Jo',
    can_export_history: true,
    export_purposes: { audit: 'Audit' },
};

beforeEach(() => {
    inertia.url = '/emar/reports/history?site_id=8&client_id=42';
    vi.clearAllMocks();
    window.sessionStorage.clear();
});
afterEach(cleanup);

describe('Medication history server periods and frontline controls', () => {
    it('carries only supported person, Site and NZ dates in actual navigation anchors with multiple history types selected', () => {
        inertia.url += '&history_view=table&history_staff=Jo';
        render(
            <AuditLog
                {...props}
                filters={{
                    ...props.filters,
                    date_from: '2026-09-21',
                    date_to: '2026-09-27',
                    event_types: ['dose_administered', 'correction_approved'],
                }}
            />,
        );
        for (const [label, path] of [
            ['Change log', '/emar/reports/history/logs'],
            ['Print MAR & CD register', '/emar/reports'],
        ]) {
            const link = screen.getByRole('link', { name: label });
            const destination = new URL(
                link.getAttribute('href')!,
                'https://oblivionfindings.test',
            );
            expect(destination.pathname).toBe(path);
            expect([...destination.searchParams.entries()]).toEqual([
                ['client_id', '42'],
                ['site_id', '8'],
                ['date_from', '2026-09-21'],
                ['date_to', '2026-09-27'],
            ]);
            expect(destination.searchParams.has('event_types')).toBe(false);
            expect(destination.searchParams.has('event_types[]')).toBe(false);
            expect(destination.searchParams.has('history_view')).toBe(false);
            expect(destination.searchParams.has('history_staff')).toBe(false);
        }
    });

    it('initializes from returned dates, renders both NZ records and requests explicit dates with current scope', () => {
        render(<AuditLog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Custom dates' }));
        expect(screen.getByLabelText('From (NZ date)')).toHaveValue(
            '2026-09-27',
        );
        expect(screen.getByLabelText('To (NZ date)')).toHaveValue('2026-09-27');
        expect(
            screen.getByRole('tab', { name: 'Doses 1' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('tab', { name: 'Stock 1' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Aspirin given to Ada')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('From (NZ date)'), {
            target: { value: '2026-04-05' },
        });
        fireEvent.change(screen.getByLabelText('To (NZ date)'), {
            target: { value: '2026-04-05' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply dates' }));
        expect(inertia.get).toHaveBeenCalledWith(
            '/emar/reports/history',
            expect.objectContaining({
                date_from: '2026-04-05',
                date_to: '2026-04-05',
                site_id: 8,
                client_id: 42,
                event_types: props.filters.event_types,
            }),
            { preserveState: true, preserveScroll: true },
        );
    });

    it('requests an inclusive preset from the server and preserves the requested person and Site', () => {
        render(<AuditLog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Custom period' }));
        fireEvent.click(screen.getByRole('button', { name: '7 days' }));
        expect(inertia.get).toHaveBeenCalledWith(
            '/emar/reports/history',
            expect.objectContaining({
                date_from: '2026-09-21',
                date_to: '2026-09-27',
                site_id: 8,
                client_id: 42,
            }),
            expect.anything(),
        );
    });

    it('preserves dates and event types while requesting a different Site or person', () => {
        render(
            <AuditLog
                {...props}
                sites={[...props.sites, { id: 9, name: 'Other House' }]}
                clients={[...props.clients, { id: 43, name: 'Ben' }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Maple House' }));
        fireEvent.click(screen.getByRole('button', { name: 'Other House' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/emar/reports/history',
            expect.objectContaining({
                site_id: 9,
                client_id: 42,
                date_from: '2026-09-27',
                date_to: '2026-09-27',
                event_types: props.filters.event_types,
            }),
            expect.anything(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Ada' }));
        fireEvent.click(screen.getByRole('button', { name: 'Ben' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/emar/reports/history',
            expect.objectContaining({
                site_id: 8,
                client_id: 43,
                date_from: '2026-09-27',
                date_to: '2026-09-27',
                event_types: props.filters.event_types,
            }),
            expect.anything(),
        );
    });

    it('resets dates and category counts to the returned window on back/forward navigation', () => {
        const { rerender } = render(<AuditLog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Custom dates' }));
        fireEvent.change(screen.getByLabelText('From (NZ date)'), {
            target: { value: '2026-04-05' },
        });
        inertia.url =
            '/emar/reports/history?site_id=8&client_id=42&date_from=2026-04-05&date_to=2026-04-05&history_view=table';
        rerender(
            <AuditLog
                {...props}
                events={[stock]}
                filters={{
                    ...props.filters,
                    date_from: '2026-04-05',
                    date_to: '2026-04-05',
                }}
            />,
        );
        expect(screen.getByLabelText('To (NZ date)')).toHaveValue('2026-04-05');
        expect(
            screen.getByRole('tab', { name: 'Doses 0' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Table' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        inertia.url = '/emar/reports/history?site_id=8&client_id=42';
        rerender(<AuditLog {...props} />);
        expect(screen.getByLabelText('From (NZ date)')).toHaveValue(
            '2026-09-27',
        );
        expect(
            screen.getByRole('tab', { name: 'Doses 1' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Timeline' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
    });

    it('keeps a visible coverage notice and uses a bounded empty state for Needs review', () => {
        render(
            <AuditLog
                {...props}
                history_notice="Showing the latest 800 historical records. Choose a shorter period to see the remaining records."
                omissions_notice="Not available before 26 Sep 2026"
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: /^Needs review/ }));
        expect(
            screen.getByText(/No flagged records in the loaded results/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Showing the latest 800 historical records/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/MAR omissions: Not available before/),
        ).toBeInTheDocument();
    });

    it('uses the shared frontline header, separate print link, 44px control classes and touch-accessible record actions', () => {
        const { container } = render(<AuditLog {...props} />);
        expect(container.querySelector('header')).toHaveClass(
            'eh-header-frontline',
        );
        const print = screen.getByRole('link', {
            name: 'Print MAR & CD register',
        });
        expect(print.querySelector('button')).toBeNull();
        expect(print).toHaveClass('frontline-tap');
        for (const control of container.querySelectorAll(
            'button, input, select, a',
        ))
            expect(control.className).toMatch(/frontline-(tap|hit)/);
        const actions = screen.getByRole('button', {
            name: 'Actions for Aspirin given to Ada',
        });
        actions.focus();
        fireEvent.click(actions);
        let menu = screen.getByRole('menu', { name: 'Record actions' });
        for (const control of within(menu).getAllByRole('menuitem'))
            expect(control).toHaveClass('frontline-tap');
        expect(
            within(menu).getByRole('menuitem', {
                name: /Copy record reference/,
            }),
        ).toBeInTheDocument();
        expect(
            within(menu).getByRole('menuitem', { name: /View record/ }),
        ).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'End' });
        expect(
            within(menu).getByRole('menuitem', {
                name: /Copy record reference/,
            }),
        ).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(actions).toHaveFocus();
        fireEvent.click(actions);
        menu = screen.getByRole('menu', { name: 'Record actions' });
        fireEvent.click(
            within(menu).getByRole('menuitem', { name: /Export this record/ }),
        );
        expect(screen.getByRole('dialog')).toHaveTextContent(
            '/emar/audit/event/admin_17/export',
        );
    });

    it('keeps mobile table cards and keyboard record links on the desktop table', () => {
        const { container } = render(<AuditLog {...props} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Table' }));
        expect(container.querySelector('.md\\:hidden')).toHaveTextContent(
            'Outcome: Given',
        );
        expect(container.querySelector('table')?.parentElement).toHaveClass(
            'hidden',
            'md:block',
        );
        const table = screen.getByRole('table');
        for (const recordLink of within(table).getAllByRole('button', {
            name: /View record/,
        }))
            expect(recordLink).toHaveClass('frontline-tap');
    });

    it.each([
        [false, administration],
        [
            true,
            {
                ...administration,
                id: 'omission_17',
                event_type: 'omission',
                flags: ['omission'],
            },
        ],
    ])(
        'keeps export actions absent for permission %s and record %j',
        (permission, record) => {
            render(
                <AuditLog
                    {...props}
                    events={[record]}
                    can_export_history={permission}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Actions for Aspirin given to Ada',
                }),
            );
            expect(
                screen.queryByRole('menuitem', { name: /Export this record/ }),
            ).not.toBeInTheDocument();
        },
    );
});
