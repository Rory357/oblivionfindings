import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { MedicationDay } from './types';

export type DayLoad =
    | { status: 'loading'; data: MedicationDay | null }
    | { status: 'ready'; data: MedicationDay }
    | { status: 'error'; data: MedicationDay | null };

/**
 * One person's medication day from the server (the Fleet profile pattern:
 * the tab loads its own records). Null date = today. A newer request wins;
 * a failed one keeps the last good day on screen behind the error.
 */
export function useMedicationDay(clientId: number, date: string | null) {
    const [state, setState] = useState<{ clientId: number; load: DayLoad }>({
        clientId,
        load: { status: 'loading', data: null },
    });
    const latest = useRef(0);

    const fetchDay = useCallback(async () => {
        const request = ++latest.current;
        setState((prev) => ({
            clientId,
            load: {
                status: 'loading',
                data: prev.clientId === clientId ? prev.load.data : null,
            },
        }));
        try {
            const response = await axios.get<MedicationDay>(
                `/emar/clients/${clientId}/day`,
                { params: date ? { date } : {} },
            );
            if (request === latest.current) {
                setState({
                    clientId,
                    load: { status: 'ready', data: response.data },
                });
            }
        } catch {
            if (request === latest.current) {
                setState((prev) => ({
                    clientId,
                    load: {
                        status: 'error',
                        data:
                            prev.clientId === clientId ? prev.load.data : null,
                    },
                }));
            }
        }
    }, [clientId, date]);

    useEffect(() => {
        void fetchDay();
        return () => {
            latest.current++;
        };
    }, [fetchDay]);

    const load: DayLoad =
        state.clientId === clientId
            ? state.load
            : { status: 'loading', data: null };
    return { load, reload: fetchDay };
}

/** The NZ day before / after a Y-m-d, as Y-m-d (calendar arithmetic, no time zone). */
export function shiftDay(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    const next = new Date(Date.UTC(y, m - 1, d + days));
    return next.toISOString().slice(0, 10);
}

/** "Today, Sat 3 Oct" / "Fri 2 Oct" / "Tomorrow, Sun 4 Oct". */
export function dayLabel(
    date: string,
    today: string,
    tomorrow: string,
): string {
    const [y, m, d] = date.split('-').map(Number);
    const parts = new Intl.DateTimeFormat('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).formatToParts(new Date(Date.UTC(y, m - 1, d)));
    const part = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? '';
    const words = `${part('weekday')} ${part('day')} ${part('month')}`;
    if (date === today) return `Today, ${words}`;
    if (date === tomorrow) return `Tomorrow, ${words}`;
    return words;
}
