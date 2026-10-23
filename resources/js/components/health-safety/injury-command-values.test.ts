import { expect, it } from 'vitest';
import { injuryCommandRequest, injuryFormValues } from './injury-command-values';
import type { InjuryRecord } from './injury-workspace-types';

const record = (medical = false, incidentAccess = 'restricted') => ({
    id: 9, worker: { id: 12, name: 'Worker' }, site: { id: 4, name: 'Kauri' },
    injury_date: '2026-10-11T12:05:00Z', injury_type: 'strain', severity: 'moderate',
    description: 'Recorded facts', body_part_affected: 'Left arm', lost_time_days: 2,
    expected_return_date: '2026-10-20', actual_return_date: null,
    medical_access: medical ? 'readable' : 'restricted',
    immediate_treatment: medical ? 'Treatment fact' : null, medical_treatment_type: medical ? 'gp_visit' : null,
    acc_claim_lodged: false, acc_claim_number: medical ? 'Claim fact' : null, notes: medical ? 'Private notes' : null,
    coordination: { coordinator: null, next_review_date: null }, rtw_plans: null,
    related_incident: null, connections: { incident: { access: incidentAccess } },
}) as unknown as InjuryRecord;

it('omits hidden medical and connected-source fields instead of clearing them during a factual edit', () => {
    const d = record(); const f = injuryFormValues(d); f.description = 'Corrected facts';
    const result = injuryCommandRequest('edit', f, d);
    expect(result).toMatchObject({ method: 'put', href: '/health-safety/injuries/9' });
    expect(result.values.description).toBe('Corrected facts');
    for (const key of ['immediate_treatment', 'medical_treatment_type', 'acc_claim_lodged', 'acc_claim_number', 'notes', 'related_incident_id']) expect(result.values).not.toHaveProperty(key);
    expect(result.values.user_id).toBe(12);
});

it('records an explicit destination owner, reason and review together with a Site correction and keeps ordinary assignment separate', () => {
    const d = record(false, 'absent');
    const f = { ...injuryFormValues(d), site_id: '8', user_id: '23', coordinator_user_id: '31', reason: 'Correct the recorded Site and transfer coordination', next_review_date: '2026-10-21' };
    expect(injuryCommandRequest('edit', f, d).values).toMatchObject({ site_id: 8, user_id: 23, coordinator_user_id: 31, reason: f.reason, next_review_date: '2026-10-21' });
    expect(injuryCommandRequest('edit', { ...f, site_id: '4' }, d).values).not.toHaveProperty('coordinator_user_id');
    expect(injuryCommandRequest('edit', { ...f, coordinator_user_id: '' }, d).values).not.toHaveProperty('reason');
    expect(injuryCommandRequest('assign', f, d)).toMatchObject({ href: '/health-safety/injuries/9/coordinator', values: { coordinator_user_id: 31, reason: f.reason, next_review_date: '2026-10-21' } });
});

it('serializes new reported details only with explicit capture capability while existing medical edits keep their own source boundary', () => {
    const f = { ...injuryFormValues(null), medical_treatment_type: 'gp_visit', immediate_treatment: 'Reporter supplied treatment fact', notes: 'New report notes' };
    for (const key of ['medical_treatment_type', 'immediate_treatment', 'notes', 'acc_claim_number']) expect(injuryCommandRequest('create', f, null).values).not.toHaveProperty(key);
    expect(injuryCommandRequest('create', f, null, undefined, true).values).toMatchObject({ medical_treatment_type: 'gp_visit', immediate_treatment: f.immediate_treatment, notes: f.notes });
    expect(injuryCommandRequest('edit', f, record(true)).values).toHaveProperty('notes', f.notes);
    expect(injuryCommandRequest('edit', f, record(false), undefined, true).values).not.toHaveProperty('notes');
});

it('preserves NZ midnight calendar dates and represents the actual event instant independently', () => {
    const d = record(true, 'absent'); const f = injuryFormValues(d);
    expect(f.injury_date).toBe('2026-10-12T01:05');
    expect(f.expected_return_date).toBe('2026-10-20');
    const result = injuryCommandRequest('edit', f, d);
    expect(result.values.injury_date).toBe('2026-10-12T01:05:00+13:00');
    expect(result.values.expected_return_date).toBe('2026-10-20');
    expect(result.values.related_incident_id).toBeNull();
});

it('never turns a recovery or closure command into an inferred actual return, clearance or operational approval', () => {
    const d = record(true); const f = injuryFormValues(d); f.reason = 'Recorded review outcome';
    expect(injuryCommandRequest('mark_recovered', f, d).values).toEqual({ status: 'recovered', reason: f.reason });
    expect(injuryCommandRequest('close', f, d).values).toEqual({ status: 'closed', reason: f.reason });
    expect(injuryCommandRequest('assign', { ...f, coordinator_user_id: '17', next_review_date: '2026-10-20' }, d).values).toEqual({ coordinator_user_id: 17, next_review_date: '2026-10-20', reason: f.reason });
});

it('requires an explicit clock occurrence at a DST overlap and cannot submit a nonexistent NZ time', () => {
    const f = injuryFormValues(null); f.injury_date = '2026-04-05T02:30';
    expect(injuryCommandRequest('create', f, null).values.injury_date).toBe('');
    f.injury_offset = '+12:00';
    expect(injuryCommandRequest('create', f, null).values.injury_date).toBe('2026-04-05T02:30:00+12:00');
    f.injury_date = '2026-09-27T02:30';
    expect(injuryCommandRequest('create', f, null).values.injury_date).toBe('');
});

it('records a separate assessor and current document source without rebinding the injured worker', () => {
    const d = record(true); const f = { ...injuryFormValues(d), assessor_user_id: '17', assessor_type: 'gp', assessor_name: '', assessment_date: '2026-10-12', capacity_status: 'requires_review', hr_document_id: '23' };
    const result = injuryCommandRequest('add_capacity', f, d);
    expect(result.href).toBe('/health-safety/injuries/9/capacity-assessments');
    expect(result.values).toMatchObject({ assessor_user_id: 17, assessor_name: null, hr_document_id: 23, capacity_status: 'requires_review' });
    expect(result.values).not.toHaveProperty('user_id'); expect(result.values).not.toHaveProperty('worker_id');
});

it('preserves all recovery arrangements while limiting child writes and evidence to their exact command', () => {
    const d = record(true); const f = { ...injuryFormValues(d), plan_start_date: '2026-10-12', goals: ['Return gradually'], stages: [{ name: 'Stage one', start_date: '2026-10-12', end_date: '', hours_per_week: '12.5', duties_description: 'Supported duties' }], worker_agreed: true, worker_agreed_at: '2026-10-11', medical_clearance_provider: 'Reported provider', medical_clearance_notes: 'Reported source notes', workplace_modifications: 'Recorded arrangement' };
    const request = injuryCommandRequest('edit_rtw', f, d, { plan: 3 });
    expect(request.href).toBe('/health-safety/injuries/rtw-plans/3');
    expect(request.values).toMatchObject({ goals: ['Return gradually'], stages: [{ name: 'Stage one', start_date: '2026-10-12', end_date: null, hours_per_week: 12.5, duties_description: 'Supported duties' }], worker_agreed: true, worker_agreed_at: '2026-10-11', workplace_modifications: 'Recorded arrangement' });
    expect(request.values).not.toHaveProperty('approved'); expect(request.values).not.toHaveProperty('user_id');
    const file = new File(['source bytes'], 'evidence.pdf', { type: 'application/pdf' });
    expect(injuryCommandRequest('upload_evidence', { ...f, file, kind: 'medical_cert', notes: 'Supplied source' }, d).values).toEqual({ file, kind: 'medical_cert', notes: 'Supplied source', alt_text: null });
    expect(injuryCommandRequest('remove_evidence', { ...f, reason: 'Superseded source' }, d, { attachment: 7 })).toEqual({ method: 'delete', href: '/health-safety/injuries/9/attachments/7', values: { reason: 'Superseded source' } });
});
