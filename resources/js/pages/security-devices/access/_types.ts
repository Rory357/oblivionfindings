import type { GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
/** What AccessPagePresenter sends (S&D rebuild drawings pg-sdr-19, 19b, 19c, 19d, 19f). */
import type { StatusVariant } from '@/components/ui/status-badge';

export type AccessTab = 'doors' | 'people' | 'visitors' | 'schedules' | 'activity';

/** A server line: "{time}", "{ago}" and "{date}" are filled in NZ time from `at`. */
export type TimedLine = { text: string; at: string | null };

export type Badge = { tone: StatusVariant; text: string; at?: string | null };

export type Option = { value: string; label: string };

export type PageMeta = { page: number; last_page: number; total: number; from: number; to: number };

export type ConsoleReader = { id: string; name: string };

export type ConsoleDoor = { id: string; name: string; readers: ConsoleReader[] };

export type ConsolePolicy = { id: string; name: string; doors: string; people: number };

/** One Site's UniFi Access console: what the pop-ups choose from. */
export type AccessConsoleInfo = {
    id: number;
    site_id: number;
    site: string;
    state: string;
    synced_at: string | null;
    doors: ConsoleDoor[];
    policies: ConsolePolicy[];
};

export type DoorCard = {
    id: number;
    external_id: string;
    console_id: number;
    site_id: number;
    name: string;
    sub: string;
    icon: 'lock' | 'lock-open' | 'door-open' | 'door-closed';
    group: 'attention' | 'normal';
    reason: string | null;
    badge: Badge;
    note: TimedLine | null;
    value: 'Locked' | 'Unlocked' | 'Not available';
    value_sub: string;
    can_open: number;
    graph: { label: string; note: TimedLine; spark: number[] | null };
    site: string;
    seen: { label: string; at: string | null };
    href: string | null;
};

export type DoorGroup = { key: 'attention' | 'normal'; title: string; count: number; sub: string | null; items: DoorCard[] };

export type DoorsData = {
    groups: DoorGroup[];
    total: number;
    emergency: { site_id: number; site: string; doors: number; mode: 'normal' | 'lockdown' | 'evacuation'; known: boolean }[];
    not_managed: { count: number; names: string[] };
};

export type PersonCardRef = { id: number; display: string };

export type PersonRow = {
    id: number;
    console_id: number;
    site_id: number;
    site: string;
    name: string;
    sub: string;
    kind: 'staff' | 'resident' | 'unmatched';
    status: 'active' | 'pending' | 'deactivated';
    employee_number: string | null;
    user_id: number | null;
    suggestion: { user_id: number; name: string; sub: string } | null;
    cards: PersonCardRef[];
    gets_in: string;
    doors: string;
    policy_ids: string[];
    rank: number;
    badge: Badge;
    note: TimedLine | null;
    last_used: TimedLine;
    can_log: boolean;
};

export type PeopleData = {
    to_match: { count: number; people: PersonRow[]; lines: string[] };
    rows: PersonRow[];
    meta: PageMeta;
    can_get_in: number;
    turned_off: number;
};

export type VisitorRow = {
    id: number;
    name: string;
    site_id: number | null;
    site: string;
    starts_at: string | null;
    ends_at: string | null;
    phase: 'today' | 'coming' | 'ended';
    badge: Badge;
    can_cancel: boolean;
};

export type ScheduleRow = { id: string; name: string; site: string; site_id: number | null; sub: string; doors: string; door_count: number; when: string };

export type ActivityRow = {
    id: number;
    at: string;
    door_id: number | null;
    door: string;
    site: string;
    who: { text: string; named: boolean; person_id: number | null };
    how: string;
    what: { text: string; badge: Badge | null };
};

export type ActivityData = {
    rows: ActivityRow[];
    meta: PageMeta;
    filters: { door: string; kind: string; days: string; person: string | null };
    person: string | null;
    doors: Option[];
    worth_a_look: { key: string; at: string; tone: 'critical' | 'warning' | 'info'; title: string; sub: string }[];
    how_in: { total: number; parts: { label: string; value: number }[] } | null;
    events_read: boolean;
};

export type StaffOption = { id: number; name: string; sub: string; employee_number: string | null; in_access: boolean };

/** S&D Phase H (pop-up 86): one Site's lockdown, evacuation and end, as governed command summaries. */
export type EmergencyActions = {
    console_id: number;
    device_id: number;
    site_id: number;
    site: string;
    mode: string;
    type_name: string;
    lockdown: GovernedAction | null;
    evacuate: GovernedAction | null;
    end: GovernedAction | null;
    commands: { id: number; phase: string; words: string; at: string | null }[];
    confirm_identity_url: string;
};

export type AccessPageProps = {
    /** S&D Phase H (pop-up 86): only on the Doors tab, only for people who may lock down a Site. */
    emergency_actions?: EmergencyActions[];
    tab: AccessTab;
    filters: { site: string; q: string | null; state: string; status: string; when: string };
    sites: Option[];
    consoles: AccessConsoleInfo[];
    counts: { doors: number; people: number; visitors: number };
    meters: {
        locked: { count: number; of: number; caption: string };
        held_open: { count: number; caption: TimedLine };
        turned_off: { count: number; caption: string };
        to_match: { count: number; caption: string };
    };
    doors: DoorsData | null;
    people: PeopleData | null;
    visitors: { rows: VisitorRow[]; total: number } | null;
    schedules: { rows: ScheduleRow[] } | null;
    activity: ActivityData | null;
    /** Staff at each Site (by Site id), loaded when Give someone access or Match opens. */
    staff?: Record<string, StaffOption[]>;
    can: { manage: boolean; roll_call: boolean };
};
