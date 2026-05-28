import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ShiftsHero } from './shifts-hero';

const stats = {
    total: 5,
    open: 1,
    today: 2,
    in_progress: 0,
    hours: 24,
    sites: 2,
    staff: 4,
    unassigned: 1,
};

describe('ShiftsHero week controls', () => {
    it('shows the combined week number and date range in the picker button', () => {
        render(
            <ShiftsHero
                weekLabel="25 May → 31 May"
                weekStart={new Date('2026-05-25T00:00:00')}
                stats={stats}
                filters={{
                    statuses: [],
                    site_ids: [],
                    user_ids: [],
                    client_ids: [],
                    q: '',
                }}
                onChangeFilter={vi.fn()}
                statusOptions={[]}
                siteItems={[]}
                staffItems={[]}
                clientItems={[]}
                onPickWeek={vi.fn()}
                onPrevWeek={vi.fn()}
                onNextWeek={vi.fn()}
                canCreate={false}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: /Wk 22 · 25 May → 31 May · pick week/i,
            }),
        ).toBeVisible();
    });
});
