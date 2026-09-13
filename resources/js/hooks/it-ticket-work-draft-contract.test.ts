import { describe, expect, it } from 'vitest';
import {
    readDraftPayload,
    type ItDraftPurpose,
} from './it-ticket-draft-contract';

describe('ticket work draft recovery limits', () => {
    it.each([
        ['public_reply', 'work_payload'],
        ['internal_note', 'work_payload'],
        ['ticket_work', 'work_form'],
    ] as const)('restores longer %s work details', (purpose, field) => {
        const payload = {
            step_index: 0,
            fields: { [field]: JSON.stringify({ details: 'a'.repeat(6000) }) },
        };
        expect(readDraftPayload(payload, purpose)).toEqual(payload);
    });

    it('rejects work fields over the server limit and oversized combined drafts', () => {
        const read = (
            fields: object,
            purpose: ItDraftPurpose = 'public_reply',
        ) => readDraftPayload({ step_index: 0, fields }, purpose);
        expect(
            read(
                { work_form: JSON.stringify('a'.repeat(60000)) },
                'ticket_work',
            ),
        ).toBeNull();
        expect(
            read({
                body: 'a'.repeat(5000),
                work_payload: JSON.stringify('a'.repeat(59000)),
            }),
        ).not.toBeNull();
        expect(
            read({
                body: 'a'.repeat(5000),
                work_payload: JSON.stringify('é'.repeat(40000)),
            }),
        ).toBeNull();
    });

    it('keeps note body limits and audience field boundaries', () => {
        expect(
            readDraftPayload(
                { step_index: 0, fields: { body: 'a'.repeat(5001) } },
                'public_reply',
            ),
        ).toBeNull();
        expect(
            readDraftPayload(
                { step_index: 0, fields: { work_form: '{}' } },
                'public_reply',
            ),
        ).toBeNull();
        expect(
            readDraftPayload(
                { step_index: 0, fields: { work_payload: '{}' } },
                'ticket_work',
            ),
        ).toBeNull();
    });
});
