import { describe, expect, it } from 'vitest';

import { myDayIncidentHref } from './incident-navigation';

describe('My Day incident entry', () => {
    it.each([
        [{}, null, null],
        [{ shiftId: 44 }, '44', null],
        [{ clientId: 10 }, null, '10'],
        [{ shiftId: 44, clientId: 10 }, '44', '10'],
    ] as const)(
        'keeps canonical context and a finite read return: %j',
        (context, shift, client) => {
            const url = new URL(myDayIncidentHref(context), 'https://example.test');
            expect(url.pathname).toBe('/incidents/create');
            expect(url.searchParams.get('shift_id')).toBe(shift);
            expect(url.searchParams.get('client_id')).toBe(client);
            expect(url.searchParams.getAll('return_to')).toEqual(['/my-day']);
            expect(
                [...url.searchParams.keys()].every((key) =>
                    ['shift_id', 'client_id', 'return_to'].includes(key),
                ),
            ).toBe(true);
            expect(url.hash).toBe('');
        },
    );

    it.each([0, -1, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1])(
        'does not send an invalid context identifier: %s',
        (id) => {
            expect(myDayIncidentHref({ shiftId: id, clientId: id })).toBe(
                '/incidents/create?return_to=%2Fmy-day',
            );
        },
    );
});
