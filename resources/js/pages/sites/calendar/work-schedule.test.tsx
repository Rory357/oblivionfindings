import { fireEvent, render, screen } from '@testing-library/react';
import { ClipboardList } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';
import {
    CalendarWorkEntry,
    WorkSchedule,
    workHourBands,
} from './work-schedule';

const at = (value: string) => Date.parse(value);
const entry = (key: string, date: string | null): CalendarWorkEntry => ({
    key,
    at: date ? at(date) : null,
    title: key,
    person: 'Mere',
    icon: ClipboardList,
    source: 'checklist',
    onOpen: vi.fn(),
    openLabel: 'Open task',
});

describe('My Day shared calendar schedule', () => {
    it('keeps all simultaneous and overnight occurrences through a daylight-saving shift', () => {
        const entries = [
            entry('Evening', '2026-09-26T23:00:00+12:00'),
            entry('Before clock change', '2026-09-27T01:30:00+12:00'),
            entry('After clock change', '2026-09-27T03:30:00+13:00'),
            entry('Same time', '2026-09-27T03:30:00+13:00'),
            entry('At shift end', '2026-09-27T07:00:00+13:00'),
        ];
        const bands = workHourBands(
            entries,
            at('2026-09-26T22:00:00+12:00'),
            at('2026-09-27T07:00:00+13:00'),
        );
        expect(
            bands.flatMap((band) => band.entries.map((item) => item.key)),
        ).toEqual(entries.map((item) => item.key));
        expect(bands).toHaveLength(8);
    });

    it('keeps canonical actions and anytime work available in both layouts with time prefills', () => {
        const timed = entry(
            'Prepare activity bag',
            '2026-09-13T10:00:00+12:00',
        );
        const anytime = entry('Check shared supplies', null);
        const add = vi.fn();
        render(
            <WorkSchedule
                entries={[timed]}
                anytime={[anytime]}
                now={at('2026-09-13T09:12:00+12:00')}
                startsAt={at('2026-09-13T07:00:00+12:00')}
                endsAt={at('2026-09-13T15:00:00+12:00')}
                onAddAt={add}
                onAddAnytime={() => add()}
            />,
        );
        expect(screen.getByRole('button', { name: 'Agenda' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByText('Up next · 10:00 am')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Add at 9:30 am' }));
        expect(add).toHaveBeenLastCalledWith(at('2026-09-13T09:30:00+12:00'));
        fireEvent.click(
            screen.getByRole('button', { name: /^Day$/ }),
        );
        expect(screen.getByText('Prepare activity bag')).toBeVisible();
        expect(screen.getByText('Check shared supplies')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Add at 10:00 am' }),
        );
        expect(add).toHaveBeenLastCalledWith(timed.at);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Open task' })[0],
        );
        expect(timed.onOpen).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', { name: 'Add anytime task' }),
        );
        expect(add).toHaveBeenLastCalledWith();
    });
});
