import {
    scriptCanonical,
    scriptManifest,
    scriptReply,
    scriptReview,
    scriptText,
    type ScriptManifest,
    type ScriptReview,
} from './_contracts';

export type ScriptMetadata = Pick<
    ScriptManifest,
    | 'name'
    | 'publisher_label'
    | 'interpreter'
    | 'interpreter_version'
    | 'wrapper_sha256'
    | 'architecture'
    | 'parameter_schema'
    | 'execution_policy'
>;
export type ScriptFile = {
    name: string;
    text: string;
    base64: string;
    size: number;
    sha256: string;
};
export type RuntimeSelection = ScriptFile & {
    version: string;
    architecture: 'amd64' | 'arm64';
    wrapper: string;
};
export type ScriptUpload = {
    expected_actor_id: number;
    request_key: string;
    reason: string;
    metadata: ScriptMetadata;
    review: ScriptReview;
    artifact_base64: string;
    runtime_manifest_base64: string;
};
export type PreparedScriptUpload = {
    body: ScriptUpload;
    manifest: ScriptManifest;
};
function check(
    value: unknown,
    message = 'Check the selected script and runtime manifest.',
): asserts value {
    if (!value) throw Error(message);
}
function exact(value: unknown, keys: string[]): Record<string, unknown> {
    check(value && typeof value === 'object' && !Array.isArray(value));
    check(Object.keys(value).sort().join('\0') === [...keys].sort().join('\0'));
    return value as Record<string, unknown>;
}
function strictJson(raw: string): unknown {
    const value: unknown = JSON.parse(raw);
    const stack: { object: boolean; key: boolean; seen: Set<string> }[] = [];
    for (const token of raw.match(
        /"(?:\\.|[^"\\])*"|[{}[\],:]|[^\s{}[\],:]+/gs,
    ) ?? []) {
        // JSON.parse erases integer-versus-float notation; PHP deliberately
        // rejects floats for every numeric field in this closed runtime shape.
        check(
            !/^[0-9-]/.test(token) || /^(0|[1-9][0-9]*)$/.test(token),
            'Runtime sizes and version must use whole numbers without decimals or exponents.',
        );
        if (token === '{' || token === '[') {
            stack.push({
                object: token === '{',
                key: token === '{',
                seen: new Set(),
            });
            check(stack.length <= 32);
            continue;
        }
        if (token === '}' || token === ']') {
            stack.pop();
            continue;
        }
        const scope = stack.at(-1);
        if (!scope?.object) continue;
        if (token === ',') scope.key = true;
        else if (scope.key && token.startsWith('"')) {
            const key = JSON.parse(token);
            check(
                !scope.seen.has(key),
                'The runtime manifest contains duplicate fields.',
            );
            scope.seen.add(key);
            scope.key = false;
        }
    }
    return value;
}
const sha = async (bytes: Uint8Array) =>
    [
        ...new Uint8Array(
            await crypto.subtle.digest('SHA-256', new Uint8Array(bytes).buffer),
        ),
    ]
        .map((n) => n.toString(16).padStart(2, '0'))
        .join('');
export async function readScriptFile(
    file: File,
    kind: 'script' | 'runtime',
): Promise<ScriptFile> {
    const maximum = kind === 'script' ? 262144 : 1048576;
    check(
        file.size > 0 && file.size <= maximum,
        `Choose a ${kind === 'script' ? 'script up to 256 KB' : 'runtime manifest up to 1 MB'}.`,
    );
    check(
        kind === 'script'
            ? /\.ps1$/i.test(file.name)
            : /\.json$/i.test(file.name),
        'Choose a PowerShell .ps1 script or a .json runtime manifest.',
    );
    const bytes = new Uint8Array(await file.arrayBuffer());
    check(bytes.length === file.size && bytes.length <= maximum);
    const text = new TextDecoder('utf-8', {
        fatal: true,
        ignoreBOM: true,
    }).decode(bytes);
    check(
        !text.startsWith('\uFEFF') && !text.includes('\0'),
        'Use UTF-8 without a byte-order mark or null characters.',
    );
    // Chunking avoids an argument-stack overflow at the permitted 1 MB limit.
    let binary = '';
    for (let start = 0; start < bytes.length; start += 8192)
        binary += String.fromCharCode(...bytes.subarray(start, start + 8192));
    return {
        name: file.name,
        text,
        base64: btoa(binary),
        size: bytes.length,
        sha256: await sha(bytes),
    };
}
export function scriptRuntime(file: ScriptFile): RuntimeSelection {
    const r = exact(strictJson(file.text), [
        'version',
        'interpreter',
        'interpreter_version',
        'architecture',
        'entrypoint',
        'files',
    ]);
    check(
        r.version === 1 &&
            r.interpreter === 'powershell7' &&
            typeof r.interpreter_version === 'string' &&
            /^7\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,3})$/.test(
                r.interpreter_version,
            ),
    );
    check(
        (r.architecture === 'amd64' || r.architecture === 'arm64') &&
            r.entrypoint === 'pwsh.exe',
    );
    check(
        Array.isArray(r.files) && r.files.length >= 2 && r.files.length <= 4096,
    );
    let previous = '',
        total = 0,
        entry = false,
        wrapper = '';
    const paths = new Set<string>();
    for (const raw of r.files) {
        const f = exact(raw, ['path', 'size_bytes', 'sha256']);
        check(
            typeof f.path === 'string' &&
                f.path.length <= 240 &&
                /^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/.test(f.path),
        );
        const segments = f.path.split('/');
        check(
            segments.every(
                (p) =>
                    p !== '.' &&
                    p !== '..' &&
                    !p.endsWith('.') &&
                    !/^(?:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/i.test(p),
            ),
        );
        const key = f.path.toLowerCase();
        check(
            previous < key,
            'Runtime files must be unique and sorted by path.',
        );
        for (let i = 1; i < segments.length; i++)
            check(
                !paths.has(segments.slice(0, i).join('/').toLowerCase()),
                'A runtime file cannot also be a folder.',
            );
        check(
            typeof f.size_bytes === 'number' &&
                Number.isSafeInteger(f.size_bytes) &&
                f.size_bytes > 0 &&
                f.size_bytes <= 536870912,
        );
        check(typeof f.sha256 === 'string' && /^[0-9a-f]{64}$/.test(f.sha256));
        total += f.size_bytes;
        check(total <= 2147483648);
        paths.add(key);
        previous = key;
        if (f.path === 'pwsh.exe') entry = true;
        if (f.path === 'obli-script-wrapper.ps1') wrapper = f.sha256;
    }
    check(
        entry && wrapper,
        'The runtime manifest must identify PowerShell and the reviewed script wrapper.',
    );
    return {
        ...file,
        version: r.interpreter_version,
        architecture: r.architecture,
        wrapper,
    };
}
export function prepareScriptUpload(
    actor: number,
    key: string,
    reason: string,
    metadata: ScriptMetadata,
    review: ScriptReview,
    file: ScriptFile,
    runtime: RuntimeSelection,
): PreparedScriptUpload {
    check(
        Number.isSafeInteger(actor) &&
            actor > 0 &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
                key,
            ),
    );
    check(scriptText(reason, 500, 10), 'Give a reason of 10–500 characters.');
    check(
        scriptText(metadata.name, 200, 1) &&
            scriptText(metadata.publisher_label, 200, 1),
        'Enter a script name and publisher using 1–200 readable characters each.',
    );
    check(
        scriptText(review.compensation, 1000, 1),
        'Add a compensation or recovery plan using 1–1,000 readable characters.',
    );
    const manifest = scriptManifest({
        ...metadata,
        script_uuid: key,
        script_version: 1,
        sha256: file.sha256,
        size_bytes: file.size,
        runtime_manifest_sha256: runtime.sha256,
    });
    scriptReview(review);
    check(
        metadata.interpreter_version === runtime.version &&
            metadata.architecture === runtime.architecture &&
            metadata.wrapper_sha256 === runtime.wrapper,
    );
    const encode = new TextEncoder();
    check(
        encode.encode(JSON.stringify(metadata)).length <= 32768 &&
            encode.encode(JSON.stringify(review)).length <= 32768,
        'Reduce the input definitions or review notes.',
    );
    const body = {
        expected_actor_id: actor,
        request_key: key,
        reason,
        metadata,
        review,
        artifact_base64: file.base64,
        runtime_manifest_base64: runtime.base64,
    };
    check(
        encode.encode(JSON.stringify(body)).length <= 1900000,
        'The upload exceeds the allowed request size.',
    );
    // Freeze a detached copy so editing fields cannot change a retained retry.
    return JSON.parse(JSON.stringify({ body, manifest }));
}
export function scriptUploadReply(
    value: unknown,
    expected: PreparedScriptUpload,
) {
    const reply = scriptReply(
        value,
        expected.body.expected_actor_id,
        undefined,
        expected.body.request_key,
    );
    const row = reply.script;
    check(
        reply.receipt?.event === 'upload' &&
            reply.receipt.status === 'uploaded' &&
            reply.receipt.status_version === 1,
    );
    check(row.uploaded_by_user_id === expected.body.expected_actor_id);
    check(
        scriptCanonical({
            ...row.manifest,
            script_uuid: expected.manifest.script_uuid,
        }) === scriptCanonical(expected.manifest),
    );
    check(
        scriptCanonical(row.review) === scriptCanonical(expected.body.review),
    );
    return row;
}
