import { expect, it } from 'vitest';
import { exposureFormPayload, exposureInitialValues, exposureTimeChoices, exposureTimeInput, exposureTimeInstant } from './chemical-exposure-form';

it('preserves recorded seconds and the worker clock through a winter and summer round trip', () => {
    for (const [instant, local, offset] of [['2026-07-03T00:01:42Z', '2026-07-03T12:01:42', '+12:00'], ['2026-12-03T00:01:07Z', '2026-12-03T13:01:07', '+13:00']]) {
        expect(exposureTimeInput(instant)).toBe(local);
        expect(new Date(exposureTimeInstant(local, offset)!).toISOString()).toBe(new Date(instant).toISOString());
    }
});
it('requires an explicit occurrence for the repeated NZ clock and refuses skipped or impossible times', () => {
    const local = '2026-04-05T02:30:14';
    expect(exposureTimeChoices(local)).toEqual(['+13:00', '+12:00']);
    expect(exposureTimeInstant(local, '')).toBeNull();
    expect(exposureTimeInstant(local, '+11:00')).toBeNull();
    expect(new Date(exposureTimeInstant(local, '+12:00')!).getTime() - new Date(exposureTimeInstant(local, '+13:00')!).getTime()).toBe(3600000);
    for (const invalid of ['2026-09-27T02:30:00', '2026-02-30T12:00:00', '2026-04-05T25:00:00', '2026-04-05T02:30:60']) {
        expect(exposureTimeChoices(invalid)).toEqual([]); expect(exposureTimeInstant(invalid, '+12:00')).toBeNull();
    }
});
it('keeps a legacy association omitted until the user explicitly records an absence or selection', () => {
    const values = exposureInitialValues('correct');
    values.exposed_at = '2026-07-03T12:01:42'; values.medical_attention_sought = '0'; values.incident_reported = '0';
    expect(exposureFormPayload('correct', values).payload).not.toHaveProperty('related_incident_id');
    values.association_mode = 'absent'; expect(exposureFormPayload('correct', values).payload.related_incident_id).toBeNull();
    values.association_mode = 'selected'; expect(exposureFormPayload('correct', values).errors.related_incident_id).toBeTruthy();
    values.related_incident_id = '45'; expect(exposureFormPayload('correct', values).payload.related_incident_id).toBe('45');
});
it('never turns an unanswered medical or incident question into a negative recorded fact', () => {
    const values = exposureInitialValues('capture'); values.exposed_at = '2026-07-03T12:00';
    expect(exposureFormPayload('capture', values).errors).toEqual({});
    for (const key of ['medical_attention_sought', 'incident_reported', 'medical_treatment']) expect(exposureFormPayload('capture', values).payload).not.toHaveProperty(key);
    values.medical_attention_sought = 'unknown'; values.incident_reported = 'unknown'; values.medical_treatment = 'unknown';
    expect(exposureFormPayload('capture', values).payload).toMatchObject({ medical_attention_sought: null, incident_reported: null, medical_treatment: null });
    values.medical_attention_sought = '0'; values.incident_reported = '1';
    expect(exposureFormPayload('capture', values).payload).toMatchObject({ medical_attention_sought: false, incident_reported: true, exposed_at: '2026-07-03T12:00:00+12:00' });
});
it('preserves untouched correction facts and records explicit independent confirmations without deriving an attention answer', () => {
    const values = exposureInitialValues('correct'); values.exposed_at = '2026-07-03T12:00';
    values.medical_attention_sought = '1'; values.incident_reported = '0'; values.medical_treatment = 'first_aid';
    values.circumstances = 'Original circumstance'; const baseline = { ...values };
    values.symptoms = 'New symptom'; values.reason = 'Update symptoms only';
    expect(exposureFormPayload('correct', values, baseline)).toEqual({ payload: { symptoms: 'New symptom', reason: 'Update symptoms only' }, errors: {} });
    expect(exposureFormPayload('correct', values, baseline, ['medical_attention_sought']).payload.medical_attention_sought).toBe(true);
    values.medical_treatment = 'none';
    const treatment = exposureFormPayload('correct', values, baseline).payload;
    expect(treatment.medical_treatment).toBe('none'); expect(treatment).not.toHaveProperty('medical_attention_sought');
    values.medical_attention_sought = 'unknown';
    expect(exposureFormPayload('correct', values, baseline).payload.medical_attention_sought).toBeNull();
    values.incident_reported = 'invalid'; expect(exposureFormPayload('correct', values, baseline).errors.incident_reported).toBeTruthy();
});
it('keeps selected evidence bytes separate from removal and completion commands', () => {
    const values = exposureInitialValues('upload_evidence'); const file = new File(['original private bytes'], 'synthetic.txt');
    values.file = file; values.description = 'Retained description'; values.reason = 'Recorded removal reason'; values.completion_notes = 'Recorded completion';
    values.completed_at = '2026-07-03T12:00:14';
    expect(exposureFormPayload('upload_evidence', values).payload).toEqual({ file, description: 'Retained description' });
    expect(exposureFormPayload('remove_evidence', values).payload).toEqual({ reason: 'Recorded removal reason' });
    expect(exposureFormPayload('complete_followup', values).payload).toEqual({ completed_at: '2026-07-03T12:00:14+12:00', completion_notes: 'Recorded completion' });
});
it('transfers assignment without copying an instruction, due time or completion into the new command', () => {
    const values = exposureInitialValues('reassign_followup'); values.assigned_to = '8'; values.reason = 'New responsible coordinator';
    values.instruction = 'Retained original instruction'; values.due_at = '2026-07-03T12:00:00'; values.completion_notes = 'Unsubmitted completion';
    expect(exposureFormPayload('reassign_followup', values)).toEqual({ payload: { assigned_to: '8', reason: 'New responsible coordinator' }, errors: {} });
});
it('omits an untouched occurrence when correcting another fact but submits an explicitly changed time or repeated-clock occurrence', () => {
    const values = exposureInitialValues('correct'); values.exposed_at = exposureTimeInput('2026-07-03T00:01:42.123456Z'); values.exposed_at_offset = '+12:00';
    values.medical_attention_sought = '0'; values.incident_reported = '0'; const baseline = { ...values };
    values.symptoms = 'Updated reported symptoms'; values.reason = 'Correct the reported symptoms';
    expect(exposureFormPayload('correct', values, baseline).payload).not.toHaveProperty('exposed_at');
    values.exposed_at = '2026-07-03T12:01:43';
    expect(exposureFormPayload('correct', values, baseline).payload.exposed_at).toBe('2026-07-03T12:01:43+12:00');
    values.exposed_at = '2026-04-05T02:30:14'; values.exposed_at_offset = '+13:00'; const repeated = { ...values };
    values.exposed_at_offset = '+12:00';
    expect(exposureFormPayload('correct', values, repeated).payload.exposed_at).toBe('2026-04-05T02:30:14+12:00');
    values.exposed_at = '2026-09-27T02:30:00';
    expect(exposureFormPayload('correct', values, repeated).errors).toHaveProperty('exposed_at');
    expect(exposureFormPayload('capture', baseline, baseline).payload.exposed_at).toBe('2026-07-03T12:01:42+12:00');
});
