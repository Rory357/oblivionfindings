import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    DOSE_STATUS_META,
    hiddenControlledCaption,
} from '@/components/meds/board-bits';

import type { StreamItem } from '../lib/stream-grouping';
import type { MyDayMedDue } from '../lib/types';
import { workIsDone } from '../lib/work-priority';
import { StreamItemRow } from './stream-item';

function med(status: MyDayMedDue['status']): StreamItem {
    return {
        kind: 'med',
        at: '07:00',
        hr: 7,
        clientId: 1,
        data: {
            id: '1:2026-06-15T07:00:00+12:00',
            medication_id: 1,
            client_id: 1,
            client_name: 'Aroha Ngata',
            medication_name: 'Metformin',
            dose: '1 tablet',
            route: 'Oral',
            is_controlled: false,
            can_record: true,
            can_give: true,
            scheduled_for: '2026-06-15T07:00:00+12:00',
            status,
            emar_url: null,
        },
    };
}

describe('My Day medication states (C6)', () => {
    it('shows a dose recorded as missed as "Missed (recorded)", done and never overdue', () => {
        render(
            <StreamItemRow
                item={med('missed')}
                isNow={false}
                showResident={false}
                onToggleTask={vi.fn()}
                onGiveMed={vi.fn()}
                onOpenContextMenu={vi.fn()}
            />,
        );

        expect(screen.getAllByText('Missed (recorded)').length).toBeGreaterThan(
            0,
        );
        expect(screen.queryByText('Overdue')).toBeNull();
        expect(screen.queryByTitle('Mark as given')).toBeNull();
        expect(workIsDone(med('missed'))).toBe(true);
        // The label Meds today uses for the same state.
        expect(DOSE_STATUS_META.missed.label).toBe('Missed (recorded)');
    });

    it('says how many controlled doses a list leaves out, naming none', () => {
        expect(hiddenControlledCaption(0)).toBeNull();
        expect(hiddenControlledCaption(1)).toBe(
            '1 more controlled-medicine dose isn’t shown — needs controlled-medicine access.',
        );
        expect(hiddenControlledCaption(3)).toBe(
            '3 more controlled-medicine doses aren’t shown — needs controlled-medicine access.',
        );
        // The overdue ones among them: what reconciles the list with the badge.
        expect(hiddenControlledCaption(2, 1)).toBe(
            '2 more controlled-medicine doses aren’t shown (1 overdue) — needs controlled-medicine access.',
        );
    });
});
