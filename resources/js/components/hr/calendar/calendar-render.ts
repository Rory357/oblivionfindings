/**
 * Shared rendering helpers for the `/hr/calendar` views (month grid, week/day
 * time-grid, agenda). Lifted 1:1 from the design prototype so every surface uses
 * the same date maths, colour tokens and chip/bar styling. Colours are token
 * references resolved via `var(--token)` + `color-mix()` — never raw hex.
 */
import { type CSSProperties } from 'react';

import { LAYER_META, type CalendarLayerFeed } from '@/lib/calendar/layer-feed';

/* ── date helpers (Monday-first, en-NZ) ──────────────────────────────────── */

export function isoKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
export function addDays(d: Date, n: number): Date {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
}
export function startOfWeek(d: Date): Date {
    const x = new Date(d);
    const dow = (x.getDay() + 6) % 7;
    x.setDate(x.getDate() - dow);
    x.setHours(0, 0, 0, 0);
    return x;
}
export function dayStart(d: Date): Date {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x;
}
export function sameDay(a: Date, b: Date): boolean {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}
export function fmtTime(d: Date): string {
    const h = d.getHours();
    const m = d.getMinutes();
    const ap = h < 12 ? 'am' : 'pm';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return m === 0
        ? `${h12}${ap}`
        : `${h12}:${String(m).padStart(2, '0')}${ap}`;
}
export function fmtDayMon(d: Date): string {
    return d.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}
export function fmtLong(d: Date): string {
    return d.toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

/* ── feed accessors ──────────────────────────────────────────────────────── */

/** Parsed start/end Dates for a feed row. */
export function range(e: CalendarLayerFeed): { start: Date; end: Date } {
    const start = new Date(e.start);
    const end = e.end ? new Date(e.end) : start;
    return { start, end };
}

/** The accent colour token for a feed row, as a CSS value. */
export function colorVar(e: CalendarLayerFeed): string {
    return `var(--${e.color})`;
}

export function layerLabel(e: CalendarLayerFeed): string {
    return LAYER_META[e.layer]?.label ?? '';
}

/** Where a read-only row opens, as a short verb ("Leave", "Rostering"…). */
export function deepName(e: CalendarLayerFeed): string {
    const link = e.deepLink ?? '';
    if (link.includes('rostering')) return 'Rostering';
    if (link.includes('leave')) return 'Leave';
    if (link.includes('compliance')) return 'Compliance';
    return 'its hub';
}

export function secondaryFor(e: CalendarLayerFeed): string {
    const p = e.extendedProps;
    return (
        (p.location as string) ||
        (p.person as string) ||
        (p.requirement as string) ||
        (p.client as string) ||
        ''
    );
}

/* ── style builders (mirror the prototype) ──────────────────────────────── */

/** A spanning all-day bar (month grid) / all-day chip (time-grid). */
export function barStyle(
    c: string,
    dashed: boolean,
    extra?: CSSProperties,
): CSSProperties {
    return {
        display: 'flex',
        alignItems: 'center',
        gap: 6,
        width: '100%',
        borderRadius: 7,
        border: `1px ${dashed ? 'dashed' : 'solid'} color-mix(in oklch, ${c} 35%, transparent)`,
        background: `color-mix(in oklch, ${c} 14%, var(--card))`,
        color: 'var(--foreground)',
        padding: '2px 7px',
        fontSize: 11,
        fontWeight: 600,
        lineHeight: 1.5,
        cursor: 'pointer',
        textAlign: 'left',
        ...extra,
    };
}

export function dotStyle(c: string): CSSProperties {
    return {
        height: 7,
        width: 7,
        flex: 'none',
        borderRadius: 2,
        background: c,
    };
}
