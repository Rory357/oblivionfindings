import { fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
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
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(onChange).not.toHaveBeenCalled();
});

it('preserves a custom trigger ref and confirms a chosen date without submitting its form', async () => {
    const onChange = vi.fn();
    const onSubmit = vi.fn((event) => event.preventDefault());
    const trigger = createRef<HTMLButtonElement>();
    render(
        <form onSubmit={onSubmit}>
            <DatePicker
                id="custom"
                label="Calendar day"
                value="2026-09-24"
                onChange={onChange}
                trigger={
                    <button type="button" ref={trigger}>
                        Choose calendar day
                    </button>
                }
            />
        </form>,
    );
    const button = screen.getByRole('button', { name: 'Choose calendar day' });
    expect(trigger.current).toBe(button);
    fireEvent.click(button);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Fri 25 September 2026' }),
    );
    expect(onChange).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
    expect(onChange).toHaveBeenCalledExactlyOnceWith('2026-09-25');
    expect(onSubmit).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: 'Use date' }),
    ).not.toBeInTheDocument();
});

it('cannot apply an empty required date or submit while cancelling', async () => {
    const onChange = vi.fn();
    const onSubmit = vi.fn((event) => event.preventDefault());
    render(
        <form onSubmit={onSubmit}>
            <DatePicker
                id="required"
                label="Required date"
                value=""
                onChange={onChange}
            />
        </form>,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Required date: Choose date' }),
    );
    expect(
        await screen.findByRole('button', { name: 'Use date' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Clear date' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onChange).not.toHaveBeenCalled();
    expect(onSubmit).not.toHaveBeenCalled();
});
