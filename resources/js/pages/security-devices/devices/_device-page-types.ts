import type { StatusVariant } from '@/components/ui/status-badge';
import type { SshHostKeyState } from '@/components/security-devices/ssh-host-key-notice';
import type { SgTone } from '@/components/signature/blocks';
import type { DeviceKind, DeviceState } from '@/lib/security-devices/device-words';
import type { DeviceCardData, DeviceFormData, TimedText } from './_types';
import type { FirmwareUpdate } from '@/components/security-devices/firmware-update-popup';
import type { SavedLogin } from './_device-saved-login';
import type { ObliHelpTabData } from '@/lib/security-devices/obli-fixes';

/**
 * The device page (S&D rebuild C2): one page per kind for network gear, computers, printers,
 * UPSs, recorders, sensor gateways and other devices. Built by DevicePagePresenter; only the
 * open tab's data is filled in. A null value means "Not available", never 0.
 */

export type DevicePageTab = 'overview' | 'health' | 'obli' | 'remote' | 'checks' | 'terminal' | 'notes';

/** S&D Phase G (pg-sdr-3b): the Terminal tab, through the hub, for IT admins. */
export type TerminalTabData = {
    ready: boolean;
    reason: string | null;
    hub: string | null;
    host: string;
    session: { id: string; state: string } | null;
    history: { id: string; who: string; at: string | null; reason: string | null; commands: string[] }[];
};
export type DevicePageGroup = 'network' | 'computer' | 'other';
export type TrendRange = '24h' | '7d' | '30d';

export type DevicePageMeter = {
    key: string;
    label: string;
    /** null: "Not available", unless `from` (a duration) or `ago` (a time) fills it. */
    value: string | null;
    from: string | null;
    ago: string | null;
    caption: TimedText;
    tone: 'brand' | 'success' | 'warning' | 'critical';
    tab: DevicePageTab;
    bar: number | null;
};

export type LogEntry = {
    key: string;
    at: string | null;
    tone: SgTone;
    title: string;
    sub: string | null;
    compare_id?: number | null;
};

export type TrendSeries = {
    range: TrendRange;
    step_seconds: number;
    start: string;
    points: (number | null)[];
    unit: string;
    title?: string;
};

export type Availability = {
    answered: number;
    missed: number;
    percent: number;
    last_missed_at: string | null;
    days: number;
};

export type MapNode = {
    id: number;
    name: string;
    kind: DeviceKind;
    sub: string;
    state: DeviceState;
    href: string | null;
};

export type BackupCopy = {
    id: number;
    at: string | null;
    changes: number | null;
    first: boolean;
    compare: boolean;
};

export type BackupAction = {
    available: boolean;
    reason: string | null;
    via: string | null;
    ssh_key: SshHostKeyState;
    last_request: {
        id: number;
        status: string;
        label: string;
        waiting: boolean;
        at: string | null;
        mine: boolean;
        can_run: boolean;
    } | null;
};

export type BehindDevice = {
    id: number;
    name: string;
    kind: DeviceKind;
    model: string;
    state: DeviceState;
    last_seen_at: string | null;
    href: string;
};

export type Told = { sub: string; rows: [string, string][] };

export type TroubleState = {
    since: string | null;
    checked_by: string;
    last_try_at: string | null;
    every_seconds: number | null;
    last_reply: { at: string; latency_ms: number | null } | null;
    missed: { count: number; first_at: string | null; last_at: string | null };
    behind: BehindDevice[];
    behind_count: number;
    next: string;
    told: Told | null;
    events: LogEntry[];
};

export type NetworkOverview = {
    trend: TrendSeries | null;
    availability: Availability | null;
    events: LogEntry[];
    facts: [string, string][];
    connected?: { up: MapNode[]; self: MapNode; down: MapNode[]; more: number } | null;
    reading_trend?: TrendSeries | null;
    cameras?: DeviceCardData[];
    sensors?: GatewaySensor[];
};

export type GatewaySensor = {
    id: number;
    name: string;
    kind: DeviceKind;
    model: string;
    state: DeviceState;
    where: string | null;
    reading: string | null;
    battery: number | null;
    battery_low: boolean;
    seen_at: string | null;
    href: string;
    address: string | null;
    site: string | null;
    site_id: number | null;
};

export type CheckItemData = { label: string; state: SgTone; note: string | null };

export type ComputerOverview = {
    now: { read_at: string | null; cpu: number | null; memory: number | null; disk: number | null } | null;
    restricted: boolean;
    safety: CheckItemData[];
    events: LogEntry[];
    facts: [string, string][];
};

export type ComputerHealth = {
    read_at: string | null;
    restricted: boolean;
    available: boolean;
    safety: {
        name: string;
        sub: string;
        flag: { label: string; tone: StatusVariant };
        facts: { tone: SgTone; text: string }[];
    }[];
    disks: { name: string; used: string; total: string; percent: number | null }[];
    services: CheckItemData[];
    facts: [string, string][];
    warranty_until: string | null;
    programs: number | null;
};

export type RemoteSupport = {
    ready: CheckItemData[];
    asked_every_time: boolean;
    can_control: boolean;
    max_minutes: number | null;
    start_href: string | null;
    sessions: {
        key: string;
        at: string | null;
        who: string;
        outcome: string;
        tone: SgTone;
        minutes: number | null;
        why: string | null;
    }[];
};

/** Every kind's overview: the shared log and facts, then its own parts. */
export type DeviceOverview = Partial<Omit<NetworkOverview, 'events' | 'facts'>> &
    Partial<Omit<ComputerOverview, 'events' | 'facts'>> & {
        events: LogEntry[];
        facts: [string, string][];
    };

export type CheckRow = {
    id: number;
    kind: string;
    title: string;
    question: string;
    how: string;
    every: string;
    result: { label: string; tone: StatusVariant; at: string | null };
    alert_when: string;
};

export type ChecksTab = {
    noun: string;
    rows: CheckRow[];
    quiet_time: {
        name: string;
        starts_at: string | null;
        ends_at: string | null;
        now: boolean;
        whole_site: boolean;
        reason: string | null;
        by: string | null;
    } | null;
    told: Told | null;
    depends: { upstream: string[]; downstream: number };
    alerts: LogEntry[];
    backups: BackupCopy[];
};

export type DocumentRow = {
    id: number;
    title: string;
    file: string;
    kind: string;
    added_at: string | null;
    expiry: { date: string; ended: boolean; soon: boolean } | null;
    href: string;
};

export type NotesTab = {
    /** label, value, date (YYYY-MM-DD) */
    upkeep: [string, string, string | null][];
    documents: DocumentRow[];
    kinds: { value: string; label: string }[];
    notes: LogEntry[];
    purchase: {
        price: string | null;
        asset: { name: string; tag: string | null; href: string | null } | null;
    };
};

export type DevicePageProps = {
    device: {
        id: number;
        name: string;
        kind: DeviceKind;
        kind_label: string;
        group: DevicePageGroup;
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
    tab: DevicePageTab;
    tabs: { key: DevicePageTab; label: string }[];
    range: TrendRange;
    meters: DevicePageMeter[];
    can: {
        edit: boolean;
        watch: boolean;
        move: boolean;
        retire: boolean;
        assign: boolean;
        upload: boolean;
        see_events: boolean;
    };
    actions: {
        ping: boolean;
        backup: BackupAction | null;
        remote_href: string | null;
        /** S&D Phase G (pop-up 61): whether the device's web page can open through the hub, and why not. */
        web?: { ok: boolean; reason: string | null; hub: string | null } | null;
        /** S&D Phase G (pop-up 62): newer software a maker's source says is ready. */
        update?: FirmwareUpdate | null;
        /** S&D Phase G (pop-up 63): the saved login the hub uses, for people who may replace it. */
        login?: SavedLogin | null;
        /** S&D Phase I (pop-up 97): a Wi-Fi point's "Ask Obli" (IT), with the Site its hub checks. */
        ask_obli?: { site_id: number; site: string } | null;
    };
    trouble: TroubleState | null;
    overview: DeviceOverview | null;
    health: ComputerHealth | null;
    remote: RemoteSupport | null;
    checks: ChecksTab | null;
    terminal?: TerminalTabData | null;
    notes: NotesTab | null;
    /** S&D Phase I (pg-sdr-9d): the Obli help tab. */
    obli?: ObliHelpTabData | null;
    deviceForm?: DeviceFormData;
};
