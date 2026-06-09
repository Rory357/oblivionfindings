/* Shared types, helpers and small UI primitives for the Shift Handovers page. */
import { avatarHueStyle } from '@/components/rostering/avatar-hue';
import { cn } from '@/lib/utils';

export type HandoverStatus = 'draft' | 'submitted' | 'acknowledged';

export type HandoverStaff = { id: number; name: string; role?: string | null };
export type HandoverClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id: number | null;
};
export type HandoverSite = { id: number; name: string };
export type HandoverShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    shift_type: string | null;
    label: string;
};
export type HandoverLock = {
    locked: boolean;
    reason: string;
    days_left: number | null;
    age_days: number | null;
};

export type Handover = {
    id: number;
    status: HandoverStatus | string;
    handover_notes: string;
    client_mood: string | null;
    medications_due: string[];
    incidents_to_note: string[];
    follow_up_items: string[];
    tasks_pending: string[];
    created_at: string | null;
    submitted_at: string | null;
    acknowledged_at: string | null;
    client: HandoverClient | null;
    site: HandoverSite | null;
    outgoing_staff: HandoverStaff | null;
    incoming_staff: HandoverStaff | null;
    acknowledger: { id: number; name: string } | null;
    outgoing_shift: HandoverShift | null;
    incoming_shift: HandoverShift | null;
    can_submit: boolean;
    can_acknowledge: boolean;
    can_edit: boolean;
    lock: HandoverLock;
};

export type CatalogueClient = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id: number | null;
    site_id: number | null;
};
export type CatalogueStaff = {
    id: number;
    name: string;
    email?: string | null;
    role?: string | null;
};
export type CatalogueSite = { id: number; name: string };
export type CatalogueServiceContext = {
    id: number;
    name: string;
    type: string | null;
};
export type CatalogueShift = {
    id: number;
    client_id: number;
    site_id: number | null;
    user_id: number | null;
    service_context_id: number | null;
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
    serviceContexts: CatalogueServiceContext[];
    shifts: CatalogueShift[];
};

export type ViewMode = 'cards' | 'list' | 'board';
export type StatusTab = 'all' | 'draft' | 'submitted' | 'acknowledged';
export type Filters = {
    staff: number | null;
    client: number | null;
    site: number | null;
};

export const MOODS = [
    'Settled',
    'Bright',
    'Anxious',
    'Withdrawn',
    'Unsettled',
    'Content',
] as const;

export const MOOD_EMOJI: Record<string, string> = {
    Settled: '😌',
    Bright: '😊',
    Anxious: '😟',
    Withdrawn: '😶',
    Unsettled: '😣',
    Content: '🙂',
};

export function moodEmoji(mood: string | null | undefined): string {
    if (!mood) return '🙂';
    return MOOD_EMOJI[mood] ?? '🙂';
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
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
    return h;
}

/** Local YYYY-MM-DD key (the rostering barrel only re-exports this aliased). */
export function ymd(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

export function clientName(
    client: { first_name: string; last_name: string } | null | undefined,
): string {
    if (!client) return 'Unknown client';
    return `${client.first_name} ${client.last_name}`.trim();
}

export function humanizeRole(role: string | null | undefined): string | null {
    if (!role) return null;
    return role
        .split(/[_\s]+/)
        .filter(Boolean)
        .map((w) => w[0]!.toUpperCase() + w.slice(1))
        .join(' ');
}

export function fmtTime(iso: string | null | undefined): string {
    if (!iso) return '--:--';
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

export function fmtShiftRange(
    shift: HandoverShift | CatalogueShift | null | undefined,
): string {
    if (!shift) return '';
    return `${fmtTime(shift.starts_at)}–${fmtTime(shift.ends_at)}`;
}

export function relTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
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

/** The calendar day a handover belongs to — its shift date, falling back to the
 *  created timestamp. Used for day grouping, the week strip and week filtering. */
export function handoverDate(h: Handover): Date {
    const iso = h.outgoing_shift?.starts_at ?? h.created_at;
    return iso ? new Date(iso) : new Date();
}

export function statusLabel(status: string): string {
    if (status === 'acknowledged') return 'Acknowledged';
    if (status === 'submitted') return 'Submitted';
    if (status === 'draft') return 'Draft';
    return status;
}

/** Counts surfaced on cards/rows. "Meds due" mirrors the prototype: items whose
 *  text mentions "due" (the rest are already-given, signed entries). */
export function cardCounts(h: Handover) {
    return {
        meds: (h.medications_due ?? []).filter((m) => /due/i.test(m)).length,
        medsTotal: (h.medications_due ?? []).length,
        incidents: (h.incidents_to_note ?? []).length,
        followups: (h.follow_up_items ?? []).length,
        tasks: (h.tasks_pending ?? []).length,
    };
}

// ---- shift-chain helpers (wizard) -----------------------------------------
export function clientShiftsSorted(
    shifts: CatalogueShift[],
    clientId: number | string | null,
): CatalogueShift[] {
    if (!clientId) return [];
    const cid = Number(clientId);
    return shifts
        .filter((s) => s.client_id === cid && s.starts_at)
        .slice()
        .sort(
            (a, b) =>
                new Date(a.starts_at!).getTime() -
                new Date(b.starts_at!).getTime(),
        );
}

/** Immediate next shift for the same client that starts at/after the outgoing
 *  shift ends — the auto-suggested incoming shift. */
export function nextShiftIdAfter(
    shifts: CatalogueShift[],
    clientId: number | string | null,
    outgoingId: number | string | null,
): string {
    if (!clientId || !outgoingId) return '';
    const list = clientShiftsSorted(shifts, clientId);
    const out = list.find((x) => String(x.id) === String(outgoingId));
    if (!out || !out.ends_at) return '';
    const outEnd = new Date(out.ends_at).getTime();
    const next = list.find(
        (x) =>
            String(x.id) !== String(outgoingId) &&
            x.starts_at &&
            new Date(x.starts_at).getTime() >= outEnd,
    );
    return next ? String(next.id) : '';
}

export function shiftOptionLabel(shift: CatalogueShift): string {
    const day = shift.starts_at
        ? new Date(shift.starts_at).toLocaleDateString('en-NZ', {
              weekday: 'short',
          })
        : '';
    return `${shift.label} · ${fmtTime(shift.starts_at)}–${fmtTime(shift.ends_at)}${day ? ` (${day})` : ''}`;
}

// ---- shared UI ------------------------------------------------------------
export function HueAvatar({
    name,
    size = 34,
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

const STATUS_PILL: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    submitted: 'bg-status-warning-bg text-status-warning',
    acknowledged: 'bg-status-success-bg text-status-success',
};
const STATUS_DOT: Record<string, string> = {
    draft: 'bg-muted-foreground',
    submitted: 'bg-status-warning',
    acknowledged: 'bg-status-success',
};

export function StatusPill({
    status,
    className,
}: {
    status: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                STATUS_PILL[status] ?? 'bg-muted text-muted-foreground',
                className,
            )}
        >
            <span
                className={cn(
                    'h-1.5 w-1.5 rounded-full',
                    STATUS_DOT[status] ?? 'bg-muted-foreground',
                )}
            />
            {statusLabel(status)}
        </span>
    );
}

export function MoodChip({
    mood,
    className,
}: {
    mood: string | null | undefined;
    className?: string;
}) {
    if (!mood) return null;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-[11px] font-medium text-foreground',
                className,
            )}
        >
            <span className="text-[13px] leading-none">{moodEmoji(mood)}</span>
            {mood}
        </span>
    );
}
