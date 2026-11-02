/** Props for Alerts (S&D rebuild drawing pg-sdr-13, with Rules & automations 13b), from AlertsPresenter. */

export type AlertsTab =
    | 'open'
    | 'snoozed'
    | 'done'
    | 'rules'
    | 'quiet'
    | 'oncall';

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

/* S&D Phase F: house automations (pg-sdr-13b, pop-ups 40 and 41), from AutomationsPresenter. */

/** The only actions an automation may take (SafeActions::ACTIONS, D12). There is no disarm or unlock. */
export type SafeActionKey =
    | 'notify'
    | 'open_alert'
    | 'camera_privacy_on'
    | 'door_locked_check'
    | 'alarm_armed_check';

export type AutomationTriggerKind =
    | 'door_opened'
    | 'reading'
    | 'device_offline'
    | 'device_back'
    | 'time';

/** What starts it. `device_id` null is "any at the Site"; times are NZ wall clock ("21:00"). */
export type AutomationTrigger = {
    kind: AutomationTriggerKind;
    device_id?: number | null;
    metric?: string;
    direction?: 'above' | 'below';
    value?: number;
    at?: string;
    /** 1–7, Monday first. */
    days?: number[];
};

export type AutomationAction = {
    action: SafeActionKey;
    who?: 'on_shift' | 'role';
    role?: string | null;
    email?: boolean;
    push?: boolean;
    message?: string | null;
    cameras?: number[];
    until?: string;
    doors?: number[];
    panels?: number[];
};

export type AutomationDefinition = {
    site_id: number;
    name: string;
    is_on: boolean;
    trigger: AutomationTrigger;
    conditions: {
        between: { from: string; to: string } | null;
        /** The Site's house modes it runs in; empty runs in any mode. */
        modes?: number[];
    };
    actions: AutomationAction[];
};

export type AutomationRow = {
    id: number;
    version: number;
    name: string;
    /** "Front door opens between 10:00 pm and 6:00 am": the only-if folds into the when. */
    when: string;
    sub: string;
    icon: string;
    where: string;
    then: string;
    last_run: {
        at: string;
        test: boolean;
        outcome: 'ok' | 'partial' | 'failed';
    } | null;
    on: boolean;
    can_change: boolean;
    /** For Edit (the same wizard, prefilled); null without automations.manage. */
    definition: AutomationDefinition | null;
};

export type AutomationDeviceGroup =
    | 'doors'
    | 'sensors'
    | 'cameras'
    | 'panels'
    | 'any';

/** What Add automation chooses from (an optional prop, loaded when it opens). */
export type AutomationForm = {
    sites: { value: string; label: string }[];
    /** By Site id, then group: only devices the person can see there. */
    devices: Record<
        string,
        Partial<Record<AutomationDeviceGroup, { id: number; name: string }[]>>
    >;
    roles: { value: string; label: string }[];
    actions: { key: SafeActionKey; label: string; description: string }[];
    /** By Site id: its house modes, for "House mode is". A Site with none shows no choice. */
    modes?: Record<string, { id: number; name: string }[]>;
    metrics: {
        value: string;
        label: string;
        unit: string;
        min: number;
        max: number;
    }[];
    max_actions: number;
};

/** One run's lines (pop-up 41). */
export type AutomationRunResult = {
    id: number;
    test: boolean;
    at: string;
    summary: string;
    outcome: 'ok' | 'partial' | 'failed';
    lines: {
        tone: 'success' | 'warning' | 'critical' | 'info';
        text: string;
        at: string;
    }[];
    result: string;
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
    /** On call has no count. */
    counts: Record<Exclude<AlertsTab, 'oncall'>, number>;
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
    /** S&D Phase F: the house automations, in the same list as the rules (Rules & automations tab). */
    automations?: AutomationRow[] | null;
    per_week?: { labels: string[]; values: number[] } | null;
    /** S&D round 3: Quiet times (13c), on its tab only. */
    quiet_times?: QuietTimes | null;
    /** S&D round 3: On call (13d), on its tab only. */
    on_call?: OnCall | null;
    can: {
        acknowledge: boolean;
        quiet_time: boolean;
        add_rule: boolean;
        report_incident: boolean;
        /** securityDevices.automations.manage: add, change, test, turn off and remove automations. */
        add_automation?: boolean;
        /** pg-sdr-18b: Export (device events, last 90 days), for people who may see reports. */
        export?: boolean;
    };
    /** Optional, loaded when Quiet time or Add alert rule opens. */
    alertDevices?: PickableDevice[];
    /** Optional, loaded when Add alert rule opens. */
    ruleForm?: RuleForm | null;
    /** Optional, loaded when Add automation opens; null without automations.manage. */
    automationForm?: AutomationForm | null;
};

/** A note with a time in it ("Set {time}", "Until {time}"), or plain words. */
export type NoteLine = string | TimedLine;

/** Alerts › Quiet times (13c): one quiet time, from QuietTimesPresenter. */
export type QuietTimeRow = {
    id: number;
    version: number;
    scope: {
        site_id: number;
        device_id: number | null;
        monitor_id: number | null;
        monitor_device_id: number | null;
    };
    paused: {
        name: string;
        sub: string;
        icon: 'site' | 'building' | 'network';
        href: string | null;
    };
    /** The current or next occurrence, or the last one once it has ended. */
    when: {
        starts_at: string;
        ends_at: string;
        repeats: 'daily' | 'weekly' | null;
        until: string | null;
    };
    why: string;
    set_by: string | null;
    state: {
        label: string;
        tone: 'success' | 'warning' | 'info' | 'neutral';
        note: NoteLine;
    };
    can_end: boolean;
    ends_now: boolean;
};

export type QuietTimes = {
    rows: QuietTimeRow[];
    held: {
        at: string;
        tone: 'success' | 'warning';
        title: string;
        sub: NoteLine;
    }[];
    know: { tone: 'info' | 'warning'; text: string }[];
    can: { add: boolean; end: boolean };
};

/** Alerts › On call (13d), from OnCallPresenter. */
export type OnCall = {
    now: { name: string; first_name: string; until: string } | null;
    people: {
        id: number;
        name: string;
        role: string | null;
        when: 'now' | 'next' | 'before';
        from: string;
        to: string;
    }[];
    /** The IT role chosen in Setup › Alerts & checks; null = IT for each Site. */
    role: string | null;
    handover: {
        from: string;
        to: string | null;
        at: string;
        still_open: string | null;
        coming_up: string | null;
        good_to_know: string | null;
    } | null;
    waiting: {
        id: number;
        at: string;
        tone: 'critical' | 'warning';
        title: string;
        told_at: string | null;
        reminded_at: string | null;
    }[];
    delivery: {
        key: string;
        when: string;
        sub: string;
        app: boolean;
        email: boolean;
        push: boolean;
    }[];
    can: { rostering: boolean; handover: boolean; setup: boolean };
};
