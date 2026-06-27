/* eslint-disable no-restricted-syntax -- Portal-positioned popover with custom
 * on-surface action buttons (mirrors the rostering context-menu pattern); these
 * are intentional raw <button>/<div> layout cases, token-coloured throughout. */
import { CalendarClock, Copy, ExternalLink, MapPin, Pencil, Trash2, Users } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties } from 'react';
import { createPortal } from 'react-dom';

import { LAYER_META, type CalendarLayer } from '@/lib/calendar/layer-feed';

export interface EventDetail {
    x: number;
    y: number;
    id: string;
    title: string;
    start: string | null;
    end: string | null;
    allDay: boolean;
    layer: CalendarLayer;
    deepLink?: string | null;
    props: Record<string, unknown>;
}

const DEEP_LINK_LABEL: Partial<Record<CalendarLayer, string>> = {
    leave: 'Open in Leave',
    shift: 'Open in Rostering',
    compliance: 'Open in Compliance',
    milestone: 'Open profile',
};

function formatWhen(start: string | null, end: string | null, allDay: boolean): string {
    if (!start) return '';
    const fmt = (v: string) =>
        new Date(v).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            ...(allDay ? {} : { hour: 'numeric', minute: '2-digit' }),
        });
    const a = fmt(start);
    const b = end ? fmt(end) : '';
    return b && b !== a ? `${a} – ${b}` : a;
}

/**
 * Detail popover for any calendar entry. Read-only layers show a deep-link to
 * their real editor; HR events (when the viewer can manage) show Edit / Duplicate
 * / Delete. Positioned at the click point, viewport-clamped, closes on Esc /
 * outside-click — mirrors the rostering context menu.
 */
export function CalendarDetailPopover({
    detail,
    canManage,
    onClose,
    onEdit,
    onDuplicate,
    onDelete,
    onDeepLink,
}: {
    detail: EventDetail;
    canManage: boolean;
    onClose: () => void;
    onEdit: () => void;
    onDuplicate: () => void;
    onDelete: () => void;
    onDeepLink: (href: string) => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<CSSProperties>({ left: detail.x, top: detail.y });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let left = detail.x;
        let top = detail.y;
        if (left + r.width > window.innerWidth - 8) left = window.innerWidth - r.width - 8;
        if (top + r.height > window.innerHeight - 8) top = window.innerHeight - r.height - 8;
        setPos({ left: Math.max(8, left), top: Math.max(8, top) });
    }, [detail.x, detail.y]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        window.addEventListener('keydown', onKey);
        window.addEventListener('mousedown', onDown);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('mousedown', onDown);
        };
    }, [onClose]);

    const meta = LAYER_META[detail.layer];
    const isEvent = detail.layer === 'event';
    const location = detail.props.location as string | undefined;
    const person = detail.props.person as string | undefined;
    const attendeeCount = detail.props.attendeeCount as number | undefined;
    const redacted = detail.props.redacted as boolean | undefined;

    return createPortal(
        <div
            ref={ref}
            style={{ position: 'fixed', ...pos, zIndex: 60 }}
            className="w-[280px] rounded-xl border border-border bg-popover p-3 shadow-[var(--shadow-float)]"
        >
            <div className="mb-1.5 flex items-center gap-2">
                <span className="h-2.5 w-2.5 flex-none rounded-full" style={{ background: `var(--${meta.color})` }} />
                <span className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{meta.label}</span>
            </div>
            <div className="text-[15px] font-bold leading-tight">{detail.title}</div>

            <div className="mt-2 space-y-1 text-[12.5px] text-muted-foreground">
                <div className="flex items-center gap-1.5">
                    <CalendarClock className="h-3.5 w-3.5 flex-none" />
                    {formatWhen(detail.start, detail.end, detail.allDay)}
                </div>
                {location ? (
                    <div className="flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5 flex-none" />
                        {location}
                    </div>
                ) : null}
                {person ? (
                    <div className="flex items-center gap-1.5">
                        <Users className="h-3.5 w-3.5 flex-none" />
                        {person}
                    </div>
                ) : null}
                {isEvent && attendeeCount ? (
                    <div className="flex items-center gap-1.5">
                        <Users className="h-3.5 w-3.5 flex-none" />
                        {attendeeCount} invited
                    </div>
                ) : null}
                {redacted ? <div className="italic">Reason hidden</div> : null}
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
                {isEvent && canManage ? (
                    <>
                        <button type="button" onClick={onEdit} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-2.5 py-1.5 text-[12px] font-semibold text-primary-foreground hover:brightness-95">
                            <Pencil className="h-3.5 w-3.5" /> Edit
                        </button>
                        <button type="button" onClick={onDuplicate} className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-[12px] font-semibold hover:bg-muted">
                            <Copy className="h-3.5 w-3.5" /> Duplicate
                        </button>
                        <button type="button" onClick={onDelete} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12px] font-semibold text-status-critical hover:bg-status-critical-bg">
                            <Trash2 className="h-3.5 w-3.5" /> Delete
                        </button>
                    </>
                ) : detail.deepLink ? (
                    <button type="button" onClick={() => onDeepLink(detail.deepLink as string)} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-2.5 py-1.5 text-[12px] font-semibold text-primary-foreground hover:brightness-95">
                        <ExternalLink className="h-3.5 w-3.5" /> {DEEP_LINK_LABEL[detail.layer] ?? 'Open'}
                    </button>
                ) : null}
            </div>
        </div>,
        document.body,
    );
}

export default CalendarDetailPopover;
