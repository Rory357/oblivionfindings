import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import type {
    ConfigOption,
    MaintenanceWindowData,
    OptionGroup,
    SignalOutboxRow,
    SignalRuleData,
    SignalSourceOption,
    SignalTypeOption,
    TriageQueueData,
} from '@/components/control-room/settings/types';

/**
 * What each Settings view can be narrowed by. Every view has its own filters
 * in the header (DESIGN.md "Filterless rail tabs"), all run on the lists the
 * page already has, so the header numbers stay true for the whole view.
 */
export type OnOff = 'all' | 'on' | 'off';

export type SettingsFilterState = {
    /** Routing */
    q: string;
    area: string;
    severity: string;
    rulesOn: OnOff;
    /** Queues */
    queuesOn: OnOff;
    moveOn: 'all' | 'yes' | 'no';
    /** Sources */
    sourceState: 'all' | SourceState;
    /** Maintenance */
    windowStatus: 'all' | MaintenanceWindowData['status'];
    /** Delivery recovery */
    problem: 'all' | SignalOutboxRow['status'];
    /** Options */
    optionGroup: 'all' | OptionGroup;
    optionsOn: OnOff;
};

export const NO_FILTERS: SettingsFilterState = {
    q: '',
    area: 'all',
    severity: 'all',
    rulesOn: 'all',
    queuesOn: 'all',
    moveOn: 'all',
    sourceState: 'all',
    windowStatus: 'all',
    problem: 'all',
    optionGroup: 'all',
    optionsOn: 'all',
};

export const ON_OFF_OPTIONS: Array<{ value: OnOff; label: string }> = [
    { value: 'all', label: 'On and off' },
    { value: 'on', label: 'On' },
    { value: 'off', label: 'Off' },
];

const matchesOnOff = (filter: OnOff, on: boolean) =>
    filter === 'all' || on === (filter === 'on');

/* ------------------------------------------------------------------ */
/*  Routing: areas, severity, search                                   */
/* ------------------------------------------------------------------ */

/** Rules with no signal type match every signal, so they get their own heading. */
export const ANY_SIGNAL_AREA = 'any';

/** SignalType categories in plain words (SignalType::categories()), in this order. */
const AREA_LABELS: Record<string, string> = {
    people_safety: 'People safety',
    medical_wellbeing: 'Medical and wellbeing',
    home_facility: 'Home and facility',
    fleet: 'Fleet and vehicles',
    assets: 'Assets',
    security: 'Security',
    compliance: 'Compliance',
};
const AREA_ORDER = Object.keys(AREA_LABELS);

export function areaLabel(area: string): string {
    if (area === ANY_SIGNAL_AREA) return 'Any signal type';
    return AREA_LABELS[area] ?? plainLabel(area);
}

/** The area a rule belongs to: its signal type's category. */
export function ruleArea(rule: SignalRuleData, signalTypes: SignalTypeOption[]): string {
    if (rule.signal_type_id === null && !rule.signal_type_code) return ANY_SIGNAL_AREA;
    const type =
        signalTypes.find((t) => t.id === rule.signal_type_id) ??
        signalTypes.find((t) => t.code === rule.signal_type_code);
    return type?.category || ANY_SIGNAL_AREA;
}

/** A rule that sets no severity keeps the signal's own. */
export const INHERIT_SEVERITY = 'inherit';

export function ruleSeverity(rule: SignalRuleData): string {
    return rule.output_severity || INHERIT_SEVERITY;
}

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

export function severityOptions(rules: SignalRuleData[]) {
    const present = new Set(rules.map(ruleSeverity));
    const known = SEVERITY_ORDER.filter((s) => present.has(s));
    const other = [...present].filter((s) => s !== INHERIT_SEVERITY && !SEVERITY_ORDER.includes(s)).sort();
    return [
        { value: 'all', label: 'All severities' },
        ...[...known, ...other].map((value) => ({ value, label: plainLabel(value) })),
        ...(present.has(INHERIT_SEVERITY)
            ? [{ value: INHERIT_SEVERITY, label: "Keeps the signal's severity" }]
            : []),
    ];
}

function sortAreas(a: string, b: string): number {
    const rank = (area: string) =>
        area === ANY_SIGNAL_AREA ? 1000 : AREA_ORDER.includes(area) ? AREA_ORDER.indexOf(area) : 500;
    return rank(a) - rank(b) || areaLabel(a).localeCompare(areaLabel(b));
}

export function areaOptions(rules: SignalRuleData[], signalTypes: SignalTypeOption[]) {
    const areas = [...new Set(rules.map((rule) => ruleArea(rule, signalTypes)))].sort(sortAreas);
    return [
        { value: 'all', label: 'All areas' },
        ...areas.map((value) => ({ value, label: areaLabel(value) })),
    ];
}

export function filterRules(
    rules: SignalRuleData[],
    signalTypes: SignalTypeOption[],
    filters: Pick<SettingsFilterState, 'q' | 'area' | 'severity' | 'rulesOn'>,
): SignalRuleData[] {
    const query = filters.q.trim().toLowerCase();
    return rules.filter(
        (rule) =>
            (filters.area === 'all' || ruleArea(rule, signalTypes) === filters.area) &&
            (filters.severity === 'all' || ruleSeverity(rule) === filters.severity) &&
            matchesOnOff(filters.rulesOn, rule.is_active) &&
            (!query ||
                [rule.name, rule.signal_type_name, rule.signal_type_code, rule.signal_source_name]
                    .filter(Boolean)
                    .some((text) => (text as string).toLowerCase().includes(query))),
    );
}

export type RuleGroup = { area: string; label: string; rules: SignalRuleData[] };

/** Rules under area headings, each area in a fixed order and its rules in priority order. */
export function groupRulesByArea(rules: SignalRuleData[], signalTypes: SignalTypeOption[]): RuleGroup[] {
    const groups = new Map<string, SignalRuleData[]>();
    for (const rule of rules) {
        const area = ruleArea(rule, signalTypes);
        groups.set(area, [...(groups.get(area) ?? []), rule]);
    }
    return [...groups.keys()]
        .sort(sortAreas)
        .map((area) => ({ area, label: areaLabel(area), rules: groups.get(area)! }));
}

/* ------------------------------------------------------------------ */
/*  Queues                                                             */
/* ------------------------------------------------------------------ */

export const movesOn = (queue: TriageQueueData) => Boolean(queue.auto_escalate_after_minutes);

export function filterQueues(
    queues: TriageQueueData[],
    filters: Pick<SettingsFilterState, 'queuesOn' | 'moveOn'>,
): TriageQueueData[] {
    return queues.filter(
        (queue) =>
            matchesOnOff(filters.queuesOn, queue.is_active) &&
            (filters.moveOn === 'all' || movesOn(queue) === (filters.moveOn === 'yes')),
    );
}

/* ------------------------------------------------------------------ */
/*  Sources                                                            */
/* ------------------------------------------------------------------ */

/** A source is silent after 10 minutes with no heartbeat. */
export const SILENT_AFTER_MINUTES = 10;

export type SourceState = 'reporting' | 'silent' | 'never' | 'off';

/**
 * One state per source: off or in maintenance first (silence is expected),
 * then never heard from, then reporting or silent by its heartbeat.
 */
export function sourceState(source: SignalSourceOption, now: number = Date.now()): SourceState {
    if (source.status !== 'active') return 'off';
    if (!source.last_heartbeat_at) return 'never';
    const minutes = (now - new Date(source.last_heartbeat_at).getTime()) / 60000;
    return minutes > SILENT_AFTER_MINUTES ? 'silent' : 'reporting';
}

export const SOURCE_STATE_OPTIONS: Array<{ value: SettingsFilterState['sourceState']; label: string }> = [
    { value: 'all', label: 'Every source' },
    { value: 'reporting', label: 'Reporting' },
    { value: 'silent', label: `Silent over ${SILENT_AFTER_MINUTES} min` },
    { value: 'never', label: 'Never heard from' },
    { value: 'off', label: 'Off or in maintenance' },
];

export function filterSources(
    sources: SignalSourceOption[],
    filters: Pick<SettingsFilterState, 'sourceState'>,
    now: number = Date.now(),
): SignalSourceOption[] {
    return sources.filter(
        (source) => filters.sourceState === 'all' || sourceState(source, now) === filters.sourceState,
    );
}

/* ------------------------------------------------------------------ */
/*  Maintenance, delivery recovery, options                            */
/* ------------------------------------------------------------------ */

export const WINDOW_STATUS_OPTIONS: Array<{ value: SettingsFilterState['windowStatus']; label: string }> = [
    { value: 'all', label: 'Every status' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'active', label: 'On now' },
    { value: 'completed', label: 'Finished' },
    { value: 'cancelled', label: 'Cancelled' },
];

export const PROBLEM_OPTIONS: Array<{ value: SettingsFilterState['problem']; label: string }> = [
    { value: 'all', label: 'Every problem' },
    { value: 'failed', label: 'Failed' },
    { value: 'dead_letter', label: 'Gave up after retries' },
    { value: 'unroutable', label: 'Nowhere to send it' },
];

export function filterOptions(
    options: Record<string, ConfigOption[]>,
    filters: Pick<SettingsFilterState, 'optionsOn'>,
): Record<string, ConfigOption[]> {
    return Object.fromEntries(
        Object.entries(options ?? {}).map(([group, items]) => [
            group,
            items.filter((option) => matchesOnOff(filters.optionsOn, option.is_active)),
        ]),
    );
}
