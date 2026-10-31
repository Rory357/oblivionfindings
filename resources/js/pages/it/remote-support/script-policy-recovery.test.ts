import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    scriptPolicyRecoveredReply,
    scriptPolicyReferenceFor,
    scriptPolicyReply,
    type ScriptPolicyAttempt,
} from './script-policy-contract';
import {
    discardUnreadableScriptPolicyReferences,
    releaseScriptPolicyReference,
    retainScriptPolicyReference,
    scriptPolicyReferences,
} from './script-policy-journal';

// Exact original PHP HTTP producer bytes, not a hand-shaped contract fixture.
const corpus = JSON.parse(
    readFileSync(
        process.env.ENDPOINT_SCRIPT_POLICY_CORPUS ??
            'resources/js/pages/it/remote-support/__fixtures__/script-policy-http.json',
        'utf8',
    ),
) as {
    version: 1;
    actor_id: number;
    device_id: number;
    documents: Array<{
        name: string;
        method: string;
        request_json: string | null;
        status: number;
        response_json: string;
    }>;
};
const actor = corpus.actor_id,
    device = corpus.device_id;
const original = corpus.documents.find((row) => row.name === 'original_save')!;
const attempt = JSON.parse(original.request_json!) as ScriptPolicyAttempt;
beforeEach(() => {
    sessionStorage.clear();
    vi.stubGlobal('crypto', webcrypto);
});
afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('original native script policy recovery', () => {
    it('accepts original HTTP saves replays and expired-proof recovery with their exact private intent', async () => {
        expect(corpus.version).toBe(1);
        expect(corpus.documents).toHaveLength(12);
        let accepted = 0;
        for (const document of corpus.documents.filter(
            (row) => row.status === 200,
        )) {
            const value = JSON.parse(document.response_json);
            if (document.request_json) {
                const body = JSON.parse(
                    document.request_json,
                ) as ScriptPolicyAttempt;
                const ref = await scriptPolicyReferenceFor(body, device);
                expect(
                    scriptPolicyReply(value, actor, device, body).receipt
                        ?.reason,
                ).toBe(body.reason);
                retainScriptPolicyReference(ref);
                expect(
                    await scriptPolicyRecoveredReply(
                        value,
                        actor,
                        device,
                        scriptPolicyReferences(actor, device)[0],
                    ),
                ).toEqual(value);
                releaseScriptPolicyReference(ref);
            } else if (document.name === 'original_recovery') {
                const ref = await scriptPolicyReferenceFor(attempt, device);
                const dto = await scriptPolicyRecoveredReply(
                    value,
                    actor,
                    device,
                    ref,
                );
                expect(dto.identity_confirmed).toBe(false);
                expect(dto.receipt?.policy.enabled).toBe(true);
                expect(dto.policy.enabled).toBe(false);
                expect(dto.policy.version).toBeGreaterThan(
                    dto.receipt!.policy.version,
                );
                expect(dto.receipt?.reason).toBe(
                    '  Approved Māori staff / computer policy review.  ',
                );
            } else
                expect(scriptPolicyReply(value, actor, device).available).toBe(
                    false,
                );
            accepted++;
        }
        expect(accepted).toBe(7);
        expect(scriptPolicyReferences(actor, device)).toEqual([]);
    });

    it('refuses original private denial bodies and altered recovered intent', async () => {
        const ref = await scriptPolicyReferenceFor(attempt, device);
        for (const document of corpus.documents.filter(
            (row) => row.status !== 200,
        )) {
            await expect(
                scriptPolicyRecoveredReply(
                    JSON.parse(document.response_json),
                    actor,
                    device,
                    ref,
                ),
            ).rejects.toThrow();
        }
        for (const change of [
            { reason: 'An altered but otherwise valid reason.' },
            {
                policy: {
                    version: 2,
                    enabled: false,
                    consent_mode: 'attended',
                },
            },
        ]) {
            const altered = JSON.parse(original.response_json);
            Object.assign(altered.receipt, change);
            // Make the current policy internally consistent too: only the retained
            // original-intent digest can detect this changed saved result.
            altered.policy = { ...altered.receipt.policy };
            await expect(
                scriptPolicyRecoveredReply(altered, actor, device, ref),
            ).rejects.toThrow();
        }
        await expect(
            scriptPolicyRecoveredReply(
                JSON.parse(original.response_json),
                actor + 1,
                device,
                ref,
            ),
        ).rejects.toThrow();
        await expect(
            scriptPolicyRecoveredReply(
                JSON.parse(original.response_json),
                actor,
                device + 1,
                ref,
            ),
        ).rejects.toThrow();
    });

    it('retains only opaque scoped references and preserves exact reason bytes in their digest', async () => {
        const ref = await scriptPolicyReferenceFor(attempt, device);
        const reversed = Object.fromEntries(
            Object.entries(attempt).reverse(),
        ) as ScriptPolicyAttempt;
        expect(await scriptPolicyReferenceFor(reversed, device)).toEqual(ref);
        const trimmed = await scriptPolicyReferenceFor(
            { ...attempt, reason: attempt.reason.trim() },
            device,
        );
        expect(trimmed.intent_sha256).not.toBe(ref.intent_sha256);
        retainScriptPolicyReference(ref);
        retainScriptPolicyReference(ref);
        const raw = sessionStorage.getItem(sessionStorage.key(0)!)!;
        expect(raw).not.toMatch(/reason|enabled|consent_mode|Māori|Approved/);
        expect(scriptPolicyReferences(actor, device)).toEqual([ref]);
        expect(scriptPolicyReferences(actor + 1, device)).toEqual([]);
        expect(scriptPolicyReferences(actor, device + 1)).toEqual([]);
        expect(() =>
            releaseScriptPolicyReference({
                ...ref,
                intent_sha256: 'b'.repeat(64),
            }),
        ).toThrow();
        expect(scriptPolicyReferences(actor, device)).toEqual([ref]);
    });

    it('bounds unresolved references without evicting earlier requests', async () => {
        const ref = await scriptPolicyReferenceFor(attempt, device);
        const refs = Array.from({ length: 9 }, (_, index) => ({
            ...ref,
            request_key: `${(index + 1).toString(16).padStart(8, '0')}-1111-4111-8111-111111111111`,
        }));
        for (const row of refs.slice(0, 8)) retainScriptPolicyReference(row);
        expect(() => retainScriptPolicyReference(refs[8])).toThrow();
        expect(scriptPolicyReferences(actor, device)).toHaveLength(8);
        expect(
            scriptPolicyReferences(actor, device).some(
                (row) => row.request_key === refs[0].request_key,
            ),
        ).toBe(true);
    });

    it('clears unreadable data only after explicit scoped cleanup and preserves other accounts', async () => {
        const ref = await scriptPolicyReferenceFor(attempt, device);
        retainScriptPolicyReference(ref);
        retainScriptPolicyReference({ ...ref, actor_id: actor + 1 });
        expect(() =>
            discardUnreadableScriptPolicyReferences(actor, device),
        ).toThrow();
        const key = `obli.script-policy.references.v1:${actor}:${device}`;
        sessionStorage.setItem(key, '{broken');
        expect(() => scriptPolicyReferences(actor, device)).toThrow();
        discardUnreadableScriptPolicyReferences(actor, device);
        expect(scriptPolicyReferences(actor, device)).toEqual([]);
        expect(scriptPolicyReferences(actor + 1, device)).toHaveLength(1);
    });

    it('rejects injected fields cross-scope references duplicate keys and invalid Unicode intent', async () => {
        const ref = await scriptPolicyReferenceFor(attempt, device);
        const key = `obli.script-policy.references.v1:${actor}:${device}`;
        for (const rows of [
            [{ ...ref, reason: 'must not persist private content' }],
            [{ ...ref, actor_id: actor + 1 }],
            [ref, ref],
        ]) {
            sessionStorage.setItem(key, JSON.stringify(rows));
            expect(() => scriptPolicyReferences(actor, device)).toThrow();
        }
        await expect(
            scriptPolicyReferenceFor(
                {
                    ...attempt,
                    reason: 'Invalid isolated surrogate \ud800 here.',
                },
                device,
            ),
        ).rejects.toThrow();
    });
});
