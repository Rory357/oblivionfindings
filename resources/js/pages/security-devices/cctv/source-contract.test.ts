import { describe, expect, it } from 'vitest';
import {
    sourceAddress,
    sourceHref,
    sourceSetupReply,
    sourceWriteReply,
} from './_source-contract';
import { sourceFixture } from './source-test-fixtures';
describe('CCTV source identity and picker boundaries', () => {
    it('accepts the current actor and camera only, without inventing stream readiness', () => {
        const setup = sourceFixture();
        expect(sourceSetupReply(setup, 7, 42).source?.stream_state).toBe(
            'unavailable',
        );
        expect(() =>
            sourceSetupReply({ ...setup, actor_id: 8 }, 7, 42),
        ).toThrow();
        expect(() =>
            sourceSetupReply({ ...setup, revision: 3 }, 7, 42),
        ).toThrow();
        expect(() =>
            sourceWriteReply(
                { actor_id: 8, camera_id: 42, source: setup.source },
                7,
                42,
            ),
        ).toThrow();
        expect(() =>
            sourceSetupReply(
                {
                    ...setup,
                    source: {
                        ...setup.source,
                        stream_state: 'verified',
                        stream_verified_at: null,
                    },
                },
                7,
                42,
            ),
        ).toThrow();
    });
    it('binds paging destinations to camera, choice, query and collector', () => {
        const setup = sourceFixture();
        setup.choice = 'scopes';
        setup.q = 'camera';
        setup.collector_id = 51;
        const valid =
            '/security-devices/devices/42/live/source?choice=scopes&q=camera&collector_id=51&cursor=cGFnZQ';
        setup.choices.scopes.next_href = valid;
        expect(sourceSetupReply(setup, 7, 42)).toEqual(setup);
        for (const href of [
            valid.replace('42/', '43/'),
            valid.replace('q=camera', 'q=other'),
            valid.replace('collector_id=51', 'collector_id=52'),
            valid + '&choice=recorders',
            'https://other.example' + valid,
        ])
            expect(() =>
                sourceSetupReply(
                    {
                        ...setup,
                        choices: {
                            ...setup.choices,
                            scopes: {
                                ...setup.choices.scopes,
                                next_href: href,
                            },
                        },
                    },
                    7,
                    42,
                ),
            ).toThrow();
    });
    it('allows only exact source routes and literal reviewed addresses', () => {
        for (const href of [
            '/security-devices/devices/42/live/source?choice=recorders&next=https://other',
            '/security-devices/devices/42/live/source?choice=recorders&collector_id=51',
            '/security-devices/devices/42/live/source/enable-stream?source=other',
        ])
            expect(sourceHref(href, 42)).toBe(false);
        for (const ip of ['10.20.30.5', '2001:db8::1'])
            expect(sourceAddress(ip)).toBe(true);
        for (const ip of [
            '10.20.030.5',
            '999.20.30.5',
            'recorder.example.test',
            'http://10.20.30.5',
            '[::1]',
            '2001:DB8::1',
            '::ffff:7f00:1',
        ])
            expect(sourceAddress(ip)).toBe(false);
    });
});
