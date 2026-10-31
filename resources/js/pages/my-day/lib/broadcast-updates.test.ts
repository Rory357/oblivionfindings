import { describe, expect, it } from 'vitest';
import { isBroadcastUpdate, validateBroadcastUpdates } from './broadcast-updates';

const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const read = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const reader = {
    schema_version: 1, viewer_id: 7, read_id: read, checked_at: '2026-10-21T01:00:00Z',
    capabilities: { mark_read: false, acknowledge: false, bulk_mark_read: false },
};
const update = {
    id, title: 'Kia ora whānau — 你好 👋\nPlease read the full message.', at: 'Today',
    broadcast: { schema_version: 1, notification_id: id, recipient_viewer_id: 7,
        reader_read_id: read, href: `/notifications?notification=${id}` },
};

describe('My Day typed broadcast preview association', () => {
    it('accepts the slim reader command capabilities without changing the preview association', () => {
        const eligibleReader = {
            ...reader,
            capabilities: { mark_read: true, acknowledge: false, bulk_mark_read: true },
        };
        expect(validateBroadcastUpdates([update], eligibleReader, 7)?.[0]).toBe(update);
        expect(validateBroadcastUpdates([], eligibleReader, 7)).toEqual([]);
    });
    it('retains the exact admitted item and Unicode, separate from ordinary updates', () => {
        expect(validateBroadcastUpdates([{ id: 'other', title: 'Ordinary', at: 'Today' }, update], reader, 7))
            .toEqual([update]);
        expect(validateBroadcastUpdates([update], reader, 7)?.[0]).toBe(update);
    });
    it.each([null, {}, { ...reader, viewer_id: 8 }, { ...reader, schema_version: 2 },
        { ...reader, read_id: 'invalid' }, { ...reader, checked_at: 'yesterday' },
        { ...reader, capabilities: { ...reader.capabilities, acknowledge: true } }])(
        'rejects unavailable or mismatched reader metadata', (candidate) => {
            expect(validateBroadcastUpdates([update], candidate, 7)).toBeNull();
        },
    );
    it.each([
        { notification_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' },
        { recipient_viewer_id: 8 }, { reader_read_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' },
        { href: '/notifications' }, { href: '/notifications?notification=' + id + '#extra' },
        { href: 'https://example.com/notifications?notification=' + id },
        { schema_version: 2 },
    ])('rejects a corrupted typed association without ordinary fallback', (change) => {
        expect(validateBroadcastUpdates([{ ...update, broadcast: { ...update.broadcast, ...change } }], reader, 7)).toBeNull();
    });
    it('withholds the whole typed preview on duplicate identity or malformed marker', () => {
        expect(validateBroadcastUpdates([update, update], reader, 7)).toBeNull();
        const malformed = { ...update, broadcast: null };
        expect(isBroadcastUpdate(malformed as never)).toBe(true);
        expect(validateBroadcastUpdates([malformed], reader, 7)).toBeNull();
    });
    it('distinguishes a verified empty preview from unavailable data', () => {
        expect(validateBroadcastUpdates([], reader, 7)).toEqual([]);
        expect(validateBroadcastUpdates(undefined, reader, 7)).toBeNull();
        expect(validateBroadcastUpdates([], reader, null)).toBeNull();
    });
});
