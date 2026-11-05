/** Props for Sites and the Site page (S&D rebuild drawings pg-sdr-11 and pg-sdr-12, 12g–12j). */
import type { DeviceState, DevicesTab } from '@/lib/security-devices/device-words';
import type { DeviceRowData, DevicesCan } from '../devices/_types';

/** A ring tone, as the signature blocks read it. */
export type FactTone = 'info' | 'warning' | 'success' | 'critical' | 'brand' | 'none';

/** One fact line: "**bold**" marks the figure; "{time}" / "{ago}" take `at`, in NZ time. */
export type SiteFact = { tone: FactTone; text: string; at?: string | null };

export type SiteFlag = { tone: 'success' | 'warning' | 'critical' | 'neutral'; text: string };

export type SitesTab = 'all' | 'attention' | 'not_set_up';

export type SiteCard = {
    id: number;
    name: string;
    address: string;
    region: string | null;
    device_count: number;
    flag: SiteFlag;
    facts: SiteFact[];
    needs_attention: boolean;
    set_up: boolean;
    /** The hub that watches it, when the viewer may open its panel (sdr-13). */
    hub?: { id: number; label: string } | null;
    href: string;
};

/** Add a hub (sdr-12) and approving it (sdr-79), by the hub routes' own rule. */
export type HubCan = { add_hub?: boolean; approve_hub?: boolean };

export type SitesIndexProps = {
    tab: SitesTab;
    filters: { q: string | null; region: string | null };
    regions: string[];
    tabs: Record<SitesTab, number>;
    meters: {
        set_up: { count: number; of: number; caption: string };
        hubs: { online: number; of: number; caption: string };
        attention: { count: number; caption: string };
    };
    cards: SiteCard[];
    can: { add_device: boolean; scan: boolean } & HubCan;
};

export type SitePageTab = 'overview' | 'network' | 'internet' | 'rooms' | 'modes' | 'devices' | 'alerts' | 'logbook';

/* ------------------------------------------------------------------ */
/*  Devices (pg-sdr-12g), Alerts (12h) and Logbook (12i)               */
/* ------------------------------------------------------------------ */

/** One device in the Site's list: the Devices list's row, with its room instead of the Site. */
export type SiteDeviceListRow = DeviceRowData & { room: string | null; tab: DevicesTab };

export type SiteDevices = {
    rows: SiteDeviceListRow[];
    by_kind: Partial<Record<DevicesTab, number>>;
    /** How they're checked: "**bold**" marks the hub or the figure. */
    checked: { tone: FactTone; text: string }[];
    not_watched: string[];
    not_watched_count: number;
    retired: string[];
    retired_count: number;
    can: Pick<DevicesCan, 'edit' | 'watch' | 'move' | 'retire' | 'export' | 'commands'>;
};

export type SiteAlertRow = {
    id: number;
    title: string;
    sub: string;
    device_id: number;
    device_kind: string | null;
    device: string | null;
    state: 'open' | 'snoozed' | 'done';
    at: string;
    told: string;
    ended: { badge: string; tone: 'success' | 'warning' | 'critical' | 'info' | 'neutral'; note: string };
    href: string;
};

export type SiteAlerts = {
    rows: SiteAlertRow[];
    days: number;
    told: { when: string; then: string }[];
    quiet: { id: number; at: string; title: string; sub: string }[];
};

/** One line of the Logbook or the Overview's Activity (SiteStream). */
export type StreamKind = 'alert' | 'hs' | 'alarm' | 'mode' | 'device' | 'door' | 'planned';

export type StreamLine = {
    key: string;
    at: string;
    tone: 'critical' | 'warning' | 'info' | 'success' | 'none';
    kind: StreamKind;
    title: string;
    sub: string | null;
    site_id: number | null;
    site: string;
    device_id: number | null;
    device_kind: string | null;
    href: string | null;
    next: string | null;
    by: string | null;
    action: { label: string; href: string } | null;
};

export type LogbookWhat = 'everything' | 'alerts' | 'alarm' | 'modes' | 'devices' | 'people';

export type SiteLogbook = {
    day: string;
    today: boolean;
    what: LogbookWhat;
    sections: { key: 'day' | 'night' | 'before'; title: string; items: StreamLine[] }[];
};

/* ------------------------------------------------------------------ */
/*  Network map: links found (pg-sdr-12j)                              */
/* ------------------------------------------------------------------ */

export type LinkMapState = 'on' | 'new' | 'unsure' | 'gone';

export type FoundLink = {
    key: string;
    name: string;
    sub: string;
    parent_id: number;
    child_id: number;
    child_kind: string | null;
    child_href: string;
    by: string;
    ports: string | null;
    /** How sure Oblivion is, 0–100; null when placed on the map by hand. */
    sure: number | null;
    seen_at: string | null;
    /** `note` may carry "{time}" or "{date}" for `at`. */
    map: { state: LinkMapState; tone: 'success' | 'info' | 'neutral' | 'warning'; label: string; note: string; at: string | null };
    relationship: { id: number; device_id: number } | null;
};

export type UnplacedDeviceOption = {
    id: number;
    name: string;
    kind: string;
    sub: string;
};

export type LinksFound = {
    nothing: boolean;
    sources: { key: string; who: string; text: string; at: string | null }[];
    /** monitoring.policy.topology_dependency_minimum_confidence, as a percentage. */
    threshold: number;
    rows: FoundLink[];
    changes: { key: string; at: string | null; tone: 'success' | 'info' | 'warning'; title: string; sub: string }[];
    unplaced: {
        count: number;
        names: string[];
        more: number;
        items?: UnplacedDeviceOption[];
        parents?: UnplacedDeviceOption[];
    };
    can: { change: boolean };
};

/* ------------------------------------------------------------------ */
/*  Rooms (pg-sdr-12d) and house modes (pg-sdr-12c, pop-ups 42, 43)    */
/* ------------------------------------------------------------------ */

/** A fact line on a card: "**bold**" marks the figure; `href` opens the device. */
export type SiteCardFact = { tone: FactTone; text: string; href?: string | null };

export type SiteRoomCard = {
    key: string;
    name: string;
    sub: string;
    flag: SiteFlag;
    facts: SiteCardFact[];
};

export type SiteRooms = {
    items: SiteRoomCard[];
    room_count: number;
    unplaced: { count: number; names: string[]; more: number } | null;
    /** Sites & locations, where rooms are added and renamed, when the viewer may open it. */
    profile_href: string | null;
};

/** The only actions a house mode may take (SafeActions, decision D12). */
export type SafeActionKey = 'notify' | 'open_alert' | 'camera_privacy_on' | 'door_locked_check' | 'alarm_armed_check';

export type SafeActionChoice = { key: SafeActionKey; label: string; description: string };

export type HouseModeAction =
    | { action: 'notify'; message?: string | null }
    | { action: 'open_alert' }
    | { action: 'camera_privacy_on'; cameras: number[]; until: string }
    | { action: 'door_locked_check'; doors: number[] }
    | { action: 'alarm_armed_check'; panels: number[]; after_minutes: number };

/** Weekly start in NZ wall-clock time; days 1–7, Monday first. Null: by hand only. */
export type HouseModeSchedule = { days: number[]; starts: string };

/** The header's House mode block and Change mode (pop-up 42), on every tab. */
export type HouseSummary = {
    current: { id: number; name: string; since: string } | null;
    next: { name: string; at: string } | null;
    modes: { id: number; name: string; summary: string[]; usual: string | null }[];
};

export type SiteModeItem = {
    id: number;
    name: string;
    sub: string;
    on: boolean;
    /** "Starts {time}" takes `at`, in NZ time. */
    flag: { tone: 'success' | 'info' | 'neutral'; text: string; at: string | null };
    facts: SiteCardFact[];
    schedule: HouseModeSchedule | null;
    actions: HouseModeAction[];
};

export type SiteModeLogRow = { id: number; at: string; tone: FactTone; title: string; sub: string };

export type DeviceOption = { id: number; name: string };

/** What pop-up 43 offers: only SafeActions' choices, and only this Site's devices. */
export type HouseModeEditOptions = {
    choices: SafeActionChoice[];
    doors: DeviceOption[];
    cameras: DeviceOption[];
    panels: DeviceOption[];
    max: number;
};

export type SiteModes = {
    items: SiteModeItem[];
    log: SiteModeLogRow[];
    edit: HouseModeEditOptions | null;
};

export type SiteDeviceRow = {
    id: number;
    name: string;
    kind: string;
    kind_key: string;
    state: DeviceState;
    subline: string;
    seen_at: string | null;
    behind: string | null;
    href: string;
};

export type SiteEventRow = {
    id: number;
    at: string | null;
    tone: FactTone;
    title: string;
    device: string;
};

export type SitePageProps = {
    site: { id: number; name: string; address: string };
    tab: SitePageTab;
    /** The rail: the Devices count, and whether this viewer may open Alerts and Logbook. */
    tabs?: { devices: number; alerts: boolean; logbook: boolean };
    flag: SiteFlag;
    /** `id` is set when the viewer may open the hub panel (sdr-13). */
    hub: { id?: number | null; label: string; own: boolean; answering: boolean; version: string | null; seen_at: string | null } | null;
    meters: {
        online: { count: number; of: number; caption: { text: string; at: string | null } };
        alerts: { count: number; caption: string };
        scan: { at: string | null; caption: string; href: string } | null;
    };
    overview: {
        states: { state: DeviceState; count: number }[];
        cameras: { id: number; name: string; state: DeviceState; href: string }[];
        camera_count: number;
        week: { values: (number | null)[]; labels: string[]; note: string } | null;
        hub_down: {
            hub: string;
            seen_at: string | null;
            cant_check: number;
            still_seen: { name: string; href: string }[];
        } | null;
    } | null;
    network: { tree: unknown; links?: LinksFound } | null;
    internet: { gateways: SiteDeviceRow[]; outages: SiteEventRow[] } | null;
    rooms?: SiteRooms | null;
    modes?: SiteModes | null;
    devices?: SiteDevices | null;
    alerts?: SiteAlerts | null;
    logbook?: SiteLogbook | null;
    house?: HouseSummary;
    /** ask_obli: S&D Phase I (pop-up 97), Obli's read-only network checks through the hub (IT). */
    can: { add_device: boolean; assign: boolean; scan: boolean; switch_mode?: boolean; edit_modes?: boolean; ask_obli?: boolean } & HubCan;
};
