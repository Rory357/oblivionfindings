/** Props for Alerts (S&D rebuild drawing pg-sdr-13, with Rules & automations 13b), from AlertsPresenter. */

export type AlertsTab = 'open' | 'snoozed' | 'done' | 'rules';

export type AlertTone = 'critical' | 'warning' | 'success';

/** A server line with a time in it: "{time}", "{ago}", "{for}" or "{closed}" take `at`, in NZ time. */
export type TimedLine = { text: string; at: string | null };

export type AlertQueueRow = {
    id: number;
    title: string;
    tone: AlertTone;
    state: 'open' | 'snoozed' | 'done';
    opened_at: string;
    closed_at: string | null;
    snoozed_until: string | null;
    /** "Site · who has it"; "{closed}" and "{snoozed}" take `closed_at` and `snoozed_until`. */
    sub: string;
    badge: { tone: AlertTone; text: string };
};

export type AlertTimelineRow = {
    id: number;
    at: string;
    tone: 'critical' | 'warning' | 'success' | 'info';
    title: string;
    sub: string | null;
};

export type SelectedAlert = {
    id: number;
    title: string;
    tone: AlertTone;
    state: 'open' | 'snoozed' | 'done';
    /** "Needs someone now · offline {for}", "Snoozed until {time}", "Resolved · {closed}". */
    label: TimedLine;
    opened_at: string;
    closed_at: string | null;
    device: { id: number; name: string; sub: string } | null;
    /** Fact chips: "**bold**" marks the figure; "{time}" takes `at`. */
    facts: {
        icon: 'map-pin' | 'clock' | 'network' | string;
        text: string;
        at: string | null;
    }[];
    /** What we saw; "{time}" is when it started. */
    saw: string;
    affects: { count: number; text: string } | null;
    timeline: AlertTimelineRow[];
    acknowledged: { by: string; at: string | null; note: string | null } | null;
    assigned: { name: string; at: string | null } | null;
    can: {
        acknowledge: boolean;
        assign: boolean;
        snooze: boolean;
        report_incident: boolean;
        /** Safety alerts and wrong sensor logins don’t clear by themselves: a person marks them done. */
        done?: boolean;
        /** S&D Phase F: a scald-risk alert can raise its H&S hazard once. */
        raise_hazard?: boolean;
    };
    /** S&D Phase F: the H&S hazard raised from this alert. */
    hazard_url?: string | null;
};

export type DoneRow = {
    id: number;
    title: string;
    site: string | null;
    opened_at: string;
    closed_at: string | null;
    lasted: string;
    how: string;
};

export type AlertRuleRow = {
    id: number;
    version: number;
    name: string;
    when: string;
    sub: string;
    icon: 'wifi-off' | 'bell' | string;
    where: string;
    then: string;
    on: boolean;
    can_change: boolean;
};

export type AlertsRules = { rows: AlertRuleRow[] };

/** What Add alert rule chooses from (an optional prop, loaded when it opens). */
export type RuleForm = {
    roles: { value: string; label: string }[];
    /** A rule for every Site needs access to every Site. */
    can_all_sites: boolean;
};

/** A device Quiet time can pause or a rule can name: network gear, computers and building systems. */
export type PickableDevice = {
    id: number;
    name: string;
    site_id: number;
    site: string;
    kind: string;
    domain: string;
};

export type AlertsProps = {
    tab: AlertsTab;
    counts: Record<AlertsTab, number>;
    site: string;
    sites: { value: string; label: string }[];
    q?: string | null;
    meters: {
        needs_now: { count: number; caption: string };
        waiting: { count: number; caption: string | TimedLine };
        quiet: { text: string; caption: string | TimedLine };
    };
    queue: AlertQueueRow[];
    done: {
        rows: DoneRow[];
        page: number;
        pages: number;
        total: number;
    } | null;
    selected: SelectedAlert | null;
    rules: AlertsRules | null;
    per_week?: { labels: string[]; values: number[] } | null;
    can: {
        acknowledge: boolean;
        quiet_time: boolean;
        add_rule: boolean;
        report_incident: boolean;
    };
    /** Optional, loaded when Quiet time or Add alert rule opens. */
    alertDevices?: PickableDevice[];
    /** Optional, loaded when Add alert rule opens. */
    ruleForm?: RuleForm | null;
};
