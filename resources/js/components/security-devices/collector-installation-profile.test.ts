import { describe, expect, it } from 'vitest';
import {
    collectorSetupOptions,
    collectorSetupProfile,
} from './collector-installation-profile';
export const setupReview = {
    platform: 'windows' as const,
    site_id: 9,
    site_label: 'Main office',
    deployment_url: 'https://care.example.test',
    server_url: 'https://collectors.example.test',
    organisation: 'Example Care',
    enrolment_minutes: 15,
    configured: true,
    context_hash: 'a'.repeat(64),
};
export function setupProfile() {
    return {
        version: 1,
        component: 'site',
        platform: 'windows',
        site_id: 9,
        site_label: 'Main office',
        deployment_url: setupReview.deployment_url,
        server_url: setupReview.server_url,
        organisation: 'Example Care',
        server_public_key: 'A'.repeat(43) + '=',
        tls_public_key_pin: 'sha256//' + 'A'.repeat(43) + '=',
        collector_uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        enrolment_token: 'ofc_enrol_' + 'A'.repeat(64),
        expires_at: new Date(Date.now() + 900000).toISOString(),
    };
}
describe('collector setup response boundary', () => {
    it('binds the download to the reviewed platform, Site and two server origins', () => {
        expect(collectorSetupOptions(setupReview, 9, 'windows')).toEqual(
            setupReview,
        );
        const valid = setupProfile();
        expect(collectorSetupProfile(valid, setupReview)).toEqual(valid);
        for (const change of [
            { site_id: 10 },
            { platform: 'linux' },
            { server_url: 'https://other.test' },
            { deployment_url: 'https://other.test' },
            { expires_at: new Date(0).toISOString() },
            { server_public_key: 'invalid' },
            { tls_public_key_pin: 'invalid' },
            { tenant_id: 1 },
        ]) {
            expect(() =>
                collectorSetupProfile(
                    { ...setupProfile(), ...change },
                    setupReview,
                ),
            ).toThrow();
        }
        expect(() =>
            collectorSetupOptions(
                {
                    ...setupReview,
                    server_url: 'https://user:password@other.test',
                },
                9,
                'windows',
            ),
        ).toThrow();
        expect(() =>
            collectorSetupOptions(
                { ...setupReview, site_id: 10 },
                9,
                'windows',
            ),
        ).toThrow();
    });
    it('supports Linux Site profiles and a clearly unconfigured state', () => {
        const linux = { ...setupReview, platform: 'linux' as const };
        expect(
            collectorSetupProfile(
                { ...setupProfile(), platform: 'linux' },
                linux,
            ).platform,
        ).toBe('linux');
        const missing = {
            ...setupReview,
            configured: false,
            deployment_url: null,
            server_url: null,
            organisation: null,
            enrolment_minutes: null,
        };
        expect(collectorSetupOptions(missing, 9, 'windows').configured).toBe(
            false,
        );
        expect(() => collectorSetupProfile(setupProfile(), missing)).toThrow();
    });
});
