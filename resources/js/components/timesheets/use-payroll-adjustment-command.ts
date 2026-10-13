import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type PayrollAdjustmentReceipt = {
    action: 'process_payroll_adjustment';
    actor_id: number;
    amendment_id: number;
    timesheet_id: number;
    changed: boolean;
    outcome: 'recorded_external_processing' | 'already_recorded';
    processing_method: 'external';
    applied_at: string;
};
export type PayrollAdjustmentIdentity = {
    actorId: number;
    amendmentId: number;
    timesheetId: number;
};
export type PayrollAdjustmentOutcome =
    | { status: 'confirmed'; receipt: PayrollAdjustmentReceipt }
    | { status: 'unknown'; message: string };
export const UNKNOWN_ADJUSTMENT_RESULT =
    'The result could not be confirmed. Processing may already be recorded. Reload the pending queue and check the timesheet before another attempt.';
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
export function payrollAdjustmentReceipt(
    value: unknown,
    expected: PayrollAdjustmentIdentity,
): PayrollAdjustmentOutcome {
    const flash = object(value);
    const r = object(flash.timesheet_payroll_adjustment_result);
    const timestamp =
        typeof r.applied_at === 'string' &&
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/.test(r.applied_at) &&
        Number.isFinite(Date.parse(r.applied_at)) &&
        new Date(r.applied_at).toISOString() === r.applied_at;
    if (
        !flash.error &&
        !flash.warning &&
        positive(expected.actorId) &&
        positive(expected.amendmentId) &&
        positive(expected.timesheetId) &&
        r.actor_id === expected.actorId &&
        r.amendment_id === expected.amendmentId &&
        r.timesheet_id === expected.timesheetId &&
        r.action === 'process_payroll_adjustment' &&
        r.processing_method === 'external' &&
        typeof r.changed === 'boolean' &&
        r.outcome ===
            (r.changed ? 'recorded_external_processing' : 'already_recorded') &&
        timestamp
    )
        return { status: 'confirmed', receipt: r as PayrollAdjustmentReceipt };
    return { status: 'unknown', message: UNKNOWN_ADJUSTMENT_RESULT };
}

/** Identity-only processing never replays after an interrupted or unconfirmed response. */
export function usePayrollAdjustmentCommand(context: string) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<PayrollAdjustmentOutcome | null>(
        null,
    );
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
            setOutcome({
                status: 'unknown',
                message: UNKNOWN_ADJUSTMENT_RESULT,
            });
        }
    }, [context]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const submit = (expected: PayrollAdjustmentIdentity) => {
        if (!mounted.current || busy.current || held.current) return;
        busy.current = true;
        attempted.current = true;
        setPending(true);
        setOutcome(null);
        const sentContext = context,
            sentGeneration = generation.current;
        let result: PayrollAdjustmentOutcome | null = null,
            finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            busy.current = false;
            held.current = true;
            const next =
                sentContext === identity.current &&
                sentGeneration === generation.current
                    ? (result ?? {
                          status: 'unknown' as const,
                          message: UNKNOWN_ADJUSTMENT_RESULT,
                      })
                    : {
                          status: 'unknown' as const,
                          message: UNKNOWN_ADJUSTMENT_RESULT,
                      };
            if (mounted.current) {
                setPending(false);
                setOutcome(next);
            }
        };
        try {
            router.post(
                `/operations/timesheets/amendments/${expected.amendmentId}/mark-processed`,
                {},
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        if (!finished && !result)
                            result = payrollAdjustmentReceipt(
                                page.props.flash,
                                expected,
                            );
                    },
                    onError: () => {
                        if (!finished && !result)
                            result = {
                                status: 'unknown',
                                message: UNKNOWN_ADJUSTMENT_RESULT,
                            };
                    },
                    onCancel: () => {
                        if (!finished && !result)
                            result = {
                                status: 'unknown',
                                message: UNKNOWN_ADJUSTMENT_RESULT,
                            };
                    },
                    onFinish: finish,
                },
            );
        } catch {
            finish();
        }
    };
    return { submit, pending, outcome, busy, held };
}
