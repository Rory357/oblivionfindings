/** Props for Sites and the Site page (S&D rebuild drawings pg-sdr-11 and pg-sdr-12). */
import type { DeviceState } from '@/lib/security-devices/device-words';

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

export type SitePageTab = 'overview' | 'network' | 'internet';

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
    network: { tree: unknown } | null;
    internet: { gateways: SiteDeviceRow[]; outages: SiteEventRow[] } | null;
    can: { add_device: boolean; assign: boolean; scan: boolean } & HubCan;
};
