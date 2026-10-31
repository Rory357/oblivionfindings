import { incidentRegisterReturn } from '@/lib/incidents/navigation';
import { expect, it } from 'vitest';
import {
    LONE_WORKER_REGISTER as REGISTER,
    loneWorkerHref,
    loneWorkerReturn,
} from './lone-worker-navigation';
import { safetyReviewReturn } from './navigation';

it('restores the selected source section and filtered register from connected work', () => {
    const register =
        REGISTER + '?tab=alerts&site_id=3&status=triaging&period=week&page=2';
    const source = loneWorkerHref(14, {
        section: 'response',
        returnTo: register,
    });
    expect(incidentRegisterReturn(source)).toBe(source);
    expect(safetyReviewReturn(source)).toBe(source);
    expect(loneWorkerReturn(source)).toBe(source);
    expect(
        new URL(source, 'https://test.invalid').searchParams.get('return_to'),
    ).toBe(register);
});
it('preserves approved cross-module origins without keeping commands', () => {
    for (const value of [
        '/my-day',
        '/tasks?assigned_to=me',
        '/operations/shifts/17',
        '/security-devices/tracking?tab=personal-safety&attention=stale',
        '/operations/people-locations/map?site=3&selected=worker-12',
    ]) {
        expect(loneWorkerReturn(value)).not.toBe(REGISTER);
    }
    const source = REGISTER + '/sessions/14?section=location&action=locate';
    expect(loneWorkerReturn(source)).toBe(
        REGISTER + '/sessions/14?section=location',
    );
    expect(
        loneWorkerReturn(
            '/operations/shifts/17?action=end&section=unsupported',
        ),
    ).toBe('/operations/shifts/17');
});
it('bounds input and removes nested cycles or unsafe navigation', () => {
    for (const value of [
        'https://evil.test/my-day',
        '//evil.test/my-day',
        '/my-day#secret',
        '/my-day\\anything',
        '/health-safety/lone-workers/../events',
        REGISTER + '?q=' + 'ā'.repeat(751),
        '/my-day\n',
    ])
        expect(loneWorkerReturn(value)).toBe(REGISTER);
    const same = REGISTER + '/sessions/14';
    const viaIncident = '/incidents/3?return_to=' + encodeURIComponent(same);
    expect(loneWorkerHref(14, { returnTo: viaIncident })).toBe(same);
    expect(loneWorkerHref(14, { returnTo: same + '?section=response' })).toBe(
        same,
    );
    const tooDeep =
        '/incidents/3?return_to=' +
        encodeURIComponent(same + '?return_to=%2Fmy-day');
    expect(loneWorkerReturn(tooDeep)).toBe('/incidents/3');
});
it('keeps one valid value per filter and rejects command parameters', () => {
    expect(
        loneWorkerReturn(
            REGISTER +
                '?tab=alerts&status=ack&q=%C4%81&site_id=3&site_id=4&start=1&action=panic',
        ),
    ).toBe(REGISTER + '?tab=alerts&q=%C4%81&status=ack');
    expect(loneWorkerHref(0)).toBe(REGISTER);
    expect(loneWorkerHref(Number.MAX_SAFE_INTEGER + 1)).toBe(REGISTER);
});
