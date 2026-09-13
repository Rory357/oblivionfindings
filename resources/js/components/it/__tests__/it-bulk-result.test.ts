import { describe, expect, it } from 'vitest';
import { readItBulkResult } from '../it-bulk-result';

const outcome = () => ({
    resource: 'tickets',
    action: 'close',
    selected: 2,
    updated: 1,
    unchanged: 0,
    rejected: 1,
    items: [
        { id: 4, status: 'updated', message: 'Change saved.' },
        { id: 7, status: 'stale', message: 'Review before retrying.' },
    ],
});
const command = { action: 'close', ids: [4, 7] };

describe('bulk command acknowledgements', () => {
    it('accepts exact per-record results independently of return order', () => {
        const result = outcome();
        result.items.reverse();
        expect(readItBulkResult(result, 'tickets', command)).toEqual(result);
    });

    it.each([
        ['another action', { action: 'priority' }],
        [
            'duplicate IDs',
            {
                items: [
                    { id: 4, status: 'updated', message: 'Saved' },
                    { id: 4, status: 'stale', message: 'Stale' },
                ],
            },
        ],
        [
            'unselected ID',
            {
                items: [
                    { id: 4, status: 'updated', message: 'Saved' },
                    { id: 8, status: 'stale', message: 'Stale' },
                ],
            },
        ],
        ['fabricated success totals', { updated: 2, rejected: 0 }],
        ['fabricated unchanged totals', { updated: 0, unchanged: 1 }],
        [
            'missing item',
            { items: [{ id: 4, status: 'updated', message: 'Saved' }] },
        ],
        [
            'null item',
            { items: [null, { id: 7, status: 'stale', message: 'Stale' }] },
        ],
        [
            'unsafe ID',
            {
                items: [
                    {
                        id: Number.MAX_SAFE_INTEGER + 1,
                        status: 'updated',
                        message: 'Saved',
                    },
                    { id: 7, status: 'stale', message: 'Stale' },
                ],
            },
        ],
    ])(
        'refuses %s without inferring any successful change',
        (_label, patch) => {
            expect(
                readItBulkResult(
                    { ...outcome(), ...patch },
                    'tickets',
                    command,
                ),
            ).toBeNull();
        },
    );

    it('refuses a changed or malformed original selection', () => {
        expect(
            readItBulkResult(outcome(), 'tickets', {
                action: 'close',
                ids: [4],
            }),
        ).toBeNull();
        expect(
            readItBulkResult(outcome(), 'tickets', {
                action: 'close',
                ids: [4, 4],
            }),
        ).toBeNull();
        expect(readItBulkResult(outcome(), 'provisioning', command)).toBeNull();
    });
});
