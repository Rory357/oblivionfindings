import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import {
    scriptIndex,
    scriptMaterial,
    scriptRecord,
    scriptReply,
    scriptText,
} from './_contracts';

// Original HTTP bodies from EndpointScriptCatalogueTest's synthetic lifecycle.
// These are consumed without rewriting their keys, values or content hashes.
const corpus = JSON.parse(
    readFileSync(
        process.env.ENDPOINT_SCRIPT_HTTP_CORPUS ??
            'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value));
beforeAll(() => vi.stubGlobal('crypto', webcrypto));
afterAll(() => vi.unstubAllGlobals());

describe('PHP catalogue HTTP documents', () => {
    it.each(['uploaded', 'pending', 'published', 'withdrawn'])(
        'reads the original %s receipt',
        (name) => {
            const body = corpus[name];
            expect(
                scriptReply(
                    body,
                    body.actor_id,
                    undefined,
                    body.receipt.request_key,
                ).script,
            ).toEqual(body.script);
        },
    );
    it.each(['index_pending', 'index_published', 'index_withdrawn'])(
        'reads the original %s page',
        (name) => {
            expect(scriptIndex(corpus[name])).toEqual(corpus[name]);
        },
    );
    it('verifies the original private bytes against the producer hashes', async () => {
        const index = scriptIndex(corpus.index_pending);
        expect(
            await scriptMaterial(
                corpus.material,
                index.actor_id,
                index.scripts[0],
            ),
        ).toEqual(corpus.material);
    });
    it.each(['artifact', 'runtime_manifest'])(
        'rejects changed %s bytes',
        async (key) => {
            const body = copy(corpus.material);
            body[key] = body[key].replace(/./, 'X');
            await expect(
                scriptMaterial(
                    body,
                    corpus.index_pending.actor_id,
                    corpus.index_pending.scripts[0],
                ),
            ).rejects.toThrow();
        },
    );
    it.each(['actor_id', 'content_hash', 'review'])(
        'rejects material detached from its expected %s',
        async (key) => {
            const body = copy(corpus.material);
            if (key === 'actor_id') body.actor_id++;
            if (key === 'content_hash') body.content_hash = 'f'.repeat(64);
            if (key === 'review') body.review.compensation = 'Changed review';
            await expect(
                scriptMaterial(
                    body,
                    corpus.index_pending.actor_id,
                    corpus.index_pending.scripts[0],
                ),
            ).rejects.toThrow();
        },
    );
    it('rejects invalid Unicode before browser UTF-8 replacement can hide it', async () => {
        for (const text of ['\ud800', '\u0000', '\ufeff']) {
            const body = copy(corpus.material);
            body.artifact = text + body.artifact.slice(1);
            await expect(
                scriptMaterial(
                    body,
                    corpus.index_pending.actor_id,
                    corpus.index_pending.scripts[0],
                ),
            ).rejects.toThrow();
        }
        expect(scriptText('A😀', 2)).toBe(true);
        expect(scriptText('A😀', 1)).toBe(false);
        expect(scriptText('A\u202e', 10)).toBe(false);
    });
    it('binds replies to actor, exact script, digest, version and request', () => {
        const expected = corpus.index_pending.scripts[0],
            actor = corpus.published.actor_id;
        for (const mutate of [
            (body: typeof corpus.published) => {
                body.actor_id++;
            },
            (body: typeof corpus.published) => {
                body.script.uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            },
            (body: typeof corpus.published) => {
                body.script.content_hash = 'f'.repeat(64);
            },
            (body: typeof corpus.published) => {
                body.script.manifest.execution_policy.runtime_seconds++;
            },
            (body: typeof corpus.published) => {
                body.script.review.compensation = 'Unexpected revision';
            },
            (body: typeof corpus.published) => {
                body.script.uploaded_by_user_id++;
            },
            (body: typeof corpus.published) => {
                body.script.status_version = 1;
            },
            (body: typeof corpus.published) => {
                body.receipt.request_key =
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            },
            (body: typeof corpus.published) => {
                body.receipt.status_version = 99;
            },
            (body: typeof corpus.published) => {
                body.receipt.content_hash = 'f'.repeat(64);
            },
        ]) {
            const body = copy(corpus.published);
            mutate(body);
            expect(() =>
                scriptReply(
                    body,
                    actor,
                    expected,
                    corpus.published.receipt.request_key,
                ),
            ).toThrow();
        }
    });
    it('accepts an original approval receipt with a later withdrawn current record', () => {
        const body = { ...corpus.withdrawn, receipt: corpus.published.receipt };
        expect(
            scriptReply(
                body,
                body.actor_id,
                corpus.index_pending.scripts[0],
                body.receipt.request_key,
            ).script.status,
        ).toBe('withdrawn');
    });
    it('rejects extra fields, type coercion, self-review and inconsistent capabilities', () => {
        for (const mutate of [
            (row: typeof corpus.pending.script) => {
                row.unexpected = true;
            },
            (row: typeof corpus.pending.script) => {
                row.status_version = '2';
            },
            (row: typeof corpus.pending.script) => {
                row.manifest.execution_policy.output_bytes = '4096';
            },
            (row: typeof corpus.pending.script) => {
                row.review.no_dynamic_code = false;
            },
            (row: typeof corpus.pending.script) => {
                row.uploaded_by_user_id = corpus.index_pending.actor_id;
            },
            (row: typeof corpus.pending.script) => {
                row.can_read_material = false;
            },
            (row: typeof corpus.pending.script) => {
                row.publication_issue = 'runtime_unavailable';
            },
        ]) {
            const row = copy(corpus.index_pending.scripts[0]);
            mutate(row);
            expect(() =>
                scriptRecord(row, corpus.index_pending.actor_id),
            ).toThrow();
        }
    });
    it('rejects untrusted paging, duplicate rows, false counts and execution availability', () => {
        for (const mutate of [
            (body: typeof corpus.index_pending) => {
                body.pagination.next_href = 'https://elsewhere.invalid/';
            },
            (body: typeof corpus.index_pending) => {
                body.pagination.next_href =
                    body.pagination.reset_href + '&expected_actor_id=999';
            },
            (body: typeof corpus.index_pending) => {
                body.pagination.reset_href += '#elsewhere';
            },
            (body: typeof corpus.index_pending) => {
                body.pagination.shown = 0;
            },
            (body: typeof corpus.index_pending) => {
                body.scripts.push(body.scripts[0]);
                body.pagination.shown = 2;
            },
            (body: typeof corpus.index_pending) => {
                body.execution_available = true;
            },
            (body: typeof corpus.index_pending) => {
                body.query.status = 'published';
            },
        ]) {
            const body = copy(corpus.index_pending);
            mutate(body);
            expect(() => scriptIndex(body)).toThrow();
        }
    });
});
