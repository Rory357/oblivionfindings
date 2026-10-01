import { fireEvent, render, screen } from '@testing-library/react';
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
