import { describe, expect, it } from 'vitest';
import {
    popReaderFrame,
    pushReaderFrame,
    readReaderTrail,
    readerFrame,
    readerLocation,
    type ReaderFrame,
} from './_reader-trail';

const origin: ReaderFrame = {
    href: '/company-hub?view=resources&search=welcome',
    label: 'resources',
    focus: { key: 'resource-20' },
    scroll: { window: 400, regions: [240] },
};
describe('bounded Company Hub reading history', () => {
    it('preserves exact recognition destinations and only their matching fragment', () => {
        expect(
            readerLocation(
                '/company-hub?view=shoutouts&recognition=81#kudos-81',
            ),
        ).toBe('/company-hub?view=shoutouts&recognition=81#kudos-81');
        expect(
            readerLocation(
                '/company-hub?view=shoutouts&recognition=81#kudos-82',
            ),
        ).toBe('/company-hub?view=shoutouts&recognition=81');
        expect(
            readerLocation(
                '/company-hub?view=shoutouts&recognition=2147483648',
            ),
        ).toBeNull();
        expect(
            readerLocation('/company-hub?view=people&recognition=81#kudos-81'),
        ).toBe('/company-hub?view=people');
    });
    it('returns through guide, profile and original filtered browse location in order after serialisation', () => {
        let trail = pushReaderFrame(null, 11, origin);
        trail = pushReaderFrame(trail, 11, {
            ...origin,
            href: '/company-hub/read/welcome',
            label: 'guide',
            scroll: { window: 630, regions: [] },
        });
        trail = pushReaderFrame(trail, 11, {
            ...origin,
            href: '/company-hub/people/21',
            label: 'profile',
        });
        let back = popReaderFrame(JSON.parse(JSON.stringify(trail)), 11);
        expect(back.frame?.href).toBe('/company-hub/people/21');
        back = popReaderFrame(back.trail, 11);
        expect(back.frame).toMatchObject({
            href: '/company-hub/read/welcome',
            scroll: { window: 630 },
        });
        back = popReaderFrame(back.trail, 11);
        expect(back.frame).toEqual(origin);
        expect(popReaderFrame(back.trail, 11).frame).toBeNull();
    });
    it('clears all origins when signed out or reading under another account', () => {
        const trail = pushReaderFrame(null, 11, origin);
        expect(readReaderTrail(trail, 12)).toEqual({
            actor_id: 12,
            frames: [],
        });
        expect(readReaderTrail(trail, null)).toEqual({
            actor_id: null,
            frames: [],
        });
        expect(popReaderFrame(trail, 12).frame).toBeNull();
    });
    it('bounds long navigation to the most recent24 frames and replaces duplicate current origins', () => {
        let trail = pushReaderFrame(null, 11, origin);
        for (let id = 1; id <= 40; id++)
            trail = pushReaderFrame(trail, 11, {
                ...origin,
                href: `/company-hub/people/${id}`,
                label: 'profile',
            });
        expect(trail.frames).toHaveLength(24);
        expect(trail.frames[0].href).toBe('/company-hub/people/17');
        trail = pushReaderFrame(trail, 11, {
            ...origin,
            href: '/company-hub/people/40',
            label: 'profile',
            scroll: { window: 900, regions: [] },
        });
        expect(trail.frames).toHaveLength(24);
        expect(popReaderFrame(trail, 11).frame?.scroll.window).toBe(900);
    });
    it.each([
        'https://example.test/company-hub',
        '//example.test/company-hub',
        '/\\example.test/company-hub',
        '/company-hub/../settings',
        '/company-hub/manage/1/edit',
        '/company-hub?event_workspace=edit',
        '/company-hub?event_draft=2',
        '/company-hub?view=ideas&idea=2147483648',
        '/company-hub?view=ideas&idea=abc',
        '/company-hub/people/0',
        '/company-hub/events/2?date=2026-02-30',
        '/company-hub/%2e%2e/settings',
    ])('rejects a non-reader or unsafe return destination: %s', (href) => {
        expect(readerLocation(href)).toBeNull();
        expect(pushReaderFrame(null, 11, { ...origin, href }).frames).toEqual(
            [],
        );
    });
    it('keeps event dates and conversation anchors while stripping tokens and unrelated parameters', () => {
        expect(
            readerLocation(
                '/company-hub/events/12?date=2026-10-23&token=private',
            ),
        ).toBe('/company-hub/events/12?date=2026-10-23');
        expect(
            readerLocation(
                '/company-hub/read/guide?secret=private#hub-conversation',
            ),
        ).toBe('/company-hub/read/guide#hub-conversation');
        expect(
            readerLocation(
                '/company-hub?view=people&search=' + 'a'.repeat(250),
            ),
        ).toBe('/company-hub?view=people&search=' + 'a'.repeat(150));
    });
    it('preserves authorised-detail selectors and the publishing return location without retaining private parameters', () => {
        expect(
            readerLocation('/company-hub?view=ideas&idea=17&token=private'),
        ).toBe('/company-hub?view=ideas&idea=17');
        expect(
            readerLocation('/company-hub?view=publishing&token=private'),
        ).toBe('/company-hub?view=publishing');
        expect(
            readerLocation('/company-hub/read/learning#block-team_poll-2'),
        ).toBe('/company-hub/read/learning#block-team_poll-2');
        expect(readerLocation('/company-hub#block-team_poll-2')).toBe(
            '/company-hub#block-team_poll-2',
        );
        expect(
            readerLocation('/company-hub?view=people#block-team_poll-2'),
        ).toBe('/company-hub?view=people');
        expect(
            readerLocation('/company-hub/read/learning#block-unsafe%22'),
        ).toBe('/company-hub/read/learning');
        const trail = pushReaderFrame(null, 11, {
            ...origin,
            href: '/company-hub?view=publishing',
            label: 'publishing',
        });
        expect(popReaderFrame(trail, 11).frame).toMatchObject({
            href: '/company-hub?view=publishing',
            label: 'publishing',
        });
    });
    it('retains no protected content or untrusted selector and bounds invalid scroll offsets', () => {
        const frame = readerFrame({
            ...origin,
            title: 'Private title',
            body: 'Private details',
            focus: { key: '"]script', href: 'https://example.test' },
            scroll: {
                window: Infinity,
                regions: [-1, 30, ...Array(18).fill(2)],
            },
        });
        expect(frame).not.toHaveProperty('title');
        expect(frame).not.toHaveProperty('body');
        expect(frame).not.toHaveProperty('focus');
        expect(frame?.scroll.window).toBe(0);
        expect(frame?.scroll.regions).toHaveLength(12);
        expect(frame?.scroll.regions.slice(0, 2)).toEqual([0, 30]);
    });
    it('drops broken ancestors instead of skipping an invalid frame to an earlier page', () => {
        expect(
            readReaderTrail(
                {
                    actor_id: 11,
                    frames: [
                        origin,
                        { ...origin, href: '/settings' },
                        {
                            ...origin,
                            href: '/company-hub/people/21',
                            label: 'profile',
                        },
                    ],
                },
                11,
            ).frames,
        ).toHaveLength(1);
        expect(
            readReaderTrail(
                {
                    actor_id: 11,
                    frames: [
                        origin,
                        { ...origin, label: 'Private person name' },
                    ],
                },
                11,
            ).frames,
        ).toEqual([]);
    });
});
