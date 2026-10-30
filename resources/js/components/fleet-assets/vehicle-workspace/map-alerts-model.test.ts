import { describe, expect, it } from 'vitest';
import type {
    AlertDetail,
    AlertItem,
    AlertPlan,
    RecordedEvent,
} from './alerts-types';
import {
    ackText,
    availableActions,
    deadlineText,
    duplicatesText,
    isTerminal,
    minutesBetween,
    noticeFor,
    planFormFrom,
    planHeading,
    planParagraph,
    planStepError,
    recordedEventOption,
    routeSource,
    statusLabel,
    statusVariant,
    type PlanForm,
} from './map-alerts-model';

const NOW = Date.parse('2026-09-21T21:30:00Z');

const item = (patch: Partial<AlertItem> = {}): AlertItem => ({
    id: 11,
    type: 'response',
    reference: 'CRA-000011',
    kind: 'Overspeed threshold',
    kind_key: 'overspeed',
    severity: 'medium',
    priority: 'P3',
    status: 'open',
    escalation_level: 0,
    owner: null,
    observed_at: '2026-09-21T21:10:00Z',
    acknowledged_at: null,
    acknowledge_by: '2026-09-21T21:44:10Z',
    acknowledge_breached: false,
    duplicates: 0,
    decision: null,
    work: null,
    delivery: 'sent',
    attempts: 1,
    signal_id: 5,
    ...patch,
});

const detail = (patch: Partial<AlertDetail> = {}): AlertDetail => ({
    id: 11,
    reference: 'CRA-000011',
    kind: 'Overspeed threshold',
    kind_key: 'overspeed',
    severity: 'medium',
    priority: 'P3',
    status: 'open',
    escalation_level: 0,
    owner: null,
    observed_at: '2026-09-21T21:10:00Z',
    received_at: '2026-09-21T21:10:05Z',
    acknowledged_at: null,
    acknowledge_by: '2026-09-21T21:40:00Z',
    acknowledge_breached: false,
    decision: null,
    resolution: null,
    work: null,
    follow_ups: [],
    vehicle: 'Hiace · ABC123',
    device: null,
    location: null,
    location_withheld: 'access',
    driver: { label: 'Not shown', state: 'hidden' },
    source: { label: 'Vehicle profile', trip: null, sent_by: null },
    correlation: { duplicates: 0, signal_id: 5 },
    evidence: 'Peak 112 km/h for 18 seconds',
    review_source: null,
    history: [],
    version: 'a'.repeat(64),
    can: {
        acknowledge: true,
        triage: true,
        escalate: true,
        resolve: true,
        maintenance_create: true,
        maintenance_link: true,
        open_work: true,
        follow_up: true,
        review_source: true,
    },
    ...patch,
});

const plan = (patch: Partial<AlertPlan> = {}): AlertPlan => ({
    version: 2,
    status: 'draft',
    owner: { id: 4, name: 'Aroha Smith' },
    backup: { id: 5, name: 'Ben Jones' },
    speed_threshold_kph: 100,
    speed_tolerance_kph: 5,
    speed_duration_s: 15,
    speed_cooldown_s: 60,
    offline_minutes: 30,
    low_voltage_v: 11.5,
    low_voltage_minutes: 10,
    notes: 'Call the driver first.',
    saved_by: 'Aroha Smith',
    saved_at: '2026-09-20T20:00:00Z',
    ...patch,
});

const recorded = (patch: Partial<RecordedEvent> = {}): RecordedEvent => ({
    source_key: 'overspeed:42:overspeed-1',
    kind: 'overspeed',
    title: 'Overspeed threshold',
    at: '2026-09-21T20:15:00Z',
    detail: '',
    trip_id: 42,
    trip_reference: 'Trip #42',
    event_key: 'overspeed-1',
    event_id: null,
    peak_kph: 112.4,
    seconds: 18,
    route: null,
    ...patch,
});

describe('alert status', () => {
    it('keeps queued delivery distinct from a received or resolved response', () => {
        expect(
            statusLabel({ status: 'delivery_pending', escalation_level: 0 }),
        ).toBe('Waiting for Control Room');
        expect(statusVariant('delivery_pending')).toBe('info');
        expect(availableActions('delivery_pending')).toEqual([]);
        expect(ackText(item({ status: 'delivery_pending' }), NOW)).toBe(
            'Waiting for Control Room receipt',
        );
        expect(isTerminal('delivery_pending')).toBe(false);
    });
    it('names each Control Room status the way the design does', () => {
        expect(statusLabel({ status: 'open', escalation_level: 0 })).toBe(
            'New',
        );
        expect(statusLabel({ status: 'ack', escalation_level: 0 })).toBe(
            'Acknowledged',
        );
        expect(statusLabel({ status: 'triaging', escalation_level: 0 })).toBe(
            'Triaged',
        );
        expect(statusLabel({ status: 'confirmed', escalation_level: 0 })).toBe(
            'Confirmed',
        );
        expect(statusLabel({ status: 'open', escalation_level: 1 })).toBe(
            'Escalated',
        );
        expect(statusLabel({ status: 'resolved', escalation_level: 2 })).toBe(
            'Resolved',
        );
        expect(statusLabel({ status: 'closed', escalation_level: 0 })).toBe(
            'Resolved',
        );
        expect(statusLabel({ status: 'dismissed', escalation_level: 0 })).toBe(
            'Dismissed',
        );
        expect(
            statusLabel({ status: 'delivery_failed', escalation_level: 0 }),
        ).toBe('Delivery failed');
    });

    it('colours failures, open work and closed work', () => {
        expect(statusVariant('delivery_failed')).toBe('critical');
        expect(statusVariant('open')).toBe('warning');
        expect(statusVariant('triaging')).toBe('warning');
        expect(statusVariant('resolved')).toBe('success');
        expect(statusVariant('dismissed')).toBe('neutral');
    });

    it('offers the lifecycle actions for each status', () => {
        expect(availableActions('open')).toEqual([
            'acknowledge',
            'triage',
            'escalate',
        ]);
        expect(availableActions('ack')).toEqual([
            'triage',
            'escalate',
            'resolve',
        ]);
        expect(availableActions('confirmed')).toEqual([
            'triage',
            'escalate',
            'resolve',
        ]);
        expect(availableActions('delivery_failed')).toEqual(['retry']);
        expect(availableActions('resolved')).toEqual([]);
        expect(isTerminal('closed')).toBe(true);
        expect(isTerminal('ack')).toBe(false);
    });
});

describe('response clock', () => {
    it('measures whole minutes since a moment', () => {
        expect(minutesBetween(null, NOW)).toBeNull();
        expect(minutesBetween('not a date', NOW)).toBeNull();
        expect(minutesBetween('2026-09-21T21:00:30Z', NOW)).toBe(29);
        expect(minutesBetween('2026-09-21T22:00:00Z', NOW)).toBe(0);
    });

    it('counts down to the acknowledgement target', () => {
        expect(ackText(item(), NOW)).toBe('Acknowledge in 15 min');
        expect(
            ackText(item({ acknowledged_at: '2026-09-21T21:20:00Z' }), NOW),
        ).toBe('Acknowledged');
        expect(ackText(item({ acknowledge_by: null }), NOW)).toBe(
            'No acknowledgement target set',
        );
        expect(ackText(item({ status: 'resolved' }), NOW)).toBe(
            'Closed without acknowledgement',
        );
        expect(ackText(item({ status: 'delivery_failed' }), NOW)).toBe(
            'Delivery retry required',
        );
    });

    it('reports an overdue acknowledgement and its escalation', () => {
        expect(
            ackText(item({ acknowledge_by: '2026-09-21T21:00:00Z' }), NOW),
        ).toBe('Acknowledgement overdue');
        expect(ackText(item({ acknowledge_breached: true }), NOW)).toBe(
            'Acknowledgement overdue',
        );
        expect(
            ackText(
                item({ acknowledge_breached: true, escalation_level: 2 }),
                NOW,
            ),
        ).toBe('Acknowledgement overdue · escalated to level 2');
    });

    it('describes the detail deadline', () => {
        expect(deadlineText(detail(), NOW)).toMatch(/^10 min remaining · by /);
        expect(
            deadlineText(
                detail({ acknowledged_at: '2026-09-21T21:20:00Z' }),
                NOW,
            ),
        ).toMatch(/^Acknowledged · /);
        expect(deadlineText(detail({ status: 'dismissed' }), NOW)).toBe(
            'Closed',
        );
        expect(deadlineText(detail({ acknowledge_by: null }), NOW)).toBe(
            'No acknowledgement target set in Control Room',
        );
        expect(
            deadlineText(
                detail({ acknowledge_breached: true, escalation_level: 1 }),
                NOW,
            ),
        ).toMatch(/^Overdue since .+ · escalated to level 1$/);
    });
});

describe('alert wording', () => {
    it('counts correlated duplicates', () => {
        expect(duplicatesText(0)).toBe('Original signal retained');
        expect(duplicatesText(1)).toBe('1 duplicate report correlated');
        expect(duplicatesText(3)).toBe('3 duplicate reports correlated');
    });

    it('never presents a collision signal as a confirmed accident', () => {
        expect(noticeFor('collision').title).toBe(
            'Potential collision, not a confirmed accident',
        );
        expect(noticeFor('overspeed').title).toBe(
            'Assess context before concluding',
        );
    });

    it('names the tracker source only when its model is known', () => {
        expect(routeSource({ model: 'GV500CG' })).toBe('GV500CG event');
        expect(routeSource({ model: null })).toBe('Tracker event');
    });
});

describe('response plan', () => {
    it('summarises the owners and thresholds', () => {
        expect(planHeading(null)).toBe('No response plan drafted');
        expect(planHeading(plan())).toBe('Aroha Smith → Ben Jones');
        expect(planHeading(plan({ backup: null }))).toBe(
            'Aroha Smith → Backup not set',
        );
        const paragraph = planParagraph(plan({ low_voltage_v: 12 }));
        expect(paragraph).toContain(
            'above 100 km/h plus 5 km/h tolerance for 15 seconds',
        );
        expect(paragraph).toContain('60 seconds below the trigger');
        expect(paragraph).toContain('below 12.0 V for 10 minutes');
        expect(paragraph).toContain('Tracker overdue: 30 minutes');
        expect(planParagraph(null)).toContain('not a live device setting');
    });

    it('starts a first plan blank rather than with invented defaults', () => {
        expect(planFormFrom(null)).toEqual({
            owner_user_id: null,
            backup_user_id: null,
            speed_threshold_kph: '',
            speed_tolerance_kph: '',
            speed_duration_s: '',
            speed_cooldown_s: '',
            offline_minutes: '',
            low_voltage_v: '',
            low_voltage_minutes: '',
            notes: '',
        });
        expect(planFormFrom(plan())).toMatchObject({
            owner_user_id: 4,
            backup_user_id: 5,
            speed_threshold_kph: '100',
            low_voltage_v: '11.5',
            notes: 'Call the driver first.',
        });
    });

    it('checks each wizard step like the server does', () => {
        const valid: PlanForm = planFormFrom(plan());
        expect([0, 1, 2, 3].map((step) => planStepError(valid, step))).toEqual([
            '',
            '',
            '',
            '',
        ]);
        expect(planStepError({ ...valid, backup_user_id: null }, 0)).toBe(
            'Choose the primary and backup response owners.',
        );
        expect(planStepError({ ...valid, backup_user_id: 4 }, 0)).toBe(
            'Choose a different person as the backup owner.',
        );
        expect(
            planStepError({ ...valid, speed_threshold_kph: '151' }, 1),
        ).not.toBe('');
        expect(
            planStepError({ ...valid, speed_tolerance_kph: '21' }, 1),
        ).not.toBe('');
        expect(
            planStepError({ ...valid, speed_duration_s: '7.5' }, 1),
        ).not.toBe('');
        expect(planStepError({ ...valid, speed_cooldown_s: '0' }, 1)).not.toBe(
            '',
        );
        expect(planStepError({ ...valid, low_voltage_v: '7.9' }, 2)).not.toBe(
            '',
        );
        expect(planStepError({ ...valid, low_voltage_v: '32.1' }, 2)).not.toBe(
            '',
        );
        expect(planStepError({ ...valid, offline_minutes: '' }, 2)).not.toBe(
            '',
        );
        expect(planStepError({ ...valid, notes: '  ' }, 2)).toBe(
            'Record the response, cooldown and escalation instructions.',
        );
    });
});

describe('recorded events', () => {
    const when = () => 'Mon 8:15 am';

    it('describes an overspeed episode without claiming a road limit', () => {
        expect(recordedEventOption(recorded(), when)).toEqual({
            value: 'overspeed:42:overspeed-1',
            label: 'Overspeed · 112 km/h peak · Mon 8:15 am',
            description:
                'Trip #42 · 18 sec at or above the fleet threshold · road limit not checked',
        });
        expect(
            recordedEventOption(
                recorded({ peak_kph: null, seconds: null }),
                when,
            ).label,
        ).toBe('Overspeed · Peak not recorded · Mon 8:15 am');
    });

    it('shows where an already-sent event got to', () => {
        expect(
            recordedEventOption(
                recorded({
                    route: {
                        signal_id: 5,
                        delivery: 'sent',
                        response: {
                            id: 11,
                            reference: 'CRA-000011',
                            status: 'open',
                        },
                    },
                }),
                when,
            ).description,
        ).toMatch(/Sent to Control Room · CRA-000011$/);
    });

    it('describes a power event from its own detail', () => {
        expect(
            recordedEventOption(
                recorded({
                    source_key: 'telemetry:77',
                    kind: 'power_disconnected',
                    title: 'Main power disconnected',
                    detail: 'Reported by the tracker',
                    trip_id: null,
                    trip_reference: null,
                    event_key: null,
                    event_id: 77,
                    peak_kph: null,
                    seconds: null,
                }),
                when,
            ),
        ).toEqual({
            value: 'telemetry:77',
            label: 'Main power disconnected · Mon 8:15 am',
            description: 'Reported by the tracker',
        });
    });
});
