import rawCatalog from './catalog.json';

export type Role = keyof typeof rawCatalog;
export type Range = 'today' | 'week' | 'month' | 'quarter';
export type BlockKind =
    | 'list'
    | 'focus'
    | 'trend'
    | 'donut'
    | 'bars'
    | 'progress'
    | 'links';
export interface BlockMeta {
    id: string;
    title: string;
    description: string;
    category: string;
    kind: BlockKind;
    span: number;
    icon: string;
    next?: string;
    module?: string;
}
export interface RoleMeta {
    name: string;
    icon: string;
    description: string;
    range: Range;
    primary: string;
    blocks: BlockMeta[];
    starters: { name: string; description: string; ids: string[] }[];
}
export const catalog = rawCatalog as Record<Role, RoleMeta>;
export interface BlockLayout {
    id: string;
    span: number;
    height: number;
    site: string;
}
export interface SavedCopy {
    name: string;
    blocks: BlockLayout[];
    density: 'balanced' | 'compact';
}
export interface Layout {
    blocks: BlockLayout[];
    favorites: string[];
    copies: SavedCopy[];
    density: 'balanced' | 'compact';
    range: Range;
}
export interface Row {
    label: string;
    detail?: string;
    value?: number | null;
    target?: number;
    href?: string;
    date?: string | null;
    end?: string;
    status?: string;
    tone?: 'critical' | 'warning' | 'neutral';
    actionLabel?: string;
    sparkline?: (number | null)[];
    handover?: Row;
    followups?: Row;
    /** Read-only facts for the detail panel; a row with facts opens it. */
    facts?:
        | {
              label: string;
              value?: string | null;
              date?: string | null;
              dateTime?: string | null;
          }[]
        | null;
    history?:
        | {
              at: string;
              state: 'healthy' | 'degraded' | 'failed' | 'unknown';
              checks: number;
          }[]
        | null;
}
export interface BlockData {
    scopeMode?: 'site' | 'personal' | 'organisation';
    state: 'ready' | 'proposed' | 'restricted' | 'unavailable';
    title?: string;
    subtitle: string;
    message?: string;
    empty?: string;
    actionLabel?: string;
    /** Focus card: the quiet route shown when nothing is scheduled. */
    emptyActionLabel?: string;
    headline?: string;
    seriesLabel?: string;
    comparisonLabel?: string;
    unit?: '%';
    presentation?:
        | 'progress-rows'
        | 'links'
        | 'delivery'
        | 'pipeline'
        | 'site-status'
        | 'connected-teams'
        | 'service-health'
        | 'assurance'
        | 'decisions'
        | 'priorities';
    decisions?: Row[] | null;
    reviews?: Row[];
    reviewTotal?: number;
    reviewQueues?: Row[];
    sections?: {
        title: string;
        caption: string;
        state: 'ready' | 'restricted';
        rows: Row[];
    }[];
    coverage?: {
        date: string;
        required: number | null;
        assigned: number | null;
        gaps: number;
        unassessed: number;
        windows: number;
        rows: {
            id: number;
            name: string;
            cells: {
                id: string;
                starts: string;
                ends: string;
                assigned: number | null;
                required: number;
                gap: boolean;
                roleGap: boolean;
                href: string;
            }[][];
        }[];
    };
    footnote?: string;
    actionTarget?: 'attention';
    focusMeta?: string[];
    focusTag?: string;
    calendar?: {
        date: string;
        count: number;
        hours?: number;
        starts: string | null;
        ends: string | null;
    }[];
    href?: string | null;
    total: number | null;
    rows: Row[];
    series: {
        label: string;
        value: number | null;
        comparison?: number | null;
        completed?: number;
        planned?: number;
    }[];
    partial?: boolean;
    measurementKnown?: boolean;
}
export interface OverviewData {
    primary?: { label: string; href: string; target?: 'attention' };
    blocks: Record<string, BlockData>;
    metrics: {
        label: string;
        value: number | null;
        total?: number;
        caption: string;
        href?: string;
        target?: string;
        series?: number[];
        instrument?: 'bar' | 'donut';
        display?: string;
        percent?: number | null;
        instrumentCaption?: string;
        avatars?: string[];
        people?: { id: number; name: string; detail?: string }[];
    }[];
    urgent: Row[];
    partial: boolean;
    scope: string;
    updatedAt: string;
    period: { start: string; end: string };
}
export interface OverviewProps {
    role: Role;
    roles: Role[];
    /** The overview that opens first on sign-in. */
    defaultRole?: Role;
    sites: { id: number; name: string }[];
    filters: { site: string; range: Range };
    overview: OverviewData;
    preference: {
        value: Omit<Layout, 'blocks'> & { blocks: BlockLayout[] | null };
        revision: string | null;
    };
}

export function defaultBlocks(role: Role): BlockLayout[] {
    const defaults = catalog[role].blocks.slice(0, 6);
    if (role === 'hr') [defaults[2], defaults[4]] = [defaults[4], defaults[2]];
    return defaults.map((b, i) => ({
        id: b.id,
        span: role === 'worker' && i < 2 ? 6 : b.span,
        height: 0,
        site: 'page',
    }));
}

/** Fill each row without changing widths; output order is also keyboard/reading order. */
export function tidyBlocks(blocks: BlockLayout[]): BlockLayout[] {
    const remaining = blocks.map((b) => ({ ...b, height: 0 }));
    const result: BlockLayout[] = [];
    while (remaining.length) {
        const first = remaining.shift()!;
        result.push(first);
        let space = 12 - first.span;
        while (space > 0) {
            const exact = remaining.findIndex((b) => b.span === space);
            const index =
                exact >= 0
                    ? exact
                    : remaining.findIndex((b) => b.span <= space);
            if (index < 0) break;
            const [next] = remaining.splice(index, 1);
            result.push(next);
            space -= next.span;
        }
    }
    return result;
}

/**
 * Display only (never saved): widen the last block of each row so a filtered
 * view such as Insights has no holes where the hidden blocks were.
 */
export function fillRows(blocks: BlockLayout[]): BlockLayout[] {
    const result = blocks.map((b) => ({ ...b, height: 0 }));
    let used = 0;
    result.forEach((block, index) => {
        if (used > 0 && used + block.span > 12) {
            result[index - 1].span += 12 - used;
            used = 0;
        }
        used = (used + block.span) % 12;
    });
    if (used > 0) result[result.length - 1].span += 12 - used;
    return result;
}

export function moveBlock(
    blocks: BlockLayout[],
    id: string,
    delta: number,
): BlockLayout[] {
    const index = blocks.findIndex((b) => b.id === id);
    if (index < 0 || index + delta < 0 || index + delta >= blocks.length)
        return blocks;
    const next = [...blocks];
    const [block] = next.splice(index, 1);
    next.splice(index + delta, 0, block);
    return next;
}

export async function writeOverview(url: string, body: unknown) {
    const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        body: JSON.stringify(body),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok)
        throw new Error(
            result.message ??
                'Could not save. Your changes are still here; please try again.',
        );
    return result;
}
