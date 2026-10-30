import { CalendarContextMenu } from '@/pages/sites/calendar/_parts';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { Ban, FileText } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';
import { recordMenuItems } from './calendar';

const reason = 'A started journey can’t be cancelled';

describe('Transport calendar record actions', () => {
    it('lists a disabled record action with its reason, focusable but inert', () => {
        const opened = vi.fn();
        const cancelled = vi.fn();
        const onClose = vi.fn();
        const items = recordMenuItems([
            { label: 'Open record', icon: FileText, onClick: opened },
            { separator: true },
            {
                label: 'Cancel transport',
                icon: Ban,
                danger: true,
                onClick: cancelled,
                disabled: reason,
            },
            { label: 'Nothing to do' },
        ]);
        expect(items.map((item) => item.label)).toEqual([
            'Open record',
            'Cancel transport',
        ]);

        render(
            <CalendarContextMenu
                x={10}
                y={10}
                chip="Actions"
                heading="Clinic trip"
                ariaLabel="Transport calendar entry actions"
                sections={[{ key: 'actions', items }]}
                onClose={onClose}
            />,
        );
        const menu = screen.getByRole('menu', {
            name: 'Transport calendar entry actions',
        });
        const blocked = within(menu).getByRole('menuitem', {
            name: 'Cancel transport',
        });
        expect(blocked).toHaveAttribute('aria-disabled', 'true');
        expect(blocked).not.toHaveAttribute('disabled');
        expect(blocked).toHaveAccessibleDescription(reason);
        expect(within(blocked).getByText(reason)).toBeInTheDocument();
        expect(blocked).not.toHaveClass('text-destructive');

        // Reachable with the arrow keys, so the reason is announced.
        const open = within(menu).getByRole('menuitem', {
            name: 'Open record',
        });
        expect(open).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(blocked).toHaveFocus();

        // Choosing it does nothing and leaves the menu open.
        fireEvent.click(blocked);
        expect(cancelled).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();

        // Enabled actions behave as before.
        fireEvent.click(open);
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(opened).toHaveBeenCalledTimes(1);
    });
});
