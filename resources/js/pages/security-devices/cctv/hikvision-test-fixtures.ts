import {
    hikProfile,
    type HikReview,
    type HikSetup,
    type HikSource,
} from './_hikvision-contract';
export const hikUuid = '11111111-1111-4111-8111-111111111111';
export const hikTime = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
export const hikIdentity = {
    model: 'synthetic-model',
    serial: 'synthetic-serial',
    device_id: 'synthetic-vendor-id',
    firmware: 'synthetic-version',
    firmware_build: '',
};
export const hikChannel = {
    id: '101',
    input_id: '1',
    name: 'Reception',
    codec: 'H.264',
    enabled: true,
    video_enabled: true,
    width: 1920,
    height: 1080,
    fps100: 2500,
    audio_enabled: null,
};
export function hikFixture(): HikSetup {
    return {
        provider: 'hikvision',
        actor_id: 7,
        camera_id: 42,
        camera: {
            id: 42,
            name: 'Reception camera',
            site: { id: 3, name: 'Aurora House' },
            model: hikIdentity.model,
            serial: hikIdentity.serial,
            ip_address: '10.44.0.10',
        },
        revision: 0,
        source: null,
        active_source: null,
        readiness: {
            can_discover: true,
            can_map: true,
            can_enable: false,
            can_disable: false,
            can_view: false,
            reason_code: 'not_mapped',
        },
        choices: {
            collectors: {
                items: [{ id: 11, name: 'Aurora collector' }],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
            scopes: {
                items: [
                    {
                        id: 12,
                        name: 'Approved camera network',
                        collector_id: 11,
                    },
                ],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
            credentials: {
                items: [{ id: 13, name: 'Approved camera access' }],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
        },
        choice: null,
        q: '',
        collector_id: null,
    };
}
export function hikReviewFixture(setup = hikFixture()): HikReview {
    return {
        actor_id: setup.actor_id,
        camera_id: setup.camera_id,
        site_id: setup.camera.site.id,
        discovery_uuid: hikUuid,
        expected_revision: setup.revision,
        result_sha256: 'a'.repeat(64),
        identity: hikIdentity,
        observed_at: hikTime(),
        transport_profile: hikProfile,
        channels: [{ ...hikChannel, can_select: true, reason_code: null }],
        current_context: true,
        mapping_enabled: true,
        live_available: false,
    };
}
export function hikDiscoveryFixture(setup = hikFixture()) {
    return {
        actor_id: setup.actor_id,
        request_uuid: hikUuid,
        device_id: setup.camera_id,
        site_id: setup.camera.site.id,
        source_revision: setup.revision,
        status: 'observed',
        reason_code: null,
        current_context: true,
        execution_enabled: true,
        created_at: hikTime(),
        observed_at: hikTime(),
        inventory: { identity: hikIdentity, channels: [hikChannel] },
        reader_href: '/security-devices/cctv/hikvision-discoveries/' + hikUuid,
    };
}
export function hikMappingFixture(
    setup = hikFixture(),
    review = hikReviewFixture(setup),
) {
    return {
        actor_id: setup.actor_id,
        camera_id: setup.camera_id,
        source: {
            id: 100,
            revision: setup.revision + 1,
            provider: 'hikvision',
            kind: 'direct_camera',
            recorder_device_id: null,
            current_mapping: true,
            enabled: false,
            stream_state: 'not_requested',
            stream_verified_at: null,
            identity: hikIdentity,
            channel: hikChannel,
            transport_profile: hikProfile,
            video_codec: 'H264',
            audio_enabled: false,
            observed_at: review.observed_at,
            mapped_at: hikTime(),
        },
        mapping_enabled: true,
        live_available: false,
    };
}
export function hikSavedFixture(enabled = false): HikSetup {
    const setup = hikFixture();
    const source: HikSource = {
        id: 100,
        revision: 1,
        enabled,
        stream_state: 'not_requested',
        stream_verified_at: null,
        channel_name: hikChannel.name,
        observed_at: hikTime(),
    };
    return {
        ...setup,
        revision: 1,
        source,
        active_source: enabled ? source : null,
        readiness: {
            ...setup.readiness,
            can_enable: !enabled,
            can_disable: enabled,
            can_view: enabled,
            reason_code: null,
        },
    };
}
