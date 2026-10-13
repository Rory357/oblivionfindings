import { webcrypto } from 'node:crypto';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { approverCommand, prepareApproverAttempt } from './restriction-approver-contract';

// Independently derived from the backend's UTF-8 domain + NUL + canonical
// JSON contract. These assertions use actual Web Crypto, not a mocked digest.
const vectors = [
    { label: 'Unicode line separator', ids: [9, 7, 9], reason: ' \tCafé / cover\u2028\n',
        hash: '17c560a71c0d14411600f6e5623cc5a7c63c72b2cd4160ab74537f145733232f' },
    { label: 'two Unicode separators', ids: [7], reason: 'Cover\u2028then\u2029finish',
        hash: '444c465c0a2ac895be4ef9ae8fe4d1616000b4dc855f2e10d1a60967983b0591' },
    { label: 'form feed is not trim', ids: [7], reason: '\fReview\f',
        hash: 'eb8b54b52d54f239cbdfc2fd583617e2b4f0d7593eab4b2201e133904125d780' },
    { label: 'NBSP around visible text', ids: [7], reason: ' \u00a0Māori team\u00a0 ',
        hash: '9f28e8c8f22ba060715bf0828b901ab6831f1153192dc95c4043ef07fbd97c7d' },
    { label: 'interior NUL', ids: [7], reason: 'A\u0000B',
        hash: '817d165ba329355f9597f41aa4c8ec9a0bd9e7f6f24af82b5f1bc1d4b55204be' },
    { label: 'astral and slash', ids: [7], reason: 'Review 🚗 / cover',
        hash: '211b1be98deef06b8df2b274956ed187aa66df5fad1dd25d0ca40234629a6ebc' },
    { label: 'combining text distinct', ids: [7], reason: 'Cafe\u0301',
        hash: '1f05a1ac2fc17c90c7abc21881544d363d6e2e9352291378ffc8b09606143262' },
];
afterEach(() => vi.unstubAllGlobals());

describe('approver command backend hash parity', () => {
    it.each(vectors)('$label', async ({ ids, reason, hash }) => {
        vi.stubGlobal('crypto', webcrypto);
        const command = approverCommand('7d886442-95d4-4bb6-a8b5-364489f0d22d', null, ids, reason);
        expect(command).not.toBeNull();
        const attempt = await prepareApproverAttempt(4, command!);
        expect(attempt.intentHash).toBe(hash);
    });
});
