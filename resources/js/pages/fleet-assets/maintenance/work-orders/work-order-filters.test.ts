import { describe, expect, it } from 'vitest';

import { readFileSync } from 'node:fs';
import {
    mergeWorkOrderFilters,
    workOrderStatusFilterUpdate,
    workOrderStatusFilterValue,
} from './work-order-filters';

describe('work order filters', () => {
    it('links the maintenance overdue tile to the canonical overdue filter', () => {
        const dashboard = readFileSync(
            'resources/js/pages/fleet-assets/maintenance/dashboard.tsx',
            'utf8',
        );

        expect(dashboard).toMatch(
            /href="\/fleet-assets\/maintenance\/work-orders\?overdue=1"\s+label="Overdue"/,
        );
    });

    it('clears the hidden overdue filter when status changes', () => {
        expect(
            mergeWorkOrderFilters(
                { overdue: '1', priority: 'high' },
                { status: 'completed' },
            ),
        ).toEqual({ priority: 'high', status: 'completed' });
    });

    it('preserves the overdue filter when priority changes', () => {
        expect(
            mergeWorkOrderFilters(
                { overdue: '1', status: 'open' },
                { priority: 'critical' },
            ),
        ).toEqual({
            overdue: '1',
            status: 'open',
            priority: 'critical',
        });
    });

    it('surfaces the overdue-only state in the status control', () => {
        expect(workOrderStatusFilterValue({ overdue: '1' })).toBe('overdue');
    });

    it('does not surface non-canonical overdue query values', () => {
        expect(
            workOrderStatusFilterValue({ overdue: '0', status: 'open' }),
        ).toBe('open');
        expect(workOrderStatusFilterValue({ overdue: 'false' })).toBe('all');
        expect(workOrderStatusFilterValue({ overdue: 'true' })).toBe('all');
    });

    it('maps the overdue status option to the explicit overdue filter', () => {
        expect(workOrderStatusFilterUpdate('overdue')).toEqual({
            status: '',
            overdue: '1',
        });
    });

    it('maps all statuses to a status change that clears overdue', () => {
        expect(workOrderStatusFilterUpdate('all')).toEqual({ status: '' });
    });
});
