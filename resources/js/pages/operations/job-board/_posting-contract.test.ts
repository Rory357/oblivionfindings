import { describe, expect, it } from 'vitest';
import {
    confirmedPosting,
    postingReadback,
    postingText,
    preparePostingIntent,
    readPostablePage,
    type PostableShift,
} from './_posting-contract';

const request = '2405f050-cabb-41bb-b4ed-a3dbef71c353';
const row: PostableShift = {
    id: 32,
    label: 'Support shift',
    location: 'Matai House',
    starts_at: '2026-10-20T20:00:00Z',
    ends_at: '2026-10-21T04:00:00Z',
    status: 'scheduled',
    assigned: false,
    source_revision: 'a'.repeat(64),
    post_url: '/operations/shifts/32/open-position',
    active_position: null,
    can_post: true,
    reason: null,
};
const intent = () =>
    preparePostingIntent(
        row,
        18,
        request,
        { skills: ' Hoist\nHoist ', notes: ' Support cover ', expiry: '' },
        'Pacific/Auckland',
    );
const receipt = () => ({
    result: {
        version: 1,
        action: 'post_position',
        outcome: 'posted',
        changed: true,
        request_id: request,
        actor_id: 18,
        source_revision: 'a'.repeat(64),
        committed_at: '2026-10-10T10:00:00Z',
        shift: {
            id: 32,
            site_id: 9,
            assigned: false,
            status: 'scheduled',
            starts_at: '2026-10-20T20:00:00Z',
            ends_at: '2026-10-21T04:00:00Z',
        },
        position: {
            id: 70,
            shift_id: 32,
            replacement_request_id: null,
            status: 'open',
            required_skills: ['Hoist', 'Hoist'],
            coverage_roles: ['driver'],
            notes: 'Support cover',
            claimed_by: null,
            claimed_at: null,
            approved_by: null,
            approved_at: null,
            expires_at: null,
        },
    },
});

describe('Job Board posting intent and uncertain result boundary', () => {
    it('confirms a correlated201 against reviewed normalized details without inventing picker coverage fields', () => {
        expect(confirmedPosting(201, receipt(), intent(), 18)).toEqual({
            positionId: 70,
            committedAt: '2026-10-10T10:00:00Z',
        });
        expect(intent().body.required_skills).toEqual(['Hoist', 'Hoist']);
        expect(intent().body.notes).toBe('Support cover');
    });
    it('accepts a canonical assigned replacement while refusing an ordinary assigned receipt without replacement', () => {
        const replacement = preparePostingIntent(
            { ...row, assigned: true },
            18,
            request,
            { skills: 'Hoist\nHoist', notes: 'Support cover', expiry: '' },
            'Pacific/Auckland',
        );
        const value = receipt();
        value.result.shift.assigned = true;
        expect(confirmedPosting(201, value, replacement, 18)).toBeNull();
        expect(
            confirmedPosting(
                201,
                {
                    result: {
                        ...value.result,
                        position: {
                            ...value.result.position,
                            replacement_request_id: 80,
                        },
                    },
                },
                replacement,
                18,
            )?.positionId,
        ).toBe(70);
    });
    it.each([200, 202, 403, 409, 422, 500])(
        'does not treat HTTP%s as saved even with a positive-looking body',
        (status) => {
            expect(
                confirmedPosting(status, receipt(), intent(), 18),
            ).toBeNull();
        },
    );
    it.each([null, {}, { result: null }, { result: { outcome: 'posted' } }])(
        'holds missing or partial confirmation (%j)',
        (value) => {
            expect(confirmedPosting(201, value, intent(), 18)).toBeNull();
        },
    );
    it('withholds a receipt after the current account changes', () => {
        expect(confirmedPosting(201, receipt(), intent(), 19)).toBeNull();
    });
    it.each([
        'actor_id',
        'request_id',
        'source_revision',
        'outcome',
        'changed',
        'version',
    ])('rejects altered receipt %s', (key) => {
        const value = receipt();
        expect(
            confirmedPosting(
                201,
                { result: { ...value.result, [key]: 'different' } },
                intent(),
                18,
            ),
        ).toBeNull();
    });
    it.each(['id', 'status', 'assigned', 'starts_at', 'ends_at'])(
        'rejects a changed governing Shift %s',
        (key) => {
            const value = receipt();
            expect(
                confirmedPosting(
                    201,
                    {
                        result: {
                            ...value.result,
                            shift: {
                                ...value.result.shift,
                                [key]: 'different',
                            },
                        },
                    },
                    intent(),
                    18,
                ),
            ).toBeNull();
        },
    );
    it('rejects hidden Client or worker identity fields in the minimal Shift receipt', () => {
        const value = receipt();
        expect(
            confirmedPosting(
                201,
                {
                    result: {
                        ...value.result,
                        shift: { ...value.result.shift, client_id: 8 },
                    },
                },
                intent(),
                18,
            ),
        ).toBeNull();
    });
    it('rejects receipt details that silently remove a repeated requested skill or change notes', () => {
        const value = receipt();
        expect(
            confirmedPosting(
                201,
                {
                    result: {
                        ...value.result,
                        position: {
                            ...value.result.position,
                            required_skills: ['Hoist'],
                        },
                    },
                },
                intent(),
                18,
            ),
        ).toBeNull();
        expect(
            confirmedPosting(
                201,
                {
                    result: {
                        ...value.result,
                        position: {
                            ...value.result.position,
                            notes: 'Different support',
                        },
                    },
                },
                intent(),
                18,
            ),
        ).toBeNull();
    });
    it('normalizes invisible edges before Review and keeps internal text and duplicate order', () => {
        expect(postingText('\u200b \u00adcare\u200b support \u2060')).toBe(
            'care\u200b support',
        );
        const draft = preparePostingIntent(
            row,
            18,
            request,
            {
                skills: '\u200bHoist\nHoist\u2060',
                notes: '\u200b \u2060',
                expiry: '',
            },
            'Pacific/Auckland',
        );
        expect(draft.body).toMatchObject({
            required_skills: ['Hoist', 'Hoist'],
            notes: null,
        });
    });
    it('refuses a blank entry among supplied skills instead of silently changing requirements', () => {
        expect(() =>
            preparePostingIntent(
                row,
                18,
                request,
                { skills: 'Hoist\n\u200b\nDriver', notes: '', expiry: '' },
                'Pacific/Auckland',
            ),
        ).toThrow(/no empty lines/);
    });
    it.each(['2026-09-27T02:30', '2026-04-05T02:30'])(
        'requires an unambiguous NZ expiry (%s)',
        (expiry) => {
            expect(() =>
                preparePostingIntent(
                    row,
                    18,
                    request,
                    { skills: '', notes: '', expiry },
                    'Pacific/Auckland',
                    Date.parse('2026-01-01T00:00:00Z'),
                ),
            ).toThrow(/daylight-saving/);
        },
    );
    it('shows the exact normalized expiry intent and accepts an equivalent whole-second UTC receipt', () => {
        const captured = preparePostingIntent(
            row,
            18,
            request,
            {
                skills: 'Hoist\nHoist',
                notes: 'Support cover',
                expiry: '2026-10-20T08:00',
            },
            'Pacific/Auckland',
            Date.parse('2026-01-01T00:00:00Z'),
        );
        const value = receipt();
        expect(captured.body.expires_at).toBe('2026-10-19T19:00:00.000Z');
        expect(
            confirmedPosting(
                201,
                {
                    result: {
                        ...value.result,
                        position: {
                            ...value.result.position,
                            expires_at: '2026-10-19T19:00:00Z',
                        },
                    },
                },
                captured,
                18,
            )?.positionId,
        ).toBe(70);
    });
    it('copies the displayed target so a later read cannot change the captured submission', () => {
        const source = { ...row };
        const captured = preparePostingIntent(
            source,
            18,
            request,
            { skills: '', notes: '', expiry: '' },
            'Pacific/Auckland',
        );
        source.id = 50;
        source.source_revision = 'b'.repeat(64);
        expect(captured.row.id).toBe(32);
        expect(captured.body.source_revision).toBe('a'.repeat(64));
    });
    it('rejects a wrong page, foreign post URL, duplicate row or invalid civil instant instead of rendering false empty', () => {
        const page = {
            data: [row],
            page: 1,
            per_page: 20,
            has_more: false,
            workerTimezone: 'Pacific/Auckland',
        };
        expect(readPostablePage(page, 1)).toEqual(page);
        for (const value of [
            { ...page, page: 2 },
            {
                ...page,
                data: [{ ...row, post_url: 'https://example.com/post' }],
            },
            { ...page, data: [row, row] },
            { ...page, data: [{ ...row, starts_at: '2026-02-30T20:00:00Z' }] },
        ]) {
            expect(() => readPostablePage(value, 1)).toThrow(
                /could not be verified/,
            );
        }
    });
    it('keeps old requests unconfirmed after sparse or active current reads', () => {
        expect(postingReadback(undefined)).toContain('cannot say whether');
        expect(
            postingReadback({
                ...row,
                can_post: false,
                reason: 'duplicate',
                active_position: { id: 70, status: 'open', expired: true },
            }),
        ).toContain('cannot show whether your earlier attempt');
        expect(postingReadback(row)).toContain('not posted for cover');
    });
});
