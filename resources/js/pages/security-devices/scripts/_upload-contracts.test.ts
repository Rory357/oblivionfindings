import { webcrypto } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import {
    prepareScriptUpload,
    readScriptFile,
    scriptRuntime,
    scriptUploadReply,
    type ScriptMetadata,
} from './_upload-contracts';

const corpus = JSON.parse(
    readFileSync(
        'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
const body = corpus.uploaded;
const metadata = (): ScriptMetadata =>
    Object.fromEntries(
        [
            'name',
            'publisher_label',
            'interpreter',
            'interpreter_version',
            'wrapper_sha256',
            'architecture',
            'parameter_schema',
            'execution_policy',
        ].map((key) => [key, structuredClone(body.script.manifest[key])]),
    ) as ScriptMetadata;
function file(text: string | Uint8Array, name: string) {
    const bytes =
        typeof text === 'string' ? new TextEncoder().encode(text) : text;
    return Object.assign(new File([new Uint8Array(bytes).buffer], name), {
        arrayBuffer: async () => new Uint8Array(bytes).buffer,
    });
}
const runtime = async (raw = corpus.material.runtime_manifest) =>
    scriptRuntime(await readScriptFile(file(raw, 'runtime.json'), 'runtime'));
async function prepared() {
    return prepareScriptUpload(
        body.actor_id,
        body.receipt.request_key,
        'Synthetic independent catalogue verification.',
        metadata(),
        structuredClone(body.script.review),
        await readScriptFile(
            file(corpus.material.artifact, 'inspection.ps1'),
            'script',
        ),
        await runtime(),
    );
}
beforeAll(() => vi.stubGlobal('crypto', webcrypto));
afterAll(() => vi.unstubAllGlobals());
describe('exact-byte script upload contract', () => {
    it.each(['1.0', '1e0', '1E0'])(
        'rejects float notation %s before PHP integer validation',
        async (value) => {
            await expect(
                runtime(
                    corpus.material.runtime_manifest.replace(
                        '"version":1',
                        '"version":' + value,
                    ),
                ),
            ).rejects.toThrow(/whole numbers/);
        },
    );
    it('prepares original producer bytes and binds the original upload receipt', async () => {
        const request = await prepared();
        expect(atob(request.body.artifact_base64)).toBe(
            corpus.material.artifact,
        );
        expect(atob(request.body.runtime_manifest_base64)).toBe(
            corpus.material.runtime_manifest,
        );
        expect(scriptUploadReply(body, request)).toEqual(body.script);
        const recovered = {
            ...body,
            script: {
                ...corpus.withdrawn.script,
                can_review: false,
                can_submit: false,
            },
            receipt: body.receipt,
        };
        expect(scriptUploadReply(recovered, request).status).toBe('withdrawn');
    });
    it('preserves UTF-8 and line endings without text normalization', async () => {
        const text = '# 😀\r\nWrite-Output "test"\r\n';
        const result = await readScriptFile(file(text, 'test.ps1'), 'script');
        expect(result.text).toBe(text);
        expect([...Buffer.from(result.base64, 'base64')]).toEqual([
            ...new TextEncoder().encode(text),
        ]);
        expect(result.size).toBe(new TextEncoder().encode(text).length);
    });
    it.each([
        new Uint8Array([255]),
        new Uint8Array([239, 187, 191, 35]),
        new Uint8Array([35, 0]),
    ])('rejects malformed UTF-8, BOM or null script bytes', async (bytes) => {
        await expect(
            readScriptFile(file(bytes, 'bad.ps1'), 'script'),
        ).rejects.toThrow();
    });
    it('rejects empty, oversized and wrong-extension files before retaining their contents', async () => {
        for (const f of [
            file('', 'empty.ps1'),
            file('x'.repeat(262145), 'large.ps1'),
            file('code', 'wrong.exe'),
        ])
            await expect(readScriptFile(f, 'script')).rejects.toThrow();
    });
    it('rejects duplicate runtime keys including escaped spellings', async () => {
        await expect(
            runtime(
                corpus.material.runtime_manifest.replace(
                    '"version":1',
                    '"version":1,"\\u0076ersion":1',
                ),
            ),
        ).rejects.toThrow(/duplicate/);
        await expect(
            runtime(
                corpus.material.runtime_manifest.replace(
                    '"size_bytes":10',
                    '"size_bytes":10,"size_bytes":10',
                ),
            ),
        ).rejects.toThrow(/duplicate/);
    });
    it('rejects traversal, device paths, unsorted duplicates, ancestor collisions and missing wrapper', async () => {
        const base = JSON.parse(corpus.material.runtime_manifest);
        const cases = [
            {
                ...base,
                files: [
                    { ...base.files[0], path: '../wrapper.ps1' },
                    base.files[1],
                ],
            },
            {
                ...base,
                files: [{ ...base.files[0], path: 'CON.ps1' }, base.files[1]],
            },
            { ...base, files: [base.files[1], base.files[0]] },
            {
                ...base,
                files: [base.files[0], { ...base.files[0] }, base.files[1]],
            },
            {
                ...base,
                files: [
                    { ...base.files[0], path: 'a' },
                    { ...base.files[0], path: 'a-child' },
                    { ...base.files[0], path: 'a/b' },
                    ...base.files,
                ],
            },
            {
                ...base,
                files: [
                    { ...base.files[0], path: 'another.ps1' },
                    base.files[1],
                ],
            },
        ];
        for (const value of cases)
            await expect(runtime(JSON.stringify(value))).rejects.toThrow();
    });
    it('rejects a response with the wrong original request, author, effect, script or runtime', async () => {
        const request = await prepared();
        for (const mutate of [
            (value: typeof body) => {
                value.receipt.request_key =
                    '11111111-1111-4111-8111-111111111111';
            },
            (value: typeof body) => {
                value.script.uploaded_by_user_id++;
            },
            (value: typeof body) => {
                value.script.review.compensation = 'Changed';
            },
            (value: typeof body) => {
                value.script.manifest.sha256 = 'f'.repeat(64);
            },
            (value: typeof body) => {
                value.script.manifest.runtime_manifest_sha256 = 'f'.repeat(64);
            },
            (value: typeof body) => {
                value.receipt.event = 'review';
            },
        ]) {
            const changed = structuredClone(body);
            mutate(changed);
            expect(() => scriptUploadReply(changed, request)).toThrow();
        }
    });
    it('detaches retry intent from form objects and checks parameter and execution bounds', async () => {
        const m = metadata(),
            review = structuredClone(body.script.review);
        const script = await readScriptFile(
                file(corpus.material.artifact, 'test.ps1'),
                'script',
            ),
            r = await runtime();
        const request = prepareScriptUpload(
            body.actor_id,
            body.receipt.request_key,
            'A valid upload reason.',
            m,
            review,
            script,
            r,
        );
        m.execution_policy.runtime_seconds = 901;
        review.compensation = 'Changed after sending';
        expect(request.body.metadata.execution_policy.runtime_seconds).toBe(60);
        expect(request.body.review.compensation).toBe(
            body.script.review.compensation,
        );
        expect(() =>
            prepareScriptUpload(
                body.actor_id,
                body.receipt.request_key,
                'A valid upload reason.',
                m,
                review,
                script,
                r,
            ),
        ).toThrow();
        const invalid = metadata();
        invalid.parameter_schema = [
            {
                name: 'Verbose',
                type: 'boolean',
                required: true,
                constraints: {},
            },
        ];
        expect(() =>
            prepareScriptUpload(
                body.actor_id,
                body.receipt.request_key,
                'A valid upload reason.',
                invalid,
                review,
                script,
                r,
            ),
        ).toThrow();
    });
    it('produces exact upload requests for the PHP reader with all four input types', async () => {
        const basic = await prepared();
        const source = '# Synthetic UTF-8 fixture 😀\r\n# Never execute.\r\n';
        const m = metadata();
        m.parameter_schema = [
            {
                name: 'Enabled',
                type: 'boolean',
                required: true,
                constraints: {},
            },
            {
                name: 'Count',
                type: 'integer',
                required: false,
                constraints: { minimum: -3, maximum: 100 },
            },
            {
                name: 'Message',
                type: 'string',
                required: true,
                constraints: { min_length: 1, max_length: 120 },
            },
            {
                name: 'Mode',
                type: 'enum',
                required: true,
                constraints: { values: ['inspect', 'report 😀'] },
            },
        ];
        m.execution_policy = {
            ...m.execution_policy,
            output_bytes: 0,
            timeout_mode: 'terminate_owned_job',
            success_exit_codes: [0, 42],
        };
        const typed = prepareScriptUpload(
            body.actor_id,
            '22222222-2222-4222-8222-222222222222',
            'Synthetic UTF-8 upload verification.',
            m,
            structuredClone(body.script.review),
            await readScriptFile(file(source, 'typed.ps1'), 'script'),
            await runtime(),
        );
        expect(typed.body.metadata.parameter_schema).toHaveLength(4);
        const path = process.env.ENDPOINT_SCRIPT_UPLOAD_EXPORT;
        if (path)
            writeFileSync(
                path,
                JSON.stringify(
                    [
                        {
                            request: basic.body,
                            request_json: JSON.stringify(basic.body),
                            expected_manifest: basic.manifest,
                            artifact: corpus.material.artifact,
                        },
                        {
                            request: typed.body,
                            request_json: JSON.stringify(typed.body),
                            expected_manifest: typed.manifest,
                            artifact: source,
                        },
                    ],
                    null,
                    2,
                ) + '\n',
            );
    });
});
