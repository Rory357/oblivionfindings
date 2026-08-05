import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import { describe, expect, it, vi } from 'vitest';
import { buildControlRoomAlertRowActions } from './control-room-alert-row-actions';

function row(): AlertWorklistRow {
    return {
        id: 17,
        reference_number: 'CR-0017',
        summary: 'Welfare check overdue',
        source: { key: 'manual', label: 'Manual' },
        status: 'open',
        severity: 'high',
        priority: { level: 'high', rank: 1, reason: 'High severity' },
        triggered_at: '2026-07-18T01:00:00Z',
        next_deadline_at: null,
        sla: { status: 'on_track', next_deadline_at: null },
        site: null,
        person: null,
        assignee: null,
        queue: null,
        journey: {
            incident_reference: null,
            health_safety_reference: null,
            handover_status: null,
        },
        next_action: {
            label: 'Continue response',
            href: '/control-room/alerts/17',
        },
        actions: {
            can_claim: false,
            can_acknowledge: false,
            can_move_queue: false,
            can_escalate: false,
            can_create_incident: false,
            can_snooze: false,
            can_unsnooze: false,
            can_copy_reference: true,
            incident_href: null,
            health_safety_href: null,
        },
        href: '/control-room/alerts/17',
    };
}

const dependencies = () => ({
    openWorkspace: vi.fn(),
    post: vi.fn(),
    visit: vi.fn(),
    copy: vi.fn(),
});

describe('buildControlRoomAlertRowActions', () => {
    it('omits every server-denied action', () => {
        expect(
            buildControlRoomAlertRowActions(row(), dependencies()).map(
                (action) => action.key,
            ),
        ).toEqual(['open', 'copy-reference']);
    });

    it('uses the canonical workspace for lifecycle actions and routes safe direct actions', () => {
        const record = row();
        record.actions = {
            ...record.actions,
            can_claim: true,
            can_acknowledge: true,
            can_move_queue: true,
            can_escalate: true,
            can_create_incident: true,
            can_snooze: true,
            incident_href: '/incidents/4',
            health_safety_href: '/health-safety/events/8',
        };
        const effects = dependencies();
        const actions = buildControlRoomAlertRowActions(record, effects);

        actions.find((action) => action.key === 'claim')?.onSelect();
        actions.find((action) => action.key === 'acknowledge')?.onSelect();
        actions.find((action) => action.key === 'snooze')?.onSelect();
        actions.find((action) => action.key === 'open-incident')?.onSelect();

        expect(effects.post).toHaveBeenNthCalledWith(
            1,
            '/control-room/alerts/17/assign-to-me',
        );
        expect(effects.post).toHaveBeenNthCalledWith(
            2,
            '/control-room/alerts/17/acknowledge',
        );
        expect(effects.openWorkspace).toHaveBeenCalledWith(17);
        expect(effects.visit).toHaveBeenCalledWith('/incidents/4');
    });
});
