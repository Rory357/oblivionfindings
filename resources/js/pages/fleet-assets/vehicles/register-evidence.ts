import { useEffect, useState } from 'react';
import type { FleetEvent } from './fleet-calendar';

/** Calendar evidence is context, never an availability or readiness decision. */
export function registerEvidence(
    events: FleetEvent[],
    vehicleId: number,
    now: number,
) {
    const rows = events.filter(
        (event): event is FleetEvent & { start: string } =>
            event.vehicleId === vehicleId &&
            !!event.start &&
            Number.isFinite(Date.parse(event.start)),
    );
    const current = rows
        .filter(
            (event) =>
                Date.parse(event.start) <= now &&
                (!event.end || Date.parse(event.end) > now) &&
                [
                    'restriction',
                    'booking',
                    'busy',
                    'unavailable',
                    'appointment',
                ].includes(event.kind),
        )
        .sort(
            (a, b) =>
                (a.kind === 'restriction' ? 0 : 1) -
                (b.kind === 'restriction' ? 0 : 1),
        )[0];
    const next = rows
        .filter((event) => Date.parse(event.start) > now)
        .sort((a, b) => Date.parse(a.start) - Date.parse(b.start))[0];
    return { current, next };
}

export function useRegisterEvidence(enabled: boolean) {
    const [state, setState] = useState<{
        events: FleetEvent[];
        asOf: string;
        state: 'loading' | 'ready' | 'failed';
    }>({ events: [], asOf: '', state: 'loading' });
    useEffect(() => {
        if (!enabled) return;
        let request: AbortController | null = null;
        const load = async () => {
            request?.abort();
            const controller = new AbortController();
            request = controller;
            try {
                const now = Date.now();
                const params = new URLSearchParams({
                    start: new Date(now - 86400000).toISOString(),
                    end: new Date(now + 14 * 86400000).toISOString(),
                });
                const response = await fetch(
                    `/fleet-assets/vehicles/fleet-calendar/events?${params}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Evidence unavailable');
                const feed = (await response.json()) as {
                    events: FleetEvent[];
                    as_of: string;
                };
                if (!controller.signal.aborted)
                    setState({
                        events: feed.events,
                        asOf: feed.as_of,
                        state: 'ready',
                    });
            } catch {
                if (!controller.signal.aborted)
                    setState({ events: [], asOf: '', state: 'failed' });
            }
        };
        void load();
        const timer = window.setInterval(() => void load(), 60000);
        return () => {
            request?.abort();
            window.clearInterval(timer);
        };
    }, [enabled]);
    return state;
}
