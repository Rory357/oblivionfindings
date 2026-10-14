import { csrf } from './remote-viewer';

export type DiagnosticGroup = 'system' | 'software' | 'services';
export const diagnosticGroups: DiagnosticGroup[] = [
    'system',
    'software',
    'services',
];
export type ObliScope = {
    actorId: number;
    deviceId: number;
    ticketId: number | null;
};
export type ObliOptions = {
    actor_id: number;
    device_id: number;
    ticket_id: number | null;
    version: number;
    enabled: boolean;
    provider: 'openai' | 'anthropic';
    model: string;
    chat_retention_days: number;
    diagnostic_categories: DiagnosticGroup[];
    mode: 'read_suggest';
    can_preview: boolean;
    can_assist: boolean;
};
export type DiagnosticSnapshot = {
    schema: 1;
    observed_at: string;
    system?: {
        windows_version: string;
        architecture: string;
        memory_total_bytes: number | null;
        memory_available_bytes: number | null;
        uptime_seconds: number | null;
        cpu_percent: number | null;
        disks: { name: string; total_bytes: number; free_bytes: number }[];
    };
    software?: { name: string; version: string }[];
    services?: { name: string; state: string }[];
};
export type DiagnosticPreview = {
    actor_id: number;
    device_id: number;
    ticket_id: number | null;
    snapshot_version: string;
    observed_at: string;
    received_at: string;
    fresh_until: string;
    groups: DiagnosticGroup[];
    snapshot: DiagnosticSnapshot;
    completeness: Partial<
        Record<
            DiagnosticGroup,
            { complete: boolean; missing_fields?: string[] }
        >
    >;
};
export type ComputerReference = {
    snapshot_version: string;
    groups: DiagnosticGroup[];
    source_request_uuid?: string;
};
export type TechnicianMessage = {
    role: 'user' | 'assistant';
    content: string;
    computer_diagnostics?: ComputerReference;
};
export type ChatCommand = {
    request_uuid: string;
    expected_actor_id: number;
    configuration_version: number;
    ticket_id: number | null;
    messages: TechnicianMessage[];
};
export type ChatResult = {
    request_uuid: string;
    status: 'pending' | 'completed' | 'unconfirmed';
    text: string | null;
};
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const hex = /^[a-f0-9]{64}$/;
const plain = (v: unknown, limit: number): v is string =>
    typeof v === 'string' &&
    v.length <= limit &&
    !/[\p{Cc}&&[^\t\n\r]]/v.test(v);
const keys = (value: Record<string, unknown>, allowed: string[]) =>
    Object.keys(value).length === allowed.length &&
    Object.keys(value).every((key) => allowed.includes(key));
const object = (v: unknown): Record<string, unknown> => {
    if (!v || typeof v !== 'object' || Array.isArray(v))
        throw Error('Unconfirmed Obli response');
    return v as Record<string, unknown>;
};
function scopeReply(value: unknown, scope: ObliScope) {
    const r = object(value);
    if (
        r.actor_id !== scope.actorId ||
        r.device_id !== scope.deviceId ||
        r.ticket_id !== scope.ticketId
    )
        throw new TechnicianObliError(403, 'scope_changed');
    return r;
}
function groups(value: unknown): value is DiagnosticGroup[] {
    return (
        Array.isArray(value) &&
        value.length <= 3 &&
        new Set(value).size === value.length &&
        value.every((v) => diagnosticGroups.includes(v))
    );
}
export function obliOptions(value: unknown, scope: ObliScope): ObliOptions {
    const r = scopeReply(value, scope);
    if (
        !Number.isSafeInteger(r.version) ||
        Number(r.version) < 0 ||
        typeof r.enabled !== 'boolean' ||
        !['openai', 'anthropic'].includes(String(r.provider)) ||
        !plain(r.model, 120) ||
        !Number.isInteger(r.chat_retention_days) ||
        Number(r.chat_retention_days) < 1 ||
        Number(r.chat_retention_days) > 90 ||
        !groups(r.diagnostic_categories) ||
        r.mode !== 'read_suggest' ||
        typeof r.can_preview !== 'boolean' ||
        typeof r.can_assist !== 'boolean'
    )
        throw Error('Unconfirmed Obli settings');
    return r as ObliOptions;
}
export function diagnosticPreview(
    value: unknown,
    scope: ObliScope,
): DiagnosticPreview {
    const r = scopeReply(value, scope);
    if (
        !plain(r.snapshot_version, 64) ||
        !hex.test(r.snapshot_version) ||
        !groups(r.groups) ||
        r.groups.length === 0 ||
        !['observed_at', 'received_at', 'fresh_until'].every(
            (k) =>
                typeof r[k] === 'string' &&
                Number.isFinite(Date.parse(r[k] as string)),
        )
    )
        throw Error('Unconfirmed diagnostic preview');
    const snapshot = object(r.snapshot),
        completeness = object(r.completeness);
    if (!keys(completeness, r.groups)) throw Error('Unconfirmed completeness');
    if (
        snapshot.schema !== 1 ||
        snapshot.observed_at !== r.observed_at ||
        new TextEncoder().encode(JSON.stringify(snapshot)).length > 4096 ||
        Object.keys(snapshot).some(
            (k) =>
                !['schema', 'observed_at', ...(r.groups as string[])].includes(
                    k,
                ),
        )
    )
        throw Error('Unconfirmed diagnostic snapshot');
    for (const group of r.groups) {
        const status = object(completeness[group]);
        if (
            typeof status.complete !== 'boolean' ||
            !keys(
                status,
                group === 'system'
                    ? ['complete', 'missing_fields']
                    : ['complete'],
            ) ||
            !(group in snapshot)
        )
            throw Error('Unconfirmed diagnostic group');
        if (group === 'system') {
            const system = object(snapshot.system);
            const fields = [
                'windows_version',
                'architecture',
                'memory_total_bytes',
                'memory_available_bytes',
                'uptime_seconds',
                'cpu_percent',
                'disks',
            ];
            if (
                !keys(system, fields) ||
                !Array.isArray(status.missing_fields) ||
                new Set(status.missing_fields).size !==
                    status.missing_fields.length ||
                status.missing_fields.some((v) => !fields.includes(v)) ||
                status.complete !== (status.missing_fields.length === 0)
            )
                throw Error('Unconfirmed system completeness');
            if (
                !plain(system.windows_version, 40) ||
                !/^(unknown|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,8}(\.[0-9]{1,8})?)$/.test(
                    system.windows_version,
                ) ||
                !['amd64', 'arm64', 'unknown'].includes(
                    String(system.architecture),
                ) ||
                ![
                    'memory_total_bytes',
                    'memory_available_bytes',
                    'uptime_seconds',
                    'cpu_percent',
                ].every(
                    (k) =>
                        system[k] === null ||
                        (typeof system[k] === 'number' &&
                            Number.isFinite(system[k]) &&
                            Number(system[k]) >= 0),
                ) ||
                (system.cpu_percent !== null &&
                    Number(system.cpu_percent) > 100) ||
                (system.uptime_seconds !== null &&
                    (!Number.isSafeInteger(system.uptime_seconds) ||
                        Number(system.uptime_seconds) > 3155760000)) ||
                (system.memory_total_bytes !== null &&
                    (!Number.isSafeInteger(system.memory_total_bytes) ||
                        Number(system.memory_total_bytes) < 1)) ||
                (system.memory_available_bytes !== null &&
                    (!Number.isSafeInteger(system.memory_available_bytes) ||
                        system.memory_total_bytes === null ||
                        Number(system.memory_available_bytes) >
                            Number(system.memory_total_bytes))) ||
                !Array.isArray(system.disks) ||
                system.disks.length > 8 ||
                new Set(system.disks.map((d) => d?.name)).size !==
                    system.disks.length ||
                system.disks.some(
                    (d) =>
                        !d ||
                        !keys(object(d), [
                            'name',
                            'total_bytes',
                            'free_bytes',
                        ]) ||
                        !/^[A-Z]:$/.test(d.name) ||
                        !Number.isSafeInteger(d.total_bytes) ||
                        !Number.isSafeInteger(d.free_bytes) ||
                        d.total_bytes < 1 ||
                        d.free_bytes < 0 ||
                        d.free_bytes > d.total_bytes,
                )
            )
                throw Error('Unconfirmed system snapshot');
        } else {
            const rows = snapshot[group];
            const names =
                group === 'software'
                    ? [
                          'microsoft_edge',
                          'google_chrome',
                          'microsoft_teams',
                          'teams_installer',
                      ]
                    : [
                          'print_spooler',
                          'windows_update',
                          'background_transfer',
                          'windows_defender',
                          'windows_firewall',
                      ];
            if (
                !Array.isArray(rows) ||
                rows.length > names.length ||
                new Set(rows.map((row) => row?.name)).size !== rows.length ||
                rows.some(
                    (row) =>
                        !row ||
                        !keys(
                            object(row),
                            group === 'software'
                                ? ['name', 'version']
                                : ['name', 'state'],
                        ) ||
                        !names.includes(row.name) ||
                        (group === 'software'
                            ? !/^[0-9]{1,9}(\.[0-9]{1,9}){1,5}$/.test(
                                  row.version,
                              )
                            : !['running', 'stopped', 'unknown'].includes(
                                  row.state,
                              )),
                )
            )
                throw Error('Unconfirmed diagnostic entries');
        }
    }
    return r as DiagnosticPreview;
}
export function chatResult(value: unknown, requestId: string): ChatResult {
    const r = object(value);
    if (
        r.request_uuid !== requestId ||
        !uuid.test(requestId) ||
        !['pending', 'completed', 'unconfirmed'].includes(String(r.status)) ||
        (r.status === 'completed'
            ? !plain(r.text, 65536) || !r.text.trim()
            : r.text !== null)
    )
        throw Error('Unconfirmed Obli reply');
    return r as ChatResult;
}
export class TechnicianObliError extends Error {
    constructor(
        public status: number,
        public code: string,
    ) {
        super('Obli request could not be confirmed');
    }
}
export async function technicianObliJSON(
    scope: ObliScope,
    action: 'options' | 'diagnostics' | 'chat' | `chat/${string}`,
    signal: AbortSignal,
    command?: ChatCommand,
    selected?: DiagnosticGroup[],
): Promise<unknown> {
    if (
        ![
            scope.actorId,
            scope.deviceId,
            ...(scope.ticketId === null ? [] : [scope.ticketId]),
        ].every((n) => Number.isSafeInteger(n) && n > 0) ||
        (!['options', 'diagnostics', 'chat'].includes(action) &&
            !(action.startsWith('chat/') && uuid.test(action.slice(5))))
    )
        throw Error('Invalid support context');
    if (
        (action === 'chat') !== Boolean(command) ||
        (command &&
            (command.expected_actor_id !== scope.actorId ||
                command.ticket_id !== scope.ticketId ||
                !uuid.test(command.request_uuid))) ||
        (selected &&
            (action !== 'diagnostics' ||
                !groups(selected) ||
                selected.length === 0))
    )
        throw Error('Invalid support request');
    const query = new URLSearchParams();
    if (!command) query.set('expected_actor_id', String(scope.actorId));
    if (!command && scope.ticketId !== null)
        query.set('ticket_id', String(scope.ticketId));
    if (selected) for (const group of selected) query.append('groups[]', group);
    const path =
        '/it/remote-support/devices/' +
        scope.deviceId +
        '/obli/' +
        action +
        (query.size ? '?' + query : '');
    const controller = new AbortController(),
        cancel = () => controller.abort();
    signal.addEventListener('abort', cancel, { once: true });
    if (signal.aborted) cancel();
    const timer = setTimeout(cancel, command ? 55000 : 20000);
    try {
        const response = await fetch(path, {
            method: command ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                ...(command
                    ? { 'Content-Type': 'application/json', ...csrf() }
                    : {}),
            },
            body: command ? JSON.stringify(command) : undefined,
        });
        if (
            !response.headers
                .get('content-type')
                ?.includes('application/json') ||
            !response.body
        )
            throw new TechnicianObliError(response.status, 'unconfirmed');
        const reader = response.body.getReader(),
            chunks: Uint8Array[] = [];
        let length = 0;
        try {
            for (;;) {
                const part = await reader.read();
                if (part.done) break;
                length += part.value.length;
                if (length > 131072) {
                    await reader.cancel();
                    throw Error('Obli response too large');
                }
                chunks.push(part.value);
            }
        } finally {
            reader.releaseLock();
        }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) {
            bytes.set(chunk, offset);
            offset += chunk.length;
        }
        const value: unknown = JSON.parse(
            new TextDecoder('utf-8', { fatal: true }).decode(bytes),
        );
        if (!response.ok) {
            const r = object(value);
            throw new TechnicianObliError(
                response.status,
                typeof r.code === 'string' ? r.code : 'unconfirmed',
            );
        }
        return value;
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', cancel);
    }
}
