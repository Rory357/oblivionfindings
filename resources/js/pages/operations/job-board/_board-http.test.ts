import { describe, expect, it } from 'vitest';

import { newRequestId, refusalMessage } from './_board-http';

const definite = [409, 419, 422] as const;

describe('refusals that prove nothing was saved', () => {
    it('reads validation errors from a definite refusal', () => {
        expect(
            refusalMessage(
                422,
                { message: 'x', errors: { claim: ['Already claimed.'] } },
                definite,
            ),
        ).toBe('Already claimed.');
    });

    it('never treats a status that can follow a save as a refusal', () => {
        for (const status of [200, 202, 302, 403, 404, 500, 502]) {
            expect(
                refusalMessage(
                    status,
                    { message: 'Nope', errors: { claim: ['Nope'] } },
                    definite,
                ),
            ).toBeNull();
        }
    });

    it('gives a plain message for an expired session', () => {
        expect(refusalMessage(419, null, definite)).toMatch(
            /session has expired/,
        );
    });

    it('counts a 503 only when it carries the eligibility check field error', () => {
        expect(
            refusalMessage(
                503,
                {
                    message: 'x',
                    errors: {
                        position: [
                            'Eligibility checks are temporarily unavailable. No assignment was made.',
                        ],
                    },
                },
                definite,
            ),
        ).toMatch(/temporarily unavailable/);
        expect(
            refusalMessage(503, { message: 'Service Unavailable' }, definite),
        ).toBeNull();
    });

    it('ignores a body that carries a result', () => {
        expect(
            refusalMessage(422, { result: {}, message: 'x' }, definite),
        ).toBeNull();
    });
});

describe('request ids', () => {
    it('are v4 UUIDs', () => {
        expect(newRequestId()).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
    });
});
