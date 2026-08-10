import { Check, TriangleAlert } from 'lucide-react';

import { cn } from '@/lib/utils';
import { formatMoney } from './money';

export type PostingLine = {
    accountCode?: string;
    accountName: string;
    /** Debit amount as a decimal string or number (decimal(14,2)). */
    debit?: string | number | null;
    /** Credit amount as a decimal string or number (decimal(14,2)). */
    credit?: string | number | null;
    memo?: string;
};

/** Decimal-safe (cents-integer) sum so floating point can't break the balance check. */
function sumCents(lines: PostingLine[], side: 'debit' | 'credit'): number {
    return lines.reduce((acc, line) => {
        const v = line[side];
        const n = typeof v === 'number' ? v : Number(v ?? 0);
        return acc + Math.round((Number.isFinite(n) ? n : 0) * 100);
    }, 0);
}

/** Returns totals + whether debits == credits (to the cent). */
export function journalBalance(lines: PostingLine[]): {
    debit: number;
    credit: number;
    balanced: boolean;
    difference: number;
} {
    const debit = sumCents(lines, 'debit');
    const credit = sumCents(lines, 'credit');
    return {
        debit: debit / 100,
        credit: credit / 100,
        balanced: debit === credit && debit > 0,
        difference: (debit - credit) / 100,
    };
}

/**
 * Double-entry posting preview — the shared finance card that shows exactly
 * which GL lines a workflow will post, with a live debits-vs-credits balance
 * check. Use in New Journal, the payroll-post review step, period-close, etc.
 * so every "this will post to the GL" surface looks identical.
 */
export function PostingPreview({
    lines,
    currency = 'NZD',
    title = 'Journal preview',
    className,
}: {
    lines: PostingLine[];
    currency?: string;
    title?: string;
    className?: string;
}) {
    const totals = journalBalance(lines);
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-border bg-card',
                className,
            )}
        >
            <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
                <span className="text-sm font-semibold">{title}</span>
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                        totals.balanced
                            ? 'bg-status-success-bg text-status-success'
                            : 'bg-status-warning-bg text-status-warning',
                    )}
                >
                    {totals.balanced ? (
                        <Check className="h-3 w-3" />
                    ) : (
                        <TriangleAlert className="h-3 w-3" />
                    )}
                    {totals.balanced ? 'Balanced' : 'Out of balance'}
                </span>
            </div>
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-xs text-muted-foreground">
                        <th className="px-4 py-1.5 text-left font-medium">
                            Account
                        </th>
                        <th className="px-4 py-1.5 text-right font-medium">
                            Debit
                        </th>
                        <th className="px-4 py-1.5 text-right font-medium">
                            Credit
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {lines.map((line, i) => (
                        <tr key={i} className="border-t border-border/60">
                            <td className="px-4 py-1.5">
                                <span className="font-medium">
                                    {line.accountCode
                                        ? `${line.accountCode} · `
                                        : ''}
                                    {line.accountName}
                                </span>
                                {line.memo ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {line.memo}
                                    </span>
                                ) : null}
                            </td>
                            <td className="px-4 py-1.5 text-right tabular-nums">
                                {line.debit
                                    ? formatMoney(line.debit, { currency })
                                    : ''}
                            </td>
                            <td className="px-4 py-1.5 text-right tabular-nums">
                                {line.credit
                                    ? formatMoney(line.credit, { currency })
                                    : ''}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t border-border bg-muted/40 font-semibold">
                        <td className="px-4 py-1.5 text-right text-xs text-muted-foreground uppercase">
                            Totals
                        </td>
                        <td className="px-4 py-1.5 text-right tabular-nums">
                            {formatMoney(totals.debit, { currency })}
                        </td>
                        <td className="px-4 py-1.5 text-right tabular-nums">
                            {formatMoney(totals.credit, { currency })}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

export default PostingPreview;
