/* Medication › Settings (eMAR P11): the page's settings model.
 *
 * The server sends every setting's definition (values, words, default and
 * which changes loosen a check), the saved values, who reviewed each one and
 * the change history. Drafts live on the page and survive moving between
 * tabs and views; nothing applies until a view is reviewed and saved.
 *
 * `loosens()` reads the same ranking the server uses (P11 v5 AUDIT §5: one
 * rule for the destructive confirm, the history label and put-back). */

export type ViewKey = 'rules' | 'rounds' | 'staff' | 'alerts' | 'history';

export type SettingDefinition = {
    group: string;
    key: string;
    scope: 'organisation' | 'site';
    section: string;
    label: string;
    options: { value: string; label: string }[];
    default: string;
    /** A whole number in this range (no options): the smallest and largest it accepts. */
    range: [number, number] | null;
    /** The words after a number ("minutes before the dose time"). */
    unit: string | null;
    /** A setting decided together with this one: reviewed, kept and listed as one. */
    paired_with: string | null;
    /** The words for a number switched off ("No renewal"). */
    off_label?: string | null;
    /** Off means "Not configured"; what happens until someone chooses. */
    when_not_configured?: string | null;
    /** Option values from loosest to strictest. */
    rank: string[] | null;
    numeric: {
        direction: 'higher_is_looser' | 'higher_is_stricter';
        off: string | null;
        off_is_loosest: boolean;
    } | null;
    /** A value that isn't one option or number (P11 B2): who gets an alert, a list of people. */
    kind?: 'alert' | 'people' | null;
    /** The name "Still to decide" uses ("Alert: Overdue doses"). */
    decide_label?: string | null;
    /** A house setting house managers change for their own houses (B2 Q3). */
    house_managed?: boolean;
    alert?: AlertMeta;
    /** The words for an empty list of people ("Nobody extra"). */
    empty_label?: string;
};

/** One medication alert in the catalogue (P11 v5 `ALERTS`). */
export type AlertMeta = {
    key: string;
    label: string;
    subline: string;
    /** Recipient groups offered, in order; decided ones are `locked` on. */
    groups: string[];
    locked: string[];
    group_labels: Record<string, { label: string; description: string }>;
    /** Always about a controlled medicine. */
    controlled: boolean;
    until: string;
    /** Channels that send today. */
    channels: string[];
};

/** Who gets one alert, as saved (canonical JSON on the server). */
export type AlertSetting = {
    inapp: boolean;
    email: boolean;
    push: boolean;
    follow_up: boolean;
    groups: string[];
    people: number[];
};

export type SettingGroupMeta = {
    key: string;
    view: ViewKey;
    effect: string;
    audit_event: string;
    keys: string[];
};

/** Null = still the default; nobody has deliberately saved or kept it. */
export type Reviewer = { by: string | null; at: string | null } | null;

export type HistoryEntry = {
    id: number;
    at: string | null;
    who: string | null;
    action: 'changed' | 'kept';
    group: string;
    key: string;
    site_id: number | null;
    site_name: string | null;
    view: ViewKey;
    section: string;
    label: string;
    before_text: string;
    after_text: string;
    /** The saved value before the change, so it can be put back. Null for a kept default. */
    before_value: string | null;
    loosens: boolean;
    note: string | null;
    event: string;
};

export type SettingsPayload = {
    groups: Record<string, SettingGroupMeta>;
    definitions: Record<string, Record<string, SettingDefinition>>;
    values: Record<string, Record<string, string>>;
    reviewed: Record<string, Record<string, Reviewer>>;
    site_values: Record<string, Record<string, Record<string, string>>>;
    site_reviewed: Record<string, Record<string, Record<string, Reviewer>>>;
    history: HistoryEntry[];
    can_manage_organisation: boolean;
    /** Names of people an alert value names, by id (added by the page). */
    people_names?: Record<string, string>;
    /** House names by id (added by the page). */
    site_names?: Record<string, string>;
    /** Who each alert group would tell at each house (added by the page). */
    alert_reach?: AlertReach;
};

/**
 * From the server's alert resolution (P11 B2 safety net): how many people
 * each group would tell at each house — all, and those with
 * controlled-medicine access — and the medication settings managers behind
 * them; and where each named person can get alerts.
 */
export type AlertReach = {
    houses: Record<
        string,
        {
            groups: Record<string, { all: number; controlled: number }>;
            fallback: { all: number; controlled: number };
        }
    >;
    people: Record<
        string,
        { ok: boolean; site_ids: number[]; controlled: boolean }
    >;
};

/** A house where an alert's choices would tell nobody. `nobody`: not even the safety net. */
export type FallbackHouse = { siteId: number; name: string; nobody: boolean };

/** Edited values only, by group then key. A key absent here is the saved
 * value. A house setting's key carries its house: "key@siteId". */
export type Draft = Record<string, Record<string, string>>;

export const siteSlot = (key: string, siteId: number) => `${key}@${siteId}`;

/** A house's saved value, or the setting's default. */
export const siteSavedValue = (
    s: SettingsPayload,
    group: string,
    key: string,
    siteId: number,
) =>
    s.site_values[siteId]?.[group]?.[key] ??
    definitionOf(s, group, key)?.default ??
    '';

export const siteDraftValue = (
    s: SettingsPayload,
    draft: Draft,
    group: string,
    key: string,
    siteId: number,
) =>
    draft[group]?.[siteSlot(key, siteId)] ??
    siteSavedValue(s, group, key, siteId);

export function parseAlert(value: string): AlertSetting | null {
    try {
        const v = JSON.parse(value) as AlertSetting;
        if (!v || typeof v !== 'object') return null;
        if (
            !['inapp', 'email', 'push', 'follow_up'].every(
                (k) => typeof v[k as keyof AlertSetting] === 'boolean',
            )
        )
            return null;
        if (!Array.isArray(v.groups) || !Array.isArray(v.people)) return null;
        return v;
    } catch {
        return null;
    }
}

/** The server's canonical form: groups in catalogue order with decided ones on, people unique and in order. */
export function encodeAlert(meta: AlertMeta, a: AlertSetting): string {
    const chosen = new Set([...a.groups, ...meta.locked]);
    return JSON.stringify({
        inapp: meta.locked.length ? true : a.inapp,
        email: a.email,
        push: a.push,
        follow_up: a.follow_up,
        groups: meta.groups.filter((g) => chosen.has(g)),
        people: [...new Set(a.people)].sort((x, y) => x - y),
    });
}

export function parsePeople(value: string): number[] | null {
    try {
        const v = JSON.parse(value);
        return Array.isArray(v) && v.every((x) => Number.isInteger(x) && x > 0)
            ? v
            : null;
    } catch {
        return null;
    }
}

export const encodePeople = (ids: number[]) =>
    JSON.stringify([...new Set(ids)].sort((x, y) => x - y));

const personName = (names: Record<string, string> | undefined, id: number) =>
    names?.[id] ?? 'A former staff member';

/**
 * Houses where this alert's groups, named people and house extras (as
 * drafted) would tell nobody, so it goes to medication settings managers —
 * worked out like the server resolves an alert. Everyone rostered is counted
 * against the roster when the page loaded.
 */
export function fallbackHouses(
    s: SettingsPayload,
    draft: Draft,
    alertKey: string,
): FallbackHouse[] {
    const meta = definitionOf(s, 'alerts', alertKey)?.alert;
    const reach = s.alert_reach;
    const a = parseAlert(draftValue(s, draft, 'alerts', alertKey));
    if (!meta || !reach || !a) return [];
    const level = meta.controlled ? 'controlled' : 'all';
    const canGet = (id: number, siteId: number) => {
        const p = reach.people[id];
        return (
            !!p &&
            p.ok &&
            p.site_ids.includes(siteId) &&
            (!meta.controlled || p.controlled)
        );
    };
    return Object.entries(reach.houses).flatMap(([sid, house]) => {
        const siteId = Number(sid);
        const extras =
            parsePeople(
                siteDraftValue(s, draft, 'alertExtra', alertKey, siteId),
            ) ?? [];
        const told =
            a.groups.some(
                (g) =>
                    g === 'staffMember' || (house.groups[g]?.[level] ?? 0) > 0,
            ) ||
            a.people.some((id) => canGet(id, siteId)) ||
            extras.some((id) => canGet(id, siteId));
        return told
            ? []
            : [
                  {
                      siteId,
                      name: s.site_names?.[siteId] ?? 'a house',
                      nobody: (house.fallback[level] ?? 0) === 0,
                  },
              ];
    });
}

const houseList = (names: string[]) =>
    names.length > 2
        ? `${names.slice(0, 2).join(', ')} and ${names.length - 2} more ${names.length - 2 === 1 ? 'house' : 'houses'}`
        : names.join(' and ');

/** The plain warnings for houses that fall back (Main, 2 Oct). */
export function fallbackWarnings(list: FallbackHouse[]): string[] {
    const net = list.filter((h) => !h.nobody).map((h) => h.name);
    const none = list.filter((h) => h.nobody).map((h) => h.name);
    return [
        ...(net.length
            ? [
                  `Nobody at ${houseList(net)} in these groups — goes to medication settings managers`,
              ]
            : []),
        ...(none.length
            ? [
                  `Nobody at ${houseList(none)} in these groups or among medication settings managers — nobody would be told`,
              ]
            : []),
    ];
}

/** "in-app" · "in-app or email" · "in-app, email or push" — the channels that send today. */
export const channelWords = (channels: string[]) => {
    const words = channels.map(
        (c) => ({ inapp: 'in-app', email: 'email', push: 'push' })[c] ?? c,
    );
    return words.length > 1
        ? `${words.slice(0, -1).join(', ')} or ${words.at(-1)}`
        : (words[0] ?? 'in-app');
};

export const VIEW_LABEL: Record<ViewKey, string> = {
    rules: 'Medication rules',
    rounds: 'Rounds & timing',
    staff: 'Staff & PINs',
    alerts: 'Alerts & access',
    history: 'Change history',
};

export function definitionOf(
    s: SettingsPayload,
    group: string,
    key: string,
): SettingDefinition | undefined {
    return s.definitions[group]?.[key];
}

export const isNumber = (def: SettingDefinition) =>
    !def.kind && def.options.length === 0 && def.range !== null;

/** The value meaning "switched off", for a number that can be off. */
export const offValue = (def: SettingDefinition) =>
    isNumber(def) ? (def.numeric?.off ?? null) : null;

/** Is this a value the setting accepts? (The server checks the same.) */
export function accepts(def: SettingDefinition, value: string): boolean {
    if (def.kind === 'alert') {
        const a = parseAlert(value);
        return !!a && a.groups.every((g) => def.alert!.groups.includes(g));
    }
    if (def.kind === 'people') return parsePeople(value) !== null;
    if (isNumber(def)) {
        if (offValue(def) !== null && value === offValue(def)) return true;
        const [min, max] = def.range!;
        return /^\d{1,6}$/.test(value) && +value >= min && +value <= max;
    }
    return def.options.some((o) => o.value === value);
}

/** What to say when a number isn't accepted (the server's words). */
export const numberError = (def: SettingDefinition) =>
    `Enter a whole number from ${def.range![0].toLocaleString('en-NZ')} to ${def.range![1].toLocaleString('en-NZ')}${offValue(def) !== null ? ', or switch it off' : ''}.`;

/** The words for a value (the server's words). `names` names the people an alert value lists. */
export function format(
    def: SettingDefinition,
    value: string,
    names?: Record<string, string>,
): string {
    if (def.kind === 'alert') {
        const a = parseAlert(value);
        if (!a) return value;
        const meta = def.alert!;
        const channels = meta.channels.map(
            (c) =>
                `${{ inapp: 'In-app', email: 'email', push: 'push' }[c] ?? c} ${a[c as 'inapp' | 'email' | 'push'] ? 'on' : 'off'}`,
        );
        const who = [
            ...a.groups.map((g) => meta.group_labels[g]?.label ?? g),
            ...a.people.map((id) => personName(names, id)),
        ];
        return [...channels, who.length ? who.join(', ') : 'nobody'].join(
            ' · ',
        );
    }
    if (def.kind === 'people') {
        const ids = parsePeople(value) ?? [];
        return ids.length
            ? ids.map((id) => personName(names, id)).join(', ')
            : (def.empty_label ?? 'Nobody');
    }
    if (isNumber(def)) {
        if (offValue(def) !== null && value === offValue(def))
            return def.off_label ?? 'Off';
        return [value, def.unit].filter(Boolean).join(' ');
    }
    return def.options.find((o) => o.value === value)?.label ?? value;
}

/** A setting still off where off means "Not configured". */
export const notConfigured = (
    def: SettingDefinition | undefined,
    value: string,
) => !!def?.when_not_configured && value === offValue(def);

/** Does changing from one value to another turn a check off or make it less strict? */
export function loosens(
    def: SettingDefinition,
    from: string,
    to: string,
): boolean {
    if (from === to) return false;
    if (def.kind === 'alert') {
        const a = parseAlert(from);
        const b = parseAlert(to);
        if (!a || !b) return false;
        return (
            (['inapp', 'email', 'push', 'follow_up'] as const).some(
                (k) => a[k] && !b[k],
            ) ||
            a.groups.some((g) => !b.groups.includes(g)) ||
            a.people.some((p) => !b.people.includes(p))
        );
    }
    if (def.kind === 'people') {
        const b = parsePeople(to) ?? [];
        return (parsePeople(from) ?? []).some((p) => !b.includes(p));
    }
    if (def.rank) {
        const before = def.rank.indexOf(from);
        const after = def.rank.indexOf(to);
        return before >= 0 && after >= 0 && after < before;
    }
    if (def.numeric) {
        const { off, direction, off_is_loosest } = def.numeric;
        if (from !== off && to === off) return off_is_loosest;
        // Switching a check on is never looser.
        if (from === off || from === '' || to === '') return false;
        const a = Number(from);
        const b = Number(to);
        if (!Number.isFinite(a) || !Number.isFinite(b)) return false;
        return direction === 'higher_is_looser' ? b > a : b < a;
    }
    return false;
}

export const savedValue = (s: SettingsPayload, group: string, key: string) =>
    s.values[group]?.[key] ?? definitionOf(s, group, key)?.default ?? '';

export const draftValue = (
    s: SettingsPayload,
    draft: Draft,
    group: string,
    key: string,
) => draft[group]?.[key] ?? savedValue(s, group, key);

export type Change = {
    group: string;
    key: string;
    /** The house, for a house setting. */
    site_id: number | null;
    view: ViewKey;
    section: string;
    label: string;
    from: string;
    to: string;
    fromText: string;
    toText: string;
    loosens: boolean;
};

/** Every unsaved change, optionally for one view. */
export function changes(
    s: SettingsPayload,
    draft: Draft,
    view?: ViewKey,
): Change[] {
    const out: Change[] = [];
    const push = (
        g: SettingGroupMeta,
        def: SettingDefinition,
        siteId: number | null,
        from: string,
        to: string,
    ) => {
        if (to === from) return;
        out.push({
            group: g.key,
            key: def.key,
            site_id: siteId,
            view: g.view,
            section: def.section,
            label:
                siteId === null
                    ? def.label
                    : `${def.label} at ${s.site_names?.[siteId] ?? 'a house'}`,
            from,
            to,
            fromText: format(def, from, s.people_names),
            toText: format(def, to, s.people_names),
            loosens: loosens(def, from, to),
        });
    };
    Object.values(s.groups).forEach((g) => {
        if (view && g.view !== view) return;
        g.keys.forEach((key) => {
            const def = definitionOf(s, g.key, key);
            if (!def) return;
            if (def.scope === 'site') {
                Object.entries(draft[g.key] ?? {}).forEach(([slot, to]) => {
                    const [k, site] = slot.split('@');
                    if (k !== key || !site) return;
                    push(
                        g,
                        def,
                        Number(site),
                        siteSavedValue(s, g.key, key, Number(site)),
                        to,
                    );
                });
                return;
            }
            const to = draft[g.key]?.[key];
            if (to === undefined) return;
            push(g, def, null, savedValue(s, g.key, key), to);
        });
    });
    return out;
}

export const isDirty = (
    s: SettingsPayload,
    draft: Draft,
    group: string,
    key: string,
) => {
    const to = draft[group]?.[key];
    return to !== undefined && to !== savedValue(s, group, key);
};

/** Has a house's value for this setting an unsaved change? */
export const isSiteDirty = (
    s: SettingsPayload,
    draft: Draft,
    group: string,
    key: string,
    siteId: number,
) => {
    const to = draft[group]?.[siteSlot(key, siteId)];
    return to !== undefined && to !== siteSavedValue(s, group, key, siteId);
};

/** Drop a view's (or every) edited value. */
export function withoutDrafts(
    s: SettingsPayload,
    draft: Draft,
    view?: ViewKey,
): Draft {
    if (!view) return {};
    const next: Draft = { ...draft };
    Object.values(s.groups).forEach((g) => {
        if (g.view === view) delete next[g.key];
    });
    return next;
}

export function withDraft(
    draft: Draft,
    group: string,
    key: string,
    value: string,
): Draft {
    return { ...draft, [group]: { ...(draft[group] ?? {}), [key]: value } };
}

export const reviewerOf = (
    s: SettingsPayload,
    group: string,
    key: string,
): Reviewer => s.reviewed[group]?.[key] ?? null;

/** A setting nobody has deliberately chosen: "Still to decide". */
export type Pending = {
    group: string;
    key: string;
    view: ViewKey;
    section: string;
    label: string;
    state: 'default' | 'nc';
    /** What happens until someone decides. */
    until: string;
};

/** The second of a pair is decided with the first, so it isn't listed on its own. */
export const isSecondOfPair = (s: SettingsPayload, def: SettingDefinition) => {
    if (!def.paired_with) return false;
    const keys = s.groups[def.group]?.keys ?? [];
    return keys.indexOf(def.paired_with) < keys.indexOf(def.key);
};

/** A pair is one decision: saving or keeping either half reviews it. */
export function decisionReviewer(
    s: SettingsPayload,
    group: string,
    key: string,
): Reviewer {
    const paired = definitionOf(s, group, key)?.paired_with;
    return (
        reviewerOf(s, group, key) ??
        (paired ? reviewerOf(s, group, paired) : null)
    );
}

/** The settings "Keep today's value" confirms together: one, or a pair. */
export function keptTogether(s: SettingsPayload, group: string, key: string) {
    const def = definitionOf(s, group, key);
    return def?.paired_with ? [key, def.paired_with] : [key];
}

export function stillToDecide(s: SettingsPayload): Pending[] {
    const out: Pending[] = [];
    Object.values(s.groups).forEach((g) => {
        g.keys.forEach((key) => {
            const def = definitionOf(s, g.key, key);
            if (!def || def.scope !== 'organisation') return;
            if (isSecondOfPair(s, def)) return;
            // Off where off means "Not configured": listed until someone
            // chooses a number, even after a deliberate save.
            if (notConfigured(def, savedValue(s, g.key, key))) {
                out.push({
                    group: g.key,
                    key,
                    view: g.view,
                    section: def.section,
                    label: def.label,
                    state: 'nc',
                    until: def.when_not_configured!,
                });
                return;
            }
            if (decisionReviewer(s, g.key, key)) return;
            const pair = def.paired_with
                ? definitionOf(s, g.key, def.paired_with)
                : undefined;
            const saved = savedValue(s, g.key, key);
            const value = format(def, saved, s.people_names);
            out.push({
                group: g.key,
                key,
                view: g.view,
                section: def.section,
                label: def.decide_label ?? def.label,
                state: 'default',
                until:
                    isNumber(def) && saved !== offValue(def)
                        ? `Today’s rule: ${pair ? `${value} within ${format(pair, savedValue(s, g.key, pair.key))}` : value}`
                        : `Behaves as: ${value}`,
            });
        });
    });
    return out;
}

/** Number settings in a view whose draft isn't a value they accept, by "group.key". */
export function validateView(
    s: SettingsPayload,
    draft: Draft,
    view: ViewKey,
): Record<string, string> {
    const errors: Record<string, string> = {};
    Object.values(s.groups).forEach((g) => {
        if (g.view !== view) return;
        g.keys.forEach((key) => {
            const def = definitionOf(s, g.key, key);
            const to = draft[g.key]?.[key];
            if (def && isNumber(def) && to !== undefined && !accepts(def, to))
                errors[`${g.key}.${key}`] = numberError(def);
            // P11 v5: an alert with every channel off tells nobody.
            const a =
                def?.kind === 'alert' && to !== undefined
                    ? parseAlert(to)
                    : null;
            if (
                def?.alert &&
                a &&
                !def.alert.channels.some(
                    (c) => a[c as 'inapp' | 'email' | 'push'],
                )
            )
                errors[`${g.key}.${key}`] =
                    `“${def.alert.label}”: turn on ${channelWords(def.alert.channels)} — otherwise nobody is told.`;
        });
    });
    return errors;
}

/** Can this history entry's earlier value be put back into the draft? */
export function canRestore(
    s: SettingsPayload,
    draft: Draft,
    h: HistoryEntry,
    canEdit: (group: string) => boolean,
): boolean {
    if (h.action !== 'changed' || h.before_value === null || h.site_id !== null)
        return false;
    const def = definitionOf(s, h.group, h.key);
    if (!def || !canEdit(h.group)) return false;
    if (!accepts(def, h.before_value)) return false;
    return draftValue(s, draft, h.group, h.key) !== h.before_value;
}
