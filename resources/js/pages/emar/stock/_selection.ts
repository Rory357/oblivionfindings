import type { StockRow } from '@/pages/emar/_stock-dialogs';
import { useEffect, useState } from 'react';

export type LoadStockContext = (
    medicationId: number,
    signal: AbortSignal,
) => Promise<StockRow | null>;

/** An off-page selection cannot borrow a previous medicine's stock balance. */
export function useSelectedStock(
    id: string,
    rows: StockRow[],
    load?: LoadStockContext,
) {
    const known = rows.find((row) => String(row.medication_id) === id) ?? null;
    const [result, setResult] = useState<{
        id: string;
        row: StockRow | null;
        failed: boolean;
    } | null>(null);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!id || known || !load) return;
        const controller = new AbortController();
        setResult(null);
        load(Number(id), controller.signal)
            .then((row) => {
                if (!controller.signal.aborted)
                    setResult({ id, row, failed: false });
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setResult({ id, row: null, failed: true });
            });
        return () => controller.abort();
    }, [id, known, load, retry]);
    const matched = result?.id === id ? result : null;
    return {
        row: known ?? matched?.row ?? null,
        loading: !!id && !!load && !known && !matched,
        failed: !known && !!matched?.failed,
        retry: () => {
            setResult(null);
            setRetry((value) => value + 1);
        },
    };
}
