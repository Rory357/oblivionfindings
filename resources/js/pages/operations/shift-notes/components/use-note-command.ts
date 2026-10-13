import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type NoteValues = {
    type: string;
    body: string;
    is_flagged: boolean;
    flagged_reason: string | null;
    is_private: boolean;
};
export type NoteReceipt = {
    action: 'create' | 'update' | 'flag' | 'review';
    actor_id: number;
    note_id: number;
    shift_id: number;
    client_id: number;
    changed: boolean;
    values_hash: string;
    is_flagged: boolean;
    is_private: boolean;
    edited_at: string | null;
    edited_by: number | null;
    reviewed_at: string | null;
    reviewed_by: number | null;
};
export type NoteExpectation = {
    action: NoteReceipt['action'];
    actorId: number;
    noteId?: number;
    shiftId: number;
    clientId: number;
    values: NoteValues;
    priorReview?: { at: string | null; by: number | null };
};
export type NoteOutcome =
    | { status: 'confirmed'; receipt: NoteReceipt }
    | {
          status: 'rejected' | 'unknown';
          message: string;
          errors?: Record<string, string>;
      };
export const UNKNOWN_NOTE_RESULT =
    'The save result could not be confirmed. It may already have saved. Your entries are kept here. Check the current records before starting another attempt.';

// Matches the installed Laravel Str::trim edge characters; never alters inner text.
const edges = String.raw`[\s\u0000\u0085\u00ad\u034f\u061c\u115f\u1160\u17b4\u17b5\u180e\u200b-\u200f\u2060-\u2065\u206a-\u206f\u2800\u3164\uffa0\u{1d159}\u{1d173}-\u{1d17a}\u{e0020}]`;
export function normalizeNoteText(value: string): string {
    // eslint-disable-next-line no-misleading-character-class -- Each Unicode code point is an independent Laravel edge-trim character.
    return value.replace(new RegExp(`^${edges}+|${edges}+$`, 'gu'), '');
}
export function normalizedNoteValues(values: NoteValues): NoteValues {
    return {
        type: normalizeNoteText(values.type),
        body: normalizeNoteText(values.body),
        is_flagged: values.is_flagged,
        flagged_reason: values.is_flagged
            ? normalizeNoteText(values.flagged_reason ?? '') || null
            : null,
        is_private: values.is_private,
    };
}
export async function noteValuesHash(values: NoteValues): Promise<string> {
    // Explicit key order matches the persisted server receipt, including null.
    const json = JSON.stringify({
        type: values.type,
        body: values.body,
        is_flagged: values.is_flagged,
        flagged_reason: values.flagged_reason,
        is_private: values.is_private,
    });
    const hash = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(json),
    );
    return Array.from(new Uint8Array(hash), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}
const positive = (value: unknown) =>
    Number.isSafeInteger(value) && Number(value) > 0;
const instant = (value: unknown): value is string =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));
const provenance = (at: unknown, by: unknown) =>
    (at === null && by === null) || (instant(at) && positive(by));
export function noteReceipt(
    flash: unknown,
    expected: NoteExpectation,
    hash: string,
): NoteOutcome {
    const data = flash as {
        error?: unknown;
        warning?: unknown;
        shift_note_result?: Partial<NoteReceipt>;
    } | null;
    const r = data?.shift_note_result;
    if (
        !data?.error &&
        !data?.warning &&
        r &&
        r.action === expected.action &&
        positive(expected.actorId) &&
        r.actor_id === expected.actorId &&
        positive(r.note_id) &&
        (expected.action === 'create'
            ? expected.noteId === undefined
            : positive(expected.noteId) && r.note_id === expected.noteId) &&
        r.shift_id === expected.shiftId &&
        r.client_id === expected.clientId &&
        positive(r.shift_id) &&
        positive(r.client_id) &&
        typeof r.changed === 'boolean' &&
        r.values_hash === hash &&
        /^[a-f0-9]{64}$/.test(hash) &&
        r.is_flagged === expected.values.is_flagged &&
        r.is_private === expected.values.is_private &&
        provenance(r.edited_at, r.edited_by) &&
        (provenance(r.reviewed_at, r.reviewed_by) ||
            (instant(r.reviewed_at) &&
                r.reviewed_by === null &&
                expected.priorReview?.at === r.reviewed_at &&
                expected.priorReview.by === null)) &&
        (!expected.priorReview?.at ||
            (r.reviewed_at === expected.priorReview.at &&
                r.reviewed_by === expected.priorReview.by)) &&
        (expected.action !== 'create' ||
            (r.changed && r.edited_at === null && r.reviewed_at === null)) &&
        (expected.action !== 'update' ||
            (r.changed &&
                instant(r.edited_at) &&
                r.edited_by === expected.actorId)) &&
        (expected.action !== 'flag' || r.changed) &&
        (expected.action !== 'review' ||
            (instant(r.reviewed_at) &&
                (positive(r.reviewed_by) ||
                    (!r.changed &&
                        expected.priorReview?.at === r.reviewed_at &&
                        expected.priorReview.by === null)) &&
                (!r.changed || r.reviewed_by === expected.actorId)))
    )
        return { status: 'confirmed', receipt: r as NoteReceipt };
    return { status: 'unknown', message: UNKNOWN_NOTE_RESULT };
}

/** One mounted intent owns one write. Missing receipts and interrupted writes never replay. */
export function useNoteCommand(context: string) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<NoteOutcome | null>(null);
    const busy = useRef(false);
    const held = useRef(false);
    const mounted = useRef(true);
    const currentContext = useRef(context);
    const generation = useRef(0);
    const attempted = useRef(false);
    useLayoutEffect(() => {
        if (currentContext.current === context) return;
        currentContext.current = context;
        generation.current += 1;
        if (attempted.current) {
            held.current = true;
            setOutcome({
                status: 'unknown',
                message:
                    'The person or note shown has changed. Check the original record before taking another action.',
            });
        }
    }, [context]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const submit = async (
        method: 'post' | 'put' | 'patch',
        url: string,
        payload: Parameters<typeof router.post>[1],
        expected: NoteExpectation,
    ) => {
        if (!mounted.current || busy.current || held.current) return;
        busy.current = true;
        attempted.current = true;
        setPending(true);
        setOutcome(null);
        const submittedContext = context;
        const submittedGeneration = generation.current;
        let result: NoteOutcome | null = null;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            busy.current = false;
            const next: NoteOutcome =
                submittedContext !== currentContext.current ||
                submittedGeneration !== generation.current
                    ? {
                          status: 'unknown',
                          message:
                              'The person or note shown has changed. Check the original record before taking another action.',
                      }
                    : (result ?? {
                          status: 'unknown',
                          message: UNKNOWN_NOTE_RESULT,
                      });
            held.current = next.status !== 'rejected';
            if (mounted.current) {
                setOutcome(next);
                setPending(false);
            }
        };
        let hash: string;
        try {
            hash = await noteValuesHash(expected.values);
        } catch {
            result = {
                status: 'rejected',
                message:
                    'Save confirmation is unavailable in this browser. No request was sent. Keep your draft and use a secure browser connection.',
            };
            finish();
            return;
        }
        if (
            !mounted.current ||
            submittedContext !== currentContext.current ||
            submittedGeneration !== generation.current
        ) {
            finish();
            return;
        }
        try {
            router[method](url, payload, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!finished && !result)
                        result = noteReceipt(page.props.flash, expected, hash);
                },
                onError: (errors) => {
                    if (finished || result) return;
                    const entries = Object.entries(errors);
                    const validation =
                        entries.length > 0 &&
                        entries.every(
                            ([key, value]) =>
                                [
                                    'shift_id',
                                    'type',
                                    'body',
                                    'is_flagged',
                                    'flagged_reason',
                                    'is_private',
                                ].includes(key) &&
                                typeof value === 'string' &&
                                value.length > 0,
                        );
                    result = validation
                        ? {
                              status: 'rejected',
                              message:
                                  'Review the highlighted fields. Your entries have been kept.',
                              errors: errors as Record<string, string>,
                          }
                        : { status: 'unknown', message: UNKNOWN_NOTE_RESULT };
                },
                onCancel: () => {
                    if (!finished && !result)
                        result = {
                            status: 'unknown',
                            message: UNKNOWN_NOTE_RESULT,
                        };
                },
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    return { submit, pending, outcome, busy, held };
}
