import { Button } from '@/components/ui/button';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import type { FormDataType, Method } from '@inertiajs/core';
import { useState } from 'react';
import type { DrillWorkspaceDetail } from './types';

/** A form keeps the revision it opened against, including after a failed save. */
export function useDrillCommandForm<T extends FormDataType<T>>(
    initial: T,
    detail: DrillWorkspaceDetail | null,
    onSaved: () => void,
) {
    const [expected] = useState(() => ({
        id: detail?.id,
        version: detail?.expected_version,
    }));
    const [identity] = useState(() => crypto.randomUUID());
    const [failure, setFailure] = useState<string | null>(null);
    const form = useSafetyForm(initial);
    const guards = {
        idempotency_key: identity,
        ...(expected.version != null
            ? { expected_version: expected.version }
            : {}),
    };
    const save = (method: Method, href: string) => {
        if (form.processing) return;
        setFailure(null);
        let responded = false;
        form.submit(method, href, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                responded = true;
                const error = (
                    page.props.flash as { error?: string } | undefined
                )?.error;
                if (error) {
                    setFailure(error);
                    return;
                }
                const updated = page.props.detail as
                    | DrillWorkspaceDetail
                    | undefined;
                if (
                    !updated ||
                    !Number.isSafeInteger(updated.id) ||
                    updated.id <= 0 ||
                    !Number.isSafeInteger(updated.lock_version) ||
                    updated.lock_version < 1 ||
                    (expected.id != null &&
                        (updated.id !== expected.id ||
                            updated.lock_version <= expected.version!))
                ) {
                    setFailure(
                        'The saved change could not be confirmed. Your entries are still here. Check the drill before trying again.',
                    );
                    return;
                }
                form.reset();
                onSaved();
            },
            onError: () => {
                responded = true;
            },
            onCancel: () => {
                responded = true;
                setFailure(
                    'Saving was interrupted. Your entries are still here. Check the drill before trying again.',
                );
            },
            onFinish: () => {
                if (!responded)
                    setFailure(
                        'The save could not be confirmed. Your entries are still here. Check the drill before trying again.',
                    );
            },
        });
    };
    return { form, guards, save, failure, setFailure };
}

export function DrillCommandFooter({
    label,
    processing,
    failure,
    errors,
    onCancel,
    disabled = false,
    cancelLabel = 'Back to drill',
}: {
    label: string;
    processing: boolean;
    failure: string | null;
    errors: Record<string, string>;
    onCancel: () => void;
    disabled?: boolean;
    cancelLabel?: string;
}) {
    return (
        <>
            {(failure || Object.keys(errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={processing || disabled}
                >
                    {processing ? 'Saving…' : label}
                </Button>
                <Button
                    className="frontline-tap"
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={onCancel}
                >
                    {cancelLabel}
                </Button>
            </div>
        </>
    );
}
