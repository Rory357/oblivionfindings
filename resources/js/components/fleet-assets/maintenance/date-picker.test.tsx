import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { DatePicker } from './date-picker';

it('clears an optional date and closes the calendar without submitting a form', async () => {
    const onChange = vi.fn();
    const onSubmit = vi.fn((event) => event.preventDefault());
    render(
        <form onSubmit={onSubmit}>
            <DatePicker
                id="purchase"
                label="Purchase date"
                value="2026-09-24"
                onChange={onChange}
                allowClear
            />
        </form>,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Purchase date: 24 Sep 2026' }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Clear date' }));
    expect(onChange).toHaveBeenCalledExactlyOnceWith('');
    expect(onSubmit).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: 'Use date' }),
    ).not.toBeInTheDocument();
});

it('keeps a required date and discards an unconfirmed choice', async () => {
    const onChange = vi.fn();
    render(
        <DatePicker
            id="return"
            label="Return due"
            value="2026-09-24"
            onChange={onChange}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Return due: 24 Sep 2026' }),
    );
    expect(
        screen.queryByRole('button', { name: 'Clear date' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        await screen.findByRole('button', { name: 'Cancel' }),
    );
    expect(onChange).not.toHaveBeenCalled();
});
