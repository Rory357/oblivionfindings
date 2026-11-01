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
};
