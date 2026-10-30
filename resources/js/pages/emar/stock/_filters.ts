import { medicationReturnParams } from '@/lib/medication-navigation';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { StockFilters } from './_hub-types';

/** One cancellable visit owns all filters, including rapid house/person changes. */
export function useStockFilters(server: StockFilters) {
    const [filters, setFilters] = useState(server);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const current = useRef(server);
    const serverRef = useRef(server);
    const flight = useRef<{
        serial: number;
        pending: boolean;
        cancel?: () => void;
        timer?: ReturnType<typeof setTimeout>;
    }>({ serial: 0, pending: false });

    const cancel = () => {
        flight.current.serial++;
        flight.current.pending = false;
        clearTimeout(flight.current.timer);
        flight.current.cancel?.();
        flight.current.cancel = undefined;
    };
    useEffect(() => {
        serverRef.current = server;
        if (!flight.current.pending && !failed) {
            current.current = serverRef.current;
            setFilters(serverRef.current);
        }
    }, [server, busy, failed]);
    useEffect(() => {
        const back = () => {
            cancel();
            current.current = serverRef.current;
            setFilters(serverRef.current);
            setBusy(false);
            setFailed(false);
        };
        const fail = () => {
            if (!flight.current.pending) return;
            cancel();
            setBusy(false);
            setFailed(true);
            return false;
        };
        const invalid = router.on('invalid', (event) => {
            if (
                flight.current.pending &&
                event.detail.response.config.url?.includes('/emar/stock')
            ) {
                fail();
                event.preventDefault();
            }
        });
        const exception = router.on('exception', (event) => {
            if (flight.current.pending) {
                fail();
                event.preventDefault();
            }
        });
        window.addEventListener('popstate', back);
        return () => {
            cancel();
            invalid();
            exception();
            window.removeEventListener('popstate', back);
        };
    }, []);

    const change = (patch: Partial<StockFilters>, delay = 0) => {
        cancel();
        const next = { ...current.current, page: 1, ...patch };
        current.current = next;
        setFilters(next);
        setBusy(true);
        setFailed(false);
        flight.current.pending = true;
        const serial = flight.current.serial;
        const owns = () => flight.current.serial === serial;
        flight.current.timer = setTimeout(() => {
            router.get(
                '/emar/stock',
                { ...next, ...medicationReturnParams() },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: delay > 0,
                    onCancelToken: (token) => {
                        if (owns())
                            flight.current.cancel = () => token.cancel();
                        else token.cancel();
                    },
                    onSuccess: (page) => {
                        if (!owns()) return;
                        const accepted = page.props.filters as StockFilters;
                        current.current = accepted;
                        setFilters(accepted);
                    },
                    onError: () => {
                        if (owns()) setFailed(true);
                    },
                    onFinish: () => {
                        if (owns()) {
                            flight.current.pending = false;
                            setBusy(false);
                        }
                    },
                },
            );
        }, delay);
    };
    return {
        filters,
        busy,
        failed,
        change,
        retry: () => change(current.current),
    };
}
