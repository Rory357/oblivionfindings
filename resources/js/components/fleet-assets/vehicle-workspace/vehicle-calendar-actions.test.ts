import { describe, expect, it } from 'vitest';
import type {
    BookingRow,
    UnavailableRow,
    VehicleCalendarSummary,
} from './calendar-types';
import type { ObligationReminder, VehicleReminder } from './types';
import {
    creationActions,
    custodyActions,
    dayNavigationActions,
    entryActions,
    openEntryLabel,
    type CalendarEntry,
    type EntryActionContext,
} from './vehicle-calendar-actions';

const can = {
    view_bookings: true,
    request: true,
    manage: true,
    approve: true,
    authority: true,
    schedule_service: true,
    report_work: true,
    add_reminder: true,
    mark_unavailable: true,
    view_maintenance: true,
    review_release: false,
};

const summary = (
    overrides: Partial<VehicleCalendarSummary> = {},
): VehicleCalendarSummary =>
    ({
        asset: {
            id: 7,
            name: 'Kōwhai van',
            asset_tag: null,
            registration_number: null,
        },
        restriction: null,
        use_problem: null,
        use_problem_code: null,
        use_problem_kind: null,
        readiness_label: 'Ready',
        next_appointment: null,
        next_due: null,
        bookings: [],
        drivers: [],
        open_work: [],
        can,
        ...overrides,
    }) as VehicleCalendarSummary;

const entry = (overrides: Partial<CalendarEntry>): CalendarEntry =>
    ({
        id: 'x',
        title: 'Entry',
        start: '2026-09-25T09:00:00+12:00',
        end: '2026-09-25T10:00:00+12:00',
        source: 'respite',
        status: 'scheduled',
        kind: 'booking',
        statusLabel: 'Confirmed',
        recordId: 1,
        workOrderId: null,
        desc: null,
        _start: new Date('2026-09-25T09:00:00+12:00'),
        ...overrides,
    }) as CalendarEntry;

const booking = (overrides: Partial<BookingRow> = {}): BookingRow =>
    ({
        kind: 'booking',
        id: 1,
        reference: 'BK-1',
        purpose: 'Hospital visit',
        status: 'pending',
        status_label: 'Pending approval',
        lock_version: 1,
        history: [],
        keys: [],
        files: [],
        can: {
            edit: true,
            approve: true,
            decline: true,
            checkout: false,
            return: false,
            cancel: true,
            upload: true,
        },
        ...overrides,
    }) as BookingRow;

const period = (overrides: Partial<UnavailableRow> = {}): UnavailableRow =>
    ({
        kind: 'unavailable',
        id: 4,
        reference: null,
        work_order_id: null,
        purpose: 'Panel beater',
        starts_at: '2026-09-26T09:00:00+12:00',
        ends_at: '2026-09-26T17:00:00+12:00',
        status: 'active',
        status_label: 'Unavailable',
        cancellation_reason: null,
        lock_version: 2,
        history: [],
        files: [],
        can: { edit: true, cancel: true, upload: true },
        ...overrides,
    }) as UnavailableRow;

const context = (
    overrides: Partial<EntryActionContext> = {},
): EntryActionContext => ({
    summary: summary(),
    reminders: [],
    obligations: [],
    row: null,
    vehicleId: 7,
    planStart: () => '2026-09-25T09:00',
    ...overrides,
});

const labels = (actions: Array<{ label: string }>) =>
    actions.map((action) => action.label);

describe('vehicle calendar creation menu', () => {
    it('offers the approved items for a future slot', () => {
        expect(
            labels(
                creationActions(
                    '2026-09-25T09:00',
                    '2026-09-24T12:00',
                    summary(),
                ),
            ),
        ).toEqual([
            'Request vehicle booking',
            'Schedule service or inspection',
            'Add reminder',
            'Mark vehicle unavailable',
        ]);
    });

    it('keeps past-time items visible but disabled, with the reason', () => {
        const actions = creationActions(
            '2026-09-23T09:00',
            '2026-09-24T12:00',
            summary(),
        );
        expect(actions.every((action) => action.disabled)).toBe(true);
        expect(actions[0].reason).toBe(
            'Past time · choose a future slot to schedule',
        );
    });

    it('offers a retry instead of silently dropping items when the summary failed', () => {
        const actions = creationActions(
            '2026-09-25T09:00',
            '2026-09-24T12:00',
            null,
        );
        expect(actions).toHaveLength(1);
        expect(actions[0].intent).toEqual({ type: 'retry-summary' });
    });

    it('leaves out actions the person has no permission for', () => {
        expect(
            labels(
                creationActions(
                    '2026-09-25T09:00',
                    '2026-09-24T12:00',
                    summary({
                        can: {
                            ...can,
                            request: false,
                            mark_unavailable: false,
                        },
                    }),
                ),
            ),
        ).toEqual(['Schedule service or inspection', 'Add reminder']);
    });

    it('only offers trips for past days and when trips are visible', () => {
        expect(
            labels(dayNavigationActions('2026-09-20', '2026-09-24', true)),
        ).toEqual(['View this day / availability', 'View trips on this date']);
        expect(
            labels(dayNavigationActions('2026-09-28', '2026-09-24', true)),
        ).toEqual(['View this day / availability']);
        expect(
            labels(dayNavigationActions('2026-09-20', '2026-09-24', false)),
        ).toEqual(['View this day / availability']);
    });
});

describe('vehicle calendar entry menus', () => {
    it('names the first item after where the entry opens', () => {
        expect(openEntryLabel('restriction')).toBe('View restriction reason');
        expect(openEntryLabel('appointment')).toBe('Open work order');
        expect(openEntryLabel('busy')).toBe('Open source record');
    });

    it('lists pending booking actions with destructive ones marked, and blocks approval with the readiness reason', () => {
        const blocked = summary({ use_problem: 'RUC: assess applicability.' });
        const actions = custodyActions(booking(), blocked);
        expect(labels(actions)).toEqual([
            'Edit booking',
            'Review & approve',
            'Upload evidence',
            'Decline request',
            'Cancel booking',
        ]);
        const approve = actions.find((action) => action.key === 'approve');
        expect(approve?.disabled).toBe(true);
        expect(approve?.reason).toBe('RUC: assess applicability.');
        expect(
            actions
                .filter((action) => action.destructive)
                .map((action) => action.key),
        ).toEqual(['decline', 'cancel']);
    });

    it('never offers cancelling a checked-out booking', () => {
        const actions = custodyActions(
            booking({
                status: 'checked_out',
                can: {
                    edit: false,
                    approve: false,
                    decline: false,
                    checkout: false,
                    return: true,
                    cancel: true,
                    upload: true,
                },
            }),
            summary(),
        );
        expect(labels(actions)).toEqual([
            'Record vehicle return',
            'Upload evidence',
        ]);
    });

    it('manages an appointment-held period through its appointment only', () => {
        expect(
            labels(custodyActions(period({ work_order_id: 91 }), summary())),
        ).toEqual(['Reschedule / manage appointment']);
        expect(
            labels(
                entryActions(
                    entry({
                        kind: 'unavailable',
                        workOrderId: 91,
                        meta: { held_by_appointment: true },
                    }),
                    context(),
                ),
            ),
        ).toEqual(['Reschedule / manage appointment', 'Open work order']);
        expect(labels(custodyActions(period(), summary()))).toEqual([
            'Change unavailable period',
            'Upload evidence',
            'Cancel unavailable period',
        ]);
    });

    it('gives a document renewal reminder the document to edit', () => {
        const reminder = {
            id: 3,
            title: 'Insurance renewal',
            source: { type: 'document_set', id: 12, label: 'Insurance' },
            state: 'scheduled',
        } as unknown as VehicleReminder;
        const actions = entryActions(
            entry({ kind: 'reminder', recordId: 3, source: 'compliance' }),
            context({ reminders: [reminder] }),
        );
        expect(actions[0].intent).toEqual({ type: 'edit-document', setId: 12 });
        expect(labels(actions)).toEqual([
            'Edit / reschedule reminder',
            'Snooze reminder',
            'Complete follow-up',
            'Open linked document',
            'View reminder activity',
        ]);
    });

    it('offers release review only while restricted, disabled until the work is complete', () => {
        const restriction = entry({
            kind: 'restriction',
            status: 'overdue',
            workOrderId: 55,
            meta: { work_status: 'in_progress' },
        });
        const reviewer = summary({ can: { ...can, review_release: true } });
        const release = entryActions(
            restriction,
            context({ summary: reviewer }),
        ).find((action) => action.key === 'release');
        expect(release?.disabled).toBe(true);
        expect(release?.reason).toBe(
            'Complete the repair and record a passing retest first.',
        );
        const done = entryActions(
            { ...restriction, meta: { work_status: 'completed' } },
            context({ summary: reviewer }),
        ).find((action) => action.key === 'release');
        expect(done?.disabled).toBe(false);
        expect(done?.intent).toEqual({
            type: 'open-work',
            workOrderId: 55,
            release: true,
        });
        expect(
            entryActions(
                { ...restriction, status: 'completed' },
                context({ summary: reviewer }),
            ).some((action) => action.key === 'release'),
        ).toBe(false);
    });

    it('plans a due date on its open linked work instead of creating more', () => {
        const due = entry({
            kind: 'schedule',
            recordId: 8,
            source: 'compliance',
            title: '12-month service due · reminder',
        });
        const fresh = entryActions(due, context());
        expect(fresh[0].intent).toEqual({
            type: 'plan-linked',
            startLocal: '2026-09-25T09:00',
            presetType: '12-month service',
            source: { type: 'service_schedule', id: 8 },
        });
        const linked = entryActions(
            due,
            context({
                summary: summary({
                    open_work: [
                        {
                            id: 70,
                            reference: 'WO-70',
                            title: '12-month service',
                            status: 'open',
                            version: 1,
                            source: { type: 'service_schedule', id: 8 },
                        },
                    ],
                }),
            }),
        );
        expect(linked[0].intent).toMatchObject({
            type: 'manage-appointment',
            workOrderId: 70,
        });
    });

    it('gives a vehicle check due date its own reminder actions', () => {
        const obligation = {
            key: 'vehicle_check:7',
            source_type: 'vehicle_check',
            can: { acknowledge: true, retry: false },
        } as unknown as ObligationReminder;
        const actions = entryActions(
            // The feed sends no record id for a check due date.
            entry({ kind: 'check', recordId: undefined, source: 'compliance' }),
            context({ obligations: [obligation] }),
        );
        expect(labels(actions)).toEqual([
            'Add follow-up reminder',
            'Acknowledge reminder',
            'View reminder activity',
        ]);
        expect(actions[0].intent).toEqual({
            type: 'add-follow-up',
            source: 'vehicle',
        });
    });

    it('offers a retry for summary-backed items when the summary failed', () => {
        const actions = entryActions(
            entry({ kind: 'booking' }),
            context({ summary: null }),
        );
        expect(actions.map((action) => action.intent.type)).toEqual([
            'retry-summary',
        ]);
    });
});
