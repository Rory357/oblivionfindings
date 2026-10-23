import { describe, expect, it } from 'vitest';
import {
    controlRoomActiveView,
    controlRoomDestinationForPath,
    controlRoomDestinations,
    controlRoomHubForUrl,
    controlRoomPrimaryLinkActive,
} from './control-room-navigation';

const operator = { controlRoom: { viewAny: true, alertsManage: true } };
const reader = { controlRoom: { viewAny: true } };
const alertsOnly = { controlRoom: { alertsView: true } };

describe('Control Room navigation', () => {
    it('shows the six approved destinations in order', () => {
        expect(controlRoomDestinations(operator).map((d) => d.title)).toEqual([
            'Live response',
            'Monitoring',
            'Communications',
            'Duty handover',
            'Reports',
            'Settings',
        ]);
    });

    it('keeps the Live response views, Safety handovers included', () => {
        const live = controlRoomDestinations(operator)[0];
        expect(live.views.map((v) => v.label)).toEqual([
            'Active',
            'My work',
            'Escalated',
            'Waiting',
            'Safety handovers',
            'History',
        ]);
        expect(live.href).toBe('/control-room');
    });

    it('lets procedure readers read procedures without administration rights', () => {
        const settings = controlRoomDestinations(reader).find((d) => d.key === 'settings')!;
        expect(settings.views.map((v) => v.label)).toEqual(['Procedures', 'Response targets']);
        expect(settings.href).toBe('/control-room/playbooks');
    });

    it('gives an alerts-only person the alert list, never the desk', () => {
        const destinations = controlRoomDestinations(alertsOnly);
        expect(destinations.map((d) => d.title)).toEqual(['Live response']);
        expect(destinations[0].href).toBe('/control-room/alerts');
        expect(destinations[0].views.map((v) => v.label)).toEqual([
            'Active',
            'Waiting',
            'History',
        ]);
    });

    it('files every old URL under exactly one destination', () => {
        expect(controlRoomDestinationForPath('/control-room')).toBe('response');
        expect(controlRoomDestinationForPath('/control-room/alerts/12')).toBe('response');
        expect(controlRoomDestinationForPath('/control-room/devices/4')).toBe('monitoring');
        expect(controlRoomDestinationForPath('/control-room/messaging')).toBe('communications');
        expect(controlRoomDestinationForPath('/control-room/shifts/3/handover')).toBe('duty');
        expect(controlRoomDestinationForPath('/control-room/sla/breaches')).toBe('reports');
        expect(controlRoomDestinationForPath('/control-room/sla-breaches')).toBe('reports');
        expect(controlRoomDestinationForPath('/control-room/sla')).toBe('settings');
        expect(controlRoomDestinationForPath('/control-room/playbooks/9')).toBe('settings');
        expect(controlRoomDestinationForPath('/fleet')).toBeUndefined();
    });

    it('keeps one sidebar entry lit across its views', () => {
        expect(controlRoomPrimaryLinkActive('/control-room/escalations', '/control-room')).toBe(true);
        expect(controlRoomPrimaryLinkActive('/control-room/map', '/control-room')).toBe(false);
        expect(controlRoomPrimaryLinkActive('/control-room/devices/2', '/control-room/map')).toBe(true);
        expect(controlRoomPrimaryLinkActive('/fleet', '/fleet')).toBeUndefined();
    });

    it('picks the tab by path and query, and lights the parent for a record', () => {
        const keys = controlRoomDestinations(operator)[0].views.map((v) => v.key);
        expect(controlRoomActiveView('/control-room/alerts?lens=history&page=2', keys)).toBe(
            '/control-room/alerts?lens=history',
        );
        expect(controlRoomActiveView('/control-room/alerts?lens=snoozed', keys)).toBe(
            '/control-room/alerts?lens=snoozed',
        );
        expect(controlRoomActiveView('/control-room/alerts', keys)).toBe('/control-room');
        expect(controlRoomActiveView('/control-room/alerts/12', keys)).toBe('/control-room');
        expect(controlRoomActiveView('/control-room?severity=critical', keys)).toBe('/control-room');
    });

    it('describes the hub for the header rail', () => {
        const hub = controlRoomHubForUrl('/control-room/stats', operator)!;
        expect(hub.destination.title).toBe('Reports');
        expect(hub.activeView).toBe('/control-room/stats');
        expect(controlRoomHubForUrl('/dashboard', operator)).toBeNull();
    });
});
