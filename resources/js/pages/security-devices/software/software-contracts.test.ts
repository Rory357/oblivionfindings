import { describe, expect, it } from 'vitest';
import {
    metadataValid,
    productVersionValid,
    softwareIndexReply,
    softwarePageHref,
    softwareWriteReply,
} from './_contracts';
import { actor, catalogue, device, release } from './software-fixtures';
describe('approved software context', () => {
    it('accepts exact machine MSI identity and rejects noncanonical versions', () => {
        expect(metadataValid(release())).toBe(true);
        for (const version of ['0.0.0', '255.255.65535', '4.2.1'])
            expect(productVersionValid(version)).toBe(true);
        for (const version of [
            '256.0.0',
            '4.256.0',
            '4.2.65536',
            '04.2.1',
            '4.2',
            '4.2.1.1',
            '4.2.1\n',
        ])
            expect(productVersionValid(version)).toBe(false);
    });
    it('requires matching actor and device before exposing a catalogue', () => {
        expect(
            softwareIndexReply(catalogue(), actor, device).packages,
        ).toHaveLength(1);
        for (const value of [
            { ...catalogue(), actor_id: 9002 },
            {
                ...catalogue(),
                query: { ...catalogue().query, device_id: 9101 },
            },
        ]) {
            expect(() => softwareIndexReply(value, actor, device)).toThrow();
        }
        expect(() =>
            softwareWriteReply({ actor_id: 9002, package: release() }, actor),
        ).toThrow();
    });
    it('rejects foreign, duplicate, unsafely scoped and unexpected pagination routes', () => {
        for (const href of [
            'https://foreign.test/security-devices/software?expected_actor_id=9001&device_id=9100',
            '/security-devices/software?expected_actor_id=9002&device_id=9100',
            '/security-devices/software?expected_actor_id=9001&device_id=9101',
            '/security-devices/software?expected_actor_id=9001&device_id=9100&device_id=9100',
            '/security-devices/software?expected_actor_id=9001&device_id=9100&redirect=https://foreign.test',
            '/security-devices/software?expected_actor_id=9001&device_id=9100#hidden',
        ])
            expect(() => softwarePageHref(href, actor, device)).toThrow();
    });
    it('rejects changed action URLs, duplicate release rows and mismatched pagination totals', () => {
        for (const value of [
            {
                ...catalogue(),
                packages: [
                    release({
                        can_review: true,
                        review_href: 'https://foreign.test/review',
                    }),
                ],
            },
            {
                ...catalogue(),
                packages: [release(), release()],
                pagination: { ...catalogue().pagination, shown: 2 },
            },
            {
                ...catalogue(),
                pagination: { ...catalogue().pagination, shown: 0 },
            },
        ])
            expect(() => softwareIndexReply(value, actor, device)).toThrow();
    });
});
