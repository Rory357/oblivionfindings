/** Props for the Security & devices home (S&D rebuild drawings pg-sdr-1, 1b, 18, 18b and 18c). */
import type { DeviceState, DevicesTab } from '@/lib/security-devices/device-words';
import type { FactTone, SiteCard, StreamLine } from '../sites/_types';

export type OverviewTab = 'overview' | 'trends' | 'reports' | 'activity';

/** pg-sdr-18c: Activity's "Every kind" groups. */
export type ActivityGroup = 'devices' | 'alarm' | 'alerts' | 'doors' | 'planned' | 'hs';

export type ActivityData = {
    day: string;
    today: boolean;
    kind: ActivityGroup | 'all';
    kinds: { value: string; label: string }[];
    rows: (StreamLine & { group: ActivityGroup })[];
    total: number;
    overnight: { from: string; to: string; sites: string[]; rows: StreamLine[] };
    by_kind: { key: ActivityGroup; label: string; count: number }[];
    /** Doors worth a look are only in the stream for people who can see Access. */
    doors: boolean;
};

/** A person who gets, or may get, the monthly email. */
export type ReportPerson = { id: number; name: string };

/** pg-sdr-18b: one row of the camera viewing log. */
export type CameraViewRow = {
    key: string;
    at: string;
    who: string;
    camera: string;
    camera_id: number;
    kind: 'live' | 'clip';
    from: string | null;
    to: string | null;
    reason: string;
};

/** pg-sdr-18b: the monthly report (MonthlyReport::present). A null figure means "Not available". */
export type ReportsData = {
    month: { key: string; label: string; short: string; range: string; current: boolean; ready_at: string | null };
    months: { value: string; label: string }[];
    site_label: string;
    device_count: number;
    offline: { sites: { name: string; minutes: number; still_offline: boolean }[] } | null;
    alerts: { total: number; kinds: { label: string; count: number }[] };
    safety: {
        weeks: { label: string; minutes: number | null }[];
        month: { count: number; picked: number; not_picked: number; average_minutes: number | null };
    } | null;
    upkeep: { batteries: number; software: number; settings: number } | null;
    cameras: { cameras: number; with_hours: number; without_hours: number; percent: number | null; worst: { name: string; hours: number }[] } | null;
    camera_views: { rows: CameraViewRow[]; total: number; live: boolean; clips: boolean; export: string } | null;
    kept: { door_names_days: number; audit_years: number; months: number };
    links: { pdf: string; csv: string; devices: string; events: string; upkeep: string };
    email: { on: boolean; recipients: ReportPerson[]; next_at: string; can_change: boolean; choices: ReportPerson[] };
};

export type TimedLine = { text: string; at: string | null };

export type ActivityRow = { id: number; at: string | null; tone: FactTone; title: string; sub: string; href: string };

export type OverviewProps = {
    tab: OverviewTab;
    site: string;
    sites: { value: string; label: string }[];
    first_time: {
        site_count: number;
        steps: { label: string; meta: string; state: 'now' | 'todo' | 'done' }[];
    } | null;
    meters: {
        online: { count: number; of: number; caption: TimedLine };
        offline: { count: number; caption: TimedLine };
        cant_check: { count: number; caption: string };
        alerts: { count: number; caption: string };
    };
    overview: {
        attention: { key: string; at: string | null; tone: FactTone; title: string; sub: string; href: string; action: string }[];
        attention_count: number;
        sites: SiteCard[];
        cameras: { id: number; name: string; site: string | null; state: DeviceState; href: string }[];
        camera_count: number;
        hubs: { online: number; offline: number; not_installed: number; calm: string | null } | null;
        activity: ActivityRow[];
    } | null;
    trends: {
        online: { labels: string[]; online: (number | null)[]; watched: (number | null)[]; note: string } | null;
        kinds: { tab: DevicesTab; count: number }[];
        device_count: number;
        alerts_per_week: { labels: string[]; values: number[] } | null;
        batteries: { id: number; name: string; site: string | null; battery: number | null; href: string }[];
        slowest: { id: number; name: string; model: string; kind: string; site: string | null; week_ms: number; usual_ms: number | null; href: string }[];
    } | null;
    /** pg-sdr-18b: only on the Reports tab, for people who may see reports. */
    reports?: ReportsData | null;
    /** pg-sdr-18c: only on the Activity tab, for people who may see device events. */
    activity?: ActivityData | null;
    /**
     * add_hub / approve_hub: Add a hub (sdr-12) and approving it (sdr-79). reports: the Reports tab (18b).
     * activity: the Activity tab (18c); export: its Export…, the events CSV.
     */
    can: {
        add: boolean;
        scan: boolean;
        connect: boolean;
        assign: boolean;
        add_hub?: boolean;
        approve_hub?: boolean;
        reports?: boolean;
        activity?: boolean;
        export?: boolean;
    };
    view?: 'it';
};

/* ------------------------------------------------------------------ */
/*  pg-sdr-1c: the support worker's Overview (WorkerOverviewPresenter)  */
/* ------------------------------------------------------------------ */

export type WorkerTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

/** A line from the server; "{time}" is filled from `at` in NZ time. */
export type WorkerLine = { tone: WorkerTone | 'brand' | 'none'; text: string; at: string | null };

export type WorkerMeter = {
    key: 'alarm' | 'safety' | 'panic';
    label: string;
    value: string;
    caption: { text: string; at: string | null };
    tone: 'success' | 'warning' | 'critical' | null;
    /** The alarm meter opens its panel's page. */
    device_id: number | null;
};

export type WorkerAlert = {
    id: number;
    title: string;
    tone: 'critical' | 'warning';
    safety: boolean;
    /** Where the person opens it: the Alerts page, the alert on its own (care and safety alerts they were told about), or nowhere. */
    href?: string | null;
    at: string;
    sub: string;
    then: string;
};

export type WorkerKitItem = {
    key: string;
    kind: 'alarm' | 'zone' | 'panic' | 'door';
    /** The device's id (a zone's is its panel's). */
    id: number;
    name: string;
    sub: string;
    flag: { tone: WorkerTone; text: string };
    facts: WorkerLine[];
    panel: { state: string; label: string; tone: WorkerTone; since: string | null; by: string | null } | null;
    ready?: boolean;
};

export type WorkerPlace = {
    key: string;
    name: string;
    sub: string;
    flag: { tone: WorkerTone; text: string };
    facts: WorkerLine[];
    href: string | null;
};

export type WorkerTold = { id: string; at: string | null; tone: 'critical' | 'warning' | 'info'; title: string; sub: string };

export type WorkerOverviewProps = {
    view: 'worker';
    tab: 'overview';
    site: { id: number; name: string } | null;
    sites: { value: string; label: string }[];
    shift: { starts_at: string; ends_at: string } | null;
    on_shift: boolean;
    meters: WorkerMeter[];
    safety: WorkerAlert[];
    kit: { brand: string | null; reported_at: string | null; items: WorkerKitItem[] };
    around: WorkerPlace[];
    told: WorkerTold[];
    can: { open_devices: boolean; open_alerts: boolean };
};

export type SecurityDevicesHomeProps = OverviewProps | WorkerOverviewProps;
