import type { SgTone } from '@/components/signature/blocks';
import type { StatusVariant } from '@/components/ui/status-badge';
import type { DeviceKind, DeviceState } from '@/lib/security-devices/device-words';
import type { DeviceFormData, TimedText } from './_types';

/**
 * The sensor page (pg-sdr-7) and the care tracker page (pg-sdr-8, 8b, 8c), built by
 * SensorPagePresenter and TrackerPagePresenter. Only the open tab's data is filled in. A null
 * value means "Not available", never 0. Server lines may hold "{time}", "{ago}" or "{date}".
 */

export type PageDevice = {
    id: number;
    name: string;
    kind: DeviceKind;
    kind_label: string;
    group: 'sensor' | 'tracker';
    state: DeviceState;
    badge: { label: string; tone: StatusVariant };
    model: string;
    site: string | null;
    site_id: number | null;
    address: string | null;
    watching: string;
    subline: TimedText[];
    back: { href: string; label: string };
};

export type PageCan = {
    edit: boolean;
    watch: boolean;
    move: boolean;
    assign: boolean;
    retire: boolean;
    upload: boolean;
    see_events: boolean;
};

export type FactLine = { label: string; text: string; at: string | null };

export type AlertLine = {
    key: string;
    at: string | null;
    tone: SgTone;
    title: string;
    sub: string | null;
    href?: string | null;
};

/* ------------------------------------------------------------------ */
/*  Sensor                                                             */
/* ------------------------------------------------------------------ */

export type SensorTab = 'nights' | 'readings' | 'alerts';
export type SensorRange = '24h' | '7d' | '30d';

export type SensorMeter = {
    key: string;
    label: string;
    value: string | null;
    caption: TimedText;
    tone: 'brand' | 'success' | 'warning' | 'critical';
    tab: SensorTab;
};

export type SensorSeries = {
    key: string;
    title: string;
    unit: string;
    start: string;
    step_seconds: number;
    points: (number | null)[];
    limits: { value: number; label: string }[];
};

export type SensorRuleRow = {
    key?: string;
    label: string;
    hint: string;
    enabled?: boolean;
    /** A sensor that keeps a resident safe can't turn this off (its battery alert). */
    locked?: boolean;
};

/** Who changed a sensor's alert limits, when, and what (newest first). */
export type SensorRuleChange = { at: string; by: string; changes: string[] };

export type SensorEditableMetricRule = {
    key: string;
    label: string;
    unit: string;
    mode: 'range' | 'threshold';
    enabled: boolean;
    below: number | null;
    above: number | null;
    warning_at: number | null;
    critical_at: number | null;
};

export type SensorEditableRules = {
    metrics: SensorEditableMetricRule[];
    battery: { enabled: boolean; below: number } | null;
};

export type SensorNightsData = {
    care_banner: {
        kicker: string;
        title: string;
        sub: string;
        night_hours: string;
        person_href: string | null;
    } | null;
    can_link: boolean;
    last_night: {
        sub: string;
        items: { key: string; time: string; tone: SgTone; title: string; sub: string | null }[];
        empty: string | null;
    };
    seven_nights: {
        labels: string[];
        /** Minutes out of bed each night; null when the night has no readings ("No readings", never 0). */
        values: (number | null)[];
        note: string;
    };
    readiness: {
        passed: number;
        of: number;
        label: string;
        sub: TimedText;
        checks: { state: SgTone; label: string; note: string | null }[];
    };
    /** Flag, room and key worker are null unless the viewer may open the resident's record. */
    person_card: {
        name: string;
        sub: string | null;
        flag: string | null;
        href: string | null;
        key_worker: string | null;
    } | null;
    what_it_senses: FactLine[];
};

export type SensorScaldAlert = {
    id: number;
    title: string;
    opened_at: string | null;
    hazard_raised: boolean;
    hazard_url: string | null;
    can_raise_hazard: boolean;
    alert_href: string;
};

export type SensorPageProps = {
    device: PageDevice;
    tab: SensorTab;
    tabs: { key: SensorTab; label: string }[];
    range: SensorRange;
    meters: SensorMeter[];
    can: PageCan & { manage_rules?: boolean };
    nights?: SensorNightsData | null;
    readings: {
        series: SensorSeries[];
        rules: SensorRuleRow[];
        /** Only for people who may see alerts. */
        rules_history?: SensorRuleChange[];
        editable_rules?: SensorEditableRules | null;
        where: { rows: FactLine[]; gateway_href: string | null };
    } | null;
    alerts: AlertLine[] | null;
    /** S&D Phase F + E6 (7b, 7c, pop-up 65): the person this sensor is linked to, and the scald watch. */
    care?: {
        link: { title: string; rule: string | null; plan: string | null; person_href: string | null; linked_at: string | null } | null;
        can_link: boolean;
        /** Safety fix round (F2): care alerts on this sensor stopped recently because the person's consent ended. */
        stopped?: { person: string; ended_at: string | null } | null;
        scald: { on: boolean; can_change: boolean; alert?: SensorScaldAlert | null } | null;
    };
    deviceForm?: DeviceFormData;
};

/* ------------------------------------------------------------------ */
/*  Care tracker                                                       */
/* ------------------------------------------------------------------ */

export type TrackerTab = 'location' | 'console';

export type TrackerMeter = {
    key: string;
    label: string;
    /** null: "Not available", unless `time` fills it. */
    value: string | null;
    time: string | null;
    caption: TimedText;
    tone: 'brand' | 'success' | 'warning' | 'critical';
    tab: TrackerTab;
};

export type TrackerCommandKey = 'request_location' | 'update_reporting_interval' | 'reboot' | 'read_config';

export type TrackerCommandOption = {
    key: TrackerCommandKey;
    label: string;
    sub: string;
    risky: boolean;
    /** The exact message, password hidden; per interval (minutes) for Change report interval. */
    message: string | null;
    messages: Record<string, string | null> | null;
};

export type TrackerCommands = {
    ready: boolean;
    reason: string | null;
    options: TrackerCommandOption[];
    /** How often it reports now, in seconds, when the unit has read its settings back. */
    interval: number | null;
    intervals: number[];
    /** Ask for position now is held back because the location can't be shown. */
    locate_off: boolean;
};

export type TrackerPrivacy = {
    shown: boolean;
    state: string;
    person: {
        kind: 'client' | 'staff';
        short: string | null;
        consent: { active: boolean; until: string | null; ended: string | null; details: boolean } | null;
    } | null;
};

export type TrackerLocation = {
    position: { lat: number; lng: number; accuracy_m: number | null; at: string | null; address: string | null } | null;
    fix: { tone: 'warning' | 'critical'; note: string; title: string | null; sub: string | null } | null;
    record_href: string | null;
    battery: { start: string; step_seconds: number; points: (number | null)[]; unit: string } | null;
    checkins: FactLine[];
    consent_history: (AlertLine & { sub_at: string | null })[] | null;
    linked: { name: string; sub: string | null; flag: { label: string; tone: StatusVariant } | null; href: string | null } | null;
};

export type ConsoleLine = { id: number; at: string | null; out: boolean; text: string; note: string | null };

export type ConsoleCommand = {
    id: number;
    at: string | null;
    title: string;
    sub: string;
    tone: SgTone;
    message: string;
    reply: string | null;
};

export type TrackerConsole = {
    host: string;
    lines: ConsoleLine[];
    commands: ConsoleCommand[];
    settings: { read_at: string | null; rows: [string, string][] };
    location_hidden: boolean;
};

export type TrackerPageProps = {
    device: PageDevice;
    tab: TrackerTab;
    tabs: { key: TrackerTab; label: string }[];
    meters: TrackerMeter[];
    can: PageCan & { see_console: boolean; command: boolean; locate: boolean };
    privacy: TrackerPrivacy;
    commands: TrackerCommands;
    location: TrackerLocation | null;
    console: TrackerConsole | null;
    deviceForm?: DeviceFormData;
};
