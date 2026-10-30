import type {
    RecorderDiscovery,
    RecorderSetup,
} from './_hikvision-recorder-contract';
import {
    hikChannel,
    hikFixture,
    hikIdentity,
    hikTime,
    hikUuid,
} from './hikvision-test-fixtures';
export function recorderFixture(): RecorderSetup {
    const c = hikFixture();
    return {
        provider: 'hikvision_recorder',
        actor_id: c.actor_id,
        recorder_id: c.camera_id,
        recorder: { ...c.camera, name: 'Aurora recorder' },
        revision: 0,
        readiness: { can_discover: true },
        choices: c.choices,
        choice: null,
        q: '',
        collector_id: null,
        recent_requests: [],
    };
}
export function recorderResult(setup = recorderFixture()): RecorderDiscovery {
    return {
        actor_id: setup.actor_id,
        request_uuid: hikUuid,
        device_id: setup.recorder_id,
        site_id: setup.recorder.site.id,
        source_revision: 0,
        status: 'observed',
        reason_code: null,
        current_context: true,
        execution_enabled: true,
        created_at: hikTime(),
        observed_at: hikTime(),
        inventory: {
            identity: { ...hikIdentity, model: 'Synthetic recorder' },
            channels: [
                {
                    ...hikChannel,
                    id: '1301',
                    input_id: '13',
                    input_kind: 'digital_proxy',
                    fps100: 0,
                    codec: 'H.265',
                },
            ],
            inputs: [
                {
                    id: '13',
                    proxy_protocol: 'HIKVISION',
                    addressing_type: 'ipaddress',
                    address: '192.0.2.20',
                    management_port: 8000,
                    source_input_id: '1',
                    online: null,
                    camera_identity: null,
                    association: 'unverified',
                },
            ],
            response_sha256: {
                device_info: 'a'.repeat(64),
                streaming_proxy: 'b'.repeat(64),
                input_status: 'c'.repeat(64),
            },
        },
        reader_href: '/security-devices/cctv/hikvision-discoveries/' + hikUuid,
    };
}
