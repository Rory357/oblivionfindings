import type { SgTone } from '@/components/signature/blocks';
import type { StatusVariant } from '@/components/ui/status-badge';
import type { DeviceState } from '@/lib/security-devices/device-words';
import type { ChecksTab, LogEntry, NotesTab } from './_device-page-types';
import type { DeviceFormData, TimedText } from './_types';

/**
 * The alarm panel's page and the door's page (S&D rebuild Phase E): built by AlarmPagePresenter and
 * DoorPagePresenter. Only the open tab's data is filled in; a null number means "Not available".
 */

export type SafetyKitDevice = {
    id: number;
    name: string;
    kind: 'alarm_panel' | 'door';
    kind_label: string;
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

export type SafetyKitMeter = {
    key: string;
    label: string;
    value: string | null;
    caption: TimedText;
    tone: 'brand' | 'success' | 'warning' | 'critical';
    tab: string;
};

/** One governed action, as the server would treat it now. */
export type GovernedAction = {
    available: boolean;
    reason: string | null;
    /** The person must confirm it's them (password) before it can be asked for. */
    step_up: boolean;
    /** A second person approves it before it's sent. */
    approval: boolean;
    /** The framework asks for the device's name to be typed (disarm). */
    type_name: boolean;
    /** The hub it goes through, or null for the Oblivion server. */
    via: string | null;
};

export type CommandPhase = 'step_up' | 'approval' | 'ready' | 'checking' | 'done' | 'mismatch' | 'unconfirmed' | 'stopped';

/** A recent arm, disarm or unlock request and what happened to it. */
export type SafetyCommand = {
    id: number;
    action: 'arm' | 'disarm' | 'unlock';
    area: string | null;
    phase: CommandPhase;
    words: string;
    failure: string | null;
    at: string | null;
    done_at: string | null;
    by: string | null;
    approved_by: string | null;
    reason: string;
    mine: boolean;
    can_run: boolean;
};

export type BaseCan = {
    edit: boolean;
    watch: boolean;
    move: boolean;
    retire: boolean;
    assign: boolean;
    see_events: boolean;
};

/* ------------------------------------------------------------------ */
/*  Alarm panel                                                        */
/* ------------------------------------------------------------------ */

export type AlarmTab = 'overview' | 'zones' | 'checks' | 'notes';

export type AlarmArea = {
    id: string;
    name: string;
    armed: boolean | null;
    mode: string | null;
    since: string | null;
    by: string | null;
};

export type AlarmZone = {
    id: string;
    name: string;
    number: string | null;
    state: 'normal' | 'open' | 'triggered' | 'bypassed' | 'tamper' | 'fault' | 'not_reported';
    label: string;
    tone: StatusVariant;
    area: string | null;
    changed_at: string | null;
};

export type PanelState = {
    state: 'armed' | 'partly' | 'disarmed' | 'alarm' | 'not_reported' | 'hidden';
    label: string;
    tone: StatusVariant;
    mode: string | null;
    since: string | null;
    armed: number | null;
    total: number | null;
};

export type ClockLine = { state: SgTone; lead: string | null; text: string; at: string | null };

export type AlarmPageProps = {
    device: SafetyKitDevice;
    tab: AlarmTab;
    tabs: { key: AlarmTab; label: string }[];
    panel: PanelState;
    meters: SafetyKitMeter[];
    can: BaseCan & { upload: boolean; arm: boolean; disarm: boolean };
    control: {
        controllable: boolean;
        view_only: boolean;
        brand: string | null;
        arm: GovernedAction | null;
        disarm: GovernedAction | null;
        refusal: string | null;
        commands: SafetyCommand[];
        zones: AlarmZone[];
        confirm_identity_url: string;
    };
    areas: AlarmArea[];
    overview: {
        lines: ClockLine[];
        zones: AlarmZone[];
        zone_count: number;
        last_report: string | null;
        events: LogEntry[];
        facts: [string, TimedText][];
    } | null;
    zones: { rows: AlarmZone[]; modes_help: boolean } | null;
    checks: ChecksTab | null;
    notes: NotesTab | null;
    deviceForm?: DeviceFormData;
};

/* ------------------------------------------------------------------ */
/*  Door                                                               */
/* ------------------------------------------------------------------ */

export type DoorTab = 'overview' | 'events' | 'access';

export type DoorEvent = {
    key: string;
    at: string | null;
    kind: 'came_in' | 'unlocked' | 'locked' | 'refused' | 'forced' | 'held' | 'opened' | 'closed' | 'other';
    tone: SgTone;
    title: string;
    /** A staff member's name, "Resident fob", or null (never a resident's name). */
    who: string | null;
    who_kind: 'staff' | 'resident' | 'unknown' | 'none';
    how: string | null;
    method: string;
};

export type DoorPageProps = {
    device: SafetyKitDevice;
    tab: DoorTab;
    tabs: { key: DoorTab; label: string }[];
    lock: {
        title: string;
        tone: 'success' | 'warning' | 'critical' | null;
        chip: { label: string; tone: StatusVariant };
        checked_at: string | null;
        relocks: boolean;
    };
    meters: SafetyKitMeter[];
    can: BaseCan & { unlock: boolean; keep_unlocked?: boolean; lock_again?: boolean };
    control: {
        unlock: GovernedAction | null;
        /** S&D Phase H (pop-up 70): keep unlocked and lock again, for a door UniFi Access knows. */
        keep_unlocked?: GovernedAction | null;
        lock_again?: GovernedAction | null;
        seconds: number | null;
        commands: SafetyCommand[];
        confirm_identity_url: string;
    };
    overview: {
        today: DoorEvent[];
        who: [string, string][];
        week: { labels: string[]; values: number[] } | null;
        facts: [string, string][];
    } | null;
    events: {
        range: 'today' | '7d' | '90d';
        ranges: { key: 'today' | '7d' | '90d'; label: string }[];
        rows: DoorEvent[];
        total: number;
        page: number;
        last_page: number;
        ways: { key: string; label: string; tone: string; value: number }[];
    } | null;
    access: {
        groups: { key: string; group: string; kind: 'staff' | 'resident'; who: string; when: string; count: number }[];
        schedules: string[];
    } | null;
    deviceForm?: DeviceFormData;
};
