import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { cellKind, cellLabel, recordBlock } from './dose-cell';
import { MarDayGrid, MarDayLegend, nowColumn } from './mar-day-grid';
import type { DayDose, DayMedicine, MedicationDay } from './types';

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));

function dose(time: string, over: Partial<DayDose> = {}): DayDose {
    return {
        key: `1:${time}`,
        client_id: 7,
        client_name: 'Aroha Ngata',
        medication_id: 1,
        medication_name: 'Metformin',
        dose: '500 mg',
        route: 'Oral',
        is_controlled: false,
        requires_witness: false,
        scheduled_for: `2026-06-15T${time}:00+12:00`,
        time,
        round_label: 'Morning',
        status: 'upcoming',
        away_reason: null,
        recorded: null,
        mar_url: '/emar/mar?client_id=7',
        state: 'not_due',
        window_opens_at: null,
        window_ends_at: null,
        ...over,
    };
}

function medicine(name: string, cells: Record<string, DayDose[]>): DayMedicine {
    return {
        id: name.length,
        name,
        dose: '500 mg',
        route: 'Oral',
        is_controlled: false,
        requires_witness: false,
        cells,
    };
}

function day(over: Partial<MedicationDay> = {}): MedicationDay {
    return {
        date: '2026-06-15',
        today: '2026-06-15',
        tomorrow: '2026-06-16',
        now: '2026-06-15T14:30:00+12:00',
        timezone: 'Pacific/Auckland',
        coverage: {
            available_from: '2026-06-01',
            complete: true,
            notice: null,
        },
        times: ['08:00', '13:00', '20:00'],
        medicines: [
            medicine('Metformin', {
                '08:00': [
                    dose('08:00', {
                        status: 'given',
                        state: 'given',
                        recorded: {
                            id: 91,
                            status: 'given',
                            administered_at: null,
                            time: '08:05',
                            by: 'Priya Shah',
                            witness: null,
                            reason: null,
                            reason_label: null,
                            notes: null,
                        },
                    }),
                ],
                '20:00': [dose('20:00')],
            }),
            medicine('Iron', {
                '13:00': [dose('13:00', { status: 'overdue', state: 'late' })],
            }),
        ],
        hidden_controlled: { total: 0, overdue: 0 },
        prn: { rows: [], hidden: 0 },
        allergies: { status: 'none', entries: [] },
        chart_alerts: [],
        can: {
            record: true,
            record_reason: null,
            record_controlled: false,
            report: true,
        },
        recorder: null,
        ...over,
    };
}

afterEach(() => cleanup());

describe('dose cells', () => {
    it('speaks Meds today’s words for every state', () => {
        const cases: [Partial<DayDose>, boolean, string][] = [
            [{ status: 'due', state: 'due' }, true, 'Due now'],
            [{ status: 'due', state: 'not_due' }, true, 'Due'],
            [{ status: 'overdue', state: 'late' }, true, 'Overdue'],
            [
                { status: 'overdue', state: 'not_recorded' },
                false,
                'Not recorded',
            ],
            [{ status: 'refused' }, true, 'Refused'],
            [{ status: 'withheld' }, true, 'Withheld'],
            [{ status: 'missed' }, true, 'Missed (recorded)'],
            [{ status: 'pending_check' }, true, 'Waiting for the order check'],
            [
                {
                    status: 'away',
                    away_reason: 'In hospital (since Mon 15 Jun)',
                },
                true,
                'Away · In hospital (since Mon 15 Jun)',
            ],
            [{ status: 'upcoming' }, true, 'Upcoming'],
        ];
        for (const [over, isToday, label] of cases) {
            const d = dose('08:00', over);
            expect(cellLabel(d, cellKind(d, isToday))).toBe(label);
        }
        const given = day().medicines[0].cells['08:00'][0];
        expect(cellLabel(given, cellKind(given, true))).toBe('Given ✓ 8:05 am');
    });

    it('says why a due dose can’t be recorded here', () => {
        const due = dose('08:00', { status: 'due', state: 'due' });
        const kind = cellKind(due, true);
        expect(recordBlock(due, kind, day(), 'Aroha')).toBeNull();
        expect(
            recordBlock(
                due,
                kind,
                day({
                    can: {
                        ...day().can,
                        record: false,
                        record_reason: 'no_shift',
                    },
                }),
                'Aroha',
            ),
        ).toBe('Clock in to a shift covering Aroha to record their doses.');
        expect(
            recordBlock({ ...due, is_controlled: true }, kind, day(), 'Aroha'),
        ).toBe('Controlled doses need controlled-medicine recording access.');
        expect(
            recordBlock(due, kind, day({ date: '2026-06-14' }), 'Aroha'),
        ).toBe('Only today’s doses are recorded here.');
    });

    it('places "now" after the last dose time already passed', () => {
        expect(nowColumn(['08:00', '13:00', '20:00'], '14:30')).toBe(1);
        expect(nowColumn(['08:00', '13:00'], '07:00')).toBe(-1);
    });
});

describe('MarDayGrid', () => {
    it('records a due dose, opens a recorded one, and does nothing for the rest', () => {
        const onRecord = vi.fn();
        const onOpenDose = vi.fn();
        render(
            <MarDayGrid
                day={day()}
                personName="Aroha"
                onRecord={onRecord}
                onOpenDose={onOpenDose}
                onMedicineDetails={() => {}}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Iron, 1:00 pm: Overdue/ }),
        );
        expect(onRecord).toHaveBeenCalledTimes(1);
        expect(onRecord.mock.calls[0][0].time).toBe('13:00');

        fireEvent.click(
            screen.getByRole('button', { name: /Metformin, 8:00 am: Given/ }),
        );
        expect(onOpenDose).toHaveBeenCalledTimes(1);

        fireEvent.click(
            screen.getByRole('button', {
                name: /Metformin, 8:00 pm: Upcoming/,
            }),
        );
        expect(onRecord).toHaveBeenCalledTimes(1);

        expect(screen.getByText('Now 2:30 pm')).toBeTruthy();
    });

    it('moves focus through the grid with the arrow keys', () => {
        render(
            <MarDayGrid
                day={day()}
                personName="Aroha"
                onRecord={() => {}}
                onOpenDose={() => {}}
                onMedicineDetails={() => {}}
            />,
        );
        const first = screen.getByRole('button', {
            name: /Metformin, 8:00 am/,
        });
        first.focus();
        fireEvent.keyDown(first, { key: 'ArrowRight' });
        expect(document.activeElement?.getAttribute('aria-label')).toBe(
            'No dose at 1:00 pm',
        );
        fireEvent.keyDown(document.activeElement!, { key: 'ArrowDown' });
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /Iron, 1:00 pm/,
        );
    });

    it('shows the legend in the grid’s own words', () => {
        render(<MarDayLegend kinds={['overdue', 'given', 'pending_check']} />);
        expect(screen.getByText('Overdue')).toBeTruthy();
        expect(screen.getByText('Given')).toBeTruthy();
        expect(screen.getByText('Waiting for the order check')).toBeTruthy();
    });
});
