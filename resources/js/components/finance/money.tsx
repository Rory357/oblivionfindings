import { forwardRef } from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * en-NZ money formatting. Backend amounts are stored as `decimal(14,2)` and
 * arrive as a string ("1234.50") or number — never as integer minor units — so
 * formatting parses to a number for display only. NEVER do arithmetic on these
 * display numbers; keep money math on the server (bcmath) where it belongs.
 */
export function formatMoney(
    amount: string | number | null | undefined,
    options?: {
        currency?: string;
        /** Show the currency code suffix instead of just the symbol. */
        decimals?: number;
        /** Always render a leading + for positive values (ledger style). */
        signed?: boolean;
    },
): string {
    const currency = options?.currency ?? 'NZD';
    const decimals = options?.decimals ?? 2;
    const n =
        typeof amount === 'number'
            ? amount
            : amount == null || amount === ''
              ? 0
              : Number(amount);
    const safe = Number.isFinite(n) ? n : 0;
    const formatted = new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(safe);
    if (options?.signed && safe > 0) return `+${formatted}`;
    return formatted;
}

/** Compact money for hero KPI stats: $1.2k, $3.4m. Falls back to full format under $1k. */
export function formatMoneyCompact(
    amount: string | number | null | undefined,
    currency = 'NZD',
): string {
    const n =
        typeof amount === 'number' ? amount : Number(amount ?? 0);
    const safe = Number.isFinite(n) ? n : 0;
    if (Math.abs(safe) < 1000) return formatMoney(safe, { currency });
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(safe);
}

/**
 * Decimal-safe money input. Stays a string the whole way through (no float
 * round-trips) and only admits digits + a single decimal point with ≤2 dp, so
 * the value can be POSTed straight to a `decimal(14,2)` column.
 */
export const AmountField = forwardRef<
    HTMLInputElement,
    {
        value: string;
        onValueChange: (next: string) => void;
        currencySymbol?: string;
        placeholder?: string;
        id?: string;
        name?: string;
        disabled?: boolean;
        className?: string;
        'aria-label'?: string;
        'aria-invalid'?: boolean;
    }
>(function AmountField(
    {
        value,
        onValueChange,
        currencySymbol = '$',
        placeholder = '0.00',
        className,
        ...rest
    },
    ref,
) {
    return (
        <div className={cn('relative', className)}>
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                {currencySymbol}
            </span>
            <Input
                ref={ref}
                inputMode="decimal"
                placeholder={placeholder}
                className="pl-7 text-right tabular-nums"
                value={value}
                onChange={(e) => {
                    const raw = e.target.value;
                    // allow empty, an integer, or up to 2 decimals
                    if (raw === '' || /^\d*\.?\d{0,2}$/.test(raw)) {
                        onValueChange(raw);
                    }
                }}
                {...rest}
            />
        </div>
    );
});

/**
 * Coloured amount label — positive reads as money-in (success), negative as
 * money-out (critical), zero muted. Tokens only, never hex.
 */
export function MoneyBadge({
    amount,
    currency = 'NZD',
    signed = true,
    className,
}: {
    amount: string | number | null | undefined;
    currency?: string;
    signed?: boolean;
    className?: string;
}) {
    const n = typeof amount === 'number' ? amount : Number(amount ?? 0);
    const safe = Number.isFinite(n) ? n : 0;
    const tone =
        safe > 0
            ? 'text-status-success'
            : safe < 0
              ? 'text-status-critical'
              : 'text-muted-foreground';
    return (
        <span className={cn('font-semibold tabular-nums', tone, className)}>
            {formatMoney(safe, { currency, signed })}
        </span>
    );
}
