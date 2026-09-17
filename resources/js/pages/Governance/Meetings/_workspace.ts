/**
 * Meeting workspace rules and wording (docs/audits/2026-09-14-governance-
 * plain-language-ux/vocabulary.md). Pure functions, so the Meetings pages
 * stay thin and every rule here is unit-tested in `_workspace.test.ts`.
 */
import type { MeetingWorkspaceFocus } from '@/components/governance/meeting-workspace-links';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateLong, toDateInput } from '@/lib/datetime';
import {
    governanceStatus,
    humaniseGovernanceValue,
    voteLabel,
    type GovernanceStatusChip,
} from '@/lib/governance-labels';

/* -------------------------------------------------------------------------- */
/*  Tabs and location                                                          */
/* -------------------------------------------------------------------------- */

/** In the order members use them: prepare, decide, attend, then the record. */
export const MEETING_TABS = [
    'agenda',
    'resolutions',
    'attendance',
    'minutes',
    'workflow',
] as const;

export type MeetingTab = (typeof MEETING_TABS)[number];

export const MEETING_TAB_LABELS: Record<MeetingTab, string> = {
    agenda: 'Agenda',
    resolutions: 'Resolutions',
    attendance: 'Attendance',
    minutes: 'Minutes',
    workflow: 'Workflow',
};

export function isMeetingTab(value: string | null | undefined): value is MeetingTab {
    return Boolean(value && (MEETING_TABS as readonly string[]).includes(value));
}

/** Workflow is the chair and secretary's checklist — only for people who run the meeting. */
export function workspaceTabKeys(canRunMeeting: boolean): MeetingTab[] {
    return MEETING_TABS.filter((tab) => tab !== 'workflow' || canRunMeeting);
}

export function readWorkspaceLocation(url: string): {
    tab: MeetingTab;
    paper: string | null;
    focus: MeetingWorkspaceFocus | null;
} {
    const params = new URLSearchParams(url.split('#')[0]?.split('?')[1] ?? '');
    const tab = params.get('tab');
    const paper = params.get('paper');
    return {
        tab: isMeetingTab(tab) ? tab : paper ? 'resolutions' : 'agenda',
        paper,
        focus: params.get('focus') === 'follow-ups' ? 'follow-ups' : null,
    };
}

/**
 * A meeting's status chip. `pack_draft` (set when a board pack is first
 * prepared) has no entry in the shared meeting_status labels yet, so it is
 * worded here rather than humanised to "Pack draft".
 */
export function meetingStatusChip(status: string | null | undefined): GovernanceStatusChip {
    if (status === 'pack_draft') return { label: 'Board pack being prepared', variant: 'neutral' };
    return governanceStatus('meeting_status', status);
}

/** Every stage a meeting can reach, for the register's status filter. */
export const MEETING_STATUS_FILTERS = [
    'scheduled',
    'agenda_draft',
    'agenda_final',
    'pack_draft',
    'in_progress',
    'minutes_draft',
    'minutes_review',
    'minutes_approved',
    'minutes_signed',
    'archived',
    'cancelled',
] as const;

/* -------------------------------------------------------------------------- */
/*  Resolutions                                                                */
/* -------------------------------------------------------------------------- */

export interface ResolutionForMember {
    id: number;
    status: string;
    purpose?: string | null;
    can_vote?: boolean;
    my_vote?: { vote: string } | null;
    my_conflict?: { withdrew_from_voting: boolean } | null;
}

/** Voting is open, the member may vote, and they haven't yet. */
export function isOpenForMyVote(resolution: ResolutionForMember): boolean {
    return (
        resolution.status === 'open' &&
        Boolean(resolution.can_vote) &&
        !resolution.my_vote
    );
}

/** The one button a resolution row (or agenda item) offers. */
export function readResolutionLabel(resolution: ResolutionForMember): string {
    return isOpenForMyVote(resolution) ? 'Read resolution & vote' : 'Read resolution';
}

/** Where the member stands on a resolution, in a few words (or null). */
export function resolutionVoteNote(resolution: ResolutionForMember): string | null {
    if (resolution.my_vote) return `You voted ${voteLabel(resolution.my_vote.vote)}`;
    if (resolution.my_conflict?.withdrew_from_voting) return 'You stepped aside from the vote';
    if (isOpenForMyVote(resolution)) return 'Open for your vote';
    if (resolution.purpose === 'information' || resolution.purpose === 'discussion') {
        return 'No vote needed';
    }
    if (resolution.status === 'draft' || resolution.status === 'proposed') {
        return "Voting hasn't opened yet";
    }
    return null;
}

/**
 * Resolutions in agenda order, then any others — the same order the paper's
 * "Next resolution" follows, so the list and the workspace agree.
 */
export function orderResolutions<T extends { id: number }>(
    agendaItems: ReadonlyArray<{ resolution_id?: number | null }>,
    resolutions: readonly T[],
): T[] {
    const byId = new Map(resolutions.map((resolution) => [resolution.id, resolution]));
    const ordered: T[] = [];
    const seen = new Set<number>();
    for (const item of agendaItems) {
        const id = item.resolution_id;
        if (typeof id === 'number' && byId.has(id) && !seen.has(id)) {
            ordered.push(byId.get(id)!);
            seen.add(id);
        }
    }
    for (const resolution of resolutions) {
        if (!seen.has(resolution.id)) {
            ordered.push(resolution);
            seen.add(resolution.id);
        }
    }
    return ordered;
}

/* -------------------------------------------------------------------------- */
/*  Meeting timing                                                             */
/* -------------------------------------------------------------------------- */

/** On or after the meeting's NZ calendar day — when attendance can be counted. */
export function meetingDayReached(
    scheduledAt: string | null | undefined,
    now: Date | number = Date.now(),
): boolean {
    const day = toDateInput(scheduledAt);
    const today = toDateInput(now);
    return Boolean(day && today) && day <= today;
}

/** The meeting has finished: its start plus its length is in the past. */
export function meetingHasHappened(
    scheduledAt: string | null | undefined,
    durationMinutes: number | null | undefined,
    now: Date | number = Date.now(),
): boolean {
    if (!scheduledAt) return false;
    const start = new Date(scheduledAt).getTime();
    if (Number.isNaN(start)) return false;
    const end = start + Math.max(0, durationMinutes ?? 0) * 60_000;
    return end < (typeof now === 'number' ? now : now.getTime());
}

/** Meeting stages in which nothing after the meeting has been started yet. */
const BEFORE_FOLLOW_UP = ['scheduled', 'agenda_draft', 'agenda_final', 'pack_draft', 'in_progress'];

export interface HeldMeetingPrompt {
    title: string;
    body: string;
    /** The first thing to do, if the viewer can do it. */
    primary: 'attendance' | 'minutes' | null;
    /** The second thing to do, if the viewer can do it too. */
    secondary: 'minutes' | null;
}

/**
 * For the people running a meeting that has already happened but whose
 * attendance or minutes haven't been started: say so, instead of offering
 * pre-meeting work like preparing the board pack.
 */
export function heldMeetingPrompt(input: {
    status: string;
    happened: boolean;
    attendanceRecorded: boolean;
    minutesStarted: boolean;
    canRecordAttendance: boolean;
    canWriteMinutes: boolean;
}): HeldMeetingPrompt | null {
    if (!input.happened || !BEFORE_FOLLOW_UP.includes(input.status)) return null;

    const needsAttendance = !input.attendanceRecorded;
    const needsMinutes = !input.minutesStarted;
    if (!needsAttendance && !needsMinutes) return null;

    const tasks = [
        needsAttendance ? 'record attendance' : null,
        needsMinutes ? 'write the minutes' : null,
    ].filter(Boolean);

    const sentences: string[] = [];
    if (needsAttendance) {
        sentences.push(
            input.canRecordAttendance
                ? 'Record who was present so the quorum and the minutes are right.'
                : 'Attendance can no longer be changed for this meeting. An administrator can help if it still needs recording.',
        );
    }
    if (needsMinutes) {
        sentences.push(
            !input.canWriteMinutes
                ? 'The secretary or chair writes the minutes.'
                : needsAttendance && input.canRecordAttendance
                  ? 'Then write up what was discussed and decided.'
                  : 'Write up what was discussed and decided.',
        );
    }

    const primary =
        needsAttendance && input.canRecordAttendance
            ? 'attendance'
            : needsMinutes && input.canWriteMinutes
              ? 'minutes'
              : null;

    return {
        title: `This meeting has happened — ${tasks.join(' and ')}`,
        body: sentences.join(' '),
        primary,
        secondary:
            primary === 'attendance' && needsMinutes && input.canWriteMinutes
                ? 'minutes'
                : null,
    };
}

/* -------------------------------------------------------------------------- */
/*  Header meters                                                              */
/* -------------------------------------------------------------------------- */

export type MeterTone = 'brand' | 'success' | 'warning' | 'critical';

export interface MeterReading {
    value: string;
    caption: string;
    tone: MeterTone;
}

export interface PackReading {
    sent: boolean;
    is_recipient: boolean;
    read: boolean;
    read_at: string | null;
    version: number;
}

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** "Board pack — Read / To read", or why there's nothing to read yet. */
export function packMeter(reading: PackReading | null | undefined): MeterReading {
    if (!reading) {
        return {
            value: 'Not sent yet',
            caption: 'The secretary sends it before the meeting',
            tone: 'brand',
        };
    }
    if (!reading.sent) {
        return {
            value: 'Ready to send',
            caption: `Version ${reading.version} · not sent to members yet`,
            tone: 'warning',
        };
    }
    if (!reading.is_recipient) {
        return {
            value: 'Sent',
            caption: `Version ${reading.version} sent to members`,
            tone: 'success',
        };
    }
    return reading.read
        ? {
              value: 'Read',
              caption: reading.read_at
                  ? `You confirmed version ${reading.version} on ${formatDateLong(reading.read_at)}`
                  : `You confirmed version ${reading.version}`,
              tone: 'success',
          }
        : {
              value: 'To read',
              caption: `Version ${reading.version} · confirm you've read it`,
              tone: 'brand',
          };
}

/** "Your votes — N open". */
export function votesMeter(resolutions: readonly ResolutionForMember[]): MeterReading & {
    open: number;
} {
    const open = resolutions.filter(isOpenForMyVote).length;
    const anyOpen = resolutions.some((resolution) => resolution.status === 'open');
    const anyDraft = resolutions.some(
        (resolution) => resolution.status === 'draft' || resolution.status === 'proposed',
    );
    return {
        open,
        value: String(open),
        caption:
            open > 0
                ? `${plural(open, 'resolution')} open for your vote`
                : anyOpen
                  ? 'Nothing waiting for your vote'
                  : anyDraft
                    ? "Voting hasn't opened yet"
                    : 'No resolutions to vote on',
        tone: open > 0 ? 'warning' : 'brand',
    };
}

/** "Conflicts — N declared". */
export function conflictsMeter(resolutions: readonly ResolutionForMember[]): MeterReading {
    const declared = resolutions.filter((resolution) => resolution.my_conflict).length;
    return {
        value: String(declared),
        caption: declared > 0 ? 'Declared by you' : 'None declared',
        tone: 'brand',
    };
}

/** "Attendance — Attending / Not confirmed". */
export function attendanceMeter(
    response: string | null | undefined,
    happened: boolean,
): MeterReading {
    if (!response) {
        return happened
            ? { value: 'No reply', caption: "You didn't reply to the invitation", tone: 'brand' }
            : { value: 'Not confirmed', caption: 'Let the secretary know', tone: 'warning' };
    }
    return {
        value: governanceStatus('rsvp_response', response).label,
        caption: 'Your reply',
        tone: response === 'accepted' || response === 'attending' ? 'success' : 'brand',
    };
}

/** "Quorum — 3 of 4", shown only on or after the meeting day. */
export function quorumMeter(quorum: {
    present: number;
    required: number;
    met: boolean;
}): MeterReading {
    const needed = Math.max(0, quorum.required - quorum.present);
    return {
        value: `${quorum.present} of ${quorum.required}`,
        caption: quorum.met ? 'Quorum met' : `${plural(needed, 'more member')} needed`,
        tone: quorum.met ? 'success' : 'warning',
    };
}

/** Replies to the invitation, for the people running the meeting. */
export function repliesMeter(rsvps: ReadonlyArray<{ response: string }>): MeterReading {
    const count = (responses: string[]) =>
        rsvps.filter((rsvp) => responses.includes(rsvp.response)).length;
    const attending = count(['accepted', 'attending']);
    const apologies = count(['declined', 'apology']);
    const unsure = count(['tentative', 'unsure']);
    return {
        value: `${attending} attending`,
        caption:
            rsvps.length === 0
                ? 'No replies yet'
                : `${plural(apologies, 'apology', 'apologies')} · ${unsure} not sure`,
        tone: 'brand',
    };
}

/* -------------------------------------------------------------------------- */
/*  Checklist and readiness                                                    */
/* -------------------------------------------------------------------------- */

const CHECKLIST_CHIPS: Record<string, GovernanceStatusChip> = {
    done: { label: 'Done', variant: 'success' },
    in_progress: { label: 'In progress', variant: 'info' },
    todo: { label: 'To do', variant: 'neutral' },
    blocked: { label: 'Waiting on an earlier step', variant: 'neutral' },
    not_applicable: { label: 'Not needed', variant: 'neutral' },
};

/** A checklist step's status chip; the server's plain label wins when sent. */
export function checklistStatusChip(
    status: string,
    serverLabel?: string | null,
): GovernanceStatusChip {
    const chip = CHECKLIST_CHIPS[status] ?? {
        label: humaniseGovernanceValue(status),
        variant: 'neutral' as StatusVariant,
    };
    return serverLabel ? { ...chip, label: serverLabel } : chip;
}

/** The readiness summary card's variant (its value is already plain words). */
export function readinessVariant(status: string): StatusVariant {
    switch (status) {
        case 'done':
            return 'success';
        case 'in_progress':
            return 'info';
        case 'warning':
            return 'warning';
        default:
            return 'neutral';
    }
}

/* -------------------------------------------------------------------------- */
/*  Minutes                                                                    */
/* -------------------------------------------------------------------------- */

/** Minutes sent for approval are stored as `reviewed`. */
export function minutesStatusChip(status: string | null | undefined): GovernanceStatusChip {
    if (status === 'reviewed') return { label: 'Sent for approval', variant: 'warning' };
    return governanceStatus('minutes_status', status);
}

const LEGACY_ATTRIBUTION = 'Legacy attribution unavailable';

/** Older records kept no name; say so plainly. */
export function recordedName(name: string | null | undefined): string | null {
    if (!name) return null;
    return name === LEGACY_ATTRIBUTION ? 'Name not recorded' : name;
}

export interface MinutesHistoryEntry {
    version?: number;
    event?: string;
    status?: string;
    at?: string;
    actor_name?: string;
    note?: string;
    reason_for_correction?: string;
    content_blocks?: Array<{ heading: string; content: string }>;
    content_hash?: string;
}

const HISTORY_EVENTS: Record<string, string> = {
    submitted_for_review: 'Sent for approval',
    approved: 'Approved',
    signed: 'Signed',
    archived: 'Archived',
    superseded_for_correction: 'Replaced by a correction',
};

const HISTORY_NOTES: Record<string, string> = {
    'Initial draft created': 'First draft',
    'First draft started': 'First draft',
    'Prior version superseded by update': 'Earlier draft, replaced by a later edit',
    'Replaced by a later edit': 'Earlier draft, replaced by a later edit',
};

/** What happened at this point in the minutes' history, in plain words. */
export function minutesHistoryLabel(entry: MinutesHistoryEntry): string {
    if (entry.event && HISTORY_EVENTS[entry.event]) return HISTORY_EVENTS[entry.event];
    if (entry.note && HISTORY_NOTES[entry.note]) return HISTORY_NOTES[entry.note];
    if (entry.status) return minutesStatusChip(entry.status).label;
    return 'Saved version';
}

export const DEFAULT_MINUTES_HEADINGS = [
    'Welcome and apologies',
    'Minutes of the previous meeting',
    'Matters arising',
    'General business',
    'Next meeting',
] as const;

/* -------------------------------------------------------------------------- */
/*  Replies to the invitation                                                  */
/* -------------------------------------------------------------------------- */

export type RsvpChoice = 'accepted' | 'declined' | 'tentative';

export const RSVP_CHOICES: ReadonlyArray<{
    key: RsvpChoice;
    label: string;
    description: string;
}> = [
    { key: 'accepted', label: 'Attending', description: "I'll be there." },
    { key: 'declined', label: 'Sending apologies', description: "I can't make it." },
    { key: 'tentative', label: 'Not sure yet', description: "I'll confirm closer to the day." },
];

export interface RsvpFormValues {
    response: RsvpChoice;
    decline_reason: string;
    dietary_requirements: boolean;
    dietary_notes: string;
}

/** Normalise a stored response (legacy keys included) to a tile choice. */
export function rsvpChoiceFor(response: string | null | undefined): RsvpChoice {
    if (response === 'declined' || response === 'apology') return 'declined';
    if (response === 'tentative' || response === 'unsure') return 'tentative';
    return 'accepted';
}

/**
 * Exactly what the server stores: an apology keeps only its reason;
 * attending (or not sure yet) keeps only dietary or access needs.
 */
export function buildRsvpPayload(values: RsvpFormValues): {
    response: RsvpChoice;
    decline_reason: string | null;
    dietary_requirements: boolean;
    dietary_notes: string | null;
} {
    if (values.response === 'declined') {
        return {
            response: 'declined',
            decline_reason: values.decline_reason.trim() || null,
            dietary_requirements: false,
            dietary_notes: null,
        };
    }
    const needs = values.dietary_requirements;
    return {
        response: values.response,
        decline_reason: null,
        dietary_requirements: needs,
        dietary_notes: needs ? values.dietary_notes.trim() || null : null,
    };
}
