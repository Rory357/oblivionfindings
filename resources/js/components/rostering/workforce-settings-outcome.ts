import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type SettingsValues = Record<string, string | number>;
export type SettingsCommand = {
    action: 'preferences' | 'staffing_rules';
    actor_id: number;
    expected_revision: string;
    values: SettingsValues;
};
export type SettingsReceipt = SettingsCommand & {
    prior_revision: string;
    revision: string;
    changed: boolean;
    refresh?: {
        status: 'staged' | 'not_requested';
        recheck_id: number | null;
        source_version: number | null;
    };
};
export const object = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
export const isRevision = (value: unknown): value is string =>
    typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
export function settingsProps(page: unknown, actorId: number) {
    const props = object(object(page)?.props);
    if (
        !props ||
        object(object(props.auth)?.user)?.id !== actorId ||
        object(props.flash)?.error ||
        Object.keys(object(props.errors) ?? {}).length
    )
        return null;
    return props;
}
export function settingsReceipt(
    page: unknown,
    expected: SettingsCommand,
): SettingsReceipt | null {
    const props = settingsProps(page, expected.actor_id);
    const receipt = object(object(props?.flash)?.workforce_settings_result);
    const values = object(receipt?.values);
    if (
        !receipt ||
        !values ||
        receipt.action !== expected.action ||
        receipt.actor_id !== expected.actor_id ||
        receipt.expected_revision !== expected.expected_revision ||
        receipt.prior_revision !== expected.expected_revision ||
        !isRevision(receipt.revision) ||
        typeof receipt.changed !== 'boolean' ||
        Object.keys(values).length !== Object.keys(expected.values).length ||
        Object.entries(expected.values).some(
            ([key, value]) => values[key] !== value,
        )
    )
        return null;
    if (expected.action === 'staffing_rules') {
        const refresh = object(receipt.refresh);
        if (!refresh) return null;
        if (receipt.changed) {
            if (
                refresh.status !== 'staged' ||
                !Number.isSafeInteger(refresh.recheck_id) ||
                Number(refresh.recheck_id) <= 0 ||
                !Number.isSafeInteger(refresh.source_version) ||
                Number(refresh.source_version) <= 0
            )
                return null;
        } else if (
            refresh.status !== 'not_requested' ||
            refresh.recheck_id !== null ||
            refresh.source_version !== null ||
            receipt.revision !== receipt.prior_revision
        )
            return null;
    }
    return receipt as SettingsReceipt;
}

type Options = NonNullable<Parameters<typeof router.patch>[2]>;
/** Commands and recovery reads share one lock; unconfirmed writes require an explicit read. */
export function useSettingsCommand(actorId: number) {
    const pending = useRef(false);
    const held = useRef(false);
    const alive = useRef(true);
    const generation = useRef(0);
    const actor = useRef(actorId);
    useEffect(() => {
        actor.current = actorId;
    }, [actorId]);
    const [processing, setProcessing] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            generation.current += 1;
        };
    }, []);
    const run = (
        command: SettingsCommand,
        send: (options: Options) => void,
        confirmed: (receipt: SettingsReceipt) => void,
    ) => {
        if (
            pending.current ||
            held.current ||
            !Number.isSafeInteger(actorId) ||
            actorId <= 0
        )
            return;
        pending.current = true;
        setProcessing(true);
        setError(null);
        const token = ++generation.current;
        const expected = { ...command, values: { ...command.values } };
        const current = () =>
            alive.current &&
            generation.current === token &&
            actor.current === command.actor_id;
        let settled = false;
        let finished = false;
        const unknown = () => {
            if (!current() || settled) return;
            settled = true;
            held.current = true;
            setUncertain(true);
            setError(
                'We could not confirm this save. Your entries are retained. Check current settings before trying again.',
            );
        };
        const finish = () => {
            if (!current() || finished) return;
            finished = true;
            if (!settled) unknown();
            pending.current = false;
            setProcessing(false);
        };
        try {
            send({
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!current() || settled) return;
                    const receipt = settingsReceipt(page, expected);
                    if (!receipt) {
                        unknown();
                        return;
                    }
                    settled = true;
                    confirmed(receipt);
                },
                onError: (errors) => {
                    if (!current() || settled) return;
                    const messages = Object.values(errors).filter(
                        (value) => typeof value === 'string' && value.trim(),
                    );
                    if (!messages.length) {
                        unknown();
                        return;
                    }
                    settled = true;
                    setError(messages.join(' '));
                },
                onCancel: unknown,
                onFinish: finish,
            });
        } catch {
            unknown();
            finish();
        }
    };
    const check = <T>(
        keys: string[],
        parse: (props: Record<string, unknown>) => T | null,
        reviewed: (value: T) => void,
    ) => {
        if (pending.current) return;
        pending.current = true;
        setProcessing(true);
        const token = ++generation.current;
        const expectedActor = actorId;
        const current = () =>
            alive.current &&
            generation.current === token &&
            actor.current === expectedActor;
        let settled = false;
        let finished = false;
        const failed = () => {
            if (!current() || settled) return;
            settled = true;
            setError(
                'Current settings could not be checked. Your entries are retained. Try checking again.',
            );
        };
        const finish = () => {
            if (!current() || finished) return;
            finished = true;
            if (!settled) failed();
            pending.current = false;
            setProcessing(false);
        };
        try {
            router.reload({
                only: [...new Set([...keys, 'auth'])],
                onSuccess: (page) => {
                    if (!current() || settled) return;
                    const props = settingsProps(page, expectedActor);
                    const value = props ? parse(props) : null;
                    if (!value) {
                        failed();
                        return;
                    }
                    settled = true;
                    held.current = false;
                    setUncertain(false);
                    setError(null);
                    reviewed(value);
                },
                onError: failed,
                onCancel: failed,
                onFinish: finish,
            });
        } catch {
            failed();
            finish();
        }
    };
    return {
        processing,
        uncertain,
        error,
        setError,
        pending,
        run,
        check,
        locked: processing || uncertain,
    };
}
