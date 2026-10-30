/* eMAR P01 — loads what recording one dose needs and allows (the server's
 * DoseRecordingRequirements) when the dialog opens. Read-only; saving
 * checks everything again. */
import { rememberOfflineMaxHours } from '@/lib/offline-queue';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
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

/** Header that carries the open dialog's own connected review when it is refreshed (EA-060). */
export const RENEW_REVIEW_HEADER = 'X-Live-Recording-Context';

export function useDoseRequirements(target: DoseTarget | null): RequirementsState & {
    reload: () => void;
    /**
     * Fetch the current requirements again while the open form keeps its
     * draft (EA-008, EA-060). The answer replaces the shown requirements only
     * when it arrives; nothing is cleared while it loads.
     */
    refresh: (renewReview?: string | null) => Promise<RequirementsAnswer | null>;
} {
    const [state, setState] = useState<RequirementsState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const url = target ? requirementsUrl(target) : null;
    const currentUrl = useRef(url);

    useEffect(() => {
        currentUrl.current = url;
        if (!url) return;
        let cancelled = false;
        setState({ status: 'loading' });
        axios
            .get<RequirementsAnswer>(url, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (cancelled) return;
                // Review P1: the device ages its saved doses by this limit.
                if ('offline_max_hours' in response.data) rememberOfflineMaxHours(response.data.offline_max_hours);
                setState({ status: 'ready', data: response.data });
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

    const refresh = useCallback(async (renewReview?: string | null): Promise<RequirementsAnswer | null> => {
        const requested = currentUrl.current;
        if (!requested) return null;
        try {
            const response = await axios.get<RequirementsAnswer>(requested, {
                headers: {
                    Accept: 'application/json',
                    ...(renewReview ? { [RENEW_REVIEW_HEADER]: renewReview } : {}),
                },
            });
            if (currentUrl.current !== requested) return null;
            setState({ status: 'ready', data: response.data });

            return response.data;
        } catch (error: unknown) {
            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            if (status === 404 || status === 403) setState({ status: 'not_found' });

            return null;
        }
    }, []);

    return { ...state, reload, refresh };
}
