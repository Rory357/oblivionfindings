import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DateTimeField } from './date-time-field';

const clearName = 'Given: Clear date and time';

describe('DateTimeField clear control', () => {
    it('offers Clear date and time once there is a value, by default', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <DateTimeField
                id="given"
                label="Given"
                value=""
                onChange={onChange}
            />,
        );
        expect(
            screen.queryByRole('button', { name: clearName }),
        ).not.toBeInTheDocument();

        rerender(
            <DateTimeField
                id="given"
                label="Given"
                value="2026-10-01T09:30"
                onChange={onChange}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: clearName }));
        expect(onChange).toHaveBeenCalledExactlyOnceWith('');
    });

    it('hides the clear control when clearable is false, keeping the pickers', () => {
        render(
            <DateTimeField
                id="given"
                label="Given"
                value="2026-10-01T09:30"
                onChange={vi.fn()}
                clearable={false}
            />,
        );
        expect(
            screen.queryByRole('button', { name: clearName }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Clear date and time')).toBeNull();
        expect(
            screen.getByRole('button', { name: /^Given date: / }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^Given time: / }),
        ).toBeInTheDocument();
    });
});

describe('compact medication date and time fields', () => {
    function Form({ onSubmit = vi.fn() }: { onSubmit?: () => void }) {
        const [value, setValue] = useState('2026-10-05T02:04');
        return (
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit();
                }}
            >
                <DateTimeField
                    compact
                    id="happened"
                    label="When it happened"
                    value={value}
                    onChange={setValue}
                />
                <output aria-label="Applied timestamp">{value}</output>
            </form>
        );
    }

    it('keeps the paired time when a calendar day is applied', async () => {
        render(<Form />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it happened date: 5 Oct 2026',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Tue 6 October 2026' }),
        );
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-05T02:04',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-06T02:04',
        );
    });

    it('applies exact minutes with Enter without submitting the parent form', async () => {
        const onSubmit = vi.fn();
        render(<Form onSubmit={onSubmit} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it happened time: 02:04 AM',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Type time' }),
        );
        fireEvent.change(screen.getByLabelText('When it happened time hour'), {
            target: { value: '1' },
        });
        fireEvent.change(
            screen.getByLabelText('When it happened time minute'),
            { target: { value: '17' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'PM' }));
        fireEvent.keyDown(
            screen.getByLabelText('When it happened time minute'),
            { key: 'Enter' },
        );
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-05T13:17',
        );
        expect(
            screen.queryByRole('button', { name: 'Use time' }),
        ).not.toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('cancels pending edits and clears only when asked, without submitting', async () => {
        const onSubmit = vi.fn();
        render(<Form onSubmit={onSubmit} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it happened time: 02:04 AM',
            }),
        );
        fireEvent.change(
            await screen.findByLabelText('When it happened time minute'),
            { target: { value: '31' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText('Applied timestamp')).toHaveTextContent(
            '2026-10-05T02:04',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it happened: Clear date and time',
            }),
        );
        expect(
            screen.getByLabelText('Applied timestamp'),
        ).toBeEmptyDOMElement();
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
