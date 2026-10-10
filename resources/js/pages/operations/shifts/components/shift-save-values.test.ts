import { expect, it } from 'vitest';
import { shiftSaveProjection, type ShiftSaveFields } from './shift-save-values';
const fields: ShiftSaveFields = {
    client_id: '10',
    service_context_id: '',
    user_id: '7',
    starts_at: '2026-10-05T09:17:12.900+13:00',
    ends_at: '2026-10-05T17:47:12.900+13:00',
    location: '  Community  venue  ',
    notes: '  Notes  ',
    status: 'scheduled',
    shift_type: 'standard',
    is_sleepover: false,
    is_on_call: false,
    is_lone_worker: true,
    expected_break_minutes: '0',
    coverage_roles: ['driver'],
    required_licence_class: '',
    required_licence_endorsements: [],
    tasks: [
        {
            id: 31,
            label: '  Check notes  ',
            scheduled_time: '10:17:00',
            can_edit: true,
        },
        {
            id: 32,
            label: 'Source-owned task',
            scheduled_time: '11:00',
            can_edit: false,
        },
        { label: 'New task', scheduled_time: null },
        { label: '  ', scheduled_time: null },
    ],
};
it('normalizes the submitted snapshot without mutating the form or carrying protected tasks', () => {
    const original = structuredClone(fields);
    expect(shiftSaveProjection(fields)).toEqual({
        client_id: 10,
        service_context_id: null,
        user_id: 7,
        starts_at: '2026-10-04T20:17:12.000Z',
        ends_at: '2026-10-05T04:47:12.000Z',
        location: 'Community  venue',
        notes: 'Notes',
        status: 'scheduled',
        shift_type: 'standard',
        is_sleepover: false,
        is_on_call: false,
        is_lone_worker: true,
        expected_break_minutes: 0,
        coverage_roles: ['driver'],
        required_licence_class: null,
        required_licence_endorsements: [],
        tasks: [
            { id: 31, label: 'Check notes', scheduled_time: '10:17' },
            { id: null, label: 'New task', scheduled_time: null },
        ],
    });
    expect(fields).toEqual(original);
});
it('distinguishes blank break and omitted tasks from zero and an explicit empty checklist', () => {
    expect(
        shiftSaveProjection({
            ...fields,
            expected_break_minutes: '',
            tasks: null,
        }),
    ).toMatchObject({ expected_break_minutes: null, tasks: null });
    expect(shiftSaveProjection({ ...fields, tasks: [] })).toMatchObject({
        expected_break_minutes: 0,
        tasks: [],
    });
});
it('retains explicit assigned drafts, normalizes unassigned scheduling and derives type flags', () => {
    expect(shiftSaveProjection({ ...fields, status: 'draft' }).status).toBe(
        'draft',
    );
    expect(shiftSaveProjection({ ...fields, user_id: '' }).status).toBe(
        'draft',
    );
    expect(
        shiftSaveProjection({ ...fields, shift_type: 'sleepover' })
            .is_sleepover,
    ).toBe(true);
    expect(
        shiftSaveProjection({ ...fields, shift_type: 'on_call' }).is_on_call,
    ).toBe(true);
});
it('rejects ambiguous date input and malformed record, break and task controls before transport', () => {
    for (const change of [
        { starts_at: '2026-10-05T09:17' },
        { client_id: 'bad' },
        { expected_break_minutes: '2.5' },
        { tasks: [{ label: 'Task', scheduled_time: '25:00' }] },
    ])
        expect(() => shiftSaveProjection({ ...fields, ...change })).toThrow();
});
