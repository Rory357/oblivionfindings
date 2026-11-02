import type { ChecksTab, DevicePageMeter, DevicePageProps, LogEntry } from './_device-page-types';
import type { DeviceFormData, TimedText } from './_types';

/**
 * The camera's page (S&D rebuild Phase E, drawings pg-sdr-4, 4b and 4c). Built by
 * CameraPagePresenter; only the open tab's data is filled in. A null value means "Not available".
 */

export type CameraPageTab = 'live' | 'recordings' | 'checks' | 'settings';

/** One weekly time range in NZ wall-clock time. Days 1–7, Monday first; times "HH:MM". */
export type PrivacyRange = { days: number[]; from: string; to: string };

export type CameraPrivacyState = {
    enabled: boolean;
    ranges: PrivacyRange[];
    /** Inside its privacy hours now: no picture anywhere until `until`. */
    active: boolean;
    until: string | null;
    next_start: string | null;
};

export type CameraLive = {
    available: boolean;
    reason: null | 'privacy_hours' | 'not_set_up' | 'no_access' | 'unavailable';
    actor_id: number;
    source_revision: number | null;
    start_href: string | null;
    /** The hub the live picture comes through, e.g. "Head office hub". */
    via: string | null;
    max_seconds: number | null;
};

export type CameraOverview = {
    events: LogEntry[];
    last_view: { at: string | null; who: string; reason: string } | null;
    facts: [string, string][];
    it: [string, string][];
};

export type CameraClip = {
    key: string;
    start: string;
    end: string;
    why: string;
    by: string;
    href: string;
};

export type CameraRecordings = {
    clips: CameraClip[];
    unavailable: boolean;
    facts: [string, string][];
};

export type CameraSettings = {
    camera: {
        name: string;
        site_id: number | null;
        site: string | null;
        location: string;
        address: string;
        manufacturer: string | null;
        model: string | null;
        notes: string | null;
        domain: string;
        category: string;
        subcategory: string | null;
    };
    recorder: string | null;
    login: string;
    who: [string, string][];
};

export type CameraMeter = Omit<DevicePageMeter, 'tab'> & { tab: CameraPageTab };

export type CameraPageProps = {
    device: Omit<DevicePageProps['device'], 'group'> & { group: 'camera' };
    tab: CameraPageTab;
    tabs: { key: CameraPageTab; label: string }[];
    meters: CameraMeter[];
    can: DevicePageProps['can'] & {
        see_picture: boolean;
        watch_live: boolean;
        see_clips: boolean;
        save_clip: boolean;
        privacy: boolean;
    };
    actions: DevicePageProps['actions'] & { recorder_href: string | null };
    privacy: CameraPrivacyState;
    live: CameraLive | null;
    overview: CameraOverview | null;
    recordings: CameraRecordings | null;
    checks: ChecksTab | null;
    settings: CameraSettings | null;
    deviceForm?: DeviceFormData;
};

export type { TimedText };
