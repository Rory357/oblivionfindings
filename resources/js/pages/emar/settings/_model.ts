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
};

/** Edited values only, by group then key. A key absent here is the saved value. */
export type Draft = Record<string, Record<string, string>>;

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
    def.options.length === 0 && def.range !== null;

/** The value meaning "switched off", for a number that can be off. */
export const offValue = (def: SettingDefinition) =>
    isNumber(def) ? (def.numeric?.off ?? null) : null;

/** Is this a value the setting accepts? (The server checks the same.) */
export function accepts(def: SettingDefinition, value: string): boolean {
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

export function format(def: SettingDefinition, value: string): string {
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
    Object.values(s.groups).forEach((g) => {
        if (view && g.view !== view) return;
        g.keys.forEach((key) => {
            const def = definitionOf(s, g.key, key);
            const to = draft[g.key]?.[key];
            if (!def || to === undefined) return;
            const from = savedValue(s, g.key, key);
            if (to === from) return;
            out.push({
                group: g.key,
                key,
                view: g.view,
                section: def.section,
                label: def.label,
                from,
                to,
                fromText: format(def, from),
                toText: format(def, to),
                loosens: loosens(def, from, to),
            });
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
            const value = format(def, saved);
            out.push({
                group: g.key,
                key,
                view: g.view,
                section: def.section,
                label: def.label,
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
