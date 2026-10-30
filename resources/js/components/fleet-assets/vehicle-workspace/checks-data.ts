import { useCallback, useEffect, useState } from 'react';
import type { VehicleChecks } from './checks-types';

export type ChecksLoad = 'loading' | 'ready' | 'error' | 'forbidden';

/**
 * Checks & inspections for one vehicle, from its JSON read model. Reloads
 * when `refreshKey` changes (e.g. the workspace's as_of after a save made
 * elsewhere on the page) and on demand after a change made here.
 */
export function useVehicleChecks(
    vehicleId: number,
    refreshKey = '',
    enabled = true,
) {
    const [data, setData] = useState<VehicleChecks | null>(null);
    const [load, setLoad] = useState<ChecksLoad>('loading');
    const [nonce, setNonce] = useState(0);

    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        setLoad((current) => (current === 'ready' ? current : 'loading'));
        fetch(`/fleet-assets/vehicles/${vehicleId}/checks`, {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok)
                    throw Object.assign(new Error('request failed'), {
                        status: response.status,
                    });
                setData((await response.json()) as VehicleChecks);
                setLoad('ready');
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name === 'AbortError') return;
                const status = (error as { status?: number })?.status;
                setLoad(
                    status === 401 || status === 403 || status === 404
                        ? 'forbidden'
                        : 'error',
                );
            });
        return () => controller.abort();
    }, [vehicleId, refreshKey, enabled, nonce]);

    const reload = useCallback(() => setNonce((value) => value + 1), []);

    return { data, load, reload };
}
