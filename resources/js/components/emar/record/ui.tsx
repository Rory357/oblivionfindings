import { LockKeyhole, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { cn } from '@/lib/utils';

/** Controlled-medicine concealment (P11 v4 wording, P02 build note 5). */
export const CONCEALED = {
    name: 'Controlled medicine',
    subline: 'Details need controlled-medicine access',
    ask: 'The house lead can tell you more.',
};

/** The identity a redacted row shows in an EntityTable. */
export const concealedIdentity = {
    icon: LockKeyhole,
    name: CONCEALED.name,
    subline: CONCEALED.subline,
};

/**
 * How many controlled rows this reader doesn't see in full. `listed`: the
 * rows are shown redacted (inside the record); otherwise left out (lists
 * across people).
 */
export function ConcealedCaption({
    n,
    listed = true,
}: {
    n: number;
    listed?: boolean;
}) {
    if (!n) return null;
    return (
        <span className="inline-flex items-center gap-1">
            <LockKeyhole className="size-3" aria-hidden="true" />
            {listed
                ? `${n} controlled — details hidden (needs controlled-medicine access)`
                : `${n} controlled not shown — needs controlled-medicine access`}
        </span>
    );
}

/** A long identity subline that wraps inside EntityTable's subline slot. */
export const Wrap = ({ children }: { children: ReactNode }) => (
    <span className="block whitespace-normal">{children}</span>
);

/** A titled body card (the Fleet record's body cards). */
export function SectionCard({
    eyebrow,
    title,
    right,
    children,
    className,
    icon: Icon,
}: {
    eyebrow?: string;
    title: ReactNode;
    right?: ReactNode;
    children: ReactNode;
    className?: string;
    icon?: LucideIcon;
}) {
    return (
        <Card className={cn('gap-3 p-5', className)}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    {Icon ? (
                        <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-primary/10 text-primary">
                            <Icon className="size-4" aria-hidden="true" />
                        </span>
                    ) : null}
                    <div className="min-w-0">
                        {eyebrow ? (
                            <p className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                {eyebrow}
                            </p>
                        ) : null}
                        <h2 className="text-section-title">{title}</h2>
                    </div>
                </div>
                {right ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {right}
                    </div>
                ) : null}
            </div>
            {children}
        </Card>
    );
}

/** A small key/value facts row (the Fleet record's footer facts). */
export function FactStrip({
    items,
}: {
    items: { label: string; value: ReactNode }[];
}) {
    return (
        <div className="grid divide-y rounded-lg border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {items.map((item) => (
                <div key={item.label} className="px-3 py-2.5">
                    <span className="block text-[11px] text-muted-foreground">
                        {item.label}
                    </span>
                    <span className="block text-[13px] font-semibold">
                        {item.value}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * "18 Aug 2026" for a calendar date (Y-m-d) or an instant (its NZ day) —
 * one short form for every date on the record.
 */
export function recordDate(value: string | null | undefined): string {
    if (!value) return '—';
    return formatDateOnly(value.length === 10 ? value : toDateInput(value));
}
