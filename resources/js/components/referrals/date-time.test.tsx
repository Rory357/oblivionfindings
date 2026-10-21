import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import { ReferralDateTime, validReferralDateTime } from './date-time';

vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));

it('requires a real Auckland time and an explicit occurrence during the repeated hour', () => {
    expect(validReferralDateTime('2026-09-27T02:30')).toBe(false);
    expect(validReferralDateTime('2026-04-05T02:30')).toBe(false);
    expect(validReferralDateTime('2026-04-05T02:30:42+13:00')).toBe(true);
    expect(validReferralDateTime('2026-04-05T02:30:42+12:00')).toBe(true);
    expect(validReferralDateTime('2026-10-18T13:00+12:00')).toBe(false);
    expect(validReferralDateTime('2026-10-18T13:00:60+13:00')).toBe(false);
    expect(validReferralDateTime('2026-10-18T')).toBe(false);
});

it('preserves actual-start seconds when changing dates and updates the seasonal offset', () => {
    function Form() {
        const [value, setValue] = useState('2026-10-18T13:00:42+13:00');
        return (
            <>
                <ReferralDateTime
                    id="actual"
                    label="Actual start"
                    value={value}
                    onChange={setValue}
                    includeSeconds
                />
                <output>{value}</output>
            </>
        );
    }
    render(<Form />);
    fireEvent.change(screen.getByLabelText('Actual start'), {
        target: { value: '2026-06-18T13:00' },
    });
    expect(screen.getByRole('status')).toHaveTextContent(
        '2026-06-18T13:00:42+12:00',
    );
    fireEvent.change(screen.getByLabelText('Actual start seconds'), {
        target: { value: '9' },
    });
    expect(screen.getByRole('status')).toHaveTextContent(
        '2026-06-18T13:00:09+12:00',
    );
});
