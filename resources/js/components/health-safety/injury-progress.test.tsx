import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { injuryNextBar, injuryRoundSteps, injuryStepPane } from './injury-progress';
import type { InjuryRecord } from './injury-workspace-types';

afterEach(cleanup);

const HEMI = { id: 7, name: 'Hemi Walker' };

/** Hemi coordinates Mere's return to work and has no wellbeing access (board G4-8). */
function record(changes: Partial<InjuryRecord> = {}): InjuryRecord {
    return {
        id: 13, reference: 'INJ-2026-0013', title: 'Cut left index finger — Mere Tane', injury_words: 'Cut left index finger', reported_by: 'Mere Tane',
        status: 'return_to_work', severity: 'minor', injury_type: 'laceration', injury_type_label: 'Laceration', body_part_affected: 'Left index finger',
        description: 'The knife slipped on a wet chopping board.', injury_date: '2026-10-26T22:20:00Z', created_at: '2026-10-26T22:40:00Z',
        lost_time_days: 0, worker: { id: 9, name: 'Mere Tane' }, site: { id: 2, name: 'Kauri House' }, medical_access: 'restricted',
        source_integrity: 'valid', recovery_integrity: 'unknown', history_integrity: 'unknown', evidence_integrity: 'unknown',
        coordination: { coordinator: HEMI, attribution_state: 'recorded', next_review_date: '2026-11-03' },
        rtw_plans: null, capacity_assessments: null, attachments: null, history: null, audits: null,
        immediate_treatment: null, medical_treatment_type: null, medical_treatment_label: null, acc_claim_number: null, notes: null,
        claim_delivery: { internally_recorded: false, external_receipt_state: 'unconfirmed', lodged_at: null, acknowledged_at: null },
        duty_restrictions: {
            items: [{
                id: 4, status: 'active', start_date: '2026-10-28', end_date: '2026-11-03', duties: 'Light kitchen and lounge duties on her 7–3 shift',
                restrictions: 'No knife work or wet hand-washing tasks', hours_per_day: 8, set_by: 'HR advisor', set_at: '2026-10-27T21:00:00Z',
            }],
        },
        safety_review: null,
        can: { manage: true, create: true, update: true, transition: true, assign: true, review: false, recovery: false, upload_evidence: false, remove_evidence: false, read_evidence: false, record_medical_clearance: false, approve_restrictions: false, declare_fit: false },
        commands: { expected_version: 3, transitions: ['recovered'] },
        ...changes,
    } as InjuryRecord;
}

it('draws the injury’s own five steps, Return to work since the first duty restriction', () => {
    const steps = injuryRoundSteps(record());
    expect(steps.map((step) => step.label)).toEqual(['Reported', 'Under treatment', 'Return to work', 'Recovered', 'Closed']);
    expect(steps.map((step) => step.state)).toEqual(['done', 'done', 'now', 'todo', 'todo']);
    expect(String(steps[0].meta)).toMatch(/^Mere Tane · /);
    expect(String(steps[2].meta)).toBe('Since Wed 28 Oct');
});

it('says the next step once, to the coordinator, with the next review date from the record', () => {
    const open = vi.fn();
    const bar = injuryNextBar(record(), HEMI.id, open);
    expect(bar.title).toBe('You record the recovery when Mere is back on full duties');
    expect(bar.text).toBe('next review Tue 3 Nov');
    render(<>{bar.action}</>);
    fireEvent.click(screen.getByRole('button', { name: 'Record recovery' }));
    expect(open).toHaveBeenCalledWith('mark_recovered');
});

it('names the coordinator to others, and gives HR (who can’t act) no button', () => {
    const officer = injuryNextBar(record(), 3, vi.fn());
    expect(officer.title).toBe('Hemi Walker records the recovery when Mere is back on full duties');
    const hr = injuryNextBar(record({ medical_access: 'readable', can: { ...record().can, transition: false, manage: false, assign: false }, commands: { expected_version: 3, transitions: [] } }), 21, vi.fn());
    expect(hr.title).toBe('Hemi Walker records the recovery when Mere is back on full duties');
    expect(hr.action).toBeUndefined();
});

it('shows the coordinator the duty restrictions and never why (decision 10)', () => {
    const pane = injuryStepPane(record(), 'return_to_work', HEMI.id, {});
    const duty = pane.items?.find((item) => item.label === 'Duty restrictions');
    expect(duty?.value).toBe('No knife work or wet hand-washing tasks; Light kitchen and lounge duties on her 7–3 shift');
    expect(String(duty?.meta)).toBe('Until Tue 3 Nov · set by HR advisor · the reason stays private');
    expect(pane.items?.find((item) => item.label === 'Next review')?.meta).toBe('You, as coordinator');
    expect(pane.note).toBe('Medical details stay private: you see what Mere can do at work, never why.');
    expect(pane.activity?.items.map((item) => `${item.who} ${item.what}`)).toEqual(['HR advisor set the duty restrictions until Tue 3 Nov']);
    // Under treatment: whether a claim was lodged, never the claim or the treatment.
    const treatment = injuryStepPane(record(), 'under_treatment', HEMI.id, {});
    expect(treatment.items?.map((item) => item.label)).toEqual(['ACC claim lodged']);
});

it('asks for a coordinator first when nobody coordinates it', () => {
    const bar = injuryNextBar(record({ coordination: { coordinator: null, attribution_state: 'unassigned', next_review_date: null } }), 3, vi.fn());
    expect(bar.owns).toBe('assign');
    expect(bar.title).toBe('Choose who coordinates this injury');
});
