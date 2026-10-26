import { PopupConfirm } from '@/components/popup';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';

type Procedure = { id: number; name: string; auto_attach: boolean };

const toggleUrl = (id: number) => `/control-room/playbooks/${id}/toggle-active`;

/** Turning a procedure back on restores it, so it needs no confirm. */
export function turnOnProcedure(procedure: Pick<Procedure, 'id'>) {
    router.post(toggleUrl(procedure.id), {}, { preserveScroll: true });
}

/**
 * Turning a procedure off stops operators starting it (and stops it starting
 * on its own), which loosens a safety check — so it is confirmed in red.
 * Used by the Procedures list and a procedure's own page.
 */
export function TurnOffProcedureConfirm({
    procedure,
    onClose,
}: {
    procedure: Procedure;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const confirm = () => {
        setProcessing(true);
        setError(undefined);
        router.post(
            toggleUrl(procedure.id),
            {},
            {
                preserveScroll: true,
                onSuccess: (page: Page) => {
                    const flash = (page.props as { flash?: { error?: string } }).flash;
                    if (flash?.error) setError(flash.error);
                    else onClose();
                },
                onError: () => setError('This could not be turned off. Try again.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={confirm}
            variant="warning"
            title="Turn off this procedure?"
            description={
                <>
                    Operators can&apos;t start{' '}
                    <b className="font-semibold text-foreground">{procedure.name}</b> from an
                    alert while it is off
                    {procedure.auto_attach
                        ? ', and it no longer starts on its own for matching new alerts'
                        : ''}
                    . Procedures already running on alerts carry on, and you can turn it
                    back on at any time.
                </>
            }
            confirmLabel="Turn off procedure"
            confirmDestructive
            processing={processing}
            error={error}
            testId="turn-off-procedure"
        />
    );
}
