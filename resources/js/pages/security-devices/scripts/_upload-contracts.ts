import {
    reservedScriptParameterNames,
    scriptCanonical,
    scriptManifest,
    scriptParameterName,
    scriptReply,
    scriptReview,
    scriptText,
    textIssue,
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
    check(
        scriptText(reason, 500, 10),
        'Give a reason of 10–500 characters on one line.',
    );
    check(
        scriptText(metadata.name, 200, 1) &&
            scriptText(metadata.publisher_label, 200, 1),
        'Enter a script name and publisher using 1–200 readable characters each.',
    );
    check(
        scriptText(review.compensation, 1000, 1),
        'Add a compensation or recovery plan using 1–1,000 readable characters.',
    );
    let manifest: ScriptManifest;
    try {
        manifest = scriptManifest({
            ...metadata,
            script_uuid: key,
            script_version: 1,
            sha256: file.sha256,
            size_bytes: file.size,
            runtime_manifest_sha256: runtime.sha256,
        });
        scriptReview(review);
    } catch {
        // scriptUploadIssues names the field for every rule a person can
        // break; anything left is a mismatch the form can't explain. Never
        // suggest refreshing: that would discard the draft.
        throw Error(
            'Some script details could not be checked. Review the inputs and limits, then upload again.',
        );
    }
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
/** Non-blank trimmed lines: "one per line" fields ignore empty lines. */
export const scriptLines = (value: string) =>
    value
        .split(/\r?\n/)
        .map((x) => x.trim())
        .filter(Boolean);
export type ScriptUploadDraft = {
    metadata: ScriptMetadata;
    review: ScriptReview;
    file: ScriptFile | null;
    runtime: RuntimeSelection | null;
    exitCodes: string;
    children: string;
    destinations: string;
    acknowledged: boolean;
    reason: string;
};
/** Wizard section index (0 files … 3 check and upload) for each field id. */
export type ScriptUploadIssue = {
    field: string;
    step: number;
    message: string;
};
const whole = (x: unknown, min: number, max: number): x is number =>
    typeof x === 'number' && Number.isSafeInteger(x) && x >= min && x <= max;
const count = (n: number) => n.toLocaleString('en-NZ');
function listIssue(value: string, noun: string): string | null {
    const rows = scriptLines(value);
    if (rows.length > 32) return `List up to 32 ${noun}, one per line.`;
    for (const row of rows) {
        const issue = textIssue(row, 1, 256, noun + ' list');
        if (issue) return issue;
    }
    return null;
}
/**
 * Every rule a person can break while filling in the upload, as a fixable
 * message next to its field. Mirrors the catalogue's own manifest rules so
 * that the generic contract check never has to explain a user mistake.
 */
export function scriptUploadIssues(
    draft: ScriptUploadDraft,
): ScriptUploadIssue[] {
    const issues: ScriptUploadIssue[] = [];
    const add = (field: string, step: number, message: string | null) => {
        if (message) issues.push({ field, step, message });
    };
    const { metadata, review } = draft;
    add('script-name', 0, textIssue(metadata.name, 1, 200, 'script name'));
    add(
        'script-publisher',
        0,
        textIssue(metadata.publisher_label, 1, 200, 'publisher or author'),
    );
    add('script-file', 0, draft.file ? null : 'Choose the PowerShell script.');
    add(
        'script-runtime',
        0,
        draft.runtime ? null : 'Choose the runtime manifest.',
    );
    const seen = new Set<string>();
    metadata.parameter_schema.forEach((p, i) => {
        const key = p.name.toLowerCase();
        add(
            'parameter-name-' + i,
            1,
            p.name.trim() === ''
                ? 'Name this input.'
                : !scriptParameterName.test(p.name)
                  ? 'Start with a letter and use only letters, numbers and underscores (up to 32 characters).'
                  : reservedScriptParameterNames.has(key)
                    ? `“${p.name}” is reserved by PowerShell. Choose another name.`
                    : seen.has(key)
                      ? 'Another input already uses this name.'
                      : null,
        );
        seen.add(key);
        if (p.type === 'integer') {
            const { minimum, maximum } = p.constraints;
            const lowest = -Number.MAX_SAFE_INTEGER;
            add(
                'parameter-min-' + i,
                1,
                whole(minimum, lowest, Number.MAX_SAFE_INTEGER)
                    ? null
                    : 'Enter a whole number.',
            );
            add(
                'parameter-max-' + i,
                1,
                !whole(maximum, lowest, Number.MAX_SAFE_INTEGER)
                    ? 'Enter a whole number.'
                    : whole(minimum, lowest, Number.MAX_SAFE_INTEGER) &&
                        maximum < minimum
                      ? 'The maximum must be the same as or more than the minimum.'
                      : null,
            );
        } else if (p.type === 'string') {
            const { min_length: low, max_length: high } = p.constraints;
            add(
                'parameter-min-' + i,
                1,
                whole(low, 0, 1024)
                    ? null
                    : 'Enter a whole number from 0 to 1,024.',
            );
            add(
                'parameter-max-' + i,
                1,
                !whole(high, 0, 1024)
                    ? 'Enter a whole number from 0 to 1,024.'
                    : whole(low, 0, 1024) && high < low
                      ? 'The maximum must be the same as or more than the minimum.'
                      : null,
            );
        } else if (p.type === 'enum') {
            const values = scriptLines(p.constraints.values.join('\n'));
            add(
                'parameter-options-' + i,
                1,
                values.length === 0
                    ? 'Add at least one choice.'
                    : values.length > 32
                      ? 'Use 32 choices or fewer.'
                      : (values
                            .map((x) => textIssue(x, 1, 1024, 'choice'))
                            .find(Boolean) ??
                        (new Set(values).size !== values.length
                            ? 'Each choice must be different.'
                            : null)),
            );
        }
    });
    const policy = metadata.execution_policy;
    add(
        'script-time',
        1,
        whole(policy.runtime_seconds, 1, 900)
            ? null
            : 'Use a time limit from 1 to 900 seconds.',
    );
    add(
        'script-output',
        1,
        whole(policy.output_bytes, 0, 65536)
            ? null
            : `Use a whole number from 0 to ${count(65536)} bytes.`,
    );
    add(
        'script-retention',
        1,
        whole(policy.output_retention_hours, 1, 168)
            ? null
            : 'Keep output for 1 to 168 hours.',
    );
    const codes = draft.exitCodes.split(',').map((x) => x.trim());
    add(
        'script-exit-codes',
        1,
        codes.some((x) => !/^(0|[1-9][0-9]*)$/.test(x))
            ? 'Enter whole numbers separated by commas, such as 0, 3010.'
            : codes.length > 16
              ? 'List up to 16 success exit codes.'
              : codes.some((x) => Number(x) > 4294967295)
                ? `Each exit code must be ${count(4294967295)} or less.`
                : new Set(codes.map(Number)).size !== codes.length
                  ? 'List each exit code once.'
                  : null,
    );
    add('script-children', 2, listIssue(draft.children, 'programs'));
    add(
        'script-network',
        2,
        listIssue(draft.destinations, 'network destinations'),
    );
    add(
        'script-compensation',
        2,
        textIssue(review.compensation, 1, 1000, 'recovery plan'),
    );
    add(
        'script-acknowledged',
        2,
        draft.acknowledged
            ? null
            : 'Tick this to confirm the script leaves no background work running and loads no other code.',
    );
    add('script-upload-reason', 3, textIssue(draft.reason, 10, 500, 'reason'));
    return issues;
}
/** Exit codes as typed ("0, 3010") once scriptUploadIssues has passed them. */
export const scriptExitCodes = (value: string) =>
    value.split(',').map((x) => Number(x.trim()));
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
