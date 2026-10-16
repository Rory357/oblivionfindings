import { describe, expect, it } from 'vitest';
import {
    recognitionReceipt,
    type RecognitionPayload,
} from './recognition-receipt';
const payload: RecognitionPayload = {
    to_user_ids: ['11', '12'],
    to_department_id: null,
    category: '',
    impact: 'thank_you',
    message: 'Thanks for your help.',
};
const record = (id: number, user: number) => ({
    kudos_id: id,
    feed_post_id: id + 100,
    recipient_type: 'person',
    to_user_id: user,
    to_department_id: null,
    reader_url: `/company-hub?view=shoutouts&recognition=${id}#kudos-${id}`,
});
describe('recognition receipt identities', () => {
    it('accepts actual fanout records and one true team record', () => {
        const people = {
            saved: true,
            actor_id: 7,
            records: [record(1, 11), record(2, 12)],
        };
        expect(recognitionReceipt(people, 7, payload)).toEqual(people);
        const team = {
            saved: true,
            actor_id: 7,
            records: [
                {
                    ...record(3, 11),
                    recipient_type: 'team',
                    to_user_id: null,
                    to_department_id: 4,
                },
            ],
        };
        expect(
            recognitionReceipt(team, 7, {
                ...payload,
                to_user_ids: [],
                to_department_id: 4,
            }),
        ).toEqual(team);
    });
    it.each([
        [record(1, 11)],
        [record(1, 11), record(1, 12)],
        [record(1, 11), record(2, 11)],
        [record(1, 11), record(2, 99)],
        [
            record(1, 11),
            { ...record(2, 12), reader_url: 'https://example.org' },
        ],
        [
            record(1, 11),
            {
                ...record(2, 12),
                reader_url: '/company-hub?view=shoutouts&recognition=3#kudos-3',
            },
        ],
    ])(
        'rejects incomplete, duplicate, mismatched or unsafe receipts',
        (...records) => {
            expect(() =>
                recognitionReceipt(
                    { saved: true, actor_id: 7, records },
                    7,
                    payload,
                ),
            ).toThrow();
        },
    );
});
