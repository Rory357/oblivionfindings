import { formatDateTime, formatTime } from '@/lib/datetime';

export type Position = {
    lat: number;
    lng: number;
    timestamp: string;
    accuracy?: number | null;
    address?: string | null;
};
export type Source = {
    id: string;
    label: string;
    reference: string;
    retentionDays: number | null;
    purpose: string;
};
export type Person = {
    id: string;
    recordId: number;
    kind: 'client' | 'staff';
    name: string;
    reference: string;
    site: { id: number; name: string };
    authority: string;
    sources: Source[];
    sourceChoiceRequired?: boolean;
    position: Position | null;
    positionState: 'recent' | 'stale' | 'unknown';
    battery: number | null;
    batteryAt: string | null;
    power: string;
    powerAt: string | null;
    motion: string;
    motionAt: string | null;
    contactAt: string | null;
    profileUrl: string;
    transportUrl: string | null;
};
export type Sample = {
    at: string;
    battery: number | null;
    power: string;
    motion: string | null;
    event: string | null;
};
export type Journey = {
    id: number;
    reference: string;
    departedAt: string | null;
    arrivedAt: string | null;
    accountedAt: string | null;
    destination?: string | null;
    purpose?: string;
    status: string;
    href: string;
};
export type History = {
    needsSource: boolean;
    scope?: string;
    journey?: Journey | null;
    personId: string;
    name?: string;
    source?: Source;
    fingerprint?: string;
    positions?: Position[];
    samples?: Sample[];
    journeys?: Journey[];
    truncated?: boolean;
    window?: { from: string; to: string; timezone: string };
};
export type ResponseAlert = {
    id: number;
    reference: string;
    personId: string;
    type: string;
    status: string;
    severity: string;
    owner: string | null;
    dueAt: string | null;
    nextAction: string;
    triggeredAt: string | null;
    href: string;
};
export type Preferences = {
    value: {
        population: string;
        site: string;
        peopleView: 'cards' | 'list';
        boundaries: boolean;
    };
    revision: number;
    siteUnavailable: boolean;
    sites: { id: number; name: string }[];
};
export type Boundary = {
    id: number;
    name: string;
    revision: number;
    href: string;
    geometry:
        | {
              type: 'circle';
              center: { lat: number; lng: number };
              radius_m: number;
          }
        | { type: 'polygon'; coordinates: { lat: number; lng: number }[] };
};
export type Workspace = {
    preferences: Preferences;
    boundaries: Boundary[];
    canViewHistory: boolean;
    people: Person[];
    sites: { id: number; name: string }[];
    history: History | null;
    alerts: ResponseAlert[];
    checkedAt: string;
    canReadAlerts: boolean;
    canExport: boolean;
    staffAvailable: boolean;
    filters: {
        population: string;
        site: string;
        selected: string;
        source: string;
        date: string;
    };
};
export type View =
    | 'map'
    | 'people'
    | 'analytics'
    | 'alerts'
    | 'history'
    | 'settings';
export const labels: Record<string, string> = {
    recent: 'Recent position',
    stale: 'Last known · stale',
    unknown: 'Unknown / unavailable',
    charging: 'Charging',
    full: 'Fully charged',
    not_charging: 'Not charging',
    external: 'External power reported',
    moving: 'Moving',
    stationary: 'Stationary / rest',
};
export function cohortLabel(
    cohort: string,
    sites: Workspace['sites'] = [],
): string {
    if (cohort.includes('|'))
        return cohort
            .split('|')
            .map((part) => cohortLabel(part, sites))
            .join(' · ');
    const [kind, value] = cohort.split(':');
    if (kind === 'site')
        return (
            sites.find((site) => String(site.id) === value)?.name ??
            'Selected site'
        );
    if (kind === 'positionAge' || kind === 'batteryAge') {
        const ages: Record<string, string> = {
            recent: 'up to 15 minutes',
            hour: '15–60 minutes',
            day: '1–24 hours',
            older: 'over 24 hours',
            unknown: 'not reported',
        };
        return `${kind === 'positionAge' ? 'Position' : 'Battery'} age: ${ages[value] ?? 'not reported'}`;
    }
    if (kind === 'battery')
        return (
            (
                {
                    low: 'Battery: 20% or below',
                    medium: 'Battery: 21–50%',
                    high: 'Battery: over 50%',
                    unknown: 'Battery not supplied',
                } as Record<string, string>
            )[value] ?? 'Battery filter'
        );
    if (kind === 'missing')
        return (
            (
                {
                    'source-choice': 'Choose a source',
                    'no-source': 'No source assigned',
                    'no-position': 'No position reported',
                } as Record<string, string>
            )[value] ?? 'Missing position'
        );
    if (value === 'not-recent') return 'Without a recent position';
    return labels[value] ?? 'Selected evidence';
}
export const normalise = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
export function filterPeople(
    people: Person[],
    query: string,
    cohort: string,
    sort: string,
    checkedAt: string | number = Date.now(),
): Person[] {
    const q = normalise(query.trim());
    return people
        .filter(
            (p) =>
                (!q ||
                    normalise(
                        [
                            p.name,
                            p.reference,
                            p.site.name,
                            ...p.sources.flatMap((s) => [s.label, s.reference]),
                        ].join(' '),
                    ).includes(q)) &&
                matches(p, cohort, checkedAt),
        )
        .sort((a, b) => {
            if (sort === 'battery')
                return (
                    (a.battery ?? Infinity) - (b.battery ?? Infinity) ||
                    a.name.localeCompare(b.name)
                );
            if (sort === 'position')
                return (
                    Date.parse(b.position?.timestamp ?? '1970-01-01') -
                        Date.parse(a.position?.timestamp ?? '1970-01-01') ||
                    a.name.localeCompare(b.name)
                );
            if (sort === 'attention')
                return (
                    { unknown: 0, stale: 1, recent: 2 }[a.positionState] -
                        { unknown: 0, stale: 1, recent: 2 }[b.positionState] ||
                    a.name.localeCompare(b.name)
                );
            return a.name.localeCompare(b.name);
        });
}
export function matches(
    p: Person,
    cohort: string,
    checkedAt: string | number = Date.now(),
): boolean {
    if (!cohort || cohort === 'all') return true;
    if (cohort.includes('|'))
        return cohort.split('|').every((part) => matches(p, part, checkedAt));
    const [kind, value] = cohort.split(':');
    if (kind === 'positionAge')
        return ageBand(p.position?.timestamp, checkedAt) === value;
    if (kind === 'batteryAge') return ageBand(p.batteryAt, checkedAt) === value;
    if (kind === 'position')
        return value === 'not-recent'
            ? p.positionState !== 'recent'
            : p.positionState === value;
    if (kind === 'missing') {
        if (p.positionState !== 'unknown') return false;
        if (value === 'source-choice')
            return p.sourceChoiceRequired ?? p.sources.length > 1;
        if (value === 'no-source') return p.sources.length === 0;
        if (value === 'no-position')
            return (
                p.sources.length > 0 &&
                !(p.sourceChoiceRequired ?? p.sources.length > 1)
            );
        return false;
    }
    if (kind === 'site') return String(p.site.id) === value;
    if (kind === 'battery') return batteryBand(p) === value;
    if (kind === 'power') return p.power === value;
    if (kind === 'motion') return p.motion === value;
    return false;
}
export function ageBand(
    at: string | null | undefined,
    checkedAt: string | number = Date.now(),
) {
    if (!at) return 'unknown';
    const age =
        (typeof checkedAt === 'number' ? checkedAt : Date.parse(checkedAt)) -
        Date.parse(at);
    if (!Number.isFinite(age) || age < 0) return 'unknown';
    return age <= 15 * 60000
        ? 'recent'
        : age <= 3600000
          ? 'hour'
          : age <= 86400000
            ? 'day'
            : 'older';
}
export function batteryBand(p: Pick<Person, 'battery'>) {
    return p.battery === null
        ? 'unknown'
        : p.battery <= 20
          ? 'low'
          : p.battery <= 50
            ? 'medium'
            : 'high';
}
export function distribution<T>(
    rows: T[],
    keys: string[],
    classify: (row: T) => string,
) {
    return keys.map((key) => ({
        key,
        name: labels[key] ?? key.replaceAll('_', ' '),
        value: rows.filter((p) => classify(p) === key).length,
    }));
}
export function batterySeries(samples: Sample[]) {
    const points: { at: number; battery: number | null }[] = [];
    const sorted = [...samples].sort(
        (a, b) => Date.parse(a.at) - Date.parse(b.at),
    );
    sorted.forEach((s, i) => {
        const at = Date.parse(s.at),
            previous = i ? Date.parse(sorted[i - 1].at) : at;
        if (at - previous > 30 * 60_000)
            points.push({ at: previous + 1, battery: null });
        points.push({ at, battery: s.battery });
    });
    return points;
}
export function hourlySamples(samples: Sample[], window?: History['window']) {
    if (!window || Date.parse(window.from) > Date.parse(window.to)) return [];
    const from = Date.parse(window.from),
        to = Date.parse(window.to);
    const rows: { at: number; count: number; partial: boolean }[] = [];
    for (let at = from; at < to; at += 3600_000) {
        const end = Math.min(at + 3600_000, to);
        rows.push({
            at,
            count: samples.filter(
                (s) =>
                    Date.parse(s.at) >= at &&
                    (Date.parse(s.at) < end ||
                        (end === to && Date.parse(s.at) === end)),
            ).length,
            partial: end - at < 3600_000,
        });
    }
    return rows;
}
export const time = (value?: string | number | null) =>
    formatDateTime(value, 'Not reported');
export const clockTime = (value: number) => formatTime(value);

export function observationGaps(samples: Sample[]) {
    const ordered = [...samples].sort(
        (a, b) => Date.parse(a.at) - Date.parse(b.at),
    );
    return ordered.flatMap((sample, i) => {
        if (!i) return [];
        const from = Date.parse(ordered[i - 1].at),
            to = Date.parse(sample.at);
        return to - from > 30 * 60_000 ? [{ from, to }] : [];
    });
}
