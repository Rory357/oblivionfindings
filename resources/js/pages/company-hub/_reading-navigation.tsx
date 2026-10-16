import { router, useRemember } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type Dispatch,
    type MouseEvent,
    type ReactNode,
    type SetStateAction,
} from 'react';
import {
    popReaderFrame,
    pushReaderFrame,
    readerFrame,
    readerLocation,
    readerReturnLabels,
    readReaderTrail,
    type ReaderFrame,
    type ReaderTrail,
} from './_reader-trail';

type Controls = Record<string, string | number>;
// Browser Back shares this document; a reload starts a fresh presentation session.
const readingSession = crypto.randomUUID();
type ReadingState = {
    session: string;
    trail: ReaderTrail;
    controls: Record<string, Controls>;
    restore?: { href: string; frame: ReaderFrame };
};
type ReadingNavigation = {
    controls: ReadingState['controls'];
    setControls: (key: string, update: SetStateAction<Controls>) => void;
    open: (href: string, focus?: HTMLElement | null) => void;
    primary: (href: string) => void;
    back: () => void;
    returnLabel: string | null;
    returnHref: string | null;
    capture: (event: MouseEvent<HTMLElement>) => void;
};
const ReadingContext = createContext<ReadingNavigation | null>(null);

function cleanControls(value: unknown): Controls {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
    return Object.fromEntries(
        Object.entries(value)
            .filter(
                ([key, item]) =>
                    /^[a-zA-Z_]{1,30}$/.test(key) &&
                    ((typeof item === 'string' && item.length <= 200) ||
                        (typeof item === 'number' &&
                            Number.isSafeInteger(item) &&
                            item >= 0 &&
                            item <= 9999)),
            )
            .slice(0, 16),
    );
}
function cleanState(value: unknown, actorId: number): ReadingState {
    const stored =
        value && typeof value === 'object'
            ? (value as Partial<ReadingState>)
            : {};
    const sameSession = stored.session === readingSession;
    const trail = readReaderTrail(sameSession ? stored.trail : null, actorId);
    const controls =
        sameSession &&
        stored.trail?.actor_id === actorId &&
        stored.controls &&
        typeof stored.controls === 'object'
            ? Object.fromEntries(
                  Object.entries(stored.controls)
                      .slice(-24)
                      .filter(([key]) => /^[\w: -]{1,180}$/.test(key))
                      .map(([key, value]) => [key, cleanControls(value)]),
              )
            : {};
    return { session: readingSession, trail, controls };
}
const scrollSnapshot = () => ({
    window: window.scrollY,
    regions: [...document.querySelectorAll('[scroll-region]')]
        .slice(0, 12)
        .map((region) => region.scrollTop),
});
function restoreFrame(frame: ReaderFrame, isCurrent: () => boolean) {
    requestAnimationFrame(() => {
        if (!isCurrent()) return;
        const targets = [
            ...document.querySelectorAll<HTMLElement>(
                '[data-hub-return-key], [data-hub-event-key], a[href]',
            ),
        ].filter((element) => {
            if (element.closest('[hidden], [inert], [aria-hidden="true"]'))
                return false;
            const style = getComputedStyle(element);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });
        const target =
            targets.find(
                (element) =>
                    frame.focus?.key &&
                    (element.dataset.hubReturnKey === frame.focus.key ||
                        element.dataset.hubEventKey === frame.focus.key),
            ) ??
            targets.find(
                (element) =>
                    frame.focus?.href &&
                    readerLocation(element.getAttribute('href')) ===
                        frame.focus.href,
            );
        target?.focus({ preventScroll: true });
        window.scrollTo({ top: frame.scroll.window, behavior: 'instant' });
        document
            .querySelectorAll('[scroll-region]')
            .forEach((region, index) => {
                if (frame.scroll.regions[index] !== undefined)
                    region.scrollTop = frame.scroll.regions[index];
            });
    });
}

/** Remembers only list controls and safe locations; every reader still comes from the server. */
export function HubReadingProvider({
    actorId,
    label,
    children,
}: {
    actorId: number;
    label: ReaderFrame['label'];
    children: ReactNode;
}) {
    const key = `company-hub:reading:${actorId}`;
    const [stored, setStored] = useRemember<ReadingState>(
        {
            session: readingSession,
            trail: { actor_id: actorId, frames: [] },
            controls: {},
        },
        key,
    );
    const state = cleanState(stored, actorId);
    const alive = useRef(true),
        sequence = useRef(0),
        pendingBack = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            // Invalidate the latest request, not the counter at effect setup.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            sequence.current++;
        };
    }, []);
    useEffect(() => {
        if (
            stored.session !== readingSession ||
            stored.trail.actor_id !== actorId ||
            !stored.restore
        )
            return;
        const frame = readerFrame(stored.restore.frame);
        if (
            !frame ||
            readerLocation(
                window.location.pathname +
                    window.location.search +
                    window.location.hash,
            ) !== stored.restore.href
        )
            return;
        const next = cleanState(stored, actorId);
        setStored(next);
        router.remember(next, key);
        const generation = sequence.current;
        restoreFrame(
            frame,
            () => alive.current && sequence.current === generation,
        );
    }, [actorId, key, stored, setStored]);
    const remember = (next: ReadingState) => {
        setStored(next);
        // The destination owns its trail; do not overwrite the departing history entry.
        router.remember(next, key);
    };
    const visit = (
        href: string,
        next: ReadingState,
        restoring?: ReaderFrame,
    ) => {
        let request: number | null = null;
        let rejectedDestination = false;
        const isCurrent = () =>
            alive.current && request !== null && request === sequence.current;
        router.visit(href, {
            preserveState: true,
            preserveScroll: !!restoring,
            onStart: () => {
                if (alive.current) {
                    // A vetoed attempt must not take ownership from a visit still in flight.
                    request = ++sequence.current;
                    pendingBack.current = !!restoring;
                }
            },
            onBeforeUpdate: (page) => {
                const destinationActor = (
                    page.props.auth as { user?: { id: number } } | undefined
                )?.user?.id;
                if (
                    !isCurrent() ||
                    destinationActor !== actorId ||
                    !readerLocation(href) ||
                    readerLocation(page.url) !== readerLocation(href)
                ) {
                    rejectedDestination = true;
                    return;
                }
                // Inertia swaps React pages before onSuccess. Carry only safe navigation
                // metadata into the destination's initial history, not the origin entry.
                page.rememberedState = {
                    ...page.rememberedState,
                    [key]: {
                        ...next,
                        ...(restoring
                            ? {
                                  restore: {
                                      href: readerLocation(href),
                                      frame: restoring,
                                  },
                              }
                            : {}),
                    },
                };
            },
            onSuccess: () => {
                if (!isCurrent() || rejectedDestination) return;
                remember(next);
                if (restoring) restoreFrame(restoring, isCurrent);
                else {
                    const anchor = new URL(href, window.location.origin).hash;
                    if (/^#block-[a-zA-Z0-9_-]{1,60}$/.test(anchor))
                        requestAnimationFrame(() => {
                            if (isCurrent())
                                document
                                    .getElementById(anchor.slice(1))
                                    ?.focus({ preventScroll: true });
                        });
                }
            },
            onFinish: () => {
                if (isCurrent()) {
                    pendingBack.current = false;
                }
            },
        });
    };
    const setControls = useCallback(
        (name: string, update: SetStateAction<Controls>) => {
            setStored((previous) => {
                const current = cleanState(previous, actorId);
                const next =
                    typeof update === 'function'
                        ? update(current.controls[name] ?? {})
                        : update;
                return {
                    ...current,
                    controls: Object.fromEntries(
                        [
                            ...Object.entries(current.controls).filter(
                                ([key]) => key !== name,
                            ),
                            [name, cleanControls(next)],
                        ].slice(-24),
                    ),
                };
            });
        },
        [actorId, setStored],
    );
    const open = (
        href: string,
        focus: HTMLElement | null = document.activeElement instanceof
        HTMLElement
            ? document.activeElement
            : null,
    ) => {
        const destination = readerLocation(href);
        const origin = readerLocation(
            window.location.pathname +
                window.location.search +
                window.location.hash,
        );
        if (!destination || destination === origin) return;
        if (!origin) {
            primary(destination);
            return;
        }
        const frame: ReaderFrame = {
            href: origin,
            label,
            scroll: scrollSnapshot(),
            focus: {
                key: focus?.dataset.hubReturnKey || focus?.dataset.hubEventKey,
                href: focus?.getAttribute('href') ?? destination,
            },
        };
        visit(destination, {
            ...state,
            trail: pushReaderFrame(state.trail, actorId, frame),
        });
    };
    const primary = (href: string) => {
        visit(href, { ...state, trail: { actor_id: actorId, frames: [] } });
    };
    const back = () => {
        if (pendingBack.current) return;
        const { frame, trail } = popReaderFrame(state.trail, actorId);
        if (!frame) return;
        visit(frame.href, { ...state, trail }, frame);
    };
    const capture = (event: MouseEvent<HTMLElement>) => {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;
        const link =
            event.target instanceof Element
                ? event.target.closest<HTMLAnchorElement>('a[href]')
                : null;
        if (
            !link ||
            (link.target && link.target !== '_self') ||
            link.hasAttribute('download') ||
            link.hasAttribute('data-hub-reading-back')
        )
            return;
        const href = link.getAttribute('href');
        const destination = readerLocation(href);
        if (!destination) return;
        event.preventDefault();
        if (
            /^\/company-hub\/(read|people|events|documents)\//.test(
                destination,
            ) ||
            destination.includes('#block-') ||
            new URL(destination, window.location.origin).searchParams.has(
                'announcement',
            ) ||
            /^\/company-hub\?view=shoutouts(?:&[^#]*)?&recognition=/.test(
                destination,
            ) ||
            /^\/company-hub\?view=ideas(?:&[^#]*)?&idea=/.test(destination)
        )
            open(destination, link);
        else primary(href!);
    };
    const frame = state.trail.frames.at(-1);
    return (
        <ReadingContext.Provider
            value={{
                controls: state.controls,
                setControls,
                open,
                primary,
                back,
                capture,
                returnLabel: frame ? readerReturnLabels[frame.label] : null,
                returnHref: frame?.href ?? null,
            }}
        >
            {children}
        </ReadingContext.Provider>
    );
}

export const useHubReading = () => useContext(ReadingContext);

function withDefaults<T extends Controls>(defaults: T, saved?: Controls): T {
    return {
        ...defaults,
        ...Object.fromEntries(
            Object.entries(saved ?? {}).filter(
                ([key, value]) =>
                    Object.hasOwn(defaults, key) &&
                    typeof value === typeof defaults[key],
            ),
        ),
    };
}

export function useHubListControls<T extends Controls>(
    name: string,
    defaults: T,
): [T, Dispatch<SetStateAction<T>>] {
    const reading = useHubReading();
    const [local, setLocal] = useState(defaults);
    const saved = reading?.controls[name];
    const state = saved ? withDefaults(defaults, saved) : local;
    const setShared = reading?.setControls;
    useEffect(() => {
        if (setShared && !saved) setShared(name, defaults);
    }, [name, saved, setShared, defaults]);
    const set: Dispatch<SetStateAction<T>> = (update) => {
        if (reading)
            reading.setControls(name, (previous) =>
                typeof update === 'function'
                    ? update(withDefaults(defaults, previous))
                    : update,
            );
        else setLocal(update);
    };
    return [state, set];
}
