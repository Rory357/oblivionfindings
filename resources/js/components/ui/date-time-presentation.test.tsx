import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from './dialog';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from './sheet';

describe('date/time presentation in shared form shells', () => {
    it('inherits compact fields through a dialog portal and preserves exact-minute drafts without submitting', async () => {
        const submit = vi.fn();
        function Form() {
            const [value, setValue] = useState('2026-10-05T09:04');
            return (
                <Dialog open>
                    <DialogContent>
                        <DialogTitle>Record inspection</DialogTitle>
                        <DialogDescription>
                            Choose when the inspection happened.
                        </DialogDescription>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                submit();
                            }}
                        >
                            <DateTimeField
                                id="inspected"
                                label="Inspected at"
                                value={value}
                                onChange={setValue}
                            />
                            <output aria-label="Applied timestamp">
                                {value}
                            </output>
                        </form>
                    </DialogContent>
                </Dialog>
            );
        }
        render(<Form />);
        expect(
            screen.getByRole('group', {
                name: 'Inspected at Pacific/Auckland',
            }),
        ).toHaveAttribute('data-compact', 'true');
        expect(
            screen.getByRole('button', {
                name: 'Inspected at date: 5 Oct 2026',
            }),
        ).toHaveAttribute('data-compact', 'true');
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspected at time: 09:04 AM' }),
        );
        const clock = await screen.findByRole('dialog', {
            name: 'Inspected at time picker',
        });
        expect(clock).toHaveAttribute('data-compact', 'true');
        fireEvent.click(
            within(clock).getByRole('button', { name: 'Type time' }),
        );
        fireEvent.change(screen.getByLabelText('Inspected at time minute'), {
            target: { value: '17' },
        });
        fireEvent.click(within(clock).getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-05T09:04',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspected at time: 09:04 AM' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Type time' }),
        );
        fireEvent.change(screen.getByLabelText('Inspected at time minute'), {
            target: { value: '17' },
        });
        fireEvent.keyDown(screen.getByLabelText('Inspected at time minute'), {
            key: 'Enter',
        });
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-05T09:17',
        );
        expect(submit).not.toHaveBeenCalled();
    });

    it('inherits for standalone date and time fields in sheets without changing values', () => {
        const change = vi.fn();
        render(
            <Sheet open>
                <SheetContent>
                    <SheetTitle>Appointment</SheetTitle>
                    <SheetDescription>Choose a day and time.</SheetDescription>
                    <DatePicker
                        id="day"
                        label="Appointment day"
                        value="2026-10-05"
                        onChange={change}
                    />
                    <TimePicker
                        id="time"
                        label="Appointment time"
                        value="09:04"
                        onChange={change}
                    />
                </SheetContent>
            </Sheet>,
        );
        expect(
            screen.getByRole('button', { name: 'Appointment day: 5 Oct 2026' }),
        ).toHaveAttribute('data-compact', 'true');
        expect(
            screen.getByRole('button', { name: 'Appointment time: 09:04 AM' }),
        ).toHaveAttribute('data-compact', 'true');
        expect(change).not.toHaveBeenCalled();
    });

    it('keeps standalone page fields unchanged and allows explicit compact presentation', () => {
        const { rerender } = render(
            <DatePicker
                id="filter"
                label="Report day"
                value=""
                onChange={vi.fn()}
            />,
        );
        expect(
            screen.getByText('Choose a day on the calendar'),
        ).toBeInTheDocument();
        rerender(
            <DatePicker
                compact
                id="filter"
                label="Report day"
                value=""
                onChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByText('Choose a day on the calendar'),
        ).not.toBeInTheDocument();
    });
});
