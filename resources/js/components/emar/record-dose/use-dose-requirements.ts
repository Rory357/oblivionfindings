/* eMAR P01 — loads what recording one dose needs and allows (the server's
 * DoseRecordingRequirements) when the dialog opens. Read-only; saving
 * checks everything again. */
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import type { DoseTarget, RequirementsAnswer } from './types';

export type RequirementsState =
    | { status: 'loading' }
    | { status: 'ready'; data: RequirementsAnswer }
    | { status: 'not_found' }
    | { status: 'error' };

export function requirementsUrl(target: DoseTarget): string {
    if (target.kind === 'prn') {
        return `/meds/today/prn/${target.orderId}/requirements`;
    }
    const query = new URLSearchParams({
        client_medication_id: String(target.orderId),
        scheduled_for: target.scheduledFor,
    });

    return `/meds/today/doses/requirements?${query.toString()}`;
}

export function useDoseRequirements(target: DoseTarget | null): RequirementsState & { reload: () => void } {
    const [state, setState] = useState<RequirementsState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const url = target ? requirementsUrl(target) : null;

    useEffect(() => {
        if (!url) return;
        let cancelled = false;
        setState({ status: 'loading' });
        axios
            .get<RequirementsAnswer>(url, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!cancelled) setState({ status: 'ready', data: response.data });
            })
            .catch((error: unknown) => {
                if (cancelled) return;
                const status = axios.isAxiosError(error) ? error.response?.status : undefined;
                setState(status === 404 || status === 403 ? { status: 'not_found' } : { status: 'error' });
            });

        return () => {
            cancelled = true;
        };
    }, [url, attempt]);

    const reload = useCallback(() => setAttempt((n) => n + 1), []);

    return { ...state, reload };
}
