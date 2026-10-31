import { describe, expect, it } from 'vitest';
import {
    DRILL_REGISTER,
    drillHref,
    drillLocation,
    drillReturn,
} from './drill-navigation';

const parsed = (value: string) => new URL(value, 'https://example.test');
describe('drill workspace navigation', () => {
    it('restores a Control Room alert and its bounded queue filters without restoring commands', () => {
        const queue =
            '/control-room/alerts?lens=my_queue&assigned_to=me&severity=high&date_from=2026-10-08&date_to=2026-02-30&sort=priority&dir=desc&action=close';
        const alert =
            '/control-room/alerts/9?section=tasks&action=resolve&return_to=' +
            encodeURIComponent(queue);
        const restored = parsed(drillReturn(alert));
        expect(restored.pathname).toBe('/control-room/alerts/9');
        expect(restored.searchParams.get('section')).toBe('tasks');
        expect(restored.searchParams.has('action')).toBe(false);
        const origin = parsed(restored.searchParams.get('return_to')!);
        expect(origin.searchParams.get('lens')).toBe('my_queue');
        expect(origin.searchParams.get('assigned_to')).toBe('me');
        expect(origin.searchParams.get('date_from')).toBe('2026-10-08');
        expect(origin.searchParams.has('date_to')).toBe(false);
        expect(origin.searchParams.has('action')).toBe(false);
    });
    it('retains a selected finding and the filtered register through a corrective-action round trip', () => {
        const register =
            '/health-safety/drills?tab=findings&site_id=2&q=Evacuation&period=all';
        const drill = drillHref(8, {
            section: 'findings',
            finding: 19,
            returnTo: register,
        });
        const action =
            '/health-safety/corrective-actions/12?section=evidence&return_to=' +
            encodeURIComponent(drill);
        // Only one record plus one leaf is retained; a nested record chain is not accepted.
        expect(parsed(drillReturn(action)).searchParams.has('return_to')).toBe(
            false,
        );
        const returned = drillLocation(drillReturn(drill));
        expect(returned.section).toBe('findings');
        expect(returned.finding).toBe(19);
        expect(parsed(returned.returnTo).searchParams.get('q')).toBe(
            'Evacuation',
        );
        expect(parsed(returned.returnTo).searchParams.get('site_id')).toBe('2');
    });
    it('keeps completed Tasks filters but strips commands and unsafe targets', () => {
        expect(drillReturn('/calendar?action=create')).toBe('/calendar');
        expect(drillReturn('/sites/2/calendar?action=delete')).toBe(
            '/sites/2/calendar',
        );
        const href = drillHref(8, {
            section: 'findings',
            finding: 19,
            returnTo:
                '/tasks?assigned=me&done=1&sources=emergency_drill_finding&action=complete',
        });
        const back = parsed(drillLocation(href).returnTo);
        expect(back.pathname).toBe('/tasks');
        expect(back.searchParams.get('done')).toBe('1');
        expect(back.searchParams.has('action')).toBe(false);
        expect(
            drillReturn(
                '/health-safety/drills/8?action=cancel&finding=19&participant=5',
            ),
        ).toBe('/health-safety/drills/8?finding=19');
        for (const unsafe of [
            '//outside.test',
            '/\\outside.test',
            'https://outside.test',
            '/health-safety/drills/../users/2',
            '/health-safety/drills#cancel',
            '/users/2',
        ])
            expect(drillReturn(unsafe)).toBe(DRILL_REGISTER);
    });
    it('preserves Site drill context and accepts legacy command spelling only as a command', () => {
        expect(drillReturn('/sites/2?tab=drills&action=delete')).toBe(
            '/sites/2?tab=drills',
        );
        const location = drillLocation(
            '/health-safety/drills/8?section=participants&action=add_participant&participant=7',
        );
        expect(location).toMatchObject({
            section: 'participants',
            command: 'add-participant',
            participant: 7,
        });
        expect(
            drillLocation(
                '/health-safety/drills/8?finding=9007199254740993&participant=-2',
            ).finding,
        ).toBeNull();
    });
    it('prevents returning to itself through a source record and never restores a command', () => {
        const source =
            '/health-safety/events/4?section=actions&return_to=' +
            encodeURIComponent('/health-safety/drills/8?section=findings');
        expect(
            parsed(drillHref(8, { returnTo: source })).searchParams.has(
                'return_to',
            ),
        ).toBe(false);
        expect(
            drillLocation(
                drillReturn(
                    '/health-safety/drills/8?section=findings&finding=19&action=resolve_finding',
                ),
            ).command,
        ).toBeNull();
    });
});
