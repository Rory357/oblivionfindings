import { toDateInput } from '@/lib/datetime';

export function isOverviewOverdue(due: string | null, asOf: string): boolean {
    if (!due) return false;
    return /^\d{4}-\d{2}-\d{2}$/.test(due)
        ? due < toDateInput(asOf)
        : Date.parse(due) < Date.parse(asOf);
}

/** End is exclusive; a midnight return does not reserve the following day. */
export function bookingOverlapsOverviewDay(
    start: string | null,
    end: string | null,
    day: string,
    zone: string,
): boolean {
    if (
        !start ||
        !end ||
        !Number.isFinite(Date.parse(start)) ||
        !Number.isFinite(Date.parse(end))
    )
        return false;
    const format = new Intl.DateTimeFormat('en-CA', {
        timeZone: zone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const dateKey = (value: Date) => {
        const parts = format.formatToParts(value);
        return ['year', 'month', 'day']
            .map((key) => parts.find((part) => part.type === key)?.value)
            .join('-');
    };
    return (
        dateKey(new Date(start)) <= day &&
        dateKey(new Date(Date.parse(end) - 1)) >= day
    );
}

/** Saved browser data is untrusted. Retain only the known preference keys. */
export function normalizeOverviewFilters<T extends Record<string, string>>(
    value: unknown,
    defaults: T,
): T {
    const input =
        value && typeof value === 'object'
            ? (value as Record<string, unknown>)
            : {};
    const choices: Record<string, readonly string[]> = {
        view: ['overview', 'attention', 'upcoming', 'availability'],
        period: ['week', 'today'],
        attention: [
            'all',
            'returns',
            'restricted',
            'unassigned',
            'work',
            'evidence',
        ],
        due: ['all', 'overdue', 'today', 'undated'],
        sort: ['due', 'resource'],
        availability: [
            'all',
            'Available now',
            'In use',
            'Restricted',
            'Unknown',
        ],
        mapType: ['all', 'vehicle', 'asset'],
        mapFresh: ['all', 'stale'],
        agendaKind: ['all', 'booking', 'appointment', 'due', 'work'],
    };
    const result: Record<string, string> = { ...defaults };
    for (const key of Object.keys(defaults)) {
        const candidate = input[key];
        if (typeof candidate !== 'string') continue;
        if (key === 'q') result[key] = candidate.slice(0, 120);
        else if (key === 'site' && /^(all|[1-9]\d*)$/.test(candidate))
            result[key] = candidate;
        else if (
            key === 'agendaDay' &&
            (/^(all|today|tomorrow|rest)$/.test(candidate) ||
                /^\d{4}-\d{2}-\d{2}$/.test(candidate))
        )
            result[key] = candidate;
        else if (choices[key]?.includes(candidate)) result[key] = candidate;
    }
    return result as T;
}

/** Merge explicit browser imports without replacing a different account view. */
export function browserViewsToImport<T extends Record<string, string>>(
    account: { name: string; filters: T }[],
    local: { name: string; filters: T }[],
): { name: string; filters: T }[] {
    const names = new Set(account.map((item) => item.name.toLocaleLowerCase()));
    const additions: { name: string; filters: T }[] = [];
    for (const item of local) {
        if (
            account.some(
                (saved) =>
                    saved.name.toLocaleLowerCase() ===
                        item.name.toLocaleLowerCase() &&
                    JSON.stringify(saved.filters) ===
                        JSON.stringify(item.filters),
            )
        )
            continue;
        if (account.length + additions.length >= 6) break;
        let name = item.name;
        if (names.has(name.toLocaleLowerCase())) {
            let number = 1;
            do {
                const suffix =
                    number === 1 ? ' (browser)' : ` (browser ${number})`;
                name = `${item.name.slice(0, 40 - suffix.length)}${suffix}`;
                number++;
            } while (names.has(name.toLocaleLowerCase()));
        }
        names.add(name.toLocaleLowerCase());
        additions.push({ ...item, name });
    }
    return additions;
}

export function boundedOverviewPage(page: number, count: number): number {
    return Math.max(
        1,
        Math.min(
            Number.isFinite(page) ? Math.floor(page) : 1,
            Math.ceil(count / 5) || 1,
        ),
    );
}
