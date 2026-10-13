import { describe, expect, it } from 'vitest';
import {
    hikActionReply,
    hikBase,
    hikDiscoveryReply,
    hikMappingReply,
    hikPath,
    hikReviewReply,
    hikSetupReply,
} from './_hikvision-contract';
import {
    hikChannel,
    hikDiscoveryFixture,
    hikFixture,
    hikMappingFixture,
    hikReviewFixture,
    hikSavedFixture,
    hikUuid,
} from './hikvision-test-fixtures';

describe('Hikvision manager contracts', () => {
    it('accepts separate mapping and enabled readiness without claiming verified video', () => {
        const initial = hikFixture();
        expect(hikSetupReply(initial, 7, 42)).toEqual(initial);
        const enabled = hikSavedFixture(true);
        expect(hikSetupReply(enabled, 7, 42).source?.stream_state).toBe(
            'not_requested',
        );
        expect(
            hikActionReply(
                {
                    actor_id: 7,
                    camera_id: 42,
                    source: {
                        id: 100,
                        revision: 1,
                        enabled: true,
                        stream_state: 'not_requested',
                        stream_verified_at: null,
                    },
                },
                enabled,
                enabled.source!,
            ),
        ).toMatchObject({ enabled: true, stream_state: 'not_requested' });
    });
    it.each(['actor_id', 'camera_id', 'revision', 'readiness', 'choices'])(
        'rejects changed %s',
        (key) => {
            const setup = hikFixture();
            const changed = {
                ...setup,
                [key]:
                    key === 'readiness'
                        ? { ...setup.readiness, can_view: true }
                        : key === 'choices'
                          ? { ...setup.choices, secret: 'private' }
                          : key === 'revision'
                            ? -1
                            : 999,
            };
            expect(() => hikSetupReply(changed, 7, 42)).toThrow();
        },
    );
    it('rejects forged scope ownership, private fields, duplicate pages and cross-camera page links', () => {
        const setup = hikFixture();
        setup.choice = 'scopes';
        setup.collector_id = 999;
        expect(() => hikSetupReply(setup, 7, 42)).toThrow();
        const extra = { ...hikFixture(), private_material: 'secret' };
        expect(() => hikSetupReply(extra, 7, 42)).toThrow();
        const duplicate = hikFixture();
        duplicate.choices.collectors.items.push({
            ...duplicate.choices.collectors.items[0],
        });
        expect(() => hikSetupReply(duplicate, 7, 42)).toThrow();
        const link = hikFixture();
        link.choices.collectors.next_href =
            hikBase(43) + '/setup?choice=collectors&cursor=x';
        expect(() => hikSetupReply(link, 7, 42)).toThrow();
    });
    it('allows only closed local read/write and recovery destinations', () => {
        expect(
            hikPath(
                42,
                7,
                hikBase(42) + '/setup?choice=scopes&collector_id=11',
                false,
            ),
        ).toBe(true);
        expect(
            hikPath(
                42,
                7,
                hikBase(42) +
                    '/source/requests/' +
                    hikUuid +
                    '?expected_actor_id=7',
                false,
            ),
        ).toBe(true);
        for (const path of [
            hikBase(43) + '/setup',
            hikBase(42) + '/setup?choice=collectors&choice=scopes',
            hikBase(42) + '/setup?choice=credentials&collector_id=11',
            hikBase(42) +
                '/source/requests/' +
                hikUuid +
                '?expected_actor_id=8',
            'https://other.test' + hikBase(42) + '/source',
            hikBase(42) + '/setup#secret',
        ])
            expect(hikPath(42, 7, path, false)).toBe(false);
        expect(hikPath(42, 7, hikBase(42) + '/source/enable', true)).toBe(true);
        expect(hikPath(42, 7, hikBase(42) + '/setup', true)).toBe(false);
    });
    it('correlates original observation, selected channel and inactive mapping with exact model and serial', () => {
        const setup = hikFixture(),
            review = hikReviewFixture(setup),
            mapping = hikMappingFixture(setup, review);
        expect(
            hikDiscoveryReply(hikDiscoveryFixture(setup), setup, hikUuid)
                .status,
        ).toBe('observed');
        expect(hikReviewReply(review, setup, hikUuid).channels[0].id).toBe(
            '101',
        );
        expect(hikMappingReply(mapping, setup, review, hikChannel)).toBe(100);
        expect(() =>
            hikReviewReply(
                {
                    ...review,
                    identity: { ...review.identity, serial: 'another-camera' },
                },
                setup,
                hikUuid,
            ),
        ).toThrow();
        expect(() =>
            hikMappingReply(
                {
                    ...mapping,
                    source: {
                        ...mapping.source,
                        channel: { ...hikChannel, id: '102' },
                    },
                },
                setup,
                review,
                hikChannel,
            ),
        ).toThrow();
        expect(() =>
            hikMappingReply(
                {
                    ...mapping,
                    source: { ...mapping.source, recorder_device_id: 999 },
                },
                setup,
                review,
                hikChannel,
            ),
        ).toThrow();
        expect(() =>
            hikReviewReply(
                {
                    ...review,
                    channels: [review.channels[0], review.channels[0]],
                },
                setup,
                hikUuid,
            ),
        ).toThrow();
    });
});
