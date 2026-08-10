export type ShiftRowClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id?: number | null;
};

export type ShiftRowStaff = {
    id: number;
    name: string;
    email?: string;
};

export type ShiftRowSite = {
    id: number;
    name: string;
    type?: string | null;
};

export type ShiftRowTask = {
    id: number;
    label: string;
    sort_order?: number | null;
    is_completed?: boolean;
};

export type ShiftRow = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location?: string | null;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    notes?: string | null;
    expected_break_minutes?: number | null;
    service_context_id?: number | null;
    coverage_roles?: string[] | null;
    required_licence_class?: string | null;
    required_licence_endorsements?: string[] | null;
    client: ShiftRowClient;
    staff: ShiftRowStaff | null;
    site?: ShiftRowSite | null;
    tasks?: ShiftRowTask[];
    cover_requested?: boolean;
};

/** Derive the “open” state used by the UI: scheduled + no staff assigned. */
export function isOpenShift(s: Pick<ShiftRow, 'status' | 'staff'>): boolean {
    return s.status === 'scheduled' && !s.staff;
}

/** Effective status used by badges/coloring — collapses scheduled-and-unassigned to "open". */
export function effectiveStatus(s: Pick<ShiftRow, 'status' | 'staff'>): string {
    return isOpenShift(s) ? 'open' : s.status;
}

export function shiftDayKey(starts_at: string): string {
    // ISO datetime is rendered in app timezone, but we slice to date so grouping is timezone-stable enough
    // for the week-bounded controller payload. Tweak if shifts span midnight in odd ways.
    const d = new Date(starts_at);
    if (Number.isNaN(d.getTime())) return '';
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

export function shiftStartTime(starts_at: string): string {
    const d = new Date(starts_at);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

export function shiftEndTime(ends_at: string): string {
    const d = new Date(ends_at);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

export function shiftHours(starts_at: string, ends_at: string): number {
    const a = new Date(starts_at).getTime();
    const b = new Date(ends_at).getTime();
    if (!a || !b || b <= a) return 0;
    return (b - a) / 3_600_000;
}

export function clientFullName(
    c: Pick<ShiftRowClient, 'first_name' | 'last_name'>,
): string {
    return `${c.first_name} ${c.last_name}`.trim();
}
