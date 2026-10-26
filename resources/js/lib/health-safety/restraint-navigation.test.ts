import { expect, it } from 'vitest';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
} from './restraint-navigation';

it.each([
    [
        '/operations/clients/12?tab=observations&action=approve&receipt_key=private',
        '/operations/clients/12?tab=observations',
    ],
    ['/respite/stays/21?kind=plan&command_id=private', '/respite/stays/21'],
    ['/respite?tab=stays&command_id=private', '/respite?tab=stays'],
    [
        '/health-clinical/behaviour?client_id=12&site_id=3&date_from=2026-10-01&date_to=2026-10-12&view=restraint&action=review',
        '/health-clinical/behaviour?client_id=12&site_id=3&date_from=2026-10-01&date_to=2026-10-12&view=restraint',
    ],
])('returns to the bounded source context for %s', (origin, expected) => {
    const href = restraintHref('events', 7, {
        section: 'monitoring',
        command: 'correct-observation',
        childKey: 'observation',
        child: 4,
        returnTo: origin,
    });
    expect(restraintLocation(href, 'events')).toMatchObject({
        section: 'monitoring',
        command: 'correct-observation',
        observation: 4,
        returnTo: expected,
    });
    expect(restraintReturn(origin)).toBe(expected);
});

it('keeps both register pages and the selected version without restoring a legacy modal or a command', () => {
    const origin =
        '/health-safety/restraints?lens=plans&tab=review_due&client_id=12&q=recorded+plan&events_page=2&plans_page=4&plan=55&approve=1';
    expect(restraintReturn(origin)).toBe(
        '/health-safety/restraints?lens=plans&tab=review_due&client_id=12&q=recorded+plan&events_page=2&plans_page=4',
    );
    const href = restraintHref('plans', 8, {
        workspace: 'approval',
        section: 'versions',
        childKey: 'version',
        child: 3,
        returnTo: origin,
    });
    expect(restraintLocation(href, 'plans')).toMatchObject({
        section: 'versions',
        version: 3,
    });
    expect(restraintReturn(href + '&action=decision&receipt_key=private')).toBe(
        href,
    );
});

it('keeps one supported source leaf and rejects cyclic or deeper return contexts', () => {
    const source =
        '/health-safety/restraints/events/7?section=connections&return_to=' +
        encodeURIComponent('/respite/stays/21');
    expect(restraintReturn(source)).toBe(source);
    const cycle =
        '/health-safety/restraints/events/7?return_to=' +
        encodeURIComponent('/health-safety/restraints/plans/8');
    expect(restraintHref('plans', 8, { returnTo: cycle })).toBe(
        '/health-safety/restraints/plans/8',
    );
    const nested =
        '/health-safety/restraints/events/7?return_to=' +
        encodeURIComponent(
            '/health-safety/restraints/events/8?return_to=/tasks',
        );
    expect(restraintReturn(nested)).toBe('/health-safety/restraints/events/7');
});

it.each([
    'https://outside.example/tasks',
    '//outside.example/tasks',
    '/health-safety/restraints/events/../events/7',
    '/health-safety/restraints/events/0',
    '/health-safety/restraints/events/9007199254740992',
    '/health-safety/restraints/events/7/approval',
    '/health-safety/restraints/events/7/attachments/2/download',
    '/health-safety/restraints/plans/8/activate',
    '/respite/stays/21/extend',
    '/respite/stays/21/restraints/report',
    '/respite/stays/9007199254740992',
    '/operations/clients/9007199254740992?tab=observations',
    '/health-clinical/behaviour/approve',
    '/tasks#private',
    '/tasks\n',
    '/health-safety/restraints?q=' + 'é'.repeat(800),
])(
    'does not create a source return to an unsafe, command or out-of-range path: %s',
    (value) => {
        expect(restraintReturn(value)).toBe(RESTRAINT_REGISTER);
    },
);

it('strips duplicate, invalid or protected filter values and command child ids from returns', () => {
    expect(
        restraintReturn(
            '/health-safety/restraints?client_id=0&site_id=9007199254740992&from=2026-02-31&q=a&q=b&action=archive',
        ),
    ).toBe(RESTRAINT_REGISTER);
    expect(
        restraintReturn(
            '/health-safety/restraints/events/7?section=approval&attachment=2&observation=3&action=remove-evidence',
        ),
    ).toBe('/health-safety/restraints/events/7');
    expect(
        restraintLocation(
            '/health-safety/restraints/plans/8?action=correct-observation&version=-1',
            'plans',
        ),
    ).toMatchObject({ command: null, version: null });
    expect(
        restraintReturn(
            '/health-clinical/behaviour?client_id=0&date_from=2026-02-31&view=restraint',
        ),
    ).toBe('/health-clinical/behaviour?view=restraint');
});
