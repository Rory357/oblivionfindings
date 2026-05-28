import { fireEvent, render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ShiftListView } from './shift-list-view';
import type { ShiftRow } from './shift-row-types';

function shift(overrides: Partial<ShiftRow> = {}): ShiftRow {
    return {
        id: 9376,
        starts_at: '2026-05-28T09:00:00+12:00',
        ends_at: '2026-05-28T13:00:00+12:00',
        status: 'scheduled',
        location: 'Matai House',
        shift_type: 'standard',
        is_sleepover: false,
        is_on_call: false,
        client: {
            id: 7,
            first_name: 'Ari',
            last_name: 'Kauri',
        },
        staff: null,
        site: { id: 2, name: 'Matai House', type: 'house' },
        tasks: [],
        ...overrides,
    };
}

describe('ShiftListView audit fixes', () => {
    it('routes the unassigned-row Find cover action to the cover workflow, not edit', () => {
        const onFindCover = vi.fn();
        const onAssignOpen = vi.fn();

        render(
            React.createElement(ShiftListView as React.ComponentType<any>, {
                shifts: [shift()],
                todayKey: '2026-05-28',
                onShiftClick: vi.fn(),
                onAssignOpen,
                onFindCover,
                onContextMenu: vi.fn(),
                onEditClick: vi.fn(),
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /Find cover/i }));

        expect(onFindCover).toHaveBeenCalledWith(
            expect.objectContaining({ id: 9376 }),
        );
        expect(onAssignOpen).not.toHaveBeenCalled();
    });

    it('disables the row action when cover has already been requested', () => {
        render(
            React.createElement(ShiftListView as React.ComponentType<any>, {
                shifts: [shift({ cover_requested: true } as Partial<ShiftRow>)],
                todayKey: '2026-05-28',
                onShiftClick: vi.fn(),
                onAssignOpen: vi.fn(),
                onFindCover: vi.fn(),
                onContextMenu: vi.fn(),
                onEditClick: vi.fn(),
            }),
        );

        expect(
            screen.getByRole('button', { name: /Cover requested/i }),
        ).toBeDisabled();
    });
});
