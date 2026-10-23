import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import { restraintReturn } from '@/lib/health-safety/restraint-navigation';
import { injuryReturn } from '@/lib/health-safety/injury-navigation';
import type { FormDataType, Method } from '@inertiajs/core';
import type { InertiaFormProps } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

export type CommandContext = {
    id: number;
    version: number;
    allowed: boolean;
    policyRevision?: number | null;
    candidateId?: number | null;
    candidateDigest?: string | null;
};
export type SourceReceipt = Record<string, unknown> & {
    accepted: true;
    command: string;
    idempotency_key: string;
    version: number;
    replayed: boolean;
};
export type SourceReceiptExpectation = {
    kind: 'event' | 'plan' | 'injury';
    id?: number;
    version?: number;
    key: string;
    command: string;
    childKey?: string;
    childId?: number;
};
type SourceCommandGuard = {
    id: number | undefined;
    version: number | undefined;
    key: string;
    policyRevision: number | null | undefined;
    candidateId: number | null | undefined;
    candidateDigest: string | null | undefined;
};
type SourceCommand<T extends object> = {
    form: InertiaFormProps<T>;
    change: <K extends keyof T>(field: K, value: T[K]) => void;
    guard: SourceCommandGuard;
    current: CommandContext | null;
    stale: boolean;
    checking: boolean;
    checkFailed: boolean;
    reviewAvailable: boolean;
    checkedResponse: unknown;
    allowed: boolean;
    receipt: SourceReceipt | null;
    confirmedHref: string | null;
    failure: string | null;
    checkCurrent: () => Promise<void>;
    useCurrent: () => void;
    save: (
        method: Method,
        href: string,
        values: Record<string, unknown>,
    ) => void;
};
export function confirmsSourceReceipt(
    value: unknown,
    expected: {
        kind: 'event' | 'plan';
        id?: number;
        version?: number;
        key: string;
        command: string;
        childKey?: string;
        childId?: number;
    },
): value is SourceReceipt {
    if (!value || typeof value !== 'object') return false;
    const receipt = value as Record<string, unknown>;
    const id = receipt[expected.kind + '_id'];
    return (
        receipt.accepted === true &&
        Number.isSafeInteger(id) &&
        Number(id) > 0 &&
        (expected.id == null || id === expected.id) &&
        receipt.version ===
            (expected.version == null ? 1 : expected.version + 1) &&
        (expected.kind === 'event'
            ? Number.isSafeInteger(receipt.source_revision) &&
              Number(receipt.source_revision) > 0 &&
              typeof receipt.recorded_at === 'string' &&
              !Number.isNaN(Date.parse(receipt.recorded_at))
            : receipt.source_type === 'plan' &&
              Number.isSafeInteger(receipt.receipt_id) &&
              Number(receipt.receipt_id) > 0) &&
        receipt.command === expected.command &&
        receipt.idempotency_key === expected.key &&
        typeof receipt.replayed === 'boolean' &&
        (expected.childKey == null ||
            (receipt[expected.childKey] === expected.childId &&
                (expected.childKey !== 'supersedes_id' ||
                    (['correct_observation', 'correct_debrief'].includes(
                        expected.command,
                    ) &&
                        Number.isSafeInteger(
                            receipt[
                                expected.command === 'correct_observation'
                                    ? 'observation_id'
                                    : 'debrief_id'
                            ],
                        ) &&
                        Number(
                            receipt[
                                expected.command === 'correct_observation'
                                    ? 'observation_id'
                                    : 'debrief_id'
                            ],
                        ) > 0 &&
                        receipt[
                            expected.command === 'correct_observation'
                                ? 'observation_id'
                                : 'debrief_id'
                        ] !== expected.childId))))
    );
}

/** Source reads never replace the draft/history. Stale work needs explicit review; receipts precede success. */
export function useSourceCommand<T extends FormDataType<T>>({
    initial,
    kind,
    command,
    context,
    captureAllowed = true,
    checkHref,
    readContext,
    receiptKey,
    childKey,
    childId,
    confirmReceipt,
}: {
    initial: T;
    kind: 'event' | 'plan' | 'injury';
    command: string;
    context: CommandContext | null;
    captureAllowed?: boolean;
    checkHref: string | null;
    readContext: (value: unknown) => CommandContext | null;
    receiptKey:
        | 'restraint_confirmation'
        | 'behaviour_support_plan_confirmation'
        | 'injury_confirmation';
    childKey?: string;
    childId?: number;
    confirmReceipt?: (value: unknown, expected: SourceReceiptExpectation) => value is SourceReceipt;
}): SourceCommand<T> {
    const form = useSafetyForm(initial);
    const [guard, setGuard] = useState(() => ({
        id: context?.id,
        version: context?.version,
        key: crypto.randomUUID(),
        policyRevision: context?.policyRevision,
        candidateId: context?.candidateId,
        candidateDigest: context?.candidateDigest,
    }));
    const [checked, setChecked] = useState<{
        source: CommandContext | null;
        current: CommandContext;
        response: unknown;
    } | null>(null);
    const [checking, setChecking] = useState(false);
    const [checkFailed, setCheckFailed] = useState(false);
    const [withdrawn, setWithdrawn] = useState(false);
    const [attempted, setAttempted] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [receipt, setReceipt] = useState<SourceReceipt | null>(null);
    const [confirmedHref, setConfirmedHref] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    useSafetyDraftState({
        dirty: !receipt && (form.isDirty || attempted),
        busy: form.processing || checking,
    });
    const checkedMatches =
        checked !== null &&
        JSON.stringify(checked.source) === JSON.stringify(context);
    const current = checkedMatches ? checked.current : context;
    const stale =
        !!context &&
        (!current ||
            current.id !== guard.id ||
            current.version !== guard.version ||
            current.policyRevision !== guard.policyRevision ||
            current.candidateId !== guard.candidateId ||
            current.candidateDigest !== guard.candidateDigest);
    const allowed =
        !withdrawn &&
        (context
            ? context.allowed && current?.allowed === true
            : captureAllowed && (!checkedMatches || checked.current.allowed));
    const expected = {
        kind,
        id: guard.id,
        version: guard.version,
        key: guard.key,
        command,
        childKey,
        childId:
            kind === 'plan' && childKey === 'candidate_version_id'
                ? (guard.candidateId ?? childId)
                : childId,
    };
    const confirms = (value: unknown): value is SourceReceipt =>
        confirmReceipt ? confirmReceipt(value, expected)
            : expected.kind === 'injury' ? false
            : confirmsSourceReceipt(value, { ...expected, kind: expected.kind });
    const receiptHref = (value: Record<string, unknown>) =>
        value[kind === 'injury' ? 'injury_href' : kind === 'event' ? 'restraint_href' : 'plan_href'];
    const accept = (value: unknown, href?: unknown) => {
        if (!confirms(value)) return false;
        const id = value[kind + '_id'];
        const sourcePath = kind === 'injury' ? `/health-safety/injuries/${id}`
            : `/health-safety/restraints/${kind === 'event' ? 'events' : 'plans'}/${id}`;
        if (
            typeof href === 'string' &&
            (href === sourcePath ||
                href.startsWith(sourcePath + '?') ||
                href === sourcePath + '/review' ||
                href.startsWith(sourcePath + '/review?') ||
                (kind === 'plan' &&
                    (href === sourcePath + '/approval' ||
                        href.startsWith(sourcePath + '/approval?')))) &&
            !/[\\\r\n#]/.test(href)
        )
            setConfirmedHref(kind === 'injury' ? injuryReturn(href) : restraintReturn(href));
        setReceipt(value);
        setAttempted(false);
        setFailure(null);
        form.reset();
        return true;
    };
    const change = <K extends keyof T>(field: K, value: T[K]) => {
        if (form.processing || checking || receipt) return;
        if (Object.is(form.data[field], value)) return;
        // This command changes top-level fields. Avoid Inertia's recursive
        // dotted-field key expansion while preserving its typed object update.
        form.setData((before) => ({ ...before, [field]: value }));
        setGuard((before) => ({ ...before, key: crypto.randomUUID() }));
        setFailure(null);
        setAttempted(false);
    };
    const checkCurrent = async () => {
        if (!checkHref || checking || form.processing || receipt) return;
        const controller = new AbortController();
        request.current = controller;
        setChecking(true);
        try {
            const response = await axios.get(checkHref, {
                headers: { Accept: 'application/json' },
                params: { receipt_key: guard.key, include_history: 0 },
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const value = readContext(response.data);
            if (
                !value ||
                (context
                    ? value.id !== guard.id ||
                      !Number.isSafeInteger(value.version) ||
                      value.version < (kind === 'injury' ? 0 : 1)
                    : value.id !== 0 || value.version !== 0) ||
                typeof value.allowed !== 'boolean'
            )
                throw new Error('Current source unavailable');
            setChecked({
                source: context,
                current: value,
                response: response.data,
            });
            setCheckFailed(false);
            setWithdrawn(!value.allowed);
            if (
                !accept(
                    response.data?.[receiptKey],
                    receiptHref(response.data),
                )
            )
                setFailure(null);
        } catch (error) {
            if (controller.signal.aborted) return;
            setCheckFailed(true);
            if (
                axios.isAxiosError(error) &&
                [401, 403, 404].includes(error.response?.status ?? 0)
            )
                setWithdrawn(true);
            setFailure(
                'Current work could not be checked. Your entries remain here. Retry the check before saving.',
            );
        } finally {
            if (request.current === controller) {
                request.current = null;
                setChecking(false);
            }
        }
    };
    const useCurrent = () => {
        if (
            !checkedMatches ||
            !checked.current.allowed ||
            checked.current.id !== guard.id ||
            checking ||
            form.processing ||
            receipt
        )
            return;
        setGuard((before) => ({
            ...before,
            version: checked.current.version,
            key: crypto.randomUUID(),
            policyRevision: checked.current.policyRevision,
            candidateId: checked.current.candidateId,
            candidateDigest: checked.current.candidateDigest,
        }));
        form.clearErrors();
        setAttempted(false);
        setFailure(null);
    };
    const save = (
        method: Method,
        href: string,
        values: Record<string, unknown>,
    ) => {
        if (
            form.processing ||
            checking ||
            !allowed ||
            stale ||
            (form.errors as Record<string, unknown>).expected_version ||
            (form.errors as Record<string, unknown>).expected_policy_revision ||
            checkFailed ||
            receipt
        )
            return;
        setAttempted(true);
        setFailure(null);
        form.transform(
            () =>
                ({
                    ...values,
                    idempotency_key: guard.key,
                    ...(guard.version != null
                        ? { expected_version: guard.version }
                        : {}),
                    ...(guard.policyRevision != null
                        ? { expected_policy_revision: guard.policyRevision }
                        : {}),
                }) as T,
        );
        let responded = false;
        form.submit(method, href, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                responded = true;
                if (
                    !accept(
                        page.props[receiptKey],
                        receiptHref(page.props),
                    )
                )
                    setFailure(
                        'Saving could not be confirmed. Your entries remain here. Check the record before trying again.',
                    );
            },
            onError: () => {
                responded = true;
            },
            onCancel: () => {
                responded = true;
                setFailure(
                    'Saving was interrupted. Your entries remain here. Check the record before trying again.',
                );
            },
            onFinish: () => {
                if (!responded)
                    setFailure(
                        'Saving could not be confirmed. Your entries remain here. Check the record before trying again.',
                    );
            },
        });
    };
    return {
        form,
        change,
        guard,
        current,
        stale:
            stale ||
            !!(form.errors as Record<string, unknown>).expected_version ||
            !!(form.errors as Record<string, unknown>).expected_policy_revision,
        checking,
        checkFailed,
        reviewAvailable: !!checkedMatches && checked.current.allowed,
        checkedResponse:
            checkedMatches && checked.current.allowed ? checked.response : null,
        allowed,
        receipt,
        confirmedHref,
        failure,
        checkCurrent,
        useCurrent,
        save,
    };
}
