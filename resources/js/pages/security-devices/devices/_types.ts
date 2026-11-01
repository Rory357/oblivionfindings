import type { FirmwareUpdate } from '@/components/security-devices/firmware-update-popup';
import type { StatusVariant } from '@/components/ui/status-badge';
import type {
    DeviceKind,
    DeviceState,
    DevicesTab,
} from '@/lib/security-devices/device-words';

/** A line with an optional "{time}" or "{ago}" the page fills in, in NZ time. */
export type TimedText = { text: string; at: string | null };

export type DeviceIdentity = {
    id: number;
    name: string;
    sub: string;
    kind: DeviceKind;
    kind_label: string;
    state: DeviceState;
    site: string | null;
    site_id: number | null;
    address: string | null;
    model: string;
    battery: number | null;
    watching: string;
    href: string;
};

export type DeviceBadge = { label: string; tone: StatusVariant };

export type DeviceCardData = DeviceIdentity & {
    badge: DeviceBadge;
    note: TimedText;
    /** null: no reading in the data ("Not available"). `from` asks for a duration. */
    reading: { value: string | null; sub: string | null; from: string | null } | null;
    graph: {
        label: string;
        note: TimedText | null;
        /** One mark per hour, oldest first: o answered, c failed, n not checked. */
        hours: string | null;
        spark: (number | null)[] | null;
        unit: string | null;
    };
    seen: { label: string; at: string | null };
};

export type DeviceRowData = DeviceIdentity & {
    badge: DeviceBadge;
    note: TimedText;
    seen_at: string | null;
};

export type PageMeta = {
    total: number;
    page: number;
    last_page: number;
    from: number;
    to: number;
};

export type CardGroup = {
    key: 'attention' | 'normal';
    title: string;
    count: number;
    sub: string;
    truncated: boolean;
    items: DeviceCardData[];
};

export type CameraTile = {
    id: number;
    name: string;
    site: string | null;
    state: DeviceState;
    line: string;
    line_at: string | null;
    /** S&D Phase E: inside its privacy hours until then; the tile shows no picture. */
    privacy_until?: string | null;
    href: string;
};

export type BatteryRow = {
    id: number;
    name: string;
    model: string;
    kind: DeviceKind;
    where: string | null;
    battery: number;
    tone: StatusVariant;
    cant_check: boolean;
    behind: string | null;
    charged: boolean;
    read_at: string | null;
    href: string;
};

export type SiteOption = {
    id: number;
    name: string;
    checked_by: string;
    hubs: { id: number; label: string }[];
    suggested_hub_id: number | null;
    suggested_range: string | null;
};

export type ModelOption = {
    value: string;
    label: string;
    description: string;
    kind: string;
    brand: string;
    model: string;
};

export type DeviceFormData = {
    kinds: { value: string; label: string }[];
    models: ModelOption[];
    watch: { every: string; after: string; know_within: string };
    sites: SiteOption[];
};

export type Meter = { count: number; caption: TimedText };

export type DevicesCan = {
    add: boolean;
    assign: boolean;
    scan: boolean;
    connect: boolean;
    edit: boolean;
    move: boolean;
    retire: boolean;
    watch: boolean;
    /** pg-sdr-18b: Export (the device list and upkeep log CSVs), for people who may see reports. */
    export?: boolean;
    /** S&D rebuild C6: the bulk bar's "Run action" (the keys that may start a command batch). */
    commands?: boolean;
};

export type DevicesIndexProps = {
    tab: DevicesTab;
    layout: 'cards' | 'list';
    upkeep: 'batteries' | 'updates';
    /** `group`: "all", "health" (Health kit) or a device group's id (S&D rebuild C6). Optional for older fixtures. */
    filters: { site: string; status: string; group?: string; q: string };
    tabs: { key: DevicesTab; count: number | null }[];
    meters: {
        online: Meter;
        offline: Meter;
        cant_check: Meter;
        batteries_low: Meter;
    };
    sites: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
    /** Health kit and today's device groups (D8), read-only; empty when there are none. */
    groups?: { value: string; label: string }[];
    can: DevicesCan;
    cards: {
        groups: CardGroup[];
        not_watched: { count: number; names: string[] };
    } | null;
    cameras: {
        stills: boolean;
        items: CameraTile[];
        cant_check: { site: string; text: string }[];
    } | null;
    list: { rows: DeviceRowData[]; meta: PageMeta } | null;
    batteries: { forecast: boolean; rows: BatteryRow[]; meta: PageMeta } | null;
    updates: {
        /** Whether any device here has a source that reports its updates (UniFi). */
        source: boolean;
        /** S&D Phase G (2d): devices a maker's source says have newer software. */
        rows: FirmwareUpdate[];
        /** Network gear and cameras no update check here covers yet. */
        unchecked: number;
        can: { quiet_time: boolean };
        recent: {
            id: number;
            device_id: number;
            name: string;
            versions: string | null;
            at: string | null;
        }[];
    } | null;
    openDialog: 'add' | null;
    deviceForm?: DeviceFormData;
};
