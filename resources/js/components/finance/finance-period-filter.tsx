import { router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PageHeaderFilterButton } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * The reporting-period pill for the Overview hub's three period-aware views
 * (Executive, By site, and the site drill-down). One control, one shape: the
 * header filter row never grows a second date widget per page, and the three
 * views re-query with the same `?from=&to=` contract their controllers already
 * validate.
 */

const iso = (date: Date) => {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const shortDate = (value: string) => {
    if (!value) return '';
    const parsed = new Date(`${value}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return value;

    return parsed.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
    });
};

/** NZ financial year runs 1 April → 31 March. */
const financialYear = (today: Date) => {
    const startYear =
        today.getMonth() >= 3 ? today.getFullYear() : today.getFullYear() - 1;

    return {
        from: iso(new Date(startYear, 3, 1)),
        to: iso(new Date(startYear + 1, 2, 31)),
    };
};

export type FinancePeriodPreset = {
    key: string;
    label: string;
    range: () => { from: string; to: string };
};

export const FINANCE_PERIOD_PRESETS: FinancePeriodPreset[] = [
    {
        key: 'this-month',
        label: 'This month',
        range: () => {
            const now = new Date();

            return {
                from: iso(new Date(now.getFullYear(), now.getMonth(), 1)),
                to: iso(now),
            };
        },
    },
    {
        key: 'last-month',
        label: 'Last month',
        range: () => {
            const now = new Date();

            return {
                from: iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)),
                to: iso(new Date(now.getFullYear(), now.getMonth(), 0)),
            };
        },
    },
    {
        key: 'this-quarter',
        label: 'This quarter',
        range: () => {
            const now = new Date();
            const quarterStart = Math.floor(now.getMonth() / 3) * 3;

            return {
                from: iso(new Date(now.getFullYear(), quarterStart, 1)),
                to: iso(now),
            };
        },
    },
    {
        key: 'financial-year',
        label: 'Financial year',
        range: () => financialYear(new Date()),
    },
];

export function FinancePeriodFilter({
    url,
    from,
    to,
    idPrefix = 'finance-period',
    onApply,
}: {
    /** The page's own URL — the range re-queries in place. */
    url: string;
    from: string;
    to: string;
    idPrefix?: string;
    /**
     * Pages whose date range is one of several filters (a list with status and
     * type pills) pass their own apply so the rest of the query survives the
     * re-query; without it the pill re-queries `url` with `?from=&to=` alone.
     */
    onApply?: (range: { from: string; to: string }) => void;
}) {
    const [open, setOpen] = useState(false);
    const [range, setRange] = useState({ from, to });

    // Keep the popover fields in step with a range applied elsewhere (a preset,
    // the back button, or a meter that re-queries the page).
    useEffect(() => setRange({ from, to }), [from, to]);

    const apply = (next: { from: string; to: string }) => {
        setOpen(false);
        if (onApply) {
            onApply(next);
            return;
        }
        router.get(
            url,
            { from: next.from, to: next.to },
            { preserveScroll: true, preserveState: false },
        );
    };

    const dated = Boolean(from || to);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    icon={CalendarRange}
                    active={dated}
                    aria-label="Change the reporting period"
                >
                    {dated
                        ? `${shortDate(from) || 'Earliest'} – ${shortDate(to) || 'Today'}`
                        : 'Any dates'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64">
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(range);
                    }}
                >
                    <div className="flex flex-wrap gap-1.5">
                        {FINANCE_PERIOD_PRESETS.map((preset) => (
                            <Button
                                key={preset.key}
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-xs"
                                onClick={() => apply(preset.range())}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={`${idPrefix}-from`}>From</Label>
                        <Input
                            id={`${idPrefix}-from`}
                            type="date"
                            value={range.from}
                            onChange={(event) =>
                                setRange((current) => ({
                                    ...current,
                                    from: event.target.value,
                                }))
                            }
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={`${idPrefix}-to`}>To</Label>
                        <Input
                            id={`${idPrefix}-to`}
                            type="date"
                            value={range.to}
                            onChange={(event) =>
                                setRange((current) => ({
                                    ...current,
                                    to: event.target.value,
                                }))
                            }
                        />
                    </div>
                    <Button type="submit" size="sm">
                        Show these dates
                    </Button>
                </form>
            </PopoverContent>
        </Popover>
    );
}

export default FinancePeriodFilter;
