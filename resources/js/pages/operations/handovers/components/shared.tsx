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

/** Two-person controlled-drug count reconciliation recorded at the handover
 *  (eMAR medication lens). Null when no CD check was recorded. */
export type CdVerification = {
    result: 'verified' | 'discrepancy';
    witness_id: number | null;
    witness_name: string | null;
    notes: string | null;
    verified_at: string | null;
    verified_by: number | null;
    verified_by_name: string | null;
};

export type Handover = {
    id: number;
    status: HandoverStatus | string;
    handover_notes: string;
    client_mood: string | null;
    medications_due: string[];
    cd_verification: CdVerification | null;
    /** True when the client has an active controlled medication (stamped at save) —
     *  drives the exact "CD count unverified at handover" alert. */
    cd_required: boolean;
    /** Optimistic-concurrency token — sent back on edit so the server can detect a
     *  concurrent save of the same shared draft. */
    version: number;
    /** Active presence edit-lock held by ANOTHER worker (within the TTL). Null when
     *  the draft is free or this viewer holds it. */
    edit_lock: { held_by_name: string; held_at: string | null } | null;
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
    /** Immutable worker recorded as the recipient at submission time. */
    submitted_incoming_staff?: HandoverStaff | null;
    /** Worker currently assigned to the incoming Shift and able to acknowledge. */
    current_incoming_staff?: HandoverStaff | null;
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
    // eMAR medication-focused handovers enrich each client with their active
    // medication orders so the wizard's meds step is MAR-bound (optional;
    // Operations does not populate it).
    medications?: { id: number; name: string }[];
};
export type CatalogueStaff = {
    id: number;
    name: string;
    email?: string | null;
    role?: string | null;
    site_ids?: number[];
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
    status: string;
    label: string;
    starts_at: string | null;
    ends_at: string | null;
    actual_ends_at: string | null;
    staff: { id: number; name: string } | null;
};
export type Catalogue = {
    clients: CatalogueClient[];
    staff: CatalogueStaff[];
    staffBySite: Record<string, { id: number; name: string }[]>;
    sites: CatalogueSite[];
    serviceContexts: CatalogueServiceContext[];
    shifts: CatalogueShift[];
    controlledWitnessesBySite: Record<string, { id: number; name: string }[]>;
    capabilities: {
        view_controlled: boolean;
        record_controlled: boolean;
        manage_any_shifts: boolean;
    };
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
    for (let i = 0; i < name.length; i++)
        h = (h * 31 + name.charCodeAt(i)) % 360;
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

/** Outgoing choices the current actor can actually write. A broad read grant is
 *  deliberately irrelevant: only the assigned worker or an exact shift manager
 *  may create the handover. Unassigned shifts cannot satisfy the server's
 *  canonical outgoing-owner contract. */
export function outgoingHandoverShifts(
    shifts: CatalogueShift[],
    clientId: number | string | null,
    currentUserId: number,
    canManageAnyShift: boolean,
): CatalogueShift[] {
    return clientShiftsSorted(shifts, clientId).filter(
        (shift) =>
            shift.site_id !== null &&
            shift.user_id !== null &&
            shift.status !== 'cancelled' &&
            (canManageAnyShift || shift.user_id === currentUserId),
    );
}

/** Incoming choices mirror ShiftHandoverService::incomingShiftMatches(): same
 *  client/Site/context, assigned and active, beginning at the handoff boundary
 *  and no more than twelve hours later. */
export function incomingHandoverShifts(
    shifts: CatalogueShift[],
    clientId: number | string | null,
    outgoingId: number | string | null,
): CatalogueShift[] {
    if (!clientId || !outgoingId) return [];
    const list = clientShiftsSorted(shifts, clientId);
    const outgoing = list.find(
        (shift) => String(shift.id) === String(outgoingId),
    );
    const outgoingBoundary = outgoing?.actual_ends_at ?? outgoing?.ends_at;
    if (!outgoing || outgoing.site_id === null || !outgoingBoundary) return [];

    const boundary = new Date(outgoingBoundary).getTime();
    const latest = boundary + 12 * 60 * 60 * 1000;

    return list.filter((shift) => {
        if (
            String(shift.id) === String(outgoing.id) ||
            !shift.user_id ||
            shift.site_id === null ||
            !['scheduled', 'in_progress'].includes(shift.status) ||
            !shift.starts_at
        ) {
            return false;
        }
        if (outgoing.site_id !== shift.site_id) {
            return false;
        }
        if (
            outgoing.service_context_id &&
            shift.service_context_id !== outgoing.service_context_id
        ) {
            return false;
        }

        const starts = new Date(shift.starts_at).getTime();
        const ends = shift.ends_at ? new Date(shift.ends_at).getTime() : null;

        return (
            Number.isFinite(starts) &&
            starts >= boundary &&
            starts <= latest &&
            (ends === null || (Number.isFinite(ends) && ends > starts))
        );
    });
}

/** Immediate next shift for the same client that starts at/after the outgoing
 *  shift ends — the auto-suggested incoming shift. */
export function nextShiftIdAfter(
    shifts: CatalogueShift[],
    clientId: number | string | null,
    outgoingId: number | string | null,
): string {
    const next = incomingHandoverShifts(shifts, clientId, outgoingId).at(0);
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
