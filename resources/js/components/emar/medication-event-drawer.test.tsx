import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    eventPrimaryLink,
    MedicationEventDrawer,
    type AuditEvent,
} from './medication-event-drawer';

const visit = vi.hoisted(() => vi.fn());
const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    router: { visit, post },
}));

const event = (overrides: Partial<AuditEvent> = {}): AuditEvent => ({
    id: 'med_start_17',
    event_type: 'medication_started',
    timestamp: '2026-10-03T10:00:00Z',
    description: 'Aspirin started for Ada',
    performed_by: 'Jo',
    witness: null,
    witness_required: false,
    client_id: 42,
    client_name: 'Ada',
    details: {},
    category: 'clinical',
    source: 'MAR',
    site_id: 8,
    site_name: 'Maple House',
    outcome: null,
    flags: [],
    ...overrides,
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    vi.clearAllMocks();
});

describe('history event destinations', () => {
    it.each(['medication_started', 'medication_changed', 'medication_ceased'])(
        'opens Medicines for %s rather than the clinical category review list',
        (event_type) => {
            expect(eventPrimaryLink(event({ event_type }))).toEqual({
                href: '/emar/medications?client_id=42',
                label: 'Medicines',
            });
        },
    );

    it('distinguishes a stock receipt from a destruction with the same category and source', () => {
        const stock = { category: 'stock', source: 'Stock' };
        expect(
            eventPrimaryLink(event({ ...stock, event_type: 'stock_received' })),
        ).toEqual({
            href: '/emar/stock?client_id=42',
            label: 'Stock',
        });
        expect(
            eventPrimaryLink(event({ ...stock, event_type: 'destruction' })),
        ).toEqual({
            href: '/emar/controlled?view=destructions&client_id=42',
            label: 'Destruction register',
        });
    });

    it('opens completed reviews in the supported recorded view for the person', () => {
        expect(
            eventPrimaryLink(
                event({ event_type: 'review_completed', source: 'Clinical' }),
            ),
        ).toEqual({
            href: '/emar/reviews?view=recorded&client_id=42',
            label: 'Reviews',
        });
    });

    it.each([
        'dose_administered',
        'dose_refused',
        'dose_missed',
        'dose_withheld',
        'dose_pending',
        'dose_recorded',
        'omission',
        'correction_submitted',
        'correction_approved',
        'correction_rejected',
        'correction_recorded',
    ])('keeps %s on the person MAR chart', (event_type) => {
        expect(
            eventPrimaryLink(event({ event_type, category: 'doses' })),
        ).toEqual({
            href: '/emar/mar?client_id=42',
            label: 'MAR chart',
        });
    });

    it.each([
        'cd_given',
        'cd_received',
        'cd_wasted',
        'cd_adjustment',
        'cd_balance_check',
    ])('opens %s with the controlled reader person filter', (event_type) => {
        expect(
            eventPrimaryLink(
                event({ event_type, category: 'controlled', source: 'CD' }),
            ),
        ).toEqual({
            href: '/emar/controlled?client_id=42',
            label: 'CD register',
        });
    });

    it.each([
        {
            event_type: 'prescriber_order',
            category: 'clinical',
            source: 'Orders',
            href: '/emar/prescriptions',
            label: 'Orders',
        },
        {
            event_type: 'medication_error',
            category: 'errors',
            source: 'Errors',
            href: '/emar/errors',
            label: 'Error register',
        },
    ])(
        'does not invent a person list filter for $event_type',
        ({ href, label, ...overrides }) => {
            expect(eventPrimaryLink(event(overrides))).toEqual({ href, label });
        },
    );

    it('uses the producer source for an unrecognised event rather than assuming every clinical event is a review', () => {
        expect(
            eventPrimaryLink(
                event({ event_type: 'new_event', source: 'Orders' }),
            ),
        ).toEqual({
            href: '/emar/prescriptions',
            label: 'Orders',
        });
        expect(
            eventPrimaryLink(
                event({ event_type: 'new_event', source: 'Other' }),
            ),
        ).toEqual({
            href: '/emar',
            label: 'eMAR',
        });
    });

    it.each([null, 0, -1, 1.5])(
        'omits an absent or invalid person identifier (%s)',
        (client_id) => {
            expect(eventPrimaryLink(event({ client_id }))?.href).toBe(
                '/emar/medications',
            );
        },
    );

    it.each([
        {
            event_type: 'medication_started',
            category: 'clinical',
            source: 'MAR',
            href: '/emar/medications?client_id=42',
            label: 'Medicines',
        },
        {
            event_type: 'stock_received',
            category: 'stock',
            source: 'Stock',
            href: '/emar/stock?client_id=42',
            label: 'Stock',
        },
        {
            event_type: 'review_completed',
            category: 'clinical',
            source: 'Clinical',
            href: '/emar/reviews?view=recorded&client_id=42',
            label: 'Reviews',
        },
        {
            event_type: 'destruction',
            category: 'stock',
            source: 'Stock',
            href: '/emar/controlled?view=destructions&client_id=42',
            label: 'Destruction register',
        },
    ])(
        'keeps the drawer chip and primary action consistent for $event_type',
        ({ href, label, ...overrides }) => {
            vi.stubGlobal(
                'fetch',
                vi.fn(() => new Promise(() => {})),
            );
            render(
                <MedicationEventDrawer
                    event={event(overrides)}
                    onClose={vi.fn()}
                />,
            );
            expect(screen.getByRole('link', { name: label })).toHaveAttribute(
                'href',
                href,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Open on ' + label }),
            );
            expect(visit).toHaveBeenCalledWith(href);
        },
    );
});

describe('history drawer New Zealand timestamp', () => {
    it('shows the NZ date and year when a scheduled UTC instant crosses NZ midnight', () => {
        vi.stubEnv('TZ', 'UTC');
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        render(
            <MedicationEventDrawer
                event={event({
                    event_type: 'dose_pending',
                    category: 'doses',
                    timestamp: '2026-10-02T11:30:00Z',
                })}
                onClose={vi.fn()}
            />,
        );
        expect(
            screen.getByText('3 October 2026, 12:30 am'),
        ).toBeInTheDocument();
    });
});

describe('history drawer touch controls', () => {
    it('keeps secondary phone actions available in the compact menu', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ backed: true }),
            }),
        );
        const onExport = vi.fn();
        render(
            <MedicationEventDrawer
                event={event()}
                onClose={vi.fn()}
                onExport={onExport}
            />,
        );
        await screen.findByRole('button', { name: 'Flag for investigation' });
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'More record actions' }),
            { key: 'ArrowDown' },
        );
        const exportAction = await screen.findByRole('menuitem', {
            name: 'Export event',
        });
        expect(
            screen.getByRole('menuitem', { name: 'Verify integrity' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('menuitem', { name: 'Flag for investigation' }),
        ).toBeEnabled();
        expect(exportAction).toHaveClass('frontline-tap');
        fireEvent.click(exportAction);
        expect(onExport).toHaveBeenCalledOnce();
        expect(post).not.toHaveBeenCalled();
    });
    it('gives the real close controls, section rail, footer actions and chips the shared tap/focus styles', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ backed: true }),
            }),
        );
        render(
            <MedicationEventDrawer
                event={event({ flags: ['no_actor'] })}
                onClose={vi.fn()}
                onExport={vi.fn()}
            />,
        );
        await screen.findByRole('button', { name: 'Flag for investigation' });
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getAllByRole('button', { name: 'Close' }),
        ).toHaveLength(2);
        const controls = [
            ...within(dialog).getAllByRole('button'),
            ...within(dialog).getAllByRole('link'),
        ];
        for (const control of controls) {
            expect(control).toHaveClass('frontline-tap', 'frontline-focus');
        }
        for (const name of ['Medicines', 'Client profile']) {
            expect(within(dialog).getByRole('link', { name })).toHaveClass(
                'inline-flex',
                'items-center',
            );
        }
        expect(
            within(dialog).getByRole('button', { name: 'Export event' }),
        ).toBeEnabled();
        expect(dialog.querySelector('[data-wizard-region="body"]')).toHaveClass(
            'min-h-0',
            'overflow-y-auto',
        );
        expect(visit).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it.each([
        { flags: ['no_actor'], href: '/emar/medications?client_id=42' },
        {
            flags: ['missing_witness'],
            href: '/emar/mar?client_id=42&date=2026-10-08&tab=history&dose_id=8',
        },
    ])(
        'presents the gap navigation as one truthful source-record link ($flags)',
        ({ flags, href }) => {
            vi.stubGlobal(
                'fetch',
                vi.fn(() => new Promise(() => {})),
            );
            render(
                <MedicationEventDrawer
                    event={event({
                        flags,
                        source_href: href,
                        source_label: 'Open source record',
                    })}
                    onClose={vi.fn()}
                />,
            );
            const link = screen.getByRole('link', {
                name: 'Review source record',
            });
            expect(link).toHaveAttribute('href', href);
            expect(link).toHaveAttribute('data-slot', 'button');
            expect(link).toHaveClass('frontline-tap', 'frontline-focus');
            expect(
                screen.getByRole('dialog').querySelector('a button, button a'),
            ).toBeNull();
            expect(screen.queryByText('Resolve gap')).not.toBeInTheDocument();
            expect(visit).not.toHaveBeenCalled();
            expect(post).not.toHaveBeenCalled();
        },
    );
});
