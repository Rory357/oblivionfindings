import { router } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { broadcastReaderId, validateBroadcastUpdates } from './broadcast-updates';
import type { MyDayNotification } from './types';

type Input = {
    viewerId: number | null;
    notifications: unknown;
    reader: unknown;
    url: string;
};
type Confirmed = Input & { generation: number; items: MyDayNotification[] };

/**
 * The initial/history props are not an authenticated current read. Only our
 * completed paired GET can reveal typed previews. Other page visits invalidate
 * them without remounting forms or losing drafts elsewhere in My Day.
 */
export function useBroadcastUpdates(input: Input) {
    const seenLists = useRef(new WeakSet<object>());
    const seenReaders = useRef(new WeakSet<object>());
    const seenReadIds = useRef(new Set<string>());
    const remember = useCallback((notifications: unknown, reader: unknown) => {
        if (notifications && typeof notifications === 'object') seenLists.current.add(notifications);
        if (reader && typeof reader === 'object') seenReaders.current.add(reader);
        const readId = broadcastReaderId(reader);
        if (readId) seenReadIds.current.add(readId);
    }, []);
    const current = useRef(input);
    const generation = useRef(0);
    const boundary = useRef({ viewerId: input.viewerId, url: input.url });
    // After each commit (never during render): remember the props this page
    // showed, keep the latest input for callbacks, and move the request
    // boundary when the viewer or URL changes so in-flight replies are stale.
    useLayoutEffect(() => {
        remember(input.notifications, input.reader);
        current.current = input;
        if (boundary.current.viewerId !== input.viewerId || boundary.current.url !== input.url) {
            generation.current += 1;
            boundary.current = { viewerId: input.viewerId, url: input.url };
        }
    });
    const mounted = useRef(false);
    const busy = useRef(false);
    const [loading, setLoading] = useState(false);
    const [confirmed, setConfirmed] = useState<Confirmed | null>(null);
    // A different viewer or URL ends the confirmed preview for good, so an
    // account that changes away and back can't revive it (React's "adjust
    // state while rendering" pattern; no ref is read during render).
    const [shownFor, setShownFor] = useState({
        viewerId: input.viewerId,
        url: input.url,
    });
    if (shownFor.viewerId !== input.viewerId || shownFor.url !== input.url) {
        setShownFor({ viewerId: input.viewerId, url: input.url });
        setConfirmed(null);
    }
    const invalidate = useCallback(() => {
        generation.current += 1;
        setConfirmed(null);
    }, []);

    const refresh = useCallback(() => {
        const start = current.current;
        if (!mounted.current || busy.current || !Number.isSafeInteger(start.viewerId) ||
            Number(start.viewerId) <= 0 || window.location.pathname !== '/my-day') return;
        busy.current = true;
        setLoading(true);
        setConfirmed(null);
        const url = window.location.pathname + window.location.search + window.location.hash;
        const oldRead = broadcastReaderId(start.reader);
        let request = -1;
        let requestPage: unknown = null;
        let pairedFresh = false;
        try {
            router.reload({
                only: ['notifications', 'broadcast_updates_reader'],
                onStart: () => { request = generation.current; },
                onBeforeUpdate: (response) => {
                    requestPage = response;
                    const notifications = response.props.notifications;
                    const reader = response.props.broadcast_updates_reader;
                    const readId = broadcastReaderId(reader);
                    // Partial props have already been merged here. Reject
                    // objects inherited from ANY preceding page response,
                    // including one that arrived after this request started.
                    pairedFresh = !!notifications && typeof notifications === 'object' &&
                        !!reader && typeof reader === 'object' && !!readId &&
                        !seenLists.current.has(notifications) &&
                        !seenReaders.current.has(reader) && !seenReadIds.current.has(readId);
                },
                onSuccess: (response) => {
                    // Inertia's onSuccess supplies global page.get(). Bind it
                    // to this request's pre-swap object, not another response.
                    if (!pairedFresh || response !== requestPage ||
                        !mounted.current || request < 0 || request !== generation.current ||
                        window.location.pathname + window.location.search + window.location.hash !== url ||
                        response.url !== url || current.current.viewerId !== start.viewerId) return;
                    const props = response.props as Record<string, unknown>;
                    const auth = props.auth as { user?: { id?: number } } | undefined;
                    if (auth?.user?.id !== start.viewerId ||
                        props.notifications === start.notifications ||
                        props.broadcast_updates_reader === start.reader ||
                        broadcastReaderId(props.broadcast_updates_reader) === oldRead) return;
                    const items = validateBroadcastUpdates(
                        props.notifications, props.broadcast_updates_reader, start.viewerId,
                    );
                    if (items !== null) setConfirmed({
                        viewerId: start.viewerId, notifications: props.notifications,
                        reader: props.broadcast_updates_reader, url, generation: request, items,
                    });
                },
                onFinish: () => {
                    busy.current = false;
                    if (mounted.current) setLoading(false);
                },
            });
            // Installed Inertia calls onStart synchronously when sending.
            // A draft/navigation guard can cancel before it, with no onFinish.
            if (request < 0) {
                busy.current = false;
                if (mounted.current) setLoading(false);
            }
        } catch {
            busy.current = false;
            if (mounted.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        mounted.current = true;
        const off = router.on('before', invalidate);
        const offUpdate = router.on('beforeUpdate', (event) => {
            remember(event.detail.page.props.notifications, event.detail.page.props.broadcast_updates_reader);
        });
        const hide = () => flushSync(invalidate);
        const restored = (event: PageTransitionEvent) => {
            if (event.persisted) hide();
        };
        const visibility = () => {
            if (document.visibilityState === 'hidden') hide();
        };
        window.addEventListener('popstate', hide, true);
        window.addEventListener('pagehide', hide);
        window.addEventListener('pageshow', restored);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            mounted.current = false;
            generation.current += 1;
            off();
            offUpdate();
            window.removeEventListener('popstate', hide, true);
            window.removeEventListener('pagehide', hide);
            window.removeEventListener('pageshow', restored);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [invalidate, remember]);

    // Navigation, history restore and hiding the page clear `confirmed`
    // (invalidate); a changed viewer, URL or props never match below.
    const matched = confirmed &&
        confirmed.viewerId === input.viewerId && confirmed.url === input.url &&
        confirmed.notifications === input.notifications && confirmed.reader === input.reader;
    return { items: matched ? confirmed.items : null, loading, refresh };
}
