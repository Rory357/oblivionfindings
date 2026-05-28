import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { WeekPicker, ymd } from './week-picker';

describe('WeekPicker keyboard selection', () => {
    it('moves the highlighted week with arrow keys and selects it with Enter', () => {
        const anchor = document.createElement('button');
        document.body.appendChild(anchor);
        const onSelect = vi.fn();
        const onClose = vi.fn();

        render(
            <WeekPicker
                selectedWeekStart={new Date('2026-05-25T00:00:00')}
                today={new Date('2026-05-25T00:00:00')}
                anchorRef={{ current: anchor }}
                onSelect={onSelect}
                onClose={onClose}
            />,
        );

        const dialog = screen.getByRole('dialog', { name: /pick a week/i });
        fireEvent.keyDown(dialog, { key: 'ArrowDown' });

        expect(screen.getByText('June 2026')).toBeVisible();

        fireEvent.keyDown(dialog, { key: 'Enter' });

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(ymd(onSelect.mock.calls[0][0])).toBe('2026-06-01');
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
