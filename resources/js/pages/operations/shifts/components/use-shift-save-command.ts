import type { FormDataConvertible, Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type ShiftSaveSource = {
    shift_id: number;
    client_id: number | null;
    site_id: number | null;
    user_id: number | null;
    service_context_id: number | null;
    shift_series_id: number | null;
    status: string;
};
export type ShiftSaveProjection = {
    client_id: number;
    service_context_id: number | null;
    user_id: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    notes: string | null;
    status: 'draft' | 'scheduled';
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_lone_worker: boolean;
    expected_break_minutes: number | null;
    coverage_roles: string[];
    required_licence_class: string | null;
    required_licence_endorsements: string[];
    tasks: Array<{
        id: number | null;
        label: string;
        scheduled_time: string | null;
    }> | null;
};
export type ShiftSaveReceipt = {
    action: 'create' | 'update';
    actor_id: number;
    shift_id: number;
    scope: 'single';
    source: ShiftSaveSource | null;
    client_id: number;
    site_id: number;
    user_id: number | null;
    service_context_id: number | null;
    status: 'draft' | 'scheduled';
    changed: boolean;
    outcome: 'saved' | 'unchanged';
    starts_at: string;
    ends_at: string;
    values_hash: string;
    assignment_warnings?: string[];
};
export type ShiftSaveExpectation = {
    actorId: number;
    source: ShiftSaveSource | null;
    siteId: number;
    values: ShiftSaveProjection;
};
export type ShiftEligibilityFeedback = {
    is_eligible?: boolean;
    is_allowed?: boolean;
    blocked_reasons?: string[];
    warning_reasons?: string[];
    overrideable_warnings?: Array<{
        rule: string;
        message: string;
        overrideable: boolean;
    }>;
};
export type ShiftSaveOutcome =
    | { status: 'confirmed'; receipt: ShiftSaveReceipt; warnings: string[] }
    | {
          status: 'rejected';
          message: string;
          errors: Record<string, string>;
          reason?: 'eligibility_warning' | 'override_reason_required';
          eligibility?: ShiftEligibilityFeedback;
      }
    | { status: 'unknown'; message: string };
export const UNKNOWN_SHIFT_SAVE =
    'The result could not be confirmed. This shift may already have saved. Your entries are kept here. Check the saved shift before another attempt.';
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const nullableId = (value: unknown) => value === null || positive(value);
const sourceKeys = [
    'shift_id',
    'client_id',
    'site_id',
    'user_id',
    'service_context_id',
    'shift_series_id',
    'status',
] as const;

/** This hash order is the backend's normalized submitted-intent contract. */
export async function shiftSaveHash(values: ShiftSaveProjection) {
    const ordered: ShiftSaveProjection = {
        client_id: values.client_id,
        service_context_id: values.service_context_id,
        user_id: values.user_id,
        starts_at: values.starts_at,
        ends_at: values.ends_at,
        location: values.location,
        notes: values.notes,
        status: values.status,
        shift_type: values.shift_type,
        is_sleepover: values.is_sleepover,
        is_on_call: values.is_on_call,
        is_lone_worker: values.is_lone_worker,
        expected_break_minutes: values.expected_break_minutes,
        coverage_roles: values.coverage_roles,
        required_licence_class: values.required_licence_class,
        required_licence_endorsements: values.required_licence_endorsements,
        tasks:
            values.tasks?.map((task) => ({
                id: task.id,
                label: task.label,
                scheduled_time: task.scheduled_time,
            })) ?? null,
    };
    const bytes = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(JSON.stringify(ordered)),
    );
    return Array.from(new Uint8Array(bytes), (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
}
export function shiftSaveReceipt(
    value: unknown,
    expected: ShiftSaveExpectation,
    hash: string,
): ShiftSaveOutcome {
    const flash = object(value),
        receipt = object(flash.shift_result);
    const old = object(receipt.source),
        source = expected.source;
    const action = source ? 'update' : 'create';
    const values = expected.values;
    const sourceMatches = source
        ? sourceKeys.every((key) => old[key] === source[key]) &&
          receipt.shift_id === source.shift_id
        : receipt.source === null;
    const bound =
        positive(expected.actorId) &&
        receipt.actor_id === expected.actorId &&
        receipt.action === action &&
        receipt.scope === 'single' &&
        sourceMatches &&
        /^[a-f0-9]{64}$/.test(hash) &&
        receipt.values_hash === hash;
    const feedback = object(flash.eligibility_result);
    const feedbackValid =
        ['blocked_reasons', 'warning_reasons'].every(
            (key) =>
                feedback[key] === undefined ||
                (Array.isArray(feedback[key]) &&
                    feedback[key].every((item) => typeof item === 'string')),
        ) &&
        (feedback.overrideable_warnings === undefined ||
            (Array.isArray(feedback.overrideable_warnings) &&
                feedback.overrideable_warnings.every((item) => {
                    const warning = object(item);
                    return (
                        typeof warning.rule === 'string' &&
                        typeof warning.message === 'string' &&
                        typeof warning.overrideable === 'boolean'
                    );
                }))) &&
        ['is_eligible', 'is_allowed'].every(
            (key) =>
                feedback[key] === undefined ||
                typeof feedback[key] === 'boolean',
        );
    // Only explicit current-command rejection evidence permits a warning retry.
    // Existing reservation cleanup may have committed; Shift/task values did not.

    if (
        bound &&
        (action === 'create'
            ? receipt.shift_id === null
            : positive(receipt.shift_id)) &&
        receipt.outcome === 'not_saved' &&
        receipt.changed === false &&
        (receipt.reason === 'eligibility_warning' ||
            receipt.reason === 'override_reason_required')
    )
        return {
            status: 'rejected',
            reason: receipt.reason,
            errors: {},
            eligibility:
                feedbackValid && Object.keys(feedback).length
                    ? (feedback as ShiftEligibilityFeedback)
                    : undefined,
            message:
                receipt.reason === 'override_reason_required'
                    ? 'The shift was not saved. Add a reason before confirming the eligibility override.'
                    : 'The shift was not saved. Review the current staff eligibility warnings. Your entries are kept here.',
        };
    // The current create receipt owns its warnings; old session flash and
    // eligibility previews cannot describe this committed assignment.
    const warnings = receipt.assignment_warnings;
    const validWarnings =
        action !== 'create' ||
        (Array.isArray(warnings) &&
            warnings.every((warning) => typeof warning === 'string'));
    if (
        !flash.error &&
        validWarnings &&
        bound &&
        positive(expected.siteId) &&
        positive(receipt.shift_id) &&
        receipt.actor_id === expected.actorId &&
        receipt.action === action &&
        receipt.scope === 'single' &&
        sourceMatches &&
        receipt.client_id === values.client_id &&
        receipt.site_id === expected.siteId &&
        receipt.user_id === values.user_id &&
        nullableId(receipt.service_context_id) &&
        (values.service_context_id === null ||
            receipt.service_context_id === values.service_context_id) &&
        receipt.status === values.status &&
        receipt.starts_at === values.starts_at &&
        receipt.ends_at === values.ends_at &&
        typeof receipt.changed === 'boolean' &&
        (action !== 'create' || receipt.changed) &&
        receipt.outcome === (receipt.changed ? 'saved' : 'unchanged') &&
        /^[a-f0-9]{64}$/.test(hash) &&
        receipt.values_hash === hash
    )
        return {
            status: 'confirmed',
            receipt: receipt as ShiftSaveReceipt,
            warnings: action === 'create' ? [...(warnings as string[])] : [],
        };
    return { status: 'unknown', message: UNKNOWN_SHIFT_SAVE };
}

/** One single-Shift command; an uncertain result cannot be replayed automatically. */
export function useShiftSaveCommand(context: string) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<ShiftSaveOutcome | null>(null);
    const busy = useRef(false),
        held = useRef(false),
        mounted = useRef(true);
    const identity = useRef(context),
        generation = useRef(0),
        attempted = useRef(false);
    useLayoutEffect(() => {
        if (identity.current === context) return;
        identity.current = context;
        generation.current += 1;
        if (attempted.current) {
            held.current = true;
            setOutcome({ status: 'unknown', message: UNKNOWN_SHIFT_SAVE });
        }
    }, [context]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const submit = async (
        input: ShiftSaveExpectation,
        payload: Record<string, FormDataConvertible>,
    ) => {
        if (!mounted.current || busy.current || held.current) return;
        busy.current = true;
        setPending(true);
        setOutcome(null);
        const sentContext = context,
            sentGeneration = generation.current;
        let expected: ShiftSaveExpectation;
        let sentPayload: Record<string, FormDataConvertible>;
        let hash: string;
        try {
            expected = structuredClone(input);
            sentPayload = structuredClone(payload);
            hash = await shiftSaveHash(expected.values);
        } catch {
            busy.current = false;
            if (mounted.current) {
                setPending(false);
                setOutcome({
                    status: 'rejected',
                    message:
                        'The save could not be prepared. Your entries are kept here.',
                    errors: {},
                });
            }
            return;
        }
        if (
            !mounted.current ||
            identity.current !== sentContext ||
            generation.current !== sentGeneration
        ) {
            busy.current = false;
            held.current = true;
            if (mounted.current) {
                setPending(false);
                setOutcome({ status: 'unknown', message: UNKNOWN_SHIFT_SAVE });
            }
            return;
        }
        attempted.current = true;
        let result: ShiftSaveOutcome | null = null,
            finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            busy.current = false;
            const next =
                sentContext === identity.current &&
                sentGeneration === generation.current
                    ? (result ?? {
                          status: 'unknown' as const,
                          message: UNKNOWN_SHIFT_SAVE,
                      })
                    : {
                          status: 'unknown' as const,
                          message: UNKNOWN_SHIFT_SAVE,
                      };
            held.current = next.status !== 'rejected';
            if (mounted.current) {
                setPending(false);
                setOutcome(next);
            }
        };
        const callbacks = {
            // Older callers retain validation-error redirects. This dialog can
            // match the committed, actor-bound single-command outcome.
            headers: { 'X-Shift-Result': 'committed-v1' },
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: Page) => {
                if (!finished && !result)
                    result = shiftSaveReceipt(page.props.flash, expected, hash);
            },
            onError: (errors: Record<string, string>) => {
                if (!finished && !result)
                    result = Object.keys(errors).length
                        ? {
                              status: 'rejected',
                              message: Object.values(errors).join(' '),
                              errors,
                          }
                        : { status: 'unknown', message: UNKNOWN_SHIFT_SAVE };
            },
            onCancel: () => {
                if (!finished && !result)
                    result = { status: 'unknown', message: UNKNOWN_SHIFT_SAVE };
            },
            onFinish: finish,
        };
        try {
            if (expected.source)
                router.put(
                    `/operations/shifts/${expected.source.shift_id}`,
                    sentPayload,
                    callbacks,
                );
            else router.post('/operations/shifts', sentPayload, callbacks);
        } catch {
            finish();
        }
    };
    return { submit, pending, outcome, busy, held };
}
