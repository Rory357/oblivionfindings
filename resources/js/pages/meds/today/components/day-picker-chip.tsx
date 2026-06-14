/* eslint-disable no-restricted-syntax -- hero-footer day chip on the dark
 * banner (rostering week-stepper idiom) with a calendar popover; colours are
 * semantic tokens throughout. */
import { cn } from '@/lib/utils';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/** Parse a Y-m-d string into a local Date without timezone surprises. */
export function parseYmd(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, (m ?? 1) - 1, d ?? 1);
}

export function toYmd(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function addDays(value: string, days: number): string {
    const d = parseYmd(value);
    d.setDate(d.getDate() + days);
    return toYmd(d);
}

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

/**
 * The centre chip of the hero day stepper: shows the selected day and opens a
 * month-grid popover for jumping to any date.
 *
 * The popover is rendered through a portal with `position: fixed`, anchored to
 * the trigger button — the same approach as the rostering WeekPicker — so it
 * escapes the hero footer's `overflow-hidden` (which would otherwise clip it).
 */
export function DayPickerChip({
    date,
    isToday,
    onPick,
}: {
    /** Selected day, Y-m-d. */
    date: string;
    isToday: boolean;
    onPick: (ymd: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = parseYmd(date);
    const [month, setMonth] = useState(
        () => new Date(selected.getFullYear(), selected.getMonth(), 1),
    );
    const btnRef = useRef<HTMLButtonElement | null>(null);
    const popRef = useRef<HTMLDivElement | null>(null);
    const [pos, setPos] = useState({ top: 0, left: 0 });

    // Position the fixed popover under the trigger, and keep it pinned while
    // the page scrolls/resizes.
    useLayoutEffect(() => {
        if (!open) return;
        const update = () => {
            const el = btnRef.current;
            if (!el) return;
            const r = el.getBoundingClientRect();
            // Clamp so a 272px popover never runs off the right edge.
            const left = Math.max(8, Math.min(r.left, window.innerWidth - 280));
            setPos({ top: r.bottom + 6, left });
        };
        update();
        window.addEventListener('resize', update);
        window.addEventListener('scroll', update, true);
        return () => {
            window.removeEventListener('resize', update);
            window.removeEventListener('scroll', update, true);
        };
    }, [open]);

    // Close on outside click / Escape. The popover lives in a portal, so the
    // outside test must consider BOTH the trigger and the popover.
    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            const t = e.target as Node;
            if (btnRef.current?.contains(t) || popRef.current?.contains(t)) return;
            setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const today = new Date();
    const sameDay = (a: Date, b: Date) =>
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate();

    const firstOffset = (month.getDay() + 6) % 7; // Monday-start grid
    const daysInMonth = new Date(
        month.getFullYear(),
        month.getMonth() + 1,
        0,
    ).getDate();
    const cells: (number | null)[] = [
        ...Array.from({ length: firstOffset }, () => null),
        ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
    ];

    // en-NZ renders "Wed, 10 Jun" — the design chip reads "Wed 10 Jun".
    const dayLabel = selected
        .toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        })
        .replace(',', '');
    const chipLabel = isToday ? `Today · ${dayLabel}` : dayLabel;
    const monthLabel = month.toLocaleDateString('en-NZ', {
        month: 'long',
        year: 'numeric',
    });

    return (
        <div className="relative">
            <button
                ref={btnRef}
                type="button"
                aria-haspopup="dialog"
                aria-expanded={open}
                onClick={() => {
                    setMonth(
                        new Date(selected.getFullYear(), selected.getMonth(), 1),
                    );
                    setOpen((v) => !v);
                }}
                className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-primary-foreground hover:bg-primary-foreground/30"
            >
                <CalendarDays className="h-3.5 w-3.5" />
                {chipLabel} · pick day
                <ChevronDown className="h-3 w-3" />
            </button>

            {open
                ? createPortal(
                      <div
                          ref={popRef}
                          style={{ top: pos.top, left: pos.left }}
                          className="fixed z-50 w-[272px] rounded-xl border border-border bg-card p-3 text-foreground shadow-xl"
                      >
                          <div className="mb-2 flex items-center justify-between">
                              <button
                                  type="button"
                                  aria-label="Previous month"
                                  onClick={() =>
                                      setMonth(
                                          new Date(
                                              month.getFullYear(),
                                              month.getMonth() - 1,
                                              1,
                                          ),
                                      )
                                  }
                                  className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                              >
                                  <ChevronLeft className="h-4 w-4" />
                              </button>
                              <span className="text-sm font-bold">{monthLabel}</span>
                              <button
                                  type="button"
                                  aria-label="Next month"
                                  onClick={() =>
                                      setMonth(
                                          new Date(
                                              month.getFullYear(),
                                              month.getMonth() + 1,
                                              1,
                                          ),
                                      )
                                  }
                                  className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                              >
                                  <ChevronRight className="h-4 w-4" />
                              </button>
                          </div>
                          <div className="grid grid-cols-7 gap-0.5 text-center text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                              {WEEKDAYS.map((d) => (
                                  <span key={d} className="py-1">
                                      {d}
                                  </span>
                              ))}
                          </div>
                          <div className="grid grid-cols-7 gap-0.5">
                              {cells.map((d, i) =>
                                  d == null ? (
                                      <span key={`empty-${i}`} />
                                  ) : (
                                      (() => {
                                          const cellDate = new Date(
                                              month.getFullYear(),
                                              month.getMonth(),
                                              d,
                                          );
                                          const isSelected = sameDay(
                                              cellDate,
                                              selected,
                                          );
                                          const isPast =
                                              cellDate < today &&
                                              !sameDay(cellDate, today);
                                          return (
                                              <button
                                                  key={d}
                                                  type="button"
                                                  aria-current={
                                                      isSelected ? 'date' : undefined
                                                  }
                                                  onClick={() => {
                                                      setOpen(false);
                                                      if (!isSelected)
                                                          onPick(toYmd(cellDate));
                                                  }}
                                                  className={cn(
                                                      'grid h-8 place-items-center rounded-md text-[13px] tabular-nums transition-colors',
                                                      isSelected
                                                          ? 'bg-primary font-bold text-primary-foreground'
                                                          : 'hover:bg-accent',
                                                      !isSelected &&
                                                          isPast &&
                                                          'text-muted-foreground',
                                                      !isSelected &&
                                                          sameDay(cellDate, today) &&
                                                          'font-bold text-primary',
                                                  )}
                                              >
                                                  {d}
                                              </button>
                                          );
                                      })()
                                  ),
                              )}
                          </div>
                          <p className="mt-2 border-t border-border pt-2 text-[11px] text-muted-foreground">
                              Doses shown are for the selected day. Stock &amp; CD
                              checks always show today.
                          </p>
                      </div>,
                      document.body,
                  )
                : null}
        </div>
    );
}

export default DayPickerChip;
