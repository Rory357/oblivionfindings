/** Only navigation metadata belongs in reading history. Protected content is reloaded. */
export const readerTrailLimit = 24;
export const readerReturnLabels = {
    hub: 'Back to the hub',
    news: 'Back to news',
    resources: 'Back to resources',
    pages: 'Back to pages',
    people: 'Back to people',
    teams: 'Back to teams',
    events: 'Back to events',
    campaigns: 'Back to campaigns',
    saved: 'Back to saved items',
    following: 'Back to following',
    updates: 'Back to updates',
    search: 'Back to search results',
    story: 'Back to the story',
    guide: 'Back to the guide',
    document: 'Back to the document',
    guides: 'Back to company guides',
    profile: 'Back to the work profile',
    event: 'Back to the event',
    team: 'Back to the team',
    campaign: 'Back to the campaign',
    publishing: 'Back to publishing',
    ideas: 'Back to ideas',
} as const;

export type ReaderFrame = {
    href: string;
    label: keyof typeof readerReturnLabels;
    focus?: { key?: string; href?: string };
    scroll: { window: number; regions: number[] };
};
export type ReaderTrail = { actor_id: number | null; frames: ReaderFrame[] };

const browseViews = new Set([
    'home',
    'news',
    'resources',
    'guides',
    'pages',
    'people',
    'teams',
    'events',
    'campaigns',
    'saved',
    'following',
    'updates',
    'shoutouts',
    'ideas',
    'services',
    'publishing',
]);
const object = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const actor = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const offset = (value: unknown): value is number =>
    typeof value === 'number' &&
    Number.isFinite(value) &&
    value >= 0 &&
    value <= 1_000_000;

/** Readers and shared browse pages only; never an external or editing destination. */
export function readerLocation(value: unknown): string | null {
    if (
        typeof value !== 'string' ||
        value.length > 6000 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        value.includes('\\') ||
        [...value].some((char) => char.charCodeAt(0) <= 32)
    )
        return null;
    try {
        const url = new URL(value, 'https://hub.invalid');
        if (
            url.origin !== 'https://hub.invalid' ||
            !/^\/company-hub(?:\/(?:read\/[a-z0-9_-]{1,180}|people\/[1-9][0-9]{0,14}|events\/[1-9][0-9]{0,14}|documents\/[1-9][0-9]{0,14}))?$/.test(
                url.pathname,
            )
        )
            return null;
        if (
            url.searchParams.has('event_workspace') ||
            url.searchParams.has('event_draft')
        )
            return null;
        const query = new URLSearchParams();
        const view = url.searchParams.get('view');
        if (url.pathname === '/company-hub' && view) {
            if (!browseViews.has(view)) return null;
            query.set('view', view);
        }
        const search = url.searchParams.get('search');
        if (url.pathname === '/company-hub' && search)
            query.set('search', search.slice(0, 150));
        const idea = url.searchParams.get('idea');
        const announcement = url.searchParams.get('announcement');
        if (url.pathname === '/company-hub' && announcement) {
            if (
                !/^[1-9][0-9]{0,9}$/.test(announcement) ||
                Number(announcement) > 2147483647
            )
                return null;
            query.set('announcement', announcement);
        }
        if (url.pathname === '/company-hub' && view === 'ideas' && idea) {
            if (!/^[1-9][0-9]{0,9}$/.test(idea) || Number(idea) > 2147483647)
                return null;
            query.set('idea', idea);
        }
        const date = url.searchParams.get('date');
        const recognition = url.searchParams.get('recognition');
        if (
            url.pathname === '/company-hub' &&
            view === 'shoutouts' &&
            recognition
        ) {
            if (
                !/^[1-9][0-9]{0,9}$/.test(recognition) ||
                Number(recognition) > 2147483647
            )
                return null;
            query.set('recognition', recognition);
        }
        if (/^\/company-hub\/events\//.test(url.pathname) && date) {
            if (
                !/^\d{4}-\d{2}-\d{2}$/.test(date) ||
                !Number.isFinite(Date.parse(date)) ||
                new Date(date).toISOString().slice(0, 10) !== date
            )
                return null;
            query.set('date', date);
        }
        const hash =
            url.hash === '#hub-conversation' ||
            (query.has('recognition') &&
                url.hash === `#kudos-${recognition}`) ||
            (/^#block-[a-zA-Z0-9_-]{1,60}$/.test(url.hash) &&
                (url.pathname.startsWith('/company-hub/read/') ||
                    (url.pathname === '/company-hub' &&
                        (!view || view === 'home'))))
                ? url.hash
                : '';
        return url.pathname + (query.size ? `?${query}` : '') + hash;
    } catch {
        return null;
    }
}

export function readerFrame(value: unknown): ReaderFrame | null {
    if (!object(value)) return null;
    const href = readerLocation(value.href);
    if (
        !href ||
        typeof value.label !== 'string' ||
        !Object.hasOwn(readerReturnLabels, value.label)
    )
        return null;
    const scroll = object(value.scroll) ? value.scroll : {};
    const focus = object(value.focus) ? value.focus : {};
    const key =
        typeof focus.key === 'string' && /^[\w:-]{1,100}$/.test(focus.key)
            ? focus.key
            : undefined;
    const focusHref = readerLocation(focus.href) ?? undefined;
    return {
        href,
        label: value.label as ReaderFrame['label'],
        ...(key || focusHref
            ? {
                  focus: {
                      ...(key ? { key } : {}),
                      ...(focusHref ? { href: focusHref } : {}),
                  },
              }
            : {}),
        scroll: {
            window: offset(scroll.window) ? scroll.window : 0,
            regions: Array.isArray(scroll.regions)
                ? scroll.regions
                      .slice(0, 12)
                      .map((value) => (offset(value) ? value : 0))
                : [],
        },
    };
}

export function readReaderTrail(
    value: unknown,
    actorId: number | null,
): ReaderTrail {
    const id = actor(actorId) ? actorId : null;
    if (
        !id ||
        !object(value) ||
        value.actor_id !== id ||
        !Array.isArray(value.frames)
    )
        return { actor_id: id, frames: [] };
    // Stop at an invalid ancestor; a malformed history must not skip backwards to
    // an unrelated reader. The current route remains the safe fallback.
    const frames: ReaderFrame[] = [];
    for (const item of value.frames.slice(-readerTrailLimit)) {
        const frame = readerFrame(item);
        if (!frame) frames.length = 0;
        else frames.push(frame);
    }
    return { actor_id: id, frames };
}

export function pushReaderFrame(
    value: unknown,
    actorId: number | null,
    origin: unknown,
): ReaderTrail {
    const current = readReaderTrail(value, actorId);
    const frame = readerFrame(origin);
    if (!current.actor_id || !frame) return current;
    const frames = [...current.frames];
    if (frames.at(-1)?.href === frame.href) frames.pop();
    frames.push(frame);
    return { ...current, frames: frames.slice(-readerTrailLimit) };
}

export function popReaderFrame(
    value: unknown,
    actorId: number | null,
): { trail: ReaderTrail; frame: ReaderFrame | null } {
    const current = readReaderTrail(value, actorId);
    const frames = [...current.frames];
    const frame = frames.pop() ?? null;
    return { trail: { ...current, frames }, frame };
}
