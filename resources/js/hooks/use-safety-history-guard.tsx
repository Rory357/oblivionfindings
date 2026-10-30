import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    safetyHistoryIsRestoring,
    subscribeSafetyHistory,
} from '@/lib/safety-history';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/** Browser traversal has no Inertia before-visit event. Contents stay in their forms. */
export function useSafetyHistoryGuard(
    guarded: boolean,
    review: (run: () => void) => void,
    priority = 0,
) {
    const identity = useRef({});
    const [failure, setFailure] = useState<{
        retry: () => void;
        url: string;
        blocked: boolean;
        reload: boolean;
    } | null>(null);
    useEffect(() => {
        if (!guarded) setFailure(null);
    }, [guarded]);
    useEffect(() => {
        if (!guarded) return;
        return subscribeSafetyHistory({
            identity: identity.current,
            priority,
            beforeRestore: () => router.cancelAll(),
            resynchronize: (reload, url) => {
                if (reload) window.location.replace(url);
                else router.visit(window.location.href, { replace: true });
            },
            review: (run) => {
                setFailure(null);
                review(run);
            },
            failed: setFailure,
        });
    }, [guarded, priority, review]);
    useEffect(
        () =>
            router.on('before', (event) => {
                if (safetyHistoryIsRestoring()) event.preventDefault();
            }),
        [],
    );
    return failure ? (
        <ConfirmDialog
            open
            frontline
            processing={false}
            variant="default"
            title="Return to your safety work"
            description={
                <>
                    <p>
                        {failure.reload
                            ? 'A response arrived while browser history was returning to this record. A submitted save may have finished. Your unsaved entries remain in this tab; reloading will discard them. Check the saved record before sending again.'
                            : failure.blocked
                              ? 'Browser history could not return to this record. Your unsaved entries remain in this tab. Try returning before continuing.'
                              : 'That history destination is no longer available. Your unsaved entries remain here. Cancel to keep editing.'}
                    </p>
                    {failure.blocked && !failure.reload && (
                        <Button
                            className="frontline-hit mt-3"
                            variant="outline"
                            onClick={() => window.location.assign(failure.url)}
                        >
                            Reload record and discard unsaved entries
                        </Button>
                    )}
                </>
            }
            confirmText={
                failure.reload
                    ? 'Reload and check record'
                    : failure.blocked
                      ? 'Try returning'
                      : 'Keep editing'
            }
            cancelText="Keep entries in this tab"
            onConfirm={() => {
                if (failure.reload) window.location.replace(failure.url);
                else if (failure.blocked) failure.retry();
                else setFailure(null);
            }}
            onClose={() => {
                if (!failure.blocked) setFailure(null);
            }}
        />
    ) : null;
}
