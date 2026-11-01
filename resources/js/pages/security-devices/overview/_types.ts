/** Props for the Security & devices home (S&D rebuild drawings pg-sdr-1, 1b and 18). */
import type { DeviceState, DevicesTab } from '@/lib/security-devices/device-words';
import type { FactTone, SiteCard } from '../sites/_types';

export type OverviewTab = 'overview' | 'trends';

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
    /** add_hub / approve_hub: Add a hub (sdr-12) and approving it (sdr-79). */
    can: { add: boolean; scan: boolean; connect: boolean; assign: boolean; add_hub?: boolean; approve_hub?: boolean };
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

export type WorkerAlert = { id: number; title: string; tone: 'critical' | 'warning'; safety: boolean; at: string; sub: string; then: string };

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
