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
    select: vi.fn(),
    openAction: vi.fn(),
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
        ).toEqual(['preview', 'open', 'copy-reference']);
    });

    it('runs one-step actions straight away and opens a pop-up for the rest', () => {
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
        const run = (key: string) =>
            actions.find((action) => action.key === key)?.onSelect();

        run('preview');
        run('open');
        run('claim');
        run('acknowledge');
        run('snooze');
        run('escalate');
        run('incident');
        run('open-incident');

        expect(effects.select).toHaveBeenCalledWith(17);
        expect(effects.visit).toHaveBeenNthCalledWith(1, '/control-room/alerts/17');
        expect(effects.post).toHaveBeenNthCalledWith(
            1,
            '/control-room/alerts/17/assign-to-me',
        );
        expect(effects.post).toHaveBeenNthCalledWith(
            2,
            '/control-room/alerts/17/acknowledge',
        );
        expect(effects.openAction).toHaveBeenNthCalledWith(1, 17, 'snooze');
        expect(effects.openAction).toHaveBeenNthCalledWith(2, 17, 'escalate');
        expect(effects.openAction).toHaveBeenNthCalledWith(3, 17, 'report_incident');
        expect(effects.visit).toHaveBeenNthCalledWith(2, '/incidents/4');
        expect(actions.find((a) => a.key === 'incident')?.label).toBe('Report incident…');
    });
});
