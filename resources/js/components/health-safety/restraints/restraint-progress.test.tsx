import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import { LinkedSafetyReviewCard, linkedReviewChecks, linkedReviewLine, type LinkedSafetyReview } from './linked-safety-review-card';
import { currentRestraintStep, followupSummary, restraintNextBar, restraintRoundSteps, restraintStepPane } from './restraint-progress';
import type { RestraintEventDetail, RestraintFollowup, SourceFact } from './types';

vi.mock('@inertiajs/react', () => ({ Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => <a href={href} {...rest}>{children}</a> }));
afterEach(cleanup);

const HEMI = { id: 7, name: 'Hemi Walker', visibility: 'current' as const };
const MERE = { id: 9, name: 'Mere Tane', visibility: 'current' as const };
const DANA = { id: 3, name: 'Dana Hall', visibility: 'current' as const };

const fact = (id: number, at: string, changes: Partial<SourceFact> = {}): SourceFact => ({
    id, source_revision: 1, supersedes_id: null, occurred_at: at, created_at: at, evidence_ids: [], recorder: MERE, actual_actor: null, facts: {}, ...changes,
});
const followup = (id: number, notes: string, assignee: typeof MERE, changes: Partial<RestraintFollowup> = {}): RestraintFollowup => ({
    id, notes, assignment_revision: 1, due_at: '2026-11-06T04:00:00Z', completed_at: null, cancelled_at: null, cancellation_reason: null,
    completion_outcome: null, completion_evidence_ids: [], creator: DANA, assignee, completed_by: null, cancelled_by: null, history: [],
    can: { complete: false, reassign: true, cancel: true, correct: false }, ...changes,
});

function detail(changes: Partial<RestraintEventDetail['event']> = {}, can: Partial<RestraintEventDetail['can']> = {}): RestraintEventDetail {
    return {
        event: {
            id: 31, reference_number: 'RE-2026-0031', title: 'Restraint — Aroha Kingi', client_id: 1, site_id: 2, client: { id: 1, name: 'Aroha Kingi' },
            site: { id: 2, name: 'Kauri House' }, lock_version: 4, source_revision: 1, started_at: '2026-10-21T03:10:00Z', ended_at: '2026-10-21T03:11:00Z',
            created_at: '2026-10-21T03:25:00Z', duration_minutes: 1, restraint_type: 'physical', severity: 'low', trigger_description: null,
            de_escalation_attempted: null, restraint_description: 'Held arm', person_response: null, post_incident_support: null, injury_occurred: false,
            injury_details: null, within_support_plan: true, deviation_reason: null, reviewed_at: null, review_notes: null, lessons_learned: null,
            review_due_at: '2026-11-02T04:00:00Z', review_assignment_revision: 1, reviewed_source_revision: null, needs_review: true,
            source_provenance: 'recorded', plan_authorization: { state: 'verified', explanation: null }, staff_involved: [MERE], authorised_by: HEMI,
            reviewed_by: null, review_owner_id: HEMI, created_by: MERE, updated_by: MERE, reviews: [],
            observations: [fact(1, '2026-10-21T03:40:00Z'), fact(2, '2026-10-21T20:00:00Z')], debriefs: [],
            followups: [
                followup(11, 'Tell Aroha’s family/whānau what happened', MERE, { completed_at: '2026-10-21T04:00:00Z', completion_outcome: { outcome: 'contacted', notes: null } }),
                followup(12, 'Talk to the behaviour support practitioner', DANA),
            ],
            attachments: [], deliveries: [], ...changes,
        },
        connections: { client: null, incident: null, plan: null, plan_version: null, plan_approval: null, stay: null, health_safety: null, control_room: null },
        safety_review: null,
        can: { review: true, manage: false, capture: true, ...can },
    };
}

it('carries only the restraint’s own five steps, with the debrief next once checks are recorded', () => {
    const d = detail();
    expect(currentRestraintStep(d.event)).toBe('debrief');
    const steps = restraintRoundSteps(d.event);
    expect(steps.map((step) => step.label)).toEqual(['Recorded', 'Restraint review owner', 'Monitoring', 'Debrief', 'Restraint review']);
    expect(steps.map((step) => step.state)).toEqual(['done', 'done', 'done', 'now', 'todo']);
    expect(steps[1].meta).toBe('Hemi Walker');
    expect(String(steps[2].meta)).toMatch(/^2 checks · last /);
    expect(String(steps[4].meta)).toMatch(/^Due /);
});

it('speaks to the owner in the next step, and names the owner to everyone else', () => {
    const run = vi.fn();
    const owner = restraintNextBar(detail(), HEMI.id, run);
    expect(owner.title).toBe('You record the debrief, then the restraint review');
    expect(owner.owns).toBe('record-debrief');
    render(<>{owner.action}</>);
    fireEvent.click(screen.getByRole('button', { name: 'Record debrief' }));
    expect(run).toHaveBeenCalledWith('record-debrief');
    cleanup();

    const officer = restraintNextBar(detail(), DANA.id, run);
    expect(officer.title).toBe('Hemi Walker records the debrief, then the restraint review');

    // A reader the server doesn't let record it gets the words, never a button.
    const reader = restraintNextBar(detail({}, { review: false }), MERE.id, run);
    expect(reader.action).toBeUndefined();
    expect(reader.owns).toBeNull();
});

it('asks for an owner first, then the restraint review once debriefed, and finishes when reviewed', () => {
    expect(restraintNextBar(detail({ review_owner_id: null }), DANA.id, vi.fn()).owns).toBe('assign-review');
    expect(restraintNextBar(detail({ debriefs: [fact(5, '2026-10-23T01:00:00Z')] }), HEMI.id, vi.fn()).title).toBe('You record the restraint review');
    const done = restraintNextBar(detail({ needs_review: false, reviewed_at: '2026-10-30T01:00:00Z' }), HEMI.id, vi.fn());
    expect(done.tone).toBe('done');
    expect(done.action).toBeUndefined();
});

it('sums up follow-ups on the debrief step, with “yours” for the viewer’s own', () => {
    const d = detail();
    expect(followupSummary(d.event.followups, DANA.id)).toMatch(/^Tell Aroha’s family\/whānau what happened \(Mere Tane, done\) · Talk to the behaviour support practitioner \(yours, due /);
    const pane = restraintStepPane(d, 'debrief', DANA.id, { onHistory: vi.fn(), run: vi.fn() });
    expect(pane.meta).toBe('Not recorded yet · Hemi Walker owns the restraint review');
    expect(pane.note).toBe('You review restraints at Kauri House too, so you can record the debrief or the restraint review as well as Hemi Walker.');
    expect(pane.activity?.items.map((item) => item.what)).toEqual(['recorded monitoring check 2', 'recorded monitoring check 1', 'recorded the restraint']);
});

const review = (changes: Partial<LinkedSafetyReview> = {}): LinkedSafetyReview => ({
    id: 87, reference: 'HS-2026-0087', status: 'open', severity: 'low', url: '/health-safety/events/87', owner: null, assigned_at: null,
    investigation: { required: false, started: false, done: false, done_at: null }, actions: { total: 0, done: 0, last_done_at: null, checked: 0, failed: 0 },
    worksafe: { decided: false, notifiable: null, decided_at: null }, closed_at: null, ...changes,
});

it('draws the safety review card from the server’s facts, and nothing at all when the viewer can’t open it', () => {
    const checks = linkedReviewChecks(review());
    expect(checks.map((item) => item.note)).toEqual(['No owner yet', 'Not needed at Low severity', 'None', 'Nothing to check', 'After the WorkSafe decision']);
    expect(linkedReviewLine(review())).toBe('No owner yet. The WorkSafe decision is next; once it’s recorded the safety review can close.');
    expect(linkedReviewLine(review({ owner: 'Hemi Walker', actions: { total: 1, done: 1, last_done_at: null, checked: 1, failed: 1 } }), 'Hemi Walker'))
        .toBe('A check said a fix didn’t work. A new action is needed before the safety review can close. You own it.');

    render(<LinkedSafetyReviewCard review={review()} />);
    expect(screen.getByRole('link', { name: /Go to record/ })).toHaveAttribute('href', '/health-safety/events/87');
    cleanup();
    // Decision 6: no summary → no card, no "hidden" line.
    const { container } = render(<LinkedSafetyReviewCard review={null} />);
    expect(container).toBeEmptyDOMElement();
});
