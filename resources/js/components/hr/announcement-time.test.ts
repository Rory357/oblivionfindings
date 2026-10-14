import { describe, expect, it } from 'vitest';
import {
    announcementDayEnd,
    announcementDayInput,
    announcementPublishInstant,
    announcementTimeInput,
} from './announcement-time';

describe('announcement New Zealand date inputs', () => {
    it('keeps summer and winter wall times independent of the browser timezone', () => {
        expect(announcementPublishInstant('2027-01-20T10:00')).toBe(
            '2027-01-19T21:00:00.000Z',
        );
        expect(announcementPublishInstant('2027-07-20T10:00')).toBe(
            '2027-07-19T22:00:00.000Z',
        );
        expect(announcementTimeInput('2027-01-19T21:00:00Z')).toBe(
            '2027-01-20T10:00',
        );
    });
    it('uses the selected NZ day end and retains unchanged legacy deadlines', () => {
        expect(announcementDayEnd('2027-01-20')).toBe(
            '2027-01-20T10:59:59.000Z',
        );
        expect(announcementDayEnd('2027-07-20')).toBe(
            '2027-07-20T11:59:59.000Z',
        );
        expect(announcementDayInput('2027-01-20T10:59:59Z')).toBe('2027-01-20');
        expect(announcementDayEnd('2027-01-20', '2027-01-20T00:00:00Z')).toBe(
            '2027-01-20T00:00:00Z',
        );
        expect(announcementDayEnd('')).toBeNull();
    });
    it('rejects new skipped or ambiguous times while preserving an existing repeated-hour instant', () => {
        expect(() => announcementPublishInstant('2027-09-26T02:30')).toThrow(
            /does not exist/,
        );
        expect(() => announcementPublishInstant('2027-04-04T02:30')).toThrow(
            /occurs twice/,
        );
        expect(
            announcementPublishInstant(
                '2027-04-04T02:30',
                '2027-04-03T14:30:45Z',
            ),
        ).toBe('2027-04-03T14:30:45Z');
    });
});
