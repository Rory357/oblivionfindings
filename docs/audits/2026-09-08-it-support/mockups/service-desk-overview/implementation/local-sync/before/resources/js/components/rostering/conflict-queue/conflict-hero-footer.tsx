import { router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Zap,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import {
    EntityFilter,
    type EntityFilterOption,
    WeekPicker,
    addDaysWP,
    startOfWeek,
    weekLabel,
} from '@/components/rostering';
import { cn } from '@/lib/utils';

const PILL =
    'inline-flex h-[34px] items-center gap-1 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/15';

function ymd(date: Date) {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

export interface ConflictHeroFooterProps {
    weekStart: string;
    staffOptions: EntityFilterOption[];
    siteOptions: EntityFilterOption[];
    staffFilterValue: number | null;
    siteFilterValue: number | null;
    onStaffFilter: (id: number | null) => void;
    onSiteFilter: (id: number | null) => void;
    onResolveNext: () => void;
    resolveDisabled: boolean;
}

/** Hero footer: week picker (left) + staff/site filters and Resolve next (right). */
export function ConflictHeroFooter({
    weekStart,
    staffOptions,
    siteOptions,
    staffFilterValue,
    siteFilterValue,
    onStaffFilter,
    onSiteFilter,
    onResolveNext,
    resolveDisabled,
}: ConflictHeroFooterProps) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const anchorRef = useRef<HTMLButtonElement>(null);

    const weekStartDate = useMemo(
        () => startOfWeek(new Date(`${weekStart}T00:00:00`)),
        [weekStart],
    );
    const curLab = weekLabel(weekStartDate);
    const prevLab = weekLabel(addDaysWP(weekStartDate, -7));
    const nextLab = weekLabel(addDaysWP(weekStartDate, 7));
    const compactRange = `${weekStartDate.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    })} → ${addDaysWP(weekStartDate, 6).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    })}`;

    const goToWeek = (date: Date) => {
        router.get(
            '/operations/rostering/conflicts',
            { week: ymd(date) },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                {/* eslint-disable no-restricted-syntax -- translucent hero-surface pill buttons (custom layout), matching the rostering index hero footer. */}
                <button
                    type="button"
                    className={PILL}
                    onClick={() => goToWeek(addDaysWP(weekStartDate, -7))}
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    {prevLab}
                </button>
                <button
                    ref={anchorRef}
                    type="button"
                    className={cn(
                        PILL,
                        'border-primary-foreground/35 bg-primary-foreground/20 hover:bg-primary-foreground/30',
                    )}
                    aria-haspopup="dialog"
                    aria-expanded={pickerOpen}
                    onClick={() => setPickerOpen((open) => !open)}
                >
                    <CalendarRange className="h-3.5 w-3.5" />
                    {curLab} · {compactRange} · pick week
                    <ChevronDown className="h-3 w-3" />
                </button>
                <button
                    type="button"
                    className={PILL}
                    onClick={() => goToWeek(addDaysWP(weekStartDate, 7))}
                >
                    {nextLab}
                    <ChevronRight className="h-3.5 w-3.5" />
                </button>
                {/* eslint-enable no-restricted-syntax */}
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
                <EntityFilter
                    onDark
                    label="Staff"
                    pluralLabel="staff"
                    allLabel="All staff"
                    items={staffOptions}
                    value={staffFilterValue}
                    onChange={onStaffFilter}
                />
                <EntityFilter
                    onDark
                    label="Site"
                    allLabel="All sites"
                    items={siteOptions}
                    value={siteFilterValue}
                    onChange={onSiteFilter}
                />
                {/* eslint-disable-next-line no-restricted-syntax -- primary hero-surface "Resolve next" pill (inverse colours on the gradient). */}
                <button
                    type="button"
                    disabled={resolveDisabled}
                    onClick={onResolveNext}
                    className="inline-flex h-[34px] items-center gap-1.5 rounded-full bg-primary-foreground px-3.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-foreground/90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Zap className="h-4 w-4" strokeWidth={2.25} />
                    <span>Resolve next</span>
                    <ArrowRight className="h-3.5 w-3.5" strokeWidth={2.25} />
                </button>
            </div>

            {pickerOpen ? (
                <WeekPicker
                    selectedWeekStart={weekStartDate}
                    anchorRef={anchorRef}
                    onSelect={(week) => {
                        setPickerOpen(false);
                        goToWeek(week);
                    }}
                    onClose={() => setPickerOpen(false)}
                />
            ) : null}
        </div>
    );
}

export default ConflictHeroFooter;
