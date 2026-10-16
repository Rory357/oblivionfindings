import { Link, router } from '@inertiajs/react';
import { useEffect, type ComponentProps, type MouseEvent } from 'react';
import { useHubListControls } from './_reading-navigation';

type BrowseView =
    | 'news'
    | 'resources'
    | 'guides'
    | 'pages'
    | 'teams'
    | 'campaigns'
    | 'following';
export type ReturnState = {
    view: BrowseView;
    filters: Record<string, string>;
    focus?: string;
    scroll?: { window: number; regions: number[] };
};
const views = new Set([
    'news',
    'resources',
    'guides',
    'pages',
    'teams',
    'campaigns',
    'following',
]);
const filterKeys = new Set([
    'category',
    'query',
    'search',
    'sort',
    'layout',
    'collection',
    'team',
    'kind',
    'personal',
]);

/** Only list controls travel in a return link, never page content or a redirect URL. */
export function readBrowseReturn(search: string): ReturnState | null {
    try {
        const value = new URLSearchParams(search).get('hubBrowse');
        if (!value || value.length > 4096) return null;
        const saved = JSON.parse(value);
        if (
            !saved ||
            !views.has(saved.view) ||
            !saved.filters ||
            typeof saved.filters !== 'object'
        )
            return null;
        const filters: Record<string, string> = {};
        for (const [key, value] of Object.entries(saved.filters)) {
            if (
                filterKeys.has(key) &&
                typeof value === 'string' &&
                value.length <= 200
            )
                filters[key] = value;
        }
        const finiteScroll = (value: unknown) =>
            typeof value === 'number' &&
            Number.isFinite(value) &&
            value >= 0 &&
            value <= 1000000;
        return {
            view: saved.view,
            filters,
            focus:
                typeof saved.focus === 'string' &&
                /^[\w:-]{1,100}$/.test(saved.focus)
                    ? saved.focus
                    : undefined,
            scroll:
                saved.scroll &&
                finiteScroll(saved.scroll.window) &&
                Array.isArray(saved.scroll.regions) &&
                saved.scroll.regions.length <= 12 &&
                saved.scroll.regions.every(finiteScroll)
                    ? saved.scroll
                    : undefined,
        };
    } catch {
        return null;
    }
}

export function browseReturnLink(base: string, search: string) {
    const saved = readBrowseReturn(search);
    if (!saved) return null;
    return {
        href: `${base}?view=${saved.view}&hubBrowse=${encodeURIComponent(JSON.stringify(saved))}`,
        label:
            saved.view === 'news'
                ? 'Back to news'
                : saved.view === 'pages'
                  ? 'Back to pages'
                  : saved.view === 'guides'
                    ? 'Back to company guides'
                    : saved.view === 'teams'
                      ? 'Back to teams'
                      : saved.view === 'campaigns'
                        ? 'Back to campaigns'
                        : saved.view === 'following'
                          ? 'Back to following'
                          : 'Back to resources',
    };
}

export function useHubBrowseState<T extends Record<string, string>>(
    view: BrowseView,
    defaults: T,
    demo = false,
) {
    const saved =
        typeof window === 'undefined'
            ? null
            : readBrowseReturn(window.location.search);
    const initial = { ...defaults };
    if (saved?.view === view) {
        for (const key of Object.keys(defaults) as Array<keyof T>) {
            if (typeof saved.filters[key as string] === 'string')
                initial[key] = saved.filters[key as string] as T[keyof T];
        }
    }
    const [state, setState] = useHubListControls<T>(
        `${demo ? 'showcase' : 'staff'}:${view}`,
        initial,
    );
    useEffect(() => {
        if (saved?.view !== view) return;
        const frame = requestAnimationFrame(() => {
            if (saved.focus)
                document
                    .querySelector<HTMLElement>(
                        `[data-hub-return-key="${saved.focus}"]`,
                    )
                    ?.focus({ preventScroll: true });
            if (saved.scroll) {
                window.scrollTo({
                    top: saved.scroll.window,
                    behavior: 'instant',
                });
                document
                    .querySelectorAll('[scroll-region]')
                    .forEach((region, index) => {
                        if (saved.scroll?.regions[index] !== undefined)
                            region.scrollTop = saved.scroll.regions[index];
                    });
            }
        });
        return () => cancelAnimationFrame(frame);
        // A return snapshot is applied once; later filter changes must not move focus.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [view]);
    const set = <K extends keyof T>(key: K, value: T[K]) =>
        setState((previous) => ({ ...previous, [key]: value }));
    return { state, set, browse: { view, filters: state } as ReturnState };
}

export function HubBrowseLink({
    href,
    browse,
    returnKey,
    ...props
}: Omit<ComponentProps<typeof Link>, 'href'> & {
    href: string;
    browse?: ReturnState;
    returnKey: string;
}) {
    const destination = (scroll = false) => {
        if (!browse) return href;
        const url = new URL(href, 'https://hub.invalid');
        url.searchParams.set(
            'hubBrowse',
            JSON.stringify({
                ...browse,
                focus: returnKey,
                ...(scroll
                    ? {
                          scroll: {
                              window: window.scrollY,
                              regions: [
                                  ...document.querySelectorAll(
                                      '[scroll-region]',
                                  ),
                              ]
                                  .slice(0, 12)
                                  .map((region) => region.scrollTop),
                          },
                      }
                    : {}),
            }),
        );
        return url.pathname + url.search + url.hash;
    };
    const navigate = (event: MouseEvent<Element>) => {
        if (
            !browse ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;
        event.preventDefault();
        router.visit(destination(true));
    };
    return (
        <Link
            {...props}
            href={destination()}
            onClick={navigate}
            data-hub-return-key={returnKey}
        />
    );
}
