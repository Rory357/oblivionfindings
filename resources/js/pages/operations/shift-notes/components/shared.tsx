/* Shared types, helpers and small UI primitives for the Shift Notes page. */
import { avatarHueStyle } from '@/components/rostering/avatar-hue';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    ArrowLeftRight,
    NotebookPen,
    PenLine,
    TrendingUp,
    type LucideIcon,
} from 'lucide-react';

export type NoteType =
    | 'shift_note'
    | 'progress_note'
    | 'handover'
    | 'incident'
    | 'note';

export type NoteStaff = { id: number; name: string };
export type NoteClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id: number | null;
};
export type NoteSite = { id: number; name: string };
export type NoteShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    shift_type: string | null;
    label: string;
};
export type NoteLock = {
    locked: boolean;
    reason: string;
    days_left: number | null;
    age_days: number | null;
};

export type ShiftNote = {
    id: number;
    type: string;
    body: string;
    subject: string | null;
    is_flagged: boolean;
    flagged_reason: string | null;
    is_private: boolean;
    reviewed_at: string | null;
    reviewer: NoteStaff | null;
    edited_at: string | null;
    editor: NoteStaff | null;
    created_at: string | null;
    user: NoteStaff | null;
    client: NoteClient | null;
    site: NoteSite | null;
    shift: NoteShift | null;
    can_edit: boolean;
    lock: NoteLock;
};

export type CatalogueClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id: number | null;
};
export type CatalogueStaff = {
    id: number;
    name: string;
    email?: string | null;
    role?: string | null;
};
export type CatalogueSite = { id: number; name: string };
export type CatalogueShift = {
    id: number;
    client_id: number;
    site_id: number | null;
    user_id: number | null;
    shift_type: string | null;
    label: string;
    starts_at: string | null;
    ends_at: string | null;
    staff: { id: number; name: string } | null;
};
export type Catalogue = {
    clients: CatalogueClient[];
    staff: CatalogueStaff[];
    sites: CatalogueSite[];
    shifts: CatalogueShift[];
};

export type ViewMode = 'cards' | 'list';
export type StatusTab = 'all' | 'flagged' | 'awaiting' | 'reviewed';
export type Filters = {
    client: number | null;
    staff: number | null;
    type: NoteType | null;
};

/** Note-type metadata. Colours are brand-distinct category hues (no semantic
 *  token exists for them) — applied via inline style for the badge + card edge. */
export const TYPE_META: Record<
    NoteType,
    { label: string; color: string; icon: LucideIcon; desc: string }
> = {
    shift_note: {
        label: 'Shift Note',
        color: '#64748b',
        icon: NotebookPen,
        desc: 'End-of-shift summary of how the shift went.',
    },
    progress_note: {
        label: 'Progress Note',
        color: '#6366f1',
        icon: TrendingUp,
        desc: 'Progress against a goal or support plan.',
    },
    handover: {
        label: 'Handover',
        color: '#3b82f6',
        icon: ArrowLeftRight,
        desc: 'Pass key info to the incoming worker.',
    },
    incident: {
        label: 'Incident',
        color: '#ef4444',
        icon: AlertTriangle,
        desc: 'Something went wrong — links to an incident.',
    },
    note: {
        label: 'General',
        color: '#10b981',
        icon: PenLine,
        desc: 'General observation or note for the record.',
    },
};

export const NOTE_TYPES = Object.keys(TYPE_META) as NoteType[];

export function typeMeta(type: string) {
    return TYPE_META[type as NoteType] ?? TYPE_META.shift_note;
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0]!)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function hashHue(name: string): number {
    let h = 0;
    for (let i = 0; i < name.length; i++)
        h = (h * 31 + name.charCodeAt(i)) % 360;
    return h;
}

/** Local YYYY-MM-DD key — matches the rostering WeekPicker's day keying. */
export function ymd(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

export function clientName(
    client: { first_name: string; last_name: string } | null | undefined,
): string {
    if (!client) return 'Unknown person';
    return `${client.first_name} ${client.last_name}`.trim();
}

/** The calendar day a note belongs to — its documented shift's start, falling
 *  back to the created timestamp. Used for day grouping, the week strip and the
 *  week filter (mirrors the server-side week scope). */
export function noteDate(note: ShiftNote): Date {
    const iso = note.shift?.starts_at ?? note.created_at;
    return iso ? new Date(iso) : new Date();
}

/** Day vs night support, inferred from the shift start hour (mirrors the design). */
export function shiftRole(shift: NoteShift | null | undefined): string {
    if (!shift?.starts_at) return 'Support';
    const h = new Date(shift.starts_at).getHours();
    return h >= 22 || h < 6 ? 'Night support' : 'Day support';
}

/** 12-hour clock without a space before am/pm, e.g. "7:00am" (design spec). */
export function fmtClock(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    const mm = String(d.getMinutes()).padStart(2, '0');
    const ap = d.getHours() < 12 ? 'am' : 'pm';
    const h = d.getHours() % 12 || 12;
    return `${h}:${mm}${ap}`;
}

/** Shift chip label, e.g. "Mon 7:00am–3:00pm". */
export function fmtShiftChip(shift: NoteShift | null | undefined): string {
    if (!shift?.starts_at) return shift?.label ?? '';
    const day = new Date(shift.starts_at).toLocaleDateString('en-NZ', {
        weekday: 'short',
    });
    return `${day} ${fmtClock(shift.starts_at)}–${fmtClock(shift.ends_at)}`;
}

export function relTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 0) return 'just now';
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    const days = Math.floor(diff / 86400);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

export function fmtDayShort(d: Date): string {
    return d.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

export function fmtDateLong(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

// ---- shared UI ------------------------------------------------------------
export function HueAvatar({
    name,
    size = 38,
    className,
}: {
    name: string;
    size?: number;
    className?: string;
}) {
    const style = avatarHueStyle(hashHue(name));
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
                className,
            )}
            style={{
                width: size,
                height: size,
                fontSize: size < 30 ? 10 : 12,
                ...style,
            }}
            aria-hidden="true"
        >
            {initials(name)}
        </span>
    );
}

/** Solid, type-coloured badge (white text) used on cards, lists and the popup. */
export function TypeBadge({
    type,
    className,
}: {
    type: string;
    className?: string;
}) {
    const meta = typeMeta(type);
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-semibold text-white',
                className,
            )}
            style={{ backgroundColor: meta.color }}
        >
            {meta.label}
        </span>
    );
}

/** Status (reviewed / flagged / awaiting) tab matcher — also used for counts. */
export function matchesTab(note: ShiftNote, tab: StatusTab): boolean {
    if (tab === 'all') return true;
    if (tab === 'flagged') return note.is_flagged;
    if (tab === 'awaiting') return !note.reviewed_at;
    return !!note.reviewed_at;
}
