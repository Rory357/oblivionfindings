import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { EntityFilterOption } from '@/components/rostering';

import {
    SEVERITY_RANK,
    TYPE_META,
    TYPE_ORDER,
    type ConflictType,
    type QueueItem,
} from './types';

export type QueueFilter = 'all' | ConflictType;

export interface QueueToast {
    id: number;
    title: string;
    sub: string;
}

/** Staff/site names involved in an item — used by the hero footer entity filters. */
function itemStaff(item: QueueItem): string[] {
    const names = new Set<string>();
    if (
        item.type === 'staff_overlap' ||
        item.type === 'leave_clash' ||
        item.type === 'tight_turnaround'
    ) {
        names.add(item.who);
    }
    for (const shift of item.shifts) {
        if (shift.staff) names.add(shift.staff);
    }
    return [...names];
}

function itemSites(item: QueueItem): string[] {
    const names = new Set<string>();
    if (item.type === 'coverage_gap' || item.type === 'open_shift') {
        names.add(item.who);
    }
    for (const shift of item.shifts) {
        if (shift.location) names.add(shift.location);
    }
    return [...names];
}

function toOptions(names: Set<string>): EntityFilterOption[] {
    return [...names]
        .sort((a, b) => a.localeCompare(b))
        .map((name, index) => ({ id: index, name }));
}

export interface UseConflictQueue {
    filter: QueueFilter;
    setFilter: (next: QueueFilter) => void;
    selectedId: string | null;
    setSelectedId: (next: string | null) => void;

    open: QueueItem[];
    counts: Record<ConflictType, number>;
    blocking: number;
    /** Resolutions this week-session (client acks + confirmed server resolutions). */
    resolvedToday: number;
    /** Stable progress denominator: resolved + still-open this session. */
    seedTotal: number;
    visible: QueueItem[];
    selected: QueueItem | null;

    /** Hero-footer entity filters (client-side narrowing over the live queue). */
    staffOptions: EntityFilterOption[];
    siteOptions: EntityFilterOption[];
    staffFilterValue: number | null;
    siteFilterValue: number | null;
    setStaffFilterById: (id: number | null) => void;
    setSiteFilterById: (id: number | null) => void;

    toasts: QueueToast[];
    pushToast: (title: string, sub: string) => void;

    markLocallyResolved: (id: string) => void;
    /** Resolve an item entirely client-side: advance, hide it, toast, tally. */
    resolveLocally: (id: string, title: string, sub: string) => void;
    /** Resolve a bulk set of ids client-side (e.g. acknowledge all turnarounds). */
    resolveManyLocally: (ids: string[]) => void;
    /**
     * Tally a server-backed resolution. The item itself leaves the queue on the
     * next Inertia prop reload (or stays if the server still reports it, e.g. a
     * partially-filled coverage gap) — this only advances the progress counter.
     */
    recordResolved: (count?: number) => void;
    resolveNext: () => void;
}

const sortQueue = (a: QueueItem, b: QueueItem) => {
    const bySeverity = SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity];
    if (bySeverity !== 0) return bySeverity;
    return TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type);
};

export function useConflictQueue(
    items: QueueItem[],
    weekStart: string,
): UseConflictQueue {
    const [filter, setFilter] = useState<QueueFilter>('all');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [staffFilter, setStaffFilter] = useState<string | null>(null);
    const [siteFilter, setSiteFilter] = useState<string | null>(null);
    const [locallyResolved, setLocallyResolved] = useState<Set<string>>(
        () => new Set(),
    );
    const [toasts, setToasts] = useState<QueueToast[]>([]);
    const toastId = useRef(0);

    // Cumulative resolutions this week-session. Tracked as a counter (NOT derived
    // from items.length) because a server-backed resolution removes the conflict
    // from the reloaded props, so `items.length - open.length` would net to zero
    // for the most common triage actions.
    const [seedWeek, setSeedWeek] = useState(weekStart);
    const [resolvedCount, setResolvedCount] = useState(0);
    if (seedWeek !== weekStart) {
        // Week changed via the footer picker — reset the tally for the new week.
        setSeedWeek(weekStart);
        setResolvedCount(0);
    }

    const open = useMemo(
        () => items.filter((item) => !locallyResolved.has(item.id)),
        [items, locallyResolved],
    );

    const resolvedToday = resolvedCount;
    const seedTotal = resolvedCount + open.length;

    const counts = useMemo(() => {
        const next = Object.fromEntries(
            TYPE_ORDER.map((type) => [type, 0]),
        ) as Record<ConflictType, number>;
        for (const item of open) next[item.type] += 1;
        return next;
    }, [open]);

    const blocking = useMemo(
        () =>
            TYPE_ORDER.filter((type) => TYPE_META[type].blocking).reduce(
                (sum, type) => sum + counts[type],
                0,
            ),
        [counts],
    );

    const staffOptions = useMemo(() => {
        const names = new Set<string>();
        for (const item of open)
            for (const name of itemStaff(item)) names.add(name);
        return toOptions(names);
    }, [open]);

    const siteOptions = useMemo(() => {
        const names = new Set<string>();
        for (const item of open)
            for (const name of itemSites(item)) names.add(name);
        return toOptions(names);
    }, [open]);

    const staffFilterValue =
        staffOptions.find((option) => option.name === staffFilter)?.id ?? null;
    const siteFilterValue =
        siteOptions.find((option) => option.name === siteFilter)?.id ?? null;

    const visible = useMemo(() => {
        const list = open.filter((item) => {
            if (filter !== 'all' && item.type !== filter) return false;
            if (staffFilter && !itemStaff(item).includes(staffFilter))
                return false;
            if (siteFilter && !itemSites(item).includes(siteFilter))
                return false;
            return true;
        });
        return [...list].sort(sortQueue);
    }, [open, filter, staffFilter, siteFilter]);

    const selected = visible.find((item) => item.id === selectedId) ?? null;

    // Repair selection when the current pick leaves the visible set.
    useEffect(() => {
        if (selectedId && !visible.some((item) => item.id === selectedId)) {
            setSelectedId(visible[0]?.id ?? null);
        }
    }, [visible, selectedId]);

    // Repair the staff/site filter when its entity drops out of the queue (its
    // last conflict resolved). Without this the EntityFilter pill resets to "All
    // staff" while the name filter keeps hiding every remaining item.
    useEffect(() => {
        if (staffFilter && !staffOptions.some((o) => o.name === staffFilter)) {
            setStaffFilter(null);
        }
    }, [staffOptions, staffFilter]);
    useEffect(() => {
        if (siteFilter && !siteOptions.some((o) => o.name === siteFilter)) {
            setSiteFilter(null);
        }
    }, [siteOptions, siteFilter]);

    const pushToast = useCallback((title: string, sub: string) => {
        const id = (toastId.current += 1);
        setToasts((current) => [...current, { id, title, sub }]);
        setTimeout(() => {
            setToasts((current) => current.filter((toast) => toast.id !== id));
        }, 2600);
    }, []);

    const markLocallyResolved = useCallback((id: string) => {
        setLocallyResolved((current) => {
            const next = new Set(current);
            next.add(id);
            return next;
        });
    }, []);

    const recordResolved = useCallback((count = 1) => {
        setResolvedCount((current) => current + count);
    }, []);

    const resolveLocally = useCallback(
        (id: string, title: string, sub: string) => {
            const idx = visible.findIndex((item) => item.id === id);
            const next = visible[idx + 1] ?? visible[idx - 1] ?? null;
            markLocallyResolved(id);
            setSelectedId(next ? next.id : null);
            setResolvedCount((current) => current + 1);
            pushToast(title, sub);
        },
        [visible, markLocallyResolved, pushToast],
    );

    const resolveManyLocally = useCallback((ids: string[]) => {
        if (ids.length === 0) return;
        setLocallyResolved((current) => {
            const next = new Set(current);
            for (const id of ids) next.add(id);
            return next;
        });
        setResolvedCount((current) => current + ids.length);
        setSelectedId((current) =>
            current && ids.includes(current) ? null : current,
        );
    }, []);

    const setStaffFilterById = useCallback(
        (id: number | null) => {
            setStaffFilter(
                id == null
                    ? null
                    : (staffOptions.find((option) => option.id === id)?.name ??
                          null),
            );
        },
        [staffOptions],
    );

    const setSiteFilterById = useCallback(
        (id: number | null) => {
            setSiteFilter(
                id == null
                    ? null
                    : (siteOptions.find((option) => option.id === id)?.name ??
                          null),
            );
        },
        [siteOptions],
    );

    const resolveNext = useCallback(() => {
        const top = [...open].sort(sortQueue)[0];
        if (!top) return;
        setFilter('all');
        setStaffFilter(null);
        setSiteFilter(null);
        setSelectedId(top.id);
    }, [open]);

    return {
        filter,
        setFilter,
        selectedId,
        setSelectedId,
        open,
        counts,
        blocking,
        resolvedToday,
        seedTotal,
        visible,
        selected,
        staffOptions,
        siteOptions,
        staffFilterValue,
        siteFilterValue,
        setStaffFilterById,
        setSiteFilterById,
        toasts,
        pushToast,
        markLocallyResolved,
        resolveLocally,
        resolveManyLocally,
        recordResolved,
        resolveNext,
    };
}
