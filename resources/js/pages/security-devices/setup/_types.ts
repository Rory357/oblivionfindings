/** Setup (S&D rebuild pg-sdr-15 and its sections): the props SetupPresenter sends. */
import type { StatusVariant } from '@/components/ui/status-badge';

export type SetupSection = 'overview' | 'hubs' | 'services' | 'alerts' | 'access' | 'computers' | 'history';

/** S&D Phase G (pg-sdr-15k): Computers & Obli › Remote support. */
export type RemoteSetupData = {
    allowed: boolean;
    working: boolean;
    computers_on: boolean;
    relay_ready: boolean;
    relays: number;
    sessions_30_days: number;
    can_change: boolean;
    settings_href: string;
};

/** S&D Phase H (pg-sdr-15m): one Site's UniFi Access console, as Setup and pop-up 91 see it. */
export type AccessConsoleState = {
    id: number;
    address: string;
    port: number;
    /** waiting | ok | failed | untrusted */
    state: string;
    last_error: string | null;
    last_synced_at: string | null;
    emergency_mode: string;
    doors: number;
    staff: number;
    to_match: number;
    token: { saved_at: string | null; complete: boolean } | null;
    certificate: {
        trusted_fingerprint: string | null;
        offered: { fingerprint: string; subject: string | null; valid_to: string | null; seen_at: string | null; changed: boolean } | null;
        can_trust: boolean;
    } | null;
    trusted: boolean;
    doors_confirmed_at: string | null;
};

export type AccessPlace = {
    site_id: number;
    site: string;
    hub: { name: string; runs: boolean; needs_update: boolean; online: boolean } | null;
    console: AccessConsoleState | null;
};

export type AccessSetupData = {
    places: AccessPlace[];
    token_permissions: string[];
    can: { connect: boolean; save_token: boolean; confirm_doors: boolean };
};
export type Tone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

/** A line from the server: "**bold**" is the figure; "{time}" / "{ago}" are filled from `at` in NZ time. */
export type SetupLine = { tone: Tone; text: string; at?: string | null };

export type SetupMeter = {
    key: string;
    label: string;
    value: string;
    caption: string;
    tone: 'success' | 'warning' | 'critical' | null;
    section: SetupSection | null;
    tab: string | null;
    href?: string | null;
};

export type TodoAction =
    | { kind: 'section'; label: string; section: SetupSection; tab: string | null }
    | { kind: 'add_hub'; label: string; site_id: number }
    | { kind: 'approve_hub'; label: string; hub_id: number };

export type TodoItem = { key: string; tone: Tone; text: string; at?: string | null; action: TodoAction | null };

export type ReviewCardData = {
    key: string;
    icon: 'router' | 'plug' | 'key' | 'list-checks' | 'users' | 'history' | 'bell';
    title: string;
    tone: 'critical' | 'warning' | 'info';
    lines: SetupLine[];
    link: { label: string; section?: SetupSection; tab?: string | null; href?: string } | null;
};

export type SettingSwitch = {
    type: 'bool';
    on: boolean;
    default: boolean;
    changed: boolean;
    loosens_when_on: boolean;
    loosens_when_off: boolean;
    can_change: boolean;
};

export type SettingRole = {
    type: 'role';
    value: string | null;
    default: string | null;
    changed: boolean;
    can_change: boolean;
    options: { value: string | null; label: string }[];
};

export type SettingRow = SettingSwitch | SettingRole;

export type NetworkRow = {
    id: number;
    site: { id: number; name: string };
    range: string;
    checked_by: string;
    hub_id: number | null;
    last: { text: string; at: string | null; tone: Tone | null };
    next: { text: string; at: string | null };
    found: string | null;
    run_id: number | null;
    can_scan: boolean;
    scan_blocked: string | null;
};

export type ServiceCard = {
    key: string;
    name: string;
    sub: string;
    flag: { tone: StatusVariant; text: string };
    facts: SetupLine[];
};

export type LoginRow = {
    key: string;
    used_for: string;
    sub: string;
    icon: 'key' | 'cloud';
    site: { id: number; name: string } | null;
    used_by: string;
    last_used: string | null;
    state: { tone: StatusVariant; text: string };
};

export type SupportedRow = {
    key: string;
    brand: string;
    models: string;
    kind: string;
    works: string;
    view_only: boolean;
    level: { text: string; tone: StatusVariant };
    docs: { label: string; url: string }[];
};

export type SupportedTable = { key: string; title: string; sub: string; rows: SupportedRow[] };

export type BrokerRow = {
    id: number;
    label: string;
    sub: string;
    site: { id: number; name: string };
    reaches: string;
    connection: { tone: StatusVariant; text: string };
    last: string | null;
};

export type CheckRow = { key: string; kind: string; check: string; often: string | null; after: string | null; devices: number; watched: number };

export type HistoryRow = { id: number; at: string | null; who: string; what: string; where: string; site_id: number | null };

export type SetupProps = {
    section: SetupSection;
    tab: string | null;
    sections: { key: SetupSection; label: string; tabs: { key: string; label: string }[] }[];
    meters: SetupMeter[];
    can: { manage: boolean; add_network: boolean; add_hub: boolean; approve_hubs: boolean; export_history: boolean; open_devices: boolean };
    overview: { todo: TodoItem[]; cards: ReviewCardData[] } | null;
    settings: Record<string, SettingRow> | null;
    alerts: { rules: number } | null;
    checks: { rows: CheckRow[] } | null;
    broker: {
        rows: BrokerRow[];
        check: { passed: boolean; at: string; by: string; answered: string[] } | null;
        /** S&D Phase E (E5): the sensors certificate a gateway loads for TLS on 8883; null until the first hub asks. */
        certificate?: { fingerprint: string; download_url: string } | null;
        clashes: string[];
        can_add: boolean;
    } | null;
    networks: { rows: NetworkRow[]; notes: { tone: 'warning' | 'critical'; text: string }[] } | null;
    connected: { cards: ServiceCard[] } | null;
    logins: { rows: LoginRow[]; kept: [string, string][] } | null;
    supported: { tables: SupportedTable[] } | null;
    history: { rows: HistoryRow[]; page: number; pages: number; total: number; kept: [string, string][] } | null;
    /** S&D Phase H (pg-sdr-15m): Doors & access › UniFi Access. */
    access?: AccessSetupData | null;
    /** S&D Phase G (pg-sdr-15k): Computers & Obli › Remote support. */
    remote?: RemoteSetupData | null;
};
