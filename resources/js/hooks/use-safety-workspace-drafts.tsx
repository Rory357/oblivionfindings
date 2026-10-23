import { useSafetyHistoryGuard } from '@/hooks/use-safety-history-guard';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import type { FormDataType, PendingVisit } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/react';
import {
    cloneElement,
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useState,
} from 'react';

type Draft = { dirty: boolean; busy: boolean; uncertain?: boolean };
type Register = (id: string, state: Draft | null) => void;
export const SafetyFormDraftContext = createContext<Register | null>(null);

/** Deferred inbox refreshes cannot replace this record or its in-memory forms. All other
 * GETs still need leave protection, including detail reloads and section links. */
function isPreservedInboxRefresh(visit: PendingVisit): boolean {
    if (
        typeof window === 'undefined' ||
        visit.method !== 'get' ||
        !visit.async ||
        visit.preserveState !== true ||
        visit.reset.length ||
        visit.only.length !== 1 ||
        visit.only[0] !== 'inbox'
    )
        return false;
    const target = new URL(visit.url, window.location.href);
    const current = new URL(window.location.href);
    return (
        target.origin === current.origin &&
        target.pathname === current.pathname &&
        target.search === current.search
    );
}

/** Sensitive form contents stay inside their form, in memory only. */
export function useSafetyWorkspaceDrafts() {
    // An inbox request may start before a command opens. Its late response must
    // not restore the old query string and unmount a newly edited form. Register
    // while clean as well as dirty; ordinary record requests remain untouched.
    useEffect(
        () =>
            router.on('before', (event) => {
                if (isPreservedInboxRefresh(event.detail.visit))
                    event.detail.visit.preserveUrl = true;
            }),
        [],
    );
    const [forms, setForms] = useState<Record<string, Draft>>({});
    const register = useCallback<Register>((id, state) => {
        setForms((current) => {
            if (state === null) {
                if (!(id in current)) return current;
                const next = { ...current };
                delete next[id];
                return next;
            }
            if (
                current[id]?.dirty === state.dirty &&
                current[id]?.busy === state.busy &&
                !!current[id]?.uncertain === !!state.uncertain
            )
                return current;
            return { ...current, [id]: state };
        });
    }, []);
    const busy = Object.values(forms).some((form) => form.busy);
    const dirty = Object.values(forms).some((form) => form.dirty);
    const uncertain = Object.values(forms).some((form) => form.uncertain);
    const guard = useSettingsLeaveConfirmation(
        dirty || busy || uncertain,
        uncertain ? 'Leave before this submission is confirmed?' : busy
            ? 'Leave while safety work is saving?'
            : 'Discard unsaved safety work?',
        uncertain ? 'Your original submission may already be recorded. Leaving discards entries retained in this tab. Cancel to check the original submission before sending again.' : busy
            ? 'The request may still finish after you leave. Check the record before sending again. Cancel to stay here.'
            : 'Your unsaved entries will be discarded. Cancel to keep editing.',
        undefined,
        isPreservedInboxRefresh,
    );
    const historyRecovery = useSafetyHistoryGuard(dirty || busy || uncertain, guard.request);

    return {
        register,
        request: guard.request,
        dirty,
        busy,
        confirmation: (
            <>
                {cloneElement(guard.confirmation, { frontline: true })}
                {historyRecovery}
            </>
        ),
    };
}

/** Same Inertia form contract, with workspace-level leave protection. */
export function useSafetyForm<T extends FormDataType<T>>(initial: T) {
    const form = useForm<T>(initial);
    useSafetyDraftState({ dirty: !!form.isDirty, busy: !!form.processing });
    return form;
}

/** Local upload state participates without copying descriptions or files. */
export function useSafetyDraftState({ dirty, busy, uncertain = false }: Draft) {
    const register = useContext(SafetyFormDraftContext);
    const id = useId();
    useEffect(() => {
        register?.(id, { dirty, busy, uncertain });
    }, [register, id, dirty, busy, uncertain]);
    useEffect(() => () => register?.(id, null), [register, id]);
}
