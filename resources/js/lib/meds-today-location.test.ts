import { describe, expect, it } from 'vitest';
import { medsTodayHref, medsTodayQuery } from './meds-today-location';

describe('Meds today entry context', () => {
    it.each(['client_id', 'client', 'pp'])(
        'normalizes %s and keeps house, date and view',
        (key) => {
            expect(
                Object.fromEntries(
                    medsTodayQuery(
                        `${key}=10&site_id=3&date=2026-10-04&view=asneeded`,
                    ),
                ),
            ).toEqual({
                client_id: '10',
                site_id: '3',
                date: '2026-10-04',
                view: 'asneeded',
            });
        },
    );
    it('lets canonical selection win over stale aliases, including an explicit clear', () => {
        expect(
            medsTodayQuery('client_id=10&client=11&pp=12').get('client_id'),
        ).toBe('10');
        expect(
            medsTodayQuery('client_id=&client=11&pp=12').has('client_id'),
        ).toBe(false);
    });
    it('builds the same person destination for My Day and task entries', () => {
        expect(medsTodayHref(10, '2026-10-04')).toBe(
            '/meds/today?client_id=10&date=2026-10-04',
        );
    });
});
