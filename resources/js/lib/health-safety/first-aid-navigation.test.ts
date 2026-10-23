import { expect, it } from 'vitest';
import {
    firstAidHref,
    firstAidLocation,
    firstAidReturn,
} from './first-aid-navigation';

it('returns to the exact dashboard launchers without carrying commands or nested navigation', () => {
    expect(firstAidReturn('/health-safety?report=1&return_to=/settings')).toBe(
        '/health-safety',
    );
    expect(firstAidReturn('/dashboard?action=archive')).toBe('/dashboard');
    expect(firstAidReturn('/dashboard/settings')).toBe(
        '/health-safety/first-aid',
    );
});

it('preserves bounded filtered register and exact selected follow-up context', () => {
    const origin =
        '/health-safety/first-aid?tab=followup&site_id=4&period=quarter&q=review&page=2';
    const href = firstAidHref(9, {
        section: 'followups',
        followup: 3,
        command: 'edit-followup',
        returnTo: origin,
    });
    expect(firstAidLocation(href)).toEqual({
        section: 'followups',
        followup: 3,
        attachment: null,
        command: 'edit-followup',
        returnTo: origin,
    });
});
it('keeps source and one supported origin while stripping commands and private upload ids', () => {
    const origin = '/sites/4?tab=first_aid';
    const source = `/health-safety/first-aid/9?section=evidence&action=remove-evidence&attachment=2&return_to=${encodeURIComponent(origin)}`;
    expect(firstAidReturn(source)).toBe(
        `/health-safety/first-aid/9?section=evidence&return_to=${encodeURIComponent(origin)}`,
    );
    expect(
        firstAidReturn('/operations/clients/3?tab=first_aid&edit=true'),
    ).toBe('/operations/clients/3?tab=first_aid');
});
it('rejects unsafe, overlong, cyclic or nested return destinations', () => {
    for (const value of [
        'https://outside.test/',
        '//outside.test/',
        '/health-safety/first-aid/../first-aid/4',
        '/health-safety/first-aid/0',
        '/health-safety/first-aid/9007199254740992',
        '/health-safety/first-aid?q=' + 'é'.repeat(800),
    ])
        expect(firstAidReturn(value)).toBe('/health-safety/first-aid');
    const cycle =
        '/health-safety/first-aid/9?return_to=%2Fhealth-safety%2Ffirst-aid%2F9';
    expect(firstAidHref(9, { returnTo: cycle })).toBe(
        '/health-safety/first-aid/9',
    );
    const deeper =
        '/incidents/3?return_to=' +
        encodeURIComponent('/health-safety/first-aid/4?return_to=%2Fmy-tasks');
    expect(firstAidReturn(deeper)).toBe('/incidents/3');
});
it('does not restore invalid query values or duplicate filters', () => {
    expect(
        firstAidReturn(
            '/health-safety/first-aid?tab=fake&page=0&q=a&q=b&site_id[]=4&action=archive',
        ),
    ).toBe('/health-safety/first-aid');
    expect(
        firstAidLocation(
            '/health-safety/first-aid/8?action=unknown&followup=-1',
        ).command,
    ).toBeNull();
});
