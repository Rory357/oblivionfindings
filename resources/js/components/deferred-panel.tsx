import { router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';

/** Long enough for the slowest panel on a busy server; after this the panel offers a retry. */
const GIVE_UP_AFTER_MS = 45_000;

type Values = Record<string, unknown>;

interface DeferredPanelProps<T extends Values> {
    /** The background-loaded (deferred) page prop or props this panel shows. */
    data: (keyof T & string) | (keyof T & string)[];
    /** The matching skeleton (DESIGN.md "Skeleton vs loader"), so nothing jumps when it fills. */
    fallback: ReactNode;
    /** What the panel holds, in plain words, e.g. "Other assigned work". */
    label: string;
    /** A loaded payload that says its own read failed (e.g. `unavailable: true`). */
    failed?: (values: T) => boolean;
    children: (values: T) => ReactNode;
}

const hasAll = (props: Values | null | undefined, keys: string[]) =>
    !!props && keys.every((key) => props[key] !== undefined);

/**
 * Shows one background-loaded part of a page.
 *
 * - While it loads: the skeleton, never an empty list or a "0".
 * - If the load fails or never arrives: an error with "Try again", never a blank panel.
 * - On a page refresh: keeps what it last showed for the same person until the
 *   new data arrives, instead of flashing back to the skeleton.
 *
 * Inertia's own `<Deferred>` covers only the first of these.
 */
export function DeferredPanel<T extends Values>({
    data,
    fallback,
    label,
    failed,
    children,
}: DeferredPanelProps<T>) {
    const props = usePage().props as Values & {
        auth?: { user?: { id?: number } | null };
    };
    const viewerId = props.auth?.user?.id ?? null;
    const keysId = Array.isArray(data) ? data.join(',') : data;
    const keys = useMemo(() => keysId.split(','), [keysId]);
    const loaded = hasAll(props, keys);

    // What this panel last showed, kept through a refresh for the same person only.
    const [last, setLast] = useState<{ viewerId: number | null; values: T } | null>(null);
    const sameAsLast =
        !!last && last.viewerId === viewerId && keys.every((key) => last.values[key] === props[key]);
    if (loaded && !sameAsLast) {
        setLast({
            viewerId,
            values: Object.fromEntries(keys.map((key) => [key, props[key]])) as T,
        });
    }

    // 'missing' once a request for these props has finished without them.
    const [missing, setMissing] = useState(false);
    if (loaded && missing) setMissing(false);

    const latestProps = useRef<Values>(props);
    useEffect(() => {
        latestProps.current = props;
    });

    useEffect(() => {
        const ours = (only: string[] | undefined) => (only ?? []).some((key) => keys.includes(key));
        let answered: Values | null = null;
        const offStart = router.on('start', (event) => {
            if (!ours(event.detail.visit.only)) return;
            answered = null;
            setMissing(false);
        });
        const offSuccess = router.on('success', (event) => {
            answered = event.detail.page.props as Values;
        });
        const offFinish = router.on('finish', (event) => {
            const visit = event.detail.visit;
            if (!ours(visit.only) || visit.cancelled || visit.interrupted) return;
            if (!hasAll(answered ?? latestProps.current, keys)) setMissing(true);
        });

        return () => {
            offStart();
            offSuccess();
            offFinish();
        };
    }, [keys]);

    useEffect(() => {
        if (loaded || missing) return;
        const timer = window.setTimeout(() => setMissing(true), GIVE_UP_AFTER_MS);

        return () => window.clearTimeout(timer);
    }, [loaded, missing]);

    const retry = () => {
        setMissing(false);
        router.reload({ only: keys });
    };

    const shown = loaded
        ? last && sameAsLast
            ? last.values
            : (Object.fromEntries(keys.map((key) => [key, props[key]])) as T)
        : last && last.viewerId === viewerId
          ? last.values
          : null;

    if ((!loaded && missing) || (shown && (failed?.(shown) ?? false))) {
        return (
            <Card className="py-0">
                <ErrorState
                    className="py-6"
                    title={`${label} could not be loaded`}
                    message="This does not mean there is nothing here. Try again."
                    onRetry={retry}
                />
            </Card>
        );
    }

    if (shown) {
        return <>{children(shown)}</>;
    }

    return (
        <div aria-busy="true" aria-label={`Loading ${label.toLowerCase()}`}>
            {fallback}
        </div>
    );
}
