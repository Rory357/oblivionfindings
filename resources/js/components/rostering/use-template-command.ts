import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type {
    TemplateClientOption,
    TemplateServiceContextOption,
    TemplateStaffOption,
} from './template-dialogs';
import type { RosterTemplateRow, TemplateCapabilities } from './templates-pane';

export type TemplateOptions = {
    clients: TemplateClientOption[];
    staff: TemplateStaffOption[];
    serviceContexts: TemplateServiceContextOption[];
};
export type TemplateValues = {
    name: string;
    description: string | null;
    template_type: string;
    is_active: boolean;
    template_shifts: Array<{
        client_id: number | null;
        user_id: number | null;
        service_context_id: number | null;
        day_of_week: number;
        start_time: string;
        end_time: string;
        shift_type: string;
        is_sleepover: boolean;
        is_on_call: boolean;
        is_lone_worker: boolean;
        expected_break_minutes: number | null;
        required_skills: string[];
        location: string | null;
        notes: string | null;
    }>;
};
export type TemplateAction = 'create' | 'update' | 'delete' | 'duplicate';
export type TemplateSource = { template_id: number; source_revision: string };
export type TemplateIntent = {
    action: TemplateAction;
    source: TemplateSource | null;
    values: TemplateValues | null;
    rowCount: number;
};
export type TemplateResult = {
    action: TemplateAction;
    templateId: number;
    copyId: number | null;
    outcome: 'saved' | 'unchanged' | 'deleted' | 'copied';
    resultRevision: string | null;
    rowCount: number;
};
export type TemplateLibraryData = {
    rosterTemplates: RosterTemplateRow[];
    templateCapabilities: TemplateCapabilities;
    templateOptions: TemplateOptions;
    workerTimezone: string;
};
type Notice = {
    kind: 'confirmed' | 'unknown' | 'read' | 'error';
    message: string;
};
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const revision = (value: unknown): value is string =>
    typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const caps = [
    'can_view',
    'can_create',
    'can_edit',
    'can_duplicate',
    'can_delete',
    'can_apply',
] as const;
export const TEMPLATE_READ_KEYS = [
    'rosterTemplates',
    'templateCapabilities',
    'templateOptions',
    'workerTimezone',
    'auth',
];
export const UNKNOWN_TEMPLATE =
    'We could not confirm the result. It may already have saved. Reload the library and check the current templates before another action.';
// Match the installed Laravel Str::trim boundary, including its invisible code points.
// The shared PHP/TypeScript hash vector pins normalization without changing stored semantics.
const trimCodes = new Set([
    0, 0x0009, 0x0020, 0x00a0, 0x00ad, 0x034f, 0x061c, 0x115f, 0x1160, 0x17b4,
    0x17b5, 0x180e, 0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006,
    0x2007, 0x2008, 0x2009, 0x200a, 0x200b, 0x200c, 0x200d, 0x200e, 0x200f,
    0x202f, 0x205f, 0x2060, 0x2061, 0x2062, 0x2063, 0x2064, 0x2065, 0x206a,
    0x206b, 0x206c, 0x206d, 0x206e, 0x206f, 0x3000, 0x2800, 0x3164, 0xfeff,
    0xffa0, 0x1d159, 0x1d173, 0x1d174, 0x1d175, 0x1d176, 0x1d177, 0x1d178,
    0x1d179, 0x1d17a, 0xe0020,
]);
const edgeSpace = (character: string) =>
    /^\p{White_Space}$/u.test(character) ||
    trimCodes.has(character.codePointAt(0)!);
const trim = (value: string) => {
    const characters = Array.from(value);
    let start = 0,
        end = characters.length;
    while (start < end && edgeSpace(characters[start])) start++;
    while (end > start && edgeSpace(characters[end - 1])) end--;
    return characters.slice(start, end).join('');
};
const text = (value: string | null, falsey = false) => {
    const clean = value === null ? null : trim(value);
    return clean === '' || (falsey && clean === '0') ? null : clean;
};
export function normalizeTemplateValues(
    values: TemplateValues,
): TemplateValues {
    return {
        name: trim(values.name),
        description: text(values.description),
        template_type: trim(values.template_type),
        is_active: values.is_active,
        template_shifts: values.template_shifts.map((row) => ({
            client_id: row.client_id,
            user_id: row.user_id,
            service_context_id: row.service_context_id,
            day_of_week: row.day_of_week,
            start_time: trim(row.start_time),
            end_time: trim(row.end_time),
            shift_type: trim(row.shift_type),
            is_sleepover:
                trim(row.shift_type) === 'sleepover' || row.is_sleepover,
            is_on_call: trim(row.shift_type) === 'on_call' || row.is_on_call,
            is_lone_worker: row.is_lone_worker,
            expected_break_minutes: row.expected_break_minutes,
            required_skills: row.required_skills
                .map(trim)
                .filter((skill) => skill !== '' && skill !== '0'),
            location: text(row.location, true),
            notes: text(row.notes, true),
        })),
    };
}
export async function templateHash(intent: TemplateIntent) {
    const bytes = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(
            JSON.stringify({
                action: intent.action,
                template_id: intent.source?.template_id ?? null,
                expected_source: intent.source
                    ? {
                          template_id: intent.source.template_id,
                          source_revision: intent.source.source_revision,
                      }
                    : null,
                values: intent.values
                    ? normalizeTemplateValues(intent.values)
                    : null,
            }),
        ),
    );
    return Array.from(new Uint8Array(bytes), (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
}
function sameActorProps(page: unknown, actorId: number, allowErrors = false) {
    const props = object(object(page).props);
    return positive(actorId) &&
        object(object(props.auth).user).id === actorId &&
        (allowErrors ||
            (!Object.keys(object(props.errors)).length &&
                !object(props.flash).error))
        ? props
        : null;
}
export function currentTemplateLibrary(
    page: unknown,
    actorId: number,
    allowErrors = false,
): TemplateLibraryData | null {
    const props = sameActorProps(page, actorId, allowErrors);
    if (
        !props ||
        !Array.isArray(props.rosterTemplates) ||
        !caps.every(
            (key) =>
                typeof object(props.templateCapabilities)[key] === 'boolean',
        ) ||
        typeof props.workerTimezone !== 'string' ||
        !props.workerTimezone
    )
        return null;
    const options = object(props.templateOptions);
    if (
        !['clients', 'staff', 'serviceContexts'].every(
            (key) =>
                Array.isArray(options[key]) &&
                (options[key] as unknown[]).every((item) =>
                    positive(object(item).id),
                ),
        )
    )
        return null;
    if (
        !props.rosterTemplates.every((value) => {
            const row = object(value),
                permissions = object(row.capabilities),
                urls = object(row.urls);
            return (
                positive(row.id) &&
                revision(row.source_revision) &&
                typeof row.name === 'string' &&
                typeof row.is_active === 'boolean' &&
                typeof row.template_type === 'string' &&
                Array.isArray(row.template_shifts) &&
                row.template_shifts_count === row.template_shifts.length &&
                caps.every((key) => typeof permissions[key] === 'boolean') &&
                ['update', 'delete', 'duplicate', 'apply'].every(
                    (key) =>
                        urls[key] === null || typeof urls[key] === 'string',
                )
            );
        }) ||
        new Set(props.rosterTemplates.map((value) => object(value).id)).size !==
            props.rosterTemplates.length
    )
        return null;
    return props as TemplateLibraryData;
}
export function templateReceipt(
    page: unknown,
    expected: TemplateIntent & { actorId: number; requestId: string },
    hash: string,
): TemplateResult | null {
    const props = sameActorProps(page, expected.actorId);
    if (!props) return null;
    const receipt = object(object(props.flash).roster_template_result),
        source = expected.source;
    if (
        receipt.version !== 1 ||
        receipt.scope !== 'library' ||
        receipt.actor_id !== expected.actorId ||
        receipt.request_id !== expected.requestId ||
        receipt.action !== expected.action ||
        receipt.values_hash !== hash ||
        !revision(hash) ||
        !positive(receipt.template_id) ||
        receipt.source_revision !== (source?.source_revision ?? null) ||
        (source
            ? receipt.template_id !== source.template_id ||
              object(receipt.expected_source).template_id !==
                  source.template_id ||
              object(receipt.expected_source).source_revision !==
                  source.source_revision ||
              Object.keys(object(receipt.expected_source)).length !== 2
            : receipt.expected_source !== null) ||
        receipt.template_shifts_count !== expected.rowCount ||
        typeof receipt.committed_at !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/.test(
            receipt.committed_at,
        ) ||
        !Number.isFinite(Date.parse(receipt.committed_at))
    )
        return null;
    const action = expected.action;
    if (action === 'delete') {
        if (
            receipt.outcome !== 'deleted' ||
            receipt.changed !== true ||
            receipt.result_revision !== null ||
            receipt.copy_id !== null
        )
            return null;
    } else {
        if (!revision(receipt.result_revision)) return null;
        if (action === 'duplicate') {
            if (
                receipt.outcome !== 'copied' ||
                receipt.changed !== true ||
                !positive(receipt.copy_id) ||
                receipt.copy_id === receipt.template_id
            )
                return null;
        } else if (
            receipt.copy_id !== null ||
            !(
                (receipt.outcome === 'saved' && receipt.changed === true) ||
                (action === 'update' &&
                    receipt.outcome === 'unchanged' &&
                    receipt.changed === false &&
                    receipt.result_revision === source?.source_revision)
            )
        )
            return null;
    }
    return {
        action,
        templateId: receipt.template_id,
        copyId: receipt.copy_id as number | null,
        outcome: receipt.outcome as TemplateResult['outcome'],
        resultRevision: receipt.result_revision as string | null,
        rowCount: expected.rowCount,
    };
}
const resultMessage = (result: TemplateResult) =>
    result.outcome === 'deleted'
        ? 'Template deleted from the library. Existing roster shifts and history are retained.'
        : result.outcome === 'copied'
          ? 'Template copied. Review the new pattern before applying it.'
          : result.outcome === 'unchanged'
            ? 'This template already matches these changes. No shift rows were replaced.'
            : 'Template saved. Apply it to a chosen week when you are ready.';

/** One coordinator owns library writes and current reads, including both dialog entry points. */
export function useTemplateCommand(
    actorId: number,
    library: TemplateLibraryData | null,
    week?: string,
) {
    const identity = useRef(actorId),
        epoch = useRef(0),
        alive = useRef(true),
        pending = useRef(false),
        held = useRef(false),
        latest = useRef(library);
    useLayoutEffect(() => {
        latest.current = library;
    }, [library]);
    const detachInvalid = useRef<(() => void) | null>(null);
    const [activity, setActivity] = useState<'command' | 'read' | null>(null),
        [notice, setNotice] = useState<Notice | null>(null),
        [needsRead, setNeedsRead] = useState(false);
    useLayoutEffect(() => {
        if (identity.current === actorId) return;
        detachInvalid.current?.();
        detachInvalid.current = null;
        identity.current = actorId;
        epoch.current++;
        pending.current = false;
        held.current = true;
        setActivity(null);
        setNeedsRead(true);
        setNotice({ kind: 'unknown', message: UNKNOWN_TEMPLATE });
    }, [actorId]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            // Invalidate callback generations, not a DOM ref.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            epoch.current++;
            detachInvalid.current?.();
            detachInvalid.current = null;
        };
    }, []);
    const submit = async (
        intent: TemplateIntent,
        onConfirmed?: (result: TemplateResult) => void,
        onErrors?: (errors: Record<string, string>) => void,
    ) => {
        if (
            !alive.current ||
            pending.current ||
            held.current ||
            !positive(actorId) ||
            !latest.current
        )
            return;
        const data = latest.current;
        const row = intent.source
            ? data.rosterTemplates.find(
                  (item) =>
                      item.id === intent.source?.template_id &&
                      item.source_revision === intent.source.source_revision,
              )
            : null;
        const capability =
            intent.action === 'create'
                ? 'can_create'
                : intent.action === 'update'
                  ? 'can_edit'
                  : intent.action === 'duplicate'
                    ? 'can_duplicate'
                    : 'can_delete';
        const url =
            intent.action === 'create'
                ? '/operations/rostering/templates'
                : row?.urls?.[intent.action];
        if (
            !data.templateCapabilities[capability] ||
            (intent.action !== 'create' &&
                (!row?.capabilities?.[capability] || !url)) ||
            (intent.action === 'create') !== (intent.source === null) ||
            (intent.action === 'create' || intent.action === 'update') !==
                (intent.values !== null)
        ) {
            held.current = true;
            setNeedsRead(true);
            setNotice({
                kind: 'unknown',
                message:
                    'This template or your access has changed. Reload the current library before continuing.',
            });
            return;
        }
        pending.current = true;
        setActivity('command');
        setNotice(null);
        const token = ++epoch.current,
            current = () =>
                alive.current &&
                identity.current === actorId &&
                epoch.current === token;
        let expected: TemplateIntent & { actorId: number; requestId: string },
            hash: string;
        try {
            expected = {
                ...structuredClone(intent),
                actorId,
                requestId: crypto.randomUUID(),
            };
            hash = await templateHash(expected);
        } catch {
            if (current()) {
                pending.current = false;
                setActivity(null);
                setNotice({
                    kind: 'error',
                    message:
                        'The save could not be prepared. Nothing was sent. Try again.',
                });
            }
            return;
        }
        if (!current()) return;
        let finished = false,
            result: TemplateResult | null = null,
            fresh = false,
            errors: Record<string, string> | null = null;
        let detach: (() => void) | null = null;
        const finish = () => {
            detach?.();
            if (detachInvalid.current === detach) detachInvalid.current = null;
            detach = null;
            if (!current() || finished) return;
            finished = true;
            pending.current = false;
            held.current = !fresh;
            setActivity(null);
            setNeedsRead(!fresh);
            setNotice(
                result
                    ? {
                          kind: 'confirmed',
                          message:
                              resultMessage(result) +
                              (fresh
                                  ? ''
                                  : ' Reload the library before another action.'),
                      }
                    : errors
                      ? {
                            kind: 'error',
                            message:
                                'Review the highlighted fields. Reload the current library before saving again; your entries are retained.',
                        }
                      : { kind: 'unknown', message: UNKNOWN_TEMPLATE },
            );
            if (errors) onErrors?.(errors);
            if (result) onConfirmed?.(result);
        };
        try {
            detach = router.on('invalid', (event) => {
                if (!current() || finished) return;
                const config = event.detail.response.config,
                    headers = config.headers;
                let payload: unknown = config.data;
                try {
                    if (typeof payload === 'string')
                        payload = JSON.parse(payload);
                } catch {
                    return;
                }
                if (
                    (headers?.['X-Roster-Template-Result'] ??
                        headers?.['x-roster-template-result']) !==
                        'committed-v1' ||
                    object(payload).request_id !== expected.requestId
                )
                    return;
                event.preventDefault();
                finish();
            });
            detachInvalid.current = detach;
            router.visit(url!, {
                method:
                    intent.action === 'update'
                        ? 'put'
                        : intent.action === 'delete'
                          ? 'delete'
                          : 'post',
                data: {
                    ...(expected.values ?? {}),
                    request_id: expected.requestId,
                    ...(week ? { week } : {}),
                    ...(expected.source
                        ? { expected_source: expected.source }
                        : {}),
                },
                headers: { 'X-Roster-Template-Result': 'committed-v1' },
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!current() || finished || result) return;
                    result = templateReceipt(page, expected, hash);
                    const read = currentTemplateLibrary(page, actorId);
                    const savedRow = read?.rosterTemplates.find(
                        (row) =>
                            row.id === (result?.copyId ?? result?.templateId),
                    );
                    fresh = Boolean(
                        result &&
                        read &&
                        (result.action === 'delete'
                            ? !savedRow
                            : savedRow?.source_revision ===
                                  result.resultRevision &&
                              savedRow.template_shifts_count ===
                                  result.rowCount),
                    );
                },
                onError: (serverErrors) => {
                    if (current() && !finished) errors = serverErrors;
                    finish();
                },
                onCancel: finish,
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    const refresh = (onRead?: (data: TemplateLibraryData) => void) => {
        if (!alive.current || pending.current || !positive(actorId)) return;
        pending.current = true;
        setActivity('read');
        const token = ++epoch.current,
            current = () =>
                alive.current &&
                identity.current === actorId &&
                epoch.current === token;
        let finished = false,
            fresh: TemplateLibraryData | null = null;
        const finish = () => {
            if (!current() || finished) return;
            finished = true;
            pending.current = false;
            held.current = !fresh;
            setActivity(null);
            setNeedsRead(!fresh);
            setNotice(
                fresh
                    ? {
                          kind: 'read',
                          message:
                              'Current templates loaded. Review the saved pattern before continuing.',
                      }
                    : {
                          kind: 'unknown',
                          message:
                              'The library could not be refreshed. Try reloading again.',
                      },
            );
            if (fresh) {
                latest.current = fresh;
                onRead?.(fresh);
            }
        };
        try {
            router.reload({
                only: TEMPLATE_READ_KEYS,
                preserveScroll: true,
                onSuccess: (page) => {
                    if (current() && !finished)
                        fresh = currentTemplateLibrary(page, actorId);
                },
                onFinish: finish,
                onError: finish,
                onCancel: finish,
            });
        } catch {
            finish();
        }
    };
    return {
        submit,
        refresh,
        activity,
        notice,
        needsRead,
        busy: activity !== null,
        blocked:
            activity !== null || needsRead || !positive(actorId) || !library,
        isBusy: () => pending.current,
    };
}
export type TemplateCommand = ReturnType<typeof useTemplateCommand>;
