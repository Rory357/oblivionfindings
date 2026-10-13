import type { RecordingDetail, RecordingPage } from './_recording-contract';
export const recordingUuid = '11111111-1111-4111-8111-111111111111';
export function recordingPageFixture(): RecordingPage {
    return {
        actor_id: 7,
        server_now: '2026-10-08T08:00:00Z',
        camera: {
            id: 42,
            name: 'Main entrance',
            site: { id: 9, name: 'Harbour House' },
        },
        source: {
            revision: 2,
            recorder_device_id: 43,
            recorder_name: 'Reception recorder',
            protect_version: '7.3.53',
            source_verified_at: '2026-10-08T07:00:00Z',
        },
        current_source: {
            state: 'ready',
            reason_code: null,
            revision: 2,
            source_verified_at: '2026-10-08T07:00:00Z',
        },
        window_limits: { max_seconds: 3600, operational_policy_applied: false },
        permissions: { can_view: true, can_create: true },
        create_href: '/security-devices/devices/42/recording-references',
        request_recovery_base_href:
            '/security-devices/cctv/recording-references/requests',
        references: {
            items: [],
            limit: 20,
            previous_href: null,
            next_href: null,
        },
    };
}
export function recordingDetailFixture(): RecordingDetail {
    const page = recordingPageFixture();
    return {
        actor_id: 7,
        server_now: page.server_now,
        camera: page.camera,
        recorder: { id: 43, name: 'Reception recorder' },
        current_source: page.current_source,
        permissions: page.permissions,
        reference: {
            schema_version: 1,
            reference_uuid: recordingUuid,
            camera_id: 42,
            recorder_device_id: 43,
            site_id: 9,
            provider: 'unifi',
            console_id: 'console-one',
            provider_camera_id: 'camera-one',
            provider_recorder_id: 'nvr-one',
            source_revision: 2,
            protect_version: '7.3.53',
            requested_start_at: '2026-10-08T07:55:00Z',
            requested_end_at: '2026-10-08T08:15:00Z',
            created_at: '2026-10-08T08:00:00Z',
            source_verified_at: '2026-10-08T07:00:00Z',
            recording_verified_at: null,
            vendor_retention_until: null,
            state: 'reference_only',
            preserved: false,
            binding_basis: 'reviewed_source_revision',
            window_coverage: 'not_verified',
            created_by: { id: 7, name: 'Alex Morgan' },
            purpose: 'Review the reported concern at the entrance.',
            reader_href:
                '/security-devices/cctv/recording-references/' + recordingUuid,
        },
    };
}
