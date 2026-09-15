import { describe, expect, it } from 'vitest';
import {
    attendanceMeter,
    buildRsvpPayload,
    checklistStatusChip,
    conflictsMeter,
    heldMeetingPrompt,
    meetingDayReached,
    meetingHasHappened,
    meetingStatusChip,
    minutesHistoryLabel,
    minutesStatusChip,
    orderResolutions,
    packMeter,
    quorumMeter,
    readResolutionLabel,
    readWorkspaceLocation,
    recordedName,
    resolutionVoteNote,
    votesMeter,
    workspaceTabKeys,
} from './_workspace';

describe('meeting workspace tabs', () => {
    it('puts what members use first and keeps Workflow for the people running the meeting', () => {
        expect(workspaceTabKeys(false)).toEqual(['agenda', 'resolutions', 'attendance', 'minutes']);
        expect(workspaceTabKeys(true)).toEqual([
            'agenda',
            'resolutions',
            'attendance',
            'minutes',
            'workflow',
        ]);
    });

    it('opens the resolutions tab for a paper link and ignores unknown tabs', () => {
        expect(readWorkspaceLocation('/governance/meetings/4?paper=9')).toEqual({
            tab: 'resolutions',
            paper: '9',
            focus: null,
        });
        expect(readWorkspaceLocation('/governance/meetings/4?tab=papers').tab).toBe('agenda');
    });
});

describe('resolution rows', () => {
    const open = { id: 1, status: 'open', can_vote: true, my_vote: null };

    it('offers "Read resolution & vote" only when the member can vote now', () => {
        expect(readResolutionLabel(open)).toBe('Read resolution & vote');
        expect(readResolutionLabel({ ...open, my_vote: { vote: 'for' } })).toBe('Read resolution');
        expect(readResolutionLabel({ ...open, can_vote: false })).toBe('Read resolution');
        expect(readResolutionLabel({ ...open, status: 'draft' })).toBe('Read resolution');
        expect(readResolutionLabel({ ...open, status: 'closed' })).toBe('Read resolution');
    });

    it('says where the member stands in plain words', () => {
        expect(resolutionVoteNote({ ...open, my_vote: { vote: 'against' } })).toBe('You voted Against');
        expect(resolutionVoteNote(open)).toBe('Open for your vote');
        expect(resolutionVoteNote({ id: 2, status: 'draft' })).toBe("Voting hasn't opened yet");
        expect(resolutionVoteNote({ id: 3, status: 'open', purpose: 'information' })).toBe('No vote needed');
    });

    it('lists resolutions in agenda order — the order "Next resolution" follows', () => {
        const resolutions = [{ id: 10 }, { id: 11 }, { id: 12 }];
        const ordered = orderResolutions(
            [{ resolution_id: 12 }, { resolution_id: null }, { resolution_id: 10 }, { resolution_id: 99 }],
            resolutions,
        );
        expect(ordered.map((r) => r.id)).toEqual([12, 10, 11]);
    });
});

describe('meeting timing', () => {
    const now = new Date('2026-09-15T02:00:00Z'); // 2pm, 15 Sep in Auckland

    it('reaches the meeting day by the NZ calendar, not UTC', () => {
        // 9am 15 Sep NZST is still 14 Sep in UTC.
        expect(meetingDayReached('2026-09-14T21:00:00Z', now)).toBe(true);
        expect(meetingDayReached('2026-09-15T13:00:00Z', now)).toBe(false); // 1am 16 Sep NZ
        expect(meetingDayReached(null, now)).toBe(false);
    });

    it('treats a meeting as happened once it has finished', () => {
        expect(meetingHasHappened('2026-09-15T00:00:00Z', 60, now)).toBe(true);
        expect(meetingHasHappened('2026-09-15T01:30:00Z', 60, now)).toBe(false);
    });
});

describe('held meeting prompt', () => {
    const base = {
        status: 'scheduled',
        happened: true,
        attendanceRecorded: false,
        minutesStarted: false,
        canRecordAttendance: true,
        canWriteMinutes: true,
    };

    it('tells the chair what to do after a meeting that is still "Scheduled"', () => {
        const prompt = heldMeetingPrompt(base);
        expect(prompt?.title).toBe('This meeting has happened — record attendance and write the minutes');
        expect(prompt?.primary).toBe('attendance');
        expect(prompt?.secondary).toBe('minutes');
    });

    it('asks only for what is still missing, and says who can do it', () => {
        expect(heldMeetingPrompt({ ...base, attendanceRecorded: true })?.title).toBe(
            'This meeting has happened — write the minutes',
        );
        const blocked = heldMeetingPrompt({ ...base, canRecordAttendance: false });
        expect(blocked?.primary).toBe('minutes');
        expect(blocked?.body).toContain("Attendance can no longer be changed");
        expect(heldMeetingPrompt({ ...base, canWriteMinutes: false, attendanceRecorded: true })?.body).toBe(
            'The secretary or chair writes the minutes.',
        );
    });

    it('stays quiet before the meeting and once the follow-up has started', () => {
        expect(heldMeetingPrompt({ ...base, happened: false })).toBeNull();
        expect(heldMeetingPrompt({ ...base, attendanceRecorded: true, minutesStarted: true })).toBeNull();
        expect(heldMeetingPrompt({ ...base, status: 'minutes_draft' })).toBeNull();
    });
});

describe('member preparation meters', () => {
    it('shows whether the member has read the board pack they were sent', () => {
        expect(packMeter(null).value).toBe('Not sent yet');
        expect(
            packMeter({ sent: true, is_recipient: true, read: false, read_at: null, version: 2 }),
        ).toMatchObject({ value: 'To read', caption: "Version 2 · confirm you've read it" });
        expect(
            packMeter({
                sent: true,
                is_recipient: true,
                read: true,
                read_at: '2026-09-07T05:00:00Z',
                version: 2,
            }),
        ).toMatchObject({ value: 'Read', tone: 'success', caption: 'You confirmed version 2 on 7 September 2026' });
    });

    it('counts open votes and declared conflicts from the member’s own resolutions', () => {
        const resolutions = [
            { id: 1, status: 'open', can_vote: true, my_vote: null },
            { id: 2, status: 'open', can_vote: true, my_vote: { vote: 'for' } },
            { id: 3, status: 'draft', my_conflict: { withdrew_from_voting: true } },
        ];
        expect(votesMeter(resolutions)).toMatchObject({ open: 1, value: '1', caption: '1 resolution open for your vote' });
        expect(conflictsMeter(resolutions)).toMatchObject({ value: '1', caption: 'Declared by you' });
        expect(votesMeter([{ id: 4, status: 'draft' }]).caption).toBe("Voting hasn't opened yet");
    });

    it('names the reply, or asks for one before the meeting', () => {
        expect(attendanceMeter('accepted', false)).toMatchObject({ value: 'Attending', tone: 'success' });
        expect(attendanceMeter('declined', false).value).toBe('Sent apologies');
        expect(attendanceMeter(null, false)).toMatchObject({ value: 'Not confirmed', tone: 'warning' });
        expect(attendanceMeter(null, true).value).toBe('No reply');
    });

    it('reads the quorum as a head count', () => {
        expect(quorumMeter({ present: 2, required: 4, met: false })).toMatchObject({
            value: '2 of 4',
            caption: '2 more members needed',
            tone: 'warning',
        });
        expect(quorumMeter({ present: 4, required: 4, met: true }).caption).toBe('Quorum met');
    });
});

describe('plain status words', () => {
    it('never shows a raw checklist key', () => {
        for (const status of ['done', 'in_progress', 'todo', 'blocked', 'not_applicable', 'something_new']) {
            expect(checklistStatusChip(status).label).not.toContain('_');
        }
        expect(checklistStatusChip('blocked')).toEqual({ label: 'Waiting on an earlier step', variant: 'neutral' });
        expect(checklistStatusChip('todo', 'To do').label).toBe('To do');
    });

    it('words meeting and minutes stages for people', () => {
        expect(meetingStatusChip('pack_draft').label).toBe('Board pack being prepared');
        expect(meetingStatusChip('minutes_review').label).toBe('Minutes waiting for the board');
        expect(minutesStatusChip('reviewed').label).toBe('Sent for approval');
        expect(recordedName('Legacy attribution unavailable')).toBe('Name not recorded');
        expect(minutesHistoryLabel({ event: 'superseded_for_correction' })).toBe('Replaced by a correction');
        expect(minutesHistoryLabel({ note: 'Initial draft created' })).toBe('First draft');
    });
});

describe('reply to the invitation', () => {
    it('keeps the apology reason and the dietary needs apart', () => {
        expect(
            buildRsvpPayload({
                response: 'declined',
                decline_reason: '  Overseas that week ',
                dietary_requirements: true,
                dietary_notes: 'Vegetarian',
            }),
        ).toEqual({
            response: 'declined',
            decline_reason: 'Overseas that week',
            dietary_requirements: false,
            dietary_notes: null,
        });

        expect(
            buildRsvpPayload({
                response: 'tentative',
                decline_reason: 'left over',
                dietary_requirements: true,
                dietary_notes: 'Step-free access',
            }),
        ).toEqual({
            response: 'tentative',
            decline_reason: null,
            dietary_requirements: true,
            dietary_notes: 'Step-free access',
        });

        expect(
            buildRsvpPayload({
                response: 'accepted',
                decline_reason: '',
                dietary_requirements: false,
                dietary_notes: 'typed then unticked',
            }).dietary_notes,
        ).toBeNull();
    });
});
