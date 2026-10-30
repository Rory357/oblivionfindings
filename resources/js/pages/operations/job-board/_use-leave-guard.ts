import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * The page's ONE guard against losing an in-flight or unconfirmed request
 * (or an open posting draft) by leaving the page. It replaces the separate
 * listeners Codex gave each dialog, so a single visit can only ever raise
 * one question.
 *
 * Visits that stay on this page (search, filters, week, pages, and the
 * refresh after a confirmed result) keep the page mounted, so they are never
 * blocked. Only a visit to another page asks first.
 */
export function useLeaveGuard(active: boolean) {
    const [pending, setPending] = useState<(() => void) | null>(null);
    const allowed = useRef<string | null>(null);

    useEffect(() => {
        if (!active) return;
        const preventUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', preventUnload);
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                allowed.current !== null &&
                visit.url.href === allowed.current
            ) {
                allowed.current = null;
                return;
            }
            const samePage =
                visit.method === 'get' &&
                visit.url.origin === window.location.origin &&
                visit.url.pathname === window.location.pathname &&
                visit.preserveState !== false;
            if (samePage) return;
            event.preventDefault();
            setPending(() => () => {
                allowed.current = visit.url.href;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            remove();
            window.removeEventListener('beforeunload', preventUnload);
        };
    }, [active]);

    return {
        asking: pending !== null,
        stay: () => setPending(null),
        leave: () => {
            const run = pending;
            setPending(null);
            if (!run) return;
            try {
                run();
            } catch {
                toast.error("That page couldn't be opened", {
                    description: 'Your details are still here.',
                });
            } finally {
                allowed.current = null;
            }
        },
    };
}
