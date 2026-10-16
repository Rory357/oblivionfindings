import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubBrowse } from './_types';

type Filters = Record<string, string | number | null | undefined>;
const filterKey = (filters: Filters) =>
    JSON.stringify(
        Object.fromEntries(
            Object.entries(filters)
                .filter(
                    ([, value]) =>
                        value !== null && value !== undefined && value !== '',
                )
                .sort(([left], [right]) => left.localeCompare(right)),
        ),
    );

/** Keep old filter/account responses out of a newly selected view. */
export function useHubBrowse<T extends { id: number }>({
    endpoint,
    initial,
    fallback,
    filters,
    enabled,
}: {
    endpoint: string;
    initial?: HubBrowse<T> | null;
    fallback: T[];
    filters: Filters;
    enabled: boolean;
}) {
    const key = filterKey(filters);
    const initialKey = filterKey(initial?.filters ?? {});
    const active = enabled && !!initial;
    const context = useRef({ initial, key });
    context.current = { initial, key };
    const sequence = useRef(0);
    const locked = useRef(false);
    const [state, setState] = useState<{
        source: typeof initial;
        key: string;
        data: T[];
        pagination: HubBrowse<T>['pagination'] | null;
        loading: boolean;
        error: string;
        failedPage: number | null;
    }>({
        source: initial,
        key: initialKey,
        data: initial?.data ?? fallback,
        pagination: initial?.pagination ?? null,
        loading: false,
        error: '',
        failedPage: null,
    });
    const current = state.source === initial && state.key === key;
    const snapshot = current
        ? state
        : key === initialKey
          ? {
                data: initial?.data ?? [],
                pagination: initial?.pagination ?? null,
                loading: false,
                error: '',
                failedPage: null,
            }
          : {
                data: [],
                pagination: null,
                loading: true,
                error: '',
                failedPage: null,
            };

    const fetchPage = async (page: number, append: boolean) => {
        if (!active || locked.current) return;
        locked.current = true;
        const requestId = ++sequence.current;
        const source = initial;
        setState((previous) => ({
            source,
            key,
            data:
                append && previous.source === source && previous.key === key
                    ? previous.data
                    : [],
            pagination: append ? previous.pagination : null,
            loading: true,
            error: '',
            failedPage: null,
        }));
        try {
            const params = new URLSearchParams({
                ...JSON.parse(key),
                page: String(page),
                per_page: String(initial?.pagination.per_page ?? 20),
            });
            const result = await hubRequest<HubBrowse<T>>(
                `${endpoint}?${params}`,
                'GET',
            );
            if (
                requestId !== sequence.current ||
                context.current.initial !== source ||
                context.current.key !== key
            )
                return;
            if (
                !Array.isArray(result.data) ||
                result.pagination?.page !== page ||
                typeof result.pagination.has_more !== 'boolean' ||
                (result.pagination.has_more &&
                    result.pagination.next_page !== page + 1)
            ) {
                throw new Error(
                    'We could not confirm these results. Please try again.',
                );
            }
            setState((previous) => ({
                source,
                key,
                data: append
                    ? Array.from(
                          new Map(
                              [...previous.data, ...result.data].map((row) => [
                                  row.id,
                                  row,
                              ]),
                          ).values(),
                      )
                    : result.data,
                pagination: result.pagination,
                loading: false,
                error: '',
                failedPage: null,
            }));
        } catch (cause) {
            if (
                requestId === sequence.current &&
                context.current.initial === source &&
                context.current.key === key
            ) {
                setState((previous) => ({
                    ...previous,
                    loading: false,
                    error:
                        cause instanceof Error
                            ? cause.message
                            : 'These results could not be loaded. Please try again.',
                    failedPage: page,
                }));
            }
        } finally {
            if (requestId === sequence.current) locked.current = false;
        }
    };

    useEffect(() => {
        const invalidate = () => {
            sequence.current++;
        };
        locked.current = false;
        if (!active || key === initialKey) {
            setState({
                source: initial,
                key,
                data: initial?.data ?? fallback,
                pagination: initial?.pagination ?? null,
                loading: false,
                error: '',
                failedPage: null,
            });
            return invalidate;
        }
        const timer = setTimeout(() => {
            void fetchPage(1, false);
        }, 200);
        return () => {
            clearTimeout(timer);
            invalidate();
        };
        // The stable key captures every requested filter; fallback is only for local demos.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, initial, key, initialKey]);

    return {
        server: active,
        rows: active ? snapshot.data : fallback,
        loading: active && snapshot.loading,
        error: active ? snapshot.error : '',
        hasMore: active && !!snapshot.pagination?.has_more,
        loadMore: () =>
            snapshot.pagination?.next_page &&
            void fetchPage(snapshot.pagination.next_page, true),
        retry: () =>
            void fetchPage(
                snapshot.failedPage ?? 1,
                (snapshot.failedPage ?? 1) > 1,
            ),
    };
}
