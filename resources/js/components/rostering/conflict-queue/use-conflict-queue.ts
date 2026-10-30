import type { EntityFilterOption } from '@/components/rostering';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    SEVERITY_RANK,
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
export type UseConflictQueue = ReturnType<typeof useConflictQueue>;
function entityOptions(
    items: QueueItem[],
    key: 'staff' | 'sites',
): EntityFilterOption[] {
    const entities = new Map<number, string>();
    for (const item of items)
        for (const entity of item[key]) entities.set(entity.id, entity.name);
    const duplicates = new Map<string, number>();
    for (const name of entities.values())
        duplicates.set(name, (duplicates.get(name) ?? 0) + 1);
    return [...entities]
        .map(([id, name]) => ({
            id,
            name: (duplicates.get(name) ?? 0) > 1 ? name + ' · #' + id : name,
        }))
        .sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id);
}
function sortQueue(a: QueueItem, b: QueueItem) {
    return (
        SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity] ||
        TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type) ||
        a.id.localeCompare(b.id)
    );
}
export function useConflictQueue(items: QueueItem[], weekStart: string) {
    const [filter, setFilter] = useState<QueueFilter>('all');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [staffFilter, setStaffFilter] = useState<number | null>(null);
    const [siteFilter, setSiteFilter] = useState<number | null>(null);
    const [toasts, setToasts] = useState<QueueToast[]>([]);
    const toastId = useRef(0);
    const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
    const counts = useMemo(() => {
        const next = Object.fromEntries(
            TYPE_ORDER.map((type) => [type, 0]),
        ) as Record<ConflictType, number>;
        for (const item of items) next[item.type] += 1;
        return next;
    }, [items]);
    const staffOptions = useMemo(() => entityOptions(items, 'staff'), [items]);
    const siteOptions = useMemo(() => entityOptions(items, 'sites'), [items]);
    // Every row/count comes from the latest authorised response. No local hiding.
    const visible = useMemo(
        () =>
            items
                .filter(
                    (item) =>
                        (filter === 'all' || item.type === filter) &&
                        (staffFilter === null ||
                            item.staff.some(
                                (entity) => entity.id === staffFilter,
                            )) &&
                        (siteFilter === null ||
                            item.sites.some(
                                (entity) => entity.id === siteFilter,
                            )),
                )
                .sort(sortQueue),
        [items, filter, staffFilter, siteFilter],
    );
    const selected = visible.find((item) => item.id === selectedId) ?? null;
    useEffect(() => {
        setSelectedId(null);
        setStaffFilter(null);
        setSiteFilter(null);
    }, [weekStart]);
    useEffect(() => {
        if (selectedId && !visible.some((item) => item.id === selectedId))
            setSelectedId(visible[0]?.id ?? null);
    }, [visible, selectedId]);
    useEffect(() => {
        if (
            staffFilter !== null &&
            !staffOptions.some((entity) => entity.id === staffFilter)
        )
            setStaffFilter(null);
    }, [staffOptions, staffFilter]);
    useEffect(() => {
        if (
            siteFilter !== null &&
            !siteOptions.some((entity) => entity.id === siteFilter)
        )
            setSiteFilter(null);
    }, [siteOptions, siteFilter]);
    useEffect(
        () => () => {
            for (const timer of timers.current) clearTimeout(timer);
        },
        [],
    );
    const pushToast = useCallback((title: string, sub: string) => {
        const id = ++toastId.current;
        setToasts((current) => [...current, { id, title, sub }]);
        timers.current.push(
            setTimeout(
                () =>
                    setToasts((current) =>
                        current.filter((toast) => toast.id !== id),
                    ),
                5000,
            ),
        );
    }, []);
    const reviewNext = useCallback(() => {
        const sorted = [...items].sort(sortQueue);
        const index = sorted.findIndex((item) => item.id === selectedId);
        const top = sorted[(index + 1) % sorted.length];
        if (!top) return;
        setFilter('all');
        setStaffFilter(null);
        setSiteFilter(null);
        setSelectedId(top.id);
    }, [items, selectedId]);
    return {
        filter,
        setFilter,
        selectedId,
        setSelectedId,
        open: items,
        counts,
        visible,
        selected,
        staffOptions,
        siteOptions,
        staffFilterValue: staffFilter,
        siteFilterValue: siteFilter,
        setStaffFilterById: setStaffFilter,
        setSiteFilterById: setSiteFilter,
        toasts,
        pushToast,
        reviewNext,
    };
}
