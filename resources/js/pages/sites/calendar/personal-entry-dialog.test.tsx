import { myCalendarItem } from '@/lib/my-calendar-adapter';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CalendarTimeSlot,
    MonthView,
    CalendarUIProvider,
    decorate,
    occursOnDay,
    packDay,
} from './_parts';
import PersonalEntryDialog from './personal-entry-dialog';

const api = vi.hoisted(() => ({
    request: vi.fn(),
    toast: { success: vi.fn(), error: vi.fn() },
}));
vi.mock('@/lib/personal-calendar', async (original) => ({
    ...(await original<object>()),
    personalCalendarRequest: api.request,
}));
vi.mock('sonner', () => ({ toast: api.toast }));

beforeEach(() => vi.clearAllMocks());

describe('personal calendar interactions', () => {
    it('does not create a blank entry when an event button receives Enter', () => {
        const create = vi.fn();
        const event = decorate(myCalendarItem({ id: 'shift-1', title: 'Existing shift', start: new Date(2027, 0, 15, 9).toISOString(), extendedProps: { type: 'shift' } }, { id: 1, name: 'Worker' }));
        render(<CalendarUIProvider value={{ colorBy: 'source', density: 'comfortable', srcByKey: {}, onSelect: vi.fn(), onCreateAt: create }}><MonthView events={[event]} navDate={new Date(2027, 0, 15)} /></CalendarUIProvider>);
        fireEvent.keyDown(screen.getByRole('button', { name: /Existing shift/ }), { key: 'Enter' });
        expect(create).not.toHaveBeenCalled();
    });
    it('seeds the selected date, quarter hour and entry kind and submits offset-aware times', async () => {
        api.request.mockResolvedValue({ id: 1 });
        const saved = vi.fn();
        render(
            <PersonalEntryDialog
                seed={{
                    date: new Date(2027, 0, 15),
                    hour: 10.25,
                    eventType: 'task',
                }}
                onClose={vi.fn()}
                onSaved={saved}
            />,
        );
        expect(screen.getByLabelText('Entry type')).toHaveValue('task');
        expect(screen.getByLabelText('Starts')).toHaveValue('2027-01-15T10:15');
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Prepare notes' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Create entry' }));
        await waitFor(() => expect(saved).toHaveBeenCalledOnce());
        expect(api.request).toHaveBeenCalledWith(
            '',
            'POST',
            expect.objectContaining({
                kind: 'task',
                title: 'Prepare notes',
                start_at: new Date(2027, 0, 15, 10, 15).toISOString(),
            }),
        );
    });
    it('keeps a failed save draft and asks before discarding it', async () => {
        api.request.mockRejectedValue(
            new Error('This entry changed elsewhere.'),
        );
        const close = vi.fn();
        render(<PersonalEntryDialog onClose={close} onSaved={vi.fn()} />);
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Keep my draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Create entry' }));
        await screen.findByText('This entry changed elsewhere.');
        expect(screen.getByLabelText('Title')).toHaveValue('Keep my draft');
        fireEvent.click(
            screen.getAllByRole('button', { name: /^Close$/ })[0],
        );
        expect(close).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Keep editing' }),
        ).toBeVisible();
    });
    it('lets keyboard users move between hourly slots and create on the focused day', () => {
        const create = vi.fn();
        const day = new Date(2027, 0, 15);
        render(
            <CalendarUIProvider
                value={{
                    colorBy: 'source',
                    density: 'comfortable',
                    srcByKey: {},
                    onSelect: vi.fn(),
                    onCreateAt: create,
                }}
            >
                <div>
                    <CalendarTimeSlot day={day} hour={9} />
                    <CalendarTimeSlot day={day} hour={10} />
                </div>
            </CalendarUIProvider>,
        );
        const buttons = screen.getAllByRole('button');
        buttons[0].focus();
        fireEvent.keyDown(buttons[0], { key: 'ArrowDown' });
        expect(buttons[1]).toHaveFocus();
        fireEvent.click(buttons[1]);
        expect(create).toHaveBeenCalledWith(day, 10);
    });
    it('shows overnight entries on both days and clips them at midnight', () => {
        const event = decorate(
            myCalendarItem(
                {
                    id: 'shift-1',
                    title: 'Overnight',
                    start: new Date(2027, 0, 15, 22).toISOString(),
                    end: new Date(2027, 0, 16, 7).toISOString(),
                    extendedProps: { type: 'shift' },
                },
                { id: 1, name: 'Worker' },
            ),
        );
        expect(occursOnDay(event, new Date(2027, 0, 16))).toBe(true);
        expect(packDay([event], new Date(2027, 0, 15))[0]).toMatchObject({
            _s: 1320,
            _e: 1440,
        });
        expect(packDay([event], new Date(2027, 0, 16))[0]).toMatchObject({
            _s: 0,
            _e: 420,
        });
        expect(occursOnDay(event, new Date(2027, 0, 17))).toBe(false);
    });
});
