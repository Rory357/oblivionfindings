import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

const SAVE_ERROR = 'Could not save this preference. Try again.';

function normalizeValue<T extends FormDataConvertible[]>(value: T): T {
    if (!value.every((item) => typeof item === 'string')) return value;

    return [...new Set(value)] as T;
}

export function useUiPreference<T extends FormDataConvertible[]>({
    key,
    initialValue,
}: {
    key: string;
    initialValue: T;
}) {
    const [value, setStoredValue] = useState<T>(() =>
        normalizeValue(initialValue),
    );
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const requestSequence = useRef(0);

    const setValue = useCallback(
        (nextValue: T) => {
            const normalizedValue = normalizeValue(nextValue);
            const previousValue = value;
            const sequence = ++requestSequence.current;

            setStoredValue(normalizedValue);
            setSaving(true);
            setError(null);

            router.put(
                `/settings/ui-preferences/${encodeURIComponent(key)}`,
                { value: normalizedValue },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: [],
                    onError: () => {
                        if (requestSequence.current !== sequence) return;
                        setStoredValue(previousValue);
                        setError(SAVE_ERROR);
                    },
                    onFinish: () => {
                        if (requestSequence.current !== sequence) return;
                        setSaving(false);
                    },
                },
            );
        },
        [key, value],
    );

    return {
        value,
        setValue,
        saving,
        error,
    };
}
