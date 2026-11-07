/**
 * Obli fixes things (S&D Phase I): the payloads the server sends for a computer's Obli help tab
 * (9d), a help session's record (pop-up 96), opening its screen pictures (pop-up 94), a one-off
 * request (pop-up 95), and Setup › Computers & Obli › Obli (15i) and › Fixes Obli can make (15j,
 * pop-up 93). Times arrive as instants and are written in NZ time through lib/datetime.
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export type ObliRing = 'success' | 'warning' | 'info' | 'none' | 'critical';

export type ObliSessionSummary = {
    uuid: string;
    title: string;
    who: string;
    at: string;
    badge: { label: string; tone: StatusVariant };
    ring: ObliRing;
    sub: string;
};

export type ObliSessionStep = {
    key: number;
    at: string;
    text: string;
    note: string | null;
    tone: ObliRing;
    picture: boolean;
};

export type ObliSessionRecord = {
    uuid: string;
    title: string;
    who: string;
    at: string;
    device: { id: number; name: string };
    badge: { label: string; tone: StatusVariant };
    sub: string;
    problem: string | null;
    /** false once the chat retention has scrubbed what was typed and said */
    kept: boolean;
    found: { tone: 'ok' | 'warning' | 'critical'; text: string }[];
    plan: { text: string; note: string | null }[];
    refusal: string | null;
    planned_at: string | null;
    approved_at: string | null;
    outcome: { text: string; at: string | null } | null;
    steps: ObliSessionStep[];
    pictures: { count: number; deleted_on: string | null; can_open: boolean };
    views: { who: string; at: string; reason: string }[];
    ticket: { id: number; reference: string; href: string } | null;
    note: string;
};

export type ObliHelpTabData = {
    sessions: ObliSessionSummary[];
    latest: ObliSessionRecord | null;
    may: [string, string][];
    requests: { id: number; asked_at: string; who: string }[];
    can: { open_pictures: boolean; decide: boolean; manage_fixes: boolean };
};

export type ObliPicture = { id: number; turn: number; width: number; height: number; taken_at: string; url: string };

export type ObliPictureView = { view_id: number; expires_at: string; pictures: ObliPicture[] };

export type ObliFixRequestPanel = {
    id: number;
    device: { id: number; name: string };
    asker: string;
    asked_at: string;
    problem: string | null;
    problem_at: string | null;
    what: string | null;
    kind: 'fix' | 'commands';
    fix: { key: string; label: string } | null;
    commands: string[];
    host: string;
    state: 'requested' | 'approved' | 'declined' | 'expired' | 'cancelled';
    route: 'governed' | 'by_hand' | null;
    decided: { by: string; at: string } | null;
    result: string | null;
    governed_href: string | null;
    session: string | null;
    can_decide: boolean;
    live: boolean;
};

export type ObliSetupData = {
    helper: { on: boolean; computers_on: boolean; can_change: boolean };
    health: { on: boolean; reads: string | null };
    fixes: { on: boolean; version: number; blocker: string | null; can_change: boolean };
    pictures: { days: number; who: string };
    ai: { enabled: boolean; provider: string; computer_use: boolean; limit: string };
    limits: { minutes: number; actions: number };
    settings_href: string;
};

export type ObliFixPlace = { device_id: number; name: string; site: string | null; by: string; at: string; blocked: string | null };

export type ObliFixRow = {
    key: string;
    label: string;
    sub: string;
    icon: 'cog' | 'power';
    runs_as: string;
    turned_on: { by: string; at: string } | null;
    where: ObliFixPlace[];
    used: { asked: number; ran: number } | null;
    state: { label: string; tone: StatusVariant; note: string | null };
    can_turn_on: boolean;
    script: string[];
};

export type ObliFixesSetupData = {
    rows: ObliFixRow[];
    computers: { id: number; name: string; site: string | null }[];
    can_manage: boolean;
};

/** "Request state" words, the same on every screen. */
export const REQUEST_STATE: Record<ObliFixRequestPanel['state'], { label: string; tone: StatusVariant }> = {
    requested: { label: 'Waiting for IT', tone: 'info' },
    approved: { label: 'Approved once', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

/** The message a failed request gets, in plain words. */
export function obliError(status: number | undefined): string {
    if (status === 403) return 'You don’t have permission to do that.';
    if (status === 404) return 'It isn’t there any more, or you can’t see it.';
    if (status === 409) return 'Someone else changed it first, or Obli’s session has ended. Reload and try again.';
    if (status === 410) return 'These pictures have already been deleted.';
    if (status === 422) return 'Check what you entered and try again.';
    if (status === 429) return 'Too many tries. Wait a minute and try again.';
    return 'It wasn’t done. Try again.';
}
