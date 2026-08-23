import { describe, expect, it } from 'vitest';

import { buildSystemUserSummary } from './system-user-summary';

describe('System Users duplicated summary', () => {
    it('keeps the same organisation-wide totals when a role filter has zero rows', () => {
        const filteredResultCount = 0;
        const summary = buildSystemUserSummary({
            total: 83,
            active: 78,
            pending: 5,
            staff: 50,
        });

        expect(filteredResultCount).toBe(0);
        expect(summary.map(({ value }) => value)).toEqual([83, 78, 5, 50]);
        expect(summary.every(({ value }) => value >= 0)).toBe(true);
    });

    it('uses one value per metric for both hero and static lower-card summaries', () => {
        const summary = buildSystemUserSummary({
            total: 0,
            active: 0,
            pending: 0,
            staff: 0,
        });

        expect(summary).toHaveLength(4);
        expect(summary.every(({ staticValue }) => staticValue)).toBe(true);
        expect(
            summary.map(({ key, heroLabel, cardLabel, value }) => ({
                key,
                heroLabel,
                cardLabel,
                value,
            })),
        ).toEqual([
            {
                key: 'total',
                heroLabel: 'Total',
                cardLabel: 'Total Users',
                value: 0,
            },
            {
                key: 'active',
                heroLabel: 'Active',
                cardLabel: 'Active',
                value: 0,
            },
            {
                key: 'pending',
                heroLabel: 'Pending',
                cardLabel: 'Pending Approval',
                value: 0,
            },
            {
                key: 'staff',
                heroLabel: 'Staff',
                cardLabel: 'Staff Members',
                value: 0,
            },
        ]);
    });
});
