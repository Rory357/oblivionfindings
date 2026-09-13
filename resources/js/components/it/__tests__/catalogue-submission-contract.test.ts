import { describe, expect, it } from 'vitest';
import {
    isCatalogueSubmissionIdentity,
    readCatalogueSubmissionOutcome,
} from '../catalogue-submission-contract';

const identity = {
    actorId: 3,
    itemId: 5,
    schemaVersion: 2,
    requestUuid: '11111111-1111-4111-8111-111111111111',
};
const data = {
    viewer_user_id: 3,
    catalog_item_id: 5,
    schema_version: 2,
    request_uuid: identity.requestUuid,
    submission_id: 12,
    result_type: 'ticket',
    id: 27,
    reference: 'IT-000027',
    url: '/it/tickets/27',
    replayed: false,
};

describe('catalogue submission result contract', () => {
    it('accepts only an explicit bound cancellation without a saved result', () => {
        const cancelled = {
            viewer_user_id: 3,
            catalog_item_id: 5,
            request_uuid: identity.requestUuid,
            cancelled: true,
        };
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'cancelled', data: cancelled },
                identity,
            ),
        ).toEqual({ status: 'cancelled' });
        expect(
            readCatalogueSubmissionOutcome(
                {
                    status: 'cancelled',
                    data: { ...cancelled, cancelled: false },
                },
                identity,
            ),
        ).toBeNull();
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'cancelled', data: { ...cancelled, id: 27 } },
                identity,
            ),
        ).toBeNull();
        expect(
            readCatalogueSubmissionOutcome(
                {
                    status: 'cancelled',
                    data: { ...cancelled, viewer_user_id: 4 },
                },
                identity,
            ),
        ).toBeNull();
    });
    it('accepts only a bound canonical ticket or provisioning result', () => {
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'committed', data },
                identity,
            ),
        ).toEqual({
            status: 'committed',
            submissionId: 12,
            resultId: 27,
            resultType: 'ticket',
            reference: 'IT-000027',
            url: '/it/tickets/27',
            replayed: false,
        });
        expect(
            readCatalogueSubmissionOutcome(
                {
                    status: 'committed',
                    data: {
                        ...data,
                        result_type: 'provisioning',
                        reference: null,
                        url: '/it/provisioning/27',
                        replayed: true,
                    },
                },
                identity,
            ),
        ).toMatchObject({
            resultType: 'provisioning',
            reference: null,
            replayed: true,
        });
    });

    it.each([
        { viewer_user_id: 4 },
        { catalog_item_id: 6 },
        { schema_version: 3 },
        { request_uuid: '22222222-2222-4222-8222-222222222222' },
        { submission_id: 0 },
        { submission_id: '12' },
        { id: -1 },
        { id: 1.5 },
        { replayed: 'false' },
        { result_type: 'asset' },
        { reference: null },
        { reference: 'saved' },
        { url: '/it/tickets/28' },
        { url: 'https://example.invalid/it/tickets/27' },
        { url: '/it/tickets/27?next=/settings' },
        {
            result_type: 'provisioning',
            reference: 'IT-000027',
            url: '/it/provisioning/27',
        },
    ])('rejects a mismatched or malformed result: %j', (patch) => {
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'committed', data: { ...data, ...patch } },
                identity,
            ),
        ).toBeNull();
    });

    it('keeps no saved result distinct from successful submission', () => {
        const absent = {
            viewer_user_id: 3,
            catalog_item_id: 5,
            request_uuid: identity.requestUuid,
            retry_same_command: true,
        };
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'not_found', data: absent },
                identity,
            ),
        ).toEqual({ status: 'not_found', retrySameCommand: true });
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'not_found', data: { ...absent, id: 27 } },
                identity,
            ),
        ).toBeNull();
        expect(
            readCatalogueSubmissionOutcome(
                {
                    status: 'not_found',
                    data: { ...absent, retry_same_command: false },
                },
                identity,
            ),
        ).toBeNull();
    });

    it.each([
        null,
        '<html>Request saved</html>',
        { success: 'Saved' },
        { status: 'committed', data: [] },
    ])(
        'never treats a flash, HTML response or invalid body as a committed result',
        (body) => {
            expect(readCatalogueSubmissionOutcome(body, identity)).toBeNull();
        },
    );

    it('rejects malformed stored identities before accepting a response', () => {
        expect(isCatalogueSubmissionIdentity(identity)).toBe(true);
        expect(
            isCatalogueSubmissionIdentity({
                ...identity,
                requestUuid: 'missing',
            }),
        ).toBe(false);
        expect(
            isCatalogueSubmissionIdentity({ ...identity, actorId: '3' }),
        ).toBe(false);
        expect(
            readCatalogueSubmissionOutcome(
                { status: 'committed', data },
                { ...identity, itemId: 0 },
            ),
        ).toBeNull();
    });
});
