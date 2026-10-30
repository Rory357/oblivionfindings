import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { PollDeadline, PollTimeInput, pollInstant } from './_poll-time';

it('uses the shared calendar and clock and stages the chosen day before applying a poll deadline', async () => {
    const changed = vi.fn();
    render(
        <PollDeadline value="2026-10-21T17:00:00+13:00" onChange={changed} />,
    );
    expect(document.querySelector('input[type="datetime-local"]')).toBeNull();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Closing date and time date: 21 Oct 2026',
        }),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Thu 22 October 2026' }),
    );
    expect(changed).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
    expect(changed).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', {
            name: 'Closing date and time time: 05:00 PM',
        }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Set closing time' }));
    expect(changed).toHaveBeenCalledExactlyOnceWith(
        '2026-10-22T17:00:00+13:00',
    );
});

it('discards an unconfirmed date and keeps the existing closing time', async () => {
    const changed = vi.fn();
    render(
        <PollDeadline value="2026-10-21T17:00:00+13:00" onChange={changed} />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /Closing date and time date:/ }),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Thu 22 October 2026' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(
        screen.getByRole('button', { name: 'Set closing time' }),
    ).toBeDisabled();
    expect(changed).not.toHaveBeenCalled();
});

it('keeps locked dates inert and requires a choice for ambiguous NZ clock readings', () => {
    const changed = vi.fn();
    render(
        <PollTimeInput
            local="2026-04-05T02:30"
            offset=""
            disabled
            onChange={changed}
        />,
    );
    expect(
        screen.getByRole('button', { name: /Closing date and time date:/ }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: /Closing date and time time:/ }),
    ).toBeDisabled();
    expect(
        screen.getByRole('combobox', { name: 'Which clock reading?' }),
    ).toBeDisabled();
    expect(pollInstant('2026-04-05T02:30', '')).toBe('');
    expect(pollInstant('2026-04-05T02:30', '+12:00')).toBe(
        '2026-04-05T02:30:00+12:00',
    );
});
